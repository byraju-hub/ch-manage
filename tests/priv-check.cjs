const { spawn } = require('child_process'); const path=require('path'),fs=require('fs');
const out=process.argv[2]; fs.mkdirSync(out,{recursive:true}); const H='http://127.0.0.1:8090',PORT=9401; const sleep=ms=>new Promise(r=>setTimeout(r,ms));
let pass=0,fail=0; const ok=(n,c,x='')=>{c?pass++:fail++;console.log((c?'OK   ':'FAIL ')+n+(c?'':' '+JSON.stringify(x).slice(0,300)))};
(async()=>{
 const proc=spawn((process.env.CM_BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'),['--headless=new','--disable-gpu',`--remote-debugging-port=${PORT}`,`--user-data-dir=${path.resolve(out,'p')}`,'--no-first-run','about:blank'],{stdio:'ignore'});
 let t;for(let i=0;i<40;i++){try{t=await(await fetch(`http://127.0.0.1:${PORT}/json`)).json();if(t.find(x=>x.type==='page'))break}catch{}await sleep(300)}
 const ws=new WebSocket(t.find(x=>x.type==='page').webSocketDebuggerUrl);await new Promise(r=>ws.onopen=r);
 let id=0;const pend=new Map();const errs=[];ws.onmessage=m=>{const d=JSON.parse(m.data);if(d.id&&pend.has(d.id)){pend.get(d.id)(d.result??d.error);pend.delete(d.id)}if(d.method==='Runtime.exceptionThrown')errs.push(d.params.exceptionDetails.text)};
 const send=(method,params={})=>new Promise(r=>{const i=++id;pend.set(i,r);ws.send(JSON.stringify({id:i,method,params}))});
 const ev=async js=>(await send('Runtime.evaluate',{expression:js,awaitPromise:true,returnByValue:true})).result?.value;
 const setVal=(sel,v)=>ev(`(()=>{const i=document.querySelector(${JSON.stringify(sel)});i.value=${JSON.stringify(v)};i.dispatchEvent(new Event('input',{bubbles:true}));})()`);
 await send('Page.enable');await send('Runtime.enable');await send('Emulation.setDeviceMetricsOverride',{width:1400,height:900,deviceScaleFactor:1,mobile:false});
 // 로그인 전
 await send('Page.navigate',{url:H+'/cm/'});await sleep(2500);
 const l0=await ev(`(()=>{const a=document.querySelector('.priv-link');if(!a)return null;const r=a.getBoundingClientRect();return {t:a.textContent,href:a.getAttribute('href'),right:Math.round(innerWidth-r.right),fs:getComputedStyle(a).fontSize,top:Math.round(r.top)}})()`);
 ok('로그인 화면 맨 위 오른쪽에 작은 [개인정보 처리방침] 링크',l0&&l0.t==='개인정보 처리방침'&&l0.href==='/cm/privacy'&&parseFloat(l0.fs)<=12.5&&l0.top<90,l0);
 // 링크 눌러 이동 (로그인 안 한 채)
 await ev(`document.querySelector('.priv-link').click()`);await sleep(1800);
 const pg=await ev(`({p:location.pathname,h1:document.querySelector('h1')?.textContent,secs:document.querySelectorAll('section').length,church:document.querySelector('.pv-lead b')?.textContent,login:!!document.querySelector('input[type=password]'),sw:document.documentElement.scrollWidth<=innerWidth+1})`);
 ok('로그인 없이 방침 화면이 열림',pg.p==='/cm/privacy'&&pg.h1==='개인정보 처리방침'&&pg.secs===14&&!pg.login,pg);
 console.log('교회 이름 표시:',pg.church);
 ok('방침 화면 가로 넘침 없음',pg.sw,pg);
 // 새로고침(주소 직접 열기)
 await send('Page.navigate',{url:H+'/cm/privacy'});await sleep(2200);
 ok('주소로 바로 열어도 열림(새로고침)',(await ev(`document.querySelector('h1')?.textContent`))==='개인정보 처리방침');
 ok('돌아가기 링크',(await ev(`document.querySelector('.pv-brand').getAttribute('href')`))==='/cm/');
 // 로그인 후
 await send('Page.navigate',{url:H+'/cm/'});await sleep(2500);
 await setVal('input[autocomplete="username"]','admin');await setVal('input[type="password"]','password1');
 await ev("document.querySelector('form button[type=submit]').click()");await sleep(3500);
 for(const w of [1500,1320,1190,1100,1020,970]){
  await send('Emulation.setDeviceMetricsOverride',{width:w,height:900,deviceScaleFactor:1,mobile:false});await sleep(800);
  const m=await ev(`(()=>{const q=s=>[...document.querySelectorAll(s)];const nav=q('.main-nav > a, .main-nav .dd-btn');const lines=Math.max(...nav.map(e=>{const tops=new Set();const w=document.createTreeWalker(e,NodeFilter.SHOW_TEXT);let n;while((n=w.nextNode())){if(!n.textContent.trim())continue;const r=document.createRange();r.selectNodeContents(n);[...r.getClientRects()].forEach(x=>{if(x.width>0)tops.add(Math.round(x.top))})}return tops.size}));const tools=document.querySelector('.nav-tools').getBoundingClientRect();const last=nav[nav.length-1].getBoundingClientRect();const a=document.querySelector('.nav-tools .priv-link');const ar=a.getBoundingClientRect();const lo=document.querySelector('.nav-tools .tool-btn[aria-label=로그아웃]').getBoundingClientRect();const tops=new Set(nav.map(e=>Math.round(e.getBoundingClientRect().top)));return {rows:tops.size,lines,overlap:last.right>tools.left+1,tw:document.documentElement.scrollWidth<=innerWidth+1,rightmost:ar.left>=lo.right-1,gap:Math.round(innerWidth-ar.right),aTop:Math.round(ar.top+ar.height/2),loTop:Math.round(lo.top+lo.height/2),txt:a.textContent,lineOne:ar.height<20}})()`);
  ok(`폭 ${w}: 메뉴 한 줄·겹침 없음·링크가 로그아웃 오른쪽 끝·한 줄`,m.rows===1&&m.lines<=1&&!m.overlap&&m.tw&&m.rightmost&&m.lineOne&&Math.abs(m.aTop-m.loTop)<12,m);
 }
 // 로그인 후 링크 이동
 await send('Emulation.setDeviceMetricsOverride',{width:1400,height:900,deviceScaleFactor:1,mobile:false});await sleep(500);
 await ev(`document.querySelector('.nav-tools .priv-link').click()`);await sleep(1800);
 ok('로그인한 채로 눌러도 방침 화면',(await ev('location.pathname'))==='/cm/privacy');
 // 휴대폰
 await send('Emulation.setDeviceMetricsOverride',{width:390,height:800,deviceScaleFactor:1,mobile:true});
 await send('Page.navigate',{url:H+'/cm/privacy'});await sleep(2200);
 ok('휴대폰: 방침 화면 가로 넘침 없음',await ev('document.documentElement.scrollWidth<=innerWidth+1'));
 ok('휴대폰: 표가 잘리지 않고 옆으로 밀어 볼 수 있음',await ev(`[...document.querySelectorAll('.pv-tbl')].every(d=>d.scrollWidth>=d.clientWidth)&&document.documentElement.scrollWidth<=innerWidth+1`));
 await send('Page.navigate',{url:H+'/cm/'});await sleep(2500);
 await ev(`document.querySelector('.burger')?.click()`);await sleep(500);
 const mv=await ev(`(()=>{const a=document.querySelector('.nav-tools .priv-link');if(!a)return null;const r=a.getBoundingClientRect();return {vis:r.width>0&&r.right<=innerWidth,w:Math.round(r.width)}})()`);
 ok('휴대폰: 메뉴를 펼치면 링크가 보임',mv&&mv.vis,mv);
 ok('스크립트 오류 없음',errs.length===0,errs);
 console.log(`\n통과 ${pass} / 실패 ${fail}`);proc.kill();process.exit(fail?1:0)})();
