<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\Shield;

defined('ABSPATH') || exit;

use Throwable;

/**
 * A banner on the WordPress login screen telling people that logging in now takes a confirmation link by email.
 *
 * Shown only while Shield actually sends that link: Email Authentication is on and in use (Shield's own check, which
 * includes "all modules enabled" and a passed sending verification) and Email Auto-Login is on. Auto-Login is a
 * ShieldPRO option, so it only counts while the licence is active, exactly as Shield decides whether the link goes
 * into the email. Option names checked against Shield 22.3.4.
 *
 * The text is fixed and translated, so one release changes it on every site. What people need to know beyond that
 * belongs in the help page behind HELP_URL, which can be edited in one place for all sites.
 */
final class LoginNotice
{
    /** The "Learn more" page, the Dutch help article (source: docs/help/). Empty: the banner is shown without a link. */
    public const HELP_URL = 'https://xolution.nl/docs/article/inloggen-op-wordpress-met-2fa/';

    private const NS             = 'FernleafSystems\\Wordpress\\Plugin\\Shield\\';
    private const EMAIL_PROVIDER = self::NS . 'Modules\\LoginGuard\\Lib\\TwoFactor\\Provider\\Email';

    /**
     * Xolution green. The link stays #111: #2E9238 on #EFFFEF is 3.8:1, too little for text (fine for the icon).
     * The ID selector outranks core's `.login .message` without !important.
     */
    public const CSS = <<<'CSS'
        #xmd-login-notice{display:flex;gap:10px;align-items:flex-start;padding:12px;background:#efffef;color:#111;border:1px solid #2e9238;border-left-width:4px;box-shadow:0 1px 2px rgba(0,0,0,.08)}
        #xmd-login-notice p{margin:0;font-size:14px;line-height:1.5;color:#111}
        #xmd-login-notice a{color:#111;text-decoration:underline}
        #xmd-login-notice a:hover,#xmd-login-notice a:focus{color:#2e9238}
        #xmd-login-notice .xmd-login-notice-icon{flex:none;width:22px;height:22px;margin-top:-1px}
        CSS;

    private ?bool $shows = null;

    public function register(): void
    {
        // login_enqueue_scripts runs in the <head>, before login_message renders the body.
        add_action('login_enqueue_scripts', [$this, 'addStyles']);
        add_filter('login_message', [$this, 'prepend']);
    }

    public function addStyles(): void
    {
        if ($this->shows()) {
            wp_add_inline_style('login', self::CSS);
        }
    }

    public function prepend(mixed $message): mixed
    {
        if (!$this->shows()) {
            return $message;
        }

        return $this->render() . (is_string($message) ? $message : '');
    }

    private function shows(): bool
    {
        // wp-login.php sets $action; the notice belongs on the login form only, not on lost password, register, etc.
        return $this->shows ??= ($GLOBALS['action'] ?? 'login') === 'login' && self::shieldSendsLoginLinks();
    }

    public function render(): string
    {
        $text = sprintf(
            /* translators: %s: "New:" in bold. */
            esc_html__('%s when you log in to this website, we\'ll email you a link to confirm it\'s you.', 'xolution-maildelivery'),
            '<strong>' . esc_html__('New:', 'xolution-maildelivery') . '</strong>',
        );

        if (self::HELP_URL !== '') {
            $text .= sprintf(
                ' <a href="%s" target="_blank" rel="noopener">%s<span class="screen-reader-text"> %s</span></a>.',
                esc_url(self::HELP_URL),
                esc_html__('Learn more', 'xolution-maildelivery'),
                esc_html__('(opens in a new tab)', 'xolution-maildelivery'),
            );
        }

        $icon = '<svg class="xmd-login-notice-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            . '<circle cx="12" cy="12" r="12" fill="#2e9238"/>'
            . '<rect x="10.5" y="5" width="3" height="9.5" rx="1.5" fill="#fff"/>'
            . '<circle cx="12" cy="18" r="1.75" fill="#fff"/>'
            . '</svg>';

        return wp_get_admin_notice($icon . '<p>' . $text . '</p>', [
            'id'                 => 'xmd-login-notice',
            'additional_classes' => ['message'],
            'paragraph_wrap'     => false,
        ]);
    }

    /**
     * True when Shield's login email carries the one-click login link. False whenever that can't be determined, so a
     * Shield update that renames something hides the banner instead of breaking the login page.
     */
    public static function shieldSendsLoginLinks(): bool
    {
        if (!function_exists('shield_security_get_plugin')) {
            return false;
        }

        try {
            $provider = self::EMAIL_PROVIDER;
            if (!class_exists($provider) || !method_exists($provider, 'ProviderEnabled') || !$provider::ProviderEnabled()) {
                return false;
            }

            $opts = shield_security_get_plugin()->getController()->opts;

            // optHasAccess() first: optGet() on a premium option without a licence resets it to its default.
            return $opts->optHasAccess('enable_email_auto_login') && $opts->optIs('enable_email_auto_login', 'Y');
        } catch (Throwable) {
            return false;
        }
    }
}
