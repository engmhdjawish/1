<?php

declare(strict_types=1);

namespace Portal\Services;

/** سلة المتجر العام (زائر / عميل مسجّل) — بدون رابط مشاركة. */
final class StoreCartService
{
    public const TOKEN = '__public_store__';

    /** @return array<string, array<string, mixed>> */
    public static function items(): array
    {
        return ShareCartService::items(self::TOKEN);
    }

    public static function itemCount(): int
    {
        return ShareCartService::itemCount(self::TOKEN);
    }

    /** @return array{total_sp: float, total_usd: float} */
    public static function totals(): array
    {
        return ShareCartService::totals(self::TOKEN);
    }

    /** @return list<array<string, mixed>> */
    public static function enrichedItems(): array
    {
        return array_values(array_map(
            static fn (array $line): array => ShareCartService::enrichLineWithOffer($line),
            self::items()
        ));
    }

    /** @param array<string, mixed> $line */
    public static function add(array $line, float $quantity = 1.0): array
    {
        $line = ShareCartService::enrichLineWithOffer($line);

        return ShareCartService::add(self::TOKEN, '', $line, $quantity);
    }

    public static function updateQuantity(string $materialGuid, float $quantity): array
    {
        return ShareCartService::updateQuantity(self::TOKEN, $materialGuid, $quantity);
    }

    public static function remove(string $materialGuid): bool
    {
        return ShareCartService::remove(self::TOKEN, $materialGuid);
    }

    public static function clear(): void
    {
        ShareCartService::clear(self::TOKEN);
    }

    /** @return array<string, array<string, mixed>> */
    public static function unavailableItems(): array
    {
        return ShareCartService::unavailableItems(self::TOKEN);
    }

    public static function clearUnavailable(): void
    {
        ShareCartService::clearUnavailable(self::TOKEN);
    }

    public static function removeUnavailable(string $materialGuid): bool
    {
        return ShareCartService::removeUnavailable(self::TOKEN, $materialGuid);
    }

    /** @return array{moved: list<string>, notices: list<string>} */
    public static function reconcileStock(): array
    {
        return ShareCartService::reconcileStock(self::TOKEN);
    }

    /** @param list<string> $submittedGuids @param list<array<string, mixed>> $unavailableLines */
    public static function finalizeAfterSuccessfulOrder(array $submittedGuids, array $unavailableLines): void
    {
        ShareCartService::finalizeAfterSuccessfulOrder(self::TOKEN, $submittedGuids, $unavailableLines);
    }

    /** @param list<array<string, mixed>> $unavailableLines */
    public static function stashUnavailableLines(array $unavailableLines): void
    {
        ShareCartService::stashUnavailableLines(self::TOKEN, $unavailableLines);
    }
}
