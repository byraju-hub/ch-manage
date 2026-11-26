// 심방 수정·삭제와 세대원 순서 바꾸기: 저장소 코드를 vite 로 불러와 확인 (프로젝트 폴더에서 실행)
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
const require = createRequire(process.cwd() + '/package.json');
const { createServer } = await import(pathToFileURL(require.resolve('vite')).href);
const server = await createServer({ server: { middlewareMode: true, hmr: false }, appType: 'custom', logLevel: 'error' });
let pass = 0, fail = 0;
const ok = (n, c, x = '') => { c ? pass++ : fail++; console.log((c ? 'OK   ' : 'FAIL ') + n + (c ? '' : '  ' + JSON.stringify(x))); };
try {
	const { registry: R } = await server.ssrLoadModule('/src/lib/registry/store.svelte.ts');
	R.save = () => {};
	R.d.people = [{ id: 'a', name: '홍길동' }, { id: 'b', name: '김영희' }];
	R.d.households = [{ id: 'h', no: 'H-001' }];
	R.d.householdMembers = []; R.d.visits = []; R.d.events = []; R.d.accessLog = [];
	const base = { person_id: 'a', visit_date: '2026-11-01', kind: '일반', method: '대면', pastor: 'p', companions: null, scripture: null, hymn: null, follow_up: null };
	R.addVisit({ ...base, content: '22일 입원, 23일 수술' });
	const id = R.d.visits[0].id;
	const dates = () => R.d.events.map((e) => e.date).sort().join(',');
	ok('등록하면 일정 2건', R.d.events.length === 2, R.d.events);
	R.d.events.push({ id: 'manual', kind: '성도', date: '2026-11-30', title: '이사', person_id: 'a', note: '손으로 넣음' });
	R.updateVisit(id, { ...base, content: '22일 입원, 25일 퇴원' });
	ok('수정하면 빠진 일정은 지우고 새 일정은 넣음', dates() === '2026-11-22,2026-11-25,2026-11-30', R.d.events);
	ok('수정 내용이 저장됨', R.d.visits[0].content === '22일 입원, 25일 퇴원' && R.d.visits.length === 1);
	R.updateVisit(id, { ...base, visit_date: '2026-11-02', content: '22일 입원, 25일 퇴원' });
	ok('날짜를 고쳐도 일정이 겹치지 않고 메모의 날짜가 바뀜', R.d.events.length === 3 && R.d.events.filter((e) => e.note.includes('(2026-11-02)')).length === 2, R.d.events);
	R.addVisit({ ...base, visit_date: '2026-11-03', content: '22일 입원' });
	const second = R.d.visits[0].id;
	R.removeVisit(id);
	ok('삭제: 다른 기록에도 있는 일정과 손으로 넣은 일정은 남음', dates() === '2026-11-22,2026-11-30', R.d.events);
	R.removeVisit(second);
	ok('모두 삭제하면 심방 0건', R.d.visits.length === 0);
	ok('접속 기록에 수정·삭제가 남음', ['심방 수정', '심방 삭제'].every((a) => R.d.accessLog.some((l) => l.action === a)));

	R.addNonMember('h', '가', null, '본인'); R.addNonMember('h', '나', null, '자녀'); R.addNonMember('h', '다', null, '자녀');
	const names = () => R.membersOfHousehold('h').map((m) => m.name).join('');
	const idOf = (n) => R.d.householdMembers.find((m) => m.name === n).id;
	R.moveMember(idOf('다'), -1); ok('위로', names() === '가다나', names());
	R.moveMember(idOf('가'), 1); ok('아래로', names() === '다가나', names());
	R.moveMember(idOf('다'), -1); R.moveMember(idOf('나'), 1); ok('맨 끝에서는 그대로', names() === '다가나', names());
	R.removeFromHousehold(idOf('가')); R.addNonMember('h', '라', null, '자녀');
	ok('빼고 새로 넣으면 맨 아래, 번호 안 겹침', names() === '다나라' && new Set(R.membersOfHousehold('h').map((m) => m.sort_order)).size === 3, R.d.householdMembers);
	R.d.householdMembers.forEach((m) => (m.sort_order = 1)); R.moveMember(R.membersOfHousehold('h')[2].id, -1);
	ok('번호가 모두 같아도 순서가 바뀜', R.membersOfHousehold('h').map((m) => m.sort_order).join() === '1,2,3', R.d.householdMembers);
} finally { await server.close(); }
console.log(`\n통과 ${pass} / 실패 ${fail}`); process.exit(fail ? 1 : 0);
