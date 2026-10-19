<?php $pageTitle='My orders | HEX CHEATS SHOP';$activePage='orders';require __DIR__.'/_head.php'; ?>
<section class="hx-page-heading"><p class="hx-eyebrow">ORDER HISTORY</p><h1>My orders</h1><p class="hx-muted">Payment status and fulfillment updates for your purchases.</p></section>
<section id="proofReceipt" class="hx-proof-receipt" <?= (($_GET['proof'] ?? '') === 'received') ? '' : 'hidden' ?> role="status" aria-live="polite"><span class="hx-proof-receipt-mark"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#check"></use></svg></span><div><strong>Payment proof uploaded successfully</strong><small>Your payment is awaiting admin verification. Order and payment status are listed below.</small></div></section>
<section id="ordersList" class="hx-list"><div class="hx-loading">Loading orders…</div></section>
<script>
(() => {
  const box = document.getElementById('ordersList');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const date = value => value ? new Date(Number(value) * 1000).toLocaleString() : '—';
  const money = value => '₹' + Number(value || 0).toFixed(2);
  let csrf = '';
  async function api(path, options = {}) {
    const headers = { ...(options.headers || {}) };
    if (csrf && options.method && options.method.toUpperCase() !== 'GET') headers['X-CSRF-Token'] = csrf;
    const response = await fetch(path, { credentials: 'same-origin', ...options, headers });
    let data = {};
    try { data = await response.json(); } catch {}
    if (response.status === 401) { location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search); throw new Error('Please sign in again.'); }
    if (!response.ok) throw new Error(data.error || 'Request failed.');
    return data;
  }
  function renderOrder(order, products) {
    const pendingUpi = order.payment_method === 'upi' && order.status === 'pending';
    const product = products.find(item => String(item.name || '') === String(order.product_name || ''));
    const download = order.status === 'complete' && product?.download_url
      ? `<a class="hx-button hx-secondary" href="${esc(product.download_url)}" target="_blank" rel="noopener noreferrer"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#download"></use></svg><span>SETUP + APK</span></a>` : '';
    const payment = pendingUpi ? `<div class="hx-payment-actions">${order.payment_url ? `<a class="hx-button hx-secondary" href="${esc(order.payment_url)}" target="_blank" rel="noopener noreferrer">Continue payment</a>` : ''}<button class="hx-button hx-secondary" type="button" data-check-order="${esc(order.order_id)}">Check payment status</button></div>` : '';
    const transaction = order.transaction_id && order.transaction_id !== 'WALLET' ? `<small>Transaction ID: ${esc(order.transaction_id)}</small>` : '';
    return `<article class="hx-panel hx-order"><div><b>${esc(order.product_name || 'Product')}</b><div class="hx-muted">${esc(order.duration || '')} · ${esc(date(order.created_at))}</div></div><span class="hx-status">${esc(order.status || 'pending')}</span><b>${money(order.amount)}</b><small>Order ${esc(order.order_id || '')}</small>${transaction}${order.delivery_key ? `<div class="hx-key">${esc(order.delivery_key)}</div>` : ''}<div class="hx-order-actions">${payment}${download}</div></article>`;
  }
  function renderTopup(item) {
    const method = item.payment_method === 'binance' ? 'Binance Pay'
      : item.payment_method === 'usdt_bep20' ? 'USDT · BNB Smart Chain (BEP20)' : 'UPI / Paytm';
    const amount = item.amount_usd ? `${Number(item.amount_usd).toFixed(2)} ${item.payment_method === 'usdt_bep20' ? 'USDT' : 'USD'} · ` : '';
    const txid = item.proof_txid || item.transaction_id || '';
    const review = item.review_note ? `<small>Review note: ${esc(item.review_note)}</small>` : '';
    return `<article class="hx-panel hx-order hx-order-topup"><div><b>${esc(method)} wallet top-up</b><div class="hx-muted">${esc(date(item.created_at))}</div></div><span class="hx-status">${esc(item.status || 'pending')}</span><b>${esc(amount)}${money(item.amount_inr)}</b><small>Payment ${esc(item.order_id || '')}</small>${txid ? `<small>Transaction ID: ${esc(txid)}</small>` : ''}${review}</article>`;
  }
  async function load() {
    try {
      const session = await api('/api/store/session');
      if (!session.authenticated) { location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search); return; }
      csrf = session.csrf_token || '';
      const [orderData, walletData, catalogData] = await Promise.all([
        api('/api/store/orders'), api('/api/store/wallet'), api('/api/store/products').catch(() => ({ products: [] }))
      ]);
      const orders = orderData.orders || [];
      const products = catalogData.products || [];
      const combined = [
        ...orders.map(item => ({ time: Number(item.created_at || 0), html: renderOrder(item, products) })),
        ...(walletData.payment_history || []).map(item => ({ time: Number(item.created_at || 0), html: renderTopup(item) }))
      ].sort((a, b) => b.time - a.time);
      box.innerHTML = combined.length ? combined.map(item => item.html).join('') : '<section class="hx-panel">No orders yet. <a href="/#catalog">Browse products</a></section>';
      box.querySelectorAll('[data-check-order]').forEach(button => button.addEventListener('click', async () => {
        const orderId = button.dataset.checkOrder; button.disabled = true; button.textContent = 'Checking…';
        try { await api('/api/store/checkouts/' + encodeURIComponent(orderId) + '/verify', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }); await load(); }
        catch (error) { button.disabled = false; button.textContent = 'Check failed — retry'; button.title = error.message; }
      }));
    } catch (error) { box.textContent = error.message || 'Could not load orders.'; }
  }
  load();
})();
</script>
<?php require __DIR__.'/_foot.php'; ?>
