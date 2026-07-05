<?php
// Файловый TTL-кэш. PHP выполняется по-запросно, поэтому кэш — на диске (api/cache),
// а не в памяти. Роль та же: не превышать лимиты B2B API. Запись атомарна (tmp+rename).
if (!defined('STOREFRONT')) { http_response_code(403); exit; }

final class Cache
{
    private static function path(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $key);
        return CONFIG['cacheDir'] . '/' . $safe . '.json';
    }

    /** Вернуть свежее значение из кэша либо загрузить через $loader и сохранить. */
    public static function getOrLoad(string $key, int $ttl, callable $loader)
    {
        $f = self::path($key);
        if (is_file($f) && (time() - filemtime($f)) < $ttl) {
            $raw = file_get_contents($f);
            $val = json_decode($raw, true);
            if ($val !== null || trim($raw) === 'null') {
                return $val;
            }
        }
        $value = $loader();
        self::store($key, $value);
        return $value;
    }

    public static function store(string $key, $value): void
    {
        $dir = CONFIG['cacheDir'];
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $f = self::path($key);
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($value)) !== false) {
            @rename($tmp, $f);
        }
    }
}
