<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Settings;

/**
 * The users who may see and change the plugin. Stored as user IDs, so no user name ever lives in the code.
 *
 * When no listed manager exists any more, every administrator counts as a manager, so the plugin can always be
 * recovered from wp-admin.
 */
final class Managers
{
    public function __construct(private readonly Options $options)
    {
    }

    public function isManager(?int $userId = null): bool
    {
        $userId ??= get_current_user_id();
        if ($userId <= 0 || !user_can($userId, 'manage_options')) {
            return false;
        }
        $ids = $this->ids();

        return $ids === [] || in_array($userId, $ids, true);
    }

    /**
     * Listed managers that still exist and are still administrators.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $ids = array_map('intval', (array) $this->options->get('managers'));

        return array_values(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0 && user_can($id, 'manage_options'),
        ));
    }

    public function ensure(int $userId): void
    {
        $ids = $this->ids();
        if (!in_array($userId, $ids, true)) {
            $ids[] = $userId;
            $this->options->update(['managers' => $ids]);
        }
    }

    /**
     * @param list<int> $ids
     */
    public function replace(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0 && user_can($id, 'manage_options'),
        )));
        $this->options->update(['managers' => $ids]);
    }
}
