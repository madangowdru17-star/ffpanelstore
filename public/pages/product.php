<?php
$pageTitle = 'Product | HEX CHEATS SHOP';
$activePage = 'shop';
$productKey = strtolower((string) ($_GET['product_key'] ?? ''));
require __DIR__ . '/_head.php';
?>
<style>
.ps-product{display:grid;grid-template-columns:minmax(0,.95fr) minmax(0,1.05fr);gap:14px;align-items:start}.ps-cover{padding:0;overflow:hidden}.ps-cover img{display:block;width:100%;aspect-ratio:1/1;object-fit:cover;background:var(--hx-green-soft);-webkit-touch-callout:none;user-select:none;-webkit-user-drag:none}.ps-cover-fallback{min-height:260px;display:grid;place-items:center;background:linear-gradient(145deg,#e5f1e3,#faf6e8);color:var(--hx-green);font-size:60px;font-weight:950}.ps-summary{padding:16px}.ps-platform{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:99px;background:var(--hx-green-soft);color:var(--hx-green);font-size:10px;font-weight:850;text-transform:uppercase}.ps-summary h1{margin:10px 0 5px;font-size:clamp(20px,4vw,29px);line-height:1.15;letter-spacing:-.04em}.ps-summary p{margin:8px 0;color:var(--hx-muted);font-size:12px;line-height:1.7}.ps-count{font-size:10px;color:var(--hx-muted)}.ps-card{overflow:hidden;padding:0}.ps-step-head{display:flex;align-items:center;gap:10px;padding:13px 15px;border-bottom:1px solid var(--hx-line);background:color-mix(in srgb,var(--hx-green) 5%,var(--hx-surface))}.ps-step-num{width:25px;height:25px;flex:0 0 25px;display:grid;place-items:center;border-radius:50%;background:var(--hx-green);color:#fff;font-weight:900;font-size:12px}.ps-step-head h2{margin:0;font-size:14px}.ps-card-content{padding:14px}.ps-variations{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.ps-variation{display:flex;justify-content:space-between;align-items:center;gap:8px;min-height:54px;padding:10px 11px;border:1px solid var(--hx-line);border-radius:12px;background:var(--hx-surface);color:var(--hx-ink);text-align:left;cursor:pointer}.ps-variation.active{border:2px solid var(--hx-green);background:var(--hx-green-soft);box-shadow:0 4px 14px color-mix(in srgb,var(--hx-green) 12%,transparent)}.ps-variation-name{font-size:11px;font-weight:850;text-transform:uppercase}.ps-variation-price{color:var(--hx-green);font-size:12px;font-weight:900;white-space:nowrap}.ps-features{display:flex;gap:7px;flex-wrap:wrap;margin:12px 0}.ps-feature{display:inline-flex;align-items:center;gap:5px;padding:6px 9px;border:1px solid var(--hx-line);border-radius:99px;background:var(--hx-surface-raised);font-size:10px;color:var(--hx-ink)}.ps-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:13px}.ps-buy{background:#0b8d20!important;border-color:#0b8d20!important}.ps-maintenance{background:#c62828!important;border-color:#c62828!important}.ps-inventory{margin:10px 0;padding:10px 12px;border-radius:10px;background:var(--hx-green-soft);font-size:11px;color:var(--hx-green)}.ps-inventory.warning{background:#fff1df;color:#8a5200}.ps-payment{margin-top:13px;border:1px solid var(--hx-line);border-radius:15px;background:var(--hx-surface);padding:14px}.ps-payment h3{font-size:14px;margin:0 0 6px}.ps-pay-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}.ps-status{margin-top:10px;color:var(--hx-muted);font-size:11px;line-height:1.55}.ps-payment img:not(.hx-payment-mark){display:block;width:min(220px,100%);margin:12px auto;border-radius:10px;background:#fff;padding:7px}.ps-bottom-note{font-size:10px;color:var(--hx-muted);line-height:1.6;margin:12px 0}.ps-section-space{margin-top:12px}.ps-product-detail{white-space:pre-line}.ps-back{display:inline-flex;align-items:center;gap:6px;margin:0 0 12px;color:var(--hx-green);font-weight:800;font-size:11px}.ps-hidden,[hidden]{display:none!important}.ps-setup-ready{display:flex;align-items:center;gap:8px;margin-top:12px;padding:12px;border:1px solid color-mix(in srgb,var(--hx-green) 28%,var(--hx-line));border-radius:12px;background:var(--hx-green-soft);color:var(--hx-green);font-size:12px;font-weight:800}.ps-setup-ready .hx-icon{width:18px;height:18px;flex:0 0 18px}.ps-setup-ready a{margin-left:auto;white-space:nowrap}.ps-history-link{display:inline-flex;align-items:center;gap:7px;margin-top:10px;color:var(--hx-green);font-size:11px;font-weight:800}.ps-history-link .hx-icon{width:16px;height:16px}
@media(max-width:650px){.ps-product{grid-template-columns:1fr;gap:10px}.ps-cover img{aspect-ratio:1.22/1;max-height:300px}.ps-cover-fallback{min-height:210px}.ps-summary{padding:14px}.ps-card-content{padding:12px}.ps-actions{display:grid;grid-template-columns:1fr}.ps-setup-ready{align-items:flex-start;flex-wrap:wrap}.ps-setup-ready a{width:100%;margin-left:0}}
@media(prefers-reduced-motion:reduce){.ps-variation{transition:none}}
</style>
<a class="ps-back" href="/#catalog"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#chevron-left"></use></svg><span>Back to products</span></a>
<div id="productRoot" class="ps-loading hx-panel">Loading product details…</div>
<script>
(() => {
  const key = <?= json_encode($productKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const root = document.getElementById('productRoot');
  let product = null, selectedDuration = '', csrf = '', account = null, balance = 0, activeOrder = '', pollTimer = null, idem = '';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const numeric = value => { const cleaned = String(value ?? '').replace(/[^0-9.\-]/g, ''); if (!cleaned) return null; const number = Number(cleaned); return Number.isFinite(number) ? number : null; };
  const money = (value, code = 'INR') => { const number = numeric(value); return number === null ? 'Price unavailable' : new Intl.NumberFormat(code === 'USD' ? 'en-US' : 'en-IN', { style: 'currency', currency: code, maximumFractionDigits: 2 }).format(number); };
  function platformMarkup(item) {
    const access = String(item.access_mode || '').trim().toLowerCase().replace(/[- ]/g, '_');
    const terms = [item.platform, item.category, item.name].filter(Boolean).join(' ').toLowerCase();
    let src = '', label = '', kind = '';
    if (/ios/.test(terms)) { src = 'apple-logo.svg'; label = 'iOS'; kind = 'apple'; }
    else if (access === 'root') { src = 'kitsune-mask-source.png'; label = 'Root'; kind = 'kitsune'; }
    else if (access === 'non_root') { src = 'android-robot.svg'; label = 'Non-root'; kind = 'android'; }
    else if (/android/.test(terms)) { src = 'android-robot.svg'; label = 'Android'; kind = 'android'; }
    return src ? `<span class="ps-platform"><img class="hx-platform-mark platform-mark-${kind}" src="/assets/brands/${src}" alt="" aria-hidden="true"><span>${label}</span></span>` : '';
  }
  async function api(path, options = {}) {
    const headers = { ...(options.headers || {}) };
    if (csrf && options.method && options.method.toUpperCase() !== 'GET') headers['X-CSRF-Token'] = csrf;
    const response = await fetch(path, { ...options, headers, credentials: 'same-origin' });
    let data = {}; try { data = await response.json(); } catch {}
    if (!response.ok) throw Error(data.error || 'Could not complete this request.');
    return data;
  }
  function currencyCode(inr, usd) { let selected = 'INR'; try { selected = localStorage.getItem('hex_shop_currency') === 'USD' ? 'USD' : 'INR'; } catch {} return selected === 'USD' && numeric(usd) !== null ? 'USD' : 'INR'; }
  function currencyPrice(inr, usd) { return currencyCode(inr, usd) === 'USD' ? usd : inr; }
  function priceText() { return money(currencyPrice(product.prices?.[selectedDuration], product.usd_prices?.[selectedDuration]), currencyCode(product.prices?.[selectedDuration], product.usd_prices?.[selectedDuration])); }
  function historyLink(orderId = activeOrder) { return `<a class="ps-history-link" href="/orders">View Order History${orderId ? ` · ${esc(orderId)}` : ''}<svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#chevron-right"></use></svg></a>`; }
  function revealPaidAccess(orderId, deliveryKey = '') {
    const box = document.getElementById('purchaseAccess');
    if (box) {
      box.innerHTML = `<div class="ps-setup-ready"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#check"></use></svg><span>Payment verified and order fulfilled</span>${product.download_url ? `<a class="hx-button" href="${esc(product.download_url)}" target="_blank" rel="noopener noreferrer"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#download"></use></svg><span>SETUP + APK</span></a>` : ''}</div>${deliveryKey ? `<div class="hx-key">${esc(deliveryKey)}</div>` : ''}${historyLink(orderId)}`;
      box.hidden = false;
    }
  }
  function showProofReceiptAndOpenHistory() {
    const overlay = document.createElement('div');
    overlay.className = 'hx-proof-success-overlay';
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-live', 'assertive');
    overlay.innerHTML = '<section class="hx-proof-success-card"><span class="hx-proof-success-mark"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#check"></use></svg></span><strong>Proof uploaded successfully</strong><p>Payment is awaiting admin verification. Opening Order History…</p></section>';
    document.body.append(overlay);
    window.setTimeout(() => { overlay.remove(); const target = '/orders?proof=received'; if (window.hxGo) window.hxGo(target); else location.assign(target); }, 1000);
  }
  function render() {
    const durations = product.durations || [], prices = product.prices || {}, usd = product.usd_prices || {}, maintenance = Boolean(product.maintenance), stock = product.stock || {};
    if (!durations.includes(selectedDuration)) selectedDuration = durations[0] || '';
    const stockValue = stock[selectedDuration], stockNumber = numeric(stockValue);
    const outOfStock = stockValue !== undefined && stockValue !== null && stockNumber !== null && stockNumber <= 0;
    const hasPrice = numeric(prices[selectedDuration]) !== null;
    const features = (product.features || []).filter(feature => !/(root|ios)/i.test(String(feature)));
    const title = esc(product.name);
    const image = product.image_url ? `<img src="${esc(product.image_url)}" alt="${title}" draggable="false" oncontextmenu="return false" onerror="this.nextElementSibling.classList.remove('ps-hidden');this.remove()">` : '';
    const platform = platformMarkup(product);
    root.className = '';
    root.innerHTML = `<div class="ps-product"><section class="hx-panel ps-cover">${image}<div class="ps-cover-fallback ${image ? 'ps-hidden' : ''}" aria-hidden="true">${esc(product.name.split(/\s+/).slice(0, 2).map(word => word[0]).join('').toUpperCase())}</div></section><div><section class="hx-panel ps-summary">${platform}<h1>${title}</h1><div class="ps-count">${Number(product.sold_count || 0).toLocaleString()} sold · ${durations.length} package${durations.length === 1 ? '' : 's'}</div><p>${esc(product.description || 'Choose a package, review the access details, then continue to checkout.')}</p><div class="ps-features">${features.map(feature => `<span class="ps-feature"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#check"></use></svg><span>${esc(feature)}</span></span>`).join('')}</div><div class="ps-actions">${product.demo_url ? `<a class="hx-button hx-secondary" href="${esc(product.demo_url)}" target="_blank" rel="noopener noreferrer"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#play"></use></svg><span>WATCH DEMO</span></a>` : ''}</div></section><section class="hx-panel ps-card ps-section-space"><div class="ps-step-head"><span class="ps-step-num">1</span><h2>Select package</h2></div><div class="ps-card-content"><div class="ps-variations">${durations.map(duration => `<button type="button" class="ps-variation ${duration === selectedDuration ? 'active' : ''}" data-duration="${esc(duration)}"><span class="ps-variation-name">${esc(duration)}</span><span class="ps-variation-price">${esc(money(currencyPrice(prices[duration], usd[duration]), currencyCode(prices[duration], usd[duration])))}</span></button>`).join('') || '<p>No packages are currently configured.</p>'}</div><div class="ps-inventory ${stock[selectedDuration] !== undefined && numeric(stock[selectedDuration]) === 0 ? 'warning' : ''}" id="availability">${maintenance ? 'Maintenance is in progress. Purchases are temporarily unavailable.' : stockValue !== undefined && stockValue !== null ? `Available stock: ${stockNumber !== null && stockNumber > 100000 ? 'In stock' : esc(stockValue)}` : 'Ready for digital delivery after payment confirmation.'}</div>${maintenance ? '<button class="hx-button ps-maintenance" disabled><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#wrench"></use></svg><span>MAINTENANCE</span></button>' : `<button id="buyNow" class="hx-button ps-buy" style="width:100%" ${selectedDuration && hasPrice && !outOfStock ? '' : 'disabled'}>${outOfStock ? 'OUT OF STOCK' : !hasPrice ? 'PRICE NOT SET' : `<svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#cart"></use></svg><span>BUY NOW · ${esc(priceText())}</span>`}</button>`}<div class="ps-bottom-note">Wallet and UPI settle in INR. Binance Pay and USDT/BEP20 orders require transaction-proof review before fulfillment.</div><div id="purchaseAccess" hidden></div></div></section><section class="hx-panel ps-card ps-section-space"><div class="ps-step-head"><span class="ps-step-num">2</span><h2>Product information</h2></div><div class="ps-card-content"><p class="ps-product-detail">${esc(product.description || 'Please review the compatibility information before ordering.')}</p></div></section><section id="paymentPanel" class="ps-payment ps-hidden"><h3>Choose payment method</h3><div id="payDescription" class="hx-muted"></div><div id="payOptions" class="ps-pay-row"><button id="walletPay" class="hx-button hx-secondary" type="button"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#wallet"></use></svg><span>Pay from wallet</span></button><button id="onlinePay" class="hx-button" type="button"><img class="hx-payment-mark" src="/assets/brands/google-pay-mark.svg" alt="" aria-hidden="true"><span>Pay online · UPI</span></button><button id="payBinance" class="hx-button hx-secondary" type="button"><img class="hx-payment-mark" src="/assets/brands/binance-pay.png" alt="" aria-hidden="true"><span>Binance Pay · USD</span></button><button id="payUsdt" class="hx-button hx-secondary" type="button"><img class="hx-payment-mark" src="/assets/brands/usdt-tether-mark.svg" alt="" aria-hidden="true"><span>USDT · BEP20</span></button></div><div id="payStatus" class="ps-status" role="status" aria-live="polite"></div><div id="cryptoProofArea" class="ps-hidden"><div id="cryptoInstructions" class="hx-muted"></div><form id="cryptoProofForm" class="hx-form" enctype="multipart/form-data"><label>Transaction ID<input name="txid" minlength="4" maxlength="180" required autocomplete="off"></label><label>Payment screenshot (PNG, JPEG, WebP · max 5 MB)<input name="proof" type="file" accept="image/png,image/jpeg,image/webp" required></label><button class="hx-button" type="submit">Submit proof for admin review</button></form></div><a id="paymentLink" class="hx-button hx-secondary ps-hidden" target="_blank" rel="noopener noreferrer">Open secure payment page</a><img id="paymentQr" class="ps-hidden" alt="Payment QR code"><button id="checkOrder" class="hx-button hx-secondary ps-hidden" type="button">Check payment</button></section></div></div>`;
    root.querySelectorAll('[data-duration]').forEach(button => button.addEventListener('click', () => { selectedDuration = button.dataset.duration; idem = ''; render(); }));
    if (!maintenance) document.getElementById('buyNow')?.addEventListener('click', startPurchase);
    document.getElementById('walletPay')?.addEventListener('click', payWallet);
    document.getElementById('onlinePay')?.addEventListener('click', payOnline);
    document.getElementById('payBinance')?.addEventListener('click', () => payCrypto('binance'));
    document.getElementById('payUsdt')?.addEventListener('click', () => payCrypto('usdt_bep20'));
    document.getElementById('cryptoProofForm')?.addEventListener('submit', submitCryptoProof);
    document.getElementById('checkOrder')?.addEventListener('click', checkOrder);
  }
  async function startPurchase() {
    if (!selectedDuration) return;
    if (!account) { location.href = '/login?next=' + encodeURIComponent('/product/' + key); return; }
    const panel = document.getElementById('paymentPanel'); panel.classList.remove('ps-hidden');
    panel.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
    document.getElementById('payDescription').textContent = `${product.name} · ${selectedDuration} · ${priceText()} · Wallet ${money(balance)}`;
    const walletButton = document.getElementById('walletPay');
    walletButton.querySelector('span').textContent = `Pay from wallet · ${money(product.prices?.[selectedDuration])}`;
    walletButton.disabled = balance + 0.00001 < (numeric(product.prices?.[selectedDuration]) || 0);
    idem = idem || ((globalThis.crypto?.randomUUID?.() || `${Date.now()}${Math.random()}`).replace(/-/g, ''));
  }
  async function payWallet() {
    const button = document.getElementById('walletPay'), status = document.getElementById('payStatus'); button.disabled = true; status.textContent = 'Processing wallet payment and delivery…';
    try {
      const data = await api('/api/store/checkout/wallet', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ product_key: key, duration: selectedDuration, idempotency_key: `${idem}_wallet` }) });
      idem = ''; balance = Number(data.balance_inr || balance); activeOrder = data.order_id || '';
      if (data.status === 'complete') { status.textContent = 'Payment verified and your order is fulfilled.'; revealPaidAccess(data.order_id, data.delivery_key || ''); }
      else { status.textContent = `Order ${data.order_id} accepted. Delivery status: ${data.status}.`; status.insertAdjacentHTML('afterend', historyLink(data.order_id)); }
      document.getElementById('payOptions').classList.add('ps-hidden');
    } catch (error) { status.textContent = error.message; button.disabled = false; }
  }
  async function payOnline() {
    const button = document.getElementById('onlinePay'), status = document.getElementById('payStatus'); button.disabled = true; status.textContent = 'Creating secure UPI checkout…';
    try {
      const data = await api('/api/store/checkout', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ product_key: key, duration: selectedDuration, idempotency_key: `${idem}_upi` }) });
      idem = ''; activeOrder = data.order_id;
      const image = document.getElementById('paymentQr'); image.src = data.qr_url; image.classList.remove('ps-hidden');
      const link = document.getElementById('paymentLink'); if (data.payment_url) { link.href = data.payment_url; link.classList.remove('ps-hidden'); }
      document.getElementById('checkOrder').classList.remove('ps-hidden'); status.textContent = `Order ${data.order_id} · Pay ${money(data.amount_inr)}. Waiting for provider confirmation.`;
      document.getElementById('payOptions').classList.add('ps-hidden'); status.insertAdjacentHTML('afterend', historyLink(data.order_id)); clearInterval(pollTimer); pollTimer = setInterval(checkOrder, 7000);
    } catch (error) { status.textContent = error.message; button.disabled = false; }
  }
  async function payCrypto(method) {
    const button = document.getElementById(method === 'binance' ? 'payBinance' : 'payUsdt'), status = document.getElementById('payStatus'); button.disabled = true; status.textContent = 'Preparing secure crypto checkout…';
    try {
      const data = await api('/api/store/checkout/crypto', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ product_key: key, duration: selectedDuration, payment_method: method, idempotency_key: `${idem}_${method}` }) });
      idem = ''; activeOrder = data.order_id;
      const qr = document.getElementById('paymentQr'); qr.src = data.qr_url; qr.classList.remove('ps-hidden');
      const amount = Number(data.amount_usd || 0).toFixed(2), unit = method === 'usdt_bep20' ? 'USDT' : 'USD';
      status.textContent = `${data.product_name} · ${data.duration} · ${money(data.amount_inr)}. Send exactly ${amount} ${unit}; quote locked at ₹${Number(data.exchange_rate_inr).toFixed(4)} per USD.`;
      document.getElementById('paymentLink').classList.add('ps-hidden');
      document.getElementById('cryptoInstructions').textContent = (method === 'usdt_bep20' ? 'NETWORK: BNB Smart Chain (BEP20) only. Do not use another network. ' : '') + (data.instructions || 'Pay the exact amount, then submit your transaction ID and screenshot for review.');
      document.getElementById('cryptoProofArea').classList.remove('ps-hidden'); document.getElementById('payOptions').classList.add('ps-hidden'); document.getElementById('checkOrder').classList.remove('ps-hidden');
      if (data.status === 'proof_submitted') { document.getElementById('cryptoProofForm').classList.add('ps-hidden'); status.textContent = 'Proof already submitted. Waiting for admin review.'; }
      status.insertAdjacentHTML('afterend', historyLink(data.order_id)); clearInterval(pollTimer); pollTimer = setInterval(checkOrder, 8000);
    } catch (error) { status.textContent = error.message; button.disabled = false; }
  }
  async function submitCryptoProof(event) {
    event.preventDefault(); if (!activeOrder) return;
    const form = event.currentTarget, button = form.querySelector('button[type="submit"]'), status = document.getElementById('payStatus');
    button.disabled = true; status.textContent = 'Submitting payment proof…';
    try {
      await api('/api/store/checkouts/' + encodeURIComponent(activeOrder) + '/proof', { method: 'POST', body: new FormData(form) });
      clearInterval(pollTimer); showProofReceiptAndOpenHistory();
    } catch (error) { status.textContent = error.message; button.disabled = false; }
  }
  async function checkOrder() {
    if (!activeOrder) return;
    const status = document.getElementById('payStatus');
    try {
      const data = await api('/api/store/checkouts/' + encodeURIComponent(activeOrder) + '/verify', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      if (data.status === 'complete') {
        clearInterval(pollTimer); status.textContent = 'Payment verified and your order is fulfilled.'; revealPaidAccess(data.order_id, data.delivery_key || ''); document.getElementById('checkOrder').classList.add('ps-hidden');
      } else if (data.status === 'proof_submitted') status.textContent = 'Payment proof received. Awaiting administrator verification; delivery is not available yet.';
      else if (data.status === 'manual_pending') status.textContent = 'Payment request created. Complete the transfer, then submit your transaction ID and screenshot.';
      else if (['failed', 'paid_delivery_failed', 'delivery_unknown', 'amount_review', 'rejected'].includes(data.status)) { clearInterval(pollTimer); status.textContent = `Order status: ${data.status}. See Order History for the latest details.`; }
      else status.textContent = `Order ${activeOrder}: ${data.status}. Checking again shortly.`;
    } catch (error) { status.textContent = error.message; }
  }
  async function init() {
    try {
      const [productData, session] = await Promise.all([api('/api/store/products'), api('/api/store/session')]);
      product = (productData.products || []).find(item => item.key === key);
      if (!product) { root.textContent = 'This product is unavailable.'; return; }
      if (session.authenticated) { account = session.account; csrf = session.csrf_token || ''; try { const wallet = await api('/api/store/wallet'); balance = Number(wallet.balance_inr || 0); } catch {} }
      document.title = `${product.name} | HEX CHEATS SHOP`; render();
    } catch (error) { root.textContent = error.message || 'Could not load product.'; }
  }
  document.addEventListener('hex:currencychange', () => { if (product) render(); });
  init();
})();
</script>
<?php require __DIR__ . '/_foot.php'; ?>
