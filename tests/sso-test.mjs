// 재정 연결(입장권) 시험: node sso-test.mjs http://127.0.0.1:8090/cm   (새로 설치된 상태)
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { gunzipSync } from 'node:zlib';
const [base] = process.argv.slice(2);
const mysql = (process.env.CM_MYSQL || 'C:/xampp/mysql/bin/mysql.exe');
let pass = 0, fail = 0;
const ok = (n, c, x = '') => { c ? pass++ : fail++; console.log((c ? 'OK   ' : 'FAIL ') + n + (c ? '' : '  ' + JSON.stringify(x))); };
const sql = (s) => execFileSync(mysql, ['-uroot', '-proot', '-P3307', '-h127.0.0.1', 'cmtest', '-N', '-e', s]).toString().trim();
function client() {
  let cookie = '', csrf = '';
  return {
    async call(method, path, body, noCsrf) {
      const res = await fetch(base + '/api/v1' + path, { method, redirect: 'manual', headers: { cookie, 'content-type': 'application/json', ...(csrf && !noCsrf ? { 'x-csrf-token': csrf } : {}) }, body: body === undefined ? undefined : JSON.stringify(body) });
      const sc = res.headers.getSetCookie?.() ?? []; if (sc.length) cookie = sc.map((x) => x.split(';')[0]).join('; ');
      const raw = await res.text(); let j; try { j = JSON.parse(raw); } catch { j = { raw }; }
      if (j?.data?.csrf) csrf = j.data.csrf; return { status: res.status, headers: res.headers, ...j };
    },
    async login(id, pw) { await this.call('GET', '/auth/me'); return this.call('POST', '/auth/login', { loginId: id, password: pw }); },
    get cookie() { return cookie; },
  };
}
const A = client(); ok('관리자 로그인', (await A.login('admin', 'password1')).status === 200);
{
  const r = await fetch(base + '/api/v1/auth/me'); const sc = (r.headers.getSetCookie?.() ?? []).join(';');
  ok('세션 쿠키 경로가 /cm 슬래시 모양', /path=\/cm(;|$)/i.test(sc) && !sc.includes(String.fromCharCode(92)), sc);
}

// ---- 재정이 없을 때 ----
let boot = (await A.call('GET', '/bootstrap')).data;
ok('재정이 없으면 features.finance.available=false', boot.features.finance?.available === false && boot.features.finance.path === 'fm', boot.features);
let r = await A.call('POST', '/auth/sso-ticket');
ok('재정이 없으면 입장권 발급 404', r.status === 404 && r.error?.code === 'finance_not_installed', r);

// ---- 재정이 설치된 것처럼 (같은 DB 에 fm_meta 표) ----
sql('CREATE TABLE fm_meta (k VARCHAR(50) PRIMARY KEY, v TEXT)');
boot = (await A.call('GET', '/bootstrap')).data;
ok('fm_meta 가 있으면 available=true', boot.features.finance?.available === true, boot.features);

// ---- 발급 ----
r = await A.call('POST', '/auth/sso-ticket');
const T = r.data?.ticket;
ok('입장권 발급 200, 43자 base64url', r.status === 200 && /^[A-Za-z0-9_-]{43}$/.test(T || ''), r);
ok('응답에 폴더 이름(fm)', r.data?.path === 'fm');
ok('캐시 금지 헤더', /no-store/.test(r.headers.get('cache-control') || ''), r.headers.get('cache-control'));
const h = createHash('sha256').update(T).digest('hex');
const row = sql("SELECT token_hash, user_id, TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM cm_sso_tickets WHERE token_hash='" + h + "'").split('\t');
ok('DB 에는 해시만 저장(원문 없음)', row[0] === h && sql("SELECT COUNT(*) FROM cm_sso_tickets WHERE token_hash='" + T + "'") === '0', row);
const uid = sql("SELECT id FROM cm_users WHERE login_id='admin'");
ok('입장권이 사용자에 묶임', row[1] === uid, row);
ok('유효 시간 약 30초', +row[2] >= 27 && +row[2] <= 30, row);
ok('접속기록에 입장권 원문이 없음', !sql('SELECT IFNULL(GROUP_CONCAT(target),\'\') FROM cm_access_log').includes(T));
const q1 = () => sql("SELECT id, user_id FROM cm_sso_tickets WHERE token_hash='" + h + "' AND expires_at > NOW()");
const got = q1(); ok('재정의 조회 쿼리로 한 번 읽힘', got.split('\t')[1] === uid, got);
sql("DELETE FROM cm_sso_tickets WHERE token_hash='" + h + "'");
ok('지운 뒤 재사용 불가', q1() === '');
r = await A.call('POST', '/auth/sso-ticket'); const T2 = r.data.ticket; const h2 = createHash('sha256').update(T2).digest('hex');
sql("UPDATE cm_sso_tickets SET expires_at = NOW() - INTERVAL 1 SECOND WHERE token_hash='" + h2 + "'");
ok('만료되면 조회되지 않음', sql("SELECT id FROM cm_sso_tickets WHERE token_hash='" + h2 + "' AND expires_at > NOW()") === '');
sql("UPDATE cm_sso_tickets SET expires_at = NOW() - INTERVAL 3 DAY WHERE token_hash='" + h2 + "'");
await A.call('POST', '/auth/sso-ticket');
ok('발급할 때 하루 지난 행을 정리', sql("SELECT COUNT(*) FROM cm_sso_tickets WHERE token_hash='" + h2 + "'") === '0');

// ---- 거절 ----
ok('로그인 없이 발급 불가(401)', (await client().call('POST', '/auth/sso-ticket')).status === 401);
ok('CSRF 없이 발급 불가(403)', (await A.call('POST', '/auth/sso-ticket', undefined, true)).status === 403);
const U = client(); await U.call('GET', '/auth/me');
r = await U.call('POST', '/auth/signup', { loginId: 'userb', password: 'password2', name: '이용자', phone: '010-0000-0001' });
ok('가입 신청', r.status === 200 || r.status === 201, r);
const idb = sql("SELECT id FROM cm_users WHERE login_id='userb'");
ok('승인 전에는 로그인 불가', (await U.login('userb', 'password2')).status !== 200);
await A.call('POST', '/users/' + idb + '/approve', { grade: 'B' });
r = await U.login('userb', 'password2'); ok('승인 뒤 로그인', r.status === 200, r);
r = await U.call('POST', '/auth/sso-ticket');
const hb = createHash('sha256').update(r.data.ticket).digest('hex');
ok('B 등급도 발급되고 자기 번호에 묶임', r.status === 200 && sql("SELECT user_id FROM cm_sso_tickets WHERE token_hash='" + hb + "'") === idb, r);
ok('다른 사용자(관리자)의 번호가 아님', sql("SELECT user_id FROM cm_sso_tickets WHERE token_hash='" + hb + "'") !== uid);
await A.call('POST', '/users/' + idb + '/status', { status: '정지' });
r = await U.call('POST', '/auth/sso-ticket'); ok('정지된 계정은 발급 거절', r.status === 401 || r.status === 403, r);
const L = client(); await L.call('GET', '/auth/me');
await L.call('POST', '/auth/signup', { loginId: 'leaderd', password: 'password3', name: '인도자', phone: '010-0000-0002' });
const idl = sql("SELECT id FROM cm_users WHERE login_id='leaderd'");
await A.call('POST', '/users/' + idl + '/approve', { grade: 'D' });
await L.login('leaderd', 'password3'); r = await L.call('POST', '/auth/sso-ticket');
ok('소그룹 인도자(D)는 거절 403', r.status === 403, r);
await A.call('POST', '/system/demo-account');
const D = client(); await D.login('demo', '1234'); r = await D.call('POST', '/auth/sso-ticket');
ok('체험 계정은 거절 403', r.status === 403, r);

// ---- 분당 제한 ----
sql('DELETE FROM cm_sso_tickets');
const codes = []; for (let i = 0; i < 12; i++) codes.push((await A.call('POST', '/auth/sso-ticket')).status);
ok('분당 10회까지, 11번째부터 429', codes.slice(0, 10).every((c) => c === 200) && codes[10] === 429, codes);

// ---- 설정 ----
r = await A.call('GET', '/finance/config'); ok('설정 조회(기본 fm, fm_)', r.data?.path === 'fm' && r.data?.prefix === 'fm_' && r.data.installed === true, r);
ok('로그인 없이 설정 불가', (await client().call('GET', '/finance/config')).status === 401);
await A.call('POST', '/users/' + idb + '/status', { status: '사용' });
const B = client(); await B.login('userb', 'password2');
ok('B 등급은 설정 403', (await B.call('PUT', '/finance/config', { path: 'x', prefix: 'y_' })).status === 403);
ok('이상한 폴더 이름 거절', (await A.call('PUT', '/finance/config', { path: '../etc', prefix: 'fm_' })).status === 422);
ok('이상한 접두어 거절', (await A.call('PUT', '/finance/config', { path: 'fm', prefix: 'x`; DROP' })).status === 422);
r = await A.call('PUT', '/finance/config', { path: 'fin', prefix: 'fx_' }); ok('다른 접두어 저장 후 설치 안 됨으로 보임', r.status === 200 && r.data.installed === false, r);
ok('접두어가 맞지 않으면 발급 404', (await A.call('POST', '/auth/sso-ticket')).status === 404);
sql('CREATE TABLE fx_meta (k VARCHAR(50) PRIMARY KEY, v TEXT)');
sql('DELETE FROM cm_sso_tickets');
r = await A.call('POST', '/auth/sso-ticket'); ok('새 접두어 표가 있으면 발급되고 폴더 이름은 fin', r.status === 200 && r.data.path === 'fin', r);
boot = (await A.call('GET', '/bootstrap')).data; ok('부트스트랩 경로 fin', boot.features.finance.path === 'fin' && boot.features.finance.available);
await A.call('PUT', '/finance/config', { path: '', prefix: '' }); ok('비우면 기본값으로', (await A.call('GET', '/finance/config')).data.path === 'fm');

// ---- 백업에서 제외 ----
sql('DELETE FROM cm_sso_tickets'); await A.call('POST', '/auth/sso-ticket');
r = await A.call('POST', '/backup/run', {}); ok('백업 실행', r.status === 200, r.error);
const st = (await A.call('GET', '/backup/status')).data; const nm = (st.saved || [])[0]?.name;
if (nm) {
  const res = await fetch(base + '/api/v1/backup/file/' + nm, { headers: { cookie: A.cookie } });
  const buf = Buffer.from(await res.arrayBuffer()); let txt = ''; try { txt = gunzipSync(buf).toString('utf8'); } catch { txt = buf.toString('latin1'); }
  ok('백업 파일에 입장권 표가 없음', txt.length > 100 && !txt.includes('sso_tickets'), txt.slice(0, 80));
} else ok('백업 파일 목록 확인', false, st);

sql('DROP TABLE fm_meta; DROP TABLE fx_meta');
console.log('\n통과 ' + pass + ' / 실패 ' + fail); process.exit(fail ? 1 : 0);
