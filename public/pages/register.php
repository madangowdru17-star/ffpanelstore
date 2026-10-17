<?php
$pageTitle='Create account | HEX CHEATS SHOP';
$activePage='account';
$next=(string)($_GET['next']??'/account');
if($next===''||strlen($next)>2000||!str_starts_with($next,'/')||str_starts_with($next,'//')||str_contains($next,'\\')||preg_match('/[\x00-\x20\x7f]/',$next)||preg_match('~^/(?:login|register)(?:[/?#]|$)~i',$next))$next='/account';
$nextJson=json_encode($next,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$googleUrl='/auth/google?next='.rawurlencode($next);
$loginUrl='/login?next='.rawurlencode($next);
require __DIR__.'/_head.php';
?>
<section class="hx-panel hx-auth-panel hx-login-panel">
  <p class="hx-eyebrow">CREATE YOUR ACCOUNT</p><h1>Join HEX CHEATS SHOP</h1>
  <p class="hx-muted">Keep your wallet, payment history, orders and delivered keys together.</p>
  <a class="hx-button hx-google" href="<?=htmlspecialchars($googleUrl,ENT_QUOTES,'UTF-8')?>">Continue with Google</a>
  <div class="hx-divider"><span>or use email</span></div>
  <form id="registerForm" class="hx-form" novalidate>
    <label for="registerName">Display name<input id="registerName" type="text" name="display_name" autocomplete="name" maxlength="100"></label>
    <label for="registerEmail">Email<input id="registerEmail" type="email" name="email" autocomplete="email" required maxlength="254"></label>
    <label for="registerPassword">Password<input id="registerPassword" type="password" name="password" autocomplete="new-password" minlength="10" maxlength="256" required><small>Use at least 10 characters.</small></label>
    <button class="hx-button" type="submit"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#user"></use></svg> Create account</button>
    <div class="hx-alert" id="authStatus" role="alert" aria-live="polite"></div>
  </form>
  <p class="hx-muted">Already registered? <a href="<?=htmlspecialchars($loginUrl,ENT_QUOTES,'UTF-8')?>">Sign in</a></p>
</section>
<script>
(()=>{const form=document.getElementById('registerForm'),status=document.getElementById('authStatus'),next=<?= $nextJson ?>;form.addEventListener('submit',async event=>{event.preventDefault();status.textContent='';if(!form.reportValidity())return;const button=form.querySelector('button[type="submit"]');button.disabled=true;button.classList.add('is-loading');try{const values=new FormData(form),response=await fetch('/api/store/register',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({display_name:String(values.get('display_name')||'').trim(),email:String(values.get('email')||'').trim(),password:String(values.get('password')||'')})});let data={};try{data=await response.json()}catch{}if(!response.ok)throw new Error(data.error||data.message||`Registration failed (${response.status}).`);window.location.assign(next)}catch(error){status.textContent=error.message||'Could not connect. Please try again.'}finally{button.disabled=false;button.classList.remove('is-loading')}})})();
</script>
<?php require __DIR__.'/_foot.php'; ?>
