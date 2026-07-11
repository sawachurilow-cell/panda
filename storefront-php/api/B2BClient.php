<?php
// Клиент B2B API i-t-p.pro (JSON-RPC 2.17) на PHP. Аналог server/b2bClient.js.
// Сессия кэшируется в файле (api/cache/b2b_session) и переиспользуется между запросами.
if (!defined('STOREFRONT')) { http_response_code(403); exit; }

final class B2BClient
{
    private ?string $session = null;
    private string $sessionFile;
    private bool $retried = false;

    public function __construct()
    {
        $this->sessionFile = CONFIG['cacheDir'] . '/b2b_session';
        if (is_file($this->sessionFile)) {
            $this->session = trim(file_get_contents($this->sessionFile)) ?: null;
        }
    }

    private function saveSession(string $s): void
    {
        $this->session = $s;
        if (!is_dir(CONFIG['cacheDir'])) {
            @mkdir(CONFIG['cacheDir'], 0770, true);
        }
        file_put_contents($this->sessionFile, $s);
    }

    // --- Низкоуровневый HTTP: cURL, а при его отсутствии — потоки (file_get_contents).
    // Возвращает [int $code, string $body]. Бросает при транспортной ошибке. ---
    private function httpRaw(string $url, ?string $postJson, array $headers): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => $headers,
            ];
            if ($postJson !== null) {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = $postJson;
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException("curl: $err");
            }
            return [(int) $code, (string) $body];
        }

        // Фолбэк без ext-curl: HTTP через потоки (нужен allow_url_fopen=On).
        $http = [
            'method'        => $postJson !== null ? 'POST' : 'GET',
            'header'        => implode("\r\n", $headers),
            'timeout'       => 120,
            'ignore_errors' => true, // получить тело и при 4xx/5xx
        ];
        if ($postJson !== null) {
            $http['content'] = $postJson;
        }
        $body = @file_get_contents($url, false, stream_context_create(['http' => $http]));
        if ($body === false) {
            throw new RuntimeException('HTTP request failed (нет ни ext-curl, ни allow_url_fopen)');
        }
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        return [$code, (string) $body];
    }

    // POST/GET JSON с проверкой статуса. Content-Type добавляется для POST автоматически.
    private function httpJson(string $url, ?string $postJson, array $headers = [])
    {
        $headers = array_merge($postJson !== null ? ['Content-Type: application/json'] : [], $headers);
        [$code, $body] = $this->httpRaw($url, $postJson, $headers);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("B2B HTTP $code");
        }
        return json_decode($body, true);
    }

    // --- JSON-RPC на /api/2 с подстановкой сессии и повтором при протухании ---
    public function rpc(array $payload, bool $withSession = true)
    {
        if ($withSession) {
            if (!$this->session) {
                $this->login();
            }
            $payload['session'] = $this->session;
        }
        $json = $this->httpJson(CONFIG['baseUrl'] . '/api/2', json_encode($payload));

        if (is_array($json) && isset($json['success']) && $json['success'] === false
            && $withSession && !$this->retried) {
            $this->retried = true;
            $this->session = null;
            try {
                return $this->rpc($payload, $withSession);
            } finally {
                $this->retried = false;
            }
        }
        return $json;
    }

    // --- 2.1 Аутентификация ---
    public function login(): string
    {
        $json = $this->httpJson(CONFIG['baseUrl'] . '/api/2', json_encode([
            'data'    => ['login' => CONFIG['login'], 'password' => CONFIG['password']],
            'request' => ['method' => 'login', 'model' => 'auth', 'module' => 'quickfox'],
        ]));
        if (empty($json['success']) || empty($json['session'])) {
            throw new RuntimeException('B2B login failed: ' . ($json['message'] ?? ''));
        }
        $this->saveSession($json['session']);
        return $this->session;
    }

    private function catalogFile(string $name): string
    {
        $id = CONFIG['priceListId'];
        return $id !== ''
            ? "/download/catalog/json/{$name}_{$id}.json"
            : "/download/catalog/json/{$name}.json";
    }

    // --- Статика каталога (GET, сессия в Cookie) ---
    private function fetchStatic(string $path)
    {
        if (!$this->session) {
            $this->login();
        }
        [$code, $body] = $this->httpRaw(CONFIG['baseUrl'] . $path, null, ['Cookie: session=' . $this->session]);
        if ($code === 404) {
            throw new RuntimeException("B2B static 404 (авторизация?) $path");
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("B2B static HTTP $code $path");
        }
        return json_decode($body, true);
    }

    // --- 3.1 / 3.2 Каталог ---
    public function getCategoryTree()
    {
        return $this->fetchStatic($this->catalogFile('catalog_tree'));
    }

    public function getProducts()
    {
        return $this->fetchStatic($this->catalogFile('products'));
    }

    // Сырое тело статики товаров (для потоковой сборки, без декодирования в память).
    public function getProductsRaw(): string
    {
        if (!$this->session) { $this->login(); }
        [$code, $body] = $this->httpRaw(CONFIG['baseUrl'] . $this->catalogFile('products'), null, ['Cookie: session=' . $this->session]);
        if ($code === 404) { throw new RuntimeException('B2B static 404 (авторизация?) products'); }
        if ($code < 200 || $code >= 300) { throw new RuntimeException("B2B static HTTP $code products"); }
        return $body;
    }

    // Сырое тело ответа get_active_products (для потокового разбора).
    public function getActiveRaw(): string
    {
        if (!$this->session) { $this->login(); }
        $payload = [
            'request' => ['method' => 'get_active_products', 'model' => 'client_api', 'module' => 'platform'],
            'session' => $this->session,
        ];
        [$code, $body] = $this->httpRaw(CONFIG['baseUrl'] . '/api/2', json_encode($payload), ['Content-Type: application/json']);
        if ($code < 200 || $code >= 300) { throw new RuntimeException("B2B HTTP $code active"); }
        return $body;
    }

    // --- 3.3 Наличие и цены ---
    public function getActiveProducts(array $filter = []): array
    {
        $req = ['request' => ['method' => 'get_active_products', 'model' => 'client_api', 'module' => 'platform']];
        if ($filter) {
            $req['filter'] = $filter;
        }
        $json = $this->rpc($req);
        return $json['data']['products'] ?? [];
    }

    // --- 3.4 Изображения ---
    public function getImages(array $skus): array
    {
        $json = $this->rpc([
            'filter'  => [['operator' => 'IN', 'property' => 'sku', 'value' => array_slice($skus, 0, 100)]],
            'request' => ['method' => 'read_new', 'model' => 'products_clients_images', 'module' => 'platform'],
        ]);
        return $json['data']['product_images'] ?? [];
    }

    public function imageUrl(string $relative, string $size = 'medium'): string
    {
        return CONFIG['baseUrl'] . '/' . $relative . '?size=' . $size;
    }

    // --- 4.2 Создание заказа ---
    public function createOrder(string $comment = '', ?int $lc = null): array
    {
        $lc = $lc ?? CONFIG['logisticCenter'];
        $json = $this->rpc([
            'request' => ['method' => 'create', 'model' => 'orders', 'module' => 'platform'],
            'data'    => [['partner_comment' => $comment, 'logistic_center' => $lc]],
        ]);
        $order = $json['data']['orders'][0] ?? null;
        if (!$order) {
            throw new RuntimeException('B2B create order failed: ' . ($json['message'] ?? ''));
        }
        return $order;
    }

    // --- 4.3 Позиции заказа ---
    public function updateOrderItems(int $docId, array $update = [], array $destroy = []): array
    {
        $json = $this->rpc([
            'data'    => ['doc_id' => $docId, 'update' => $update, 'destroy' => $destroy],
            'request' => ['method' => 'client_update', 'model' => 'order_items', 'module' => 'platform'],
        ]);
        return $json['data']['order_items'] ?? [];
    }

    // --- 4.5 Подписка на отгрузку ---
    public function confirmOrder(int $id): bool
    {
        $json = $this->rpc([
            'data'    => [['confirmed' => true, 'id' => $id]],
            'request' => ['method' => 'update', 'model' => 'orders', 'module' => 'platform'],
        ]);
        return !empty($json['success']);
    }
}
