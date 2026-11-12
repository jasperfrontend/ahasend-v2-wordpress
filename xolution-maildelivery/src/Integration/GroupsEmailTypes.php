<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration;

defined('ABSPATH') || exit;

/**
 * An integration with enough email types to show them in groups. emailTypes() must return the same types, in
 * the same order, as the groups together.
 */
interface GroupsEmailTypes extends Integration
{
    /** @return list<EmailTypeGroup> */
    public function emailTypeGroups(): array;
}
