// Express-сервер витрины: REST-прокси к B2B API + раздача фронтенда киоска.
import express from 'express';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { config } from './config.js';
import { catalogRouter } from './routes/catalog.js';
import { ordersRouter } from './routes/orders.js';

const here = dirname(fileURLToPath(import.meta.url));
const app = express();

app.use(express.json());

app.get('/api/health', (_req, res) =>
  res.json({ ok: true, mode: config.useMock ? 'demo' : 'live' }),
);

app.use('/api/catalog', catalogRouter);
app.use('/api/orders', ordersRouter);

// Фронтенд киоска (статика).
app.use(express.static(join(here, '..', 'public')));

// Единый обработчик ошибок: наружу не отдаём детали B2B/секреты.
app.use((err, _req, res, _next) => {
  console.error('[storefront]', err.message);
  res.status(502).json({ error: 'upstream_error' });
});

app.listen(config.port, config.host, () => {
  const mode = config.useMock ? 'ДЕМО (mockData, без B2B)' : `LIVE → ${config.b2b.domain}`;
  console.log(`Витрина-киоск: http://${config.host}:${config.port}  | режим: ${mode}`);
});
