<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Services\ShareLinkService;

final class SharePageAccess
{
    /** @return array{requires_password: bool, has_access: bool} */
    public static function state(?array $shareLink, string $token): array
    {
        if (!isset($_SESSION['share_link_access']) || !is_array($_SESSION['share_link_access'])) {
            $_SESSION['share_link_access'] = [];
        }

        $requiresPassword = is_array($shareLink) && (bool) (($shareLink['require_password'] ?? 0) ? true : false);
        $hasAccess = $shareLink !== null && (!$requiresPassword || !empty($_SESSION['share_link_access'][$token]));

        return [
            'requires_password' => $requiresPassword,
            'has_access' => $hasAccess,
        ];
    }

    public static function unlock(string $token, string $username, string $password): bool
    {
        if (!ShareLinkService::verifyProtectedAccess($token, $username, $password)) {
            return false;
        }

        if (!isset($_SESSION['share_link_access']) || !is_array($_SESSION['share_link_access'])) {
            $_SESSION['share_link_access'] = [];
        }

        $_SESSION['share_link_access'][$token] = true;

        return true;
    }

    /** @param array<string, mixed>|null $shareLink */
    public static function policyFlags(?array $shareLink): array
    {
        return [
            'show_price' => is_array($shareLink) && (bool) (($shareLink['show_price'] ?? 0) ? true : false),
            'show_quantity' => is_array($shareLink) && (bool) (($shareLink['show_quantity'] ?? 0) ? true : false),
            'allow_cart' => is_array($shareLink) && (bool) (($shareLink['allow_cart'] ?? 0) ? true : false),
            'allow_order' => is_array($shareLink) && (bool) (($shareLink['allow_order'] ?? 0) ? true : false),
        ];
    }
}
