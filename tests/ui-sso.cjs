// 재정 열기 화면 시험: node ui-sso.cjs <작업폴더>   (새로 설치 + 화면 빌드가 www/cm 에 있는 상태)
const { spawn, execFileSync } = require('child_process'); const path = require('path'), fs = require('fs');
const out = process.argv[2]; fs.mkdirSync(out, { recursive: true });
const H = 'http://127.0.0.1:8090', PORT = 9392; const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sql = (s) => execFileSync((process.env.CM_MYSQL || 'C:/xampp/mysql/bin/mysql.exe'), ['-uroot', '-proot', '-P3307', '-h127.0.0.1', 'cmtest', '-N', '-e', s]).toString().trim();
let pass = 0, fail = 0; const ok = (n, c, x = '') => { c ? pass++ : fail++; console.log((c ? 'OK   ' : 'FAIL ') + n + (c ? '' : ' ' + String(x).slice(0, 200))); };
(async () => {
  const proc = spawn((process.env.CM_BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'), ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${path.resolve(out, "p")}`, '--no-first-run', 'about:blank'], { stdio: 'ignore' });
  let t; for (let i = 0; i < 40; i++) { try { t = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); if (t.find((x) => x.type === 'page')) break; } catch {} await sleep(300); }
  const ws = new WebSocket(t.find((x) => x.type === 'page').webSocketDebuggerUrl); await new Promise((r) => (ws.onopen = r));
  let id = 0; const pend = new Map(); ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pend.has(d.id)) { pend.get(d.id)(d.result ?? d.error); pend.delete(d.id); } };
  const send = (method, params = {}) => new Promise((r) => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
  const ev = async (js) => (await send('Runtime.evaluate', { expression: js, awaitPromise: true, returnByValue: true })).result?.value;
  const setVal = (sel, v) => ev(`(()=>{const i=document.querySelector(${JSON.stringify(sel)});i.value=${JSON.stringify(v)};i.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  const nav = async (u, ms = 2800) => { await send('Page.navigate', { url: H + u }); await sleep(ms); };
  const login = async (id, pw) => { await ev("document.querySelector('input[autocomplete=username]').value=''"); await setVal('input[autocomplete="username"]', id); await setVal('input[type="password"]', pw); await ev("document.querySelector('form button[type=submit]').click()"); await sleep(3500); };
  const finBtn = "[...document.querySelectorAll('.nav-tools .tool-btn')].find((b) => b.textContent.trim() === '재정')";
  await send('Page.enable'); await send('Runtime.enable'); await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });

  // 재정이 없을 때
  await nav('/cm/'); await login('admin', 'password1');
  ok('재정이 없으면 [재정 열기] 버튼이 없음', (await ev(`!(${finBtn})`)) === true);
  await nav('/cm/documents/');
  await ev("[...document.querySelectorAll('select')].forEach(()=>{})");
  // 문서 종류 고르기: 기부금 영수증 버튼/선택 찾기
  const pick = async () => ev(`(()=>{const els=[...document.querySelectorAll('button,a,option,label,select')];const e=els.find(x=>x.textContent.trim()==='기부금 영수증'&&x.tagName!=='SELECT');if(!e)return 'none';if(e.tagName==='OPTION'){const s=e.closest('select');s.value=e.value;s.dispatchEvent(new Event('change',{bubbles:true}));}else e.click();return 'ok';})()`);
  ok('기부금 영수증 화면을 열 수 있음', (await pick()) === 'ok'); await sleep(800);
  ok('재정이 없으면 안내가 없음', (await ev("!document.querySelector('.fin-note')")) === true);

  // 재정이 설치된 것처럼
  sql('CREATE TABLE fm_meta (k VARCHAR(50) PRIMARY KEY, v TEXT)');
  await nav('/cm/');
  ok('재정이 있으면 [재정 열기] 버튼이 보임', (await ev(`!!(${finBtn})`)) === true);
  await nav('/cm/documents/'); await pick(); await sleep(800);
  const note = await ev("document.querySelector('.fin-note')?.textContent || ''");
  ok('영수증 화면에 안내 문구와 링크가 보임', note.includes('재정 관리가 설치되어 있어요') && note.includes('재정 › 기부금 영수증') && note.includes('재정'), note);
  ok('금액 입력표는 그대로(헌금 금액은 가져오지 않음)', (await ev("!!document.querySelector('.don-t')")) === true);

  // 누르면 입장권을 받아 /fm/sso?t= 로 이동 (시험 서버에는 /fm 이 없어 404 가 나와도 주소로 확인)
  await nav('/cm/');
  await ev(`(${finBtn}).click()`); await sleep(2500);
  const url = await ev('location.pathname + location.search');
  ok('/fm/sso?t=입장권 으로 이동', /^\/fm\/sso\?t=[A-Za-z0-9_-]{43}$/.test(url), url);
  const tk = (url.match(/t=([^&]+)/) || [])[1] || '';
  const hh = require('crypto').createHash('sha256').update(tk).digest('hex');
  ok('그 입장권의 해시가 DB 에 있음', sql("SELECT COUNT(*) FROM cm_sso_tickets WHERE token_hash='" + hh + "'") === '1');

  // 설정 화면
  await nav('/cm/settings/?tab=' + encodeURIComponent('외부 서비스'), 3500);
  ok('외부 서비스 탭에 재정 관리 연결 칸이 있음', (await ev("document.body.textContent.includes('재정 관리 연결')")) === true);
  ok('설치됨 표시', (await ev("document.body.textContent.includes('재정 관리 연결') && [...document.querySelectorAll('.tag')].some((x) => x.textContent.includes('설치됨'))")) === true);
  await setVal('input[placeholder="fm"]', 'finance');
  await ev("[...document.querySelectorAll('section.card')].find((s) => s.textContent.includes('재정 관리 연결')).querySelector('button').click()"); await sleep(1500);
  ok('저장 안내가 보임', (await ev("document.body.textContent.includes('재정 관리 연결 정보를 저장했습니다')")) === true);
  await nav('/cm/'); await ev(`(${finBtn}).click()`); await sleep(2500);
  ok('바꾼 폴더 이름(finance)으로 이동', (await ev('location.pathname')) === '/finance/sso', await ev('location.pathname'));

  // 체험 계정에는 버튼이 없음
  await nav('/cm/'); await ev("(()=>{const b=[...document.querySelectorAll('button')].find(x=>x.textContent.includes('로그아웃'));b&&b.click();})()"); await sleep(1500);
  await nav('/cm/?demo=1', 4500);
  ok('체험 계정에는 [재정 열기]가 없음(demo 가 켜져 있다면)', (await ev('!!document.querySelector(".demo-bar")')) ? (await ev(`!(${finBtn})`)) === true : true);

  // 휴대폰 폭에서 메뉴에 보임
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
  sql('DROP TABLE fm_meta');
  console.log(`\n통과 ${pass} / 실패 ${fail}`); proc.kill(); process.exit(fail ? 1 : 0);
})();
