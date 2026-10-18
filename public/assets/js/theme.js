(()=>{
  const root=document.documentElement;
  const getTheme=()=>{try{const saved=localStorage.getItem('hex_shop_theme');return saved==='dark'||saved==='light'?saved:'light'}catch{return 'light'}};
  const setTheme=value=>{
    root.dataset.theme=value;
    try{localStorage.setItem('hex_shop_theme',value)}catch{}
    document.querySelectorAll('.hx-theme-toggle').forEach(button=>{
      const icon=value==='dark'?'sun':'moon';
      const label=value==='dark'?'Light theme':'Dark theme';
      button.innerHTML='<svg class="hx-icon" aria-hidden="true"><use href="/assets/icons.svg#'+icon+'"></use></svg><span>'+label+'</span>';
      button.setAttribute('aria-label',value==='dark'?'Switch to light mode':'Switch to dark mode');
    });
  };
  setTheme(getTheme());

  const addThemeButton=()=>{
    if(document.querySelector('.hx-theme-toggle'))return;
    const button=document.createElement('button');
    button.type='button';button.className='hx-theme-toggle';
    button.addEventListener('click',()=>setTheme(getTheme()==='dark'?'light':'dark'));
    const target=document.querySelector('.shop-quick-menu')||document.querySelector('.top .nav,.top .actions,.top');
    if(target)target.append(button);else{button.style.cssText='position:fixed;right:12px;top:12px';document.body.append(button)}
    setTheme(getTheme());
  };

  const navItems=[['/','home','Home'],['/add-funds','plus','Add Fund'],['/wallet','wallet','Wallet'],['/orders','receipt','Orders'],['/keys','key','My Keys'],['/account','user','Account']];
  const addBottomNav=()=>{
    const path=location.pathname;
    if(path.startsWith('/admin')||document.querySelector('.hx-bottom-nav'))return;
    const nav=document.createElement('nav');nav.className='hx-bottom-nav';nav.setAttribute('aria-label','Main navigation');
    navItems.forEach(([href,icon,label])=>{
      const link=document.createElement('a');link.href=href;
      link.innerHTML='<span class="hx-nav-icon" aria-hidden="true"><svg class="hx-icon"><use href="/assets/icons.svg#'+icon+'"></use></svg></span><span>'+label+'</span>';
      const active=href==='/'?path==='/'||path.startsWith('/product/'):path===href||path.startsWith(href+'/');
      if(active)link.setAttribute('aria-current','page');nav.append(link);
    });
    document.body.append(nav);
  };

  const bindMenu=()=>{
    const toggle=document.getElementById('shopMenuToggle'),menu=document.getElementById('shopQuickMenu'),backdrop=document.getElementById('shopMenuBackdrop');
    if(!toggle||!menu||!backdrop)return;
    if(menu.parentElement!==document.body){document.body.append(backdrop,menu)}
    const signIn=document.getElementById('menuSignIn'),signOut=document.getElementById('menuSignOut');
    const close=()=>{menu.classList.add('hidden');backdrop.classList.add('hidden');menu.setAttribute('aria-hidden','true');backdrop.setAttribute('aria-hidden','true');toggle.setAttribute('aria-expanded','false');toggle.classList.remove('is-open');document.body.classList.remove('menu-open')};
    const open=async()=>{
      menu.classList.remove('hidden');backdrop.classList.remove('hidden');menu.setAttribute('aria-hidden','false');backdrop.setAttribute('aria-hidden','false');toggle.setAttribute('aria-expanded','true');toggle.classList.add('is-open');document.body.classList.add('menu-open');
      try{const response=await fetch('/api/store/session',{credentials:'same-origin'}),data=await response.json();const name=document.getElementById('menuProfileName'),email=document.getElementById('menuProfileEmail');if(data.authenticated){name.textContent=data.account?.display_name||'Shop customer';email.textContent=data.account?.email||'';signIn?.classList.add('hidden');signOut?.classList.remove('hidden')}else{name.textContent='Your shop account';email.textContent='Sign in to see your account';if(signIn){signIn.classList.remove('hidden');signIn.href='/login?next='+encodeURIComponent(location.pathname+location.search)}signOut?.classList.add('hidden')}}catch{}
    };
    toggle.addEventListener('click',event=>{event.stopPropagation();menu.classList.contains('hidden')?open():close()});
    backdrop.addEventListener('click',close);menu.querySelectorAll('[data-menu-close]').forEach(button=>button.addEventListener('click',close));
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!menu.classList.contains('hidden')){close();toggle.focus()}});
    signOut?.addEventListener('click',async()=>{signOut.disabled=true;try{await fetch('/api/store/logout',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:'{}'})}finally{location.href='/'}});
  };

  const bindCurrency=()=>{
    const selects=[...document.querySelectorAll('[data-page-currency]')];if(!selects.length)return;
    let value='INR';try{value=localStorage.getItem('hex_shop_currency')==='USD'?'USD':'INR'}catch{}
    selects.forEach(select=>{
      select.value=value;
      select.addEventListener('change',()=>{
        const next=select.value==='USD'?'USD':'INR';
        try{localStorage.setItem('hex_shop_currency',next)}catch{}
        selects.forEach(item=>item.value=next);
        document.dispatchEvent(new CustomEvent('hex:currencychange',{detail:{currency:next}}));
      });
    });
  };

  const bindPageMotion=()=>{
    if(!document.body)return;
    const pageRoutes=new Set(['/','/add-funds','/wallet','/orders','/keys','/account','/login','/register','/help','/terms']);
    let activeRequest=null,routeSequence=0;
    const currentPage=()=>document.querySelector('.hx-page-main');
    const updateActiveTab=()=>{
      const path=['/login','/register'].includes(location.pathname)?'/account':location.pathname;
      document.querySelectorAll('.hx-bottom-nav a').forEach(link=>{
        const url=new URL(link.href,location.href),active=url.pathname==='/'?path==='/'||path.startsWith('/product/'):path===url.pathname||path.startsWith(url.pathname+'/');
        if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
      });
    };
    const activateScripts=async root=>{
      for(const oldScript of [...root.querySelectorAll('script')]){
        const script=document.createElement('script');
        for(const attribute of oldScript.attributes)script.setAttribute(attribute.name,attribute.value);
        if(oldScript.src){
          script.async=false;
          await new Promise(resolve=>{script.onload=resolve;script.onerror=resolve;document.head.append(script)});
        }else{
          script.textContent=oldScript.textContent;
          oldScript.replaceWith(script);
        }
      }
    };
    const swapPage=async(parsed,url,push,replace)=>{
      const incoming=parsed.querySelector('.hx-page-main'),existing=currentPage();
      if(!incoming||!existing)return false;
      const nextMain=document.importNode(incoming,true),nextFooter=parsed.querySelector('.footer'),footer=document.querySelector('.footer');
      const nextExtras=parsed.querySelector('#home-overlays'),currentExtras=document.getElementById('home-overlays');
      const enteringHome=Boolean(nextMain.querySelector('#catalog'));
      const leavingHome=Boolean(existing.querySelector('#catalog'))&&!enteringHome;
      const update=async()=>{
        if(leavingHome)window.__hexStorefrontCleanup?.();
        existing.replaceWith(nextMain);
        if(nextFooter&&footer)footer.replaceWith(document.importNode(nextFooter,true));
        currentExtras?.remove();
        if(enteringHome&&nextExtras)document.body.append(document.importNode(nextExtras,true));
        document.head.querySelectorAll('style[data-page-style]').forEach(style=>style.remove());
        parsed.head.querySelectorAll('style[data-page-style]').forEach(style=>{
          const copy=document.importNode(style,true),themeLink=document.querySelector('link[href*="/assets/css/theme.css"]');
          if(themeLink)document.head.insertBefore(copy,themeLink);else document.head.append(copy);
        });
        document.title=parsed.title||document.title;
        const themeColors=parsed.querySelectorAll('meta[name="theme-color"]'),themeColor=themeColors[themeColors.length-1],currentThemeColor=document.querySelector('meta[name="theme-color"]');
        if(themeColor&&currentThemeColor)currentThemeColor.content=themeColor.content;
        if(push)history.pushState({hexPage:true},'',url.href);
        else if(replace)history.replaceState({hexPage:true},'',url.href);
        updateActiveTab();
        await activateScripts(nextMain);
        if(enteringHome){
          const homeScript=[...parsed.querySelectorAll('script[src]')].find(script=>new URL(script.src,url.href).pathname==='/storefront.js');
          if(homeScript){const script=document.createElement('script');for(const attribute of homeScript.attributes)script.setAttribute(attribute.name,attribute.value);script.async=false;await new Promise(resolve=>{script.onload=resolve;script.onerror=resolve;document.body.append(script)})}
        }
      };
      if(document.startViewTransition&&!matchMedia('(prefers-reduced-motion: reduce)').matches){
        const transition=document.startViewTransition(update);await transition.updateCallbackDone;
      }else{
        existing.classList.add('route-leaving');
        await new Promise(resolve=>setTimeout(resolve,matchMedia('(prefers-reduced-motion: reduce)').matches?0:75));
        await update();nextMain.classList.add('route-entering');
        requestAnimationFrame(()=>nextMain.classList.remove('route-entering'));
      }
      if(url.hash){const target=document.getElementById(decodeURIComponent(url.hash.slice(1)));target?.scrollIntoView({behavior:'smooth',block:'start'})}
      else window.scrollTo({top:0,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
      document.dispatchEvent(new CustomEvent('hex:pagechange',{detail:{url:url.href}}));
      return true;
    };
    const navigate=async(url,{push=true,replace=false}={})=>{
      if(url.origin!==location.origin||!pageRoutes.has(url.pathname)||!currentPage())return false;
      activeRequest?.abort();const controller=new AbortController();activeRequest=controller;const sequence=++routeSequence;
      document.body.classList.add('route-loading');
      try{
        const response=await fetch(url.href,{credentials:'same-origin',headers:{'Accept':'text/html'},signal:controller.signal});
        if(!response.ok)throw new Error('Page request failed');
        const html=await response.text(),parsed=new DOMParser().parseFromString(html,'text/html');
        if(sequence!==routeSequence)return true;
        if(!(await swapPage(parsed,url,push,replace)))return false;
        return true;
      }catch(error){if(error.name==='AbortError')return true;return false}
      finally{if(sequence===routeSequence){activeRequest=null;document.body.classList.remove('route-loading')}}
    };
    window.hxGo=(target,{replace=false}={})=>{
      const url=new URL(target,location.href);
      return navigate(url,{push:!replace,replace}).then(done=>{
        if(!done){if(replace)location.replace(url.href);else location.assign(url.href)}
        return done;
      });
    };
    document.addEventListener('click',event=>{
      const button=event.target.closest('[data-toggle-pass]');
      if(!button)return;
      const input=button.closest('.hx-pass')?.querySelector('input');
      if(!input)return;
      const visible=input.type==='password';input.type=visible?'text':'password';
      button.textContent=visible?'Hide':'Show';
      button.setAttribute('aria-label',visible?'Hide password':'Show password');
      button.setAttribute('aria-pressed',String(visible));
    });
    document.addEventListener('click',async event=>{
      if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
      const link=event.target.closest('a[href]');
      if(!link||link.target||link.hasAttribute('download')||link.getAttribute('rel')?.includes('external'))return;
      let url;try{url=new URL(link.href,location.href)}catch{return}
      if(url.origin!==location.origin||!pageRoutes.has(url.pathname)||!currentPage()||(url.pathname===location.pathname&&url.search===location.search))return;
      event.preventDefault();
      const done=await navigate(url);
      if(!done)location.assign(url.href);
    });
    window.addEventListener('popstate',async()=>{
      const url=new URL(location.href),done=await navigate(url,{push:false});
      if(!done)location.reload();
    });
    updateActiveTab();
  };

  const bindCardTilt=()=>{
    if(!matchMedia('(hover: hover) and (pointer: fine)').matches||matchMedia('(prefers-reduced-motion: reduce)').matches)return;
    document.addEventListener('pointermove',event=>{
      const card=event.target.closest('.reference-product');if(!card)return;
      const rect=card.getBoundingClientRect(),x=(event.clientX-rect.left)/rect.width-.5,y=(event.clientY-rect.top)/rect.height-.5;
      card.style.setProperty('--tilt-x',`${(-y*3.2).toFixed(2)}deg`);
      card.style.setProperty('--tilt-y',`${(x*4.2).toFixed(2)}deg`);
      card.classList.add('is-tilting');
    },{passive:true});
    document.addEventListener('pointerout',event=>{
      const card=event.target.closest('.reference-product');if(!card||card.contains(event.relatedTarget))return;
      card.classList.remove('is-tilting');card.style.removeProperty('--tilt-x');card.style.removeProperty('--tilt-y');
    },{passive:true});
  };

  const init=()=>{addThemeButton();addBottomNav();bindMenu();bindCurrency();bindPageMotion();bindCardTilt()};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
