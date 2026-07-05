// Опт → розница. B2B API отдаёт дилерские цены (price) и РРЦ (rrp);
// в клиентской витрине показываем розничную цену, а не оптовую.
//
// TODO: заменить плейсхолдер на реальное бизнес-правило (наценка по категориям,
// округление до .90, приоритет РРЦ и т.п.). Сейчас — единый процент из конфига.
import { config } from './config.js';

/**
 * @param {number|null} wholesale оптовая цена из get_active_products.price
 * @param {number|null} rrp рекомендованная розничная цена из products.rrp
 * @returns {number|null} розничная цена для показа покупателю
 */
export function retailPrice(wholesale, rrp) {
  const base = Number(wholesale) || Number(rrp) || 0;
  if (!base) return null;
  const withMarkup = base * (1 + config.retailMarkupPercent / 100);
  return Math.round(withMarkup * 100) / 100;
}
