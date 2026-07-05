// Конфигурация из переменных окружения. Значения по умолчанию подобраны так,
// чтобы витрина запускалась в демо-режиме без реальной учётки B2B.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

// Мини-загрузчик .env без зависимостей: не перетирает уже заданные переменные.
function loadDotEnv() {
  const here = dirname(fileURLToPath(import.meta.url));
  try {
    const raw = readFileSync(join(here, '..', '.env'), 'utf8');
    for (const line of raw.split('\n')) {
      const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
      if (!m) continue;
      const [, key, val] = m;
      if (process.env[key] === undefined) {
        process.env[key] = val.replace(/^["']|["']$/g, '');
      }
    }
  } catch {
    // .env отсутствует — работаем на значениях по умолчанию / демо-режиме.
  }
}
loadDotEnv();

const login = process.env.B2B_LOGIN || '';
const password = process.env.B2B_PASSWORD || '';

export const config = {
  port: Number(process.env.PORT) || 3000,
  // В проде за Nginx задавайте HOST=127.0.0.1, чтобы порт не торчал наружу.
  host: process.env.HOST || '0.0.0.0',

  b2b: {
    domain: process.env.B2B_DOMAIN || 'b2b.i-t-p.pro',
    login,
    password,
    priceListId: process.env.B2B_PRICE_LIST_ID || '',
    logisticCenter: Number(process.env.B2B_LOGISTIC_CENTER) || 1,
  },

  // Нет учётки → демо-режим на mockData: фронтенд рендерится, лимиты не расходуются.
  useMock: !(login && password),

  retailMarkupPercent: Number(process.env.RETAIL_MARKUP_PERCENT) || 0,

  cache: {
    catalogMs: Number(process.env.CACHE_CATALOG_MS) || 24 * 60 * 60 * 1000,
    pricesMs: Number(process.env.CACHE_PRICES_MS) || 10 * 60 * 1000,
  },
};

// Базовый URL и путь к статике каталога (файлы могут иметь суффикс прайс-листа).
export const b2bBaseUrl = `https://${config.b2b.domain}`;
export const catalogFile = (name) =>
  config.b2b.priceListId
    ? `/download/catalog/json/${name}_${config.b2b.priceListId}.json`
    : `/download/catalog/json/${name}.json`;
