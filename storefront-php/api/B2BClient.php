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

    // --- Низкоуровневый POST JSON ---
    private function httpPost(string $url, string $json, array $headers = [])
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT        => 30,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            throw new RuntimeException("curl: $err");
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("B2B HTTP $code");
        }
        return json_decode($res, true);
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
        $json = $this->httpPost(CONFIG['baseUrl'] . '/api/2', json_encode($payload));

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
        $json = $this->httpPost(CONFIG['baseUrl'] . '/api/2', json_encode([
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
        $ch = curl_init(CONFIG['baseUrl'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Cookie: session=' . $this->session],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 404) {
            throw new RuntimeException("B2B static 404 (авторизация?) $path");
        }
        if ($res === false || $code < 200 || $code >= 300) {
            throw new RuntimeException("B2B static HTTP $code $path");
        }
        return json_decode($res, true);
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
