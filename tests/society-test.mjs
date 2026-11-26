// 전도회(선교회) 이름 바꾸기: 저장소 코드를 vite 로 불러와 확인 — node tests/society-test.mjs (프로젝트 폴더에서)
import { createServer } from 'vite';
const server = await createServer({ server: { middlewareMode: true, hmr: false }, appType: 'custom', logLevel: 'error' });
let pass = 0, fail = 0;
const ok = (n, c, x = '') => { c ? pass++ : fail++; console.log((c ? 'OK   ' : 'FAIL ') + n + (c ? '' : '  ' + JSON.stringify(x))); };
try {
	const { registry: R } = await server.ssrLoadModule('/src/lib/registry/store.svelte.ts');
	const { auth } = await server.ssrLoadModule('/src/lib/registry/auth.svelte.ts');
	const { guessGender } = await server.ssrLoadModule('/src/lib/registry/site-settings.ts');
	R.save = () => {};
	auth.user = { id: 1, is_super: true, status: '사용' };
	auth.perms = { edit: true, export: true, visit: true, admin: true };
	R.d.people = [{ id: 'a', mission_society: '1남전도회' }, { id: 'b', mission_society: '1여전도회' }, { id: 'c', mission_society: '2남전도회' }, { id: 'd', mission_society: null }];
	R.d.accessLog = []; R.d.communityList = []; R.d.churchInfo = undefined; R.d.codes = undefined; R.d.yearSettings = {};
	const year = new Date().getFullYear();
	const rows = () => R.codes.missionSocieties.map((n) => ({ orig: n, name: n, gender: R.societyGender(n) }));
	const of = (id) => R.d.people.find((p) => p.id === id).mission_society;

	ok('남·여 짐작', guessGender('3남전도회') === 'M' && guessGender('3여선교회') === 'F' && guessGender('여호수아 남선교회') === 'M' && guessGender('마리아') === '', null);
	ok('용어 기본값은 전도회', R.tm === '전도회' && R.tr('남전도회') === '남전도회');
	const before = R.settingsFor(year).societies['1남전도회'];
	let list = rows();
	list[0].name = '베드로'; list.find((r) => r.orig === '1여전도회').name = '마리아';
	ok('이름 바꾸기 성공', R.saveSocieties(list) === '');
	ok('교인 카드 이름이 따라 바뀜', of('a') === '베드로' && of('b') === '마리아' && of('c') === '2남전도회' && of('d') === null, R.d.people);
	ok('남·여 글자가 없어도 구분 유지', R.societyGender('베드로') === 'M' && R.societyGender('마리아') === 'F');
	ok('저장한 적 없는 올해 출생 연도 기준도 새 이름으로 옮김', JSON.stringify(R.settingsFor(year).societies['베드로']) === JSON.stringify(before) && !R.settingsFor(year).societies['1남전도회'], R.settingsFor(year).societies);
	list = rows(); list.find((r) => r.orig === '베드로').name = '2남전도회'; list.find((r) => r.orig === '2남전도회').name = '베드로';
	ok('두 이름 맞바꾸기', R.saveSocieties(list) === '' && of('a') === '2남전도회' && of('c') === '베드로', R.d.people);
	list = rows(); list[0].name = list[1].name;
	ok('같은 이름 두 번은 거절', R.saveSocieties(list) !== '');
	list = rows(); list[0].name = '  ';
	ok('빈 이름은 거절', R.saveSocieties(list) !== '');
	ok('구분 없는 새 모임은 거절', R.saveSocieties([...rows(), { orig: '', name: '한나', gender: '' }]) !== '');
	auth.user = { id: 2, is_super: false, status: '사용' }; auth.perms = { edit: false, export: false, visit: false, admin: true };
	list = rows(); list[0].name = '안드레';
	ok('수정 권한 없이 교인이 있는 모임 이름은 못 바꿈', R.saveSocieties(list) !== '' && R.codes.missionSocieties[0] !== '안드레');
	R.d.churchInfo = { society: '선교회' };
	ok('용어를 선교회로 하면 칸 이름이 바뀜', R.tm === '선교회' && R.tr('전도회') === '선교회');
} finally { await server.close(); }
console.log(`\n통과 ${pass} / 실패 ${fail}`); process.exit(fail ? 1 : 0);
