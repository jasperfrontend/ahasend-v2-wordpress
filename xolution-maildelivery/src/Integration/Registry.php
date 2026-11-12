<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Settings\Options;

final class Registry
{
    /** @var array<string, array{integration: Integration, type: EmailType}> keyed by marker */
    private array $types = [];

    /**
     * @param list<Integration> $integrations
     * @param list<string>      $addonIds     IDs of the integrations an add-on plugin added.
     */
    public function __construct(
        private readonly Options $options,
        private readonly array $integrations,
        private readonly array $addonIds = [],
    ) {
        foreach ($integrations as $integration) {
            foreach ($integration->emailTypes() as $type) {
                $this->types[self::marker($integration, $type)] = [
                    'integration' => $integration,
                    'type'        => $type,
                ];
            }
        }
    }

    public static function marker(Integration $integration, EmailType $type): string
    {
        return $integration->id() . '/' . $type->id();
    }

    /** @return list<Integration> */
    public function integrations(): array
    {
        return $this->integrations;
    }

    /**
     * True when the marker can be switched on and take effect on this site now: known, implemented, and its
     * source (Shield, Gravity Forms, ...) active.
     */
    public function isAvailable(string $marker): bool
    {
        $entry = $this->types[$marker] ?? null;

        return $entry !== null && $entry['type']->isImplemented() && $entry['integration']->isAvailable();
    }

    /**
     * The integration's name for a marker, or its ID when this site doesn't know it (e.g. an add-on that isn't
     * installed).
     */
    public function integrationLabel(string $marker): string
    {
        $entry = $this->types[$marker] ?? null;

        return $entry !== null ? $entry['integration']->label() : explode('/', $marker)[0];
    }

    /** True for an integration an add-on plugin added (shown as "PRO" in the settings). */
    public function isAddon(Integration $integration): bool
    {
        return in_array($integration->id(), $this->addonIds, true);
    }

    /**
     * The email type for a marker, only when it may be sent right now: known, implemented, its source available
     * and switched on.
     */
    public function enabledType(string $marker): ?EmailType
    {
        $entry = $this->types[$marker] ?? null;
        if ($entry === null || !$entry['type']->isImplemented() || !$entry['integration']->isAvailable()) {
            return null;
        }

        return in_array($marker, $this->enabledMarkers(), true) ? $entry['type'] : null;
    }

    /**
     * Markers that may be switched on: known and implemented.
     *
     * @param list<string> $markers
     * @return list<string>
     */
    public function filterSwitchable(array $markers): array
    {
        return array_values(array_filter(
            array_unique($markers),
            fn (string $marker): bool => isset($this->types[$marker]) && $this->types[$marker]['type']->isImplemented(),
        ));
    }

    /** @return list<string> */
    public function enabledMarkers(): array
    {
        return array_values(array_map('strval', (array) $this->options->get('enabled_types')));
    }

    public function registerEnabledTypes(): void
    {
        foreach ($this->enabledMarkers() as $marker) {
            if ($this->enabledType($marker) !== null) {
                $this->types[$marker]['type']->register($marker);
            }
        }
    }
}
