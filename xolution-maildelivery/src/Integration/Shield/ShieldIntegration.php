<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\Shield;

defined('ABSPATH') || exit;

use Closure;
use Xolution\MailDelivery\Integration\EmailTypeGroup;
use Xolution\MailDelivery\Integration\GroupsEmailTypes;

/**
 * Shield Security (wp-simple-firewall). Class and method names checked against Shield 22.3.4.
 */
final class ShieldIntegration implements GroupsEmailTypes
{
    private const NS = 'FernleafSystems\\Wordpress\\Plugin\\Shield\\';

    private const INSTANT_ALERTS = self::NS . 'Components\\CompCons\\InstantAlertsCon';
    private const ALERT_HANDLERS = self::NS . 'Components\\CompCons\\InstantAlerts\\Handlers\\';
    private const REPORTS        = self::NS . 'Modules\\Plugin\\Lib\\Reporting\\ReportGenerator';

    public function id(): string
    {
        return 'shield';
    }

    public function label(): string
    {
        return __('Shield Security', 'xolution-maildelivery');
    }

    public function isAvailable(): bool
    {
        // Defined by Shield's main plugin file, which WordPress loads before init.
        return function_exists('icwp_wpsf_init');
    }

    public function emailTypes(): array
    {
        return array_merge(...array_map(static fn (EmailTypeGroup $group): array => $group->types, $this->emailTypeGroups()));
    }

    public function emailTypeGroups(): array
    {
        // Built on every call, not cached: the labels must be translated in the current request's language.
        return [
            new EmailTypeGroup(__('Two-factor login', 'xolution-maildelivery'), [
                new TwoFactorLoginEmail(),
                new EmailSendingVerification(),
            ]),

            new EmailTypeGroup(
                __('Instant alerts', 'xolution-maildelivery'),
                [
                    $this->instantAlert(
                        'alert-shield-deactivated',
                        __('Shield deactivated', 'xolution-maildelivery'),
                        __('Sent when Shield is deactivated.', 'xolution-maildelivery'),
                        'AlertHandlerShieldDeactivated',
                    ),
                    $this->instantAlert(
                        'alert-cloaked-plugins',
                        __('Cloaked plugins', 'xolution-maildelivery'),
                        __('Sent when a plugin is hidden from the WordPress plugin list.', 'xolution-maildelivery'),
                        'AlertHandlerCloakedPlugins',
                    ),
                    $this->instantAlert(
                        'alert-admin-accounts',
                        __('Admin accounts', 'xolution-maildelivery'),
                        __('Sent when an administrator account is added, deleted, demoted or promoted, or its password or email address changes. ShieldPRO.', 'xolution-maildelivery'),
                        'AlertHandlerAdmins',
                    ),
                    $this->instantAlert(
                        'alert-admin-login',
                        __('Admin login', 'xolution-maildelivery'),
                        __('Sent when an administrator logs in.', 'xolution-maildelivery'),
                        'AlertHandlerAdminLogin',
                    ),
                    $this->instantAlert(
                        'alert-firewall-block',
                        __('Firewall blocks', 'xolution-maildelivery'),
                        __('Sent when the firewall blocks a request: one email per block.', 'xolution-maildelivery'),
                        'AlertHandlerFirewallBlock',
                    ),
                    $this->instantAlert(
                        'alert-vulnerabilities',
                        __('Vulnerabilities', 'xolution-maildelivery'),
                        __('Sent when the vulnerability scanner finds a vulnerable plugin or theme. ShieldPRO.', 'xolution-maildelivery'),
                        'AlertHandlerVulnerabilities',
                    ),
                    $this->instantAlert(
                        'alert-filelocker',
                        __('FileLocker changes', 'xolution-maildelivery'),
                        __('Sent when a file protected by FileLocker changes. ShieldPRO.', 'xolution-maildelivery'),
                        'AlertHandlerFileLocker',
                    ),
                ],
                __('To Shield\'s Report Email, or the site admin email when that is empty.', 'xolution-maildelivery'),
            ),

            new EmailTypeGroup(
                __('Reports', 'xolution-maildelivery'),
                [
                    new ShieldMailerEmailType(
                        'report-alert',
                        __('Alert report', 'xolution-maildelivery'),
                        __('The "Security Alert" email, sent at the Alert Reports Frequency.', 'xolution-maildelivery'),
                        self::REPORTS,
                        'sendNotificationEmail',
                        self::reportOfType('alt'),
                    ),
                    new ShieldMailerEmailType(
                        'report-info',
                        __('Info report', 'xolution-maildelivery'),
                        __('The "Security Report" email, sent at the Info Reports Frequency.', 'xolution-maildelivery'),
                        self::REPORTS,
                        'sendNotificationEmail',
                        self::reportOfType('nfo'),
                    ),
                ],
                __('To Shield\'s Report Email, or the site admin email when that is empty.', 'xolution-maildelivery'),
            ),

            new EmailTypeGroup(__('Security notices', 'xolution-maildelivery'), [
                new ShieldMailerEmailType(
                    'login-notice',
                    __('Login notice (to the user)', 'xolution-maildelivery'),
                    __('"A login to your WordPress account just occurred", sent at every login when User Login Notification Email is on.', 'xolution-maildelivery'),
                    self::NS . 'Modules\\UserManagement\\Lib\\Session\\UserSessionHandler',
                    'sendLoginNotifications',
                ),
                new ShieldMailerEmailType(
                    'backup-code-used',
                    __('Backup login code used (to the user)', 'xolution-maildelivery'),
                    __('Sent to a user who just logged in with their 2FA backup code.', 'xolution-maildelivery'),
                    self::NS . 'Modules\\LoginGuard\\Lib\\TwoFactor\\Provider\\BackupCodes',
                    'sendBackupCodeUsedEmail',
                ),
                new ShieldMailerEmailType(
                    'ip-unblock',
                    __('IP unblock link (to the user)', 'xolution-maildelivery'),
                    __('The link a logged-in user can request to unblock their own blocked IP address.', 'xolution-maildelivery'),
                    self::NS . 'Modules\\IPs\\Lib\\AutoUnblock\\AutoUnblockMagicLink',
                    'processEmailSend',
                ),
                new ShieldMailerEmailType(
                    'security-admin-removal-confirm',
                    __('Security Admin removal confirmation', 'xolution-maildelivery'),
                    __('The link that must be clicked before Security Admin is removed by email. Sent to the Report Email.', 'xolution-maildelivery'),
                    self::NS . 'Modules\\SecurityAdmin\\Lib\\SecurityAdmin\\Ops\\RemoveSecAdmin',
                    'sendConfirmationEmail',
                ),
                new ShieldMailerEmailType(
                    'security-admin-removed',
                    __('Security Admin removed', 'xolution-maildelivery'),
                    __('Tells the Report Email that the Security Admin restrictions were removed.', 'xolution-maildelivery'),
                    self::NS . 'Modules\\SecurityAdmin\\Lib\\SecurityAdmin\\Ops\\RemoveSecAdmin',
                    'sendNotificationEmail',
                ),
            ]),
        ];
    }

    /**
     * Every instant alert is sent by InstantAlertsCon::sendAlert($handler, $data); the handler says which one.
     */
    private function instantAlert(string $id, string $label, string $description, string $handler): ShieldMailerEmailType
    {
        $handlerClass = self::ALERT_HANDLERS . $handler;

        return new ShieldMailerEmailType(
            $id,
            $label,
            $description,
            self::INSTANT_ALERTS,
            'sendAlert',
            static fn (array $args): bool => isset($args[0]) && is_object($args[0]) && is_a($args[0], $handlerClass),
        );
    }

    /**
     * ReportGenerator::sendNotificationEmail(ReportVO $report) sends both reports; ReportVO::$type says which.
     *
     * @param string $type Shield's Reporting\Constants: 'alt' for the alert report, 'nfo' for the info report.
     * @return Closure(list<mixed>): bool
     */
    private static function reportOfType(string $type): Closure
    {
        return static fn (array $args): bool => isset($args[0]) && is_object($args[0]) && ($args[0]->type ?? null) === $type;
    }
}
