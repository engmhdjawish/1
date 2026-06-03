<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;

final class StorePolicyService
{
    /** @return array<string, mixed>|null */
    public static function guestPolicy(): ?array
    {
        $row = Database::pdo()->query(
            'SELECT ap.*
             FROM store_guest_settings s
             INNER JOIN access_policies ap ON ap.id = s.access_policy_id
             LIMIT 1'
        )->fetch();

        return $row ?: null;
    }
}
