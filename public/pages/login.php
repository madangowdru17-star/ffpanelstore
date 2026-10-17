<?php
$pageTitle = 'Sign in | HEX CHEATS SHOP';
$activePage = 'account';
$next = (string) ($_GET['next'] ?? '/account');
if ($next === '' || strlen($next) > 2000 || !str_starts_with($next, '/') || str_starts_with($next, '//')
    || str_contains($next, '\\') || preg_match('/[\x00-\x20\x7f]/', $next)
    || preg_match('#^/(?:login|register)(?:[/?#]|$)#i', $next)) {
    $next = '/account';
}
$nextJson = json_encode($next, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$googleUrl = '/auth/google?next=' . rawurlencode($next);
$registerUrl = '/register?next=' . rawurlencode($next);
require __DIR__ . '/_head.php';
$messages = [
    'not_configured' => 'Google sign-in is not configured yet. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in Railway variables.',
    'failed' => 'Google sign-in did not complete. Please try again.',
    'expired' => 'That sign-in link expired. Start again.',
    'invalid' => 'Google could not verify this sign-in. Please retry.',
];
$googleMessage = $messages[(string) ($_GET['google'] ?? '')] ?? '';
?>
<section class="hx-panel hx-auth-panel hx-login-panel">
  <p class="hx-eyebrow">YOUR HEX ACCOUNT</p>
  <h1>Sign in</h1>
  <p class="hx-muted">Access your wallet, orders and delivered keys.</p>
  <?php if ($googleMessage !== ''): ?><div class="hx-alert" role="status"><?= htmlspecialchars($googleMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <a class="hx-button hx-google" href="<?= htmlspecialchars($googleUrl, ENT_QUOTES, 'UTF-8') ?>"><span aria-hidden="true">G</span> Continue with Google</a>
  <div class="hx-divider"><span>or use email</span></div>
  <form id="loginForm" class="hx-form" novalidate>
    <label for="loginEmail">Email<input id="loginEmail" type="email" name="email" autocomplete="email" required maxlength="254"></label>
    <label for="loginPassword">Password<input id="loginPassword" type="password" name="password" autocomplete="current-password" required maxlength="256"></label>
    <button class="hx-button" type="submit"><span class="login-button-icon" aria-hidden="true">↪</span> Sign in</button>
    <div class="hx-alert" id="authStatus" role="alert" aria-live="polite"></div>
  </form>
  <p class="hx-muted">New here? <a href="<?= htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') ?>">Create an account</a></p>
</section>
<script>
(()=>{
  const form=document.getElementById('loginForm'),status=document.getElementById('authStatus'),next=<?= $nextJson ?>;
  form.addEventListener('submit',async event=>{
    event.preventDefault();status.textContent='';
    if(!form.reportValidity())return;
    const button=form.querySelector('button[type="submit"]');button.disabled=true;button.classList.add('is-loading');
    try{
      const values=new FormData(form);
      const response=await fetch('/api/store/login',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({email:String(values.get('email')||'').trim(),password:String(values.get('password')||'')})});
      let data={};try{data=await response.json()}catch{}
      if(!response.ok)throw new Error(data.error||data.message||`Sign in failed (${response.status}).`);
      window.location.assign(next);
    }catch(error){status.textContent=error.message||'Could not connect. Please check your connection and try again.'}
    finally{button.disabled=false;button.classList.remove('is-loading')}
  });
  fetch('/api/store/session',{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(response=>response.ok?response.json():null).then(data=>{if(data?.authenticated)window.location.replace(next)}).catch(()=>{});
})();
</script>
<?php require __DIR__ . '/_foot.php'; ?>
