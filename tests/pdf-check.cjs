const { spawn } = require('child_process'); const path=require('path'),fs=require('fs');
const out=process.argv[2]; fs.rmSync(out,{recursive:true,force:true}); fs.mkdirSync(path.join(out,'dl'),{recursive:true}); const H='http://127.0.0.1:8090',PORT=9405; const sleep=ms=>new Promise(r=>setTimeout(r,ms));
let pass=0,fail=0; const ok=(n,c,x='')=>{c?pass++:fail++;console.log((c?'OK   ':'FAIL ')+n+(c?'':' '+JSON.stringify(x).slice(0,300)))};
(async()=>{
 const proc=spawn((process.env.CM_BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'),['--headless=new','--disable-gpu',`--remote-debugging-port=${PORT}`,`--user-data-dir=${path.resolve(out,'p')}`,'--no-first-run','about:blank'],{stdio:'ignore'});
 let t;for(let i=0;i<40;i++){try{t=await(await fetch(`http://127.0.0.1:${PORT}/json`)).json();if(t.find(x=>x.type==='page'))break}catch{}await sleep(300)}
 const ws=new WebSocket(t.find(x=>x.type==='page').webSocketDebuggerUrl);await new Promise(r=>ws.onopen=r);
 let id=0;const pend=new Map();const errs=[];ws.onmessage=m=>{const d=JSON.parse(m.data);if(d.id&&pend.has(d.id)){pend.get(d.id)(d.result??d.error);pend.delete(d.id)}if(d.method==='Runtime.exceptionThrown')errs.push(d.params.exceptionDetails.text)};
 const send=(method,params={})=>new Promise(r=>{const i=++id;pend.set(i,r);ws.send(JSON.stringify({id:i,method,params}))});
 const ev=async js=>(await send('Runtime.evaluate',{expression:js,awaitPromise:true,returnByValue:true})).result?.value;
 const setVal=(sel,v)=>ev(`(()=>{const i=document.querySelector(${JSON.stringify(sel)});i.value=${JSON.stringify(v)};i.dispatchEvent(new Event('input',{bubbles:true}));})()`);
 await send('Page.enable');await send('Runtime.enable');await send('Browser.setDownloadBehavior',{behavior:'allow',downloadPath:path.resolve(out,'dl')});
 await send('Emulation.setDeviceMetricsOverride',{width:1400,height:900,deviceScaleFactor:1,mobile:false});
 const nav=async(u,ms=3000)=>{await send('Page.navigate',{url:H+u});await sleep(ms)};
 const btn=(txt)=>`[...document.querySelectorAll('button')].find(b=>b.textContent.trim().startsWith(${JSON.stringify(txt)}))`;
 const newPdf=async(before)=>{for(let i=0;i<40;i++){await sleep(500);const f=fs.readdirSync(path.join(out,'dl')).filter(x=>x.endsWith('.pdf')&&!before.includes(x));if(f.length)return f[0]}return null};
 const info=(f)=>{const b=fs.readFileSync(path.join(out,'dl',f));return {size:b.length,head:b.slice(0,5).toString(),pages:(b.toString('latin1').match(/\/Type\s*\/Page[^s]/g)||[]).length}};
 await nav('/cm/');await setVal('input[autocomplete="username"]','admin');await setVal('input[type="password"]','password1');
 await ev("document.querySelector('form button[type=submit]').click()");await sleep(3500);
 // 샘플 자료 넣기 (대시보드)
 await nav('/cm/',3500);
 const hasSample=await ev(`!!${btn('샘플 데이터 불러오기')}`);
 if(hasSample){await ev(`${btn('샘플 데이터 불러오기')}.click()`);await sleep(15000)}
 console.log('샘플 버튼 있었음:',hasSample);
 await nav('/cm/members/',4000);
 console.log('교인 수 표시:',await ev(`(document.querySelector('.page-head small')?.textContent||'')`));
 // 1) 교적부(여러 쪽 PDF 경로) / 2) 증명서(한 장 PDF 경로)
 for(const [doc,label] of [['교적부','교적부 PDF'],['교인증명서','교인증명서 PDF (한 장 경로)'],['생일 명단','생일 명단 PDF'],['교인 요람','교인 요람 PDF']]){
  await nav('/cm/documents/?doc='+encodeURIComponent(doc),3500);
  // 사람 필요한 문서: 첫 사람 고르기
  if(doc==='교적부'||doc==='교인증명서'){
   await ev(`(()=>{const i=document.querySelector('input[placeholder*="이름"]');if(i){i.focus();i.value='김';i.dispatchEvent(new Event('input',{bubbles:true}))}})()`);await sleep(800);
   await ev(`(()=>{const o=document.querySelector('.picker li, .picker button, [role=option], .pp-list button, .pp-item');if(o)o.click()})()`);await sleep(1200);
  }
  const before=fs.readdirSync(path.join(out,'dl'));
  const clicked=await ev(`(()=>{const b=document.querySelector('button.pdf');if(!b||b.disabled)return b?'disabled':'none';b.click();return 'ok'})()`);
  if(clicked!=='ok'){ok(label+': 버튼을 누를 수 있음',false,clicked);continue}
  const f=await newPdf(before);
  if(!f){ok(label+': PDF 파일이 만들어짐',false,'timeout');continue}
  const i=info(f);ok(label+': 올바른 PDF 파일(%PDF, 크기·쪽 수)',i.head==='%PDF-'&&i.size>8000&&i.pages>=1,{f,...i});
  console.log('   ',f,JSON.stringify(i));
 }
 // 3) 소그룹 명단, 4) 조직표
 for(const [url,label] of [['/cm/communities/roster/','소그룹 명단 PDF'],['/cm/orgs/','교회 조직표 PDF']]){
  await nav(url,4000);const before=fs.readdirSync(path.join(out,'dl'));
  const clicked=await ev(`(()=>{const b=document.querySelector('button.pdf');if(!b||b.disabled)return b?'disabled':'none';b.click();return 'ok'})()`);
  if(clicked!=='ok'){ok(label+': 버튼을 누를 수 있음',false,clicked);continue}
  const f=await newPdf(before);
  if(!f){ok(label+': PDF 파일이 만들어짐',false,'timeout');continue}
  const i=info(f);ok(label+': 올바른 PDF 파일',i.head==='%PDF-'&&i.size>8000,{f,...i});console.log('   ',f,JSON.stringify(i));
 }
 // 엑셀(공용 도우미) — 교인 목록, 항목 설정
 for(const [url,label,sel] of [['/cm/members/','교인 목록 엑셀','button.xl'],['/cm/members/columns/','항목 설정 엑셀','button.xl']]){
  await nav(url,4000);const before=fs.readdirSync(path.join(out,'dl'));
  const clicked=await ev(`(()=>{const b=document.querySelector(${JSON.stringify(sel)});if(!b||b.disabled)return b?'disabled':'none';b.click();return 'ok'})()`);
  let f=null;for(let i=0;i<30&&!f;i++){await sleep(500);f=fs.readdirSync(path.join(out,'dl')).find(x=>x.endsWith('.xlsx')&&!before.includes(x))}
  if(clicked!=='ok'||!f){ok(label+': 파일이 만들어짐',false,{clicked,f});continue}
  const b=fs.readFileSync(path.join(out,'dl',f));const today=new Date();const ymd=`${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
  ok(label+': 올바른 xlsx(zip) + 오늘 날짜 이름',b.slice(0,2).toString()==='PK'&&b.length>5000&&f.includes(ymd),{f,size:b.length});
  fs.renameSync(path.join(out,'dl',f),path.join(out,'dl',f+'.'+label.replace(/\s/g,'')+'.done'));
 }
 ok('스크립트 오류 없음',errs.length===0,errs);
 console.log(`\n통과 ${pass} / 실패 ${fail}`);proc.kill();process.exit(fail?1:0)})();
