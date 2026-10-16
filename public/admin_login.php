<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0f16">
<title>HEX CHEATS SHOP | Admin sign in</title>
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:#eef4f6;background:#0b0f16;--lime:#b7ff4a;--line:#273443;--muted:#9aa8b5;--panel:#111923}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:radial-gradient(ellipse at 50% 0,#23402a55,transparent 50%),#0b0f16}.card{width:min(100%,420px);border:1px solid var(--line);border-radius:20px;padding:28px;background:linear-gradient(155deg,#151f2a,#0d141d);box-shadow:0 24px 90px #0008}.brand{font-weight:900;letter-spacing:.05em;font-size:12px;color:var(--lime)}h1{font-size:28px;letter-spacing:-.05em;margin:24px 0 6px}.sub{color:var(--muted);font-size:12px;line-height:1.6;margin:0 0 22px}.field label{display:block;font-size:11px;font-weight:750;margin-bottom:7px}.field input{width:100%;border:1px solid #354351;border-radius:10px;background:#090f16;color:#eef4f6;padding:12px 13px;font:inherit;font-size:13px}.field input:focus{outline:2px solid #b7ff4a30;border-color:#b7ff4a}.button{width:100%;border:0;border-radius:10px;background:var(--lime);color:#10170a;font-weight:850;padding:12px;margin-top:15px;cursor:pointer}.button:disabled{opacity:.6}.error{min-height:19px;color:#ff8990;font-size:11px;margin-top:11px}.home{display:inline-block;margin-top:14px;color:#bdc8d0;text-decoration:none;font-size:11px}.home:hover{color:var(--lime)}
</style>
</head>
<body>
<main class="card">
  <div class="brand">HX &nbsp; HEX CHEATS SHOP</div>
  <h1>Admin sign in</h1>
  <p class="sub">Use the website administrator key configured in Railway to manage products, prices, and images.</p>
  <form id="loginForm">
    <div class="field"><label for="key">Administrator key</label><input id="key" type="password" autocomplete="current-password" minlength="24" required autofocus></div>
    <button class="button" id="submit" type="submit">Sign in</button>
    <div class="error" id="error" role="alert"></div>
  </form>
  <a class="home" href="/">← Back to the shop</a>
</main>
<script>
const form=document.getElementById('loginForm');
form.addEventListener('submit',async event=>{
  event.preventDefault();
  const button=document.getElementById('submit'),error=document.getElementById('error');
  button.disabled=true;error.textContent='';
  try{
    const response=await fetch('/admin/api/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({key:document.getElementById('key').value})});
    const raw=await response.text();let data={};try{data=JSON.parse(raw)}catch{data={error:raw}}
    if(!response.ok)throw new Error(data.error||data.message||'Sign in failed.');
    localStorage.setItem('hex_admin_token',data.token);
    location.href='/admin/catalog';
  }catch(e){error.textContent=e.message}
  finally{button.disabled=false}
});
</script>
</body>
</html>
