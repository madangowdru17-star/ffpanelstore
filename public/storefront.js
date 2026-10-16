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
let homeConfig = {};
let selectedStoreFilter = 'all';
let ordersCache = [];
let paymentHistoryCache = [];
let activeSlide = 0;
let carouselTimer = null;
let detailProduct = null;
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
  setProfileTab('profile');
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
function renderFilterGroups() {
  document.querySelectorAll('[data-store-filter]').forEach(button => {
    button.classList.toggle('active', button.dataset.storeFilter === selectedStoreFilter);
  });
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
    const access = p.access_mode==='root'?'Root':p.access_mode==='non_root'?'Non-root':'Root + non-root';
    const sold = Number(p.sold_count||0);
    return `<article class="product"><div class="product-image" data-open-product="${escapeHtml(p.key)}"><img src="${escapeHtml(p.image_url||'')}" alt="${escapeHtml(p.name)}" loading="lazy" onload="if(this.naturalWidth)this.nextElementSibling.style.display='none'" onerror="this.remove()"><div class="fallback" aria-hidden="true">${initials}</div><div class="tag-row"><span class="product-tag">${escapeHtml(p.platform||p.category||'Digital')}</span><span class="product-tag">${escapeHtml(access)}</span></div></div><div class="product-body"><div class="product-title"><h3>${escapeHtml(p.name)}</h3><span class="category">${(p.durations||[]).length} options</span></div><div class="product-meta"><span>${escapeHtml(p.category||'Digital access')}</span>${sold>0?`<span>▣ ${sold.toLocaleString()} sold</span>`:''}</div><div class="features">${(p.features||[]).slice(0,2).map(f=>`<span class="feature">${escapeHtml(f)}</span>`).join('')}</div><div class="card-bottom"><span class="price">${escapeHtml(firstPrice)}</span><button class="button view-button" type="button" data-open-product="${escapeHtml(p.key)}">View details</button></div></div></article>`;
  }).join('');
}
function openProduct(key) {
  const p=products.find(item=>item.key===key);if(!p)return;detailProduct=p;
  const durations=(p.durations||[]).map(d=>`<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join('');
  const image=p.image_url?`<img class="product-detail-image" src="${escapeHtml(p.image_url)}" alt="${escapeHtml(p.name)}" onerror="this.remove()">`:'';
  const tags=[p.platform,p.access_mode==='root'?'Root':p.access_mode==='non_root'?'Non-root':'Root + non-root',p.category].filter(Boolean).map(x=>`<span class="product-tag">${escapeHtml(x)}</span>`).join('');
  const links=`<div class="detail-actions">${p.demo_url?`<a class="button secondary" href="${escapeHtml(p.demo_url)}" target="_blank" rel="noopener noreferrer">Watch demo video</a>`:''}${p.download_url?`<a class="button secondary" href="${escapeHtml(p.download_url)}" target="_blank" rel="noopener noreferrer">Open product download</a>`:''}</div>`;
  $('detailTitle').textContent=p.name;
  $('productDetailBody').innerHTML=`${image}<div class="detail-tags">${tags}</div><p class="detail-copy">${escapeHtml(p.description||'Choose a duration below to see the available price and checkout options.')}</p><div class="features">${(p.features||[]).map(f=>`<span class="feature">${escapeHtml(f)}</span>`).join('')}</div>${links}<label class="field"><span class="hint">Choose duration</span><select class="detail-duration" id="detailDuration">${durations||'<option value="">No durations available</option>'}</select></label><div class="detail-price" id="detailPrice"></div><div class="hint">Final payment and wallet settlement are in INR. USD is a display option when that price is configured.</div><button class="button wide" id="detailBuy" type="button" ${durations?'':'disabled'}>Continue to checkout</button>`;
  const update=()=>{const d=$('detailDuration')?.value;const inr=p.prices?.[d];$('detailPrice').textContent=`${displayPrice(p,d)}${currency==='USD'&&p.usd_prices?.[d]?` · ${money(inr,'INR')} charged`:''}`;};
  $('detailDuration')?.addEventListener('change',update);update();
  $('detailBuy')?.addEventListener('click',()=>{const d=$('detailDuration')?.value;if(d){$('productModal').classList.add('hidden');buyProduct(p.key,'detailDuration')}});
  $('productModal').classList.remove('hidden');
}
async function loadHomepage() {
  try {
    const data=await request('/api/store/config');homeConfig=data;
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
    products = data.products || [];
    renderFilterGroups();
    renderProducts();
  } catch (e) {
    $('catalogGrid').innerHTML = `<div class="empty">${escapeHtml(e.message)}<br><small>Refresh to try again.</small></div>`;
  }
}
function updateAccountDisplay() {
  if (!account) return;
  $('authOpen').textContent = 'Profile';
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
    paymentHistoryCache = data.payment_history || [];
    renderPaymentHistory();
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
      body: JSON.stringify({ amount_inr: amount })
    });
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
      body: JSON.stringify({ amount_usd: amount })
    });
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
  if (!activeOrder || activePaymentType !== 'binance') return;
  const file = $('binanceProof').files?.[0];
  if (!file) { $('paymentStatus').textContent = 'Choose the payment screenshot first.'; return; }
  if (file.size > 5 * 1024 * 1024) { $('paymentStatus').textContent = 'Screenshot must be 5 MB or smaller.'; return; }
  const form = new FormData();
  form.append('txid', $('binanceTxid').value.trim());
  form.append('proof', file, file.name);
  $('submitBinanceProof').disabled = true;
  try {
    await request(`/api/store/wallet/topups/${encodeURIComponent(activeOrder)}/proof`, { method: 'POST', body: form });
    $('binanceTxid').disabled = true;
    $('binanceProof').disabled = true;
    $('paymentStatus').textContent = 'Proof submitted. An administrator must verify the transfer before your INR wallet is credited.';
    await pollStatus();
  } catch (e) {
    $('paymentStatus').textContent = e.message;
    $('submitBinanceProof').disabled = false;
  }
}
async function pollStatus() {
  if (!activeOrder) return;
  try {
    const path = ['wallet','binance'].includes(activePaymentType)
      ? `/api/store/wallet/topups/${encodeURIComponent(activeOrder)}`
      : `/api/store/checkouts/${encodeURIComponent(activeOrder)}`;
    const data = await request(path);
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
    } else if (['failed', 'paid_delivery_failed', 'delivery_unknown', 'amount_review'].includes(status)) clearInterval(pollTimer);
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
  if(detailProduct && !$('productModal').classList.contains('hidden'))openProduct(detailProduct.key);
  $('currencyNote').textContent = currency === 'USD'
    ? 'USD prices are shown only where set by the shop. Wallet balances and all payments settle in INR.'
    : 'Wallet balances and all payments settle in INR.';
}

$('authOpen').addEventListener('click', openAuth);
$('loginTab').addEventListener('click', () => setAuthMode('login'));
$('registerTab').addEventListener('click', () => setAuthMode('register'));
$('authForm').addEventListener('submit', authSubmit);
$('search').addEventListener('input', renderProducts);
$('currencySelect').addEventListener('change', event => setCurrency(event.target.value));
$('heroPrev').addEventListener('click',()=>{clearInterval(carouselTimer);goSlide(activeSlide-1);carouselTimer=setInterval(()=>goSlide((activeSlide+1)%3),6500)});
$('heroNext').addEventListener('click',()=>{clearInterval(carouselTimer);goSlide(activeSlide+1);carouselTimer=setInterval(()=>goSlide((activeSlide+1)%3),6500)});
document.addEventListener('click',event=>{
  const filter=event.target.closest('[data-store-filter]');
  if(filter){selectedStoreFilter=filter.dataset.storeFilter;renderFilterGroups();renderProducts();return}
  const profileTab=event.target.closest('[data-profile-tab]');
  if(profileTab){setProfileTab(profileTab.dataset.profileTab);return}
  const dot=event.target.closest('[data-slide]');if(dot){clearInterval(carouselTimer);goSlide(Number(dot.dataset.slide));carouselTimer=setInterval(()=>goSlide((activeSlide+1)%3),6500);return}
  const card=event.target.closest('[data-open-product]');if(card)openProduct(card.dataset.openProduct);
});
$('checkPayment').addEventListener('click', pollStatus);
$('profileForm').addEventListener('submit', saveProfile);
$('topupForm').addEventListener('submit', startTopup);
$('binanceTopupForm').addEventListener('submit', startBinanceTopup);
$('binanceProofForm').addEventListener('submit', submitBinanceProof);
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
loadHomepage();
loadProducts();
syncSession();
