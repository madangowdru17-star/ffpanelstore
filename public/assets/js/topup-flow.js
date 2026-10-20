'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const shell = document.querySelector('[data-topup-method]');
  const form = $('topupForm');
  if (!shell || !form) return;

  window.__hexTopupCleanup?.();
  const method = shell.dataset.topupMethod;
  const settings = {
    upi: { endpoint: '/api/store/wallet/topups', unit: 'INR', idScope: 'wallet-upi-topup', title: 'UPI' },
    binance: { endpoint: '/api/store/wallet/binance', unit: 'USD', idScope: 'wallet-binance-topup', title: 'Binance Pay' },
    usdt: { endpoint: '/api/store/wallet/usdt', unit: 'USDT', idScope: 'wallet-usdt-topup', title: 'USDT · BEP20' }
  }[method];
  if (!settings) return;

  const amountInput = $('topupAmount');
  const submitButton = $('createPayment');
  const errorBox = $('paymentError');
  const progress = $('securityProgress');
  const resultBox = $('paymentResult');
  const activeKey = `hex_topup_active_${method}`;
  const terminalStatuses = new Set(['complete', 'approved', 'failed', 'rejected', 'amount_review']);
  let csrf = '';
  let activeData = null;
  let pollTimer = 0;
  let pollCount = 0;
  let polling = false;
  let destroyed = false;

  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const money = value => '₹' + Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const jsonHeaders = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  const isUpi = method === 'upi';

  function requestIdempotency(amount) {
    const keyName = `hex_payment_${settings.idScope}`;
    const fingerprint = String(amount);
    try {
      const saved = JSON.parse(sessionStorage.getItem(keyName) || 'null');
      if (saved?.fingerprint === fingerprint && /^[A-Za-z0-9_-]{16,64}$/.test(saved.key || '')) return saved.key;
    } catch {}
    const key = globalThis.crypto?.randomUUID
      ? globalThis.crypto.randomUUID().replace(/-/g, '')
      : Array.from(globalThis.crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
    try { sessionStorage.setItem(keyName, JSON.stringify({ fingerprint, key })); } catch {}
    return key;
  }

  async function api(url, options = {}) {
    const headers = { 'Accept': 'application/json', ...(options.headers || {}) };
    if (options.method && options.method.toUpperCase() !== 'GET') {
      if (!(options.body instanceof FormData) && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
      if (csrf) headers['X-CSRF-Token'] = csrf;
    }
    const response = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    let data = {};
    try { data = await response.json(); } catch {}
    if (!response.ok) throw new Error(data.error || data.message || `Request failed (${response.status}).`);
    return data;
  }

  function redirectToLogin() {
    const target = '/login?next=' + encodeURIComponent(location.pathname + location.search);
    if (window.hxGo) window.hxGo(target); else location.assign(target);
  }

  function updateStep(index, title, detail) {
    document.querySelectorAll('[data-payment-step]').forEach((step, i) => {
      step.classList.toggle('is-current', i === index);
      step.classList.toggle('is-done', i < index);
    });
    document.querySelectorAll('[data-security-step]').forEach((step, i) => {
      step.classList.toggle('is-current', i === index);
      step.classList.toggle('is-done', i < index);
    });
    if (title) $('securityTitle').textContent = title;
    if (detail) $('securityDetail').textContent = detail;
  }

  function safeUrl(value) {
    if (!value) return '';
    try {
      const url = new URL(value, location.origin);
      return url.origin === location.origin || url.protocol === 'https:' ? url.href : '';
    } catch { return ''; }
  }

  function markCheckoutUrl() {
    const url = new URL(location.href);
    if (url.searchParams.get('checkout') === '1') return;
    url.searchParams.set('checkout', '1');
    history.pushState({ ...(history.state || {}), hexTopupCheckout: true }, '', url.href);
  }

  function setStatus(message, state = 'pending') {
    const status = $('paymentLiveStatus');
    if (!status) return;
    const label = status.querySelector('[data-status-text]');
    if (label) label.textContent = message; else status.textContent = message;
    status.dataset.state = state;
    status.querySelector('use')?.setAttribute('href', ['complete', 'approved'].includes(state) ? '/assets/icons.svg#check' : '/assets/icons.svg#shield-check');
    const title = $('paymentReadyTitle');
    if (title && ['complete', 'approved'].includes(state)) title.textContent = 'Payment confirmed';
    if (title && ['failed', 'rejected'].includes(state)) title.textContent = 'Payment needs attention';
  }

  function statusMessage(status) {
    const messages = {
      complete: 'Payment confirmed · Wallet updated.',
      approved: 'Payment proof approved.',
      pending: 'Waiting · checking automatically.',
      processing: 'Processing · checking automatically.',
      manual_pending: 'Proof upload required.',
      proof_submitted: 'Admin review pending.',
      amount_review: 'Amount review · do not pay again.',
      failed: 'Payment failed · contact support if deducted.',
      rejected: 'Proof rejected · review the order history.'
    };
    return messages[status] || 'Checking payment automatically.';
  }

  function stopPolling() {
    if (pollTimer) window.clearTimeout(pollTimer);
    pollTimer = 0;
  }

  function shouldPoll(data = activeData) {
    if (!data?.order_id || destroyed || terminalStatuses.has(String(data.status || '').toLowerCase())) return false;
    const status = String(data.status || '').toLowerCase();
    return isUpi || status === 'proof_submitted' || status === 'pending' || status === 'processing';
  }

  function schedulePoll(delay = 9000) {
    stopPolling();
    if (!shouldPoll()) return;
    pollTimer = window.setTimeout(verifyStatus, delay);
  }

  async function verifyStatus() {
    if (polling || !shouldPoll()) return;
    polling = true;
    pollCount += 1;
    if (isUpi || activeData.status === 'proof_submitted') setStatus('Checking securely with the payment provider…', 'checking');
    try {
      const latest = await api('/api/store/wallet/topups/' + encodeURIComponent(activeData.order_id) + '/verify', {
        method: 'POST', headers: jsonHeaders, body: '{}'
      });
      activeData.status = String(latest.status || activeData.status || 'pending').toLowerCase();
      if (latest.balance_inr != null) activeData.balance_inr = latest.balance_inr;
      if (latest.review_note) activeData.review_note = latest.review_note;
      try { sessionStorage.setItem(activeKey, JSON.stringify(activeData)); } catch {}
      if (!isUpi && activeData.status !== 'manual_pending') $('paymentProofForm')?.remove();
      setStatus(statusMessage(activeData.status, latest), activeData.status);
      if (terminalStatuses.has(activeData.status)) {
        stopPolling();
        return;
      }
      const interval = pollCount < 4 ? 9000 : pollCount < 10 ? 16000 : 30000;
      schedulePoll(interval);
    } catch (error) {
      setStatus('Automatic verification will retry shortly. Keep this page open.', 'retrying');
      schedulePoll(pollCount < 4 ? 12000 : 30000);
    } finally {
      polling = false;
    }
  }

  function showProofReceipt() {
    const receipt = document.createElement('div');
    receipt.className = 'hx-proof-success-overlay';
    receipt.setAttribute('role', 'status');
    receipt.setAttribute('aria-live', 'assertive');
    receipt.innerHTML = '<section class="hx-proof-success-card"><span class="hx-proof-success-mark"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#check"></use></svg></span><strong>Proof uploaded successfully</strong><p>Payment is awaiting admin verification. Opening Order History…</p></section>';
    document.body.append(receipt);
    window.setTimeout(() => {
      receipt.remove();
      const target = '/orders?proof=received';
      if (window.hxGo) window.hxGo(target); else location.assign(target);
    }, 1000);
  }

  function renderResult(data) {
    activeData = data;
    pollCount = 0;
    markCheckoutUrl();
    try { sessionStorage.setItem(activeKey, JSON.stringify(data)); } catch {}
    form.hidden = true;
    progress.hidden = true;
    progress.classList.add('is-ready');
    $('topupPageHeading')?.setAttribute('hidden', '');
    $('topupSteps')?.setAttribute('hidden', '');
    $('topupFormHeading')?.setAttribute('hidden', '');
    document.querySelector('.hx-pay-method-summary')?.setAttribute('hidden', '');
    document.querySelector('.hx-pay-layout')?.classList.add('is-checkout-result');
    updateStep(2, 'Payment request is ready', 'The server validated your request. Use the exact amount and QR below.');

    const qrUrl = safeUrl(data.qr_url);
    const paymentUrl = safeUrl(data.payment_url);
    const amount = isUpi ? money(data.amount_inr) : Number(data.amount_usd).toFixed(2);
    const logo = method === 'upi' ? '/assets/brands/google-pay-mark.svg'
      : method === 'binance' ? '/assets/brands/binance-pay.png' : '/assets/brands/usdt-tether-mark.svg';
    const methodLogo = `<img src="${logo}" alt="${escapeHtml(settings.title)}" class="hx-gateway-logo">`;
    const status = String(data.status || (isUpi ? 'pending' : 'manual_pending')).toLowerCase();
    const inrNote = !isUpi
      ? `<p class="hx-muted">Estimated wallet credit: <strong>${money(data.amount_inr)}</strong> at ₹${Number(data.exchange_rate_inr).toFixed(2)} per USD. This quote is locked for this request.</p>` : '';
    const networkNote = method === 'usdt'
      ? '<div class="hx-network-warning"><strong>BNB Smart Chain (BEP20) only.</strong> Sending on another network may permanently lose your funds.</div>' : '';
    const paymentLink = isUpi && paymentUrl
      ? `<a class="hx-button hx-payment-open-link" href="${escapeHtml(paymentUrl)}" target="_blank" rel="noopener noreferrer"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#arrow-right"></use></svg>Open secure UPI payment</a>` : '';
    const cryptoInstructions = !isUpi
      ? `<p>${escapeHtml(data.instructions || 'Send the exact amount, then submit your transaction ID and payment screenshot for review.')}</p>` : '';
    const proofForm = (!isUpi && status === 'manual_pending')
      ? `<form class="hx-form hx-proof-form" id="paymentProofForm" enctype="multipart/form-data"><label for="paymentTxid">Transaction ID</label><input id="paymentTxid" name="txid" minlength="4" maxlength="180" required autocomplete="off"><label for="paymentProofFile">Payment screenshot (PNG, JPEG or WebP; maximum 5 MB)</label><input id="paymentProofFile" name="proof" type="file" accept="image/png,image/jpeg,image/webp" required><button class="hx-button" type="submit">Submit for admin review</button><div class="hx-alert" id="proofStatus" role="status"></div></form>` : '';
    const alt = method === 'upi' ? 'UPI payment' : method === 'binance' ? 'Binance Pay' : 'USDT BEP20';
    const initialMessage = statusMessage(status);
    const autoState = terminalStatuses.has(status) ? status : (isUpi || status === 'proof_submitted' ? 'checking' : status);

    resultBox.innerHTML = `<section class="hx-payment-result hx-panel hx-gateway-checkout" aria-label="${escapeHtml(settings.title)} checkout">
      <header class="hx-gateway-heading"><div class="hx-gateway-brand">${methodLogo}<span><small>HEX CHEATS SHOP · SECURE CHECKOUT</small><strong id="paymentReadyTitle">${terminalStatuses.has(status) ? (['complete','approved'].includes(status) ? 'Payment confirmed' : 'Payment needs attention') : 'Payment details are ready'}</strong><em>${isUpi ? 'UPI payment · Google Pay or any UPI app' : escapeHtml(settings.title)}</em></span></div><span class="hx-auto-badge" id="paymentLiveStatus" data-state="${escapeHtml(autoState)}" role="status" aria-live="polite"><svg class="hx-icon" aria-hidden="true"><use href="${['complete','approved'].includes(status) ? '/assets/icons.svg#check' : '/assets/icons.svg#shield-check'}"></use></svg><span data-status-text>${escapeHtml(initialMessage)}</span></span></header>
      <div class="hx-pay-exact"><small>Pay exactly</small><strong>${escapeHtml(amount)}</strong><span>${isUpi ? 'INR' : escapeHtml(settings.unit)}</span></div>
      <div class="hx-payment-reference">Request reference <strong>${escapeHtml(data.order_id)}</strong></div>${networkNote}
      ${qrUrl ? `<div class="hx-qr-frame"><img class="hx-pay-qr" src="${escapeHtml(qrUrl)}" alt="${escapeHtml(alt)} QR code"><span>${isUpi ? 'Scan with Google Pay or any UPI app' : 'Scan with your payment app'}</span></div>` : '<div class="hx-qr-unavailable">Use the secure payment link to continue.</div>'}
      ${paymentLink}${inrNote}${cryptoInstructions}${proofForm}
    </section>`;
    if (isUpi || status === 'proof_submitted' || status === 'pending' || status === 'processing') schedulePoll(9000);
    if (!terminalStatuses.has(status) && (isUpi || status === 'proof_submitted')) {
      requestAnimationFrame(() => resultBox.querySelector('.hx-qr-frame')?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'end' }));
    }
    bindProofForm(data);
  }

  function bindProofForm(data) {
    $('paymentProofForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      const proofForm = event.currentTarget;
      const button = proofForm.querySelector('button[type="submit"]');
      const status = $('proofStatus');
      const file = $('paymentProofFile').files?.[0];
      if (!file) { status.textContent = 'Choose the payment screenshot first.'; return; }
      if (file.size < 1 || file.size > 5 * 1024 * 1024) { status.textContent = 'Screenshot must be 5 MB or smaller.'; return; }
      button.disabled = true;
      status.textContent = 'Uploading proof securely…';
      try {
        const saved = await api('/api/store/wallet/topups/' + encodeURIComponent(data.order_id) + '/proof', { method: 'POST', body: new FormData(proofForm) });
        data.status = saved.status || 'proof_submitted';
        activeData = data;
        try { sessionStorage.setItem(activeKey, JSON.stringify(data)); } catch {}
        showProofReceipt();
      } catch (error) {
        status.textContent = error.message || 'Proof could not be submitted. Please try again.';
        button.disabled = false;
      }
    });
  }

  async function createPayment(event) {
    event.preventDefault();
    errorBox.textContent = '';
    if (!form.reportValidity()) return;
    const amount = Number(amountInput.value);
    if (!Number.isFinite(amount) || amount < Number(amountInput.min) || amount > Number(amountInput.max)) {
      amountInput.focus();
      errorBox.textContent = 'Enter an amount within the displayed limits.';
      return;
    }
    stopPolling();
    submitButton.disabled = true;
    progress.hidden = false;
    progress.classList.remove('is-ready', 'is-error');
    resultBox.innerHTML = '';
    updateStep(0, 'Verifying your secure session', 'Confirming your signed-in account and request token.');
    try {
      const session = await api('/api/store/session');
      if (!session.authenticated) { redirectToLogin(); return; }
      csrf = session.csrf_token || '';
      if (!csrf) throw new Error('Your secure session needs to be refreshed. Sign in again and retry.');
      updateStep(1, 'Validating amount & creating request', 'The server is checking your amount and payment method.');
      const payload = method === 'upi'
        ? { amount_inr: amount, idempotency_key: requestIdempotency(amount) }
        : { amount_usd: amount, idempotency_key: requestIdempotency(amount) };
      const data = await api(settings.endpoint, { method: 'POST', headers: jsonHeaders, body: JSON.stringify(payload) });
      if (!data.order_id || !data.qr_url) throw new Error('The payment details could not be loaded. Please contact shop support before retrying.');
      await wait(180);
      updateStep(2, 'Payment request verified', 'Your server-validated QR and exact payment instructions are ready.');
      renderResult(data);
    } catch (error) {
      progress.hidden = false;
      progress.classList.remove('is-ready');
      progress.classList.add('is-error');
      updateStep(1, 'Payment request could not be started', 'No QR was created. Review the message below before retrying.');
      errorBox.textContent = error.message || 'Could not create the payment request. Please try again.';
      submitButton.disabled = false;
    }
  }

  function onVisibilityChange() {
    if (document.visibilityState === 'visible' && shouldPoll() && !polling) {
      stopPolling();
      verifyStatus();
    }
  }

  function onPageChange() {
    if (!location.pathname.startsWith('/pay/')) window.__hexTopupCleanup?.();
  }

  window.__hexTopupCleanup = () => {
    destroyed = true;
    stopPolling();
    document.removeEventListener('visibilitychange', onVisibilityChange);
    document.removeEventListener('hex:pagechange', onPageChange);
  };
  document.addEventListener('visibilitychange', onVisibilityChange);
  document.addEventListener('hex:pagechange', onPageChange);
  form.addEventListener('submit', createPayment);

  async function init() {
    submitButton.disabled = true;
    try {
      const session = await api('/api/store/session');
      if (!session.authenticated) { redirectToLogin(); return; }
      csrf = session.csrf_token || '';
      if (!csrf) throw new Error('Could not prepare a secure payment session. Please sign in again.');
      submitButton.disabled = false;
      let restored = null;
      try { restored = JSON.parse(sessionStorage.getItem(activeKey) || 'null'); } catch {}
      const checkoutRequested = new URLSearchParams(location.search).get('checkout') === '1';
      if (checkoutRequested && restored?.order_id && restored?.qr_url) renderResult(restored);
      else if (checkoutRequested) {
        const url = new URL(location.href);
        url.searchParams.delete('checkout');
        history.replaceState(history.state, '', url.href);
      }
    } catch (error) {
      errorBox.textContent = error.message || 'Could not load your account. Please refresh the page.';
    }
  }

  init();
})();
