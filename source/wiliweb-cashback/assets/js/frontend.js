(() => {
  'use strict';
  const config = window.wwcbConfig;
  if (!config) return;
  const money = n => new Intl.NumberFormat('fa-IR').format(n) + ' تومان';
  async function api(path, body) {
    const response = await fetch(config.root + path, {method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce}, body: body ? JSON.stringify(body) : undefined, cache: 'no-store'});
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'دریافت اطلاعات ناموفق بود.');
    return data;
  }
  function update(q) {
    document.querySelectorAll('[data-wwcb-credit]').forEach(el => {
      el.hidden = q.base <= 0 || !q.supported;
      el.querySelector('.wwcb-estimate b').textContent = money(q.earned);
      el.querySelector('[data-wwcb-estimate-label]').textContent = q.earned ? 'با این خرید ' : 'اعتبار جدید این خرید: ';
      el.querySelector('[data-wwcb-estimate-suffix]').textContent = q.earned ? ' اعتبار می‌گیری' : (q.applied >= q.base && q.base > 0 ? '؛ پرداخت کامل با کیف پول' : '');
      const button = el.querySelector('[data-wwcb-apply]');
      if (!button) return;
      const status = el.querySelector('[data-wwcb-status]');
      status.textContent = q.applied > 0 ? money(q.applied) + ' از کیف پول اعمال شد' : (q.available > 0 ? 'موجودی کیف پول: ' + money(q.available) : 'کیف پولت فعلاً خالیه');
      if (q.available > 0 && q.base < q.minimum) status.textContent += '؛ ' + money(q.minimum - q.base) + ' تا حداقل خرید';
      button.dataset.wwcbApply = q.enabled ? '0' : '1';
      button.textContent = q.enabled ? (q.applied > 0 ? 'حذف اعتبار' : 'لغو استفاده') : 'استفاده از اعتبار';
      button.hidden = q.available <= 0 && !q.enabled;
      button.disabled = !q.enabled && (!q.usable || !q.supported);
    });
  }
  let quoteTimer;
  let quoteRevision = 0;
  function refresh() {
    clearTimeout(quoteTimer);
    const revision = ++quoteRevision;
    quoteTimer = setTimeout(async () => {
      if (!document.querySelector('[data-wwcb-credit]')) return;
      try { const quote = await api('quote'); if (revision === quoteRevision) update(quote); } catch (_) { /* Keep the server-rendered estimate when unavailable. */ }
    }, 150);
  }
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-wwcb-apply]');
    if (!button) return;
    button.disabled = true;
    const status = button.closest('[data-wwcb-credit]').querySelector('[data-wwcb-status]');
    status.textContent = 'در حال بررسی اعتبار…';
    const enabled = button.dataset.wwcbApply === '1';
    ++quoteRevision;
    clearTimeout(quoteTimer);
    try {
      if (document.querySelector('.wp-block-woocommerce-checkout,.wp-block-woocommerce-cart') && window.wc?.blocksCheckout?.extensionCartUpdate) {
        await window.wc.blocksCheckout.extensionCartUpdate({namespace: 'wwcb', data: {enabled}});
        update(await api('quote'));
      } else {
        update(await api('apply', {enabled}));
        // ParsKala owns its totals via a separate AJAX renderer. Reload after an explicit wallet change.
        if (document.querySelector('[data-prk-cv4-cart-page]')) { window.location.reload(); return; }
        window.jQuery?.(document.body).trigger('update_checkout');
        window.jQuery?.(document.body).trigger('wc_update_cart');
      }
    } catch (error) { status.textContent = error.message; button.disabled = false; }
  });
  function hints() {
    document.querySelectorAll('[data-wwcb-hint]').forEach(el => {
      const quantity = Number(document.querySelector('form.cart input.qty')?.value || 1);
      const earned = Math.ceil(Number(el.dataset.price) * quantity * Number(el.dataset.rate) / 10000 / Number(el.dataset.step)) * Number(el.dataset.step);
      el.querySelector('[data-wwcb-hint-text]').textContent = el.dataset.copy.replace('{amount}', new Intl.NumberFormat('fa-IR').format(earned));
    });
  }
  document.addEventListener('input', event => { if (event.target.matches('form.cart input.qty')) hints(); });
  if (window.jQuery) {
    window.jQuery(document).on('found_variation', 'form.variations_form', (_, variation) => { document.querySelectorAll('[data-wwcb-hint]').forEach(el => { el.dataset.price = variation.display_price; }); hints(); });
    window.jQuery(document.body).on('updated_checkout updated_wc_div prk:cart:updated', refresh);
  }
  async function init() {
    hints();
    refresh();
    if (document.querySelector('.wp-block-woocommerce-checkout,.wp-block-woocommerce-cart') && window.wp?.data?.subscribe) {
      let previous = '';
      window.wp.data.subscribe(() => {
        const store = window.wp.data.select('wc/store/cart');
        if (!store?.getCartData) return;
        const cart = store.getCartData();
        const signature = JSON.stringify([cart?.totals, cart?.items?.map(item => [item.key, item.quantity])]);
        if (signature !== previous) { previous = signature; refresh(); }
      });
    }
    const dialog = document.querySelector('[data-wwcb-dialog]');
    if (!dialog) return;
    try {
      const data = await api('wallet');
      if (data.popup) {
        let text = money(data.snapshot.available_toman) + ' اعتبار قابل استفاده دارید.';
        if (data.snapshot.next_expiry) text += ' ' + money(data.snapshot.next_expiry.amount_toman) + ' از این مبلغ تا ' + data.expiry_text + ' معتبر است.';
        dialog.querySelector('[data-wwcb-dialog-text]').textContent = text;
        const previous = document.activeElement;
        dialog.addEventListener('close', () => previous?.focus(), {once: true});
        dialog.querySelector('[data-wwcb-close]').addEventListener('click', () => dialog.close());
        dialog.showModal(); await api('popup', {});
      }
    } catch (_) { /* The server-rendered account remains usable if the request fails. */ }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
