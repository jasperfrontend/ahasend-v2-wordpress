<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Admin;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\EmailTypeGroup;
use Xolution\MailDelivery\Integration\GroupsEmailTypes;
use Xolution\MailDelivery\Integration\Integration;
use Xolution\MailDelivery\Integration\Registry;

/**
 * The email-type switches: one accordion section per integration, so the list stays manageable with many
 * integrations. admin.js makes it an accordion; without JavaScript every section is simply open.
 *
 * An integration with many types shows them in groups, each under a sub-heading with its own "Select all".
 * Otherwise the section has one "Select all" for all of its types.
 */
final class EmailTypesField
{
    public function __construct(private readonly Registry $registry)
    {
    }

    public function render(): string
    {
        $enabled = $this->registry->enabledMarkers();
        $html    = '';

        foreach ($this->registry->integrations() as $index => $integration) {
            $groups  = $integration instanceof GroupsEmailTypes
                ? $integration->emailTypeGroups()
                : [new EmailTypeGroup('', $integration->emailTypes())];
            $grouped = $integration instanceof GroupsEmailTypes;
            $types   = $integration->emailTypes();
            $markers = array_map(static fn ($type): string => Registry::marker($integration, $type), $types);
            $on      = count(array_intersect($markers, $enabled));
            $panelId = 'xmd-types-' . sanitize_html_class($integration->id());
            $open    = $index === 0;
            $pro     = $this->registry->isAddon($integration);

            $status = $integration->isAvailable()
                ? sprintf(
                    /* translators: 1: switched-on email types, 2: all email types */
                    __('%1$d of %2$d on', 'xolution-maildelivery'),
                    $on,
                    count($types),
                )
                : __('not active on this site', 'xolution-maildelivery');

            $body = '';
            foreach ($groups as $group) {
                [$rows, $selectable] = $this->rows($integration, $group, $enabled);
                $body .= $grouped ? $this->group($group, $rows, $selectable) : $this->sectionTools($selectable) . $rows;
            }

            // The same status line with placeholders, so admin.js can keep the count up to date while ticking.
            $statusFormat = $integration->isAvailable()
                ? sprintf(' data-format="%s"', esc_attr(str_replace(['%1$d', '%2$d'], ['{on}', '{total}'], __('%1$d of %2$d on', 'xolution-maildelivery'))))
                : '';

            $html .= sprintf(
                '<div class="xmd-acc__item%s%s" data-xmd-select-scope>'
                . '<h3 class="xmd-acc__heading"><button type="button" class="xmd-acc__trigger" aria-expanded="%s" aria-controls="%s">'
                . '<span class="xmd-acc__title">%s</span>%s<span class="xmd-acc__status%s"%s>%s</span>'
                . '<span class="xmd-acc__chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>'
                . '</button></h3>'
                . '<div class="xmd-acc__panel" id="%s" role="region"%s>%s</div>'
                . '</div>',
                $open ? ' is-open' : '',
                $pro ? ' is-pro' : '',
                $open ? 'true' : 'false',
                esc_attr($panelId),
                esc_html($integration->label()),
                // A product name, not translated.
                $pro ? '<span class="xmd-acc__badge">PRO</span>' : '',
                $on > 0 ? ' has-on' : '',
                $statusFormat,
                esc_html($status),
                esc_attr($panelId),
                $open ? '' : ' hidden',
                $body,
            );
        }

        // Without JavaScript there is no way to open a section, so show them all.
        return '<div class="xmd-acc" data-xmd-accordion>' . $html . '</div>'
            . '<noscript><style>.xmd-acc__panel[hidden]{display:block}.xmd-acc__chevron{display:none}</style></noscript>';
    }

    /**
     * @param list<string> $enabled
     * @return array{0: string, 1: int} The list markup and how many of its switches can be ticked.
     */
    private function rows(Integration $integration, EmailTypeGroup $group, array $enabled): array
    {
        $rows       = '';
        $selectable = 0;
        foreach ($group->types as $type) {
            $marker   = Registry::marker($integration, $type);
            $inputId  = 'xmd-type-' . sanitize_html_class(str_replace('/', '-', $marker));
            $disabled = !$type->isImplemented() || !$integration->isAvailable();
            $selectable += $disabled ? 0 : 1;
            // The whole row is the label, so a click anywhere in it toggles the checkbox.
            $rows    .= sprintf(
                '<li><label class="xmd-type%s" for="%s"><input type="checkbox" id="%s" name="enabled_types[]" value="%s"%s%s>'
                . '<span class="xmd-type__label">%s%s</span>%s</label></li>',
                $disabled ? ' is-disabled' : '',
                esc_attr($inputId),
                esc_attr($inputId),
                esc_attr($marker),
                checked(in_array($marker, $enabled, true), true, false),
                disabled($disabled, true, false),
                esc_html($type->label()),
                $type->isImplemented() ? '' : ' <em>(' . esc_html__('not available yet', 'xolution-maildelivery') . ')</em>',
                $type->description() === '' ? '' : '<span class="xmd-type__description">' . esc_html($type->description()) . '</span>',
            );
        }

        return ['<ul class="xmd-type-list">' . $rows . '</ul>', $selectable];
    }

    /**
     * "Select all" for a whole section, right-aligned under the status count it changes. Only with two or more
     * switches; admin.js unhides it, because without JavaScript it can't work.
     */
    private function sectionTools(int $selectable): string
    {
        return $selectable < 2 ? '' : '<div class="xmd-acc__tools" hidden>' . $this->selectAllButton() . '</div>';
    }

    private function group(EmailTypeGroup $group, string $rows, int $selectable): string
    {
        $headingId = 'xmd-group-' . wp_unique_id();

        return sprintf(
            '<div class="xmd-group" role="group" aria-labelledby="%s" data-xmd-select-scope>'
            . '<div class="xmd-group__heading"><div class="xmd-group__text"><h4 class="xmd-group__title" id="%s">%s</h4>%s</div>%s</div>'
            . '%s</div>',
            esc_attr($headingId),
            esc_attr($headingId),
            esc_html($group->label),
            $group->description === '' ? '' : '<p class="xmd-group__description">' . esc_html($group->description) . '</p>',
            $selectable < 2 ? '' : $this->selectAllButton(true),
            $rows,
        );
    }

    private function selectAllButton(bool $hidden = false): string
    {
        return sprintf(
            '<button type="button" class="button-link xmd-select-all" data-select="%1$s" data-deselect="%2$s"%3$s>%1$s</button>',
            esc_attr__('Select all', 'xolution-maildelivery'),
            esc_attr__('Deselect all', 'xolution-maildelivery'),
            $hidden ? ' hidden' : '',
        );
    }
}
