<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks up a product by its barcode (EAN/UPC) in the free Open Food Facts database.
 */
class ProductLookup
{
    /**
     * @return array{name: string, quantity: string}|null Null if the code is unknown or the service is unreachable.
     */
    public function byBarcode(string $code): ?array
    {
        if (! self::isValidBarcode($code)) {
            return null;
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'E11LifeOS/1.0 (household shopping list)'])
                ->get('https://world.openfoodfacts.org/api/v2/product/'.$code.'.json', [
                    'fields' => 'product_name,product_name_de,generic_name_de,brands,quantity',
                ]);
        } catch (Throwable) {
            return null;
        }

        $product = $response->successful() ? $response->json('product') : null;

        if (! is_array($product)) {
            return null;
        }

        $name = trim((string) ($product['product_name_de'] ?? '')) ?: trim((string) ($product['product_name'] ?? '')) ?: trim((string) ($product['generic_name_de'] ?? ''));
        $brand = trim(explode(',', (string) ($product['brands'] ?? ''))[0]);

        if ($name === '') {
            return null;
        }

        return [
            'name' => mb_substr($brand !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($brand)) ? $brand.' '.$name : $name, 0, 255),
            'quantity' => mb_substr(trim((string) ($product['quantity'] ?? '')), 0, 255),
        ];
    }

    public static function isValidBarcode(string $code): bool
    {
        return (bool) preg_match('/^\d{8,14}$/', $code);
    }
}
