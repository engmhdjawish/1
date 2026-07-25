<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Services\ShareCartService;
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

    /** @return array<string, mixed> */
    public static function catalogDisplayOptions(?array $shareLink, bool $hasAccess): array
    {
        $policy = self::policyFlags($shareLink);
        $shareOptions = is_array($shareLink['options'] ?? null) ? (array) $shareLink['options'] : [];
        $showImages = (bool) (($shareOptions['show_images'] ?? true) ? true : false);
        $priceMode = (string) ($shareOptions['price_mode'] ?? 'both');
        $showPriceSyp = in_array($priceMode, ['both', 'syp'], true);
        $showPriceUsd = in_array($priceMode, ['both', 'usd'], true);

        return [
            'show_images' => $showImages,
            'show_price' => $showPriceSyp || $showPriceUsd,
            'show_quantity' => (bool) ($policy['show_quantity'] ?? false),
            'allow_cart' => $hasAccess && (bool) ($policy['allow_cart'] ?? false),
            'allow_order' => $hasAccess && (bool) ($policy['allow_order'] ?? false),
            'price_mode' => $priceMode,
        ];
    }

    /**
     * Resolve share-link browsing context for product pages and related store views.
     *
     * @return array{
     *     token: string,
     *     share_link: array<string, mixed>,
     *     share_link_id: string,
     *     has_access: bool,
     *     display_options: array<string, mixed>
     * }|null
     */
    public static function resolveShareBrowseContext(?string $explicitToken = null, ?string $returnUrl = null): ?array
    {
        $token = trim((string) ($explicitToken ?? ''));
        if ($token === '' && $returnUrl !== null) {
            $token = share_token_from_return_url($returnUrl);
        }
        if ($token === '') {
            $token = ShareCartService::activeToken() ?? '';
        }
        if ($token === '') {
            return null;
        }

        $shareLink = ShareLinkService::getByPublicToken($token);
        if ($shareLink === null) {
            return null;
        }

        $access = self::state($shareLink, $token);
        if (!$access['has_access']) {
            return null;
        }

        return [
            'token' => $token,
            'share_link' => $shareLink,
            'share_link_id' => (string) ($shareLink['id'] ?? ''),
            'has_access' => true,
            'display_options' => self::catalogDisplayOptions($shareLink, true),
        ];
    }
}
