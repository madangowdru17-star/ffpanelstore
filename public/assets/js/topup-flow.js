'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const shell = document.querySelector('[data-topup-method]');
  const form = $('topupForm');
  if (!shell || !form) return;
  const method = shell.dataset.topupMethod;
  const settings = {
    upi: { endpoint: '/api/store/wallet/topups', unit: 'INR', idScope: 'wallet-upi-topup' },
    binance: { endpoint: '/api/store/wallet/binance', unit: 'USD', idScope: 'wallet-binance-topup' },
    usdt: { endpoint: '/api/store/wallet/usdt', unit: 'USDT', idScope: 'wallet-usdt-topup' }
  }[method];
  if (!settings) return;

  const amountInput = $('topupAmount');
  const submitButton = $('createPayment');
  const errorBox = $('paymentError');
  const progress = $('securityProgress');
  const resultBox = $('paymentResult');
  const activeKey = `hex_topup_active_${method}`;
  let csrf = '';
  let activeData = null;

  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const money = value => '₹' + Number(value || 0).toFixed(2);
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const jsonHeaders = { 'Content-Type': 'application/json', 'Accept': 'application/json' };

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

  function setStatus(message, state = '') {
    const status = $('paymentLiveStatus');
    if (!status) return;
    status.textContent = message;
    status.dataset.state = state;
  }

  function renderResult(data) {
    activeData = data;
    try { sessionStorage.setItem(activeKey, JSON.stringify(data)); } catch {}
    form.hidden = true;
    progress.hidden = false;
    progress.classList.add('is-ready');
    updateStep(2, 'Payment request is ready', 'The server validated your request. Use the exact amount and QR below.');
    const qrUrl = safeUrl(data.qr_url);
    const paymentUrl = safeUrl(data.payment_url);
    const isUpi = method === 'upi';
    const amount = isUpi ? money(data.amount_inr) : Number(data.amount_usd).toFixed(2);
    const inrNote = !isUpi ? `<p class="hx-muted">Estimated wallet credit: <strong>${money(data.amount_inr)}</strong> at ₹${Number(data.exchange_rate_inr).toFixed(2)} per USD. This quote is locked for this request.</p>` : '';
    const networkNote = method === 'usdt' ? '<div class="hx-network-warning"><strong>BNB Smart Chain (BEP20) only.</strong> Sending on another network may permanently lose your funds.</div>' : '';
    const paymentLink = isUpi && paymentUrl ? `<a class="hx-button hx-payment-open-link" href="${escapeHtml(paymentUrl)}" target="_blank" rel="noopener noreferrer"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#arrow-right"></use></svg>Open UPI payment</a>` : '';
    const cryptoInstructions = !isUpi ? `<p>${escapeHtml(data.instructions || 'Send the exact amount, then submit your transaction ID and payment screenshot for review.')}</p>` : '<p>After paying, use the status button below. Wallet credit is applied only after provider confirmation.</p>';
    const proofForm = (!isUpi && (!data.status || data.status === 'manual_pending')) ? `<form class="hx-form hx-proof-form" id="paymentProofForm" enctype="multipart/form-data"><label for="paymentTxid">Transaction ID</label><input id="paymentTxid" name="txid" minlength="4" maxlength="180" required autocomplete="off"><label for="paymentProofFile">Payment screenshot (PNG, JPEG or WebP; maximum 5 MB)</label><input id="paymentProofFile" name="proof" type="file" accept="image/png,image/jpeg,image/webp" required><button class="hx-button" type="submit">Submit for admin review</button><div class="hx-alert" id="proofStatus" role="status"></div></form>` : '';
    resultBox.innerHTML = `<section class="hx-payment-result hx-panel"><div class="hx-payment-ready"><span class="hx-ready-mark"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#check"></use></svg></span><div><strong>Payment details are ready</strong><small>Keep this page open until your payment is confirmed.</small></div></div><div class="hx-pay-exact"><small>Pay exactly</small><strong>${escapeHtml(amount)}</strong><span>${isUpi ? 'INR' : escapeHtml(settings.unit)}</span></div><div class="hx-payment-reference">Request reference <strong>${escapeHtml(data.order_id)}</strong></div>${networkNote}${qrUrl ? `<div class="hx-qr-frame"><img class="hx-pay-qr" src="${escapeHtml(qrUrl)}" alt="${escapeHtml(method === 'upi' ? 'UPI' : method === 'binance' ? 'Binance Pay' : 'USDT BEP20')} payment QR code"><span>Scan with your payment app</span></div>` : `<div class="hx-qr-unavailable">Use the secure payment link to continue.</div>`}${inrNote}${paymentLink}${cryptoInstructions}${proofForm}<div class="hx-payment-live" id="paymentLiveStatus" role="status" aria-live="polite">${escapeHtml(isUpi ? 'Waiting for payment confirmation.' : data.status === 'proof_submitted' ? 'Proof received. An administrator must verify the transaction before wallet credit.' : data.status === 'approved' ? 'Payment proof approved. Your wallet has been updated.' : data.status === 'rejected' ? 'Payment proof was rejected. Contact support if you believe this is an error.' : 'After sending, submit your transaction ID and screenshot for administrator review.')}</div><div class="hx-result-actions"><button class="hx-button hx-secondary" type="button" id="checkPaymentStatus"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#refresh"></use></svg>Check payment status</button><button class="hx-button hx-ghost" type="button" id="newPayment">Start another payment</button></div></section>`;
    bindResultActions(data);
    const qrTarget = resultBox.querySelector('.hx-qr-frame') || resultBox.querySelector('.hx-pay-exact') || resultBox;
    requestAnimationFrame(() => qrTarget.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' }));
  }

  function bindResultActions(data) {
    $('checkPaymentStatus')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      button.disabled = true;
      setStatus('Checking payment status securely…', 'checking');
      try {
        const latest = await api('/api/store/wallet/topups/' + encodeURIComponent(data.order_id) + '/verify', { method: 'POST', headers: jsonHeaders, body: '{}' });
        const status = String(latest.status || 'pending');
        data.status = status;
        try { sessionStorage.setItem(activeKey, JSON.stringify(data)); } catch {}
        if (method !== 'upi' && status !== 'manual_pending') $('paymentProofForm')?.remove();
        const messages = {
          complete: `Payment confirmed. Wallet balance: ${money(latest.balance_inr)}.`,
          approved: 'Payment proof approved by the administrator. Your wallet has been updated.',
          manual_pending: 'Payment request is open. Send the exact amount, then submit your transaction proof below.',
          proof_submitted: 'Proof received. An administrator must verify the transaction before wallet credit.',
          amount_review: 'Payment received; the amount is being reviewed. Do not pay again.',
          failed: 'This payment did not complete. If money was deducted, contact support with the request reference.',
          rejected: `Payment proof was rejected.${latest.review_note ? ' ' + latest.review_note : ' Contact support if you believe this is an error.'}`
        };
        setStatus(messages[status] || 'Payment is still pending. Complete the payment, then check again.', status);
        const final = ['complete', 'approved', 'failed', 'rejected', 'amount_review'].includes(status);
        if (final) { button.dataset.state = 'final'; button.disabled = true; }
      } catch (error) {
        setStatus(error.message || 'Could not check status. Try again shortly.', 'error');
      } finally {
        if (button.isConnected && button.dataset.state !== 'final') button.disabled = false;
      }
    });

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
        proofForm.remove();
        setStatus('Your payment proof is in the administrator review queue. Do not send funds again for this request.', 'submitted');
      } catch (error) {
        status.textContent = error.message || 'Proof could not be submitted. Please try again.';
        button.disabled = false;
      }
    });

    $('newPayment')?.addEventListener('click', () => {
      try {
        sessionStorage.removeItem(activeKey);
        sessionStorage.removeItem(`hex_payment_${settings.idScope}`);
      } catch {}
      activeData = null;
      resultBox.innerHTML = '';
      form.hidden = false;
      form.reset();
      progress.classList.remove('is-ready');
      progress.hidden = true;
      errorBox.textContent = '';
      updateStep(1, 'Enter your amount', 'Your session will be checked before the new request is created.');
      submitButton.disabled = false;
      amountInput.focus();
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
    submitButton.disabled = true;
      progress.hidden = false;
      progress.classList.remove('is-ready');
      progress.classList.remove('is-error');
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
      if (restored?.order_id && restored?.qr_url) {
        renderResult(restored);
        $('checkPaymentStatus')?.click();
      }
    } catch (error) {
      errorBox.textContent = error.message || 'Could not load your account. Please refresh the page.';
    }
  }

  form.addEventListener('submit', createPayment);
  init();
})();
