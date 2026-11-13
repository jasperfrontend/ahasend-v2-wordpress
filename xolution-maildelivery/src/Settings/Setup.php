<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Settings;

defined('ABSPATH') || exit;

use Closure;
use InvalidArgumentException;
use WP_Error;
use Xolution\MailDelivery\Api\AccountClient;
use Xolution\MailDelivery\Api\Client;
use Xolution\MailDelivery\Api\SendResult;
use Xolution\MailDelivery\Mail\Sender;
use Xolution\MailDelivery\Plugin;

/**
 * The setup wizard's work, step by step (since v0.0.19).
 *
 * With a setup key (an AhaSend key with api-keys:write and domains:read): check the sending domain, create a key
 * for this site that may only send from that domain and only from the server IPs, and test it. Without one, the
 * site's saved key is kept and tested. Then save the settings, switch on the chosen emails and, if asked, send a
 * test email. A step that fails stops the ones after it, and nothing is saved before the key works, so a failed
 * run never leaves a working site half-changed.
 *
 * The setup key only passes through this request: it is never stored, logged or put in the report.
 */
final class Setup
{
    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** @var Closure(string $setupKey, string $accountId): AccountClient */
    private readonly Closure $accountClient;

    /** @var Closure(string $apiKey, string $accountId): SendResult */
    private readonly Closure $ping;

    /**
     * @param (Closure(string, string): AccountClient)|null $accountClient For tests.
     * @param (Closure(string, string): SendResult)|null    $ping          For tests.
     */
    public function __construct(private readonly Plugin $plugin, ?Closure $accountClient = null, ?Closure $ping = null)
    {
        $this->accountClient = $accountClient ?? static fn (string $key, string $account): AccountClient => new AccountClient($key, $account);
        $this->ping          = $ping ?? static fn (string $key, string $account): SendResult => (new Client($key, $account))->ping();
    }

    /**
     * @param array{account_id: string, sending_domain: string, server_ips: list<string>, slack_webhook: string, sandbox: bool, enabled_types: list<string>, skipped_types?: list<string>} $config
     * @param string $setupKey   '' to keep the site's saved key.
     * @param string $testEmail  '' for no test email.
     * @return array{ok: bool, steps: list<array{label: string, status: string, detail: string}>, skipped: list<array{label: string, count: int}>}
     */
    public function run(array $config, string $setupKey, string $testEmail = ''): array
    {
        $report = ['ok' => false, 'steps' => [], 'skipped' => []];
        $step   = static function (string $label, string $status, string $detail = '') use (&$report): void {
            $report['steps'][] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        };
        $setupKey = trim($setupKey);

        // 1. The details, before anything goes to AhaSend.
        try {
            $accountId = Configurator::validAccountId($config['account_id']);
            $domain    = Configurator::validSendingDomain($config['sending_domain']);
            $ips       = Configurator::validServerIps($config['server_ips']);
            $webhook   = Configurator::validSlackWebhook($config['slack_webhook']);
            if ($accountId === '' || $domain === '') {
                throw new InvalidArgumentException(__('The account ID and the sending domain are both needed.', 'xolution-maildelivery'));
            }
            if ($setupKey !== '' && $ips === []) {
                throw new InvalidArgumentException(__('At least one server IP is needed: the new key only works from those addresses.', 'xolution-maildelivery'));
            }
            if ($setupKey !== '' && !str_starts_with($setupKey, 'aha-sk-')) {
                throw new InvalidArgumentException(__('The setup key must be an AhaSend API v2 key, starting with "aha-sk-".', 'xolution-maildelivery'));
            }
        } catch (InvalidArgumentException $e) {
            $step(__('Details checked', 'xolution-maildelivery'), self::STATUS_FAILED, $e->getMessage());

            return $report;
        }
        $step(__('Details checked', 'xolution-maildelivery'), self::STATUS_OK);

        // 2-3. With a setup key: the domain, then a key for this site.
        if ($setupKey !== '') {
            $account = ($this->accountClient)($setupKey, $accountId);

            $checked = $account->checkDomain($domain);
            if ($checked instanceof WP_Error) {
                $step(__('Sending domain checked', 'xolution-maildelivery'), self::STATUS_FAILED, $checked->get_error_message());

                return $report;
            }
            $step(__('Sending domain checked', 'xolution-maildelivery'), self::STATUS_OK, $domain);

            $created = $account->createSendKey(self::keyLabel(), $domain, $ips, wp_generate_uuid4());
            if ($created instanceof WP_Error) {
                $step(__('API key created', 'xolution-maildelivery'), self::STATUS_FAILED, $created->get_error_message());

                return $report;
            }
            ['secret' => $apiKey, 'id' => $apiKeyId] = $created;
            $step(__('API key created', 'xolution-maildelivery'), self::STATUS_OK, sprintf(
                /* translators: 1: key label, 2: comma-separated IP addresses */
                __('"%1$s", only for sending from this domain, only from %2$s', 'xolution-maildelivery'),
                self::keyLabel(),
                implode(', ', $ips),
            ));
        } else {
            $apiKeyId = '';
            $apiKey   = Secrets::decrypt((string) $this->plugin->options->get('api_key'));
            if ($apiKey === null || $apiKey === '') {
                $step(__('API key', 'xolution-maildelivery'), self::STATUS_FAILED, __('This site has no working API key yet: fill in a setup key.', 'xolution-maildelivery'));

                return $report;
            }
            $step(__('API key', 'xolution-maildelivery'), self::STATUS_OK, __('The saved key is kept.', 'xolution-maildelivery'));
        }

        // 4. Does AhaSend accept the key from this server?
        $ping = ($this->ping)($apiKey, $accountId);
        if (!$ping->ok) {
            $detail = $ping->httpStatus === 403
                ? __('AhaSend refused the key from this server. Is this server\'s outgoing IP in the server IPs?', 'xolution-maildelivery') . ' (' . $ping->error . ')'
                : (string) $ping->error;
            $step(__('Connection tested', 'xolution-maildelivery'), self::STATUS_FAILED, $detail);

            return $report;
        }
        $step(__('Connection tested', 'xolution-maildelivery'), self::STATUS_OK);

        // 5. Save. Only now: everything before this could still fail.
        $configurator = new Configurator($this->plugin);
        $configurator->setAccountId($accountId);
        $configurator->setSendingDomain($domain);
        $configurator->setSandbox($config['sandbox']);
        if ($setupKey !== '') {
            $configurator->setApiKey($apiKey, $apiKeyId);
            $configurator->setServerIps($ips);
        } elseif ($ips !== []) {
            $configurator->setServerIps($ips);
        }
        if ($webhook !== '') {
            $configurator->setSlackWebhook($webhook);
        }
        $step(__('Settings saved', 'xolution-maildelivery'), self::STATUS_OK, $config['sandbox'] ? __('Sandbox mode is on.', 'xolution-maildelivery') : '');

        // 6. The emails: only those that work on this site now; the rest is reported, not stored.
        $wanted    = array_values(array_unique(array_merge($config['enabled_types'], $config['skipped_types'] ?? [])));
        $available = array_values(array_filter($wanted, fn (string $m): bool => $this->plugin->registry->isAvailable($m)));
        $skipped   = [];
        foreach (array_diff($wanted, $available) as $marker) {
            $label           = $this->plugin->registry->integrationLabel($marker);
            $skipped[$label] = ($skipped[$label] ?? 0) + 1;
        }
        foreach ($skipped as $label => $count) {
            $report['skipped'][] = ['label' => (string) $label, 'count' => $count];
        }
        try {
            $configurator->setEnabledTypes(array_values(array_intersect($available, $config['enabled_types'])));
            $on = count($this->plugin->registry->enabledMarkers());
            $step(__('Emails switched on', 'xolution-maildelivery'), self::STATUS_OK, sprintf(
                /* translators: %d: number of email types */
                __('Email types going through AhaSend: %d.', 'xolution-maildelivery'),
                $on,
            ));
        } catch (InvalidArgumentException $e) {
            $step(__('Emails switched on', 'xolution-maildelivery'), self::STATUS_FAILED, $e->getMessage());

            return $report;
        }

        // 7. A real email, if asked.
        if ($testEmail !== '') {
            $result = (new Sender($this->plugin))->sendTest($testEmail);
            $step(
                __('Test email sent', 'xolution-maildelivery'),
                $result->ok ? self::STATUS_OK : self::STATUS_FAILED,
                $result->ok ? $testEmail : (string) $result->error,
            );
            if (!$result->ok) {
                return $report;
            }
        }

        $report['ok'] = true;

        return $report;
    }

    /** How the key is called in AhaSend's dashboard, so whoever manages the account can find (and clean up) a site's keys. */
    public static function keyLabel(): string
    {
        return 'WordPress: ' . (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }
}
