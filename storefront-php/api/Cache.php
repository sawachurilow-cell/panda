<?php
// Файловый TTL-кэш с межпроцессной блокировкой (flock). PHP-FPM — много процессов,
// поэтому single-flight делаем через файловый лок: при холодном кэше сборку запускает
// один процесс, остальные ждут и читают готовое. Тяжёлую сборку прогревает CRON
// (эндпоинт ?r=refresh), пользовательские запросы читают тёплый кэш.
if (!defined('STOREFRONT')) { http_response_code(403); exit; }

final class Cache
{
    private static function path(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $key);
        return CONFIG['cacheDir'] . '/' . $safe . '.json';
    }

    // Значение, если оно свежее (моложе $ttl). Иначе null.
    public static function fresh(string $key, int $ttl)
    {
        $f = self::path($key);
        if (is_file($f) && (time() - filemtime($f)) < $ttl) {
            $raw = file_get_contents($f);
            $val = json_decode($raw, true);
            if ($val !== null || trim($raw) === 'null') {
                return $val;
            }
        }
        return null;
    }

    // Значение любого возраста (для отдачи «устаревшего, но готового»).
    public static function get(string $key)
    {
        $f = self::path($key);
        return is_file($f) ? json_decode(file_get_contents($f), true) : null;
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

    public static function invalidate(string $key): void
    {
        @unlink(self::path($key));
    }

    /**
     * Свежее значение из кэша либо сборка через $build под межпроцессной блокировкой.
     * Пока один процесс строит, остальные ждут и получают готовый результат —
     * не запуская параллельных тяжёлых сборок.
     */
    public static function getOrLoad(string $key, int $ttl, callable $build)
    {
        $v = self::fresh($key, $ttl);
        if ($v !== null) {
            return $v;
        }
        $dir = CONFIG['cacheDir'];
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $lock = @fopen(self::path($key) . '.lock', 'c');
        if ($lock && flock($lock, LOCK_EX)) {
            // Пока ждали лок, кэш мог собрать другой процесс.
            $v = self::fresh($key, $ttl);
            if ($v === null) {
                $v = $build();
                self::store($key, $v);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            return $v;
        }
        if ($lock) {
            fclose($lock);
        }
        // Лок не получили — отдаём что есть, иначе строим напрямую.
        $stale = self::get($key);
        return $stale !== null ? $stale : $build();
    }
}
