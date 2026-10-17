'use strict';
(() => {
  const root = document.getElementById('accountDashboard');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[char]);
  const money = (value, currency = 'INR') => new Intl.NumberFormat(currency === 'USD' ? 'en-US' : 'en-IN', {
    style: 'currency', currency, maximumFractionDigits: 2
  }).format(Number(value || 0));
  const date = value => value ? new Date(Number(value) * 1000).toLocaleString() : '—';
  let csrf = '';
  let activeTab = 'overview';
  let account = {};
  let wallet = { balance_inr: 0, transactions: [], payment_history: [] };
  let orders = [];

  async function api(path, options = {}) {
    const headers = { ...(options.headers || {}) };
    if (csrf && options.method && options.method.toUpperCase() !== 'GET') headers['X-CSRF-Token'] = csrf;
    const response = await fetch(path, { credentials: 'same-origin', ...options, headers });
    let data = {};
    try { data = await response.json(); } catch {}
    if (response.status === 401) {
      location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search);
      throw new Error('Please sign in again.');
    }
    if (!response.ok) throw new Error(data.error || 'Could not load your account.');
    return data;
  }

  function paymentRows() {
    const topups = (wallet.payment_history || []).map(item => ({
      title: item.payment_method === 'binance' ? 'Binance Pay top-up'
        : item.payment_method === 'usdt_bep20' ? 'USDT · BNB Smart Chain (BEP20) top-up' : 'UPI wallet top-up',
      ref: item.order_id, amount: Number(item.amount_inr || 0), usd: Number(item.amount_usd || 0),
      status: item.status, time: Number(item.created_at || 0), txid: item.proof_txid || item.transaction_id || '', note: item.review_note || '',
      verifyType: item.payment_method === 'upi' && item.status === 'pending' ? 'topup' : '', paymentUrl: item.payment_url || ''
    }));
    const purchases = orders.map(item => ({
      title: item.payment_method === 'binance' ? 'Binance Pay product purchase'
        : item.payment_method === 'usdt_bep20' ? 'USDT · BNB Smart Chain (BEP20) product purchase'
        : item.transaction_id === 'WALLET' ? 'Wallet product purchase' : 'UPI product purchase',
      ref: item.order_id, amount: Number(item.amount || 0), usd: 0, status: item.status,
      time: Number(item.created_at || 0), txid: item.transaction_id === 'WALLET' ? '' : (item.transaction_id || ''), note: '',
      verifyType: item.payment_method === 'upi' && item.status === 'pending' ? 'checkout' : '', paymentUrl: item.payment_url || ''
    }));
    return [...topups, ...purchases].sort((a, b) => b.time - a.time);
  }

  function orderMarkup(item) {
    const pendingUpi = item.payment_method === 'upi' && item.status === 'pending';
    const payment = pendingUpi ? `<div class="hx-payment-actions">${item.payment_url ? `<a class="hx-button hx-secondary" href="${esc(item.payment_url)}" target="_blank" rel="noopener noreferrer">Continue payment</a>` : ''}<button class="hx-button hx-secondary" type="button" data-verify-payment="checkout" data-verify-id="${esc(item.order_id)}">Check payment status</button></div>` : '';
    return `<article class="hx-panel hx-order"><div><b>${esc(item.product_name || 'Product')}</b><div class="hx-muted">${esc(item.duration || '')} · ${esc(date(item.created_at))}</div></div><span class="hx-status">${esc(item.status || 'pending')}</span><b>${money(item.amount)}</b><small>Order ${esc(item.order_id || '')}</small>${item.delivery_key ? `<div class="hx-key">${esc(item.delivery_key)}</div>` : ''}${payment}</article>`;
  }

  function render() {
    const name = account.display_name || account.email || 'Shop customer';
    const totalSpent = orders.reduce((sum, item) => sum + Number(item.amount || 0), 0);
    const history = paymentRows();
    const keyOrders = orders.filter(item => item.delivery_key);
    root.innerHTML = `
      <section class="account-welcome hx-panel"><div class="account-welcome-avatar" aria-hidden="true">${esc(name.slice(0, 1).toUpperCase())}</div><div class="account-welcome-copy"><p class="hx-eyebrow">HEX CHEATS SHOP MEMBER</p><h2>Hello, ${esc(name)}</h2><p>${esc(account.email || '')}</p></div><a class="hx-button account-fund-cta" href="/add-funds">＋ Add funds</a></section>
      <section class="account-summary-grid" aria-label="Account summary"><article class="hx-panel account-balance-card"><span>AVAILABLE BALANCE</span><strong>${money(wallet.balance_inr)}</strong><a href="/wallet">Wallet activity and payment status</a></article><article class="hx-panel account-stat-card"><strong>${orders.length}</strong><span>TOTAL ORDERS</span></article><article class="hx-panel account-stat-card"><strong>${money(totalSpent)}</strong><span>TOTAL SPENT</span></article></section>
      <nav class="account-tabs" role="tablist" aria-label="Account sections">
        <button type="button" role="tab" id="accountTabOverview" aria-controls="accountPaneOverview" aria-selected="${activeTab === 'overview'}" data-account-tab="overview">My account</button>
        <button type="button" role="tab" id="accountTabOrders" aria-controls="accountPaneOrders" aria-selected="${activeTab === 'orders'}" data-account-tab="orders">My orders <span>${orders.length}</span></button>
        <button type="button" role="tab" id="accountTabPayments" aria-controls="accountPanePayments" aria-selected="${activeTab === 'payments'}" data-account-tab="payments">Payments <span>${history.length}</span></button>
        <button type="button" role="tab" id="accountTabKeys" aria-controls="accountPaneKeys" aria-selected="${activeTab === 'keys'}" data-account-tab="keys">My keys <span>${keyOrders.length}</span></button>
      </nav>
      <section class="account-pane ${activeTab === 'overview' ? 'active' : ''}" id="accountPaneOverview" role="tabpanel" aria-labelledby="accountTabOverview" ${activeTab === 'overview' ? '' : 'hidden'}>
        <div class="hx-panel account-profile-panel"><div><p class="hx-eyebrow">PROFILE SETTINGS</p><h2>Account details</h2><p class="hx-muted">Update the display name shown in your shop account.</p></div><form id="accountProfileForm" class="hx-form"><label>Display name<input name="display_name" maxlength="100" required value="${esc(name)}"></label><button class="hx-button" type="submit">Save profile</button><div id="accountProfileStatus" class="hx-alert" role="status"></div></form></div>
        <div class="account-shortcut-grid"><a class="hx-panel" href="/add-funds"><span class="account-shortcut-icon" aria-hidden="true">＋</span><b>Add funds</b><small>UPI, Binance Pay USD, or USDT BEP20</small></a><a class="hx-panel" href="/orders"><span class="account-shortcut-icon" aria-hidden="true">▣</span><b>View all orders</b><small>Payment and delivery status</small></a><a class="hx-panel" href="/keys"><span class="account-shortcut-icon" aria-hidden="true">⌑</span><b>Delivered keys</b><small>Copy your completed order keys</small></a></div>
      </section>
      <section class="account-pane ${activeTab === 'orders' ? 'active' : ''}" id="accountPaneOrders" role="tabpanel" aria-labelledby="accountTabOrders" ${activeTab === 'orders' ? '' : 'hidden'}>${orders.length ? `<div class="hx-list">${orders.map(orderMarkup).join('')}</div>` : '<div class="hx-panel account-empty"><span aria-hidden="true">▣</span><h2>No orders yet</h2><p>After a purchase, its payment and delivery status will appear here.</p><a class="hx-button" href="/#catalog">Browse products</a></div>'}</section>
        <section class="account-pane ${activeTab === 'payments' ? 'active' : ''}" id="accountPanePayments" role="tabpanel" aria-labelledby="accountTabPayments" ${activeTab === 'payments' ? '' : 'hidden'}>${history.length ? `<div class="history-list">${history.map(item => `<article class="hx-panel account-history-row"><div><b>${esc(item.title)}</b><small>${esc(item.ref)} · ${esc(date(item.time))}</small>${item.txid ? `<small>Transaction ID: ${esc(item.txid)}</small>` : ''}${item.note ? `<small>Review note: ${esc(item.note)}</small>` : ''}${item.verifyType ? `<div class="hx-payment-actions">${item.paymentUrl ? `<a class="hx-button hx-secondary" href="${esc(item.paymentUrl)}" target="_blank" rel="noopener noreferrer">Continue payment</a>` : ''}<button class="hx-button hx-secondary" type="button" data-verify-payment="${item.verifyType}" data-verify-id="${esc(item.ref)}">Check payment status</button></div>` : ''}</div><div><strong>${money(item.amount)}</strong>${item.usd ? `<small>Paid amount: ${money(item.usd, 'USD')}</small>` : ''}</div><span class="hx-status">${esc(item.status)}</span></article>`).join('')}</div>` : '<div class="hx-panel account-empty"><span aria-hidden="true">▤</span><h2>No payment history yet</h2><p>Your top-ups and product payments will appear here.</p><a class="hx-button" href="/add-funds">Add funds</a></div>'}</section>
      <section class="account-pane ${activeTab === 'keys' ? 'active' : ''}" id="accountPaneKeys" role="tabpanel" aria-labelledby="accountTabKeys" ${activeTab === 'keys' ? '' : 'hidden'}>${keyOrders.length ? `<div class="hx-list">${keyOrders.map(item => `<article class="hx-panel account-key-card"><div><b>${esc(item.product_name || 'Product')} · ${esc(item.duration || '')}</b><small>${esc(date(item.created_at))} · ${esc(item.order_id || '')}</small></div><code>${esc(item.delivery_key)}</code><button type="button" class="hx-button hx-secondary" data-copy-key>Copy key</button></article>`).join('')}</div>` : '<div class="hx-panel account-empty"><span aria-hidden="true">⌑</span><h2>No keys delivered yet</h2><p>Keys appear here when a product order has been completed.</p></div>'}</section>`;

    root.querySelectorAll('[data-account-tab]').forEach(button => button.addEventListener('click', () => selectTab(button.dataset.accountTab)));
    root.querySelector('.account-tabs')?.addEventListener('keydown', tabKeydown);
    root.querySelector('#accountProfileForm')?.addEventListener('submit', saveProfile);
    root.querySelectorAll('[data-verify-payment]').forEach(button => button.addEventListener('click', async () => {
      const type = button.dataset.verifyPayment, id = button.dataset.verifyId;
      const endpoint = type === 'topup' ? `/api/store/wallet/topups/${encodeURIComponent(id)}/verify` : `/api/store/checkouts/${encodeURIComponent(id)}/verify`;
      button.disabled = true; button.textContent = 'Checking…';
      try {
        await api(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        const [walletData, orderData] = await Promise.all([api('/api/store/wallet'), api('/api/store/orders')]);
        wallet = walletData; orders = orderData.orders || []; render();
      } catch (error) { button.disabled = false; button.textContent = 'Check failed — retry'; button.title = error.message; }
    }));
    root.querySelectorAll('[data-copy-key]').forEach(button => button.addEventListener('click', async () => {
      const code = button.closest('.account-key-card')?.querySelector('code')?.textContent || '';
      try { await navigator.clipboard.writeText(code); button.textContent = 'Copied'; }
      catch { button.textContent = 'Select and copy the key above'; }
    }));
  }

  function selectTab(tab) {
    if (!['overview', 'orders', 'payments', 'keys'].includes(tab) || tab === activeTab) return;
    const oldPane = document.getElementById(`accountPane${activeTab[0].toUpperCase()}${activeTab.slice(1)}`);
    const nextPane = document.getElementById(`accountPane${tab[0].toUpperCase()}${tab.slice(1)}`);
    activeTab = tab;
    root.querySelectorAll('[data-account-tab]').forEach(button => button.setAttribute('aria-selected', String(button.dataset.accountTab === tab)));
    root.querySelectorAll('[role="tabpanel"]').forEach(pane => {
      const active = pane === nextPane;
      pane.hidden = !active;
      pane.classList.toggle('active', active);
    });
    nextPane?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
    oldPane?.classList.remove('active');
  }

  function tabKeydown(event) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const tabs = [...root.querySelectorAll('[data-account-tab]')];
    const index = tabs.indexOf(event.target.closest('[data-account-tab]'));
    if (index < 0) return;
    event.preventDefault();
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
      : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    tabs[next].focus();
    tabs[next].click();
  }

  async function saveProfile(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const status = form.querySelector('[role="status"]');
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    status.textContent = '';
    try {
      const result = await api('/api/store/profile', {
        method: 'PUT', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ display_name: form.elements.display_name.value.trim() })
      });
      account = result.account || account;
      render();
      selectTab('overview');
      const note = root.querySelector('#accountProfileStatus');
      if (note) note.textContent = 'Profile updated.';
    } catch (error) { status.textContent = error.message; }
    finally { if (button.isConnected) button.disabled = false; }
  }

  async function init() {
    try {
      const session = await api('/api/store/session');
      if (!session.authenticated) {
        location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search);
        return;
      }
      account = session.account || {};
      csrf = session.csrf_token || '';
      const [walletData, orderData] = await Promise.all([api('/api/store/wallet'), api('/api/store/orders')]);
      wallet = walletData;
      orders = orderData.orders || [];
      render();
    } catch (error) {
      root.innerHTML = `<div class="hx-panel hx-alert" role="alert">${esc(error.message || 'Could not load your account. Please refresh.')}</div>`;
    }
  }
  init();
})();
