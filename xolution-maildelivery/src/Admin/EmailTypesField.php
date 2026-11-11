<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Admin;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\Registry;

/**
 * The email-type switches: one accordion section per integration, so the list stays manageable with many
 * integrations. admin.js makes it an accordion; without JavaScript every section is simply open.
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
            $types   = $integration->emailTypes();
            $markers = array_map(static fn ($type): string => Registry::marker($integration, $type), $types);
            $on      = count(array_intersect($markers, $enabled));
            $panelId = 'xmd-types-' . sanitize_html_class($integration->id());
            $open    = $index === 0;

            $status = $integration->isAvailable()
                ? sprintf(
                    /* translators: 1: switched-on email types, 2: all email types */
                    __('%1$d of %2$d on', 'xolution-maildelivery'),
                    $on,
                    count($types),
                )
                : __('not active on this site', 'xolution-maildelivery');

            $rows       = '';
            $selectable = 0;
            foreach ($types as $i => $type) {
                $marker   = $markers[$i];
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

            // "Select all" only helps with two or more switches. admin.js unhides it: without JavaScript it can't work.
            $tools = $selectable < 2 ? '' : sprintf(
                '<div class="xmd-acc__tools" hidden><button type="button" class="button-link xmd-select-all" data-select="%1$s" data-deselect="%2$s">%1$s</button></div>',
                esc_attr__('Select all', 'xolution-maildelivery'),
                esc_attr__('Deselect all', 'xolution-maildelivery'),
            );

            // The same status line with placeholders, so admin.js can keep the count up to date while ticking.
            $statusFormat = $integration->isAvailable()
                ? sprintf(' data-format="%s"', esc_attr(str_replace(['%1$d', '%2$d'], ['{on}', '{total}'], __('%1$d of %2$d on', 'xolution-maildelivery'))))
                : '';

            $html .= sprintf(
                '<div class="xmd-acc__item%s">'
                . '<h3 class="xmd-acc__heading"><button type="button" class="xmd-acc__trigger" aria-expanded="%s" aria-controls="%s">'
                . '<span class="xmd-acc__title">%s</span><span class="xmd-acc__status%s"%s>%s</span>'
                . '<span class="xmd-acc__chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>'
                . '</button></h3>'
                . '<div class="xmd-acc__panel" id="%s" role="region"%s>%s<ul class="xmd-type-list">%s</ul></div>'
                . '</div>',
                $open ? ' is-open' : '',
                $open ? 'true' : 'false',
                esc_attr($panelId),
                esc_html($integration->label()),
                $on > 0 ? ' has-on' : '',
                $statusFormat,
                esc_html($status),
                esc_attr($panelId),
                $open ? '' : ' hidden',
                $tools,
                $rows,
            );
        }

        // Without JavaScript there is no way to open a section, so show them all.
        return '<div class="xmd-acc" data-xmd-accordion>' . $html . '</div>'
            . '<noscript><style>.xmd-acc__panel[hidden]{display:block}.xmd-acc__chevron{display:none}</style></noscript>';
    }
}
