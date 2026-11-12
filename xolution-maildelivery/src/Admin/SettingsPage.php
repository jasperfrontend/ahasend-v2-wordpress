<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Admin;

defined('ABSPATH') || exit;

use InvalidArgumentException;
use WP_Error;
use Xolution\MailDelivery\Alerts\SlackAlerts;
use Xolution\MailDelivery\Log\LogTable;
use Xolution\MailDelivery\Mail\Sender;
use Xolution\MailDelivery\Plugin;
use Xolution\MailDelivery\Settings\Configurator;
use Xolution\MailDelivery\Settings\Secrets;

/**
 * Settings → MailDelivery, with tabs: Settings (AhaSend and the email types), Alerts, Managers and Log. Only
 * registered for managers, and every handler checks again.
 *
 * Each tab has its own form and saves only its own section, so a form can't clear settings it doesn't show.
 */
final class SettingsPage
{
    private const ACTION_SAVE       = 'xmd_save';
    private const ACTION_PING       = 'xmd_ping';
    private const ACTION_TEST       = 'xmd_test';
    private const ACTION_ALERT_TEST = 'xmd_alert_test';

    /** Tabs that have a form, in addition to the read-only Log tab. */
    private const FORM_TABS = ['settings', 'alerts', 'managers'];

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handleSave']);
        add_action('admin_post_' . self::ACTION_PING, [$this, 'handlePing']);
        add_action('admin_post_' . self::ACTION_TEST, [$this, 'handleTest']);
        add_action('admin_post_' . self::ACTION_ALERT_TEST, [$this, 'handleAlertTest']);
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_' . Plugin::SLUG) {
            return;
        }
        $version = \Xolution\MailDelivery\VERSION;
        wp_enqueue_style('xmd-admin', plugins_url('assets/admin.css', \Xolution\MailDelivery\FILE), ['dashicons'], $version);
        wp_enqueue_script('xmd-admin', plugins_url('assets/admin.js', \Xolution\MailDelivery\FILE), [], $version, ['in_footer' => true, 'strategy' => 'defer']);
    }

    public function addMenu(): void
    {
        if (!$this->plugin->managers->isManager()) {
            return;
        }
        add_options_page(
            __('Xolution MailDelivery', 'xolution-maildelivery'),
            __('MailDelivery', 'xolution-maildelivery'),
            'manage_options',
            Plugin::SLUG,
            [$this, 'render'],
        );
    }

    // ---------------------------------------------------------------- handlers

    public function handleSave(): void
    {
        $this->guard(self::ACTION_SAVE);
        $section = in_array($this->post('section'), self::FORM_TABS, true) ? $this->post('section') : 'settings';
        $config  = new Configurator($this->plugin);
        $steps   = match ($section) {
            'alerts'   => $this->alertsSteps($config),
            'managers' => [fn () => $config->setManagers(array_map('intval', $this->postList('managers')))],
            default    => $this->settingsSteps($config),
        };

        $errors = [];
        foreach ($steps as $step) {
            try {
                $step();
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $saved = match ($section) {
            'alerts'   => __('Alerts saved.', 'xolution-maildelivery'),
            'managers' => __('Managers saved.', 'xolution-maildelivery'),
            default    => __('Settings saved.', 'xolution-maildelivery'),
        };
        $this->redirect($section, [$saved], $errors);
    }

    /**
     * @return list<callable>
     */
    private function settingsSteps(Configurator $config): array
    {
        $steps = [
            fn () => $config->setAccountId($this->post('account_id')),
            fn () => $config->setSendingDomain($this->post('sending_domain')),
            fn () => $config->setSandbox(isset($_POST['sandbox'])),
        ];
        if (trim($this->post('api_key')) !== '') {
            $steps[] = fn () => $config->setApiKey($this->post('api_key'));
        }
        // After the credentials, so a key saved in this same form counts.
        $steps[] = function () use ($config): void {
            $wanted = $this->plugin->registry->filterSwitchable($this->postList('enabled_types'));
            try {
                $config->setEnabledTypes($wanted);
            } catch (InvalidArgumentException $e) {
                $config->setEnabledTypes([]);
                throw new InvalidArgumentException($e->getMessage() . ' ' . __('All email types are off.', 'xolution-maildelivery'));
            }
        };

        return $steps;
    }

    /**
     * @return list<callable>
     */
    private function alertsSteps(Configurator $config): array
    {
        if (isset($_POST['remove_slack_webhook'])) {
            return [fn () => $config->setSlackWebhook('')];
        }
        if (trim($this->post('slack_webhook')) !== '') {
            return [fn () => $config->setSlackWebhook($this->post('slack_webhook'))];
        }

        return [];
    }

    public function handlePing(): void
    {
        $this->guard(self::ACTION_PING);
        $client = $this->plugin->client();
        if ($client instanceof WP_Error) {
            $this->redirect('settings', [], [$client->get_error_message()]);
        }
        $result = $client->ping();
        $result->ok
            ? $this->redirect('settings', [__('Connection OK: AhaSend accepted the API key from this server.', 'xolution-maildelivery')], [])
            : $this->redirect('settings', [], [sprintf(/* translators: %s: error message */ __('Connection failed: %s', 'xolution-maildelivery'), $result->error)]);
    }

    public function handleTest(): void
    {
        $this->guard(self::ACTION_TEST);
        $email  = wp_get_current_user()->user_email;
        $result = (new Sender($this->plugin))->sendTest($email);
        $result->ok
            ? $this->redirect('settings', [sprintf(/* translators: 1: recipient email address, 2: AhaSend message ID */ __('Test email accepted by AhaSend for %1$s (message %2$s).', 'xolution-maildelivery'), $email, (string) $result->messageId)], [])
            : $this->redirect('settings', [], [sprintf(/* translators: %s: error message */ __('Test email failed: %s', 'xolution-maildelivery'), $result->error)]);
    }

    public function handleAlertTest(): void
    {
        $this->guard(self::ACTION_ALERT_TEST);
        $result = $this->plugin->alerts->sendTest();
        $result === true
            ? $this->redirect('alerts', [__('Test alert posted to Slack.', 'xolution-maildelivery')], [])
            : $this->redirect('alerts', [], [sprintf(/* translators: %s: error message */ __('Test alert failed: %s', 'xolution-maildelivery'), $result)]);
    }

    // ---------------------------------------------------------------- rendering

    public function render(): void
    {
        if (!$this->plugin->managers->isManager()) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'xolution-maildelivery'), 403);
        }
        $tabs = [
            'setup'    => __('Setup', 'xolution-maildelivery'),
            'settings' => __('Settings', 'xolution-maildelivery'),
            'alerts'   => __('Alerts', 'xolution-maildelivery'),
            'managers' => __('Managers', 'xolution-maildelivery'),
            'log'      => __('Log', 'xolution-maildelivery'),
        ];
        $tab = sanitize_key((string) ($_GET['tab'] ?? ''));
        // A site that isn't set up yet opens on the setup.
        $tab = isset($tabs[$tab]) ? $tab : ($this->plugin->isConfigured() ? 'settings' : 'setup');

        echo '<div class="wrap xmd-page">';
        printf(
            '<h1>%s <span class="title-count">v%s</span></h1>',
            esc_html__('Xolution MailDelivery', 'xolution-maildelivery'),
            esc_html(\Xolution\MailDelivery\VERSION),
        );
        $this->renderFlash();
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $id => $label) {
            printf(
                '<a href="%s" class="nav-tab%s"%s>%s</a>',
                esc_url($this->url($id)),
                $tab === $id ? ' nav-tab-active' : '',
                $tab === $id ? ' aria-current="page"' : '',
                esc_html($label),
            );
        }
        echo '</nav>';

        match ($tab) {
            'setup'    => (new SetupWizard($this->plugin))->render(),
            'alerts'   => $this->renderAlerts(),
            'managers' => $this->renderManagers(),
            'log'      => $this->renderLog(),
            default    => $this->renderSettings(),
        };
        echo '</div>';
    }

    private function renderSettings(): void
    {
        $o          = $this->plugin->options;
        $configured = $this->plugin->isConfigured();
        $hint       = (string) $o->get('api_key_hint');

        if (!Secrets::saltsInConfig()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('wp-config.php does not define its own salts, so WordPress keeps them in the database. The API key is encrypted with them, which protects nothing while they sit next to it. Add salts to wp-config.php, then re-enter the API key.', 'xolution-maildelivery') . '</p></div>';
        }

        $this->openForm('settings');

        echo '<h2>' . esc_html__('AhaSend', 'xolution-maildelivery') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(
            'xmd-account-id',
            __('Account ID', 'xolution-maildelivery'),
            sprintf('<input type="text" class="regular-text code" id="xmd-account-id" name="account_id" value="%s" autocomplete="off">', esc_attr((string) $o->get('account_id'))),
            __('From the AhaSend dashboard, account settings.', 'xolution-maildelivery'),
        );
        $this->row(
            'xmd-api-key',
            __('API key', 'xolution-maildelivery'),
            sprintf(
                '<input type="password" class="regular-text code" id="xmd-api-key" name="api_key" value="" autocomplete="new-password" placeholder="%s">',
                esc_attr($hint === '' ? 'aha-sk-…' : 'aha-sk-…' . $hint),
            ),
            $hint === ''
                ? __('An AhaSend API v2 key with only the messages:send scope for the sending domain, locked to this server\'s IP. API v1 keys do not work.', 'xolution-maildelivery')
                : __('Saved and encrypted. Leave empty to keep it; type a new key to replace it.', 'xolution-maildelivery'),
        );
        $this->row(
            'xmd-sending-domain',
            __('Sending domain', 'xolution-maildelivery'),
            sprintf('<input type="text" class="regular-text code" id="xmd-sending-domain" name="sending_domain" value="%s" placeholder="mail-delivery.example.com">', esc_attr((string) $o->get('sending_domain'))),
            __('A domain verified in AhaSend.', 'xolution-maildelivery'),
        );
        $this->row(
            '',
            __('From', 'xolution-maildelivery'),
            $o->get('sending_domain') === ''
                ? '<em>' . esc_html__('Set a sending domain first.', 'xolution-maildelivery') . '</em>'
                : '<code>' . esc_html($this->plugin->fromAddress()->name . ' <' . $this->plugin->fromAddress()->email . '>') . '</code>',
            __('Worked out from this site\'s address and the sending domain.', 'xolution-maildelivery'),
        );
        $this->row(
            'xmd-sandbox',
            __('Sandbox mode', 'xolution-maildelivery'),
            sprintf('<label><input type="checkbox" id="xmd-sandbox" name="sandbox" value="1"%s> %s</label>', checked((bool) $o->get('sandbox'), true, false), esc_html__('AhaSend accepts emails but delivers nothing', 'xolution-maildelivery')),
            __('For testing only. While this is on, password reset emails and the like never arrive.', 'xolution-maildelivery'),
        );
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Email types', 'xolution-maildelivery') . '</h2>';
        echo '<p>' . esc_html__('Only switched-on email types go through AhaSend. All other email is sent the normal way. A switched-on email that AhaSend can\'t accept fails; it is not sent the normal way instead.', 'xolution-maildelivery') . '</p>';
        echo (new EmailTypesField($this->plugin->registry))->render(); // escaped inside

        submit_button(__('Save settings', 'xolution-maildelivery'));
        echo '</form>';

        $email   = wp_get_current_user()->user_email;
        $sandbox = (bool) $o->get('sandbox');
        $from    = $o->get('sending_domain') === '' ? '' : $this->plugin->fromAddress()->email;

        echo '<h2>' . esc_html__('Check', 'xolution-maildelivery') . '</h2>';
        echo '<p>';
        $this->actionButton(self::ACTION_PING, __('Test connection', 'xolution-maildelivery'), !$configured, [
            'title' => __('Test the connection?', 'xolution-maildelivery'),
            'body'  => __('This asks AhaSend whether it accepts the saved API key from this server. Nothing is sent and no settings change.', 'xolution-maildelivery')
                . "\n" . __('It checks the key, its IP allow list and the connection. It does not check whether the key may send from the sending domain: the test email does that.', 'xolution-maildelivery'),
            'ok'    => __('Test connection', 'xolution-maildelivery'),
        ]);
        echo ' ';
        $this->actionButton(self::ACTION_TEST, sprintf(/* translators: %s: email address of the current user */ __('Send test email to %s', 'xolution-maildelivery'), $email), !$configured, [
            'title' => __('Send a test email?', 'xolution-maildelivery'),
            'body'  => sprintf(/* translators: 1: recipient email address, 2: sender email address */ __('This sends a real email through AhaSend to %1$s, from %2$s.', 'xolution-maildelivery'), $email, $from)
                . "\n" . __('It does not depend on the email-type switches, and it appears in the log.', 'xolution-maildelivery')
                . ($sandbox ? "\n" . __('Sandbox mode is on: AhaSend accepts the email but does not deliver it.', 'xolution-maildelivery') : ''),
            'ok'    => __('Send test email', 'xolution-maildelivery'),
        ]);
        echo '</p>';
        $this->renderConfirmDialog();
    }

    private function renderAlerts(): void
    {
        $webhook = $this->plugin->alerts->webhookUrl();
        $host    = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        $this->openForm('alerts');
        echo '<h2>' . esc_html__('Slack', 'xolution-maildelivery') . '</h2>';
        echo '<p>' . esc_html__('Posts to Slack when an email this plugin takes over can\'t be sent, when the twice-daily check of the API key fails, and once when it works again. Recipients are masked; no content is posted.', 'xolution-maildelivery') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(
            'xmd-slack-webhook',
            __('Slack webhook', 'xolution-maildelivery'),
            sprintf(
                '<input type="password" class="regular-text code" id="xmd-slack-webhook" name="slack_webhook" value="" autocomplete="off" placeholder="%s">%s',
                esc_attr($webhook === null ? SlackAlerts::WEBHOOK_PREFIX . 'services/…' : SlackAlerts::WEBHOOK_PREFIX . '…' . substr($webhook, -4)),
                $webhook === null ? '' : '<label class="xmd-remove-webhook"><input type="checkbox" name="remove_slack_webhook" value="1"> ' . esc_html__('Remove the Slack webhook', 'xolution-maildelivery') . '</label>',
            ),
            $webhook === null
                ? __('A Slack incoming webhook URL. Without one, failures only show in the log and as a notice to managers.', 'xolution-maildelivery')
                : __('Saved. Leave empty to keep it; type a new URL to replace it.', 'xolution-maildelivery'),
        );
        echo '</tbody></table>';
        submit_button(__('Save alerts', 'xolution-maildelivery'));
        echo '</form>';

        echo '<h2>' . esc_html__('Check', 'xolution-maildelivery') . '</h2>';
        echo '<p>';
        $this->actionButton(self::ACTION_ALERT_TEST, __('Send test alert to Slack', 'xolution-maildelivery'), $webhook === null, [
            'title' => __('Post a test alert to Slack?', 'xolution-maildelivery'),
            'body'  => sprintf(/* translators: %s: this site's host name */ __('This posts a test message to the Slack channel of the saved webhook, saying that alerts from %s arrive there.', 'xolution-maildelivery'), $host)
                . "\n" . __('Everyone in that channel sees it.', 'xolution-maildelivery'),
            'ok'    => __('Post test alert', 'xolution-maildelivery'),
        ]);
        echo '</p>';
        $this->renderConfirmDialog();
    }

    private function renderManagers(): void
    {
        $managerIds = $this->plugin->managers->ids();
        $admins     = get_users(['role' => 'administrator', 'orderby' => 'login', 'fields' => ['ID', 'user_login', 'display_name']]);
        $cells      = [];
        foreach ($admins as $admin) {
            $cells[] = sprintf(
                '<label><input type="checkbox" name="managers[]" value="%d"%s> %s <span class="description">(%s)</span></label>',
                (int) $admin->ID,
                checked(in_array((int) $admin->ID, $managerIds, true), true, false),
                esc_html($admin->display_name),
                esc_html($admin->user_login),
            );
        }

        $this->openForm('managers');
        echo '<h2>' . esc_html__('Managers', 'xolution-maildelivery') . '</h2>';
        echo '<p>' . esc_html__('Only managers see this plugin: in the Plugins list, here, and in notices. Other administrators don\'t.', 'xolution-maildelivery') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row('', __('Managers', 'xolution-maildelivery'), '<fieldset>' . implode('<br>', $cells) . '</fieldset>');
        echo '</tbody></table>';
        submit_button(__('Save managers', 'xolution-maildelivery'));
        echo '</form>';
    }

    private function renderLog(): void
    {
        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        $status = in_array($status, ['sent', 'failed', 'sandbox'], true) ? $status : '';
        $type   = sanitize_text_field(wp_unslash((string) ($_GET['email_type'] ?? '')));
        $page   = max(1, (int) ($_GET['paged'] ?? 1));
        $result = $this->plugin->log->page($page, $status, $type);

        printf(
            '<p>%s</p>',
            esc_html(sprintf(
                /* translators: %d: number of days the log is kept */
                __('Every email this plugin handled in the last %d days. Recipient, subject and outcome only; no content.', 'xolution-maildelivery'),
                LogTable::RETENTION_DAYS,
            )),
        );

        echo '<form method="get" style="margin:1em 0">';
        echo '<input type="hidden" name="page" value="' . esc_attr(Plugin::SLUG) . '"><input type="hidden" name="tab" value="log">';
        echo '<select name="status"><option value="">' . esc_html__('All statuses', 'xolution-maildelivery') . '</option>';
        foreach (self::statusLabels() as $s => $label) {
            printf('<option value="%1$s"%2$s>%3$s</option>', esc_attr($s), selected($status, $s, false), esc_html($label));
        }
        echo '</select> <select name="email_type"><option value="">' . esc_html__('All email types', 'xolution-maildelivery') . '</option>';
        foreach ($this->plugin->log->emailTypes() as $t) {
            printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($t), selected($type, $t, false));
        }
        echo '</select> ';
        submit_button(__('Filter', 'xolution-maildelivery'), 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Time', 'xolution-maildelivery'), __('Email type', 'xolution-maildelivery'), __('Recipient', 'xolution-maildelivery'), __('Subject', 'xolution-maildelivery'), __('Status', 'xolution-maildelivery'), __('AhaSend message / error', 'xolution-maildelivery')] as $h) {
            echo '<th scope="col">' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ($result['rows'] === []) {
            echo '<tr><td colspan="6">' . esc_html__('Nothing logged.', 'xolution-maildelivery') . '</td></tr>';
        }
        foreach ($result['rows'] as $row) {
            $time   = wp_date('Y-m-d H:i:s', (int) strtotime($row->created_at . ' UTC'));
            $detail = $row->status === 'failed'
                ? '<span style="color:#b32d2e">' . esc_html(trim(($row->http_status ? 'HTTP ' . $row->http_status . ' · ' : '') . (string) $row->error)) . '</span>'
                : '<code>' . esc_html((string) $row->message_id) . '</code>';
            printf(
                '<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html((string) $time),
                esc_html($row->email_type),
                esc_html($row->recipient),
                esc_html($row->subject),
                esc_html(self::statusLabels()[$row->status] ?? $row->status),
                $detail,
            );
        }
        echo '</tbody></table>';

        $pages = (int) ceil($result['total'] / LogTable::PER_PAGE);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post((string) paginate_links([
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $page,
                'total'   => $pages,
            ]));
            echo '</div></div>';
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, string> log status => label
     */
    private static function statusLabels(): array
    {
        return [
            'sent'    => _x('sent', 'log status', 'xolution-maildelivery'),
            'failed'  => _x('failed', 'log status', 'xolution-maildelivery'),
            'sandbox' => _x('sandbox', 'log status', 'xolution-maildelivery'),
        ];
    }

    private function openForm(string $section): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE) . '">';
        echo '<input type="hidden" name="section" value="' . esc_attr($section) . '">';
        wp_nonce_field(self::ACTION_SAVE);
    }

    private function row(string $for, string $label, string $fieldHtml, string $description = ''): void
    {
        $th = $for === '' ? esc_html($label) : sprintf('<label for="%s">%s</label>', esc_attr($for), esc_html($label));
        echo '<tr><th scope="row">' . $th . '</th><td>' . $fieldHtml;
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    /**
     * @param array{title: string, body: string, ok: string} $confirm Shown in a dialog before the form is sent.
     *                                                         Lines in body become paragraphs.
     */
    private function actionButton(string $action, string $label, bool $disabled, array $confirm): void
    {
        printf(
            '<form method="post" action="%s" style="display:inline" data-xmd-confirm-title="%s" data-xmd-confirm-body="%s" data-xmd-confirm-ok="%s">'
            . '<input type="hidden" name="action" value="%s">%s<button type="submit" class="button"%s>%s</button></form>',
            esc_url(admin_url('admin-post.php')),
            esc_attr($confirm['title']),
            esc_attr($confirm['body']),
            esc_attr($confirm['ok']),
            esc_attr($action),
            wp_nonce_field($action, '_wpnonce', true, false),
            disabled($disabled, true, false),
            esc_html($label),
        );
    }

    private function renderConfirmDialog(): void
    {
        printf(
            '<dialog id="xmd-confirm" class="xmd-dialog" aria-labelledby="xmd-confirm-title">'
            . '<form method="dialog" class="xmd-dialog__form">'
            . '<h2 id="xmd-confirm-title" class="xmd-dialog__title"></h2><div class="xmd-dialog__body"></div>'
            . '<div class="xmd-dialog__actions"><button type="submit" value="cancel" class="button">%s</button>'
            . '<button type="submit" value="ok" class="button button-primary"></button></div>'
            . '</form></dialog>',
            esc_html__('Cancel', 'xolution-maildelivery'),
        );
    }

    private function guard(string $action): void
    {
        if (!$this->plugin->managers->isManager()) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'xolution-maildelivery'), 403);
        }
        check_admin_referer($action);
    }

    private function post(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? (string) wp_unslash($_POST[$name]) : '';
    }

    /** @return list<string> */
    private function postList(string $name): array
    {
        $value = $_POST[$name] ?? [];

        return is_array($value) ? array_values(array_map(static fn ($v): string => (string) wp_unslash((string) $v), $value)) : [];
    }

    private function url(string $tab): string
    {
        return admin_url('options-general.php?page=' . Plugin::SLUG . '&tab=' . $tab);
    }

    /**
     * @param list<string> $messages
     * @param list<string> $errors
     */
    private function redirect(string $tab, array $messages, array $errors): never
    {
        set_transient('xmd_flash_' . get_current_user_id(), ['messages' => $messages, 'errors' => $errors], 60);
        wp_safe_redirect($this->url($tab));
        exit;
    }

    private function renderFlash(): void
    {
        $key   = 'xmd_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if (!is_array($flash)) {
            return;
        }
        delete_transient($key);
        foreach ((array) ($flash['errors'] ?? []) as $error) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html((string) $error) . '</p></div>';
        }
        foreach ((array) ($flash['messages'] ?? []) as $message) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html((string) $message) . '</p></div>';
        }
    }
}
