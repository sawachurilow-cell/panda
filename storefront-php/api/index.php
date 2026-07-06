<?php
// Фронт-контроллер REST-прокси. Все /api/* приходят сюда (см. api/.htaccess).
// Аналог server/index.js + routes/* из Node-версии.
define('STOREFRONT', true);

require __DIR__ . '/config.php';
require __DIR__ . '/Cache.php';
require __DIR__ . '/pricing.php';
require __DIR__ . '/mock.php';
require __DIR__ . '/B2BClient.php';
require __DIR__ . '/CatalogService.php';

header('Content-Type: application/json; charset=utf-8');

// Маршрут: из ?r= (работает без rewrite Nginx) либо из чистого пути после /api/.
$route = isset($_GET['r']) ? (string) $_GET['r'] : '';
if ($route === '') {
    $uri   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $route = preg_replace('#^.*/api/?#', '', $uri);
    $route = preg_replace('#^index\.php/?#', '', $route);
}
$path   = trim($route, '/');
$parts  = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function send($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Оформление заказа: данные клиента в partner_comment (полей под клиента в API нет) ---
function normalize_cart($cart): array
{
    if (!is_array($cart)) {
        return [];
    }
    $out = [];
    foreach ($cart as $i) {
        $sku = (int) ($i['sku'] ?? 0);
        $qty = max(1, (int) ($i['qty'] ?? 1));
        if ($sku > 0) {
            $out[] = ['sku' => $sku, 'qty' => $qty];
        }
    }
    return $out;
}

function normalize_customer($c): array
{
    $c = is_array($c) ? $c : [];
    return [
        'name'    => trim((string) ($c['name'] ?? '')),
        'phone'   => trim((string) ($c['phone'] ?? '')),
        'address' => trim((string) ($c['address'] ?? '')),
        'comment' => trim((string) ($c['comment'] ?? '')),
    ];
}

function build_comment(array $c): string
{
    $lines = [
        "Клиент: {$c['name']}",
        "Телефон: {$c['phone']}",
        "Адрес разгрузки: {$c['address']}",
    ];
    if ($c['comment'] !== '') {
        $lines[] = "Комментарий: {$c['comment']}";
    }
    $lines[] = '— Заказ из киоска';
    return implode("\n", $lines);
}

function order_create(): void
{
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $cart = normalize_cart($body['cart'] ?? []);
    $cust = normalize_customer($body['customer'] ?? []);

    if (!$cart) {
        send(['error' => 'empty_cart'], 400);
    }
    $missing = array_values(array_filter(['name', 'phone', 'address'], fn($f) => $cust[$f] === ''));
    if ($missing) {
        send(['error' => 'missing_fields', 'fields' => $missing], 400);
    }

    $comment = build_comment($cust);

    if (CONFIG['useMock']) {
        send(['ok' => true, 'demo' => true, 'order' => ['id' => time(), 'status' => 1, 'items' => count($cart)]]);
    }

    $client = new B2BClient();
    $order  = $client->createOrder($comment);
    $update = array_map(
        fn($i) => ['sku' => $i['sku'], 'qty' => $i['qty'], 'wish_price' => 0, 'wish_price_comment' => ''],
        $cart
    );
    $lines = $client->updateOrderItems((int) $order['id'], $update, []);
    send(['ok' => true, 'order' => ['id' => $order['id'], 'status' => $order['status'] ?? 1, 'items' => count($lines)]]);
}

// --- Маршрутизация ---
try {
    if ($parts === ['health']) {
        send(['ok' => true, 'mode' => CONFIG['useMock'] ? 'demo' : 'live']);
    }
    if ($method === 'GET' && $parts === ['catalog', 'categories']) {
        send(['categories' => CatalogService::categories()]);
    }
    if ($method === 'GET' && $parts === ['catalog', 'products']) {
        $cat = isset($_GET['category']) ? (int) $_GET['category'] : null;
        $items = CatalogService::listProducts($cat);
        send(['products' => $items, 'total' => count($items)]);
    }
    if ($method === 'GET' && count($parts) === 3 && $parts[0] === 'catalog' && $parts[1] === 'product') {
        $card = CatalogService::getProduct((int) $parts[2]);
        $card ? send(['product' => $card]) : send(['error' => 'not_found'], 404);
    }
    if ($method === 'POST' && $parts === ['orders']) {
        order_create();
    }
    send(['error' => 'not_found'], 404);
} catch (Throwable $e) {
    error_log('[storefront] ' . $e->getMessage());
    send(['error' => 'upstream_error'], 502);
}
