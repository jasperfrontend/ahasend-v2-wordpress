<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Settings;

defined('ABSPATH') || exit;

use Closure;
use WP_Error;
use Xolution\MailDelivery\Api\AccountClient;
use Xolution\MailDelivery\Plugin;

/**
 * The Uninstall button's work, step by step: delete this site's API key in AhaSend, remove everything the plugin
 * stored, then deactivate and delete the plugin.
 *
 * The AhaSend key goes first. If that fails, the run stops before anything on the site changes, so it can simply
 * be tried again. Without a setup key the AhaSend step is skipped and the key stays in AhaSend.
 *
 * Which keys: the one whose ID the setup saved, plus every key called Setup::keyLabel() ("WordPress: <host>"),
 * which also catches keys left behind by an earlier setup of this site. Looking keys up by label needs
 * api-keys:read; without it only the saved ID is deleted. A site set up before the ID was saved has no ID, so
 * there the lookup is required.
 *
 * The setup key only passes through this request: it is never stored, logged or put in the report.
 */
final class Uninstall
{
    /** @var Closure(string $setupKey, string $accountId): AccountClient */
    private readonly Closure $accountClient;

    /** @var Closure(): array{status: string, detail: string} */
    private readonly Closure $deletePlugin;

    /**
     * @param (Closure(string, string): AccountClient)|null       $accountClient For tests.
     * @param (Closure(): array{status: string, detail: string})|null $deletePlugin  For tests.
     */
    public function __construct(private readonly Plugin $plugin, ?Closure $accountClient = null, ?Closure $deletePlugin = null)
    {
        $this->accountClient = $accountClient ?? static fn (string $key, string $account): AccountClient => new AccountClient($key, $account);
        $this->deletePlugin  = $deletePlugin ?? self::deletePluginFiles(...);
    }

    /**
     * @return array{ok: bool, kind: string, steps: list<array{label: string, status: string, detail: string}>}
     *         ok: false when the run stopped before changing anything on the site.
     */
    public function run(string $setupKey): array
    {
        $report = ['ok' => false, 'kind' => 'uninstall', 'steps' => []];
        $step   = static function (string $label, string $status, string $detail = '') use (&$report): void {
            $report['steps'][] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        };

        // 1. The key in AhaSend.
        $label   = __('API key deleted in AhaSend', 'xolution-maildelivery');
        $removed = $this->deleteKeys(trim($setupKey));
        if ($removed instanceof WP_Error) {
            $step($label, Setup::STATUS_FAILED, $removed->get_error_message());

            return $report;
        }
        $step($label, $removed['status'], $removed['detail']);

        // 2. Everything stored on the site.
        Cleanup::removeData();
        $step(__('Settings and log removed', 'xolution-maildelivery'), Setup::STATUS_OK);

        // 3. The plugin itself. Its data is gone already, so a failure here only leaves the files.
        $deleted = ($this->deletePlugin)();
        $step(__('Plugin deactivated and deleted', 'xolution-maildelivery'), $deleted['status'], $deleted['detail']);

        $report['ok'] = true;

        return $report;
    }

    /**
     * @return array{status: string, detail: string}|WP_Error An error stops the uninstall.
     */
    private function deleteKeys(string $setupKey): array|WP_Error
    {
        $o         = $this->plugin->options;
        $accountId = (string) $o->get('account_id');
        $keyLabel  = Setup::keyLabel();

        if ($accountId === '' || ($o->get('api_key') === '' && $o->get('api_key_id') === '')) {
            return ['status' => Setup::STATUS_SKIPPED, 'detail' => __('This site has no API key.', 'xolution-maildelivery')];
        }
        if ($setupKey === '') {
            return ['status' => Setup::STATUS_SKIPPED, 'detail' => sprintf(
                /* translators: %s: the key's label in AhaSend */
                __('No setup key: "%s" stays in AhaSend. Delete it there by hand.', 'xolution-maildelivery'),
                $keyLabel,
            )];
        }
        if (!str_starts_with($setupKey, 'aha-sk-')) {
            return new WP_Error('xmd_setup_key', __('The setup key must be an AhaSend API v2 key, starting with "aha-sk-".', 'xolution-maildelivery'));
        }

        $account = ($this->accountClient)($setupKey, $accountId);
        $savedId = (string) $o->get('api_key_id');
        $found   = $account->keyIdsWithLabel($keyLabel);
        if ($found instanceof WP_Error && $savedId === '') {
            return new WP_Error('xmd_key_lookup', sprintf(
                /* translators: 1: the key's label in AhaSend, 2: error from AhaSend */
                __('This site\'s key ID isn\'t saved (it was set up with an older version), so "%1$s" has to be looked up, which needs the scope api-keys:read. Nothing was removed. AhaSend said: %2$s', 'xolution-maildelivery'),
                $keyLabel,
                $found->get_error_message(),
            ));
        }
        $ids = array_values(array_unique(array_filter([$savedId, ...($found instanceof WP_Error ? [] : $found)])));

        $deleted = 0;
        foreach ($ids as $id) {
            $result = $account->deleteKey($id);
            if ($result instanceof WP_Error) {
                return new WP_Error('xmd_key_delete', sprintf(
                    /* translators: 1: number of keys deleted before the error, 2: error from AhaSend */
                    __('Deleting the key failed after %1$d deleted. Nothing on this site was removed. AhaSend said: %2$s', 'xolution-maildelivery'),
                    $deleted,
                    $result->get_error_message(),
                ));
            }
            $deleted += $result ? 1 : 0;
        }

        if ($deleted === 0) {
            return ['status' => Setup::STATUS_SKIPPED, 'detail' => sprintf(
                /* translators: %s: the key's label in AhaSend */
                __('AhaSend has no key "%s" (anymore).', 'xolution-maildelivery'),
                $keyLabel,
            )];
        }
        $detail = sprintf(
            /* translators: 1: the keys' label in AhaSend, 2: number of keys deleted */
            __('"%1$s", keys deleted: %2$d', 'xolution-maildelivery'),
            $keyLabel,
            $deleted,
        );
        if ($found instanceof WP_Error) {
            $detail .= '. ' . __('Older keys of this site could not be looked up without the scope api-keys:read.', 'xolution-maildelivery');
        }

        return ['status' => Setup::STATUS_OK, 'detail' => $detail];
    }

    /**
     * Deactivates the plugin and deletes it the way the Plugins screen does, which runs uninstall.php as well.
     *
     * @return array{status: string, detail: string}
     */
    private static function deletePluginFiles(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $basename = Plugin::basename();
        deactivate_plugins($basename, false, is_multisite() && is_plugin_active_for_network($basename));

        $byHand = __('Deactivated. Delete it on the Plugins screen.', 'xolution-maildelivery');
        if (!current_user_can('delete_plugins')) {
            return ['status' => Setup::STATUS_SKIPPED, 'detail' => $byHand];
        }
        // Other methods ask for FTP details, which this request can't show.
        if (get_filesystem_method() !== 'direct') {
            return ['status' => Setup::STATUS_SKIPPED, 'detail' => $byHand];
        }

        ob_start(); // delete_plugins() may print a credentials form
        $result = delete_plugins([$basename]);
        ob_end_clean();

        return $result === true
            ? ['status' => Setup::STATUS_OK, 'detail' => '']
            : ['status' => Setup::STATUS_FAILED, 'detail' => ($result instanceof WP_Error ? $result->get_error_message() . ' ' : '') . $byHand];
    }
}
