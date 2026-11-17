// 설치 마법사를 따라 가며 시험 환경을 만든다: node install.mjs http://127.0.0.1:8090/cm
const base = process.argv[2]; let cookie = '', csrf = '';
async function get() { const r = await fetch(base + '/install/', { headers: { cookie } }); const sc = r.headers.getSetCookie?.() ?? []; if (sc.length) cookie = sc.map(x => x.split(';')[0]).join('; '); const t = await r.text(); csrf = (t.match(/name="csrf" value="([^"]+)"/) || [])[1] || csrf; return t; }
async function post(f) { const r = await fetch(base + '/install/', { method: 'POST', headers: { cookie, 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ csrf, ...f }) }); const t = await r.text(); csrf = (t.match(/name="csrf" value="([^"]+)"/) || [])[1] || csrf; return t; }
await get();
await post({ act: 'check' });
await post({ act: 'db', host: '127.0.0.1:3307', name: 'cmtest', user: 'root', pass: 'root', prefix: 'cm_' });
await post({ act: 'church', church: '시험교회', unit1: '공동체', unit2: '소그룹' });
const t = await post({ act: 'install', login: 'admin', aname: '관리자', pw: 'password1', pw2: 'password1' });
console.log(/설치가 끝|완료|끝났/.test(t) ? 'INSTALLED' : 'FAILED ' + t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 400));
