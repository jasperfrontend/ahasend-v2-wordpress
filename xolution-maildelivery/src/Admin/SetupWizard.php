<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Admin;

defined('ABSPATH') || exit;

use InvalidArgumentException;
use Xolution\MailDelivery\Integration\GroupsEmailTypes;
use Xolution\MailDelivery\Integration\Registry;
use Xolution\MailDelivery\Plugin;
use Xolution\MailDelivery\Settings\ConfigFile;
use Xolution\MailDelivery\Settings\Setup;
use Xolution\MailDelivery\Settings\Uninstall;

/**
 * The Setup tab: set a site up in one go from an .xmd config file and a setup key, download a site's setup as an
 * .xmd file, and uninstall everything. The work itself is in Settings\Setup and Settings\Uninstall; this is the
 * screen, the handlers and the report.
 */
final class SetupWizard
{
    public const ACTION_SETUP  = 'xmd_setup';
    public const ACTION_EXPORT = 'xmd_export';
    public const ACTION_READ   = 'xmd_read_config';
    public const ACTION_REMOVE = 'xmd_uninstall';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_SETUP, [$this, 'handleSetup']);
        add_action('admin_post_' . self::ACTION_EXPORT, [$this, 'handleExport']);
        add_action('wp_ajax_' . self::ACTION_READ, [$this, 'handleRead']);
        add_action('admin_post_' . self::ACTION_REMOVE, [$this, 'handleUninstall']);
    }

    // ---------------------------------------------------------------- handlers

    public function handleSetup(): void
    {
        $this->guard(self::ACTION_SETUP);

        $config = [
            'account_id'     => $this->post('account_id'),
            'sending_domain' => $this->post('sending_domain'),
            'server_ips'     => preg_split('/[\s,]+/', $this->post('server_ips'), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'slack_webhook'  => $this->post('slack_webhook'),
            'sandbox'        => isset($_POST['sandbox']),
            'enabled_types'  => $this->postList('enabled_types'),
            'skipped_types'  => $this->postList('skipped_types'),
        ];
        $testEmail = isset($_POST['send_test']) ? wp_get_current_user()->user_email : '';

        // The setup key goes straight to the setup and nowhere else: not stored, not logged, not in the report.
        $report = (new Setup($this->plugin))->run($config, $this->post('setup_key'), $testEmail);
        // After a failed run the form shows what was entered (never the key), so nothing has to be done twice.
        if (!$report['ok']) {
            $report['form'] = $config;
        }

        set_transient(self::reportKey(), $report, 300);
        wp_safe_redirect(admin_url('options-general.php?page=' . Plugin::SLUG . '&tab=setup'));
        exit;
    }

    public function handleUninstall(): void
    {
        $this->guard(self::ACTION_REMOVE);

        // The setup key goes straight to the uninstall and nowhere else.
        $report = (new Uninstall($this->plugin))->run($this->post('setup_key'));

        if (!$report['ok']) {
            // Stopped before anything changed: back to the tab, with the report.
            set_transient(self::reportKey(), $report, 300);
            wp_safe_redirect(admin_url('options-general.php?page=' . Plugin::SLUG . '&tab=setup'));
            exit;
        }

        // The plugin is gone, so its screen is too: the report is this response.
        wp_die(
            '<h1>' . esc_html__('MailDelivery is uninstalled', 'xolution-maildelivery') . '</h1>'
            . '<ul>' . implode('', array_map(static fn (array $step): string => sprintf(
                '<li>%s <strong>%s</strong>%s</li>',
                match ($step['status']) {
                    Setup::STATUS_OK     => '&#10003;',
                    Setup::STATUS_FAILED => '&#10007;',
                    default              => '&ndash;',
                },
                esc_html($step['label']),
                $step['detail'] === '' ? '' : ': ' . esc_html($step['detail']),
            ), $report['steps'])) . '</ul>'
            . sprintf('<p><a class="button" href="%s">%s</a></p>', esc_url(admin_url('plugins.php')), esc_html__('Go to Plugins', 'xolution-maildelivery')),
            esc_html__('MailDelivery is uninstalled', 'xolution-maildelivery'),
            ['response' => 200],
        );
    }

    public function handleExport(): void
    {
        $this->guard(self::ACTION_EXPORT);
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name('maildelivery-' . $host) . '.' . ConfigFile::EXTENSION . '"');
        echo ConfigFile::encode(ConfigFile::export($this->plugin)); // JSON file download, not HTML
        exit;
    }

    /**
     * Reads a dropped config file for the screen: the checked values, plus what this site can't switch on.
     */
    public function handleRead(): void
    {
        if (!$this->plugin->managers->isManager() || !check_ajax_referer(self::ACTION_READ, '_wpnonce', false)) {
            wp_send_json_error(['message' => __('Sorry, you are not allowed to do that.', 'xolution-maildelivery')], 403);
        }
        try {
            $config = ConfigFile::decode($this->post('config'));
        } catch (InvalidArgumentException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        $registry = $this->plugin->registry;
        $skipped  = [];
        foreach ($config['enabled_types'] as $marker) {
            if (!$registry->isAvailable($marker)) {
                $label           = $registry->integrationLabel($marker);
                $skipped[$label] = ($skipped[$label] ?? 0) + 1;
            }
        }
        $notes = [];
        foreach ($skipped as $label => $count) {
            /* translators: 1: integration name, 2: number of emails */
            $notes[] = sprintf(__('%1$s is not active on this site: %2$d emails skipped.', 'xolution-maildelivery'), $label, $count);
        }

        wp_send_json_success(['config' => $config, 'notes' => $notes]);
    }

    // ---------------------------------------------------------------- rendering

    public function render(): void
    {
        $o          = $this->plugin->options;
        $configured = $this->plugin->isConfigured();
        $webhook    = (string) $o->get('slack_webhook');
        $report     = get_transient(self::reportKey());
        $report     = is_array($report) && isset($report['steps']) ? $report : null;
        if ($report !== null) {
            delete_transient(self::reportKey());
        }
        // What the form shows: what a failed run was given, or else what is saved.
        $form = is_array($report['form'] ?? null) ? $report['form'] : [
            'account_id'     => (string) $o->get('account_id'),
            'sending_domain' => (string) $o->get('sending_domain'),
            'server_ips'     => (array) $o->get('server_ips'),
            'slack_webhook'  => '',
            'sandbox'        => (bool) $o->get('sandbox'),
            'enabled_types'  => $this->plugin->registry->enabledMarkers(),
        ];

        echo '<p class="xmd-setup__intro">' . esc_html__('Set this site up in one go: drop a config file, check the emails, fill in the setup key. Every step is checked, and nothing is saved until the new key works.', 'xolution-maildelivery') . '</p>';

        printf(
            '<form method="post" action="%s" class="xmd-setup" data-xmd-setup data-read-url="%s" data-read-nonce="%s" data-read-failed="%s" data-read-ok="%s">',
            esc_url(admin_url('admin-post.php')),
            esc_url(admin_url('admin-ajax.php')),
            esc_attr(wp_create_nonce(self::ACTION_READ)),
            esc_attr__('This file could not be read.', 'xolution-maildelivery'),
            /* translators: %s: file name */
            esc_attr__('Loaded %s.', 'xolution-maildelivery'),
        );
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SETUP) . '">';
        wp_nonce_field(self::ACTION_SETUP);
        echo '<ol class="xmd-steps">';

        // 1. Config file and the details it fills in.
        echo '<li class="xmd-step">';
        $this->stepTitle(1, __('Config file', 'xolution-maildelivery'));
        printf(
            '<label class="xmd-drop" for="xmd-config-file" hidden><input type="file" id="xmd-config-file" class="xmd-drop__input" accept=".%s,application/json">'
            . '<span class="xmd-drop__icon dashicons dashicons-upload" aria-hidden="true"></span>'
            . '<span class="xmd-drop__text"><strong>%s</strong> %s</span></label>'
            . '<p class="xmd-drop__status" role="status" aria-live="polite"></p>',
            esc_attr(ConfigFile::EXTENSION),
            esc_html__('Drop an .xmd file here', 'xolution-maildelivery'),
            esc_html__('or click to choose one. It fills in everything below.', 'xolution-maildelivery'),
        );
        echo '<table class="form-table xmd-setup__fields" role="presentation"><tbody>';
        $this->row('xmd-setup-account', __('Account ID', 'xolution-maildelivery'), sprintf('<input type="text" class="regular-text code" id="xmd-setup-account" name="account_id" value="%s" autocomplete="off" data-xmd-field="account_id">', esc_attr((string) $form['account_id'])));
        $this->row('xmd-setup-domain', __('Sending domain', 'xolution-maildelivery'), sprintf('<input type="text" class="regular-text code" id="xmd-setup-domain" name="sending_domain" value="%s" placeholder="mail-delivery.example.com" data-xmd-field="sending_domain">', esc_attr((string) $form['sending_domain'])));
        $this->row(
            'xmd-setup-ips',
            __('Server IPs', 'xolution-maildelivery'),
            sprintf('<textarea class="regular-text code" id="xmd-setup-ips" name="server_ips" rows="3" data-xmd-field="server_ips">%s</textarea>', esc_textarea(implode("\n", (array) $form['server_ips']))),
            __('One per line. The new key only works from these addresses, so list every server this site may run on.', 'xolution-maildelivery'),
        );
        $this->row(
            'xmd-setup-webhook',
            __('Slack webhook', 'xolution-maildelivery'),
            sprintf('<input type="text" class="regular-text code" id="xmd-setup-webhook" name="slack_webhook" value="%s" autocomplete="off" placeholder="%s" data-xmd-field="slack_webhook">', esc_attr((string) $form['slack_webhook']), esc_attr($webhook === '' ? 'https://hooks.slack.com/services/…' : 'https://hooks.slack.com/…' . substr($webhook, -4))),
            $webhook === '' ? __('Optional.', 'xolution-maildelivery') : __('Saved. Leave empty to keep it.', 'xolution-maildelivery'),
        );
        $this->row('xmd-setup-sandbox', __('Sandbox mode', 'xolution-maildelivery'), sprintf('<label><input type="checkbox" id="xmd-setup-sandbox" name="sandbox" value="1"%s data-xmd-field="sandbox"> %s</label>', checked((bool) $form['sandbox'], true, false), esc_html__('AhaSend accepts emails but delivers nothing', 'xolution-maildelivery')));
        echo '</tbody></table></li>';

        // 2. The emails.
        echo '<li class="xmd-step">';
        $this->stepTitle(2, __('Emails', 'xolution-maildelivery'));
        echo '<p class="xmd-step__help">' . esc_html__('Ticking an integration switches on all of its emails. Emails of plugins that aren\'t active on this site are skipped.', 'xolution-maildelivery') . '</p>';
        echo $this->checklist(array_map('strval', (array) $form['enabled_types'])); // escaped inside
        // Emails from the config file this site can't switch on: sent along only to be reported as skipped.
        echo '<div data-xmd-skipped>';
        foreach ((array) ($form['skipped_types'] ?? []) as $marker) {
            echo '<input type="hidden" name="skipped_types[]" value="' . esc_attr((string) $marker) . '">';
        }
        echo '</div></li>';

        // 3. The setup key.
        echo '<li class="xmd-step">';
        $this->stepTitle(3, __('Setup key', 'xolution-maildelivery'));
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(
            'xmd-setup-key',
            __('Setup key', 'xolution-maildelivery'),
            '<input type="password" class="regular-text code" id="xmd-setup-key" name="setup_key" value="" autocomplete="off" placeholder="aha-sk-…">',
            ($configured ? __('Leave empty to keep this site\'s current key and only apply the rest.', 'xolution-maildelivery') . ' ' : '')
                . __('An AhaSend API key with the scopes api-keys:write and domains:read. It is used once, to create this site\'s own key, and never saved.', 'xolution-maildelivery'),
        );
        $this->row(
            'xmd-setup-test',
            __('Test email', 'xolution-maildelivery'),
            sprintf(
                '<label><input type="checkbox" id="xmd-setup-test" name="send_test" value="1" checked> %s</label>',
                /* translators: %s: email address of the current user */
                esc_html(sprintf(__('Send a test email to %s when it\'s done', 'xolution-maildelivery'), wp_get_current_user()->user_email)),
            ),
        );
        echo '</tbody></table></li>';
        echo '</ol>';

        printf('<p class="submit"><button type="submit" class="button button-primary button-hero xmd-setup__submit">%s</button></p>', esc_html__('Set up MailDelivery', 'xolution-maildelivery'));
        echo '</form>';

        echo '<h2>' . esc_html__('Config file of this site', 'xolution-maildelivery') . '</h2>';
        echo '<p>' . esc_html__('Saves this site\'s setup as an .xmd file, to set up the next site the same way. It contains the account ID, the sending domain, the server IPs, the Slack webhook and the switched-on emails. Never an API key.', 'xolution-maildelivery') . '</p>';
        printf(
            '<p><a class="button%s" href="%s">%s</a></p>',
            $configured ? '' : ' disabled',
            $configured ? esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION_EXPORT), self::ACTION_EXPORT)) : '#',
            esc_html__('Download config file', 'xolution-maildelivery'),
        );

        $this->renderUninstall();

        if ($report !== null) {
            $this->renderReport($report);
        }
    }

    private function renderUninstall(): void
    {
        $o        = $this->plugin->options;
        $hasKey   = $o->get('api_key') !== '' || $o->get('api_key_id') !== '';
        $savedId  = $o->get('api_key_id') !== '';
        $keyLabel = Setup::keyLabel();

        echo '<h2 class="xmd-uninstall__title">' . esc_html__('Uninstall MailDelivery', 'xolution-maildelivery') . '</h2>';
        echo '<p>' . esc_html__('Removes MailDelivery from this site completely: first this site\'s API key in AhaSend, then all settings and the log, then the plugin itself. Emails go the normal way again, without AhaSend.', 'xolution-maildelivery') . '</p>';

        printf(
            '<form method="post" action="%s" class="xmd-uninstall" data-xmd-confirm-title="%s" data-xmd-confirm-body="%s" data-xmd-confirm-ok="%s">',
            esc_url(admin_url('admin-post.php')),
            esc_attr__('Uninstall MailDelivery?', 'xolution-maildelivery'),
            esc_attr(
                __('This removes all MailDelivery settings and the log, then the plugin itself. With a setup key, it first deletes this site\'s API key in AhaSend. It can\'t be undone.', 'xolution-maildelivery')
                . "\n" . __('If deleting the key fails, nothing on this site is removed.', 'xolution-maildelivery')
            ),
            esc_attr__('Uninstall', 'xolution-maildelivery'),
        );
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_REMOVE) . '">';
        wp_nonce_field(self::ACTION_REMOVE);
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(
            'xmd-uninstall-key',
            __('Setup key', 'xolution-maildelivery'),
            '<input type="password" class="regular-text code" id="xmd-uninstall-key" name="setup_key" value="" autocomplete="off" placeholder="aha-sk-…">',
            !$hasKey
                ? __('This site has no API key, so nothing is deleted in AhaSend.', 'xolution-maildelivery')
                : sprintf(
                    /* translators: %s: the key's label in AhaSend */
                    __('Deletes "%s" in AhaSend. Needs the scope api-keys:delete, and api-keys:read to also find keys from earlier setups of this site.', 'xolution-maildelivery'),
                    $keyLabel,
                ) . ' '
                . ($savedId ? '' : __('This site was set up with an older version that didn\'t save the key\'s ID, so api-keys:read is required here.', 'xolution-maildelivery') . ' ')
                . __('Never saved. Leave empty to keep the key in AhaSend.', 'xolution-maildelivery'),
        );
        echo '</tbody></table>';
        printf('<p class="submit"><button type="submit" class="button xmd-button-danger">%s</button></p>', esc_html__('Uninstall MailDelivery', 'xolution-maildelivery'));
        echo '</form>';
    }

    /**
     * One row per integration with a checkbox for all of its emails, and its emails, which admin.js folds away
     * behind "Select individual emails".
     */
    /**
     * @param list<string> $enabled The markers to tick.
     */
    private function checklist(array $enabled): string
    {
        $registry = $this->plugin->registry;
        $html     = '';

        foreach ($registry->integrations() as $integration) {
            $available = $integration->isAvailable();
            $pro       = $registry->isAddon($integration);
            $listId    = 'xmd-check-' . sanitize_html_class($integration->id());
            $groups    = $integration instanceof GroupsEmailTypes ? $integration->emailTypeGroups() : null;
            $items     = '';

            foreach ($groups ?? [null] as $group) {
                $types = $group === null ? $integration->emailTypes() : $group->types;
                if ($group !== null) {
                    $items .= '<li class="xmd-check__group">' . esc_html($group->label) . '</li>';
                }
                foreach ($types as $type) {
                    $marker   = Registry::marker($integration, $type);
                    $disabled = !$available || !$type->isImplemented();
                    $items .= sprintf(
                        '<li><label><input type="checkbox" name="enabled_types[]" value="%s"%s%s> %s</label></li>',
                        esc_attr($marker),
                        checked(in_array($marker, $enabled, true) && !$disabled, true, false),
                        disabled($disabled, true, false),
                        esc_html($type->label()),
                    );
                }
            }

            $html .= sprintf(
                '<li class="xmd-check%s%s" data-xmd-check>'
                . '<div class="xmd-check__row">'
                . '<label class="xmd-check__main"><input type="checkbox" class="xmd-check__all"%s> <span class="xmd-check__name">%s</span>%s</label>'
                . '<span class="xmd-check__count" data-format="%s"></span>'
                . '%s'
                . '</div>'
                . '<ul class="xmd-check__types" id="%s">%s</ul>'
                . '</li>',
                $pro ? ' is-pro' : '',
                $available ? '' : ' is-unavailable',
                disabled(!$available, true, false),
                esc_html($integration->label()),
                $pro ? '<span class="xmd-acc__badge">PRO</span>' : '',
                esc_attr(str_replace(['%1$d', '%2$d'], ['{on}', '{total}'], __('%1$d of %2$d on', 'xolution-maildelivery'))),
                $available
                    ? sprintf(
                        '<button type="button" class="button-link xmd-check__more" aria-expanded="false" aria-controls="%s" data-open="%s" data-close="%s" hidden>%s</button>',
                        esc_attr($listId),
                        esc_attr__('Select individual emails', 'xolution-maildelivery'),
                        esc_attr__('Hide emails', 'xolution-maildelivery'),
                        esc_html__('Select individual emails', 'xolution-maildelivery'),
                    )
                    : '<span class="xmd-check__note">' . esc_html__('not active on this site', 'xolution-maildelivery') . '</span>',
                esc_attr($listId),
                $items,
            );
        }

        return '<ul class="xmd-checklist">' . $html . '</ul>';
    }

    /**
     * @param array<string, mixed> $report Setup::run()'s report.
     */
    private function renderReport(array $report): void
    {
        $icons = [
            Setup::STATUS_OK      => ['dashicons-yes-alt', __('Done', 'xolution-maildelivery')],
            Setup::STATUS_FAILED  => ['dashicons-dismiss', __('Failed', 'xolution-maildelivery')],
            Setup::STATUS_SKIPPED => ['dashicons-minus', __('Skipped', 'xolution-maildelivery')],
        ];
        $steps = '';
        foreach ((array) $report['steps'] as $step) {
            [$icon, $alt] = $icons[$step['status'] ?? ''] ?? $icons[Setup::STATUS_SKIPPED];
            $steps       .= sprintf(
                '<li class="xmd-report__step is-%s"><span class="dashicons %s" aria-hidden="true"></span><span class="screen-reader-text">%s: </span><span class="xmd-report__label">%s</span>%s</li>',
                esc_attr((string) ($step['status'] ?? '')),
                esc_attr($icon),
                esc_html($alt),
                esc_html((string) ($step['label'] ?? '')),
                ($step['detail'] ?? '') === '' ? '' : '<span class="xmd-report__detail">' . esc_html((string) $step['detail']) . '</span>',
            );
        }
        $skipped = '';
        foreach ((array) ($report['skipped'] ?? []) as $item) {
            $skipped .= '<li>' . esc_html(sprintf(
                /* translators: 1: integration name, 2: number of emails */
                __('%1$s is not active on this site: %2$d emails skipped.', 'xolution-maildelivery'),
                (string) $item['label'],
                (int) $item['count'],
            )) . '</li>';
        }
        $ok        = !empty($report['ok']);
        $uninstall = ($report['kind'] ?? '') === 'uninstall';
        $title     = match (true) {
            $uninstall => __('The uninstall stopped', 'xolution-maildelivery'),
            $ok        => __('MailDelivery is set up', 'xolution-maildelivery'),
            default    => __('The setup stopped', 'xolution-maildelivery'),
        };

        printf(
            '<dialog class="xmd-dialog xmd-report%s" aria-labelledby="xmd-report-title" data-xmd-report open>'
            . '<form method="dialog" class="xmd-dialog__form">'
            . '<h2 id="xmd-report-title" class="xmd-dialog__title"><span class="dashicons %s" aria-hidden="true"></span> %s</h2>'
            . '<ol class="xmd-report__steps">%s</ol>%s'
            . '<div class="xmd-dialog__actions">%s<button type="submit" value="close" class="button button-primary">%s</button></div>'
            . '</form></dialog>',
            $ok ? ' is-ok' : ' is-failed',
            $ok ? 'dashicons-yes-alt' : 'dashicons-warning',
            esc_html($title),
            $steps,
            $skipped === '' ? '' : '<p class="xmd-report__skipped-title">' . esc_html__('Skipped', 'xolution-maildelivery') . '</p><ul class="xmd-report__skipped">' . $skipped . '</ul>',
            $ok ? sprintf('<a class="button" href="%s">%s</a>', esc_url(admin_url('options-general.php?page=' . Plugin::SLUG)), esc_html__('Open settings', 'xolution-maildelivery')) : '',
            esc_html($ok ? __('Close', 'xolution-maildelivery') : ($uninstall ? __('Back', 'xolution-maildelivery') : __('Back to the setup', 'xolution-maildelivery'))),
        );
    }

    // ---------------------------------------------------------------- helpers

    private function stepTitle(int $number, string $title): void
    {
        printf('<h2 class="xmd-step__title"><span class="xmd-step__num" aria-hidden="true">%d</span>%s</h2>', $number, esc_html($title));
    }

    private function row(string $for, string $label, string $fieldHtml, string $description = ''): void
    {
        printf(
            '<tr><th scope="row"><label for="%s">%s</label></th><td>%s%s</td></tr>',
            esc_attr($for),
            esc_html($label),
            $fieldHtml,
            $description === '' ? '' : '<p class="description">' . esc_html($description) . '</p>',
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

    private static function reportKey(): string
    {
        return 'xmd_setup_report_' . get_current_user_id();
    }
}
