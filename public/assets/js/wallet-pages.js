'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const money = value => '₹' + Number(value || 0).toFixed(2);
  let csrf = '';

  async function call(url, options = {}) {
    const headers = { ...(options.headers || {}) };
    if (csrf && options.method && options.method.toUpperCase() !== 'GET') headers['X-CSRF-Token'] = csrf;
    const response = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    let data = {};
    try { data = await response.json(); } catch {}
    if (response.status === 401) {
      location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search);
      throw new Error('Please sign in.');
    }
    if (!response.ok) throw new Error(data.error || 'Request failed.');
    return data;
  }

  function renderWallet(data) {
    if ($('balance')) $('balance').textContent = money(data.balance_inr);
    const rows = $('walletRows');
    if (!rows) return;
    const transactions = (data.transactions || []).map(item => `<article class="hx-row"><span>${esc(item.description || item.kind)}<small>${esc(new Date(Number(item.created_at) * 1000).toLocaleString())}</small></span><b>${Number(item.amount_inr) < 0 ? '−' : '+'}${money(Math.abs(Number(item.amount_inr || 0)))}</b></article>`).join('') || '<p class="hx-muted">No wallet activity yet.</p>';
    const methodName = method => method === 'binance' ? 'Binance Pay' : method === 'usdt_bep20' ? 'USDT · BEP20' : 'UPI';
    const history = (data.payment_history || []).map(item => `<article class="hx-row"><span>${esc(methodName(item.payment_method))} · ${esc(item.order_id)}<small>${esc(item.status)} · ${esc(new Date(Number(item.created_at) * 1000).toLocaleString())}${item.proof_txid ? ' · Tx: ' + esc(item.proof_txid) : ''}${item.review_note ? ' · ' + esc(item.review_note) : ''}</small></span><b>${item.amount_usd ? '$' + Number(item.amount_usd).toFixed(2) + ' / ' : ''}${money(item.amount_inr)}</b></article>`).join('') || '<p class="hx-muted">No payments yet.</p>';
    rows.innerHTML = `<h2>Wallet activity</h2>${transactions}<h2 class="hx-history-heading">Payment history</h2>${history}`;
  }

  async function init() {
    try {
      const session = await call('/api/store/session');
      if (!session.authenticated) {
        location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search);
        return;
      }
      csrf = session.csrf_token || '';
      if ($('walletRows')) renderWallet(await call('/api/store/wallet'));
    } catch (error) { if ($('walletRows')) $('walletRows').textContent = error.message; }
  }

  document.querySelectorAll('[data-method-option]').forEach(button => button.addEventListener('click', () => {
    const selected = button.dataset.methodOption;
    document.querySelectorAll('[data-method-option]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    document.querySelectorAll('[data-method-panel]').forEach(panel => { panel.hidden = panel.dataset.methodPanel !== selected; });
    document.querySelector(`[data-method-panel="${selected}"]`)?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  }));

  const upi = $('upiForm');
  if (upi) upi.addEventListener('submit', async event => {
    event.preventDefault();
    const status = $('upiStatus'), output = $('upiResult'), button = upi.querySelector('button[type="submit"]');
    status.textContent = ''; output.innerHTML = ''; button.disabled = true;
    try {
      const data = await call('/api/store/wallet/topups', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ amount_inr: Number(upi.amount.value) }) });
      output.innerHTML = `<div class="hx-payment"><b>Payment request ${esc(data.order_id)}</b><p>Pay ${money(data.amount_inr)} through the provider. Wallet credit happens only after the provider confirms the payment.</p>${data.qr_url ? `<img class="hx-qr" src="${esc(data.qr_url)}" alt="UPI payment QR">` : ''}${data.payment_url ? `<p><a class="hx-button" href="${esc(data.payment_url)}" target="_blank" rel="noopener noreferrer">Open payment</a></p>` : ''}<button class="hx-button hx-secondary" id="checkUpi" type="button">Check payment status</button><div id="upiCheckStatus" class="hx-muted" role="status"></div></div>`;
      $('checkUpi').addEventListener('click', async () => {
        const result = $('upiCheckStatus'); result.textContent = 'Checking…';
        try { const latest = await call('/api/store/wallet/topups/' + encodeURIComponent(data.order_id)); result.textContent = `Status: ${latest.status} · Wallet: ${money(latest.balance_inr)}`; if ($('walletRows')) renderWallet(await call('/api/store/wallet')); }
        catch (error) { result.textContent = error.message; }
      });
    } catch (error) { status.textContent = error.message; }
    finally { button.disabled = false; }
  });

  function bindCrypto(formId, statusId, resultId, endpoint, methodLabel, unit, networkNote = '') {
    const form = $(formId);
    if (!form) return;
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const status = $(statusId), output = $(resultId), button = form.querySelector('button[type="submit"]');
      status.textContent = ''; output.innerHTML = ''; button.disabled = true;
      try {
        const data = await call(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ amount_usd: Number(form.amount.value) }) });
        const name = methodLabel;
        output.innerHTML = `<div class="hx-payment"><b>${esc(name)} request ${esc(data.order_id)}</b><p>Send exactly <strong>${Number(data.amount_usd).toFixed(2)} ${esc(unit)}</strong>. Estimated wallet credit: ${money(data.amount_inr)} at ₹${Number(data.exchange_rate_inr).toFixed(2)} per USD. This INR quote is locked for the request and stays pending until an admin verifies the payment.</p>${networkNote ? `<div class="hx-network-warning">${esc(networkNote)}</div>` : ''}<img class="hx-qr" src="${esc(data.qr_url)}" alt="${esc(name)} payment QR"><p>${esc(data.instructions || 'Pay the exact amount, then submit your transaction ID and payment screenshot for review.')}</p><form class="hx-form" id="proofForm-${esc(data.order_id)}" enctype="multipart/form-data"><label>Transaction ID<input name="txid" minlength="4" maxlength="180" required autocomplete="off"></label><label>Payment screenshot (PNG, JPEG, WebP; max 5 MB)<input name="proof" type="file" accept="image/png,image/jpeg,image/webp" required></label><button class="hx-button" type="submit">Submit proof for admin review</button><div class="hx-alert" data-proof-status role="status"></div></form><p class="hx-muted">Your submission is saved in the admin payment-review queue. Do not send funds again for this request.</p></div>`;
        const proofForm = document.getElementById(`proofForm-${data.order_id}`);
        proofForm.addEventListener('submit', async proofEvent => {
          proofEvent.preventDefault();
          const proofButton = proofForm.querySelector('button[type="submit"]'), proofStatus = proofForm.querySelector('[data-proof-status]');
          proofButton.disabled = true; proofStatus.textContent = '';
          try {
            const result = await call('/api/store/wallet/topups/' + encodeURIComponent(data.order_id) + '/proof', { method: 'POST', body: new FormData(proofForm) });
            proofStatus.textContent = 'Proof submitted for admin review. Status: ' + result.status + '.';
            proofForm.querySelectorAll('input').forEach(input => { input.disabled = true; });
            if ($('walletRows')) renderWallet(await call('/api/store/wallet'));
          } catch (error) { proofStatus.textContent = error.message; proofButton.disabled = false; }
        });
      } catch (error) { status.textContent = error.message; }
      finally { button.disabled = false; }
    });
  }

  bindCrypto('binanceForm', 'binanceStatus', 'binanceResult', '/api/store/wallet/binance', 'Binance Pay', 'USD');
  bindCrypto('usdtForm', 'usdtStatus', 'usdtResult', '/api/store/wallet/usdt', 'USDT · BEP20', 'USDT', 'BNB Smart Chain (BEP20) only. Sending through another network may result in permanent loss of funds.');
  init();
})();
