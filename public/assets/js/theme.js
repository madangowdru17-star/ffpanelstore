(()=>{
  const root=document.documentElement;
  const getTheme=()=>{try{const saved=localStorage.getItem('hex_shop_theme');return saved==='dark'||saved==='light'?saved:'light'}catch{return 'light'}};
  const setTheme=value=>{root.dataset.theme=value;try{localStorage.setItem('hex_shop_theme',value)}catch{};document.querySelectorAll('.hx-theme-toggle').forEach(button=>{button.textContent=value==='dark'?'☀ Light':'◐ Theme';button.setAttribute('aria-label',value==='dark'?'Switch to light mode':'Switch to dark mode')})};
  setTheme(getTheme());
  const addThemeButton=()=>{
    if(document.querySelector('.hx-theme-toggle'))return;
    const button=document.createElement('button');button.type='button';button.className='hx-theme-toggle';button.textContent=getTheme()==='dark'?'☀ Light':'◐ Theme';button.setAttribute('aria-label',getTheme()==='dark'?'Switch to light mode':'Switch to dark mode');button.addEventListener('click',()=>setTheme(getTheme()==='dark'?'light':'dark'));
    const target=document.querySelector('.top .nav,.top .actions,.top');
    if(target){target.append(button)}else{button.style.cssText='position:fixed;right:12px;top:12px';document.body.append(button)}
  };
  const navItems=[['/','⌂','Shop'],['/wallet','◉','Wallet'],['/orders','▤','Orders'],['/keys','⚿','My Key'],['/account','●','Account']];
  const addBottomNav=()=>{
    const path=location.pathname;
    if(path.startsWith('/admin')||path==='/login'||path==='/register'||document.querySelector('.hx-bottom-nav'))return;
    const nav=document.createElement('nav');nav.className='hx-bottom-nav';nav.setAttribute('aria-label','Main navigation');
    navItems.forEach(([href,icon,label])=>{const link=document.createElement('a');link.href=href;link.innerHTML='<span class="hx-nav-icon" aria-hidden="true">'+icon+'</span><span>'+label+'</span>';const active=href==='/'?path==='/'||path.startsWith('/product/'):path===href||path.startsWith(href+'/');if(active)link.setAttribute('aria-current','page');nav.append(link)});
    document.body.append(nav);
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{addThemeButton();addBottomNav()});else{addThemeButton();addBottomNav()}
})();
