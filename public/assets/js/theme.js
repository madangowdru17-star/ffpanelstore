(()=>{
  const root=document.documentElement;
  const getTheme=()=>{try{const saved=localStorage.getItem('hex_shop_theme');return saved==='dark'||saved==='light'?saved:'light'}catch{return 'light'}};
  const setTheme=value=>{
    root.dataset.theme=value;
    try{localStorage.setItem('hex_shop_theme',value)}catch{}
    document.querySelectorAll('.hx-theme-toggle').forEach(button=>{
      button.textContent=value==='dark'?'☀ Light':'◐ Theme';
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

  const navItems=[['/','⌂','Home'],['/add-funds','▰','Add Fund'],['/orders','▣','Order'],['/keys','♙','My Key'],['/account','●','Account']];
  const addBottomNav=()=>{
    const path=location.pathname;
    if(path.startsWith('/admin')||path==='/login'||path==='/register'||document.querySelector('.hx-bottom-nav'))return;
    const nav=document.createElement('nav');nav.className='hx-bottom-nav';nav.setAttribute('aria-label','Main navigation');
    navItems.forEach(([href,icon,label])=>{
      const link=document.createElement('a');link.href=href;
      link.innerHTML='<span class="hx-nav-icon" aria-hidden="true">'+icon+'</span><span>'+label+'</span>';
      const active=href==='/'?path==='/'||path.startsWith('/product/'):path===href||path.startsWith(href+'/');
      if(active)link.setAttribute('aria-current','page');nav.append(link);
    });
    document.body.append(nav);
  };

  const bindMenu=()=>{
    const toggle=document.getElementById('shopMenuToggle'),menu=document.getElementById('shopQuickMenu');
    if(!toggle||!menu)return;
    toggle.addEventListener('click',event=>{
      event.stopPropagation();const open=menu.classList.toggle('hidden');
      toggle.setAttribute('aria-expanded',String(!open));toggle.classList.toggle('is-open',!open);
    });
    document.addEventListener('click',event=>{
      if(!event.target.closest('#shopQuickMenu,#shopMenuToggle')){
        menu.classList.add('hidden');toggle.setAttribute('aria-expanded','false');toggle.classList.remove('is-open');
      }
    });
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
    document.body.classList.add('page-enter');
    requestAnimationFrame(()=>requestAnimationFrame(()=>document.body.classList.add('page-ready')));
    if(matchMedia('(prefers-reduced-motion: reduce)').matches)return;
    document.addEventListener('click',event=>{
      const link=event.target.closest('a[href]');
      if(!link||event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||link.target||link.hasAttribute('download'))return;
      let url;try{url=new URL(link.href,location.href)}catch{return}
      if(url.origin!==location.origin||(url.pathname===location.pathname&&url.search===location.search))return;
      event.preventDefault();document.body.classList.add('page-exit');
      setTimeout(()=>location.assign(url.href),145);
    });
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
