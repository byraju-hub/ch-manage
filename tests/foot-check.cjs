const { spawn } = require('child_process'); const path=require('path'),fs=require('fs');
const out=process.argv[2]; fs.mkdirSync(out,{recursive:true}); const H='http://127.0.0.1:8090',PORT=9402; const sleep=ms=>new Promise(r=>setTimeout(r,ms));
let pass=0,fail=0; const ok=(n,c,x='')=>{c?pass++:fail++;console.log((c?'OK   ':'FAIL ')+n+(c?'':' '+JSON.stringify(x).slice(0,300)))};
(async()=>{
 const proc=spawn((process.env.CM_BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'),['--headless=new','--disable-gpu',`--remote-debugging-port=${PORT}`,`--user-data-dir=${path.resolve(out,'p')}`,'--no-first-run','about:blank'],{stdio:'ignore'});
 let t;for(let i=0;i<40;i++){try{t=await(await fetch(`http://127.0.0.1:${PORT}/json`)).json();if(t.find(x=>x.type==='page'))break}catch{}await sleep(300)}
 const ws=new WebSocket(t.find(x=>x.type==='page').webSocketDebuggerUrl);await new Promise(r=>ws.onopen=r);
 let id=0;const pend=new Map();const errs=[];ws.onmessage=m=>{const d=JSON.parse(m.data);if(d.id&&pend.has(d.id)){pend.get(d.id)(d.result??d.error);pend.delete(d.id)}if(d.method==='Runtime.exceptionThrown')errs.push(d.params.exceptionDetails.text)};
 const send=(method,params={})=>new Promise(r=>{const i=++id;pend.set(i,r);ws.send(JSON.stringify({id:i,method,params}))});
 const ev=async js=>(await send('Runtime.evaluate',{expression:js,awaitPromise:true,returnByValue:true})).result?.value;
 const setVal=(sel,v)=>ev(`(()=>{const i=document.querySelector(${JSON.stringify(sel)});i.value=${JSON.stringify(v)};i.dispatchEvent(new Event('input',{bubbles:true}));})()`);
 const foot=`(()=>{const c=document.querySelector('.foot-copy');const a=c.querySelector('.priv-link');const sp=c.querySelector('span').getBoundingClientRect();const ar=a.getBoundingClientRect();const f=document.querySelector('.site-footer').getBoundingClientRect();return {n:document.querySelectorAll('.priv-link').length,inNav:!!document.querySelector('.site-header .priv-link'),t:a.textContent,href:a.getAttribute('href'),under:ar.top>=sp.bottom-1,sameRight:Math.abs(ar.right-sp.right)<3||ar.right>=sp.right-3,inFooter:ar.bottom<=f.bottom+1,fs:getComputedStyle(a).fontSize,vis:ar.width>0,right:Math.round(innerWidth-ar.right),sw:document.documentElement.scrollWidth<=innerWidth+1}})()`;
 await send('Page.enable');await send('Runtime.enable');await send('Emulation.setDeviceMetricsOverride',{width:1400,height:900,deviceScaleFactor:1,mobile:false});
 await send('Page.navigate',{url:H+'/cm/'});await sleep(2500);
 let f=await ev(foot);ok('로그인 화면: 카피라이트 아래에 링크, 상단에는 없음',f.n===1&&!f.inNav&&f.t==='개인정보 처리방침'&&f.href==='/cm/privacy'&&f.under&&f.inFooter&&f.vis,f);
 await setVal('input[autocomplete="username"]','admin');await setVal('input[type="password"]','password1');
 await ev("document.querySelector('form button[type=submit]').click()");await sleep(3500);
 await ev('scrollTo(0,document.body.scrollHeight)');await sleep(500);
 f=await ev(foot);ok('로그인 후: 맨 아래 오른쪽 카피라이트 밑, 상단 메뉴에는 없음',f.n===1&&!f.inNav&&f.under&&f.inFooter&&f.vis&&parseFloat(f.fs)<=12,f);
 console.log(JSON.stringify(f));
 ok('메뉴바에 가로 넘침·겹침 없음(1400)',await ev(`document.documentElement.scrollWidth<=innerWidth+1&&!document.querySelector('.nav-tools .priv-link')`));
 await ev(`document.querySelector('.foot-copy .priv-link').click()`);await sleep(1800);
 ok('누르면 방침 화면',(await ev('location.pathname'))==='/cm/privacy'&&(await ev(`document.querySelector('h1')?.textContent`))==='개인정보 처리방침');
 await send('Emulation.setDeviceMetricsOverride',{width:390,height:800,deviceScaleFactor:1,mobile:true});
 await send('Page.navigate',{url:H+'/cm/'});await sleep(2800);await ev('scrollTo(0,document.body.scrollHeight)');await sleep(500);
 f=await ev(foot);ok('휴대폰: 링크가 보이고 가로 넘침 없음',f.vis&&f.sw&&f.inFooter,f);
 ok('스크립트 오류 없음',errs.length===0,errs);
 console.log(`\n통과 ${pass} / 실패 ${fail}`);proc.kill();process.exit(fail?1:0)})();
