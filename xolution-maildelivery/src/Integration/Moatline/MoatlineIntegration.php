<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\Moatline;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\Integration;

/**
 * Moatline Malware Scanner (moatline-malware-scanner) and its Pro add-on. Checked against Moatline 1.4.4 and
 * Moatline Pro 1.5.2.
 *
 * Moatline sends ten alerts; they are switched in four sets, by what they are about. All of them go to Moatline's
 * alert address and only while its "Email alerts" switch is on (Malware Scanner → Settings → Alerts).
 */
final class MoatlineIntegration implements Integration
{
    public function id(): string
    {
        return 'moatline';
    }

    public function label(): string
    {
        return __('Moatline Malware Scanner', 'xolution-maildelivery');
    }

    public function isAvailable(): bool
    {
        // Defined by Moatline's main plugin file, which WordPress loads before init. The Pro add-on needs it too.
        return function_exists('nnms_send_alert');
    }

    public function emailTypes(): array
    {
        // Built on every call, not cached: the labels must be translated in the current request's language.
        return [
            new MoatlineAlertEmail(
                'threats',
                __('Threats and vulnerabilities', 'xolution-maildelivery'),
                __('A scan found threats, or a plugin, theme or WordPress version on this site has a serious known vulnerability.', 'xolution-maildelivery'),
                [
                    ['NNMS_Scanner', 'finalise'],
                    ['NNMS_Vulnerabilities', 'refresh_and_alert'],
                ],
            ),
            new MoatlineAlertEmail(
                'plugin-watch',
                __('New plugins', 'xolution-maildelivery'),
                __('A plugin was installed, a just-installed plugin was switched on, or a new file appeared in wp-content/mu-plugins. Only while "Email me when a plugin is installed" is on in Moatline.', 'xolution-maildelivery'),
                [
                    ['NNMS_Watch', 'on_install'],
                    ['NNMS_Watch', 'on_activate'],
                    ['NNMS_Watch', 'check_mu_plugins'],
                ],
            ),
            new MoatlineAlertEmail(
                'lock',
                __('Lock', 'xolution-maildelivery'),
                __('The site was unlocked (also from the agency dashboard), unlocking was paused after wrong attempts, or a new administrator was blocked while the site was locked.', 'xolution-maildelivery'),
                [
                    ['NNMS_Site_Lock', 'do_unlock'],
                    ['NNMS_Site_Lock', 'handle_unlock'],
                    ['NNMS_Site_Lock', 'guard_role_meta'],
                    ['NNMS_Pro_Agency_Control', 'run'],
                ],
            ),
            new MoatlineAlertEmail(
                'malicious-script',
                __('Malicious script blocked or saved', 'xolution-maildelivery'),
                __('Someone saved a malicious script into a post, page or Elementor data. Only while "Email me straight away" is on in Moatline. Moatline Pro.', 'xolution-maildelivery'),
                [
                    ['NNMS_Pro_Protect', 'alert'],
                ],
            ),
        ];
    }
}
