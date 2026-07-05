// Сборка витринного каталога: справочник товаров (статика) + наличие/цены
// (get_active_products) + изображения, с розничной наценкой. Всё через TTL-кэш,
// чтобы не превышать лимиты B2B API. В демо-режиме источник — mockData.
import { config } from './config.js';
import { cache } from './cache.js';
import { b2b } from './b2bClient.js';
import { retailPrice } from './pricing.js';
import { mockCategoryTree, mockProducts, mockActive } from './mockData.js';

// Дерево категорий (кэш на сутки — файл обновляется раз в ночь).
export function getCategories() {
  return cache.getOrLoad('categories', config.cache.catalogMs, async () =>
    config.useMock ? mockCategoryTree : b2b.getCategoryTree(),
  );
}

// Справочник товаров sku → карточка (кэш на сутки).
function getProductIndex() {
  return cache.getOrLoad('products', config.cache.catalogMs, async () => {
    const list = config.useMock ? mockProducts : await b2b.getProducts();
    const bySku = new Map();
    for (const p of list) bySku.set(p.sku, p);
    return bySku;
  });
}

// Наличие и цены sku → {price, qty, ...} (кэш ~10 мин — лимит 10/час).
function getActiveIndex() {
  return cache.getOrLoad('active', config.cache.pricesMs, async () => {
    const list = config.useMock ? mockActive : await b2b.getActiveProducts();
    const bySku = new Map();
    for (const a of list) bySku.set(a.sku, a);
    return bySku;
  });
}

// Символьный остаток B2B (*/**/***) → удобный для UI уровень.
function stockLevel(qty) {
  if (qty === '***') return 'high';
  if (qty === '**') return 'mid';
  if (qty === '*') return 'low';
  return 'unknown';
}

// Собрать витринную карточку. Показываем ТОЛЬКО товары в наличии (есть в active).
function toDisplay(product, active) {
  return {
    sku: product.sku,
    name: product.name,
    part: product.part,
    vendor: product.vendor,
    category: product.category,
    warranty: product.warranty,
    multiplicity: active.multiplicity || product.multiplicity || 1,
    hasImage: !!product.has_image,
    price: retailPrice(active.price, product.rrp),
    stock: stockLevel(active.qty),
    deliveryDays: active.delivery_days || 0,
  };
}

/** Список товаров витрины, опционально по категории. */
export async function listProducts({ category } = {}) {
  const [products, active] = await Promise.all([getProductIndex(), getActiveIndex()]);
  const items = [];
  for (const [sku, a] of active) {
    const p = products.get(sku);
    if (!p) continue; // нет в справочнике
    if (category && Number(p.category) !== Number(category)) continue;
    items.push(toDisplay(p, a));
  }
  return items;
}

/** Одна карточка товара с картинками (если есть). */
export async function getProduct(sku) {
  const skuN = Number(sku);
  const [products, active] = await Promise.all([getProductIndex(), getActiveIndex()]);
  const p = products.get(skuN);
  const a = active.get(skuN);
  if (!p || !a) return null;

  const card = toDisplay(p, a);
  card.images = [];
  if (p.has_image && !config.useMock) {
    try {
      const imgs = await cache.getOrLoad(`img:${skuN}`, config.cache.catalogMs, () =>
        b2b.getImages([skuN]),
      );
      card.images = imgs
        .filter((i) => !i.deleted)
        .sort((x, y) => (y.priority || 0) - (x.priority || 0))
        .map((i) => b2b.imageUrl(i.url, 'medium'));
    } catch {
      card.images = [];
    }
  }
  return card;
}
