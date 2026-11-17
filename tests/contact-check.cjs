const { spawn, execFileSync } = require('child_process'); const path=require('path'),fs=require('fs');
const out=process.argv[2]; fs.mkdirSync(out,{recursive:true}); const H='http://127.0.0.1:8090',PORT=9404; const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const sql=s=>execFileSync((process.env.CM_MYSQL || 'C:/xampp/mysql/bin/mysql.exe'),['-uroot','-proot','-P','3307','-h','127.0.0.1','cmtest','-N','-e',s]).toString().trim();
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
 // 비어 있을 때: 일반 문구
 await send('Page.navigate',{url:H+'/cm/privacy'});await sleep(2500);
 const blank=await ev(`({lead:[...document.querySelectorAll('#s12 p')][0].textContent,contact:!!document.querySelector('#s12 .pv-contact')})`);
 ok('입력 전: 일반 문구(담임목사 또는 지정한 담당자), 연락처 목록 없음',blank.lead.includes('담임목사(또는 교회가 지정한 교적 담당자)')&&!blank.contact,blank);
 // 설정 화면에서 입력·저장
 await send('Page.navigate',{url:H+'/cm/'});await sleep(2500);
 await setVal('input[autocomplete="username"]','admin');await setVal('input[type="password"]','password1');
 await ev("document.querySelector('form button[type=submit]').click()");await sleep(3500);
 await send('Page.navigate',{url:H+'/cm/settings/'});await sleep(3500);
 const has=await ev(`['예: 담임목사 홍길동'].every(p=>!!document.querySelector('input[placeholder="'+p+'"]'))&&document.body.textContent.includes('개인정보 문의 전화')&&document.body.textContent.includes('개인정보 문의 이메일')`);
 ok('교회 설정에 책임자·문의 전화·문의 이메일 칸이 있음',has===true);
 await setVal('input[placeholder="예: 담임목사 홍길동"]','담임목사 홍길동');
 const inputs=await ev(`[...document.querySelectorAll('label')].filter(l=>l.textContent.includes('개인정보 문의')).length`);
 await ev(`(()=>{const q=t=>[...document.querySelectorAll('label')].find(l=>l.textContent.includes(t)).querySelector('input');for(const [t,v] of [['개인정보 문의 전화','032-111-2222'],['개인정보 문의 이메일','privacy@test.church']]){const i=q(t);i.value=v;i.dispatchEvent(new Event('input',{bubbles:true}))}})()`);
 await ev(`[...document.querySelectorAll('button')].find(b=>b.textContent.trim()==='교회 정보 저장').click()`);await sleep(2500);
 const saved=sql("SELECT JSON_VALUE(church_info,'$.privacyOfficer'),JSON_VALUE(church_info,'$.privacyPhone'),JSON_VALUE(church_info,'$.privacyEmail') FROM cm_site_settings WHERE id=1");
 ok('저장하면 서버에 들어감',saved==='담임목사 홍길동\t032-111-2222\tprivacy@test.church',saved);
 // 로그아웃 상태 방침 화면
 await ev(`[...document.querySelectorAll('button')].find(b=>b.getAttribute('aria-label')==='로그아웃').click()`);await sleep(2000);
 await send('Page.navigate',{url:H+'/cm/privacy'});await sleep(2500);
 const after=await ev(`({lead:[...document.querySelectorAll('#s12 p')][0].textContent,items:[...document.querySelectorAll('#s12 .pv-contact li')].map(l=>l.textContent)})`);
 ok('로그인 안 한 채로 방침에 책임자·전화·이메일이 보임',after.lead.includes('담임목사 홍길동')&&after.items.length===3&&after.items[1].includes('032-111-2222')&&after.items[2].includes('privacy@test.church'),after);
 // 문의처만 비우면 대표 전화·이메일 사용
 sql("UPDATE cm_site_settings SET church_info=JSON_SET(JSON_SET(JSON_SET(church_info,'$.privacyPhone',''),'$.privacyEmail',''),'$.phone','02-999-0000','$.email','office@test.church') WHERE id=1");
 await send('Page.navigate',{url:H+'/cm/privacy'});await sleep(2500);
 const fb=await ev(`[...document.querySelectorAll('#s12 .pv-contact li')].map(l=>l.textContent)`);
 ok('문의처를 비우면 교회 대표 전화·이메일이 대신 보임',fb.some(x=>x.includes('02-999-0000'))&&fb.some(x=>x.includes('office@test.church')),fb);
 // 길이 제한
 sql("UPDATE cm_site_settings SET church_info=JSON_SET(church_info,'$.privacyOfficer','"+'가'.repeat(150)+"') WHERE id=1");
 const me=await (await fetch(H+'/cm/api/v1/auth/me')).json();
 ok('책임자 글자는 100자까지만 내려감',me.data.privacy.officer.length===100,me.data.privacy.officer.length);
 // 아이콘
 await send('Page.navigate',{url:H+'/cm/'});await sleep(2500);
 const ic=await ev(`(()=>{const a=document.querySelector('.foot-copy .priv-link');const s=a.querySelector('svg');const sr=s.getBoundingClientRect(),tr=(()=>{const r=document.createRange();r.selectNodeContents(a.lastChild);return r.getBoundingClientRect()})();return {svg:!!s,w:Math.round(sr.width),before:sr.right<=tr.left+1,aligned:Math.abs((sr.top+sr.height/2)-(tr.top+tr.height/2))<4,hidden:s.getAttribute('aria-hidden')}})()`);
 ok('링크 글자 앞에 작은 방패 아이콘(글자와 높이 맞음)',ic.svg&&ic.before&&ic.aligned&&ic.w<=13,ic);
 ok('스크립트 오류 없음',errs.length===0,errs);
 sql("UPDATE cm_site_settings SET church_info=JSON_REMOVE(JSON_REMOVE(JSON_REMOVE(church_info,'$.privacyOfficer'),'$.privacyPhone'),'$.privacyEmail') WHERE id=1");
 console.log(`\n통과 ${pass} / 실패 ${fail}`);proc.kill();process.exit(fail?1:0)})();
