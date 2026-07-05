<?php
// Опт → розница. B2B отдаёт дилерские цены; в витрине показываем розницу.
// Правило — плоский процент из .env (RETAIL_MARKUP_PERCENT). Усложнить можно здесь.
if (!defined('STOREFRONT')) { http_response_code(403); exit; }

function retail_price($wholesale, $rrp): ?float
{
    $base = (float) $wholesale;
    if ($base <= 0) {
        $base = (float) $rrp;
    }
    if ($base <= 0) {
        return null;
    }
    return round($base * (1 + CONFIG['markup'] / 100), 2);
}
