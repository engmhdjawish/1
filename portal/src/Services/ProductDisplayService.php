<?php

declare(strict_types=1);

namespace Portal\Services;

final class ProductDisplayService
{
    /** @param array<string, mixed> $product @param array<string, mixed> $displayOptions */
    public static function quickViewPayload(array $product, array $displayOptions): array
    {
        $priceMode = (string) ($displayOptions['price_mode'] ?? 'both');
        $showPriceSyp = in_array($priceMode, ['both', 'syp'], true);
        $showPriceUsd = in_array($priceMode, ['both', 'usd'], true);
        $showQuantity = (bool) ($displayOptions['show_quantity'] ?? false);
        $showImages = (bool) ($displayOptions['show_images'] ?? true);
        $showPrice = (bool) ($displayOptions['show_price'] ?? false);

        $hasOffer = !empty($product['has_offer']);
        $packaging = ShareCartService::packaging($product);
        $primaryUnit = ShareCartService::primaryUnitLabel($product);
        $packageUnit = ShareCartService::packageUnitLabel($product);
        $unitSaleSp = ShareCartService::unitSalePriceSp($product);
        $unitSaleUsd = ShareCartService::unitSalePriceUsd($product);
        $packageSaleSp = ShareCartService::packageSalePriceSp($product);
        $packageSaleUsd = ShareCartService::packageSalePriceUsd($product);

        $specs = [];
        foreach ([
            'النوع' => (string) ($product['materialType'] ?? ''),
            'الفئة العمرية' => (string) ($product['ageCategory'] ?? ''),
            'القياس' => (string) ($product['sizeRange'] ?? ''),
            'الشركة' => (string) ($product['manufacturer'] ?? ''),
            'بلد المنشأ' => (string) ($product['countryOfOrigin'] ?? ''),
            'المجموعة' => (string) ($product['groupName'] ?? ''),
        ] as $label => $value) {
            if (trim($value) !== '') {
                $specs[$label] = $value;
            }
        }

        $offer = is_array($product['offer'] ?? null) ? $product['offer'] : null;

        return [
            'guid' => \material_guid($product),
            'name' => (string) ($product['name'] ?? ''),
            'code' => (string) ($product['materialCode'] ?? $product['code'] ?? ''),
            'manufacturer' => (string) ($product['manufacturer'] ?? ''),
            'imageGuid' => \material_image_guid($product),
            'packaging' => $packaging,
            'primaryUnit' => $primaryUnit,
            'packageUnit' => $packageUnit,
            'showImages' => $showImages,
            'showPrice' => $showPrice,
            'showPriceSyp' => $showPriceSyp,
            'showPriceUsd' => $showPriceUsd,
            'showQuantity' => $showQuantity,
            'hasOffer' => $hasOffer,
            'offerBadge' => trim((string) ($product['offer_badge'] ?? '')),
            'unitSaleSp' => $unitSaleSp,
            'unitSaleUsd' => $unitSaleUsd,
            'packageSaleSp' => $packageSaleSp,
            'packageSaleUsd' => $packageSaleUsd,
            'originalUnitSp' => $hasOffer ? (float) ($product['original_unit_sale_price_sp'] ?? 0) : 0.0,
            'originalPackSp' => $hasOffer ? (float) ($product['original_package_sale_price_sp'] ?? 0) : 0.0,
            'originalPackUsd' => $hasOffer ? (float) ($product['original_package_sale_price_usd'] ?? 0) : 0.0,
            'packagesAvailable' => $showQuantity ? \packages_available_display($product) : 0.0,
            'warehouseQty' => (float) ($product['warehouseQuantity'] ?? 0),
            'specs' => $specs,
            'offerMin' => $offer !== null && is_numeric((string) ($offer['min_packages'] ?? ''))
                ? (float) $offer['min_packages'] : null,
            'offerMax' => $offer !== null && is_numeric((string) ($offer['max_packages'] ?? ''))
                ? (float) $offer['max_packages'] : null,
        ];
    }
}
