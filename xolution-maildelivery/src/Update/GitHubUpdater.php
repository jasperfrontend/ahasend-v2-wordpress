<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Update;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Plugin;

/**
 * Updates from GitHub releases. Reads a static update.json from the latest release instead of the GitHub API:
 * the unauthenticated API allows 60 requests per hour per IP, and many sites share a server IP.
 *
 * The repository is the plugin's "Update URI" header, so it is configured in one place.
 */
final class GitHubUpdater
{
    private const CACHE_KEY     = 'xmd_update_info';
    private const CACHE_TTL     = 3 * HOUR_IN_SECONDS;
    private const FAILURE_TTL   = HOUR_IN_SECONDS;
    private const MANIFEST_FILE = 'update.json';

    public function register(): void
    {
        add_filter('update_plugins_github.com', [$this, 'checkForUpdate'], 10, 3);
        add_filter('plugins_api', [$this, 'pluginInformation'], 10, 3);
        add_filter('auto_update_plugin', [$this, 'enableAutoUpdate'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'clearCache']);
    }

    /**
     * Called by wp_update_plugins() for every plugin whose Update URI is on github.com.
     *
     * @param array<string, mixed>|false $update
     * @param array<string, string>      $pluginData
     * @return array<string, mixed>|false
     */
    public function checkForUpdate(mixed $update, array $pluginData, string $pluginFile): mixed
    {
        if ($pluginFile !== Plugin::basename()) {
            return $update;
        }
        $release = $this->latestRelease();
        if ($release === null) {
            return $update;
        }

        // WordPress compares the version itself and only offers it when it's newer.
        return [
            'slug'         => Plugin::SLUG,
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => $release['requires'],
            'requires_php' => $release['requires_php'],
        ];
    }

    /**
     * The "View details" popup. Without this WordPress asks wordpress.org about our slug.
     */
    public function pluginInformation(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== Plugin::SLUG) {
            return $result;
        }
        $release = $this->latestRelease();
        $repo    = $this->repositoryUrl();

        return (object) [
            'name'          => 'Xolution MailDelivery',
            'slug'          => Plugin::SLUG,
            'version'       => $release['version'] ?? \Xolution\MailDelivery\VERSION,
            'author'        => '<a href="https://xolution.nl">Team Xolution</a>',
            'homepage'      => $repo,
            'requires'      => $release['requires'] ?? '',
            'requires_php'  => $release['requires_php'] ?? '',
            'download_link' => $release['package'] ?? '',
            'sections'      => [
                'description' => esc_html__('Sends selected WordPress emails through the AhaSend API instead of the server\'s own mailer.', 'xolution-maildelivery'),
                'changelog'   => sprintf(
                    '<p><a href="%s" target="_blank" rel="noopener">%s</a></p>',
                    esc_url($repo . '/releases'),
                    esc_html__('Release notes on GitHub', 'xolution-maildelivery'),
                ),
            ],
        ];
    }

    /**
     * Auto-updates are forced on, unless wp-config.php defines XMD_DISABLE_AUTO_UPDATE as true: then the site's own
     * auto-update setting applies, e.g. for a staged rollout.
     */
    public function enableAutoUpdate(mixed $update, object $item): mixed
    {
        if (($item->plugin ?? '') !== Plugin::basename()) {
            return $update;
        }

        return defined('XMD_DISABLE_AUTO_UPDATE') && constant('XMD_DISABLE_AUTO_UPDATE') === true ? $update : true;
    }

    public function clearCache(): void
    {
        delete_site_transient(self::CACHE_KEY);
    }

    /**
     * The latest release's manifest, or null when it can't be read or isn't valid.
     *
     * @return array{version: string, package: string, url: string, requires: string, requires_php: string}|null
     */
    private function latestRelease(): ?array
    {
        // "Check again" on Dashboard → Updates skips the cache.
        $force  = is_admin() && isset($_GET['force-check']);
        $cached = $force ? false : get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached['release'];
        }

        $release = $this->fetch();
        set_site_transient(self::CACHE_KEY, ['release' => $release], $release === null ? self::FAILURE_TTL : self::CACHE_TTL);

        return $release;
    }

    /**
     * @return array{version: string, package: string, url: string, requires: string, requires_php: string}|null
     */
    private function fetch(): ?array
    {
        $repo = $this->repositoryUrl();
        if ($repo === '') {
            return null;
        }
        $response = wp_remote_get($repo . '/releases/latest/download/' . self::MANIFEST_FILE, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }

        $version = (string) ($data['version'] ?? '');
        $package = (string) ($data['package'] ?? '');

        // Only install packages from this repository's own releases.
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1 || !str_starts_with($package, $repo . '/releases/download/')) {
            return null;
        }

        return [
            'version'      => $version,
            'package'      => $package,
            'url'          => $repo,
            'requires'     => (string) ($data['requires'] ?? ''),
            'requires_php' => (string) ($data['requires_php'] ?? ''),
        ];
    }

    /**
     * https://github.com/<owner>/<repo>, from the plugin's Update URI header.
     */
    private function repositoryUrl(): string
    {
        $headers = get_file_data(\Xolution\MailDelivery\FILE, ['uri' => 'Update URI']);
        $uri     = untrailingslashit(trim((string) $headers['uri']));

        return preg_match('#^https://github\.com/[\w.-]+/[\w.-]+$#', $uri) === 1 ? $uri : '';
    }
}
