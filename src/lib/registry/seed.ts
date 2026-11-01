// 모든 인물·전화·주소는 가상입니다.
import type * as T from './types.ts';
import { generate } from './generate.ts';
import { CATEGORIES, MEETING_OFFICERS, ORG_UNITS } from './config.ts';

const P = (o: Partial<T.Person> & { id: string; name: string }): T.Person => ({
	name_note: null, phone: null, gender: null, birth_date: null, email: null, has_account: false,
	birth_lunar: false, reg_no: null, address_zip: null, address1: null, address2: null,
	occupation: null, workplace: null, marital_status: null, wedding_date: null, previous_church: null,
	guide_person_id: null, guide_name: null, sms_opt_out: false, faith_checked_on: null, note: null,
	purged_on: null, photo: null, category_id: null, clergy_title: null, edu_dept: null, mission_society: null,
	big_family_head_id: null, big_family_relation: null, ...o
});

// 교인구분(3단계)과 교회 조직표는 config.ts 에서 만듦. 이름은 관리자 화면에서 바꿀 수 있음
export const categories: T.Category[] = structuredClone(CATEGORIES);
export const orgUnits: T.OrgUnit[] = structuredClone(ORG_UNITS);
export const meetings: T.MeetingOfficer[] = structuredClone(MEETING_OFFICERS);

export const people: T.Person[] = [
	P({ id: 'p1', name: '홍길동', gender: 'M', birth_date: '1962-04-05', phone: '010-0000-0001', has_account: true, reg_no: '0001', occupation: '자영업', marital_status: '기혼', wedding_date: '1988-05-14', email: 'gildong@example.com', category_id: 'm.a.a', mission_society: '3남전도회' }),
	P({ id: 'p2', name: '성춘향', gender: 'F', birth_date: '1965-09-12', birth_lunar: true, phone: '010-0000-0002', has_account: true, reg_no: '0002', occupation: '교사', workplace: '가상초등학교', marital_status: '기혼', wedding_date: '1988-05-14', category_id: 'm.a.a', mission_society: '5여전도회' }),
	P({ id: 'p3', name: '홍도령', gender: 'M', birth_date: '1992-07-20', phone: '010-0000-0003', has_account: true, reg_no: '0003', occupation: '개발자', workplace: '가상소프트', marital_status: '기혼', wedding_date: '2021-10-10', category_id: 'm.a.a', mission_society: '6남전도회' }),
	P({ id: 'p4', name: '김영수', name_note: 'A', gender: 'M', birth_date: '1948-02-01', phone: '010-0000-0004', reg_no: '0004', marital_status: '사별', category_id: 'm.a.a', mission_society: '1남전도회' }),
	P({ id: 'p5', name: '이순희', gender: 'F', birth_date: '1950-11-23', phone: '010-0000-0005', reg_no: '0005', marital_status: '기혼', wedding_date: '1975-03-01', category_id: 'm.a.a', mission_society: '1여전도회' }),
	P({ id: 'p6', name: '박지민', gender: 'F', birth_date: '1993-11-30', phone: '010-0000-0006', has_account: true, reg_no: '0006', occupation: '간호사', workplace: '가상병원', marital_status: '기혼', wedding_date: '2021-10-10', previous_church: '가상교회', category_id: 'm.a.a', mission_society: '10여전도회' }),
	P({ id: 'p7', name: '최민호', gender: 'M', birth_date: '2007-03-02', phone: '010-0000-0007', has_account: true, marital_status: '미혼', category_id: 'm.h.a', edu_dept: '중고등부' }),
	P({ id: 'p8', name: '정하늘', gender: 'F', birth_date: '2023-05-08', category_id: 'm.k.a', edu_dept: '유치부' }),
	P({ id: 'p9', name: '오새롬', gender: 'F', birth_date: '1995-06-15', phone: '010-0000-0009', occupation: '디자이너', marital_status: '미혼', faith_checked_on: '2026-09-27', guide_person_id: 'p2', previous_church: '가상교회', note: '직장 동료 소개로 방문', category_id: 'm.n.a', edu_dept: '청년부' }),
	P({ id: 'p10', name: '윤재석', gender: 'M', birth_date: '1958-02-09', phone: '010-0000-0010', reg_no: '0010', marital_status: '기혼', note: '건강 문제로 장기 결석', category_id: 'm.a.n', mission_society: '3남전도회' }),
	P({ id: 'p11', name: '강수진', gender: 'F', birth_date: '1980-06-30', phone: '010-0000-0011', reg_no: '0011', marital_status: '기혼', previous_church: '가상교회', category_id: 'move' }),
	P({ id: 'p12', name: '한나리', gender: 'F', birth_date: '1985-08-17', phone: '010-0000-0012', has_account: true, marital_status: '미혼', note: '이전 프로그램에서 가져온 자료, 등록일 미상', category_id: 'm.a.s' }),
	P({ id: 'p13', name: '김영수', name_note: 'B', gender: 'M', birth_date: '1975-12-24', phone: '010-0000-0013', reg_no: '0013', occupation: '회사원', marital_status: '기혼', category_id: 'm.a.a', mission_society: '5남전도회' }),
	// 교역자: 직책(clergy_title)은 구분·직분과 별개. 심방 기록은 전임 교역자까지만 볼 수 있음
	P({ id: 'p14', name: '정담임', gender: 'M', birth_date: '1972-02-10', phone: '010-0000-0014', has_account: true, reg_no: '0014', marital_status: '기혼', wedding_date: '2000-05-20', clergy_title: '담임목사', category_id: 'm.a.a' }),
	P({ id: 'p15', name: '송보관', gender: 'F', birth_date: '1950-03-03', reg_no: '0015', marital_status: '사별', note: '헌금·직분 이력이 있어 삭제하지 않고 보관', category_id: 'keep' }),
	P({ id: 'p16', name: '이교육', gender: 'F', birth_date: '1998-09-09', phone: '010-0000-0016', has_account: true, reg_no: '0016', marital_status: '미혼', clergy_title: '교육전도사', category_id: 'm.y.a', edu_dept: '청년부' }),
	P({ id: 'p17', name: '신고등', gender: 'M', birth_date: '2009-05-20', reg_no: '0017', category_id: 'm.h.a', edu_dept: '중고등부' }),
	P({ id: 'p18', name: '김부목', gender: 'M', birth_date: '1980-10-05', phone: '010-0000-0018', has_account: true, reg_no: '0018', marital_status: '기혼', wedding_date: '2008-04-26', clergy_title: '부목사', category_id: 'm.a.a' }),
	P({ id: 'p19', name: '최전임', gender: 'M', birth_date: '1988-03-14', phone: '010-0000-0019', reg_no: '0019', marital_status: '미혼', clergy_title: '전임전도사', category_id: 'm.y.a', edu_dept: '청년부' }),
	P({ id: 'p20', name: '한사모', gender: 'F', birth_date: '1975-01-18', phone: '010-0000-0020', has_account: true, reg_no: '0020', marital_status: '기혼', wedding_date: '2000-05-20', category_id: 'm.a.a', mission_society: '5여전도회' })
];

export const households: T.Household[] = [
	{ id: 'h1', no: 'H-001', zip: '00000', address: '가상시 가상구 가상로 1', home_phone: '02-000-0001', note: null },
	{ id: 'h2', no: 'H-002', zip: '00000', address: '가상시 가상구 가상로 2', home_phone: null, note: null },
	{ id: 'h3', no: 'H-003', zip: '00000', address: '가상시 가상구 가상로 3', home_phone: null, note: null },
	{ id: 'h4', no: 'H-004', zip: '00000', address: '가상시 가상구 가상로 4', home_phone: null, note: null },
	{ id: 'h5', no: 'H-005', zip: '00000', address: '가상시 가상구 가상로 5 (사택)', home_phone: null, note: '사택' }
];

const HM = (id: string, household_id: string, person_id: string | null, relation: string, sort_order: number, extra: Partial<T.HouseholdMember> = {}): T.HouseholdMember => ({
	id, household_id, person_id, name: '', birth_year: null, relation, sort_order, note: null, ...extra
});
export const householdMembers: T.HouseholdMember[] = [
	HM('hm1', 'h1', 'p1', '본인', 1), HM('hm2', 'h1', 'p2', '배우자', 2), HM('hm3', 'h1', 'p3', '자녀', 3),
	HM('hm4', 'h1', 'p6', '며느리', 4), HM('hm5', 'h1', null, '부모', 5, { name: '홍만수', birth_year: 1935, note: '교인 아님' }),
	HM('hm6', 'h2', 'p4', '본인', 1), HM('hm7', 'h2', 'p5', '배우자', 2),
	HM('hm8', 'h3', 'p7', '본인', 1), HM('hm9', 'h3', 'p8', '형제자매', 2),
	HM('hm10', 'h4', 'p10', '본인', 1),
	HM('hm11', 'h5', 'p14', '본인', 1), HM('hm12', 'h5', 'p20', '배우자', 2)
];

let n = 0;
const id = (p: string) => `${p}${++n}`;
const S = (person_id: string, kind: T.StatusKind, event_date: string | null, reason: string | null = null, church: string | null = null): T.StatusEvent => ({ id: id('s'), person_id, kind, event_date, reason, church });
const F = (person_id: string, kind: T.FaithKind, event_date: string | null, church: string | null = null, officiant: string | null = null): T.FaithEvent => ({ id: id('f'), person_id, kind, event_date, church, officiant, note: null });
const O = (person_id: string, office: T.OfficeName, rank_status: T.OfficeStatus, start_date: string | null, end_date: string | null = null, church: string | null = null, note: string | null = null): T.OfficeTerm => ({ id: id('o'), person_id, office, rank_status, start_date, end_date, church, note });

export const statusEvents: T.StatusEvent[] = [
	S('p1', '등록', '1995-03-05'), S('p2', '등록', '1995-03-05'), S('p3', '등록', '1995-03-05', '부모와 함께 등록'),
	S('p4', '등록', '1985-01-06'), S('p5', '등록', '1985-01-06'),
	S('p6', '전입', '2020-04-12', '결혼 후 이사', '가상교회'), S('p7', '등록', '2010-05-02'), S('p8', '등록', '2023-06-04'),
	S('p9', '등록', '2026-09-27', '새가족'),
	S('p10', '등록', '2002-03-10'), S('p10', '보류', '2024-09-01', '장기 결석'),
	S('p11', '전입', '2010-01-17', null, '가상교회'), S('p11', '전출', '2026-03-29', '이사', '부산 가상교회'),
	S('p12', '등록', null, '이전 프로그램에서 가져옴'),
	S('p13', '등록', '2008-09-07'),
	S('p14', '전입', '2020-03-01', '부임', '가상교회'),
	S('p15', '등록', '1990-01-07'), S('p15', '보관', '2021-06-01', '삭제할 수 없는 교적'),
	S('p16', '등록', '2024-03-03', '교육전도사 부임'),
	S('p17', '등록', '2012-05-06'),
	S('p18', '전입', '2022-03-06', '부임', '가상교회'), S('p19', '등록', '2025-03-02', '전임전도사 부임'), S('p20', '전입', '2020-03-01', '부임', '가상교회')
];
export const faithEvents: T.FaithEvent[] = [
	F('p1', '세례', '1990-12-25', '가상교회', '가상목사'), F('p2', '세례', '1992-04-19'), F('p3', '유아세례', '1992-11-01'), F('p3', '입교', '2007-04-08'),
	F('p4', '세례', '1975-04-13'), F('p5', '세례', '1976-04-11'), F('p6', '세례', '2013-05-05', '가상교회'),
	F('p7', '학습', '2022-04-10'), F('p8', '유아세례', '2023-12-03'),
	F('p10', '세례', '2003-04-20'), F('p11', '세례', '2009-12-25', '가상교회'), F('p13', '세례', '2009-04-12'),
	F('p14', '세례', '1998-12-25', '가상교회'), F('p15', '세례', '1988-04-03'), F('p16', '세례', '2016-04-10'), F('p17', '학습', '2023-04-09'),
	F('p18', '세례', '2000-04-23', '가상교회'), F('p19', '세례', '2006-04-16', '가상교회'), F('p20', '세례', '1996-04-07', '가상교회')
];
export const officeTerms: T.OfficeTerm[] = [
	O('p1', '시무집사', '시무', '2000-01-02', '2008-12-31'), O('p1', '장로', '시무', '2009-01-04'),
	O('p2', '권사', '시무', '2012-01-01'), O('p3', '서리집사', '서리', '2022-01-02'),
	O('p4', '장로', '시무', '1995-01-01', '2013-12-31'), O('p4', '장로', '원로', '2014-01-05'),
	O('p5', '권사', '시무', '1998-01-01', '2020-12-31'), O('p5', '권사', '은퇴', '2021-01-03'),
	O('p6', '서리집사', '서리', '2022-01-02'), O('p10', '서리집사', '서리', '2005-01-02', '2024-08-31'), O('p10', '서리집사', '사임', '2024-09-01'),
	// 타교 임직은 구분이 아니라 '임직교회' 칸으로 표시
	O('p13', '서리집사', '서리', null, null, '가상교회'),
	O('p14', '목사', '시무', '2020-03-01'), O('p18', '목사', '시무', '2022-03-06'), O('p16', '전도사', '시무', '2024-03-03'), O('p19', '전도사', '시무', '2025-03-02'),
	O('p20', '사모', '시무', '2020-03-01'),
	// 휴직·무임은 구분을 늘리지 않고 비고에 적음
	O('p15', '권사', '시무', '1999-01-03', '2018-12-31'), O('p15', '권사', '명예', '2019-01-06', null, null, '휴직 후 명예 전환')
];
export const personGroups: T.PersonGroup[] = [
	{ person_id: 'p1', community: '줄기 공동체', small_group: '엎드림' }, { person_id: 'p2', community: '줄기 공동체', small_group: '엎드림' },
	{ person_id: 'p3', community: '열매 공동체', small_group: '훈남' }, { person_id: 'p4', community: '뿌리 공동체', small_group: '만나' },
	{ person_id: 'p5', community: '뿌리 공동체', small_group: '만나' }, { person_id: 'p6', community: '열매 공동체', small_group: '훈남' },
	{ person_id: 'p10', community: '줄기 공동체', small_group: '미션' },
	{ person_id: 'p12', community: '열매 공동체', small_group: '푸풀' }, { person_id: 'p13', community: '열매 공동체', small_group: '광야' }
];
export const notes: T.Note[] = [
	{ id: 'n1', person_id: 'p9', body: '첫 방문. 새가족 교육 안내 완료.', author: '교적 담당 간사', created_at: '2026-09-27' },
	{ id: 'n2', person_id: 'p10', body: '전화 연결 안 됨. 소그룹 인도자가 안부 확인 예정.', author: '교적 담당 간사', created_at: '2026-10-05' }
];
export const legacyMemos: T.LegacyMemo[] = [
	{ id: 'lm1', person_id: 'p10', body: '(이전 프로그램) 2019년 가정 심방 시 부친 소천, 위로 기도.' }
];
// 새 시스템 시작 시 일괄 동의로 받음 (운영 방침에 기록). 일부러 몇 명은 동의가 없는 상태로 둠
const batch = (person_id: string): T.Consent => ({ id: `cb-${person_id}`, person_id, kind: '교적 등록', policy_version: '2026-01', consent_date: '2026-10-01', method: '서면', guardian_name: null, revoked_on: null });
export const consents: T.Consent[] = [
	...['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p10', 'p13', 'p14', 'p16', 'p18', 'p20'].map(batch),
	{ id: 'c1', person_id: 'p9', kind: '교적 등록', policy_version: '2026-01', consent_date: '2026-09-27', method: '서면', guardian_name: null, revoked_on: null },
	{ id: 'c2', person_id: 'p9', kind: '요람 공개', policy_version: '2026-01', consent_date: '2026-09-27', method: '서면', guardian_name: null, revoked_on: null },
	{ id: 'c3', person_id: 'p8', kind: '보호자 동의', policy_version: '2026-01', consent_date: '2026-10-01', method: '서면', guardian_name: '정가상', revoked_on: null }
];
export const changeLog: T.ChangeLogEntry[] = [
	{ id: 'cl1', person_id: 'p10', at: '2026-10-05 10:12', by: '교적 담당 간사', field: '비고', before: null, after: '건강 문제로 장기 결석' }
];
export const accessLog: T.AccessLogEntry[] = [];

export const visits: T.Visit[] = [
	{ id: 'vi1', person_id: 'p10', visit_date: '2026-10-08', kind: '병문안', method: '대면', pastor: '정담임', companions: '이순희', scripture: '시편 23편', hymn: '찬 305장', content: '자택 방문. 건강 상태 확인, 회복을 위해 기도.', follow_up: '2주 뒤 재방문, 병원 동행 여부 확인' },
	{ id: 'vi2', person_id: 'p9', visit_date: '2026-10-04', kind: '새신자', method: '대면', pastor: '정담임', companions: null, scripture: null, hymn: null, content: '교회 소개 및 새가족 교육 안내.', follow_up: null }
];
export const educationCourses: T.EducationCourse[] = [{ id: 'ec1', name: '새가족 교육' }];
export const educationRecords: T.EducationRecord[] = [
	{ id: 'er1', person_id: 'p9', course_id: 'ec1', start_date: '2026-10-04', end_date: null, completed: false, instructor: '정담임', note: '진행 중' },
	{ id: 'er2', person_id: 'p6', course_id: 'ec1', start_date: '2020-05-03', end_date: '2020-06-07', completed: true, instructor: '정담임', note: null }
];
export const affiliations: T.Affiliation[] = [
	{ id: 'af1', person_id: 'p2', org: '할렐루야 찬양대', role: '소프라노 파트장', start_date: '2015-01-01', end_date: null },
	{ id: 'af2', person_id: 'p3', org: '방송부', role: '부원', start_date: '2022-03-01', end_date: null },
	{ id: 'af3', person_id: 'p1', org: '국내 선교부', role: '부장', start_date: '2020-01-01', end_date: null }
];

// ---- 실제 규모(재적 357명)의 가상 교인을 위 시험용 인물 뒤에 덧붙임 ----
// 재적 교인을 357명으로 맞춤: 위 시험용 인물 중 재적인 사람을 빼고 나머지를 생성기가 채움
// (장년은 거의 모두 소그룹에 배정. 주일 출석 약 210명, 교육부서 출석 약 55명이 되도록 출석률을 맞춤)
const TARGET_ACTIVE = 357;
const handActive = people.filter((p) => {
	const ev = statusEvents.filter((e) => e.person_id === p.id);
	return ['등록', '전입', '복적'].includes(ev.at(-1)?.kind ?? '');
}).length;
const gen = generate(people.map((p) => p.name), { targetActive: TARGET_ACTIVE - handActive });
people.push(...gen.people);
households.push(...gen.households);
householdMembers.push(...gen.householdMembers);
statusEvents.push(...gen.statusEvents);
faithEvents.push(...gen.faithEvents);
officeTerms.push(...gen.officeTerms);
personGroups.push(...gen.personGroups);
notes.push(...gen.notes);
legacyMemos.push(...gen.legacyMemos);
consents.push(...gen.consents);
visits.push(...gen.visits);
affiliations.push(...gen.affiliations);
educationRecords.push(...gen.educationRecords);
// 조직표 위원장·부서장·담당자 (가상 인물). 위원장은 시무 장로, 부서장은 시무집사·권사, 담당자는 장년 교인 1~2명
{
	const live = (office: string) => people.filter((x) => officeTerms.some((o) => o.person_id === x.id && o.office === office && o.rank_status === '시무' && !o.end_date));
	const elders = live('장로');
	const heads = [...live('시무집사'), ...live('권사')];
	const adults = people.filter((x) => x.category_id?.startsWith('m.a.') && x.birth_date && x.birth_date < '1990' && !heads.includes(x));
	let e = 0, h = 0, a = 7;
	for (const o of orgUnits) {
		if (!o.parent_id) { o.leader_id = elders.length ? elders[e++ % elders.length].id : null; continue; }
		o.leader_id = heads.length ? heads[(h += 5) % heads.length].id : null;
		const n = o.sort_order % 3 === 0 ? 2 : 1;
		o.staff_ids = Array.from({ length: n }, () => adults[(a += 11) % adults.length].id);
	}
}
export const smallGroups: T.SmallGroup[] = gen.smallGroups;

export const groupNotes: T.GroupNote[] = [
	{ id: 'gn0', person_id: 'p10', small_group: '미션', date: '2026-10-05', body: '건강 문제로 몇 주째 결석. 인도자가 연락 중.', author: '인도자' },
	...gen.groupNotes
];
export const events: T.ChurchEvent[] = gen.events;
// 출석 시험용: 이번 주일에 입력한 출석. 키 = 주일 날짜 → 사람 id
export const attendance: Record<string, Record<string, T.AttendanceMark>> = {};
export const generated = { absentStreak: gen.absentStreak, attendRate: gen.attendRate };
