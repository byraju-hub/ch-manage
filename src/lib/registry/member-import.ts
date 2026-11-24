// 교인 엑셀 일괄 등록: 엑셀 표를 읽어 교인 한 줄씩의 값으로 바꾼다 (화면은 components/MemberImport.svelte).
// 첫 줄(머리글)의 칸 이름으로 칸을 찾으므로 칸 순서가 달라도 되고, 모르는 칸(예: 나이)은 건너뛴다. '이름' 칸만 꼭 있어야 한다.
// 이 프로그램의 교인 목록 엑셀(이름·동명이인구분·성별·생년월일·휴대폰·주소·직분·상태·교인구분·전도회·신급·교구·구역·등록일·차량번호)을 그대로 읽는다.
import { CLERGY_TITLES, EDU_DEPTS, FAITH_KINDS, MARITAL_STATUSES, OFFICE_NAMES, OFFICE_STATUS_OPTIONS, RELATIONS, type Category, type ClergyTitle, type EduDept, type FaithKind, type MaritalStatus, type OfficeName, type OfficeStatus, type StatusKind } from './types.ts';
import { categoryPath, formatMobile } from './derive.ts';

export interface ImportRow {
	line: number; // 엑셀의 줄 번호 (머리글이 1)
	name: string;
	name_note: string | null; // 동명이인 구분 (비우면 같은 이름이 있을 때 자동으로 A, B …)
	gender: 'M' | 'F' | null;
	birth_date: string | null;
	birth_lunar: boolean;
	phone: string | null;
	email: string | null;
	address_zip: string | null;
	address1: string | null;
	address2: string | null;
	occupation: string | null;
	workplace: string | null;
	car_number: string | null;
	marital_status: MaritalStatus | null;
	wedding_date: string | null;
	previous_church: string | null;
	guide_name: string | null;
	mission_society: string | null;
	faith_kind: FaithKind | null;
	faith_date: string | null;
	faith_checked: boolean; // '원입교인' (신급 기록은 없고 확인만 한 사람)
	office: OfficeName | null;
	office_status: OfficeStatus;
	clergy: ClergyTitle | null; // 담임목사·부목사 같은 교역자 직책 (직분 칸에 적은 경우)
	status: StatusKind; // 상태: 재적 = 등록
	reg_date: string | null;
	category_text: string | null; // 엑셀에 적힌 교인구분 글 (예: 장년 › 예배출석)
	category_id: string | null; // 위 글을 찾은 결과 (enrich 에서 채움)
	edu_dept: EduDept | null;
	community: string | null; // 교구 (= 공동체)
	small_group: string | null; // 구역 (= 소그룹)
	head_name: string | null; // 세대주 이름 (없으면 새 세대)
	relation: string; // 세대주와의 관계
	note: string | null;
	warns: string[]; // 고쳐 읽은 것 · 읽지 못해 비운 것 (등록은 됨)
}

type Key = 'name' | 'name_note' | 'gender' | 'birth_date' | 'birth_lunar' | 'phone' | 'email' | 'address_zip' | 'address1' | 'address2' | 'occupation' | 'workplace' | 'car_number'
	| 'marital_status' | 'wedding_date' | 'previous_church' | 'guide_name' | 'mission_society' | 'faith_kind' | 'faith_date' | 'office' | 'status' | 'reg_date' | 'category' | 'community' | 'small_group' | 'head_name' | 'relation' | 'note';
// 칸 이름 → 칸 (공백을 뺀 머리글이 이 모양이면 그 칸). 위에서부터 맞는 칸을 먼저 차지함
const COLS: [Key, RegExp][] = [
	['name', /^(이름|성명|성함)$/],
	['name_note', /^동명이인/],
	['gender', /^성별/],
	['birth_date', /^(생년월일|생일|출생)/],
	['birth_lunar', /^(음력|양력\/음력|양음력)/],
	['phone', /^(휴대폰|휴대전화|핸드폰|전화|연락처|모바일)/],
	['email', /^(이메일|e-?mail)/i],
	['address_zip', /^우편/],
	['address2', /^(상세주소|주소2|나머지주소)/],
	['address1', /^(주소|거주지|기본주소|주소1)/],
	['occupation', /^(직업|하는일)/],
	['workplace', /^(직장|근무처|회사|학교)/],
	['car_number', /^(차량|자동차)/],
	['wedding_date', /^(결혼기념|혼인일)/],
	['marital_status', /^(결혼여부|혼인여부|결혼|혼인)/],
	['previous_church', /^(이전|전)(출석)?교회/],
	['guide_name', /^(인도자|인도)/],
	['mission_society', /^전도회/],
	['faith_date', /^(신급일|세례일|입교일)/],
	['faith_kind', /^(신급|세례)/],
	['office', /^(직분|직책)/],
	['status', /^(상태|재적)/],
	['reg_date', /^(교회)?등록일/],
	['category', /^교인구분|^구분$/],
	['community', /^(교구|공동체)/],
	['small_group', /^(구역|소그룹|셀|목장)/],
	['head_name', /^(신앙)?세대주(?!.*관계)/],
	['relation', /^(세대)?관계|^세대주(와의)?관계/],
	['note', /^(비고|메모|특이사항)/]
];

const sq = (s: string) => s.replace(/\s+/g, '');
const txt = (v: unknown) => (v === null || v === undefined ? '' : v instanceof Date ? '' : String(v).trim());
const pad = (n: number) => String(n).padStart(2, '0');
const iso = (y: number, m: number, d: number) => {
	if (m < 1 || m > 12 || d < 1 || d > 31) return null;
	const t = new Date(Date.UTC(y, m - 1, d));
	return t.getUTCMonth() === m - 1 ? `${y}-${pad(m)}-${pad(d)}` : null; // 2월 30일 같은 날짜 거르기
};

// 칸 하나를 날짜로: 엑셀 날짜 칸, '1962-04-05', '1962.4.5', '1962년 4월 5일', '19620405', '62.4.5'. 비었으면 null, 읽지 못하면 undefined
export function parseDate(v: unknown): string | null | undefined {
	if (v === null || v === undefined || v === '') return null;
	if (v instanceof Date) return isNaN(v.getTime()) ? undefined : iso(v.getUTCFullYear(), v.getUTCMonth() + 1, v.getUTCDate()) ?? undefined;
	const s = String(v).trim();
	if (!s) return null;
	let m = s.match(/^(\d{4})\s*[-./년]\s*(\d{1,2})\s*[-./월]\s*(\d{1,2})/);
	if (m) return iso(+m[1], +m[2], +m[3]) ?? undefined;
	m = s.match(/^(\d{4})(\d{2})(\d{2})$/);
	if (m) return iso(+m[1], +m[2], +m[3]) ?? undefined;
	m = s.match(/^(\d{2})\s*[-./]\s*(\d{1,2})\s*[-./]\s*(\d{1,2})$/);
	if (m) return iso(+m[1] > 30 ? 1900 + +m[1] : 2000 + +m[1], +m[2], +m[3]) ?? undefined;
	return undefined;
}

// 상태 칸 → 재적 상태 (재적·등록·재적교인 = 등록)
function parseStatus(s: string): StatusKind | null {
	const t = sq(s);
	if (/^(재적|등록|재적교인|등록교인|출석|)$/.test(t)) return '등록';
	return (['전입', '복적', '보류', '보관', '전출', '별세', '제적'] as StatusKind[]).find((k) => t.includes(k)) ?? null;
}

// 직분 칸: '권사', '시무권사', '은퇴권사', '원로장로', '명예집사', '서리집사', '집사', '안수집사', '담임목사', '부목사', '권사(은퇴)'. 성도 · 빈 칸은 직분 없음
function parseOffice(raw: string, row: ImportRow) {
	const of = sq(raw);
	if (!of || of === '성도') return;
	const clergy = CLERGY_TITLES.find((c) => of.includes(c));
	if (clergy) row.clergy = clergy;
	const stWords = [...new Set(Object.values(OFFICE_STATUS_OPTIONS).flat())].filter((x) => x !== '시무' && x !== '서리');
	const st = stWords.find((x) => of.includes(x));
	let base = of.replace(/[()]/g, '').replace(clergy ?? '\u0000', clergy?.endsWith('목사') ? '목사' : '전도사');
	if (st) base = base.replace(st, '');
	let office: OfficeName | undefined;
	if (/안수집사|시무집사/.test(base)) office = '시무집사';
	else if (/서리집사/.test(base)) office = '서리집사';
	else if (/^(시무|서리)?집사$/.test(base)) office = st && st !== '명예' ? '시무집사' : '서리집사'; // 은퇴집사 = 시무집사 은퇴, 명예집사 = 서리집사 명예
	else office = OFFICE_NAMES.find((o) => o !== '시무집사' && o !== '서리집사' && base.includes(o));
	if (!office) { row.warns.push(`직분 '${raw}'을(를) 몰라 비움 (${OFFICE_NAMES.join(' · ')})`); row.clergy = null; return; }
	row.office = office;
	const ok = OFFICE_STATUS_OPTIONS[office];
	row.office_status = st && ok.includes(st) ? st : ok[0];
	if (st && !ok.includes(st)) row.warns.push(`${office}에는 '${st}' 구분이 없어 '${ok[0]}'로 넣음`);
}

function blank(line: number, name: string, warns: string[]): ImportRow {
	return {
		line, name, name_note: null, gender: null, birth_date: null, birth_lunar: false, phone: null, email: null, address_zip: null, address1: null, address2: null,
		occupation: null, workplace: null, car_number: null, marital_status: null, wedding_date: null, previous_church: null, guide_name: null, mission_society: null,
		faith_kind: null, faith_date: null, faith_checked: false, office: null, office_status: '시무', clergy: null, status: '등록', reg_date: null,
		category_text: null, category_id: null, edu_dept: null, community: null, small_group: null, head_name: null, relation: '본인', note: null, warns
	};
}

function parseRows(data: unknown[][]): { rows: ImportRow[]; error: string | null } {
	// 머리글 줄: '이름' 칸이 있는 첫 줄
	const hi = data.findIndex((r) => r.some((c) => COLS[0][1].test(sq(txt(c)))));
	if (hi < 0) return { rows: [], error: "첫 줄에 '이름' 칸이 없습니다. 양식을 받아서 그 모양대로 채우거나, 맨 위 줄에 칸 이름을 적어 주세요." };
	const head = data[hi].map((c) => sq(txt(c)));
	const at: Partial<Record<Key, number>> = {};
	const used = new Set<number>();
	for (const [key, re] of COLS) {
		const i = head.findIndex((h, j) => h && !used.has(j) && re.test(h));
		if (i >= 0) { at[key] = i; used.add(i); }
	}
	const rows: ImportRow[] = [];
	for (let i = hi + 1; i < data.length; i++) {
		const r = data[i] ?? [];
		const cell = (k: Key) => (at[k] === undefined ? undefined : r[at[k]!]);
		const s = (k: Key) => txt(cell(k));
		const name = s('name').replace(/\s+/g, ' ');
		if (!name) { if (r.some((c) => txt(c))) rows.push(blank(i + 1, '', ['이름이 비어 있어 건너뜀'])); continue; }
		const row = blank(i + 1, name, []);
		const warns = row.warns;
		const date = (k: 'birth_date' | 'wedding_date' | 'faith_date' | 'reg_date', label: string) => {
			const d = parseDate(cell(k));
			if (d === undefined) warns.push(`${label} '${txt(cell(k))}'을(를) 읽지 못해 비움`);
			return d ?? null;
		};
		row.birth_date = date('birth_date', '생년월일');
		row.wedding_date = date('wedding_date', '결혼기념일');
		row.faith_date = date('faith_date', '신급일');
		row.reg_date = date('reg_date', '등록일');
		row.name_note = s('name_note') || null;
		const g = s('gender');
		if (g) { if (/^(남|남자|남성|m|male)$/i.test(g)) row.gender = 'M'; else if (/^(여|여자|여성|f|female)$/i.test(g)) row.gender = 'F'; else warns.push(`성별 '${g}'을(를) 몰라 비움 (남 · 여로 적어 주세요)`); }
		row.birth_lunar = /^(o|○|●|예|y|yes|음|음력|true|1)$/i.test(s('birth_lunar'));
		let ph = s('phone').replace(/\D/g, '');
		if (ph.length === 10 && ph.startsWith('10')) ph = '0' + ph; // 엑셀이 앞의 0을 지운 경우
		if (ph) {
			if (ph.length < 9 || ph.length > 11) warns.push(`전화번호 '${s('phone')}'의 자릿수가 이상함 (그대로 넣음)`);
			row.phone = ph.length >= 10 ? formatMobile(ph) : s('phone');
		}
		row.email = s('email') || null;
		if (row.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(row.email)) warns.push(`이메일 '${row.email}' 모양이 이상함 (그대로 넣음)`);
		const zip = s('address_zip').replace(/\D/g, '');
		row.address_zip = zip ? zip.padStart(5, '0') : null;
		row.address1 = s('address1') || null;
		row.address2 = s('address2') || null;
		row.occupation = s('occupation') || null;
		row.workplace = s('workplace') || null;
		row.car_number = s('car_number') || null;
		const ms = s('marital_status');
		if (ms) { const f = MARITAL_STATUSES.find((x) => x === ms || (x === '기혼' && /^결혼/.test(ms))); if (f) row.marital_status = f; else warns.push(`결혼 여부 '${ms}'을(를) 몰라 비움 (${MARITAL_STATUSES.join(' · ')})`); }
		row.previous_church = s('previous_church') || null;
		row.guide_name = s('guide_name') || null;
		row.mission_society = s('mission_society') || null;
		// 신급: 세례 · 입교 · 학습 · 유아세례, 또는 목록에 나오는 말 (세례교인 · 유아세례교인 · 학습교인 · 원입교인 · 미확인)
		const fk = sq(s('faith_kind'));
		if (fk) {
			const f = FAITH_KINDS.find((x) => fk.startsWith(x));
			if (f) row.faith_kind = f;
			else if (fk.startsWith('원입')) row.faith_checked = true;
			else if (!/^(미확인|없음|해당없음|-)$/.test(fk)) warns.push(`신급 '${s('faith_kind')}'을(를) 몰라 비움 (${FAITH_KINDS.join(' · ')})`);
		}
		if (row.faith_date && !row.faith_kind) { warns.push('신급일만 있고 신급 종류가 없어 신급일은 넣지 않음'); row.faith_date = null; }
		parseOffice(s('office'), row);
		const stt = parseStatus(s('status'));
		if (stt) row.status = stt; else warns.push(`상태 '${s('status')}'을(를) 몰라 재적으로 넣음 (재적 · 보류 · 보관 · 전출 · 별세 · 제적)`);
		row.category_text = s('category') || null;
		row.community = s('community') || null;
		row.small_group = s('small_group') || null;
		row.head_name = s('head_name') || null;
		const rel = s('relation');
		row.relation = RELATIONS.includes(rel) ? rel : rel === '' ? (row.head_name && row.head_name !== name ? '기타' : '본인') : '기타';
		if (rel && !RELATIONS.includes(rel)) warns.push(`관계 '${rel}'은(는) 목록에 없어 '기타'로 넣음 (${RELATIONS.join(' · ')})`);
		if (row.head_name === name) { row.head_name = null; row.relation = '본인'; }
		row.note = s('note') || null;
		rows.push(row);
	}
	return { rows, error: null };
}

// 엑셀 여러 장 중 '이름' 머리글이 있는 첫 장을 읽음
export function parseMembers(sheets: { sheet: string; data: unknown[][] }[]): { rows: ImportRow[]; error: string | null } {
	let err: string | null = null;
	for (const s of sheets) {
		const res = parseRows(s.data);
		if (!res.error) return res;
		err = res.error;
	}
	return { rows: [], error: err ?? '엑셀에서 내용을 찾지 못했습니다.' };
}

// 교인구분 글('교인 › 장년 › 예배출석', '장년 › 예배출석', '장년', '새가족')을 교인구분 번호로. 못 찾으면 null
export function findCategory(cats: Category[], text: string | null): string | null {
	if (!text) return null;
	const parts = text.split(/[›>»/,|]+/).map((x) => x.trim()).filter(Boolean);
	if (!parts.length) return null;
	const byParent = (pid: string | null) => cats.filter((c) => c.parent_id === pid).sort((a, b) => a.sort_order - b.sort_order);
	const named = (list: Category[], n: string) => list.find((c) => sq(c.name) === sq(n));
	// 맨 앞이 1단계 이름이 아니면 '교인' 아래로 봄 (예: '장년 › 예배출석')
	let level = named(byParent(null), parts[0]);
	if (!level) level = named(byParent(null), '교인');
	else parts.shift();
	if (!level) return null;
	let cur: Category | undefined = level;
	for (const p of parts) {
		const next: Category | undefined = named(byParent(cur.id), p);
		if (!next) return null;
		cur = next;
	}
	// 1·2단계에서 멈췄으면 가장 첫 아래 구분(예: 장년 › 예배출석)으로
	while (cur) { const kids = byParent(cur.id); if (!kids.length) break; cur = kids[0]; }
	return cur?.id ?? null;
}

// 교인구분의 2단계가 교육부서(청년부·중고등부 …)이면 그 이름 (장년·새가족이면 null)
export const eduDeptOf = (cats: Category[], id: string | null): EduDept | null => {
	const l2 = categoryPath(cats, id)[1]?.name ?? '';
	return (EDU_DEPTS as string[]).includes(l2) ? (l2 as EduDept) : null;
};

// 양식에 적는 머리글과 예시 줄 (내보내기 엑셀과 같은 칸 이름)
export const MEMBER_TEMPLATE_HEAD = ['이름', '동명이인구분', '성별', '생년월일', '휴대폰', '주소', '직분', '상태', '교인구분', '전도회', '신급', '교구', '구역', '등록일', '차량번호'];
export const MEMBER_TEMPLATE_ROWS: string[][] = [
	['홍길동', '', '남', '1962-04-05', '010-1234-5678', '서울시 가나구 다라로 1, 101동 202호', '시무장로', '재적', '장년 › 예배출석', '3남전도회', '세례', '', '', '2005-03-06', '12가 3456'],
	['성춘향', '', '여', '1965-09-12', '010-2345-6789', '서울시 가나구 다라로 1, 101동 202호', '시무권사', '재적', '장년 › 예배출석', '5여전도회', '세례', '', '', '2005-03-06', ''],
	['오새롬', '', '여', '1995-06-15', '010-3456-7890', '서울시 마바구 사아로 22', '', '재적', '새가족', '', '', '', '', '2026-03-01', '']
];
