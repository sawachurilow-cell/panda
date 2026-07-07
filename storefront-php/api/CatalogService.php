<?php
// Сборка витринного каталога (аналог server/catalogService.js): товары + цены + фото,
// с наценкой и файловым кэшем под лимиты API. В демо-режиме источник — mock.php.
if (!defined('STOREFRONT')) { http_response_code(403); exit; }

final class CatalogService
{
    private static ?B2BClient $client = null;

    private static function client(): B2BClient
    {
        return self::$client ??= new B2BClient();
    }

    public static function categories(): array
    {
        return Cache::getOrLoad('categories', CONFIG['catalogTtl'], function () {
            return CONFIG['useMock'] ? mock_category_tree() : self::client()->getCategoryTree();
        });
    }

    // Справочник товаров: sku => карточка.
    private static function productIndex(): array
    {
        return Cache::getOrLoad('products', CONFIG['catalogTtl'], function () {
            $list = CONFIG['useMock'] ? mock_products() : self::client()->getProducts();
            $bySku = [];
            foreach ($list as $p) {
                $bySku[(int) $p['sku']] = $p;
            }
            return $bySku;
        });
    }

    // Наличие/цены: sku => данные.
    private static function activeIndex(): array
    {
        return Cache::getOrLoad('active', CONFIG['pricesTtl'], function () {
            $list = CONFIG['useMock'] ? mock_active() : self::client()->getActiveProducts();
            $bySku = [];
            foreach ($list as $a) {
                $bySku[(int) $a['sku']] = $a;
            }
            return $bySku;
        });
    }

    private static function stockLevel($qty): string
    {
        return match ($qty) {
            '***' => 'high',
            '**'  => 'mid',
            '*'   => 'low',
            default => 'unknown',
        };
    }

    private static function toDisplay(array $p, array $a): array
    {
        return [
            'sku'          => (int) $p['sku'],
            'name'         => $p['name'] ?? '',
            'part'         => $p['part'] ?? '',
            'vendor'       => $p['vendor'] ?? '',
            'category'     => $p['category'] ?? null,
            'warranty'     => $p['warranty'] ?? '',
            'multiplicity' => $a['multiplicity'] ?? ($p['multiplicity'] ?? 1),
            'hasImage'     => !empty($p['has_image']),
            'price'        => retail_price($a['price'] ?? null, $p['rrp'] ?? null),
            'stock'        => self::stockLevel($a['qty'] ?? null),
            'deliveryDays' => $a['delivery_days'] ?? 0,
        ];
    }

    // id категории → все id её потомков (включая саму). Товары лежат в листовых
    // категориях, поэтому фильтр по «ветке» должен раскрываться до листьев.
    private static function categoryDescendants(int $id): array
    {
        $children = [];
        $walk = function ($nodes) use (&$walk, &$children) {
            foreach ($nodes as $n) {
                $kids = $n['childrens'] ?? [];
                $children[(int) $n['id']] = array_map(fn($k) => (int) $k['id'], $kids);
                if ($kids) {
                    $walk($kids);
                }
            }
        };
        $walk(self::categories());

        $out = [];
        $stack = [$id];
        while ($stack) {
            $c = array_pop($stack);
            if (isset($out[$c])) {
                continue;
            }
            $out[$c] = true;
            foreach ($children[$c] ?? [] as $k) {
                $stack[] = $k;
            }
        }
        return $out; // ключи-множество: [id => true, ...]
    }

    /**
     * Товары в наличии: по категории (с вложенными) и/или поиску по названию.
     * Возвращает ['total' => сколько всего подходит, 'items' => срез limit/offset].
     */
    public static function listProducts(?int $category = null, ?string $q = null, int $limit = 300, int $offset = 0): array
    {
        $products = self::productIndex();
        $active   = self::activeIndex();

        $allowed = $category ? self::categoryDescendants($category) : null;
        $needle  = ($q !== null && trim($q) !== '') ? trim($q) : '';
        // Регистронезависимый поиск, устойчивый к отсутствию расширения mbstring.
        $hasMb = function_exists('mb_stripos');

        $matched = [];
        foreach ($active as $sku => $a) {
            if (!isset($products[$sku])) {
                continue;
            }
            $p = $products[$sku];
            if ($allowed !== null && !isset($allowed[(int) ($p['category'] ?? 0)])) {
                continue;
            }
            if ($needle !== '') {
                $name = (string) ($p['name'] ?? '');
                $found = $hasMb ? (mb_stripos($name, $needle) !== false) : (stripos($name, $needle) !== false);
                if (!$found) {
                    continue;
                }
            }
            $matched[] = [$p, $a];
        }

        $total = count($matched);
        $slice = array_slice($matched, max(0, $offset), max(1, $limit));
        $items = array_map(fn($pa) => self::toDisplay($pa[0], $pa[1]), $slice);
        return ['total' => $total, 'items' => $items];
    }

    // Карточка товара с картинками (если есть).
    public static function getProduct(int $sku): ?array
    {
        $products = self::productIndex();
        $active   = self::activeIndex();
        if (!isset($products[$sku], $active[$sku])) {
            return null;
        }
        $card = self::toDisplay($products[$sku], $active[$sku]);
        $card['images'] = [];

        if (!empty($products[$sku]['has_image']) && !CONFIG['useMock']) {
            try {
                $imgs = Cache::getOrLoad("img_$sku", CONFIG['catalogTtl'], function () use ($sku) {
                    return self::client()->getImages([$sku]);
                });
                $imgs = array_filter($imgs, fn($i) => empty($i['deleted']));
                usort($imgs, fn($x, $y) => ($y['priority'] ?? 0) <=> ($x['priority'] ?? 0));
                $card['images'] = array_map(fn($i) => self::client()->imageUrl($i['url'], 'medium'), $imgs);
                $card['images'] = array_values($card['images']);
            } catch (Throwable $e) {
                $card['images'] = [];
            }
        }
        return $card;
    }
}
