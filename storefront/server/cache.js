// TTL-кэш с single-flight. Ключевая роль — не превышать лимиты B2B API:
// пока значение свежее, загрузчик не вызывается; параллельные запросы за одним
// ключом ждут один общий вызов, а не плодят новые обращения к API.
export class TtlCache {
  constructor() {
    /** @type {Map<string, {value: any, expires: number}>} */
    this.store = new Map();
    /** @type {Map<string, Promise<any>>} */
    this.inflight = new Map();
  }

  /**
   * Вернуть свежее значение из кэша либо загрузить его через loader и закэшировать.
   * @param {string} key
   * @param {number} ttlMs время жизни значения
   * @param {() => Promise<any>} loader
   */
  async getOrLoad(key, ttlMs, loader) {
    const hit = this.store.get(key);
    const now = Date.now();
    if (hit && hit.expires > now) return hit.value;

    // Уже идёт загрузка этого ключа — присоединяемся к ней.
    const pending = this.inflight.get(key);
    if (pending) return pending;

    const promise = (async () => {
      try {
        const value = await loader();
        this.store.set(key, { value, expires: Date.now() + ttlMs });
        return value;
      } finally {
        this.inflight.delete(key);
      }
    })();

    this.inflight.set(key, promise);
    return promise;
  }

  invalidate(key) {
    this.store.delete(key);
  }
}

export const cache = new TtlCache();
