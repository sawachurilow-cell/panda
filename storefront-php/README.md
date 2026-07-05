# Витрина-киоск — PHP-версия (для ISPmanager / обычного хостинга)

То же, что `../storefront` (Node), но бэкенд-прокси переписан на **PHP** — чтобы
разворачиваться на PHP-хостинге под панелью ISPmanager без демонов, pm2 и
reverse-proxy. Фронтенд (`index.html`, `styles.css`, `app.js`) — идентичен Node-версии.

**Требования:** PHP **8.0+** с расширениями `curl` и `json` (стандартно), Apache с
`.htaccess`/`mod_rewrite` (или Nginx — см. ниже).

## Структура (заливается в корень сайта)

```
<корень сайта>/
├── index.html · styles.css · app.js   ← фронтенд киоска
├── .htaccess                          ← защита .env
├── .env                               ← создать из .env.example (НЕ в гите)
└── api/
    ├── .htaccess                      ← /api/* → index.php (rewrite)
    ├── index.php                      ← роутер REST + оформление заказа
    ├── config.php · Cache.php · pricing.php · mock.php
    ├── B2BClient.php · CatalogService.php
    └── cache/                         ← файловый кэш + сессия (доступ закрыт, права на запись)
```

REST тот же: `GET /api/catalog/categories`, `GET /api/catalog/products[?category=]`,
`GET /api/catalog/product/:sku`, `POST /api/orders`, `GET /api/health`.
Данные клиента (ФИО, телефон, адрес разгрузки, комментарий) уходят в `partner_comment`
заказа — отдельных полей под клиента в B2B API нет.

## Деплой в ISPmanager

1. **Сайт.** «Сайты» → создать сайт с вашим доменом. Запомните корень сайта
   (напр. `/var/www/www-root/data/www/ВАШ_ДОМЕН`). Версия PHP — **8.0+**.
2. **Файлы.** Залейте содержимое `storefront-php/` в корень сайта:
   - через «Менеджер файлов» (загрузить/распаковать), **или**
   - через «Shell-клиент»:
     ```
     cd ~/data/www/ВАШ_ДОМЕН
     git clone https://GITHUB_TOKEN@github.com/sawachurilow-cell/panda.git tmp
     cp -r tmp/storefront-php/. .  &&  rm -rf tmp
     ```
3. **Настройки.** Создайте `.env` в корне сайта из `.env.example` и впишите учётку:
   ```
   B2B_DOMAIN=b2b.i-t-p.pro
   B2B_LOGIN=blinov
   B2B_PASSWORD=12131312
   B2B_PRICE_LIST_ID=9
   B2B_LOGISTIC_CENTER=1
   RETAIL_MARKUP_PERCENT=0
   ```
   Права на `.env` — 600. Наценка — в `RETAIL_MARKUP_PERCENT`.
4. **Права на кэш.** Папка `api/cache/` должна быть доступна на запись веб-пользователю
   (обычно 0775 и владелец — пользователь сайта). В «Менеджере файлов» → права.
5. **DNS.** «Управление DNS» → A-запись домена на IP сервера (если DNS ведёте здесь).
6. **SSL.** «SSL-сертификаты» → выпустить Let's Encrypt для домена (кнопкой).
7. **Проверка.** Откройте `https://ВАШ_ДОМЕН/api/health` — должно быть
   `{"ok":true,"mode":"live"}`. Затем `.../api/catalog/products` — должны прийти товары.
   `mode:"demo"` означает, что `.env` не прочитан или пуста учётка.
8. **Киоск.** Откройте `https://ВАШ_ДОМЕН` в браузере киоска в полноэкранном режиме.

## Если сайт на Nginx + PHP-FPM (без Apache/.htaccess)

`.htaccess` не сработает. Добавьте в настройках веб-сервера сайта локейшн:

```
location /api/ {
    try_files $uri /api/index.php$is_args$args;
}
```

И закройте служебное снаружи (если не закрыто панелью):

```
location ~ /\.env { deny all; }
location ^~ /api/cache/ { deny all; }
```

## Прогрев кэша (необязательно)

Кэш ленивый: первый запрос после истечения TTL синхронно тянет данные из B2B.
Чтобы покупатель не ждал, повесьте в «Планировщик CRON» прогрев раз в ~10 мин:

```
*/10 * * * * curl -s https://ВАШ_ДОМЕН/api/catalog/products >/dev/null
```

## Демо-режим

Без `.env` (или с пустыми логином/паролем) витрина работает на демо-данных
(`api/mock.php`) — удобно проверить деплой, не трогая B2B.
