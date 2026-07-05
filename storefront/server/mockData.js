// Демо-данные в формате ответов B2B API — чтобы витрина работала без реальной учётки.
// Структуры соответствуют докам: categories (3.1), products (3.2), active (3.3).

export const mockCategoryTree = [
  { id: 10, leaf: false, name: 'Картриджи и расходники', childrens: [
    { id: 11, leaf: true, name: 'Струйные картриджи', childrens: [] },
    { id: 12, leaf: true, name: 'Лазерные картриджи', childrens: [] },
  ] },
  { id: 20, leaf: false, name: 'Периферия', childrens: [
    { id: 21, leaf: true, name: 'Сетевые фильтры', childrens: [] },
    { id: 22, leaf: true, name: 'Ролики и запчасти', childrens: [] },
  ] },
];

export const mockProducts = [
  { sku: 282542, category: 11, name: 'Картридж струйный Cactus CS-C8727 черный для №27 HP DeskJet (20ml)',
    part: 'CS-C8727', vendor: 'Cactus', rrp: 850, warranty: '12', has_image: false, multiplicity: 1, weight: 0.08 },
  { sku: 106050, category: 21, name: 'Сетевой фильтр SVEN Elongator 3G-10m, оранжевый (1 розетка)',
    part: '974', vendor: 'SVEN', rrp: 450, warranty: '12', has_image: false, multiplicity: 1, weight: 0.6 },
  { sku: 2881306, category: 22, name: 'Ролик захвата обходного лотка HP LJ P2015/P2014/M2727 MFP (RL1-1525)',
    part: 'RL1-1525', vendor: 'Hewlett-Packard', rrp: 5000, warranty: '12', has_image: false, multiplicity: 1, weight: 0.02 },
  { sku: 1586891, category: 12, name: 'Картридж лазерный HP CE285A (85A) для LaserJet P1102',
    part: 'CE285A', vendor: 'Hewlett-Packard', rrp: 3200, warranty: '12', has_image: false, multiplicity: 1, weight: 0.7 },
];

// Наличие/цены (get_active_products): оптовая price, остаток qty (*/**/***).
export const mockActive = [
  { sku: 282542, price: 515.63, qty: '***', real_qty: 120, delivery_days: 0, multiplicity: 1 },
  { sku: 106050, price: 299.98, qty: '**', real_qty: 30, delivery_days: 0, multiplicity: 1 },
  { sku: 1586891, price: 1980.5, qty: '*', real_qty: 4, delivery_days: 0, multiplicity: 1 },
  // sku 2881306 намеренно отсутствует — товар не в наличии, в витрину не попадёт.
];
