// 상단 메뉴가 폭마다 한 줄로 나오는지 측정: node ui-nav.cjs <작업폴더>
const { spawn, execFileSync } = require('child_process'); const path = require('path'), fs = require('fs');
const out = process.argv[2]; fs.mkdirSync(out, { recursive: true });
const H = 'http://127.0.0.1:8090', PORT = 9393; const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sql = (s) => execFileSync((process.env.CM_MYSQL || 'C:/xampp/mysql/bin/mysql.exe'), ['-uroot', '-proot', '-P3307', '-h127.0.0.1', 'cmtest', '-N', '-e', s]).toString().trim();
let pass = 0, fail = 0; const ok = (n, c, x = '') => { c ? pass++ : fail++; console.log((c ? 'OK   ' : 'FAIL ') + n + (c ? '' : ' ' + JSON.stringify(x).slice(0, 300))); };
(async () => {
  try { sql('CREATE TABLE fm_meta (k VARCHAR(50) PRIMARY KEY, v TEXT)'); } catch {}
  const proc = spawn((process.env.CM_BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'), ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${path.resolve(out, 'p')}`, '--no-first-run', 'about:blank'], { stdio: 'ignore' });
  let t; for (let i = 0; i < 40; i++) { try { t = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); if (t.find((x) => x.type === 'page')) break; } catch {} await sleep(300); }
  const ws = new WebSocket(t.find((x) => x.type === 'page').webSocketDebuggerUrl); await new Promise((r) => (ws.onopen = r));
  let id = 0; const pend = new Map(); ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pend.has(d.id)) { pend.get(d.id)(d.result ?? d.error); pend.delete(d.id); } };
  const send = (method, params = {}) => new Promise((r) => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
  const ev = async (js) => (await send('Runtime.evaluate', { expression: js, awaitPromise: true, returnByValue: true })).result?.value;
  const setVal = (sel, v) => ev(`(()=>{const i=document.querySelector(${JSON.stringify(sel)});i.value=${JSON.stringify(v)};i.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  await send('Page.enable'); await send('Runtime.enable'); await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: H + '/cm/' }); await sleep(2500);
  await setVal('input[autocomplete="username"]', 'admin'); await setVal('input[type="password"]', 'password1');
  await ev("document.querySelector('form button[type=submit]').click()"); await sleep(3500);
  const measure = `(()=>{const q=(s)=>[...document.querySelectorAll(s)];const nav=q('.main-nav > a, .main-nav .dd-btn');const tops=new Set(nav.map(e=>Math.round(e.getBoundingClientRect().top)));const lines=Math.max(...nav.map(e=>{const tops=new Set();const w=document.createTreeWalker(e,NodeFilter.SHOW_TEXT);let n;while((n=w.nextNode())){if(!n.textContent.trim())continue;const r=document.createRange();r.selectNodeContents(n);[...r.getClientRects()].forEach(x=>{if(x.width>0)tops.add(Math.round(x.top))})}return tops.size}));const hs=[lines];const bar=document.querySelector('.site-header .bar').getBoundingClientRect();const tools=document.querySelector('.nav-tools').getBoundingClientRect();const last=nav[nav.length-1].getBoundingClientRect();return {rows:tops.size,maxH:Math.max(...hs),barH:Math.round(bar.height),overlap:last.right>tools.left+1,tw:document.documentElement.scrollWidth<=innerWidth+1,fin:q('.nav-tools .tool-btn').some(b=>b.textContent.trim()==='재정'&&b.getBoundingClientRect().width>0)}})()`;
  for (const w of [1500, 1320, 1250, 1190, 1100, 1020, 970]) {
    await send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: false }); await sleep(900);
    const m = await ev(measure);
    ok(`폭 ${w}: 메뉴 한 줄, 버튼과 겹치지 않음, 가로 넘침 없음, 재정 열기 보임`, m.rows === 1 && m.maxH <= 1 && !m.overlap && m.tw && m.fin, m);
  }
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 1, mobile: true }); await sleep(900);
  ok('휴대폰에서는 가로 넘침 없음', await ev('document.documentElement.scrollWidth <= innerWidth + 1'));
  try { sql('DROP TABLE fm_meta'); } catch {}
  console.log(`\n통과 ${pass} / 실패 ${fail}`); proc.kill(); process.exit(fail ? 1 : 0);
})();
