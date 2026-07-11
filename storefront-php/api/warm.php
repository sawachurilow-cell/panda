<?php
// CLI-скрипт прогрева каталога (для CRON и ручного запуска).
// Запуск: php /путь/к/сайту/api/warm.php
// Память ограничена — сборка потоковая, в память весь каталог не грузится.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

define('STOREFRONT', 1);
@ini_set('memory_limit', '256M');
@set_time_limit(0);

foreach (['config', 'Cache', 'pricing', 'mock', 'B2BClient', 'Catalog', 'CatalogService'] as $m) {
    require __DIR__ . '/' . $m . '.php';
}

$t = microtime(true);
try {
    $res = Catalog::build();
    $res['seconds'] = round(microtime(true) - $t, 1);
    $res['peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
    echo json_encode($res, JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'BUILD ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
