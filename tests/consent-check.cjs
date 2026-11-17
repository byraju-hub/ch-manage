// 개인정보 동의 명단·접속 주소 열·처리방침 보완 시험: node consent-check.cjs <결과 폴더>   (새로 설치 + 화면 빌드가 www/cm 에 있는 상태)
const { spawn, execFileSync } = require('child_process'); const path = require('path'), fs = require('fs');
const out = process.argv[2]; fs.mkdirSync(out, { recursive: true }); const H = 'http://127.0.0.1:8090', PORT = 9407; const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sql = (q) => execFileSync(process.env.CM_MYSQL || 'C:/xampp/mysql/bin/mysql.exe', ['-uroot', '-proot', '-P3307', '-h127.0.0.1', '--default-character-set=utf8mb4', 'cmtest', '-N'], { input: Buffer.from(q, 'utf8') }).toString().trim(); // 한글이 깨지지 않게 명령줄이 아니라 표준 입력으로 보냄
let pass = 0, fail = 0; const ok = (n, c, x = '') => { c ? pass++ : fail++; console.log((c ? 'OK   ' : 'FAIL ') + n + (c ? '' : ' ' + JSON.stringify(x).slice(0, 300))); };
(async () => {
  const proc = spawn(process.env.CM_BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${path.resolve(out, 'p')}`, '--no-first-run', 'about:blank'], { stdio: 'ignore' });
  let t; for (let i = 0; i < 40; i++) { try { t = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); if (t.find((x) => x.type === 'page')) break; } catch {} await sleep(300); }
  const ws = new WebSocket(t.find((x) => x.type === 'page').webSocketDebuggerUrl); await new Promise((r) => (ws.onopen = r));
  let id = 0; const pend = new Map(); const errs = []; ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pend.has(d.id)) { pend.get(d.id)(d.result ?? d.error); pend.delete(d.id); } if (d.method === 'Runtime.exceptionThrown') errs.push(d.params.exceptionDetails.text); };
  const send = (method, params = {}) => new Promise((r) => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
  const ev = async (js) => (await send('Runtime.evaluate', { expression: js, awaitPromise: true, returnByValue: true })).result?.value;
  const setVal = (sel, v) => ev(`(()=>{const i=document.querySelector(${JSON.stringify(sel)});i.value=${JSON.stringify(v)};i.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  const nav = async (u, ms = 3000) => { await send('Page.navigate', { url: H + u }); await sleep(ms); };
  const tabBtn = (txt) => `[...document.querySelectorAll('.tabs button')].find(b=>b.textContent.trim()===${JSON.stringify(txt)})`;
  await send('Page.enable'); await send('Runtime.enable'); await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });
  await nav('/cm/'); await setVal('input[autocomplete="username"]', 'admin'); await setVal('input[type="password"]', 'password1');
  await ev("document.querySelector('form button[type=submit]').click()"); await sleep(3500);
  // 시험용 교인 4명을 데이터베이스에 바로 넣음 (영문 이름): 성인 3명(한 명은 이미 동의함), 어린이 1명(9세)
  const yr = new Date().getFullYear();
  const mk = (i, name, by) => `INSERT INTO cm_people (id, name, birth_date) VALUES ('00000000-0000-4000-8000-00000000000${i}', '${name}', '${by}-03-01');`
    + `INSERT INTO cm_status_events (id, person_id, kind, event_date) VALUES ('10000000-0000-4000-8000-00000000000${i}', '00000000-0000-4000-8000-00000000000${i}', '등록', '2020-01-01');`;
  sql(mk(1, 'Adult A', yr - 40) + mk(2, 'Adult B', yr - 35) + mk(3, 'Adult C', yr - 50) + mk(4, 'Child D', yr - 9)
    + `INSERT INTO cm_consents (id, person_id, kind, policy_version, consent_date, method) VALUES (UUID(), '00000000-0000-4000-8000-000000000003', '교적 등록', '2026-01', CURDATE(), '서면'), (UUID(), '00000000-0000-4000-8000-000000000003', '민감정보', '2026-01', CURDATE(), '서면');`);
  await nav('/cm/reports/', 4000);
  await ev(`${tabBtn('개인정보 동의')}.click()`); await sleep(800);
  const c1 = await ev(`({txt:document.querySelector('.card p')?.textContent||'',rows:document.querySelectorAll('.card tbody tr').length})`);
  console.log('동의 탭:', JSON.stringify(c1).slice(0, 200));
  const n = Number((c1.txt.match(/빠진 사람은 (\d+)명/) || [])[1] || -1);
  ok('개인정보 동의 탭이 열리고 빠진 사람 수가 나옴', n === 3 && c1.rows === 3, { n, ...c1 });
  // 한 사람(14세 이상)의 카드에서 동의 두 가지를 남기면 목록에서 빠지는지
  const pick = await ev(`(()=>{const r=[...document.querySelectorAll('.card tbody tr')].find(x=>!x.textContent.includes('보호자 동의'));const a=r?.querySelector('a');return a?{href:a.getAttribute('href'),name:a.textContent}:null})()`);
  await nav(pick.href, 3500);
  await ev(`${tabBtn('메모·동의')}.click()`); await sleep(700);
  ok('교적 카드에 동의 기록 칸이 있음', (await ev(`[...document.querySelectorAll('h2')].some(h=>h.textContent.includes('동의 기록'))`)) === true);
  for (const kind of ['교적 등록', '민감정보']) {
    await ev(`(()=>{const f=[...document.querySelectorAll('h2')].find(h=>h.textContent.includes('동의 기록')).closest('.card').querySelector('form');const s=f.querySelector('select');s.value=${JSON.stringify(kind)};s.dispatchEvent(new Event('change',{bubbles:true}));f.requestSubmit()})()`); await sleep(700);
  }
  const card = await ev(`(()=>{const c=[...document.querySelectorAll('h2')].find(h=>h.textContent.includes('동의 기록')).closest('.card');return {n:c.querySelectorAll('tbody tr').length,ver:c.querySelector('tbody tr td:nth-child(2)')?.textContent}})()`);
  ok('동의 두 건이 기록되고 처리방침 판이 2026-11-17', card.n >= 2 && card.ver === '2026-11-17', card);
  await sleep(2500);
  await nav('/cm/reports/', 4000);
  await ev(`${tabBtn('개인정보 동의')}.click()`); await sleep(800);
  const c2 = await ev(`document.querySelector('.card p')?.textContent||''`);
  const n2 = Number((c2.match(/빠진 사람은 (\d+)명/) || [])[1] || -1);
  const still = await ev(`[...document.querySelectorAll('.card tbody tr a')].some(a=>a.getAttribute('href')===${JSON.stringify(pick.href)})`);
  ok('동의를 남긴 사람이 목록에서 빠지고 수가 줄어듦', n2 === n - 1 && !still, { n, n2, still });
  await ev(`[...document.querySelectorAll('.card button')].find(b=>b.textContent.trim().startsWith('14세 미만')).click()`); await sleep(500);
  const kids = await ev(`[...document.querySelectorAll('.card tbody tr')].map(r=>r.textContent)`);
  ok('14세 미만 보기: 나오는 사람은 모두 보호자 동의가 빠진 사람', kids.length > 0 && kids.every((x) => x.includes('보호자 동의') || x.includes('없습니다')), kids.slice(0, 3));
  // 접속 기록 표에 접속 주소 열
  await nav('/cm/admin/', 3500);
  await ev(`(()=>{const b=[...document.querySelectorAll('.tabs button, button')].find(b=>/기록/.test(b.textContent)&&b.textContent.length<12);b&&b.click()})()`); await sleep(900);
  const th = await ev(`[...document.querySelectorAll('table th')].map(x=>x.textContent)`);
  ok('접속 기록 표에 [접속 주소] 열이 있음', th.includes('접속 주소'), th);
  const ips = await ev(`[...document.querySelectorAll('table tbody tr')].slice(0,6).map(r=>r.children[2]?.textContent)`);
  console.log('   접속 주소 칸 예:', JSON.stringify(ips));
  ok('최근 기록에 실제 접속 주소가 보임', ips.some((x) => /^(127\.0\.0\.1|::1)$/.test((x || '').trim())), ips);
  // 보관 기간 입력
  await nav('/cm/settings/?tab=' + encodeURIComponent('백업'), 3500);
  const min = await ev(`[...document.querySelectorAll('label')].filter(l=>l.textContent.includes('접속·수정 기록')).map(l=>l.querySelector('input')?.min)`);
  ok('접속·수정 기록 보관 입력칸의 최소값이 2', min.length === 1 && min[0] === '2', min);
  // 처리방침
  await nav('/cm/privacy', 3000);
  const pv = await ev(`({ver:document.querySelector('.pv-ver')?.textContent,ab:document.body.textContent.includes('국외 이전 (AI 분석 기능을 켠 경우에만)')&&document.body.textContent.includes('거부 방법과 불이익'),hist:!!document.querySelector('.pv-hist li'),ip:document.body.textContent.includes('접속 주소(IP)에서'),two:document.body.textContent.includes('2~10년')})`);
  ok('처리방침: 시행일·국외 이전 표·개정 이력·접속 주소·2년', pv.ver.includes('2026년 11월 17일') && pv.ab && pv.hist && pv.ip && pv.two, pv);
  ok('스크립트 오류 없음', errs.length === 0, errs);
  console.log(`\n통과 ${pass} / 실패 ${fail}`); proc.kill(); process.exit(fail ? 1 : 0);
})();
