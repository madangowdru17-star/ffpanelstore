'use strict';

const $ = id => document.getElementById(id);
let products = [];
let account = null;
let csrfToken = '';
let authMode = 'login';
let activeOrder = '';
let activePaymentType = 'checkout';
let pollTimer = null;
let pendingProduct = null;
let currency = localStorage.getItem('hex_shop_currency') === 'USD' ? 'USD' : 'INR';

const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[ch]));
const numberValue = value => {
  const n = Number(String(value ?? '').replace(/[^0-9.-]/g, ''));
  return Number.isFinite(n) ? n : null;
};
const money = (value, code = currency) => {
  const n = numberValue(value);
  if (n === null) return String(value ?? (code === 'USD' ? 'USD price unavailable' : 'Price on request'));
  return new Intl.NumberFormat(code === 'USD' ? 'en-US' : 'en-IN', {
    style: 'currency', currency: code, maximumFractionDigits: 2
  }).format(n);
};

async function request(path, options = {}) {
  const headers = { ...(options.headers || {}) };
  if (csrfToken && options.method && options.method.toUpperCase() !== 'GET') {
    headers['X-CSRF-Token'] = csrfToken;
  }
  const response = await fetch(path, { ...options, headers, credentials: 'same-origin' });
  let body = {};
  try { body = await response.json(); } catch {}
  if (!response.ok) throw new Error(body.error || body.message || 'Request failed.');
  return body;
}
function toast(message, error = false) {
  const node = $('toast');
  node.textContent = message;
  node.classList.toggle('error', error);
  node.classList.remove('hidden');
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => node.classList.add('hidden'), 3800);
}
function showAccount() {
  $('accountPanel').classList.add('show');
  $('accountPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
  loadWallet();
  loadOrders();
}
function openAuth() {
  if (account) return showAccount();
  $('authModal').classList.remove('hidden');
}
function setAuthMode(mode) {
  authMode = mode;
  const register = mode === 'register';
  $('loginTab').classList.toggle('active', !register);
  $('registerTab').classList.toggle('active', register);
  $('nameField').classList.toggle('hidden', !register);
  $('authTitle').textContent = register ? 'Create your account' : 'Your shop account';
  $('authSubmit').textContent = register ? 'Create account' : 'Sign in';
  $('password').autocomplete = register ? 'new-password' : 'current-password';
  $('authError').textContent = '';
}
function displayPrice(product, duration) {
  if (currency === 'USD') {
    const usd = product.usd_prices && product.usd_prices[duration];
    return usd ? money(usd, 'USD') : 'USD price unavailable';
  }
  return money(product.prices && product.prices[duration], 'INR');
}
function renderProducts() {
  const query = $('search').value.trim().toLowerCase();
  const shown = products.filter(p => (p.name + ' ' + p.category + ' ' + (p.features || []).join(' ')).toLowerCase().includes(query));
  $('productCount').textContent = String(products.length).padStart(2, '0');
  if (!shown.length) {
    $('catalogGrid').innerHTML = '<div class="empty">No products match that search.</div>';
    return;
  }
  $('catalogGrid').innerHTML = shown.map((p, i) => {
    const options = (p.durations || []).map(duration =>
      `<option value="${escapeHtml(duration)}">${escapeHtml(duration)} · ${escapeHtml(displayPrice(p, duration))}</option>`
    ).join('');
    const initials = escapeHtml(p.name.split(/\s+/).slice(0, 2).map(x => x[0]).join('').toUpperCase());
    return `<article class="product"><div class="product-image"><img src="${escapeHtml(p.image_url)}" alt="${escapeHtml(p.name)}" loading="lazy" onload="if(this.naturalWidth)this.nextElementSibling.style.display='none'" onerror="this.remove()"><div class="fallback" aria-hidden="true">${initials}</div><span class="product-tag">${escapeHtml(p.category || 'Digital access')}</span></div><div class="product-body"><div class="product-title"><h3>${escapeHtml(p.name)}</h3><span class="category">${(p.durations || []).length} options</span></div><div class="features">${(p.features || []).slice(0, 4).map(f => `<span class="feature">${escapeHtml(f)}</span>`).join('')}</div>${p.demo_url ? `<a class="hint" href="${escapeHtml(p.demo_url)}" target="_blank" rel="noreferrer">Watch product demo</a>` : ''}<div class="buy-row"><select aria-label="Select access period for ${escapeHtml(p.name)}" id="duration-${i}">${options || '<option>No options</option>'}</select><button class="button" ${options ? '' : 'disabled'} onclick="buyProduct('${escapeHtml(p.key)}','duration-${i}')">Buy</button></div></div></article>`;
  }).join('');
}
async function loadProducts() {
  try {
    const data = await request('/api/store/products');
    products = data.products || [];
    renderProducts();
  } catch (e) {
    $('catalogGrid').innerHTML = `<div class="empty">${escapeHtml(e.message)}<br><small>Refresh to try again.</small></div>`;
  }
}
function updateAccountDisplay() {
  if (!account) return;
  $('authOpen').textContent = account.display_name || account.email || 'My account';
  $('accountName').textContent = account.display_name || account.email || 'Shop customer';
  $('accountMeta').textContent = account.email || 'Shop account';
  $('profileDisplayName').value = account.display_name || '';
  $('walletBalance').textContent = money(account.balance_inr || 0, 'INR');
  $('navBalance').textContent = money(account.balance_inr || 0, 'INR');
  $('navBalance').classList.remove('hidden');
}
async function syncSession() {
  try {
    const data = await request('/api/store/session');
    if (data.authenticated) {
      account = data.account;
      csrfToken = data.csrf_token || '';
      updateAccountDisplay();
      $('accountPanel').classList.add('show');
      await Promise.all([loadWallet(), loadOrders()]);
    } else {
      account = null;
      csrfToken = '';
      $('authOpen').textContent = 'Sign in';
      $('navBalance').classList.add('hidden');
      $('accountPanel').classList.remove('show');
    }
  } catch {
    account = null;
    csrfToken = '';
  }
}
async function loadWallet() {
  if (!account) return;
  try {
    const data = await request('/api/store/wallet');
    account.balance_inr = Number(data.balance_inr || 0);
    updateAccountDisplay();
    const entries = data.transactions || [];
    $('walletTransactions').innerHTML = entries.length ? entries.map(t => {
      const positive = Number(t.amount_inr) >= 0;
      return `<div class="wallet-txn"><div><strong>${escapeHtml(t.description || t.kind)}</strong><small>${escapeHtml(new Date(Number(t.created_at) * 1000).toLocaleString())}</small></div><b class="${positive ? 'credit' : 'debit'}">${positive ? '+' : '−'}${escapeHtml(money(Math.abs(Number(t.amount_inr)), 'INR'))}</b></div>`;
    }).join('') : '<div class="hint">Wallet activity will appear here.</div>';
  } catch (e) {
    $('walletTransactions').innerHTML = `<div class="hint">${escapeHtml(e.message)}</div>`;
  }
}
async function loadOrders() {
  if (!account) return;
  try {
    const data = await request('/api/store/orders');
    const rows = data.orders || [];
    $('orders').innerHTML = rows.length ? rows.map(o => `<div class="order"><div><strong>${escapeHtml(o.product_name)}</strong><small>${escapeHtml(o.duration)} · ${escapeHtml(o.order_id)}</small></div><div>${escapeHtml(money(o.amount, 'INR'))}</div><div class="order-status">${escapeHtml(o.status)}</div><div class="order-key">${o.delivery_key ? escapeHtml(o.delivery_key) : '<span class="muted">Waiting for delivery/verification</span>'}</div></div>`).join('') : '<div class="empty">Your completed and pending orders will appear here.</div>';
  } catch {}
}
async function authSubmit(event) {
  event.preventDefault();
  $('authError').textContent = '';
  const payload = { email: $('email').value.trim(), password: $('password').value };
  if (authMode === 'register') {
    payload.display_name = $('displayName').value.trim();
    if (payload.password.length < 10) {
      $('authError').textContent = 'Password must be at least 10 characters.';
      return;
    }
  }
  const path = authMode === 'register' ? '/api/store/register' : '/api/store/login';
  try {
    await request(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    $('authModal').classList.add('hidden');
    await syncSession();
    toast(authMode === 'register' ? 'Account created. You are signed in.' : 'Signed in.');
    if (pendingProduct) {
      const next = pendingProduct;
      pendingProduct = null;
      choosePurchase(next.key, next.duration);
    }
  } catch (e) {
    $('authError').textContent = e.message;
  }
}
function buyProduct(key, selectId) {
  const product = products.find(item => item.key === key);
  const duration = $(selectId)?.value;
  if (!product || !duration) return;
  if (!account) {
    pendingProduct = { key, duration };
    setAuthMode('login');
    openAuth();
    return;
  }
  choosePurchase(key, duration);
}
function choosePurchase(key, duration) {
  const product = products.find(item => item.key === key);
  if (!product) return;
  const oldKey = pendingProduct && pendingProduct.key === key && pendingProduct.duration === duration
    ? pendingProduct.idempotencyKey : '';
  const cryptoApi = globalThis.crypto;
  const generatedKey = cryptoApi && cryptoApi.randomUUID ? cryptoApi.randomUUID().replace(/-/g, '')
    : cryptoApi && cryptoApi.getRandomValues ? Array.from(cryptoApi.getRandomValues(new Uint8Array(16)), x => x.toString(16).padStart(2, '0')).join('')
    : `${Date.now().toString(16)}${Math.random().toString(16).slice(2)}${Math.random().toString(16).slice(2)}`;
  const idempotencyKey = oldKey || generatedKey;
  pendingProduct = { key, duration, idempotencyKey };
  const display = displayPrice(product, duration);
  const inr = product.prices && product.prices[duration];
  $('purchaseProduct').textContent = `${product.name} · ${duration}`;
  $('purchaseDisplayPrice').textContent = currency === 'USD' ? `Catalog display: ${display}` : `Price: ${display}`;
  $('purchaseInrPrice').textContent = `Wallet and payment settlement: ${money(inr, 'INR')}`;
  const numericInr = numberValue(inr) || 0;
  const balance = Number(account.balance_inr || 0);
  $('payWalletButton').disabled = balance + 0.00001 < numericInr;
  $('payWalletButton').textContent = balance + 0.00001 < numericInr ? 'Insufficient wallet balance' : 'Pay from wallet';
  $('walletChoiceBalance').textContent = `Wallet balance: ${money(balance, 'INR')}`;
  $('purchaseChoiceError').textContent = '';
  $('purchaseChoiceModal').classList.remove('hidden');
}
async function payWithWallet() {
  if (!pendingProduct) return;
  const selected = pendingProduct;
  $('payWalletButton').disabled = true;
  $('purchaseChoiceError').textContent = '';
  try {
    const data = await request('/api/store/checkout/wallet', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ product_key: selected.key, duration: selected.duration, idempotency_key: selected.idempotencyKey })
    });
    $('purchaseChoiceModal').classList.add('hidden');
    pendingProduct = null;
    await Promise.all([syncSession(), loadOrders()]);
    if (data.status === 'complete') toast(`Order delivered: ${data.delivery_key || data.order_id}`);
    else toast(`Wallet payment accepted. Delivery status: ${data.status}`, data.status === 'paid_delivery_failed');
  } catch (e) {
    $('purchaseChoiceError').textContent = e.message;
    await loadWallet();
  } finally {
    $('payWalletButton').disabled = false;
  }
}
async function startCheckout(key, duration) {
  try {
    const data = await request('/api/store/checkout', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ product_key: key, duration })
    });
    activePaymentType = 'checkout';
    activeOrder = data.order_id;
    $('paymentDetails').innerHTML = `Order <strong>${escapeHtml(data.order_id)}</strong><br>${escapeHtml(data.product_name || products.find(p => p.key === key)?.name || key)} · ${escapeHtml(duration)}<br>Amount due in INR: <strong>${escapeHtml(money(data.amount_inr, 'INR'))}</strong>`;
    $('paymentLink').href = data.payment_url || '#';
    $('paymentLink').classList.toggle('hidden', !data.payment_url);
    $('paymentQr').src = data.qr_url;
    $('paymentQr').style.display = 'block';
    $('paymentStatus').textContent = 'Waiting for payment confirmation…';
    $('paymentModal').classList.remove('hidden');
    clearInterval(pollTimer);
    await pollStatus();
    pollTimer = setInterval(pollStatus, 6000);
  } catch (e) {
    toast(e.message, true);
  }
}
async function startTopup(event) {
  event.preventDefault();
  $('topupError').textContent = '';
  const amount = Number($('topupAmount').value);
  if (!Number.isFinite(amount) || amount < 50 || amount > 50000) {
    $('topupError').textContent = 'Top-ups must be between ₹50 and ₹50,000.';
    return;
  }
  $('topupButton').disabled = true;
  try {
    const data = await request('/api/store/wallet/topups', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount_inr: amount })
    });
    activePaymentType = 'wallet';
    activeOrder = data.order_id;
    $('paymentDetails').innerHTML = `Wallet top-up <strong>${escapeHtml(data.order_id)}</strong><br>Amount due in INR: <strong>${escapeHtml(money(data.amount_inr, 'INR'))}</strong>`;
    $('paymentLink').href = data.payment_url || '#';
    $('paymentLink').classList.toggle('hidden', !data.payment_url);
    $('paymentQr').src = data.qr_url;
    $('paymentQr').style.display = 'block';
    $('paymentStatus').textContent = 'Waiting for payment confirmation…';
    $('paymentModal').classList.remove('hidden');
    clearInterval(pollTimer);
    await pollStatus();
    pollTimer = setInterval(pollStatus, 6000);
  } catch (e) {
    $('topupError').textContent = e.message;
  } finally {
    $('topupButton').disabled = false;
  }
}
async function pollStatus() {
  if (!activeOrder) return;
  try {
    const path = activePaymentType === 'wallet'
      ? `/api/store/wallet/topups/${encodeURIComponent(activeOrder)}`
      : `/api/store/checkouts/${encodeURIComponent(activeOrder)}`;
    const data = await request(path);
    const status = data.status;
    if (activePaymentType === 'wallet') {
      $('paymentStatus').textContent = status === 'complete' ? 'Payment verified — your wallet has been credited.'
        : status === 'failed' ? 'Payment was not completed.'
        : status === 'amount_review' ? 'Payment received; the amount is being reviewed.'
        : 'Waiting for payment confirmation…';
      if (status === 'complete') {
        clearInterval(pollTimer);
        account.balance_inr = Number(data.balance_inr || 0);
        await Promise.all([loadWallet(), syncSession()]);
        toast(`Wallet credited by ${money(data.amount_inr, 'INR')}.`);
      } else if (['failed', 'amount_review'].includes(status)) clearInterval(pollTimer);
      return;
    }
    $('paymentStatus').textContent = status === 'complete' ? 'Payment verified — your order key is shown below.'
      : status === 'failed' ? 'Payment was not completed. You can start a new checkout.'
      : status === 'paid_delivery_failed' ? 'Payment received; delivery needs administrator review.'
      : status === 'amount_review' ? 'Payment received; amount is being reviewed.'
      : 'Waiting for payment confirmation…';
    if (status === 'complete') {
      clearInterval(pollTimer);
      $('paymentDetails').innerHTML = `Order <strong>${escapeHtml(data.order_id)}</strong><br>${escapeHtml(data.product_name)} · ${escapeHtml(data.duration)}<br>Delivery key: <strong>${escapeHtml(data.delivery_key)}</strong>`;
      await loadOrders();
      toast('Payment confirmed. Your order is ready.');
    } else if (['failed', 'paid_delivery_failed', 'amount_review'].includes(status)) clearInterval(pollTimer);
  } catch {
    $('paymentStatus').textContent = 'Could not check status right now. Try again shortly.';
  }
}
async function saveProfile(event) {
  event.preventDefault();
  $('profileError').textContent = '';
  try {
    const data = await request('/api/store/profile', {
      method: 'PUT', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ display_name: $('profileDisplayName').value.trim() })
    });
    account = { ...account, ...data.account };
    updateAccountDisplay();
    toast('Profile updated.');
  } catch (e) {
    $('profileError').textContent = e.message;
  }
}
function setCurrency(value) {
  currency = value === 'USD' ? 'USD' : 'INR';
  localStorage.setItem('hex_shop_currency', currency);
  $('currencySelect').value = currency;
  renderProducts();
  $('currencyNote').textContent = currency === 'USD'
    ? 'USD prices are shown only where set by the shop. Wallet balances and all payments settle in INR.'
    : 'Wallet balances and all payments settle in INR.';
}

$('authOpen').addEventListener('click', openAuth);
$('heroAccount').addEventListener('click', openAuth);
$('loginTab').addEventListener('click', () => setAuthMode('login'));
$('registerTab').addEventListener('click', () => setAuthMode('register'));
$('authForm').addEventListener('submit', authSubmit);
$('search').addEventListener('input', renderProducts);
$('currencySelect').addEventListener('change', event => setCurrency(event.target.value));
$('checkPayment').addEventListener('click', pollStatus);
$('profileForm').addEventListener('submit', saveProfile);
$('topupForm').addEventListener('submit', startTopup);
$('payWalletButton').addEventListener('click', payWithWallet);
$('payOnlineButton').addEventListener('click', () => {
  if (!pendingProduct) return;
  const selected = pendingProduct;
  $('purchaseChoiceModal').classList.add('hidden');
  pendingProduct = null;
  startCheckout(selected.key, selected.duration);
});
$('logoutButton').addEventListener('click', async () => {
  try { await request('/api/store/logout', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }); } catch {}
  account = null;
  csrfToken = '';
  $('accountPanel').classList.remove('show');
  $('navBalance').classList.add('hidden');
  $('authOpen').textContent = 'Sign in';
  toast('Signed out.');
});
document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => $(button.dataset.close).classList.add('hidden')));
document.querySelectorAll('.modal').forEach(modal => modal.addEventListener('click', event => {
  if (event.target === modal) modal.classList.add('hidden');
}));
$('year').textContent = new Date().getFullYear();
setCurrency(currency);
loadProducts();
syncSession();
