// Публичный REST каталога для фронтенда киоска. Отдаёт уже собранные, безопасные
// данные (без оптовых цен и секретов) — фронтенд знает только эти эндпоинты.
import { Router } from 'express';
import { getCategories, listProducts, getProduct } from '../catalogService.js';

export const catalogRouter = Router();

// Дерево категорий.
catalogRouter.get('/categories', async (_req, res, next) => {
  try {
    res.json({ categories: await getCategories() });
  } catch (e) {
    next(e);
  }
});

// Список товаров, опционально ?category=<id>.
catalogRouter.get('/products', async (req, res, next) => {
  try {
    const items = await listProducts({ category: req.query.category });
    res.json({ products: items, total: items.length });
  } catch (e) {
    next(e);
  }
});

// Карточка одного товара по артикулу.
catalogRouter.get('/product/:sku', async (req, res, next) => {
  try {
    const card = await getProduct(req.params.sku);
    if (!card) return res.status(404).json({ error: 'not_found' });
    res.json({ product: card });
  } catch (e) {
    next(e);
  }
});
