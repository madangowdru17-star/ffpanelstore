<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#10182a">
<title>HEX CHEATS SHOP | Admin sign in</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#eef3ff;background:#081025;--blue:#3478f6;--line:#29375a;--muted:#99a7c2;--panel:#101a35}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:block;padding:1px 0 30px;background:radial-gradient(ellipse at 50% 0,#dcebdd,transparent 55%),#faf8ef}.card{width:min(100%,430px);margin:42px auto 0;border:1px solid var(--line);border-radius:21px;padding:28px;background:linear-gradient(155deg,#121f40,#0d1730);box-shadow:0 24px 90px #0008}.brand{font-weight:900;letter-spacing:.05em;font-size:12px;color:#8db7ff}.mark{display:inline-grid;place-items:center;width:34px;height:34px;border-radius:10px;background:#214a9b;color:#fff;margin-right:8px}h1{font-size:27px;letter-spacing:-.05em;margin:22px 0 6px}.sub{color:var(--muted);font-size:12px;line-height:1.65;margin:0 0 22px}.field{margin:13px 0}.field label{display:block;font-size:11px;font-weight:750;margin-bottom:7px}.field input{width:100%;border:1px solid #35466e;border-radius:10px;background:#09132c;color:#eef3ff;padding:12px 13px;font:inherit;font-size:13px}.field input:focus{outline:2px solid #3478f640;border-color:#6397ff}.button{width:100%;border:0;border-radius:10px;background:var(--blue);color:#fff;font-weight:850;padding:12px;margin-top:15px;cursor:pointer}.button:disabled{opacity:.6}.error{min-height:19px;color:#ff9ba5;font-size:11px;margin-top:11px}.home{display:inline-block;margin-top:17px;color:#c8d5ef;text-decoration:none;font-size:11px}.legacy{margin-top:19px;border-top:1px solid #ffffff18;padding-top:14px}.legacy summary{font-size:11px;color:#adbbd5;cursor:pointer}.legacy .field{margin-top:12px}.hint{font-size:10px;color:var(--muted);line-height:1.5}
</style></head>
<body class="admin-page"><div class="wrap hx-page-wrap"><?php require __DIR__ . '/pages/_nav.php'; ?></div><main class="card">
<p class="hx-eyebrow">ADMIN ACCESS</p><h1>Admin sign in</h1>
<p class="sub">Use your administrator email and password. Your password is checked against a hash stored in the shop database.</p>
<form id="loginForm"><div class="field"><label for="email">Administrator email</label><input id="email" type="email" maxlength="254" autocomplete="username" required autofocus></div>
<div class="field"><label for="password">Password</label><input id="password" type="password" maxlength="256" autocomplete="current-password" required></div>
<details class="legacy"><summary>Use existing administrator key instead</summary><div class="field"><label for="key">Legacy admin key</label><input id="key" type="password" autocomplete="current-password"></div><div class="hint">Temporary compatibility for installations that have not yet set the new admin environment variables.</div></details>
<button class="button" id="submit" type="submit">Sign in</button><div class="error" id="error" role="alert"></div></form>
<a class="home" href="/"><svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#chevron-left"></use></svg> Back to the shop</a></main>
<script>
const form=document.getElementById('loginForm');
form.addEventListener('submit',async event=>{
  event.preventDefault();const button=document.getElementById('submit'),error=document.getElementById('error');button.disabled=true;error.textContent='';
  const key=document.getElementById('key').value;const payload=key?{key}:{email:document.getElementById('email').value.trim(),password:document.getElementById('password').value};
  try{const response=await fetch('/admin/api/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),credentials:'same-origin'});let data={};try{data=await response.json()}catch{}if(!response.ok)throw new Error(data.error||'Sign in failed.');location.href='/admin/catalog'}
  catch(e){error.textContent=e.message||'Sign in failed.'}finally{button.disabled=false}
});
</script></body></html>
