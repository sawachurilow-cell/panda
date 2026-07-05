// Оформление заказа из корзины киоска. Корзина: [{sku, qty}], клиент:
// {name, phone, address, comment}. В B2B: create заказа → client_update позиций.
//
// Важно: B2B-метод create принимает только partner_comment и logistic_center —
// отдельных полей под ФИО/телефон/адрес клиента в API НЕТ. Поэтому данные клиента
// упаковываются в структурированный partner_comment (см. buildComment).
// Подписку на отгрузку (confirmed:true) НЕ делаем — заказ уходит менеджеру.
import { Router } from 'express';
import { config } from '../config.js';
import { b2b } from '../b2bClient.js';

export const ordersRouter = Router();

function normalizeCart(cart) {
  if (!Array.isArray(cart)) return [];
  return cart
    .map((i) => ({ sku: Number(i.sku), qty: Math.max(1, Number(i.qty) || 1) }))
    .filter((i) => i.sku > 0);
}

function normalizeCustomer(c = {}) {
  return {
    name: String(c.name || '').trim(),
    phone: String(c.phone || '').trim(),
    address: String(c.address || '').trim(),
    comment: String(c.comment || '').trim(),
  };
}

// Структурированный комментарий заказа с данными клиента для менеджера.
function buildComment(cust) {
  const lines = [
    `Клиент: ${cust.name}`,
    `Телефон: ${cust.phone}`,
    `Адрес разгрузки: ${cust.address}`,
  ];
  if (cust.comment) lines.push(`Комментарий: ${cust.comment}`);
  lines.push('— Заказ из киоска');
  return lines.join('\n');
}

ordersRouter.post('/', async (req, res, next) => {
  try {
    const items = normalizeCart(req.body && req.body.cart);
    const cust = normalizeCustomer(req.body && req.body.customer);

    if (!items.length) return res.status(400).json({ error: 'empty_cart' });
    // ФИО, телефон и адрес разгрузки обязательны.
    const missing = ['name', 'phone', 'address'].filter((f) => !cust[f]);
    if (missing.length) return res.status(400).json({ error: 'missing_fields', fields: missing });

    const comment = buildComment(cust);

    // Демо-режим: заказ в B2B не создаём, отдаём заглушку.
    if (config.useMock) {
      return res.json({
        ok: true,
        demo: true,
        order: { id: Math.floor(Date.now() / 1000), status: 1, items: items.length },
      });
    }

    const order = await b2b.createOrder({ comment });
    const update = items.map((i) => ({
      sku: i.sku,
      qty: i.qty,
      wish_price: 0,
      wish_price_comment: '',
    }));
    const lines = await b2b.updateOrderItems(order.id, { update, destroy: [] });

    res.json({ ok: true, order: { id: order.id, status: order.status, items: lines.length } });
  } catch (e) {
    next(e);
  }
});
