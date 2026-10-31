// 샘플 데이터 만들기: 가상 자료(seed.ts)를 서버에 넣을 수 있는 모양으로 바꾼다. (ch-manage 전용 파일)
// 설정의 '샘플 데이터 불러오기'에서만 불러온다(동적 import). 실제 서비스 화면에는 이 자료가 들어 있지 않다.
//  - 자료마다 새 UUID 를 붙이고, 서로 가리키는 값(교인 id 등)도 함께 바꾼다.
//  - 출석은 '입력한 기록' 모양으로 최근 12주 만큼 만들어 넣는다. (실제 화면은 입력된 기록만 쓴다)
//  - 서버에 넣을 때 샘플 표시가 붙어서, 설정의 '샘플 데이터 삭제' 한 번으로 지울 수 있다.
import type { Data } from './store.svelte.ts';
import type * as T from './types.ts';
import { recentSundays } from './weeks.ts';

const WEEKS = 12;

type Rows = Record<string, unknown>[];

export async function buildSample(): Promise<Partial<Data>> {
	const seed = await import('./seed.ts');
	const cfg = await import('./config.ts');
	const att = await import('./sample-attendance.ts');
	const clone = <X>(x: X): X => structuredClone(x);

	const sets = {
		people: clone(seed.people) as unknown as Rows, households: clone(seed.households) as unknown as Rows,
		householdMembers: clone(seed.householdMembers) as unknown as Rows, statusEvents: clone(seed.statusEvents) as unknown as Rows,
		faithEvents: clone(seed.faithEvents) as unknown as Rows, officeTerms: clone(seed.officeTerms) as unknown as Rows,
		smallGroups: clone(seed.smallGroups) as unknown as Rows, groupNotes: clone(seed.groupNotes) as unknown as Rows,
		notes: clone(seed.notes) as unknown as Rows, legacyMemos: clone(seed.legacyMemos) as unknown as Rows,
		consents: clone(seed.consents) as unknown as Rows, visits: clone(seed.visits) as unknown as Rows,
		educationCourses: clone(seed.educationCourses) as unknown as Rows, educationRecords: clone(seed.educationRecords) as unknown as Rows,
		affiliations: clone(seed.affiliations) as unknown as Rows, events: clone(seed.events) as unknown as Rows,
		orgUnits: clone(seed.orgUnits) as unknown as Rows
	};
	const personGroups = clone(seed.personGroups);

	// 1) 새 id. 가리키는 대상 종류별로 옛 id → 새 id 표를 만든다.
	const maps: Record<'people' | 'households' | 'orgUnits' | 'courses', Map<string, string>> = { people: new Map(), households: new Map(), orgUnits: new Map(), courses: new Map() };
	const kindOf: Record<string, keyof typeof maps | null> = { people: 'people', households: 'households', orgUnits: 'orgUnits', educationCourses: 'courses' };
	for (const [name, rows] of Object.entries(sets)) {
		const kind = kindOf[name];
		for (const r of rows) {
			const old = r.id as string;
			const fresh = crypto.randomUUID();
			if (kind) maps[kind].set(old, fresh);
			r.id = fresh;
		}
	}
	const P = (id: unknown) => (typeof id === 'string' ? (maps.people.get(id) ?? null) : null);

	// 2) 가리키는 값 바꾸기
	for (const r of sets.people) { r.guide_person_id = P(r.guide_person_id); r.big_family_head_id = P(r.big_family_head_id); }
	for (const r of sets.householdMembers) { r.household_id = maps.households.get(r.household_id as string) ?? r.household_id; r.person_id = P(r.person_id); }
	for (const name of ['statusEvents', 'faithEvents', 'officeTerms', 'notes', 'legacyMemos', 'consents', 'visits', 'affiliations', 'events', 'groupNotes', 'educationRecords'] as const)
		for (const r of sets[name]) r.person_id = P(r.person_id);
	for (const r of sets.educationRecords) r.course_id = maps.courses.get(r.course_id as string) ?? r.course_id;
	for (const r of sets.smallGroups) r.leader_id = P(r.leader_id);
	for (const r of sets.orgUnits) {
		r.parent_id = r.parent_id ? (maps.orgUnits.get(r.parent_id as string) ?? null) : null;
		r.leader_id = P(r.leader_id); r.clergy_id = P(r.clergy_id);
		r.staff_ids = ((r.staff_ids as string[]) ?? []).map(P).filter(Boolean);
	}
	const reverse = new Map([...maps.people].map(([old, fresh]) => [fresh, old]));
	const pgs: T.PersonGroup[] = personGroups.map((g) => ({ ...g, person_id: P(g.person_id) ?? g.person_id }));

	// 3) 출석: 교인(재적) 최근 12주. 소그룹에 속한 사람은 소그룹 모임 출석도.
	const sundays = recentSundays(WEEKS);
	const inGroup = new Set(pgs.map((g) => g.person_id));
	const attendance: Data['attendance'] = {};
	const groupAttendance: NonNullable<Data['groupAttendance']> = {};
	for (const p of sets.people as unknown as T.Person[]) {
		if (!p.category_id?.startsWith('m.')) continue;
		const old = reverse.get(p.id) ?? p.id;
		sundays.forEach((d, i) => {
			const back = WEEKS - 1 - i;
			(attendance[d] ??= {})[p.id] = { present: att.wasPresent(old, d, back), reason: null };
			if (inGroup.has(p.id)) (groupAttendance[d] ??= {})[p.id] = { present: att.wasPresentGroup(old, d, back), reason: null };
		});
	}

	return {
		...(sets as unknown as Partial<Data>),
		personGroups: pgs,
		communityList: cfg.COMMUNITY_DEFS.map((c) => ({ key: c.name, name: c.name, from: c.from, to: c.to })),
		attendance, groupAttendance
	};
}
