<?php
// Тонкий фасад над Catalog (файловое хранилище под малую память).
// Веб только читает; тяжёлую сборку (build) выполняем строго из CLI/CRON.
if (!defined('STOREFRONT')) { http_response_code(403); exit; }

final class CatalogService
{
    public static function categories(): array
    {
        return Catalog::categories();
    }

    public static function listProducts(?int $category = null, ?string $q = null, int $limit = 300, int $offset = 0): array
    {
        return Catalog::listProducts($category, $q, $limit, $offset);
    }

    public static function getProduct(int $sku): ?array
    {
        return Catalog::getProduct($sku);
    }

    // Прогрев/сборка. Только CLI: на вебе тяжёлая сборка недопустима (память/таймаут).
    public static function warm(): array
    {
        if (PHP_SAPI !== 'cli') {
            return ['error' => 'cli_only', 'message' => 'Сборку каталога запускайте из CLI/CRON (api/warm.php)'];
        }
        return Catalog::build();
    }
}
