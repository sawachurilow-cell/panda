// Оформление заказа из корзины киоска. Корзина приходит как [{sku, qty}].
// В B2B: create заказа → client_update позиций. Подписку на отгрузку (confirmed:true)
// по умолчанию НЕ делаем — заказ уходит менеджеру на обработку (оптовые цены/резерв),
// и после confirmed его уже нельзя редактировать. См. README (TODO по идентификации).
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

ordersRouter.post('/', async (req, res, next) => {
  try {
    const items = normalizeCart(req.body && req.body.cart);
    const comment = String((req.body && req.body.comment) || 'Заказ из киоска');
    if (!items.length) return res.status(400).json({ error: 'empty_cart' });

    // Демо-режим: без реальной учётки не создаём заказ в B2B, отдаём заглушку.
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
