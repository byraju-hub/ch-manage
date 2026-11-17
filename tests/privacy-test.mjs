// 개인정보 보강 시험(서버): node privacy-test.mjs http://127.0.0.1:8090/cm   (새로 설치된 상태)
//  - 접속 기록에 접속 주소(IP)가 남는다  - 접속·수정 기록 보관은 최소 2년  - 로그인 전 응답의 책임자·문의처  - 구조 번호 8
import { execFileSync } from 'node:child_process';
const [base] = process.argv.slice(2);
const mysql = (process.env.CM_MYSQL || 'C:/xampp/mysql/bin/mysql.exe');
let pass = 0, fail = 0;
const ok = (n, c, x = '') => { c ? pass++ : fail++; console.log((c ? 'OK   ' : 'FAIL ') + n + (c ? '' : '  ' + JSON.stringify(x))); };
const sql = (s) => execFileSync(mysql, ['-uroot', '-proot', '-P3307', '-h127.0.0.1', '--default-character-set=utf8mb4', 'cmtest', '-N', '-e', s]).toString().trim();
function client() {
  let cookie = '', csrf = '';
  return {
    async call(method, path, body) {
      const res = await fetch(base + '/api/v1' + path, { method, redirect: 'manual', headers: { cookie, 'content-type': 'application/json', ...(csrf ? { 'x-csrf-token': csrf } : {}) }, body: body === undefined ? undefined : JSON.stringify(body) });
      const sc = res.headers.getSetCookie?.() ?? []; if (sc.length) cookie = sc.map((x) => x.split(';')[0]).join('; ');
      const raw = await res.text(); let j; try { j = JSON.parse(raw); } catch { j = { raw }; }
      if (j?.data?.csrf) csrf = j.data.csrf; return { status: res.status, ...j };
    },
    async login(id, pw) { await this.call('GET', '/auth/me'); return this.call('POST', '/auth/login', { loginId: id, password: pw }); },
  };
}
// 구조
ok('구조 번호 8 (access_log.ip 칸)', sql("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='cmtest' AND table_name='cm_access_log' AND column_name='ip'") === '1');
ok('meta schema_version >= 8', Number(sql("SELECT v FROM cm_meta WHERE k='schema_version'")) >= 8);

// 로그인 전 응답: 책임자·문의처
const anon = client();
let me = await anon.call('GET', '/auth/me');
ok('로그인 전 응답에 privacy 칸(비어 있음)', me.data?.privacy && me.data.privacy.officer === '' && me.data.privacy.phone === '' && me.data.privacy.email === '', me.data);
sql("UPDATE cm_site_settings SET church_info=JSON_SET(church_info,'$.privacyOfficer','Pastor Hong','$.phone','02-111-2222','$.email','office@test.church','$.privacyEmail','') WHERE id=1");
me = await anon.call('GET', '/auth/me');
ok('책임자는 그대로, 비운 문의처는 교회 대표 전화·이메일로 대체', me.data.privacy.officer === 'Pastor Hong' && me.data.privacy.phone === '02-111-2222' && me.data.privacy.email === 'office@test.church', me.data.privacy);
sql("UPDATE cm_site_settings SET church_info=JSON_REMOVE(JSON_REMOVE(JSON_REMOVE(JSON_REMOVE(JSON_REMOVE(church_info,'$.privacyOfficer'),'$.privacyEmail'),'$.phone'),'$.email'),'$.privacyPhone') WHERE id=1");

// 접속 기록에 IP
const A = client(); ok('관리자 로그인', (await A.login('admin', 'password1')).status === 200);
const row = sql('SELECT action, ip FROM cm_access_log ORDER BY seq DESC LIMIT 1'); // 방금 한 로그인이 가장 최근 기록
ok('로그인 기록에 접속 주소가 남음', /\t127\.0\.0\.1$|\t::1$/.test(row), row);
// 화면이 보내는 기록(검색·열람 등)에도 IP
const id = crypto.randomUUID();
let r = await A.call('POST', '/sync', { ops: [{ t: 'accessLog', id, set: { at: '2000-01-01 00:00:00', who: '가짜', action: '열람', target: '시험' } }] });
const sync = sql(`SELECT who, ip FROM cm_access_log WHERE id='${id}'`);
console.log('   sync 응답 상태', r.status, '| 저장된 줄:', JSON.stringify(sync));
ok('화면에서 보낸 기록도 접속 주소가 서버에서 채워지고 이름은 로그인한 사람', /^관리자\t(127\.0\.0\.1|::1)$/.test(sync), sync);

// 보관 기간 최소 2년
const get = await A.call('GET', '/retention/status');
ok('개인정보 정리 설정을 읽음', get.status === 200 && typeof get.data?.cfg?.logYears === 'number', get);
r = await A.call('POST', '/retention/config', { auto: true, years: 5, logYears: 1 });
ok('접속 기록 보관을 1년으로 저장하려 해도 2년으로 올려 저장', r.status === 200 && r.data.cfg.logYears === 2, r.data?.cfg);
r = await A.call('POST', '/retention/config', { auto: true, years: 5, logYears: 3 });
ok('3년은 그대로', r.data.cfg.logYears === 3, r.data?.cfg);
sql("UPDATE cm_meta SET v='{\"auto\":true,\"years\":5,\"logYears\":1}' WHERE k='retention'");
ok('예전에 1년으로 저장돼 있어도 읽을 때 2년으로 보정', (await A.call('GET', '/retention/status')).data.cfg.logYears === 2);
// 2년 안 된 기록은 지워지지 않음, 2년 넘은 기록은 지워짐
sql("INSERT INTO cm_access_log (id, who, action, target, at) VALUES (UUID(),'tst','view','age-1.5y', NOW() - INTERVAL 18 MONTH), (UUID(),'tst','view','age-3y', NOW() - INTERVAL 3 YEAR)");
r = await A.call('POST', '/retention/run', {});
console.log('   정리 실행 응답:', r.status, JSON.stringify(r.data ?? r).slice(0, 160));
const left = sql("SELECT GROUP_CONCAT(target) FROM cm_access_log WHERE who='tst'");
ok('1.5년 전 기록은 남고 3년 전 기록은 지워짐(보관 2년)', left.includes('age-1.5y') && !left.includes('age-3y'), left);
console.log(`\n통과 ${pass} / 실패 ${fail}`); process.exit(fail ? 1 : 0);
