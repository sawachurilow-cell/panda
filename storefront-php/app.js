// Фронтенд киоска. Знает только наш бэкенд (/api/*), с B2B API не общается напрямую.
'use strict';

// Все вызовы идут через реальный PHP-файл с маршрутом в ?r= — работает на любом
// Nginx+PHP-FPM без правки конфига. api('catalog/products', {category:5}) и т.п.
const API = '/api/index.php?r=';
const api = {
  url(route, params) {
    let u = API + route;
    if (params) for (const [k, v] of Object.entries(params)) u += `&${k}=${encodeURIComponent(v)}`;
    return u;
  },
  async get(route, params) {
    const r = await fetch(api.url(route, params));
    if (!r.ok) throw new Error(r.status);
    return r.json();
  },
  async post(route, body) {
    const r = await fetch(api.url(route), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return r.json();
  },
};

const LIMIT = 300; // сколько товаров грузим за раз (каталог большой)

const state = {
  roots: [],        // категории верхнего уровня
  parent: null,     // текущий узел углубления (null = корень)
  path: [],         // стек углубления для кнопки «Назад»
  products: [],
  total: 0,
  cart: new Map(),  // sku -> {sku, name, price, qty}
};

const $ = (sel) => document.querySelector(sel);
const money = (v) => (v == null ? '—' : `${Math.round(v).toLocaleString('ru-RU')} ₽`);
const initial = (name) => (name || '?').trim().charAt(0).toUpperCase();

// ---- Загрузка ----
async function boot() {
  try {
    const health = await api.get('health');
    $('#mode-badge').textContent = health.mode === 'demo' ? 'демо-режим' : 'онлайн';
  } catch { /* бэкенд недоступен */ }

  const { categories } = await api.get('catalog/categories');
  state.roots = categories || [];
  renderCats();
  showHint();     // товары не грузим сразу — каталог огромный, ждём выбора
  wireGlobal();
}

// ---- Категории: чипы с углублением ----
function renderCats() {
  const el = $('#cats');
  el.innerHTML = '';
  el.appendChild(chip('🏠 Каталог', goHome, 'home'));
  if (state.path.length) el.appendChild(chip('← Назад', goBack, 'back'));

  const level = state.parent ? (state.parent.childrens || []) : state.roots;
  for (const node of level) el.appendChild(chip(node.name, () => openCategory(node)));
}

function chip(label, onClick, extra) {
  const b = document.createElement('button');
  b.className = 'chip' + (extra ? ' ' + extra : '');
  b.textContent = label;
  b.onclick = onClick;
  return b;
}

function goHome() {
  state.parent = null;
  state.path = [];
  clearSearch();
  renderCats();
  showHint();
}

function goBack() {
  state.path.pop();
  state.parent = state.path[state.path.length - 1] || null;
  renderCats();
  if (state.parent) loadProducts({ category: state.parent.id, catName: state.parent.name });
  else showHint();
}

// Тап по категории: показать её товары (с вложенными) и, если есть подкатегории, углубиться.
function openCategory(node) {
  clearSearch();
  loadProducts({ category: node.id, catName: node.name });
  if (node.childrens && node.childrens.length) {
    state.parent = node;
    state.path.push(node);
    renderCats();
  }
}

// ---- Загрузка и отрисовка товаров ----
async function loadProducts({ category = null, q = '', catName = '' } = {}) {
  const params = { limit: LIMIT };
  if (category) params.category = category;
  if (q) params.q = q;
  const data = await api.get('catalog/products', params);
  state.products = data.products || [];
  state.total = data.total || 0;
  renderGrid();
  renderInfo({ q, catName, shown: data.shown || 0, total: state.total });
}

function renderGrid() {
  const grid = $('#grid');
  grid.querySelectorAll('.pcard').forEach((n) => n.remove());
  const empty = $('#grid-empty');
  if (!state.products.length) {
    empty.hidden = false;
    empty.textContent = 'Ничего не найдено';
  } else {
    empty.hidden = true;
  }
  for (const p of state.products) grid.appendChild(productCard(p));
}

function renderInfo({ q, catName, shown, total }) {
  const el = $('#catinfo');
  let txt = '';
  if (q) txt = `Поиск «${q}»: найдено ${total}`;
  else if (catName) txt = `${catName}: ${total} товаров`;
  if (total > shown) txt += ` · показаны первые ${shown} — уточните категорию или поиск`;
  el.textContent = txt;
  el.hidden = !txt;
}

function showHint() {
  state.products = [];
  state.total = 0;
  $('#grid').querySelectorAll('.pcard').forEach((n) => n.remove());
  const empty = $('#grid-empty');
  empty.hidden = false;
  empty.textContent = 'Выберите категорию или воспользуйтесь поиском';
  $('#catinfo').hidden = true;
}

function clearSearch() {
  const s = $('#search');
  if (s) s.value = '';
}

function productCard(p) {
  const el = document.createElement('div');
  el.className = 'pcard';
  el.innerHTML = `
    <div class="thumb">${initial(p.vendor)}</div>
    <div class="pvendor">${escapeHtml(p.vendor || '')}</div>
    <div class="pname">${escapeHtml(p.name)}</div>
    <div class="prow">
      <span class="price">${money(p.price)}</span>
      <span class="stock ${p.stock}">${stockLabel(p.stock)}</span>
    </div>
    <button class="add">В корзину</button>`;
  el.querySelector('.thumb').onclick = () => openProduct(p.sku);
  el.querySelector('.pname').onclick = () => openProduct(p.sku);
  el.querySelector('.add').onclick = () => { addToCart(p, 1); pulse(el.querySelector('.add')); };
  return el;
}

function stockLabel(s) {
  return { high: 'много', mid: 'в наличии', low: 'мало' }[s] || 'уточнить';
}

// ---- Карточка товара ----
async function openProduct(sku) {
  const { product } = await api.get(`catalog/product/${sku}`);
  let qty = 1;
  const sheet = $('#product-sheet');
  const img = product.images && product.images[0];
  const render = () => {
    sheet.innerHTML = `
      <div class="thumb" style="${img ? `background-image:url('${img}')` : ''}">${img ? '' : initial(product.vendor)}</div>
      <h2>${escapeHtml(product.name)}</h2>
      <div class="meta">${escapeHtml(product.vendor || '')} · арт. ${product.sku}${product.warranty ? ` · гарантия ${product.warranty} мес.` : ''}</div>
      <div class="prow"><span class="price">${money(product.price)}</span><span class="stock ${product.stock}">${stockLabel(product.stock)}</span></div>
      <div class="buy">
        <div class="qty"><button data-d="-1">−</button><b>${qty}</b><button data-d="1">+</button></div>
        <button class="primary" style="flex:1" id="p-add">Добавить · ${money(product.price * qty)}</button>
      </div>`;
    sheet.querySelectorAll('.qty button').forEach((b) => {
      b.onclick = () => { qty = Math.max(1, qty + Number(b.dataset.d)); render(); };
    });
    sheet.querySelector('#p-add').onclick = () => { addToCart(product, qty); closeOverlay('product'); };
  };
  render();
  show('product-overlay');
}

// ---- Корзина ----
function addToCart(p, qty) {
  const cur = state.cart.get(p.sku) || { sku: p.sku, name: p.name, price: p.price, qty: 0 };
  cur.qty += qty;
  state.cart.set(p.sku, cur);
  updateCartBadge();
}

function updateCartBadge() {
  let n = 0;
  for (const it of state.cart.values()) n += it.qty;
  $('#cart-count').textContent = n;
}

function renderCart() {
  const box = $('#cart-items');
  box.innerHTML = '';
  let total = 0;
  for (const it of state.cart.values()) {
    total += (it.price || 0) * it.qty;
    const row = document.createElement('div');
    row.className = 'citem';
    row.innerHTML = `
      <div class="cn">${escapeHtml(it.name)}</div>
      <div class="qty"><button data-d="-1">−</button><b>${it.qty}</b><button data-d="1">+</button></div>
      <div class="cp">${money((it.price || 0) * it.qty)}</div>`;
    const [minus, plus] = row.querySelectorAll('.qty button');
    minus.onclick = () => changeQty(it.sku, -1);
    plus.onclick = () => changeQty(it.sku, 1);
    box.appendChild(row);
  }
  if (!state.cart.size) box.innerHTML = '<div class="empty">Корзина пуста</div>';
  $('#cart-total').textContent = money(total);
  $('#checkout-btn').disabled = state.cart.size === 0;
}

function changeQty(sku, d) {
  const it = state.cart.get(sku);
  if (!it) return;
  it.qty += d;
  if (it.qty <= 0) state.cart.delete(sku);
  updateCartBadge();
  renderCart();
}

function cartTotal() {
  let t = 0;
  for (const it of state.cart.values()) t += (it.price || 0) * it.qty;
  return t;
}

// «Оформить заказ» из корзины → открыть форму с данными клиента.
function openCheckout() {
  if (!state.cart.size) return;
  closeOverlay('cart');
  $('#checkout-total').textContent = money(cartTotal());
  $('#form-error').hidden = true;
  show('checkout-overlay');
}

async function submitOrder(e) {
  e.preventDefault();
  const form = e.target;
  const customer = {
    name: form.name.value.trim(),
    phone: form.phone.value.trim(),
    address: form.address.value.trim(),
    comment: form.comment.value.trim(),
  };
  const err = $('#form-error');
  if (!customer.name || !customer.phone || !customer.address) {
    err.textContent = 'Заполните ФИО, телефон и адрес разгрузки.';
    err.hidden = false;
    return;
  }
  const cart = [...state.cart.values()].map((it) => ({ sku: it.sku, qty: it.qty }));
  const btn = $('#submit-order');
  btn.disabled = true;
  try {
    const res = await api.post('orders', { cart, customer });
    if (res && res.ok) {
      state.cart.clear();
      updateCartBadge();
      closeOverlay('checkout');
      form.reset();
      $('#done-text').textContent = `Номер заказа: ${res.order.id}${res.demo ? ' (демо)' : ''}. Подойдите к кассе для оплаты.`;
      show('done-overlay');
    } else {
      err.textContent = 'Не удалось оформить заказ. Попробуйте ещё раз.';
      err.hidden = false;
    }
  } catch {
    err.textContent = 'Ошибка связи. Попробуйте ещё раз.';
    err.hidden = false;
  } finally {
    btn.disabled = false;
  }
}

// ---- Утилиты UI ----
function wireGlobal() {
  // Поиск по названию (с задержкой, минимум 2 символа).
  const search = $('#search');
  let t;
  search.oninput = () => {
    clearTimeout(t);
    const q = search.value.trim();
    t = setTimeout(() => {
      if (q.length === 0) { goHome(); return; }
      if (q.length < 2) return;
      state.parent = null;
      state.path = [];
      renderCats();
      loadProducts({ q });
    }, 350);
  };

  $('#cart-btn').onclick = () => { renderCart(); show('cart-overlay'); };
  $('#checkout-btn').onclick = openCheckout;
  $('#checkout-form').onsubmit = submitOrder;
  $('#done-btn').onclick = () => closeOverlay('done');
  document.querySelectorAll('[data-close]').forEach((b) => (b.onclick = () => closeOverlay(b.dataset.close)));
  // Клик по фону оверлея — закрыть.
  document.querySelectorAll('.overlay').forEach((ov) => {
    ov.onclick = (e) => { if (e.target === ov) ov.hidden = true; };
  });
}

const show = (id) => ($(`#${id}`).hidden = false);
const closeOverlay = (name) => ($(`#${name}-overlay`).hidden = true);
const pulse = (el) => { el.style.transform = 'scale(.94)'; setTimeout(() => (el.style.transform = ''), 120); };
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

boot().catch((e) => { console.error(e); $('#grid-empty').hidden = false; });
