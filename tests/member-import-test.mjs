// 교인 엑셀 일괄 등록 읽기 시험: node --experimental-strip-types tests/member-import-test.mjs
globalThis.$state = (v) => v; // 화면 부품 없이 읽기 코드만 시험하려고 룬 함수를 흉내 냄
const { parseMembers, findCategory } = await import('../src/lib/registry/member-import.ts');
const { CATEGORIES } = await import('../src/lib/registry/config.ts');
let pass = 0, fail = 0; const ok = (n, c, x = '') => { c ? pass++ : fail++; console.log((c ? 'OK   ' : 'FAIL ') + n + (c ? '' : ' ' + JSON.stringify(x))); };
const head = ['이름', '동명이인구분', '성별', '생년월일', '나이', '휴대폰', '주소', '직분', '상태', '교인구분', '전도회', '신급', '교구', '구역', '등록일', '차량번호'];
const d = (y, m, dd) => new Date(Date.UTC(y, m - 1, dd));
const { rows, error } = parseMembers([{ sheet: 's', data: [head,
  ['홍길동', '', '남', d(1962, 4, 5), 63, 1012345678, '서울시 가나구 1', '시무장로', '재적', '교인 › 장년 › 예배출석', '3남전도회', '세례교인', '열매', '나눔', '2005.3.6', '12가 3456'],
  ['김영수', 'A', '남자', '1948-02-01', 78, '010-0000-0004', '', '은퇴집사', '별세', '장년 › 불출석', '', '원입교인', '', '', '', ''],
  ['오새롬', '', '여', '19950615', '', '', '', '집사', '', '새가족', '', '미확인', '', '', '2026-13-40', ''],
  ['이교육', '', '여', '', '', '', '', '담임목사', '', '청년부', '', '', '', '', '', ''],
  ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
  ['최민호', '', '?', '', '', '', '', '권사(원로)', '이상한상태', '', '', '', '', '', '', '']] }]);
ok('머리글을 찾음', !error && rows.length === 5, error);
const [a, b, c, e, f] = rows;
ok('홍길동: 전화 0 복원·하이픈, 날짜 칸, 직분', a.phone === '010-1234-5678' && a.birth_date === '1962-04-05' && a.office === '장로' && a.office_status === '시무', a);
ok('등록일 2005.3.6 → 2005-03-06, 신급 세례, 차량번호', a.reg_date === '2005-03-06' && a.faith_kind === '세례' && a.car_number === '12가 3456' && a.mission_society === '3남전도회', a);
ok('교구·구역 칸', a.community === '열매' && a.small_group === '나눔', a);
ok('김영수: 동명이인 A, 은퇴집사=시무집사 은퇴, 별세, 원입교인', b.name_note === 'A' && b.office === '시무집사' && b.office_status === '은퇴' && b.status === '별세' && b.faith_checked && b.gender === 'M', b);
ok('오새롬: 19950615, 집사=서리집사, 잘못된 날짜는 경고', c.birth_date === '1995-06-15' && c.office === '서리집사' && c.reg_date === null && c.warns.some((w) => w.includes('등록일')), c);
ok('담임목사 → 목사 + 직책', e.office === '목사' && e.clergy === '담임목사', e);
ok('완전히 빈 줄은 목록에 넣지 않음', !rows.some((r) => r.line === 6));
ok('모르는 성별·상태는 경고', f.gender === null && f.warns.some((w) => w.includes('성별')) && f.warns.some((w) => w.includes('상태')) && f.office === '권사' && f.office_status === '시무' && f.warns.some((w) => w.includes('원로')), f);
const cats = CATEGORIES;
ok('교인구분 찾기: 전체 경로', findCategory(cats, '교인 › 장년 › 예배출석') === 'm.a.a', findCategory(cats, '교인 › 장년 › 예배출석'));
ok('교인구분 찾기: 장년 › 불출석, 새가족, 청년부', findCategory(cats, '장년 › 불출석') === 'm.a.n' && findCategory(cats, '새가족') === 'm.n.a' && findCategory(cats, '청년부') === 'm.y.a');
ok('교인구분 못 찾으면 null', findCategory(cats, '없는구분') === null && findCategory(cats, '') === null);
ok('이름 없는 표는 오류 메시지', parseMembers([{ sheet: 's', data: [['a', 'b']] }]).error?.includes('이름'));
console.log(`\n통과 ${pass} / 실패 ${fail}`); process.exit(fail ? 1 : 0);
