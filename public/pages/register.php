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
  <a class="hx-button hx-google" href="<?=htmlspecialchars($googleUrl,ENT_QUOTES,'UTF-8')?>"><svg class="hx-g" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.2 5.5-4.7 7.2l7.6 5.9c4.4-4.1 6.9-10.1 6.9-17.6z"/><path fill="#FBBC05" d="M10.5 28.7a14.5 14.5 0 010-9.4l-7.9-6.1a24 24 0 000 21.6l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2.1 1.4-4.9 2.3-8.3 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg><span>Continue with Google</span></a>
  <div class="hx-divider"><span>or use email</span></div>
  <form id="registerForm" class="hx-form" novalidate>
    <label for="registerName">Display name<input id="registerName" type="text" name="display_name" autocomplete="name" maxlength="100"></label>
    <label for="registerEmail">Email<input id="registerEmail" type="email" name="email" autocomplete="email" required maxlength="254"></label>
    <label for="registerPassword"><span>Password</span><span class="hx-pass"><input id="registerPassword" type="password" name="password" autocomplete="new-password" required maxlength="256" minlength="10"><button class="hx-eye" type="button" data-toggle-pass aria-label="Show password" aria-pressed="false">Show</button></span><small>Use at least 10 characters.</small></label>
    <button class="hx-button" type="submit"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#user"></use></svg> Create account</button>
    <div class="hx-alert" id="authStatus" role="alert" aria-live="polite"></div>
  </form>
  <p class="hx-muted">Already registered? <a href="<?=htmlspecialchars($loginUrl,ENT_QUOTES,'UTF-8')?>">Sign in</a></p>
</section>
<script>
(()=>{const form=document.getElementById('registerForm'),status=document.getElementById('authStatus'),next=<?= $nextJson ?>;form.addEventListener('submit',async event=>{event.preventDefault();status.textContent='';if(!form.reportValidity())return;const button=form.querySelector('button[type="submit"]');button.disabled=true;button.classList.add('is-loading');try{const values=new FormData(form),response=await fetch('/api/store/register',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({display_name:String(values.get('display_name')||'').trim(),email:String(values.get('email')||'').trim(),password:String(values.get('password')||'')})});let data={};try{data=await response.json()}catch{}if(!response.ok)throw new Error(data.error||data.message||`Registration failed (${response.status}).`);(window.hxGo?window.hxGo(next):location.assign(next))}catch(error){status.textContent=error.message||'Could not connect. Please try again.'}finally{button.disabled=false;button.classList.remove('is-loading')}})})();
</script>
<?php require __DIR__.'/_foot.php'; ?>
