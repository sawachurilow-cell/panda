// Клиент B2B API i-t-p.pro (JSON-RPC 2.17). Инкапсулирует сессию и все методы,
// которые нужны витрине. Живёт ТОЛЬКО на сервере — секреты во фронтенд не попадают.
//
// Соответствие докам:
//   login                         → auth/quickfox (2.1)
//   catalog_tree_*.json (статика) → дерево категорий (3.1)
//   products_*.json (статика)     → список товаров (3.2)
//   get_active_products           → наличие и цены (3.3)
//   read_new/products_clients_images → изображения (3.4)
//   get_available_logistic_centers / get_addresses (3.6 / 3.7)
//   orders.create / order_items.client_update / orders.update confirmed (4.2–4.5)
import { config, b2bBaseUrl, catalogFile } from './config.js';

export class B2BClient {
  constructor() {
    this.session = null;
  }

  // --- JSON-RPC POST на /api/2 с автоматической подстановкой сессии ---
  async rpc(payload, { withSession = true } = {}) {
    const body = { ...payload };
    if (withSession) {
      if (!this.session) await this.login();
      body.session = this.session;
    }
    const res = await fetch(`${b2bBaseUrl}/api/2`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error(`B2B HTTP ${res.status}`);
    const json = await res.json();

    // Сессия могла протухнуть — один раз перелогиниваемся и повторяем.
    if (json && json.success === false && withSession && !this._retried) {
      this._retried = true;
      this.session = null;
      try {
        return await this.rpc(payload, { withSession });
      } finally {
        this._retried = false;
      }
    }
    return json;
  }

  // --- 2.1 Аутентификация: логин/пароль → session (лимит 10/мин) ---
  async login() {
    const json = await this.rpc(
      {
        data: { login: config.b2b.login, password: config.b2b.password },
        request: { method: 'login', model: 'auth', module: 'quickfox' },
      },
      { withSession: false },
    );
    if (!json || !json.success || !json.session) {
      throw new Error(`B2B login failed: ${json && json.message}`);
    }
    this.session = json.session;
    return this.session;
  }

  // --- Статика каталога (GET). Сессия передаётся в Cookie ---
  async fetchStatic(path) {
    if (!this.session) await this.login();
    const res = await fetch(`${b2bBaseUrl}${path}`, {
      headers: { Cookie: `session=${this.session}` },
    });
    if (res.status === 404) throw new Error(`B2B static 404 (авторизация?) ${path}`);
    if (!res.ok) throw new Error(`B2B static HTTP ${res.status} ${path}`);
    return res.json();
  }

  // --- 3.1 Дерево категорий (статика, 1/мин, обновляется ночью) ---
  getCategoryTree() {
    return this.fetchStatic(catalogFile('catalog_tree'));
  }

  // --- 3.2 Список товаров (статика, 2/час, обновляется ночью) ---
  getProducts() {
    return this.fetchStatic(catalogFile('products'));
  }

  // --- 3.3 Наличие и цены (get_active_products, 10/час). Только товары в наличии ---
  async getActiveProducts(filter = []) {
    const json = await this.rpc({
      request: { method: 'get_active_products', model: 'client_api', module: 'platform' },
      ...(filter.length ? { filter } : {}),
    });
    return (json && json.data && json.data.products) || [];
  }

  // --- 3.4 Изображения по артикулам (read_new, до 100 sku за запрос) ---
  async getImages(skus) {
    const json = await this.rpc({
      filter: [{ operator: 'IN', property: 'sku', value: skus.slice(0, 100) }],
      request: { method: 'read_new', model: 'products_clients_images', module: 'platform' },
    });
    return (json && json.data && json.data.product_images) || [];
  }

  imageUrl(relative, size = 'medium') {
    return `${b2bBaseUrl}/${relative}?size=${size}`;
  }

  // --- 3.6 / 3.7 Склады и адреса ---
  async getLogisticCenters() {
    const json = await this.rpc({
      request: { module: 'platform', model: 'client_api', method: 'get_available_logistic_centers' },
    });
    return (json && json.data && json.data.logistic_centers) || [];
  }

  async getAddresses() {
    const json = await this.rpc({
      request: { module: 'platform', model: 'client_api', method: 'get_addresses' },
    });
    return (json && json.data && json.data.addresses) || [];
  }

  // --- 4.2 Создание заказа (10/мин) → возвращает id заказа ---
  async createOrder({ comment = '', logisticCenter = config.b2b.logisticCenter } = {}) {
    const json = await this.rpc({
      request: { method: 'create', model: 'orders', module: 'platform' },
      data: [{ partner_comment: comment, logistic_center: logisticCenter }],
    });
    const order = json && json.data && json.data.orders && json.data.orders[0];
    if (!order) throw new Error(`B2B create order failed: ${json && json.message}`);
    return order;
  }

  // --- 4.3 Добавление/изменение/удаление позиций (client_update, 1/с) ---
  async updateOrderItems(docId, { update = [], destroy = [] }) {
    const json = await this.rpc({
      data: { doc_id: docId, update, destroy },
      request: { method: 'client_update', model: 'order_items', module: 'platform' },
    });
    return (json && json.data && json.data.order_items) || [];
  }

  // --- 4.5 Подписка на отгрузку (confirmed:true). После этого заказ менять нельзя ---
  async confirmOrder(orderId) {
    const json = await this.rpc({
      data: [{ confirmed: true, id: orderId }],
      request: { method: 'update', model: 'orders', module: 'platform' },
    });
    return json && json.success === true;
  }
}

export const b2b = new B2BClient();
