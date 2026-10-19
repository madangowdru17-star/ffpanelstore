(() => {
'use strict';
window.__hexStorefrontCleanup?.();
let pageActive = true;

const $ = id => document.getElementById(id);
const idemMemory = new Map();
function requestIdempotency(scope, fingerprint) {
  const storageKey = `hex_payment_${scope}`;
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    if (saved?.fingerprint === fingerprint && /^[A-Za-z0-9_-]{16,64}$/.test(saved.key || '')) return saved.key;
  } catch {
    const saved = idemMemory.get(storageKey);
    if (saved?.fingerprint === fingerprint) return saved.key;
  }
  const cryptoApi = globalThis.crypto;
  const key = cryptoApi?.randomUUID ? cryptoApi.randomUUID().replace(/-/g, '')
    : cryptoApi?.getRandomValues ? Array.from(cryptoApi.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('')
    : `${Date.now().toString(16)}${Math.random().toString(16).slice(2)}${Math.random().toString(16).slice(2)}`;
  const value = { fingerprint, key };
  idemMemory.set(storageKey, value);
  try { sessionStorage.setItem(storageKey, JSON.stringify(value)); } catch {}
  return key;
}
function clearRequestIdempotency(scope) {
  idemMemory.delete(`hex_payment_${scope}`);
  try { sessionStorage.removeItem(`hex_payment_${scope}`); } catch {}
}
const storedCurrency = () => {
  try { return localStorage.getItem('hex_shop_currency') === 'USD' ? 'USD' : 'INR'; }
  catch { return 'INR'; }
};
let products = [];
let account = null;
let csrfToken = '';
let authMode = 'login';
let activeOrder = '';
let activePaymentType = 'checkout';
let pollTimer = null;
let pendingProduct = null;
let homeConfig = {};
let selectedStoreFilter = 'all';
let ordersCache = [];
let paymentHistoryCache = [];
let activeSlide = 0;
let carouselTimer = null;
let detailProduct = null;
let currency = storedCurrency();

const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[ch]));
const numberValue = value => {
  const cleaned = String(value ?? '').replace(/[^0-9.-]/g, '');
  if (!cleaned) return null;
  const n = Number(cleaned);
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
function openAuth() {
  if (account) { location.assign('/account'); return; }
  const googleLink = $('googleAuthLink');
  if (googleLink) {
    const next = pendingProduct ? `/product/${encodeURIComponent(pendingProduct.key)}` : '/account';
    googleLink.href = `/auth/google?next=${encodeURIComponent(next)}`;
  }
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
function renderFilterGroups() {
  document.querySelectorAll('[data-store-filter]').forEach(button => {
    const active = button.dataset.storeFilter === selectedStoreFilter;
    button.classList.toggle('active', active);
    button.setAttribute('aria-pressed', String(active));
  });
}
function platformBadge(product) {
  const access = String(product.access_mode || '').trim().toLowerCase().replace(/[- ]/g, '_');
  const searchable = [product.platform, product.category, product.name].filter(Boolean).join(' ').toLowerCase();
  let mark = '', label = '';
  if (/ios/.test(searchable)) { mark = 'apple-logo.svg'; label = 'iOS'; }
  else if (access === 'root') { mark = 'kitsune-mask-source.png'; label = 'Root'; }
  else if (access === 'non_root') { mark = 'android-robot.svg'; label = 'Non-root'; }
  else if (/android/.test(searchable)) { mark = 'android-robot.svg'; label = 'Android'; }
  if (!mark) return '';
  return `<span class="product-platform-badge" role="img" aria-label="${label} platform" title="${label}"><img class="hx-platform-mark platform-mark-${mark.startsWith('apple') ? 'apple' : mark.startsWith('kitsune') ? 'kitsune' : 'android'}" src="/assets/brands/${mark}" alt="" aria-hidden="true"></span>`;
}
function renderProducts() {
  const query = $('search').value.trim().toLowerCase();
  const shown = products.filter(p => {
    const searchable = [p.name,p.category,p.platform,p.access_mode,p.description,...(p.features||[])].join(' ').toLowerCase();
    const access = String(p.access_mode || 'all').toLowerCase();
    const isIos = [p.platform, p.category, p.name].some(value => /ios/i.test(String(value || '')));
    const modeMatches = selectedStoreFilter === 'all'
      || (selectedStoreFilter === 'ios' && isIos)
      || (selectedStoreFilter === 'root' && (access === 'root' || access === 'all'))
      || (selectedStoreFilter === 'non_root' && (access === 'non_root' || access === 'all'));
    return searchable.includes(query) && modeMatches;
  });
  $('productCount').textContent = String(shown.length);
  if (!shown.length) { $('catalogGrid').innerHTML = '<div class="empty">No products match your search and filters.</div>'; return; }
  $('catalogGrid').innerHTML = shown.map(p => {
    const initials = escapeHtml(p.name.split(/\s+/).slice(0,2).map(x=>x[0]).join('').toUpperCase());
    const firstDuration = (p.durations||[])[0];
    const firstPrice = firstDuration ? displayPrice(p,firstDuration) : 'Price unavailable';
    const sold = Number(p.sold_count||0);
    const detailUrl=`/product/${encodeURIComponent(p.key)}`;
    const maintenance=Boolean(p.maintenance);
    const platform=platformBadge(p);
    const firstStock=firstDuration!==undefined&&p.stock&&p.stock[firstDuration]!==undefined?numberValue(p.stock[firstDuration]):null;
    const outOfStock=firstStock!==null&&firstStock<=0;
    return `<article class="product reference-product"><button class="product-image product-detail-trigger" type="button" data-detail-open="${escapeHtml(p.key)}" aria-label="View ${escapeHtml(p.name)} details" draggable="false"><img src="${escapeHtml(p.image_url||'')}" alt="${escapeHtml(p.name)}" loading="lazy" draggable="false" onload="if(this.naturalWidth)this.nextElementSibling.style.display='none'" onerror="this.remove()"><div class="fallback" aria-hidden="true">${initials}</div></button><div class="product-body"><button class="reference-product-title" type="button" data-detail-open="${escapeHtml(p.key)}" title="${escapeHtml(p.name)}">${escapeHtml(p.name)}</button>${platform}<div class="reference-product-meta">${sold>0?`${sold.toLocaleString()} sold`:'Digital access'}</div><span class="reference-price">${escapeHtml(firstPrice)}</span><div class="reference-card-actions"><button class="button buy-button ${maintenance?'is-maintenance':''}" type="button" aria-label="${maintenance?'Product under maintenance':outOfStock?'Product out of stock':'Buy now'}" data-detail-open="${escapeHtml(p.key)}" ${maintenance||outOfStock?'disabled':''}>${maintenance?'<svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#wrench"></use></svg><span>MAINT.</span>':outOfStock?'SOLD OUT':'<svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#cart"></use></svg><span>BUY</span>'}</button></div></div></article>`;
  }).join('');
}
function openProduct(key) {
  const p=products.find(item=>item.key===key);
  if(p)location.href=`/product/${encodeURIComponent(p.key)}`;
}
async function loadHomepage() {
  try {
    const data=await request('/api/store/config');homeConfig=data;
    if (!pageActive) return;
    const shopName=String(data.shop_name||'HEX CHEATS SHOP');
    if($('shopName')?.firstChild)$('shopName').firstChild.textContent=shopName+' ';
    $('footerShopName').textContent=shopName;
    $('heroTitle').textContent=data.hero_title||'Browse the latest products';
    $('heroSubtitle').textContent=data.hero_subtitle||'';
    $('announcement').textContent=data.announcement||'';
    document.title=`${shopName} | Digital products`;
    const binanceReady = Boolean(data.binance?.available);
    $('binanceTopupButton').disabled = !binanceReady;
    $('binanceAvailability').textContent = binanceReady
      ? 'Binance Pay is available. Payments require admin verification before INR wallet credit.'
      : 'Binance Pay will appear here after the shop administrator uploads a Binance QR image.';
    const fallback=[
      ['Featured line-up','Explore products for your platform and choose the access period that works for you.'],
      ['Choose your access','Compare product details, demo videos, download links, and durations before checkout.'],
      ['Secure checkout','Track payments, wallet balance, and delivery keys together in your shop profile.']
    ];
    const banners=(data.banners||[]).slice(0,3);
    while(banners.length<3)banners.push({slot:banners.length+1,title:'',subtitle:'',cta_label:'Browse products',cta_url:'#catalog',image_url:''});
    $('heroSlides').innerHTML=banners.map((b,i)=>{
      const title=b.title||fallback[i][0],subtitle=b.subtitle||fallback[i][1],url=b.cta_url||'#catalog';
      const external=String(url).startsWith('https://');
      return `<article class="slide ${i===0?'active':''}">${b.image_url?`<img class="slide-image" src="${escapeHtml(b.image_url)}" alt="" onerror="this.remove()">`:''}<div class="slide-shade"></div><div class="slide-content"><div class="slide-kicker">HEX CHEATS SHOP · PROMOTION ${i+1}</div><h2>${escapeHtml(title)}</h2><p>${escapeHtml(subtitle)}</p><a class="button" href="${escapeHtml(url)}" ${external?'target="_blank" rel="noopener noreferrer"':''}>${escapeHtml(b.cta_label||'Browse products')}</a></div></article>`;
    }).join('');
    $('heroDots').innerHTML=banners.map((_,i)=>`<button class="dot ${i===0?'active':''}" type="button" aria-label="Show banner ${i+1}" data-slide="${i}"></button>`).join('');
    activeSlide=0;clearInterval(carouselTimer);carouselTimer=setInterval(()=>goSlide((activeSlide+1)%3),6500);
  } catch { console.warn('Homepage settings could not be loaded.'); }
}
function goSlide(index) {
  const slides=[...document.querySelectorAll('#heroSlides .slide')],dots=[...document.querySelectorAll('#heroDots .dot')];
  if(!slides.length)return;activeSlide=(index+slides.length)%slides.length;
  slides.forEach((el,i)=>el.classList.toggle('active',i===activeSlide));dots.forEach((el,i)=>el.classList.toggle('active',i===activeSlide));
}
async function loadProducts() {
  try {
    const data = await request('/api/store/products');
    if (!pageActive) return;
    products = data.products || [];
    renderFilterGroups();
    renderProducts();
  } catch (e) {
    $('catalogGrid').innerHTML = `<div class="empty">${escapeHtml(e.message)}<br><small>Refresh to try again.</small></div>`;
  }
}
async function syncSession() {
  try {
    const data = await request('/api/store/session');
    if (data.authenticated) {
      account = data.account;
      csrfToken = data.csrf_token || '';
    } else {
      account = null;
      csrfToken = '';
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
    paymentHistoryCache = data.payment_history || [];
    renderPaymentHistory();
    const entries = data.transactions || [];
    const transactionList = $('walletTransactions');
    if (!transactionList) return;
    transactionList.innerHTML = entries.length ? entries.map(t => {
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
    ordersCache = data.orders || [];
    renderKeyHistory();
    renderPaymentHistory();
  } catch {}
}
function renderPaymentHistory() {
  if (!$('paymentHistoryList')) return;
  const topups = paymentHistoryCache.map(t => ({
    type: t.payment_method === 'binance' ? 'Binance Pay top-up' : 'UPI wallet top-up',
    reference: t.order_id, amount: Number(t.amount_inr || 0), usd: Number(t.amount_usd || 0),
    status: t.status, date: Number(t.created_at || 0), txid: t.proof_txid || t.transaction_id || '', note: t.review_note || ''
  }));
  const orders = ordersCache.map(o => ({
    type: o.transaction_id === 'WALLET' ? 'Wallet product purchase' : 'UPI product purchase',
    reference: o.order_id, amount: Number(o.amount || 0), usd: 0, status: o.status,
    date: Number(o.created_at || 0), txid: o.transaction_id === 'WALLET' ? '' : (o.transaction_id || '')
  }));
  const rows = [...topups, ...orders].sort((a,b) => b.date-a.date);
  $('paymentHistoryList').innerHTML = rows.length ? rows.map(x => `<div class="history-row"><div><strong>${escapeHtml(x.type)}</strong><small>${escapeHtml(x.reference)} · ${x.date ? escapeHtml(new Date(x.date*1000).toLocaleString()) : '—'}</small>${x.txid?`<small>Transaction: ${escapeHtml(x.txid)}</small>`:''}${x.note?`<small>Review note: ${escapeHtml(x.note)}</small>`:''}</div><div>${escapeHtml(money(x.amount,'INR'))}${x.usd>0?`<small>Paid amount: ${escapeHtml(money(x.usd,'USD'))}</small>`:''}</div><div class="history-status">${escapeHtml(x.status)}</div></div>`).join('') : '<div class="empty">Payment history will appear here.</div>';
}
function renderKeyHistory() {
  if (!$('keyHistoryList')) return;
  $('keyHistoryList').innerHTML = ordersCache.length ? ordersCache.map(o => `<div class="history-row"><div><strong>${escapeHtml(o.product_name)}</strong><small>${escapeHtml(o.duration)} · ${escapeHtml(o.order_id)}</small><small>${o.created_at?escapeHtml(new Date(Number(o.created_at)*1000).toLocaleString()):'—'}</small></div><div>${escapeHtml(money(o.amount,'INR'))}</div><div class="history-status">${escapeHtml(o.status)}</div><div class="history-key">${o.delivery_key?escapeHtml(o.delivery_key):'<span class="muted">Key pending or under review.</span>'}</div></div>`).join('') : '<div class="empty">Your purchase keys will appear here after you place an order.</div>';
}
function setProfileTab(tab) {
  const panes = { profile: 'profilePane', payments: 'paymentsPane', keys: 'keysPane' };
  document.querySelectorAll('[data-profile-tab]').forEach(button => button.classList.toggle('active', button.dataset.profileTab === tab));
  Object.entries(panes).forEach(([key,id]) => $(id)?.classList.toggle('active', key === tab));
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
  const idempotencyKey = requestIdempotency('product-checkout', `${key}|${duration}`);
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
      body: JSON.stringify({ product_key: selected.key, duration: selected.duration, idempotency_key: `${selected.idempotencyKey}_wallet` })
    });
    $('purchaseChoiceModal').classList.add('hidden');
    pendingProduct = null;
    clearRequestIdempotency('product-checkout');
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
async function startCryptoProductCheckout(method) {
  if (!pendingProduct) return;
  const selected = pendingProduct;
  const button = $(method === 'binance' ? 'payBinanceCheckoutButton' : 'payUsdtCheckoutButton');
  button.disabled = true;
  $('purchaseChoiceError').textContent = '';
  try {
    const data = await request('/api/store/checkout/crypto', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ product_key: selected.key, duration: selected.duration, payment_method: method, idempotency_key: `${selected.idempotencyKey}_${method}` })
    });
    $('purchaseChoiceModal').classList.add('hidden');
    pendingProduct = null;
    clearRequestIdempotency('product-checkout');
    activePaymentType = 'crypto_checkout';
    activeOrder = data.order_id;
    const label = method === 'usdt_bep20' ? 'USDT · BNB Smart Chain (BEP20)' : 'Binance Pay · USD';
    const unit = method === 'usdt_bep20' ? 'USDT' : 'USD';
    $('paymentSubtitle').textContent = 'Send the exact crypto amount. Submit the transaction ID and screenshot; order delivery starts only after admin verification.';
    $('paymentDetails').innerHTML = `${escapeHtml(data.product_name)} · ${escapeHtml(data.duration)}<br>${escapeHtml(label)} order <strong>${escapeHtml(data.order_id)}</strong><br>Send exactly <strong>${Number(data.amount_usd).toFixed(2)} ${unit}</strong><br>Product total: ${escapeHtml(money(data.amount_inr, 'INR'))}<br>Locked quote: 1 USD = ₹${Number(data.exchange_rate_inr).toFixed(4)}`;
    $('paymentLink').classList.add('hidden');
    $('paymentQr').src = data.qr_url;
    $('paymentQr').style.display = 'block';
    $('binanceInstructions').textContent = (method === 'usdt_bep20' ? 'NETWORK: BNB Smart Chain (BEP20) only. Do not send using another network. ' : '') + (data.instructions || 'Pay the exact amount shown, then submit your transaction ID and screenshot for admin verification.');
    $('binanceProofForm').reset();
    $('binanceTxid').disabled = false;
    $('binanceProof').disabled = false;
    $('submitBinanceProof').disabled = false;
    $('binanceProofArea').classList.remove('hidden');
    $('paymentStatus').textContent = data.status === 'proof_submitted' ? 'Proof already submitted. Waiting for admin review.' : 'After paying, submit your transaction ID and screenshot.';
    if (data.status === 'proof_submitted') $('binanceProofForm').classList.add('hidden');
    else $('binanceProofForm').classList.remove('hidden');
    $('paymentModal').classList.remove('hidden');
    clearInterval(pollTimer);
    await pollStatus();
    pollTimer = setInterval(pollStatus, 8000);
    await loadOrders();
  } catch (error) {
    $('purchaseChoiceError').textContent = error.message;
  } finally { button.disabled = false; }
}

async function startCheckout(key, duration, idempotencyKey) {
  try {
    const data = await request('/api/store/checkout', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ product_key: key, duration, idempotency_key: `${idempotencyKey}_upi` })
    });
    clearRequestIdempotency('product-checkout');
    pendingProduct = null;
    activePaymentType = 'checkout';
    activeOrder = data.order_id;
    $('paymentSubtitle').textContent = 'Complete the UPI checkout. Your order will be fulfilled after payment is verified.';
    $('binanceProofArea').classList.add('hidden');
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
      body: JSON.stringify({ amount_inr: amount, idempotency_key: requestIdempotency('wallet-upi-topup', String(amount)) })
    });
    clearRequestIdempotency('wallet-upi-topup');
    activePaymentType = 'wallet';
    activeOrder = data.order_id;
    $('paymentSubtitle').textContent = 'Complete the UPI checkout. Your wallet is credited after payment is verified.';
    $('binanceProofArea').classList.add('hidden');
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
async function startBinanceTopup(event) {
  event.preventDefault();
  $('topupError').textContent = '';
  const amount = Number($('binanceAmount').value);
  if (!Number.isFinite(amount) || amount < 0.52 || amount > 100) {
    $('topupError').textContent = 'Binance top-ups must be between $0.52 and $100.00.';
    return;
  }
  const button = $('binanceTopupButton');
  button.disabled = true;
  try {
    const data = await request('/api/store/wallet/binance', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount_usd: amount, idempotency_key: requestIdempotency('wallet-binance-topup', String(amount)) })
    });
    clearRequestIdempotency('wallet-binance-topup');
    activePaymentType = 'binance';
    activeOrder = data.order_id;
    $('paymentSubtitle').textContent = 'Pay the exact USD amount, then submit the transaction ID and screenshot for manual verification.';
    $('paymentDetails').innerHTML = `Binance Pay top-up <strong>${escapeHtml(data.order_id)}</strong><br>Send: <strong>${escapeHtml(money(data.amount_usd,'USD'))}</strong><br>Wallet credit after approval: <strong>${escapeHtml(money(data.amount_inr,'INR'))}</strong><br>Rate locked for this request: 1 USD = ₹${Number(data.exchange_rate_inr).toFixed(4)}`;
    $('paymentLink').classList.add('hidden');
    $('paymentQr').src = data.qr_url;
    $('paymentQr').style.display = 'block';
    $('binanceInstructions').textContent = data.instructions || 'Pay the exact USD amount using Binance Pay.';
    $('binanceProofArea').classList.remove('hidden');
    $('binanceTxid').value = '';
    $('binanceProof').value = '';
    $('submitBinanceProof').disabled = false;
    $('paymentStatus').textContent = 'After paying, submit your transaction ID and screenshot. Wallet credit requires admin verification.';
    $('paymentModal').classList.remove('hidden');
    clearInterval(pollTimer);
    pollTimer = setInterval(pollStatus, 8000);
  } catch (e) {
    $('topupError').textContent = e.message;
  } finally {
    button.disabled = false;
  }
}
async function submitBinanceProof(event) {
  event.preventDefault();
  if (!activeOrder || !['binance', 'crypto_checkout'].includes(activePaymentType)) return;
  const file = $('binanceProof').files?.[0];
  if (!file) { $('paymentStatus').textContent = 'Choose the payment screenshot first.'; return; }
  if (file.size > 5 * 1024 * 1024) { $('paymentStatus').textContent = 'Screenshot must be 5 MB or smaller.'; return; }
  const form = new FormData();
  form.append('txid', $('binanceTxid').value.trim());
  form.append('proof', file, file.name);
  $('submitBinanceProof').disabled = true;
  try {
    const proofPath = activePaymentType === 'crypto_checkout'
      ? `/api/store/checkouts/${encodeURIComponent(activeOrder)}/proof`
      : `/api/store/wallet/topups/${encodeURIComponent(activeOrder)}/proof`;
    await request(proofPath, { method: 'POST', body: form });
    showProofReceiptAndOpenHistory();
  } catch (e) {
    $('paymentStatus').textContent = e.message;
    $('submitBinanceProof').disabled = false;
  }
}
function showProofReceiptAndOpenHistory() {
  const overlay = document.createElement('div');
  overlay.className = 'hx-proof-success-overlay';
  overlay.setAttribute('role', 'status');
  overlay.setAttribute('aria-live', 'assertive');
  overlay.innerHTML = '<section class="hx-proof-success-card"><span class="hx-proof-success-mark"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#check"></use></svg></span><strong>Proof uploaded successfully</strong><p>Payment is awaiting admin verification. Opening Order History…</p></section>';
  document.body.append(overlay);
  window.setTimeout(() => {
    overlay.remove();
    const target = '/orders?proof=received';
    if (window.hxGo) window.hxGo(target); else location.assign(target);
  }, 1000);
}
async function pollStatus() {
  if (!activeOrder) return;
  try {
    const path = ['wallet','binance'].includes(activePaymentType)
      ? `/api/store/wallet/topups/${encodeURIComponent(activeOrder)}/verify`
      : `/api/store/checkouts/${encodeURIComponent(activeOrder)}/verify`;
    const data = await request(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
    const status = data.status;
    if (activePaymentType === 'binance') {
      $('paymentStatus').textContent = status === 'manual_pending' ? 'Payment request created. Pay the exact USD amount and submit your screenshot and transaction ID.'
        : status === 'proof_submitted' ? 'Proof submitted. Waiting for the administrator to verify the transfer.'
        : status === 'approved' ? 'Payment verified. Your INR wallet has been credited.'
        : status === 'rejected' ? `This payment proof was rejected.${data.review_note?` ${String(data.review_note)}`:' Contact shop support if you believe this is an error.'}`
        : 'Checking Binance payment review status…';
      if (status === 'approved') {
        clearInterval(pollTimer);
        account.balance_inr = Number(data.balance_inr || 0);
        await Promise.all([loadWallet(), syncSession()]);
        toast(`Binance top-up approved: ${money(data.amount_inr,'INR')} credited.`);
      } else if (status === 'rejected') {
        clearInterval(pollTimer);
        await loadWallet();
      }
      return;
    }
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
    if (activePaymentType === 'crypto_checkout' && ['manual_pending', 'proof_submitted', 'rejected'].includes(status)) {
      $('paymentStatus').textContent = status === 'manual_pending' ? 'Crypto order created. Pay the exact amount and submit the transaction ID and screenshot.'
        : status === 'proof_submitted' ? 'Proof submitted. Waiting for an administrator to verify the crypto payment before product delivery.'
        : `Crypto payment proof rejected.${data.review_note ? ` ${String(data.review_note)}` : ' Please contact shop support if you believe this is an error.'}`;
      if (status === 'rejected') clearInterval(pollTimer);
      return;
    }
    $('paymentStatus').textContent = status === 'complete' ? 'Payment verified — your order key is shown below.'
      : status === 'failed' ? 'Payment was not completed. You can start a new checkout.'
      : status === 'paid_delivery_failed' ? 'Payment received; delivery needs administrator review.'
      : status === 'delivery_unknown' ? 'Payment received; supplier delivery is being checked. Please do not place a duplicate order.'
      : status === 'amount_review' ? 'Payment received; amount is being reviewed.'
      : 'Waiting for payment confirmation…';
    if (status === 'complete') {
      clearInterval(pollTimer);
      $('paymentDetails').innerHTML = `Order <strong>${escapeHtml(data.order_id)}</strong><br>${escapeHtml(data.product_name)} · ${escapeHtml(data.duration)}<br>Delivery key: <strong>${escapeHtml(data.delivery_key)}</strong>`;
      await loadOrders();
      toast('Payment confirmed. Your order is ready.');
    } else if (['failed', 'paid_delivery_failed', 'delivery_unknown', 'amount_review', 'rejected'].includes(status)) clearInterval(pollTimer);
  } catch {
    $('paymentStatus').textContent = 'Could not check status right now. Try again shortly.';
  }
}
function setCurrency(value) {
  currency = value === 'USD' ? 'USD' : 'INR';
  try { localStorage.setItem('hex_shop_currency', currency); } catch {}
  $('currencySelect').value = currency;
  renderProducts();
  if(detailProduct && !$('productModal').classList.contains('hidden'))openProduct(detailProduct.key);
  $('currencyNote').textContent = currency === 'USD'
    ? 'USD prices are shown only where set by the shop. Wallet balances and all payments settle in INR.'
    : 'Wallet balances and all payments settle in INR.';
}

$('loginTab').addEventListener('click', () => setAuthMode('login'));
$('registerTab').addEventListener('click', () => setAuthMode('register'));
$('authForm').addEventListener('submit', authSubmit);
$('search').addEventListener('input', renderProducts);
$('currencySelect').addEventListener('change', event => setCurrency(event.target.value));
$('heroPrev').addEventListener('click',()=>{clearInterval(carouselTimer);goSlide(activeSlide-1);carouselTimer=setInterval(()=>goSlide((activeSlide+1)%3),6500)});
$('heroNext').addEventListener('click',()=>{clearInterval(carouselTimer);goSlide(activeSlide+1);carouselTimer=setInterval(()=>goSlide((activeSlide+1)%3),6500)});
const onStoreClick=event=>{
  const filter=event.target.closest('[data-store-filter]');
  if(filter){selectedStoreFilter=filter.dataset.storeFilter;filter.classList.remove('tab-tap');void filter.offsetWidth;filter.classList.add('tab-tap');$('catalogGrid').classList.add('is-switching');renderFilterGroups();renderProducts();setTimeout(()=>$('catalogGrid')?.classList.remove('is-switching'),420);return}
  const dot=event.target.closest('[data-slide]');if(dot){clearInterval(carouselTimer);goSlide(Number(dot.dataset.slide));carouselTimer=setInterval(()=>goSlide((activeSlide+1)%3),6500);return}
  const card=event.target.closest('[data-open-product]');if(card)openProduct(card.dataset.openProduct);
  const direct=event.target.closest('[data-detail-open]');if(direct){event.preventDefault();openProduct(direct.dataset.detailOpen);return}
};
document.addEventListener('click',onStoreClick);
const catalogGrid = $('catalogGrid');
const preventProductCallout = event => {
  if (event.target.closest('.product-image,.reference-product-title')) event.preventDefault();
};
catalogGrid?.addEventListener('contextmenu', preventProductCallout);
$('checkPayment').addEventListener('click', pollStatus);
$('binanceProofForm').addEventListener('submit', submitBinanceProof);
$('payWalletButton').addEventListener('click', payWithWallet);
$('payOnlineButton').addEventListener('click', () => {
  if (!pendingProduct) return;
  const selected = pendingProduct;
  $('purchaseChoiceModal').classList.add('hidden');
  startCheckout(selected.key, selected.duration, selected.idempotencyKey);
});
$('payBinanceCheckoutButton').addEventListener('click', () => startCryptoProductCheckout('binance'));
$('payUsdtCheckoutButton').addEventListener('click', () => startCryptoProductCheckout('usdt_bep20'));
document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => $(button.dataset.close).classList.add('hidden')));
document.querySelectorAll('.modal').forEach(modal => modal.addEventListener('click', event => {
  if (event.target === modal) modal.classList.add('hidden');
}));
$('year').textContent = new Date().getFullYear();
setCurrency(currency);
loadHomepage();
loadProducts();
syncSession();
window.__hexStorefrontCleanup=()=>{pageActive=false;document.removeEventListener('click',onStoreClick);catalogGrid?.removeEventListener('contextmenu',preventProductCallout);clearInterval(carouselTimer);clearInterval(pollTimer)};
})();
