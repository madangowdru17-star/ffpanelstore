<?php
$method = strtolower((string) ($_GET['method'] ?? ''));
$paymentMethods = [
    'upi' => ['name' => 'UPI', 'logo' => '/assets/brands/google-pay-mark.svg', 'unit' => 'INR', 'currency' => '₹', 'min' => '1', 'max' => '2000', 'step' => '1', 'value' => '500', 'label' => 'Amount in INR', 'hint' => '₹1–₹2,000 · automatic provider confirmation'],
    'binance' => ['name' => 'Binance Pay', 'logo' => '/assets/brands/binance-pay.png', 'unit' => 'USD', 'currency' => '$', 'min' => '0.52', 'max' => '100', 'step' => '0.01', 'value' => '5.00', 'label' => 'Amount in USD', 'hint' => '$0.52–$100 · transaction ID and screenshot review'],
    'usdt' => ['name' => 'USDT · BEP20', 'logo' => '/assets/brands/usdt-tether-mark.svg', 'unit' => 'USDT', 'currency' => '', 'min' => '0.52', 'max' => '100', 'step' => '0.01', 'value' => '5.00', 'label' => 'Amount in USDT', 'hint' => '0.52–100 USDT · BNB Smart Chain (BEP20) only'],
];
if (!isset($paymentMethods[$method])) {
    http_response_code(404);
    $method = 'upi';
}
$payment = $paymentMethods[$method];
$pageTitle = 'Pay with ' . $payment['name'] . ' | HEX CHEATS SHOP';
$activePage = 'wallet';
require __DIR__ . '/_head.php';
?>
<section class="hx-page-heading" id="topupPageHeading"><p class="hx-eyebrow">SECURE TOP-UP</p><h1>Enter your amount</h1><p class="hx-muted">We verify your signed-in session and validate the amount before creating a payment request. Your QR and exact payment instructions appear after that check.</p></section>
<nav class="hx-payment-steps" id="topupSteps" aria-label="Payment steps"><span class="is-done" data-payment-step="0"><b>1</b><small>Choose method</small></span><span class="is-current" data-payment-step="1"><b>2</b><small>Enter amount</small></span><span data-payment-step="2"><b>3</b><small>Verify &amp; pay</small></span></nav>
<section class="hx-pay-layout" data-topup-method="<?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?>">
  <div class="hx-panel hx-pay-form-panel">
    <div class="hx-pay-panel-title" id="topupFormHeading"><span class="hx-pay-title-number">2</span><div><h2>Payment amount</h2><p class="hx-muted">Choose the exact amount you intend to send.</p></div></div>
    <form class="hx-form hx-topup-form" id="topupForm" novalidate>
      <label for="topupAmount"><?= htmlspecialchars($payment['label'], ENT_QUOTES, 'UTF-8') ?></label>
      <div class="hx-amount-control"><?php if ($payment['currency'] !== ''): ?><span aria-hidden="true"><?= htmlspecialchars($payment['currency'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?><input id="topupAmount" name="amount" type="number" inputmode="decimal" min="<?= htmlspecialchars($payment['min'], ENT_QUOTES, 'UTF-8') ?>" max="<?= htmlspecialchars($payment['max'], ENT_QUOTES, 'UTF-8') ?>" step="<?= htmlspecialchars($payment['step'], ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($payment['value'], ENT_QUOTES, 'UTF-8') ?>" required aria-describedby="amountHint"></div>
      <small id="amountHint" class="hx-field-hint"><?= htmlspecialchars($payment['hint'], ENT_QUOTES, 'UTF-8') ?></small>
      <?php if ($method === 'usdt'): ?><div class="hx-network-warning"><strong>Network check:</strong> Send USDT over BNB Smart Chain (BEP20) only. Transfers on another network may be permanently lost.</div><?php endif; ?>
      <button class="hx-button hx-create-payment" id="createPayment" type="submit" disabled><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#lock"></use></svg><span>Verify amount &amp; continue</span></button>
      <div class="hx-alert" id="paymentError" role="alert"></div>
    </form>
    <section class="hx-security-progress" id="securityProgress" hidden aria-live="polite">
      <span class="hx-security-badge"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#shield-check"></use></svg></span>
      <div class="hx-security-copy"><strong id="securityTitle">Verifying your secure session</strong><p id="securityDetail">Checking your sign-in and payment request.</p></div>
      <ol class="hx-security-checks"><li data-security-step="0"><span></span>Account session</li><li data-security-step="1"><span></span>Amount validation</li><li data-security-step="2"><span></span>Payment details</li></ol>
    </section>
    <div id="paymentResult" aria-live="polite"></div>
  </div>
  <aside class="hx-panel hx-pay-method-summary"><div class="hx-pay-method-logo"><img src="<?= htmlspecialchars($payment['logo'], ENT_QUOTES, 'UTF-8') ?>" alt=""></div><p class="hx-eyebrow">SELECTED PAYMENT</p><h2><?= htmlspecialchars($payment['name'], ENT_QUOTES, 'UTF-8') ?></h2><p class="hx-muted"><?= $method === 'upi' ? 'Your wallet is credited only after the payment provider confirms the transaction.' : 'Your wallet credit stays pending until an administrator checks your transaction ID and screenshot.' ?></p><div class="hx-pay-summary-note"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#check"></use></svg><span>Secure session · exact amount · verified status</span></div><a class="hx-text-link" href="/add-funds"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#chevron-left"></use></svg>Change payment method</a></aside>
</section>
<script src="/assets/js/topup-flow.js?v=9" defer></script>
<?php require __DIR__ . '/_foot.php'; ?>
