<?php
// Конфигурация из .env (лежит на уровень выше, вне api/). Без учётки → демо-режим.
if (!defined('STOREFRONT')) { http_response_code(403); exit; }

$env = [];
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $m)) {
            $env[$m[1]] = trim($m[2], "\"'");
        }
    }
}
$val = function (string $k, string $d = '') use ($env): string {
    return isset($env[$k]) && $env[$k] !== '' ? $env[$k] : $d;
};

$login = $val('B2B_LOGIN');
$password = $val('B2B_PASSWORD');
$domain = $val('B2B_DOMAIN', 'b2b.i-t-p.pro');

define('CONFIG', [
    'domain'         => $domain,
    'baseUrl'        => 'https://' . $domain,
    'login'          => $login,
    'password'       => $password,
    'priceListId'    => $val('B2B_PRICE_LIST_ID'),
    'logisticCenter' => (int) $val('B2B_LOGISTIC_CENTER', '1'),
    // Нет учётки → демо-режим на mock.php (без обращений к B2B).
    'useMock'        => !($login && $password),
    'markup'         => (float) $val('RETAIL_MARKUP_PERCENT', '0'),
    // TTL кэша в секундах (в .env заданы в мс, как у Node-версии).
    'catalogTtl'     => (int) ((int) $val('CACHE_CATALOG_MS', '86400000') / 1000),
    'pricesTtl'      => (int) ((int) $val('CACHE_PRICES_MS', '600000') / 1000),
    'cacheDir'       => __DIR__ . '/cache',
]);
