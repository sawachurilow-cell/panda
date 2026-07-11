<?php
// Хранилище каталога под МАЛУЮ память (сервер ~768 МБ, без SQLite).
// Идея: тяжёлый каталог (120k) НЕ держим в памяти ни при сборке, ни при выдаче.
//  - Сборка (CLI/CRON): скачиваем во временные файлы и разбираем ПОТОКОВО (по
//    одному объекту), раскладывая товары по шардам на категорию (cache/shards/<cat>.jsonl).
//  - Выдача (веб): читаем только нужные шарды построчно — память почти не растёт.
if (!defined('STOREFRONT')) { http_response_code(403); exit; }

final class Catalog
{
    private static function shardDir(): string { return CONFIG['cacheDir'] . '/shards'; }
    private static function shardPath(int $cat): string { return self::shardDir() . '/' . $cat . '.jsonl'; }
    private static function manifestPath(): string { return CONFIG['cacheDir'] . '/manifest.json'; }
    private static function categoriesPath(): string { return CONFIG['cacheDir'] . '/categories.json'; }

    // ---------- Чтение (веб) ----------

    public static function categories(): array
    {
        $f = self::categoriesPath();
        return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    }

    private static function manifest(): array
    {
        $f = self::manifestPath();
        return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    }

    public static function isReady(): bool
    {
        return is_file(self::manifestPath());
    }

    // id категории → множество id потомков (включая саму) по дереву категорий.
    private static function descendants(int $id): array
    {
        $children = [];
        $walk = function ($nodes) use (&$walk, &$children) {
            foreach ($nodes as $n) {
                $kids = $n['childrens'] ?? [];
                $children[(int) $n['id']] = array_map(fn($k) => (int) $k['id'], $kids);
                if ($kids) { $walk($kids); }
            }
        };
        $walk(self::categories());

        $out = [];
        $stack = [$id];
        while ($stack) {
            $c = array_pop($stack);
            if (isset($out[$c])) { continue; }
            $out[$c] = true;
            foreach ($children[$c] ?? [] as $k) { $stack[] = $k; }
        }
        return $out;
    }

    // Список товаров по категории (с вложенными) или поиску. Малая память: читаем шарды.
    public static function listProducts(?int $category, ?string $q, int $limit, int $offset): array
    {
        $needle = ($q !== null && trim($q) !== '') ? trim($q) : '';
        if ($needle !== '') {
            return self::search($needle, $limit, $offset);
        }
        if (!$category) {
            return ['total' => 0, 'items' => []]; // нужна категория или поиск
        }

        $manifest = self::manifest();
        $counts = $manifest['counts'] ?? [];
        $leaves = array_keys(self::descendants($category));

        $total = 0;
        foreach ($leaves as $l) { $total += (int) ($counts[(string) $l] ?? $counts[$l] ?? 0); }

        $items = [];
        $skip = max(0, $offset);
        $need = max(1, $limit);
        foreach ($leaves as $l) {
            if ($need <= 0) { break; }
            $p = self::shardPath((int) $l);
            if (!is_file($p)) { continue; }
            $fh = fopen($p, 'rb');
            while ($need > 0 && ($line = fgets($fh)) !== false) {
                if ($skip > 0) { $skip--; continue; }
                $rec = json_decode($line, true);
                if ($rec) { $items[] = $rec; $need--; }
            }
            fclose($fh);
        }
        return ['total' => $total, 'items' => $items];
    }

    // Поиск по названию: потоково по всем шардам (низкая память).
    private static function search(string $needle, int $limit, int $offset): array
    {
        $hasMb = function_exists('mb_stripos');
        $total = 0;
        $items = [];
        $skip = max(0, $offset);
        foreach (glob(self::shardDir() . '/*.jsonl') ?: [] as $p) {
            $fh = fopen($p, 'rb');
            while (($line = fgets($fh)) !== false) {
                // Грубый отсев по строке до декодирования.
                $hit = $hasMb ? (mb_stripos($line, $needle) !== false) : (stripos($line, $needle) !== false);
                if (!$hit) { continue; }
                $rec = json_decode($line, true);
                if (!$rec) { continue; }
                $name = (string) ($rec['name'] ?? '');
                $ok = $hasMb ? (mb_stripos($name, $needle) !== false) : (stripos($name, $needle) !== false);
                if (!$ok) { continue; }
                $total++;
                if ($skip > 0) { $skip--; continue; }
                if (count($items) < $limit) { $items[] = $rec; }
            }
            fclose($fh);
        }
        return ['total' => $total, 'items' => $items];
    }

    // Карточка по sku (используется редко) — ищем в шардах построчно.
    public static function getProduct(int $sku): ?array
    {
        $needle = '"sku":' . $sku;
        foreach (glob(self::shardDir() . '/*.jsonl') ?: [] as $p) {
            $fh = fopen($p, 'rb');
            while (($line = fgets($fh)) !== false) {
                if (strpos($line, $needle) === false) { continue; }
                $rec = json_decode($line, true);
                if ($rec && (int) ($rec['sku'] ?? 0) === $sku) {
                    fclose($fh);
                    $rec['images'] = [];
                    return $rec;
                }
            }
            fclose($fh);
        }
        return null;
    }

    // ---------- Сборка (CLI/CRON) ----------

    public static function build(): array
    {
        $client = new B2BClient();
        @mkdir(self::shardDir(), 0770, true);
        $tmpDir = CONFIG['cacheDir'];

        $tree = CONFIG['useMock'] ? mock_category_tree() : $client->getCategoryTree();

        // Справочник товаров (имена) — тяжёлый и меняется редко, лимит 2/час.
        // Кэшируем на диске и обновляем по суточному TTL. Цены (active) — каждый прогон.
        $prodRaw = $tmpDir . '/products.raw.json';
        $actTmp  = $tmpDir . '/active.tmp.json';

        if (CONFIG['useMock']) {
            file_put_contents($prodRaw, json_encode(mock_products()));
            file_put_contents($actTmp, json_encode(['data' => ['products' => mock_active()]]));
        } else {
            if (!is_file($prodRaw) || (time() - filemtime($prodRaw)) >= CONFIG['catalogTtl']) {
                file_put_contents($prodRaw, $client->getProductsRaw());
            }
            file_put_contents($actTmp, $client->getActiveRaw());
        }

        $res = self::assemble($prodRaw, $actTmp, $tree);

        @unlink($actTmp); // справочник (products.raw.json) НЕ удаляем — переиспользуем
        return $res;
    }

    // Сборка из уже скачанных файлов (тестируемое ядро, потоковое, малая память).
    public static function assemble(string $productsPath, string $activePath, array $tree): array
    {
        @mkdir(self::shardDir(), 0770, true);
        file_put_contents(self::categoriesPath(), json_encode($tree));

        // 1) Компактная карта наличия/цен: sku => "price|qty|dd|mult".
        $active = [];
        foreach (self::eachObject($activePath, 'products') as $a) {
            $sku = (int) ($a['sku'] ?? 0);
            if (!$sku) { continue; }
            $active[$sku] = ($a['price'] ?? '') . '|' . ($a['qty'] ?? '') . '|'
                . ($a['delivery_days'] ?? 0) . '|' . ($a['multiplicity'] ?? 1);
        }

        // 2) Чистим старые шарды.
        foreach (glob(self::shardDir() . '/*.jsonl') ?: [] as $old) { @unlink($old); }

        // 3) Стримим товары → раскладываем по шардам категорий.
        $counts = [];
        $handles = [];
        $open = function (int $cat) use (&$handles) {
            if (isset($handles[$cat])) { return $handles[$cat]; }
            if (count($handles) >= 400) { // ограничиваем число открытых файлов
                $k = array_key_first($handles);
                fclose($handles[$k]);
                unset($handles[$k]);
            }
            return $handles[$cat] = fopen(self::shardPath($cat), 'ab');
        };

        foreach (self::eachObject($productsPath, null) as $p) {
            $sku = (int) ($p['sku'] ?? 0);
            if (!$sku || !isset($active[$sku])) { continue; } // не в наличии — пропускаем
            [$price, $qty, $dd, $mult] = explode('|', $active[$sku]);
            $cat = (int) ($p['category'] ?? 0);
            $rec = [
                'sku'          => $sku,
                'name'         => $p['name'] ?? '',
                'part'         => $p['part'] ?? '',
                'vendor'       => $p['vendor'] ?? '',
                'category'     => $cat,
                'warranty'     => $p['warranty'] ?? '',
                'multiplicity' => (int) $mult ?: (int) ($p['multiplicity'] ?? 1),
                'hasImage'     => !empty($p['has_image']),
                'price'        => retail_price($price === '' ? null : (float) $price, $p['rrp'] ?? null),
                'stock'        => self::stock($qty),
                'deliveryDays' => (int) $dd,
            ];
            fwrite($open($cat), json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n");
            $counts[$cat] = ($counts[$cat] ?? 0) + 1;
        }
        foreach ($handles as $h) { fclose($h); }

        file_put_contents(self::manifestPath(), json_encode(['counts' => $counts, 'total' => array_sum($counts)]));

        return ['ok' => true, 'categories' => self::countTree($tree), 'products' => array_sum($counts)];
    }

    private static function stock($qty): string
    {
        return $qty === '***' ? 'high' : ($qty === '**' ? 'mid' : ($qty === '*' ? 'low' : 'unknown'));
    }

    private static function countTree(array $tree): int
    {
        $n = 0;
        foreach ($tree as $node) {
            $n++;
            if (!empty($node['childrens'])) { $n += self::countTree($node['childrens']); }
        }
        return $n;
    }

    /**
     * Потоковый разбор JSON-массива объектов из файла. Возвращает по одному объекту,
     * не держа весь файл в памяти. $afterKey — если задан (напр. "products"), сначала
     * ищем этот ключ, затем '['; иначе берём первый '[' (голый массив).
     * Годится для плоских объектов (наш каталог), учитывает строки и экранирование.
     */
    public static function eachObject(string $path, ?string $afterKey)
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) { return; }

        $started = false;                         // вошли в целевой массив
        $keyFound = ($afterKey === null);         // ключ найден (или не нужен)
        $keyPat = $afterKey !== null ? '"' . $afterKey . '"' : '';
        $pre = '';                                // окно для поиска ключа
        $depth = 0; $buf = ''; $inStr = false; $esc = false;

        while (!feof($fh)) {
            $chunk = fread($fh, 65536);
            if ($chunk === '' || $chunk === false) { break; }
            $len = strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $c = $chunk[$i];

                if (!$started) {
                    if (!$keyFound) {
                        $pre .= $c;
                        $max = strlen($keyPat) + 4;
                        if (strlen($pre) > $max) { $pre = substr($pre, -$max); }
                        if (strpos($pre, $keyPat) !== false) { $keyFound = true; }
                        continue;
                    }
                    if ($c === '[') { $started = true; $depth = 0; $buf = ''; $inStr = false; $esc = false; }
                    continue;
                }

                if ($depth === 0) {
                    if ($c === '{') { $depth = 1; $buf = '{'; $inStr = false; $esc = false; }
                    elseif ($c === ']') { fclose($fh); return; } // конец массива
                    continue;
                }

                $buf .= $c;
                if ($inStr) {
                    if ($esc) { $esc = false; }
                    elseif ($c === '\\') { $esc = true; }
                    elseif ($c === '"') { $inStr = false; }
                } else {
                    if ($c === '"') { $inStr = true; }
                    elseif ($c === '{') { $depth++; }
                    elseif ($c === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $obj = json_decode($buf, true);
                            if ($obj !== null) { yield $obj; }
                            $buf = '';
                        }
                    }
                }
            }
        }
        fclose($fh);
    }
}
