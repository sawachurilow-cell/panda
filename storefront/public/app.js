// Фронтенд киоска. Знает только наш бэкенд (/api/*), с B2B API не общается напрямую.
'use strict';

const api = {
  async get(url) { const r = await fetch(url); if (!r.ok) throw new Error(r.status); return r.json(); },
  async post(url, body) {
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return r.json();
  },
};

const state = {
  categories: [],
  activeCategory: null,
  products: [],
  cart: new Map(), // sku -> {sku, name, price, qty}
};

const $ = (sel) => document.querySelector(sel);
const money = (v) => (v == null ? '—' : `${Math.round(v).toLocaleString('ru-RU')} ₽`);
const initial = (name) => (name || '?').trim().charAt(0).toUpperCase();

// ---- Загрузка ----
async function boot() {
  try {
    const health = await api.get('/api/health');
    $('#mode-badge').textContent = health.mode === 'demo' ? 'демо-режим' : 'онлайн';
  } catch { /* бэкенд недоступен — покажем пустой каталог */ }

  const { categories } = await api.get('/api/catalog/categories');
  state.categories = flatten(categories);
  renderCats();
  await loadProducts(null);
  wireGlobal();
}

// Дерево категорий → плоский список листовых для чипов.
function flatten(tree, acc = []) {
  for (const c of tree || []) {
    acc.push({ id: c.id, name: c.name });
    if (c.childrens && c.childrens.length) flatten(c.childrens, acc);
  }
  return acc;
}

function renderCats() {
  const el = $('#cats');
  el.innerHTML = '';
  const all = chip('Все', null);
  el.appendChild(all);
  for (const c of state.categories) el.appendChild(chip(c.name, c.id));
  highlightCat();
}

function chip(label, id) {
  const b = document.createElement('button');
  b.className = 'chip';
  b.textContent = label;
  b.dataset.cat = id == null ? '' : id;
  b.onclick = () => loadProducts(id);
  return b;
}

function highlightCat() {
  document.querySelectorAll('.chip').forEach((ch) => {
    const id = ch.dataset.cat === '' ? null : Number(ch.dataset.cat);
    ch.classList.toggle('active', id === state.activeCategory);
  });
}

async function loadProducts(category) {
  state.activeCategory = category;
  highlightCat();
  const url = category ? `/api/catalog/products?category=${category}` : '/api/catalog/products';
  const { products } = await api.get(url);
  state.products = products;
  renderGrid();
}

function renderGrid() {
  const grid = $('#grid');
  grid.querySelectorAll('.pcard').forEach((n) => n.remove());
  $('#grid-empty').hidden = state.products.length > 0;
  for (const p of state.products) grid.appendChild(productCard(p));
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
  const { product } = await api.get(`/api/catalog/product/${sku}`);
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

async function checkout() {
  const cart = [...state.cart.values()].map((it) => ({ sku: it.sku, qty: it.qty }));
  $('#checkout-btn').disabled = true;
  const res = await api.post('/api/orders', { cart });
  if (res && res.ok) {
    state.cart.clear();
    updateCartBadge();
    closeOverlay('cart');
    $('#done-text').textContent = `Номер заказа: ${res.order.id}${res.demo ? ' (демо)' : ''}. Подойдите к кассе для оплаты.`;
    show('done-overlay');
  } else {
    $('#checkout-btn').disabled = false;
    alert('Не удалось оформить заказ. Попробуйте ещё раз.');
  }
}

// ---- Утилиты UI ----
function wireGlobal() {
  $('#cart-btn').onclick = () => { renderCart(); show('cart-overlay'); };
  $('#checkout-btn').onclick = checkout;
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
