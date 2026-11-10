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
     */
    public function __construct(private readonly Options $options, private readonly array $integrations)
    {
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
