// 실제 교회 규모(재적 723명, 주일 출석 약 430명)의 가상 교적 데이터를 만듭니다.
// - 이름·전화·주소는 모두 만들어 낸 값이며 실제 교인 자료를 쓰지 않습니다.
// - 같은 시드로 만들기 때문에 열 때마다 같은 사람들이 나옵니다. (날짜는 오늘 기준으로 맞춤)
// - 공동체·소그룹 구성(3개 공동체, 소그룹 27개 + 로뎀나무숲)만 2026년 편성표의 구조를 따릅니다.
import type * as T from './types.ts';
import { COMMUNITY_DEFS, ORG_UNITS, catId, fixCat, type GroupDef, type L2Code } from './config.ts';
import { churchSeasons, isoDate, nthSunday } from './calendar.ts';

function mulberry32(a: number) {
	return () => {
		a |= 0; a = (a + 0x6d2b79f5) | 0;
		let t = Math.imul(a ^ (a >>> 15), 1 | a);
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
	};
}
const pad = (n: number) => String(n).padStart(2, '0');

type L2 = L2Code;
type L3 = 'a' | 's' | 'n' | 'l';

// 가상 '기타 의견': 소그룹 모임 전체에 대해 인도자가 적는 말 (특정 교인이 아님). 최근 주일 몇 번, 몇 개 소그룹만
export function groupOpinions(groups: { name: string; leader_id: string | null; care?: boolean }[], nameOf: (id: string) => string): T.GroupNote[] {
	const OP = ['다른 소그룹과 연합 모임으로 식사교제와 찬양 연습함.', '모임 장소를 교회 카페로 옮겨서 모임.', '새로 온 가정을 소개하고 함께 식사함.', '이번 주는 말씀 나눔 대신 중보기도 시간을 길게 가짐.', '다음 달 야외 모임 계획을 함께 정함.'];
	const out: T.GroupNote[] = [];
	const sunday = (weeksBack: number) => { const d = new Date(); d.setDate(d.getDate() - d.getDay() - 7 * weeksBack); return isoDate(d); };
	groups.filter((g) => !g.care).forEach((g, i) => {
		if (i % 4 !== 1) return;
		out.push({ id: `gop-${i}`, person_id: null, small_group: g.name, date: sunday(1 + (i % 3)), body: OP[i % OP.length], author: (g.leader_id && nameOf(g.leader_id)) || '인도자' });
	});
	return out;
}

// opts.targetActive: 생성기가 만드는 재적 교인 수(시험용 인물 제외). opts.groupP: 공동체별(뿌리·줄기·열매) 소그룹 소속 비율
export function generate(reservedNames: string[] = [], opts: { targetActive?: number; groupP?: [number, number, number] } = {}) {
	const GROUP_P = opts.groupP ?? [0.96, 0.96, 0.96]; // 장년은 거의 모두 소그룹에 배정
	const rnd = mulberry32(20261020);
	const ri = (a: number, b: number) => a + Math.floor(rnd() * (b - a + 1));
	const chance = (p: number) => rnd() < p;
	const pick = <X>(xs: readonly X[]): X => xs[Math.floor(rnd() * xs.length)];

	const NOW = new Date();
	const TODAY = isoDate(NOW);
	const THIS_YEAR = NOW.getFullYear();
	const daysAgo = (n: number) => { const d = new Date(NOW); d.setDate(d.getDate() - n); return isoDate(d); };
	const addDay = (iso: string, n: number) => { const d = new Date(iso); d.setDate(d.getDate() + n); return isoDate(d); };
	const dateIn = (y: number) => `${y}-${pad(ri(1, 12))}-${pad(ri(1, 28))}`;
	const clampPast = (iso: string) => (iso > daysAgo(3) ? daysAgo(ri(3, 40)) : iso);
	const dateBetween = (a: string, b: string) => {
		const t0 = new Date(a).getTime(), t1 = new Date(b).getTime();
		return isoDate(new Date(t0 + rnd() * Math.max(0, t1 - t0)));
	};

	// ---------- 이름 ----------
	const SUR = '김김김김김이이이이이박박박박최최최정정정강조윤장임한오서신권황안송류전고문양손배백허유남심노하곽성차주우구나민'.split('');
	const M_NEW = [['민', '서', '준', '지', '현', '도', '성', '재', '우', '태', '승', '동'], ['호', '우', '훈', '석', '민', '준', '수', '혁', '진', '환', '규', '범']];
	const M_OLD = [['영', '종', '병', '창', '상', '광', '용', '석', '기', '명'], ['수', '호', '식', '철', '환', '규', '근', '복', '길', '만']];
	const F_NEW = [['서', '지', '수', '예', '은', '하', '유', '민', '다', '소'], ['연', '윤', '은', '진', '영', '현', '서', '빈', '아', '희', '린']];
	const F_OLD = [['영', '순', '정', '금', '옥', '미', '경', '춘', '복', '숙'], ['자', '순', '희', '숙', '애', '심', '임', '분', '란']];
	const reserved = new Set(reservedNames);
	const makeName = (surname: string, gender: 'M' | 'F', birth: number) => {
		const t = gender === 'M' ? (birth < 1966 ? M_OLD : M_NEW) : birth < 1966 ? F_OLD : F_NEW;
		for (let i = 0; i < 20; i++) {
			const n = surname + pick(t[0]) + pick(t[1]);
			if (!reserved.has(n)) return n;
		}
		return surname + pick(t[0]) + pick(t[1]);
	};

	// ---------- 결과 ----------
	const people: T.Person[] = [];
	const households: T.Household[] = [];
	const householdMembers: T.HouseholdMember[] = [];
	const statusEvents: T.StatusEvent[] = [];
	const faithEvents: T.FaithEvent[] = [];
	const officeTerms: T.OfficeTerm[] = [];
	const personGroups: T.PersonGroup[] = [];
	const groupNotes: T.GroupNote[] = [];
	const notes: T.Note[] = [];
	const legacyMemos: T.LegacyMemo[] = [];
	const consents: T.Consent[] = [];
	const visits: T.Visit[] = [];
	const affiliations: T.Affiliation[] = [];
	const events: T.ChurchEvent[] = [];
	const educationRecords: T.EducationRecord[] = [];
	const absentStreak: Record<string, number> = {};
	const attendRate: Record<string, number> = {};

	let nP = 0, nH = 5, nE = 0, nPhone = 1000, nReg = 100;
	const eid = (p: string) => `${p}g${++nE}`;
	const ageAt = (birth: number) => THIS_YEAR - birth;
	const birthOf = (p: T.Person) => Number(p.birth_date!.slice(0, 4));
	const commOf = (birth: number) => COMMUNITY_DEFS.findIndex((c) => birth >= c.from && birth <= c.to);
	const byId = new Map<string, T.Person>();

	function pickGroup(ci: number, care = false) {
		const gs = COMMUNITY_DEFS[ci].groups.filter((g) => !!g.care === care);
		const total = gs.reduce((s, g) => s + g.size, 0);
		let r = rnd() * total;
		for (const g of gs) { r -= g.size; if (r <= 0) return g; }
		return gs[gs.length - 1];
	}

	interface Mk { surname: string; gender: 'M' | 'F'; birth: number; previous?: boolean; marital?: T.MaritalStatus | null; wedding?: string | null; hasReg?: boolean }
	const JOBS = ['회사원', '자영업', '교사', '공무원', '간호사', '엔지니어', '주부', '프리랜서', '연구원', '의료인', '디자이너', '회계사'];

	function mkPerson(o: Mk): T.Person {
		const id = `g${++nP}`;
		const age = ageAt(o.birth);
		const hasPhone = age >= 14 && !chance(0.04);
		const p: T.Person = {
			id, name: makeName(o.surname, o.gender, o.birth), name_note: null,
			phone: hasPhone ? `010-0000-${++nPhone}` : null, gender: o.gender,
			birth_date: `${o.birth}-${pad(ri(1, 12))}-${pad(ri(1, 28))}`, email: null, has_account: hasPhone && age < 72 && chance(0.55),
			birth_lunar: age >= 55 && chance(0.15), reg_no: o.hasReg === false ? null : String(++nReg).padStart(4, '0'),
			address_zip: null, address1: null, address2: null,
			occupation: age >= 24 && age < 66 ? pick(JOBS) : age >= 8 && age < 24 ? '학생' : null,
			workplace: null, marital_status: o.marital ?? (age < 24 ? '미혼' : null), wedding_date: o.wedding ?? null,
			previous_church: o.previous ? `가상교회${ri(1, 9)}` : null, guide_person_id: null, guide_name: null,
			sms_opt_out: age >= 14 && chance(0.02), faith_checked_on: null, note: null, purged_on: null,
			photo: null, category_id: null, clergy_title: null, edu_dept: null, mission_society: null,
			big_family_head_id: null, big_family_relation: null
		};
		people.push(p);
		byId.set(p.id, p);
		return p;
	}

	const ORDAINED: T.OfficeName[] = ['장로', '시무집사', '권사'];
	function addStatus(pid: string, kind: T.StatusKind, date: string | null, reason: string | null = null, church: string | null = null) {
		statusEvents.push({ id: eid('s'), person_id: pid, kind, event_date: date, reason, church });
	}
	function addFaithEv(pid: string, kind: T.FaithKind, date: string | null, church: string | null = null) {
		faithEvents.push({ id: eid('f'), person_id: pid, kind, event_date: date, church, officiant: church ? null : '가상목사', note: null });
	}
	function addOffice(pid: string, office: T.OfficeName, st: T.OfficeStatus, start: string | null, end: string | null = null) {
		const church = ORDAINED.includes(office) && chance(0.15) ? `가상교회${ri(1, 9)}` : null;
		officeTerms.push({ id: eid('o'), person_id: pid, office, rank_status: st, start_date: start, end_date: end, church, note: null });
	}

	// 신급 이력: 나이대에 맞춰 세례·학습 등을 부여
	function giveFaith(p: T.Person, birth: number, reg: string, fresh: boolean) {
		const age = ageAt(birth);
		if (age <= 12) {
			if (chance(0.7)) addFaithEv(p.id, '유아세례', clampPast(dateIn(birth + ri(0, Math.min(2, Math.max(0, age))))));
			return;
		}
		if (age <= 19) {
			if (chance(0.3)) addFaithEv(p.id, '학습', clampPast(dateIn(birth + ri(12, Math.min(15, age)))));
			if (age >= 15 && chance(0.4)) addFaithEv(p.id, '입교', clampPast(dateIn(birth + ri(15, age))));
			return;
		}
		const r = rnd();
		if (fresh) {
			if (r < 0.25) addFaithEv(p.id, '세례', clampPast(dateIn(birth + ri(20, age))), `가상교회${ri(1, 9)}`);
			else if (r < 0.65) p.faith_checked_on = reg;
			return;
		}
		if (r < 0.88) addFaithEv(p.id, '세례', clampPast(dateIn(Math.min(THIS_YEAR - 1, birth + ri(16, Math.max(17, Math.min(age, 45)))))));
		else if (r < 0.94) addFaithEv(p.id, '학습', clampPast(dateIn(THIS_YEAR - ri(1, 5))));
	}

	// 직분 이력: 나이와 성별로 가능성을 정함 (임직일은 나이에서 거꾸로 계산)
	function giveOffice(p: T.Person, birth: number) {
		const age = ageAt(birth);
		const male = p.gender === 'M';
		const y = (a: number) => dateIn(birth + a);
		if (male && age >= 50 && chance(0.08)) { // 장로
			const sa = ri(48, Math.min(60, age - 1));
			if (chance(0.7)) addOffice(p.id, '시무집사', '시무', y(sa - ri(6, 12)), y(sa));
			if (age >= 71) {
				addOffice(p.id, '장로', '시무', y(sa), y(70));
				addOffice(p.id, '장로', chance(0.12) ? '원로' : '은퇴', `${birth + 71}-01-07`);
			} else addOffice(p.id, '장로', chance(0.05) ? '협동' : '시무', y(sa));
			return;
		}
		if (male && age >= 42 && chance(0.12)) { // 시무집사
			const sa = ri(40, Math.min(60, age - 1));
			addOffice(p.id, '서리집사', '서리', y(sa - ri(3, 8)), y(sa));
			if (age >= 71) { addOffice(p.id, '시무집사', '시무', y(sa), y(70)); addOffice(p.id, '시무집사', '은퇴', `${birth + 71}-01-07`); }
			else addOffice(p.id, '시무집사', '시무', y(sa));
			return;
		}
		if (!male && age >= 50 && chance(0.3)) { // 권사
			const sa = ri(48, Math.min(62, age - 1));
			if (age >= 71) { addOffice(p.id, '권사', '시무', y(sa), y(70)); addOffice(p.id, '권사', chance(0.15) ? '명예' : '은퇴', `${birth + 71}-01-07`); }
			else addOffice(p.id, '권사', '시무', y(sa));
			return;
		}
		if (age >= 35 && chance(male ? 0.14 : 0.17)) addOffice(p.id, '서리집사', '서리', y(ri(33, Math.min(55, age - 1))));
	}

	function newHousehold(address = true): T.Household {
		const n = ++nH;
		const h: T.Household = {
			id: `gh${n}`, no: `H-${String(n).padStart(3, '0')}`, zip: address ? '00000' : null,
			address: address ? `가상시 가상구 가상로 ${n * 3}` : null, home_phone: chance(0.25) ? `02-000-${1000 + n}` : null, note: null
		};
		households.push(h);
		return h;
	}
	function join(h: T.Household, p: T.Person | null, relation: string, extra: Partial<T.HouseholdMember> = {}) {
		householdMembers.push({
			id: eid('hm'), household_id: h.id, person_id: p?.id ?? null, name: '', birth_year: null, relation,
			sort_order: householdMembers.filter((m) => m.household_id === h.id).length + 1, note: null, ...extra
		});
	}

	// ---- 교인구분·교육부서·전도회 ----
	const eduDeptOf = (age: number): T.EduDept | null =>
		age <= 2 ? '영아부' : age <= 6 ? '유치부' : age <= 12 ? '유초등부' : age <= 18 ? '중고등부' : age <= 34 ? '청년부' : null;
	const deptCode = (age: number): L2 => (age <= 2 ? 'b' : age <= 6 ? 'k' : age <= 12 ? 'e' : 'h');
	function l2Of(p: T.Person, birth: number, fresh: boolean): L2 {
		const age = ageAt(birth);
		if (fresh) return 'n';
		if (age <= 18) return deptCode(age);
		if (age <= 34 && p.marital_status !== '기혼') return 'y';
		return 'a';
	}
	function missionOf(p: T.Person, age: number): string | null {
		if (age < 26 || !chance(0.78)) return null;
		if (p.gender === 'M') {
			const i = age >= 72 ? 1 : age >= 65 ? 2 : age >= 58 ? 3 : age >= 50 ? 4 : age >= 42 ? 5 : 6;
			return `${i}남전도회`;
		}
		return `${Math.max(1, Math.min(10, 1 + Math.floor((82 - age) / 4)))}여전도회`;
	}
	function l3Of(grouped: boolean): L3 {
		const r = rnd();
		return grouped ? (r < 0.66 ? 'a' : r < 0.93 ? 's' : 'n') : r < 0.45 ? 'a' : r < 0.78 ? 's' : 'n';
	}

	const careKids: T.Person[] = [];
	function assignGroup(p: T.Person, birth: number, forceGroup?: GroupDef) {
		const ci = commOf(birth);
		const g = forceGroup ?? pickGroup(ci);
		personGroups.push({ person_id: p.id, community: COMMUNITY_DEFS[ci].name, small_group: g.name });
	}

	// ----- 한 세대 만들기 -----
	type Kind = 'couple_kids' | 'couple' | 'single' | 'single_parent' | 'three_gen';
	function makeHousehold(headBirth: number, kind: Kind, fresh: 'recent' | 'year' | null = null) {
		const h = newHousehold();
		const surname = pick(SUR);
		const headMale = kind === 'single_parent' ? chance(0.2) : kind === 'single' ? chance(0.5) : kind === 'three_gen' ? chance(0.85) : chance(0.8);
		const reg = fresh === 'recent' ? daysAgo(ri(2, 58)) : fresh === 'year' ? daysAgo(ri(62, 330)) : clampPast(dateBetween(`${Math.max(1990, headBirth + 22)}-01-01`, daysAgo(120)));
		const reason = fresh ? '새가족' : null;
		const ex = chance(0.18) && !fresh;
		const prevChurch = fresh ? chance(0.45) : false;
		const wedYear = headBirth + ri(25, 31);
		const wedding = kind === 'couple_kids' || kind === 'couple' || kind === 'three_gen' ? dateIn(Math.min(wedYear, THIS_YEAR - 1)) : null;
		const members: { p: T.Person; birth: number }[] = [];
		const register = (p: T.Person, birth: number, when: string) => {
			const transfer = !fresh && ex && ageAt(birth) >= 20;
			if (transfer) addStatus(p.id, '전입', when, null, `가상교회${ri(1, 9)}`);
			else addStatus(p.id, '등록', when, reason);
			giveFaith(p, birth, when, !!fresh);
			if (!fresh && ageAt(birth) >= 18) {
				giveOffice(p, birth);
				// 직분자는 세례(입교)를 받은 사람
				const holds = officeTerms.some((o) => o.person_id === p.id);
				if (holds && !faithEvents.some((f) => f.person_id === p.id && (f.kind === '세례' || f.kind === '입교'))) {
					addFaithEv(p.id, '세례', dateIn(birth + ri(18, 30)));
				}
			}
			members.push({ p, birth });
		};

		const head = mkPerson({
			surname, gender: headMale ? 'M' : 'F', birth: headBirth, previous: prevChurch,
			marital: kind === 'single' ? (headBirth < 1960 ? '사별' : '미혼') : kind === 'single_parent' ? (chance(0.5) ? '이혼' : '사별') : '기혼', wedding
		});
		register(head, headBirth, reg);
		join(h, head, '본인');

		let spouse: T.Person | null = null;
		if (kind === 'couple_kids' || kind === 'couple' || kind === 'three_gen') {
			const sb = headBirth + ri(-4, 4);
			spouse = mkPerson({ surname: pick(SUR), gender: headMale ? 'F' : 'M', birth: sb, previous: prevChurch, marital: '기혼', wedding });
			register(spouse, sb, reg);
			join(h, spouse, '배우자');
		}

		const kidsN = kind === 'couple_kids' ? ri(1, 3) : kind === 'single_parent' ? ri(1, 2) : kind === 'three_gen' ? ri(1, 2) : 0;
		const kids: { p: T.Person; birth: number }[] = [];
		for (let i = 0; i < kidsN; i++) {
			const kb = headBirth + ri(24, 38);
			if (kb > THIS_YEAR) continue;
			const kreg = reg > `${kb}-06-30` ? reg : dateBetween(`${kb}-03-01`, `${kb}-12-28`);
			const k = mkPerson({ surname: head.gender === 'M' ? surname : pick(SUR), gender: chance(0.5) ? 'M' : 'F', birth: kb, marital: '미혼' });
			register(k, kb, clampPast(kreg));
			join(h, k, '자녀');
			kids.push({ p: k, birth: kb });
		}
		// 3대: 부모님 한 분
		if (kind === 'three_gen') {
			const pb = headBirth - ri(24, 32);
			if (pb > 1925) {
				const par = mkPerson({ surname: head.gender === 'M' ? surname : pick(SUR), gender: chance(0.7) ? 'F' : 'M', birth: pb, marital: '사별' });
				register(par, pb, reg);
				join(h, par, '부모');
			}
		}
		// 교인이 아닌 세대원 (부모·형제)
		if (!fresh && chance(0.07)) join(h, null, pick(['부모', '형제자매']), { name: makeName(pick(SUR), 'M', 1950), birth_year: ri(1935, 1965), note: '교인 아님' });
		// 대가족: 같은 집이 아닌 부모 세대와 이어 두는 경우
		if (!fresh && kind === 'couple_kids' && chance(0.04)) {
			const other = people.find((x) => x.id !== head.id && x.gender === head.gender && birthOf(x) <= headBirth - 22 && !x.big_family_head_id);
			if (other) { head.big_family_head_id = other.id; head.big_family_relation = '자녀'; }
		}

		// ----- 교인구분·교육부서·전도회·소그룹 -----
		let pairGroup: GroupDef | undefined;
		for (const m of members) {
			const age = ageAt(m.birth);
			const l2 = l2Of(m.p, m.birth, !!fresh);
			m.p.edu_dept = l2 === 'a' ? null : eduDeptOf(age);
			if (l2 === 'a') m.p.mission_society = missionOf(m.p, age);
			let grouped = false;
			if (l2 === 'a' && age >= 20 && chance(GROUP_P[commOf(m.birth)])) {
				grouped = true;
				// 부부는 같은 소그룹
				const isCouple = (m.p === head || m.p === spouse) && spouse;
				if (isCouple) {
					if (!pairGroup || commOf(m.birth) !== commOf(headBirth)) pairGroup = pickGroup(commOf(m.birth));
					assignGroup(m.p, m.birth, pairGroup);
				} else assignGroup(m.p, m.birth);
			}
			m.p.category_id = catId('m', l2, fresh ? (chance(0.85) ? 'a' : 's') : l3Of(grouped));
			attendRate[m.p.id] = Math.min(0.99, 0.5 + rnd() * 0.5);
		}
		// 자녀돌봄 후보: 열매 공동체 부모의 어린 자녀 (나중에 정해진 인원만큼 편성)
		for (const k of kids) {
			const a = ageAt(k.birth);
			if (a >= 3 && a <= 12 && [head, spouse].some((x) => x && commOf(birthOf(x)) === 2 && personGroups.some((g) => g.person_id === x.id))) careKids.push(k.p);
		}
		return { head, members };
	}

	const commKinds = (c: number, headBirth: number): Kind => {
		const r = rnd();
		if (c === 0) return r < 0.42 ? 'couple' : r < 0.78 ? 'single' : 'three_gen';
		if (c === 1) return r < 0.52 ? 'couple_kids' : r < 0.68 ? 'couple' : r < 0.82 ? 'three_gen' : r < 0.92 ? 'single' : 'single_parent';
		if (headBirth >= 1990) return r < 0.58 ? 'single' : r < 0.78 ? 'couple' : 'couple_kids';
		return r < 0.72 ? 'couple_kids' : r < 0.84 ? 'couple' : r < 0.92 ? 'single_parent' : 'single';
	};
	const headBirthFor = (c: number) => (c === 0 ? ri(1930, 1955) : c === 1 ? ri(1956, 1970) : chance(0.3) ? ri(1990, 2003) : ri(1971, 1989));

	// 일반 세대
	const actives = () => statusEvents.filter((e) => e.kind === '등록' || e.kind === '전입').length;
	const mainStart = households.length;
	while (actives() < (opts.targetActive ?? 900) + 40) {
		const c = pick([0, 0, 0, 0, 1, 1, 1, 1, 1, 2, 2, 2]);
		const hb = headBirthFor(c);
		makeHousehold(hb, commKinds(c, hb));
	}
	const mainHouseholds = households.slice(mainStart);
	// 새가족: 최근 60일 안에 등록 10세대, 그 전 1년 안에 등록 30세대
	const freshMembers: T.Person[] = [];
	for (let i = 0; i < 40; i++) {
		const hb = chance(0.2) ? ri(1960, 1975) : ri(1976, 2002);
		const k: Kind = hb >= 1990 ? pick(['single', 'single', 'couple', 'couple_kids']) : pick(['couple_kids', 'couple', 'single']);
		const r = makeHousehold(hb, k, i < 10 ? 'recent' : 'year');
		freshMembers.push(...r.members.map((m) => m.p));
	}
	// 새가족 인도자·동의 기록·새가족 교육
	const guides = people.filter((p) => ageAt(birthOf(p)) >= 30 && personGroups.some((g) => g.person_id === p.id) && !freshMembers.includes(p));
	for (const p of freshMembers) {
		const first = statusEvents.find((e) => e.person_id === p.id)!;
		const age = ageAt(birthOf(p));
		if (age >= 14 && chance(0.8)) p.guide_person_id = pick(guides).id;
		else if (chance(0.3)) p.guide_name = '지인 소개';
		const d = first.event_date ?? TODAY;
		consents.push({ id: eid('c'), person_id: p.id, kind: '교적 등록', policy_version: '2026-01', consent_date: d, method: '서면', guardian_name: null, revoked_on: null });
		if (chance(0.6)) consents.push({ id: eid('c'), person_id: p.id, kind: '요람 공개', policy_version: '2026-01', consent_date: d, method: '서면', guardian_name: null, revoked_on: null });
		if (age >= 14) {
			const start = d > daysAgo(10) ? d : addDay(d, 7);
			const recent = d > daysAgo(60);
			const done = !recent && chance(0.75);
			educationRecords.push({
				id: eid('er'), person_id: p.id, course_id: 'ec1', start_date: start, end_date: done ? clampPast(addDay(start, 35)) : null,
				completed: done, instructor: '정담임', note: done ? null : recent ? '진행 중' : '중도 이탈'
			});
		}
	}

	// 보류(소식이 뜸한 교인)
	const activeAdults = people.filter((p) => ageAt(birthOf(p)) >= 25 && !freshMembers.includes(p));
	for (let i = 0; i < 30; i++) {
		const p = pick(activeAdults);
		if (statusEvents.some((e) => e.person_id === p.id && e.kind === '보류')) continue;
		// 보류일은 등록일보다 뒤여야 함 (이력은 날짜순으로 계산하므로)
		const reg = statusEvents.find((e) => e.person_id === p.id)?.event_date ?? daysAgo(900);
		const from = reg > daysAgo(900) ? addDay(reg, 30) : daysAgo(900);
		if (from >= daysAgo(40)) continue;
		addStatus(p.id, '보류', dateBetween(from, daysAgo(40)), pick(['장기 결석', '연락 두절', '해외 체류']));
		const gi = personGroups.findIndex((g) => g.person_id === p.id);
		if (gi >= 0) personGroups.splice(gi, 1);
		p.category_id = p.category_id!.replace(/\.[asnl]$/, '.n');
	}

	// 재적에서 빠진 사람 (전출·별세·제적·보관)
	const gone = (kind: '전출' | '별세' | '제적' | '보관', n: number) => {
		for (let i = 0; i < n; i++) {
			const hb = kind === '별세' ? ri(1928, 1948) : ri(1950, 2000);
			const h = newHousehold();
			const p = mkPerson({ surname: pick(SUR), gender: chance(0.5) ? 'M' : 'F', birth: hb, marital: kind === '별세' ? '사별' : null });
			const reg = dateBetween(`${Math.max(1990, hb + 25)}-01-01`, daysAgo(900));
			addStatus(p.id, '등록', reg);
			const held = kind === '제적' ? dateBetween(reg, daysAgo(500)) : reg;
			if (kind === '제적') addStatus(p.id, '보류', held, '장기 결석');
			const start = daysAgo(kind === '별세' ? 1500 : 1000);
			const at = dateBetween(held > start ? addDay(held, 30) : start, daysAgo(20));
			addStatus(p.id, kind, at, kind === '전출' ? pick(['이사', '직장 이동', '교회 이전']) : kind === '별세' ? null : kind === '보관' ? '삭제할 수 없는 교적' : '장기 소식 두절', kind === '전출' ? `가상교회${ri(1, 9)}` : null);
			giveFaith(p, hb, reg, false);
			p.category_id = kind === '별세' ? 'dead' : kind === '전출' ? 'move' : 'keep';
			join(h, p, '본인');
		}
	};
	gone('전출', 34); gone('별세', 8); gone('제적', 14); gone('보관', 12);

	// 미등록·타교인: 예배에 나오지만 아직 등록하지 않았거나 다른 교회 교인
	for (let i = 0; i < 30; i++) {
		const hb = ri(1950, 2006);
		const age = ageAt(hb);
		const p = mkPerson({ surname: pick(SUR), gender: chance(0.5) ? 'M' : 'F', birth: hb, hasReg: false, marital: age >= 30 ? '기혼' : null });
		const l2: L2 = age <= 18 ? deptCode(age) : age <= 34 ? 'y' : chance(0.2) ? 'n' : 'a';
		p.edu_dept = l2 === 'a' ? null : eduDeptOf(age);
		p.category_id = 'o'; // 미등록/타교인은 1단계만
		p.note = chance(0.5) ? '타교인(출석교회 별도)' : '등록 의사 확인 필요';
	}

	// ---- 재적 인원 맞추기: 넘치는 일반 세대를 뒤에서부터 지우고, 모자라면 세대원 한 명씩 지움 ----
	if (opts.targetActive) {
		const ACTIVE = new Set<T.StatusKind>(['등록', '전입', '복적']);
		const lastKind = new Map<string, T.StatusKind>();
		for (const e of statusEvents) lastKind.set(e.person_id, e.kind);
		const isActive = (id: string) => ACTIVE.has(lastKind.get(id)!);
		let excess = people.filter((p) => isActive(p.id)).length - opts.targetActive;
		const drop = new Set<string>();
		const freshIds = new Set(freshMembers.map((p) => p.id));
		for (let i = mainHouseholds.length - 1; i >= 0 && excess > 0; i--) {
			const mem = householdMembers.filter((m) => m.household_id === mainHouseholds[i].id && m.person_id).map((m) => m.person_id!);
			const act = mem.filter(isActive).length;
			if (!act || act > excess) continue;
			mem.forEach((id) => drop.add(id));
			excess -= act;
		}
		for (const m of [...householdMembers].reverse()) {
			if (excess <= 0) break;
			if (m.person_id && m.relation !== '본인' && isActive(m.person_id) && !drop.has(m.person_id) && !freshIds.has(m.person_id)) { drop.add(m.person_id); excess--; }
		}
		const keep = <X>(arr: X[], ok: (x: X) => boolean) => { const k = arr.filter(ok); arr.length = 0; arr.push(...k); };
		const alive = (id: string | null) => !id || !drop.has(id);
		keep(people, (x) => !drop.has(x.id));
		keep(statusEvents, (x) => alive(x.person_id));
		keep(faithEvents, (x) => alive(x.person_id));
		keep(officeTerms, (x) => alive(x.person_id));
		keep(personGroups, (x) => alive(x.person_id));
		keep(consents, (x) => alive(x.person_id));
		keep(educationRecords, (x) => alive(x.person_id));
		keep(householdMembers, (x) => alive(x.person_id));
		const used = new Set(householdMembers.map((m) => m.household_id));
		keep(households, (h) => used.has(h.id));
		for (const id of drop) { byId.delete(id); delete attendRate[id]; }
		for (const x of people) {
			if (!alive(x.guide_person_id)) x.guide_person_id = null;
			if (!alive(x.big_family_head_id)) { x.big_family_head_id = null; x.big_family_relation = null; }
		}
	}

	// 같은 이름 구분 (A, B, C ...)
	const byName = new Map<string, T.Person[]>();
	for (const p of people) byName.set(p.name, [...(byName.get(p.name) ?? []), p]);
	const regOf = (p: T.Person) => statusEvents.find((e) => e.person_id === p.id)?.event_date ?? '9999';
	for (const list of byName.values()) if (list.length > 1) [...list].sort((a, b) => regOf(a).localeCompare(regOf(b))).forEach((p, i) => (p.name_note = String.fromCharCode(65 + (i % 26))));

	// ---- 자녀돌봄: 후보 자녀 중 정해진 인원(편성표 인원)만 편성 ----
	{
		const careDef = COMMUNITY_DEFS[2].groups.find((g) => g.care)!;
		const alivePeople = new Set(people);
		const pool = careKids.filter((p) => alivePeople.has(p));
		for (let i = 0; i < careDef.size && pool.length; i++) {
			const p = pool.splice(Math.floor(rnd() * pool.length), 1)[0];
			personGroups.push({ person_id: p.id, community: COMMUNITY_DEFS[2].name, small_group: careDef.name });
		}
	}

	// ---- 소그룹 편성표 (인도자) ----
	const careNames = new Set(COMMUNITY_DEFS.flatMap((c) => c.groups.filter((g) => g.care).map((g) => g.name)));
	const grouped = people.filter((p) => personGroups.some((g) => g.person_id === p.id && !careNames.has(g.small_group)) && ageAt(birthOf(p)) >= 30);
	const hasOffice = (id: string) => officeTerms.some((o) => o.person_id === id && !o.end_date);
	const smallGroups: T.SmallGroup[] = [];
	COMMUNITY_DEFS.forEach((c) => c.groups.forEach((g, i) => {
		const mem = personGroups.filter((x) => x.community === c.name && x.small_group === g.name).map((x) => byId.get(x.person_id)!);
		const cand = mem.filter((m) => ageAt(birthOf(m)) >= 35);
		const lead = cand.filter((m) => hasOffice(m.id));
		// 자녀돌봄은 인도자 없음
		const leader = g.care ? null : cand.length ? pick(lead.length ? lead : cand) : null;
		smallGroups.push({ id: `sg-${c.name.slice(0, 1)}-${i + 1}`, community: c.name, name: g.name, place: g.place, leader_id: leader?.id ?? null, care: !!g.care, sort_order: i + 1 });
	}));

	// 인도자 특이사항 (출석부에서 인도자가 쓰는 메모)
	const GN = ['출장으로 몇 주 결석 예정', '아기 출산 예정(다음 달)', '이사 준비 중, 거리가 멀어짐', '직장 일정으로 격주 참석', '수술 후 회복 중, 기도 부탁', '시험 기간이라 잠시 쉼', '부부 중 한 명만 참석 중', '새 직장 적응 중'];
	for (let i = 0; i < 46; i++) {
		const p = pick(grouped);
		const g = personGroups.find((x) => x.person_id === p.id)!;
		const sg = smallGroups.find((x) => x.name === g.small_group);
		groupNotes.push({ id: eid('gn'), person_id: p.id, small_group: g.small_group, date: daysAgo(ri(2, 70)), body: pick(GN), author: sg?.leader_id ? (byId.get(sg.leader_id)?.name ?? '인도자') : '인도자' });
	}
	groupNotes.push(...groupOpinions(smallGroups, (id) => byId.get(id)?.name ?? ''));
	groupNotes.sort((a, b) => b.date.localeCompare(a.date));

	// 결석이 이어지는 교인(소그룹 있는 사람 중 일부). 9주(약 2달) 이상이면 대시보드 장기 결석자로 잡힘
	for (let i = 0; i < 18; i++) {
		const p = pick(grouped);
		absentStreak[p.id] = ri(3, 16);
		// 아직 교인구분은 예배출석·가끔출석으로 남아 있는 경우 (알림으로 찾아내야 하는 사람)
		// 일부는 이미 '장기결석자'로 분류, 나머지는 아직 예배출석·가끔출석으로 남아 있어 알림으로 찾아내야 하는 사람
		p.category_id = p.category_id!.replace(/\.[asnl]$/, absentStreak[p.id] >= 12 && i % 2 === 0 ? '.l' : chance(0.6) ? '.s' : '.a');
		attendRate[p.id] = 0.45;
	}

	// 평소 주일 출석률: 교인구분 3단계를 따름 (예배출석 높게, 가끔출석 중간, 불출석·장기결석자 거의 없음, 보류는 낮게)
	{
		const lastKind = new Map<string, T.StatusKind>();
		for (const e of statusEvents) lastKind.set(e.person_id, e.kind);
		for (const x of people) {
			if (absentStreak[x.id]) continue;
			const l3 = x.category_id?.split('.')[2];
			attendRate[x.id] = lastKind.get(x.id) === '보류' ? 0.05 : l3 === 'a' ? 0.72 + rnd() * 0.24 : l3 === 's' ? 0.2 + rnd() * 0.25 : l3 === 'n' ? 0.03 : l3 === 'l' ? 0 : 0.5;
		}
	}

	// ---- 심방(교역자 열람용) ----
	const activeIds = people.filter((p) => {
		const last = statusEvents.filter((e) => e.person_id === p.id).at(-1);
		return last && (last.kind === '등록' || last.kind === '전입');
	});
	const PASTORS = ['정담임', '김부목', '이교육', '최전임'];
	const VISIT = ['가정 방문, 안부 확인 후 기도', '전화로 안부를 묻고 기도 제목 확인', '병원 방문, 위로와 기도', '새가족 가정 방문, 교회 안내', '이사 후 가정 예배', '직장 방문, 격려'];
	const FOLLOW = ['2주 뒤 연락', '소그룹 인도자와 공유', '다음 달 재방문', null, null, null];
	const KINDS = ['일반', '일반', '새신자', '병문안', '대심방', '이사', '출산', '결혼'];
	const SCRIP = ['시편 23편', '로마서 8:28', '빌립보서 4:6-7', '이사야 41:10', null, null];
	const HYMN = ['찬 305장', '찬 430장', '찬 364장', null, null];
	const visitOf = (p: T.Person, date: string): T.Visit => ({
		id: eid('vi'), person_id: p.id, visit_date: date, kind: pick(KINDS), method: chance(0.25) ? '전화' : '대면', pastor: pick(PASTORS),
		companions: chance(0.3) ? pick(grouped).name : null, scripture: pick(SCRIP), hymn: pick(HYMN),
		content: date <= TODAY ? pick(VISIT) : '심방 예정', follow_up: date <= TODAY ? pick(FOLLOW) : null
	});
	for (let i = 0; i < 70; i++) visits.push(visitOf(pick(activeIds), daysAgo(ri(3, 360))));
	for (let i = 0; i < 26; i++) visits.push(visitOf(pick(activeIds), addDay(TODAY, ri(1, 40))));
	visits.sort((a, b) => b.visit_date.localeCompare(a.visit_date));

	const NOTE = ['전화 연결 안 됨. 소그룹 인도자가 안부 확인 예정.', '주소 변경 안내 받음. 세대 주소 확인 필요.', '앱 가입 안내 완료.', '요람 공개 여부 재확인 필요.', '새가족 교육 안내 완료.'];
	for (let i = 0; i < 18; i++) notes.push({ id: eid('n'), person_id: pick(activeIds).id, body: pick(NOTE), author: '교적 담당 간사', created_at: daysAgo(ri(1, 200)) });
	const MEMO = ['(디모데) 이사 후 주소 변경 요청', '(디모데) 가족 중 비신자가 있음', '(디모데) 부부 소그룹 분리 희망', '(디모데) 직장 이동으로 주일 참석이 불규칙'];
	for (let i = 0; i < 60; i++) legacyMemos.push({ id: eid('lm'), person_id: pick(activeIds).id, body: pick(MEMO) });

	// 교회봉사(조직표 부서, 여러 곳 가능)
	const depts = ORG_UNITS.filter((u) => u.parent_id);
	for (let i = 0; i < 150; i++) {
		const p = pick(activeIds);
		if (ageAt(birthOf(p)) < 20) continue;
		const d = pick(depts);
		if (affiliations.some((a) => a.person_id === p.id && a.org === d.name)) continue;
		affiliations.push({ id: eid('af'), person_id: p.id, org: d.name, role: pick(['부원', '부원', '팀원', '부장', '총무']), start_date: dateIn(THIS_YEAR - ri(0, 8)), end_date: null });
	}

	// ---- 일정 (사역·성례·성도) : 지난해~내년 ----
	let ne = 0;
	const ev = (kind: T.ChurchEvent['kind'], date: string, title: string, end: string | null = null, person: string | null = null, note: string | null = null) =>
		events.push({ id: `ev${++ne}`, kind, date, end_date: end, title, person_id: person, note });
	const nthWeekday = (y: number, m: number, wd: number, n: number) => {
		const first = new Date(y, m - 1, 1);
		return isoDate(new Date(y, m - 1, 1 + ((wd - first.getDay() + 7) % 7) + (n - 1) * 7));
	};
	for (const y of [THIS_YEAR - 1, THIS_YEAR, THIS_YEAR + 1]) {
		for (let m = 1; m <= 12; m++) {
			ev('사역 일정', nthWeekday(y, m, 6, 2), '교회 대청소 (가상 일정)');
			ev('사역 일정', nthWeekday(y, m, 5, 3), '은혜로 기도회 (가상 일정)');
			ev('사역 일정', nthWeekday(y, m, 2, 1), '당회 (가상 일정)');
			if (m % 3 === 0) ev('사역 일정', nthSunday(y, m, 4), '제직회 (가상 일정)');
			ev('성례 일정', nthSunday(y, m, 1), '성찬식');
		}
		for (const s of churchSeasons(y)) {
			ev(/어린이주일|어버이주일|창립/.test(s.title) ? '사역 일정' : '교회 절기', s.date, s.title, s.end_date);
			if (s.title === '부활주일' || s.title === '추수감사주일') ev('성례 일정', s.date, '세례식 (세례 대상자 명단 확인)');
		}
	}
	for (let i = 0; i < 12; i++) {
		const p = pick(activeIds);
		const s = addDay(TODAY, ri(-12, 30));
		ev('성도', s, pick(['수술(입원)', '입원', '수술(입원)']), addDay(s, ri(3, 10)), p.id);
	}
	for (let i = 0; i < 6; i++) ev('성도', addDay(TODAY, ri(2, 35)), pick(['돌잔치', '회갑', '이사 감사예배', '개업 감사예배']), null, pick(activeIds).id);

	// '불출석'은 장년에만 있으므로 다른 2단계의 불출석은 장기결석자로
	for (const p of people) p.category_id = fixCat(p.category_id);

	return {
		people, households, householdMembers, statusEvents, faithEvents, officeTerms, personGroups, smallGroups, groupNotes, notes, legacyMemos,
		consents, visits, affiliations, events, educationRecords, absentStreak, attendRate
	};
}
