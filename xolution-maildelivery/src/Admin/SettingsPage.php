<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Admin;

use WP_Error;
use Xolution\MailDelivery\Alerts\SlackAlerts;
use Xolution\MailDelivery\Integration\Registry;
use Xolution\MailDelivery\Log\LogTable;
use Xolution\MailDelivery\Mail\Sender;
use Xolution\MailDelivery\Plugin;
use Xolution\MailDelivery\Settings\Secrets;

/**
 * Settings → MailDelivery, with a Settings and a Log tab. Only registered for managers, and every handler checks
 * again.
 */
final class SettingsPage
{
    private const ACTION_SAVE = 'xmd_save';
    private const ACTION_PING = 'xmd_ping';
    private const ACTION_TEST = 'xmd_test';
    private const ACTION_ALERT_TEST = 'xmd_alert_test';

    private const UUID_PATTERN   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const DOMAIN_PATTERN = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handleSave']);
        add_action('admin_post_' . self::ACTION_PING, [$this, 'handlePing']);
        add_action('admin_post_' . self::ACTION_TEST, [$this, 'handleTest']);
        add_action('admin_post_' . self::ACTION_ALERT_TEST, [$this, 'handleAlertTest']);
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
        $options  = $this->plugin->options;
        $messages = [];
        $errors   = [];

        $accountId = strtolower(trim($this->post('account_id')));
        if ($accountId === '' || preg_match(self::UUID_PATTERN, $accountId) === 1) {
            $options->update(['account_id' => $accountId]);
        } else {
            $errors[] = __('The account ID is not a valid UUID. It was not saved.', 'xolution-maildelivery');
        }

        $apiKey = trim($this->post('api_key'));
        if ($apiKey !== '') {
            if (str_starts_with($apiKey, 'aha-sk-') && strlen($apiKey) > 20) {
                $options->update([
                    'api_key'      => Secrets::encrypt($apiKey),
                    'api_key_hint' => substr($apiKey, -4),
                ]);
                $messages[] = __('API key saved.', 'xolution-maildelivery');
            } else {
                $errors[] = __('The API key must be an AhaSend API v2 key, starting with "aha-sk-". Keys from API v1 do not work. It was not saved.', 'xolution-maildelivery');
            }
        }

        $domain = strtolower(trim($this->post('sending_domain')));
        if ($domain === '' || preg_match(self::DOMAIN_PATTERN, $domain) === 1) {
            $options->update(['sending_domain' => $domain]);
        } else {
            $errors[] = __('The sending domain is not a valid domain name. It was not saved.', 'xolution-maildelivery');
        }

        $options->update(['sandbox' => isset($_POST['sandbox'])]);

        $webhook = trim($this->post('slack_webhook'));
        if (isset($_POST['remove_slack_webhook'])) {
            $options->update(['slack_webhook' => '']);
        } elseif ($webhook !== '') {
            if (str_starts_with($webhook, SlackAlerts::WEBHOOK_PREFIX) && wp_http_validate_url($webhook) !== false) {
                $options->update(['slack_webhook' => $webhook]);
            } else {
                $errors[] = sprintf(
                    __('The Slack webhook must start with %s. It was not saved.', 'xolution-maildelivery'),
                    SlackAlerts::WEBHOOK_PREFIX,
                );
            }
        }

        $wanted = $this->plugin->registry->filterSwitchable($this->postList('enabled_types'));
        if ($wanted !== [] && $this->plugin->client() instanceof WP_Error) {
            $errors[] = __('Email types can only be switched on once the account ID, a working API key and the sending domain are saved. All email types are off.', 'xolution-maildelivery');
            $wanted   = [];
        }
        $options->update(['enabled_types' => $wanted]);

        $managers = array_map('intval', $this->postList('managers'));
        if ($managers === []) {
            $errors[] = __('At least one manager is needed. The managers were not changed.', 'xolution-maildelivery');
        } else {
            $this->plugin->managers->replace($managers);
        }

        $messages[] = __('Settings saved.', 'xolution-maildelivery');
        $this->redirect('settings', $messages, $errors);
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
            : $this->redirect('settings', [], [sprintf(__('Connection failed: %s', 'xolution-maildelivery'), $result->error)]);
    }

    public function handleTest(): void
    {
        $this->guard(self::ACTION_TEST);
        $email  = wp_get_current_user()->user_email;
        $result = (new Sender($this->plugin))->sendTest($email);
        $result->ok
            ? $this->redirect('settings', [sprintf(__('Test email accepted by AhaSend for %1$s (message %2$s).', 'xolution-maildelivery'), $email, (string) $result->messageId)], [])
            : $this->redirect('settings', [], [sprintf(__('Test email failed: %s', 'xolution-maildelivery'), $result->error)]);
    }

    public function handleAlertTest(): void
    {
        $this->guard(self::ACTION_ALERT_TEST);
        $result = $this->plugin->alerts->sendTest();
        $result === true
            ? $this->redirect('settings', [__('Test alert posted to Slack.', 'xolution-maildelivery')], [])
            : $this->redirect('settings', [], [sprintf(__('Test alert failed: %s', 'xolution-maildelivery'), $result)]);
    }

    // ---------------------------------------------------------------- rendering

    public function render(): void
    {
        if (!$this->plugin->managers->isManager()) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'xolution-maildelivery'), 403);
        }
        $tab = ($_GET['tab'] ?? '') === 'log' ? 'log' : 'settings';

        echo '<div class="wrap">';
        printf(
            '<h1>%s <span class="title-count">v%s</span></h1>',
            esc_html__('Xolution MailDelivery', 'xolution-maildelivery'),
            esc_html(\Xolution\MailDelivery\VERSION),
        );
        $this->renderFlash();
        echo '<nav class="nav-tab-wrapper">';
        foreach (['settings' => __('Settings', 'xolution-maildelivery'), 'log' => __('Log', 'xolution-maildelivery')] as $id => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($this->url($id)),
                $tab === $id ? ' nav-tab-active' : '',
                esc_html($label),
            );
        }
        echo '</nav>';

        $tab === 'log' ? $this->renderLog() : $this->renderSettings();
        echo '</div>';
    }

    private function renderSettings(): void
    {
        $o          = $this->plugin->options;
        $configured = $this->plugin->isConfigured();
        $hint       = (string) $o->get('api_key_hint');
        $enabled    = $this->plugin->registry->enabledMarkers();
        $managerIds = $this->plugin->managers->ids();

        if (!Secrets::saltsInConfig()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('wp-config.php does not define its own salts, so WordPress keeps them in the database. The API key is encrypted with them, which protects nothing while they sit next to it. Add salts to wp-config.php, then re-enter the API key.', 'xolution-maildelivery') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE) . '">';
        wp_nonce_field(self::ACTION_SAVE);

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
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($this->plugin->registry->integrations() as $integration) {
            $cells = [];
            foreach ($integration->emailTypes() as $type) {
                $marker = Registry::marker($integration, $type);
                $cells[] = sprintf(
                    '<label><input type="checkbox" name="enabled_types[]" value="%s"%s%s> %s</label>%s%s',
                    esc_attr($marker),
                    checked(in_array($marker, $enabled, true), true, false),
                    disabled(!$type->isImplemented() || !$integration->isAvailable(), true, false),
                    esc_html($type->label()),
                    $type->isImplemented() ? '' : ' <em>(' . esc_html__('not available yet', 'xolution-maildelivery') . ')</em>',
                    $type->description() === '' ? '' : '<br><span class="description">' . esc_html($type->description()) . '</span>',
                );
            }
            $label = $integration->label() . ($integration->isAvailable() ? '' : ' (' . __('not active on this site', 'xolution-maildelivery') . ')');
            $this->row('', $label, '<fieldset>' . implode('<br>', $cells) . '</fieldset>');
        }
        echo '</tbody></table>';

        $webhook = $this->plugin->alerts->webhookUrl();
        echo '<h2>' . esc_html__('Alerts', 'xolution-maildelivery') . '</h2>';
        echo '<p>' . esc_html__('Posts to Slack when an email this plugin takes over can\'t be sent, when the twice-daily check of the API key fails, and once when it works again. Recipients are masked; no content is posted.', 'xolution-maildelivery') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(
            'xmd-slack-webhook',
            __('Slack webhook', 'xolution-maildelivery'),
            sprintf(
                '<input type="password" class="regular-text code" id="xmd-slack-webhook" name="slack_webhook" value="" autocomplete="off" placeholder="%s">%s',
                esc_attr($webhook === null ? SlackAlerts::WEBHOOK_PREFIX . 'services/…' : SlackAlerts::WEBHOOK_PREFIX . '…' . substr($webhook, -4)),
                $webhook === null ? '' : '<br><label><input type="checkbox" name="remove_slack_webhook" value="1"> ' . esc_html__('Remove the Slack webhook', 'xolution-maildelivery') . '</label>',
            ),
            $webhook === null
                ? __('A Slack incoming webhook URL. Without one, failures only show in the log and as a notice to managers.', 'xolution-maildelivery')
                : __('Saved. Leave empty to keep it; type a new URL to replace it.', 'xolution-maildelivery'),
        );
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Managers', 'xolution-maildelivery') . '</h2>';
        echo '<p>' . esc_html__('Only managers see this plugin: in the Plugins list, here, and in notices. Other administrators don\'t.', 'xolution-maildelivery') . '</p>';
        $admins = get_users(['role' => 'administrator', 'orderby' => 'login', 'fields' => ['ID', 'user_login', 'display_name']]);
        $cells  = [];
        foreach ($admins as $admin) {
            $cells[] = sprintf(
                '<label><input type="checkbox" name="managers[]" value="%d"%s> %s <span class="description">(%s)</span></label>',
                (int) $admin->ID,
                checked(in_array((int) $admin->ID, $managerIds, true), true, false),
                esc_html($admin->display_name),
                esc_html($admin->user_login),
            );
        }
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row('', __('Managers', 'xolution-maildelivery'), '<fieldset>' . implode('<br>', $cells) . '</fieldset>');
        echo '</tbody></table>';

        submit_button(__('Save settings', 'xolution-maildelivery'));
        echo '</form>';

        echo '<h2>' . esc_html__('Check', 'xolution-maildelivery') . '</h2>';
        echo '<p>';
        $this->actionButton(self::ACTION_PING, __('Test connection', 'xolution-maildelivery'), !$configured);
        echo ' ';
        $this->actionButton(
            self::ACTION_TEST,
            sprintf(__('Send test email to %s', 'xolution-maildelivery'), wp_get_current_user()->user_email),
            !$configured,
        );
        echo ' ';
        $this->actionButton(self::ACTION_ALERT_TEST, __('Send test alert to Slack', 'xolution-maildelivery'), $webhook === null);
        echo '</p>';
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
                __('Every email this plugin handled in the last %d days. Recipient, subject and outcome only; no content.', 'xolution-maildelivery'),
                LogTable::RETENTION_DAYS,
            )),
        );

        echo '<form method="get" style="margin:1em 0">';
        echo '<input type="hidden" name="page" value="' . esc_attr(Plugin::SLUG) . '"><input type="hidden" name="tab" value="log">';
        echo '<select name="status"><option value="">' . esc_html__('All statuses', 'xolution-maildelivery') . '</option>';
        foreach (['sent', 'failed', 'sandbox'] as $s) {
            printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($s), selected($status, $s, false));
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
                esc_html($row->status),
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

    private function row(string $for, string $label, string $fieldHtml, string $description = ''): void
    {
        $th = $for === '' ? esc_html($label) : sprintf('<label for="%s">%s</label>', esc_attr($for), esc_html($label));
        echo '<tr><th scope="row">' . $th . '</th><td>' . $fieldHtml;
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private function actionButton(string $action, string $label, bool $disabled): void
    {
        printf(
            '<form method="post" action="%s" style="display:inline"><input type="hidden" name="action" value="%s">%s<button type="submit" class="button"%s>%s</button></form>',
            esc_url(admin_url('admin-post.php')),
            esc_attr($action),
            wp_nonce_field($action, '_wpnonce', true, false),
            disabled($disabled, true, false),
            esc_html($label),
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
        return admin_url('options-general.php?page=' . Plugin::SLUG . ($tab === 'log' ? '&tab=log' : ''));
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
