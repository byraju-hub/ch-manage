// 화면의 데이터(registry.d)와 서버의 표 사이를 오가는 정의. (ch-manage 전용 파일)
//   rows(d)  : 화면 데이터 → 서버로 보낼 줄(row)들. 바뀐 줄·칸을 찾을 때(sync.svelte.ts) 쓴다.
//   load(d)  : 서버에서 받은 줄들 → 화면 데이터.
// 서버(server/api/lib/data.php 의 data_specs)와 표 이름·id 모양이 같아야 한다. level 은 서버와 같은 '저장 순서'
// (먼저 만들어져야 하는 표일수록 작은 수)이며, 한 번에 보내지 못할 만큼 많을 때 순서를 지키는 데 쓴다.
import type { Data } from './store.svelte.ts';
import type * as T from './types.ts';
import { hrefTo } from './paths.ts';

export type Row = { id: string } & Record<string, unknown>;

export interface Codec {
	name: string;
	level: number;
	/** 서버가 계산하는 칸: 저장할 때 보내지 않고, 바뀌었는지 비교할 때도 뺀다 */
	skip: string[];
	/** 기록 표(변경 이력·접속 기록): 새 줄만 보낸다. 화면에서 오래된 줄을 잘라내도 서버에서는 지우지 않는다 */
	append?: boolean;
	rows(d: Data): Row[];
	load(d: Data, rows: Row[], perms: { visit: boolean }): void;
}

type Loose = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

/** 서버가 준 '/files/…' 주소를 이 사이트의 주소로 */
const fileUrl = (u: unknown): string | null => (typeof u === 'string' && u.startsWith('/files/') ? hrefTo('/api/v1' + u) : null);

/** 화면의 배열 하나 = 서버의 표 하나 (줄마다 id 가 있는 경우) */
const arr = (name: string, key: keyof Data, level: number, skip: string[] = [], fix?: (r: Row) => Row): Codec => ({
	name, level, skip,
	rows: (d) => ((d as Loose)[key] as Row[]) ?? [],
	load: (d, rows) => { (d as Loose)[key] = fix ? rows.map(fix) : rows; }
});

/** 주일 날짜 → 사람 id → 출석 표시 (출석 두 가지) */
const marks = (name: string, key: 'attendance' | 'groupAttendance'): Codec => ({
	name, level: 4, skip: [],
	rows: (d) => {
		const out: Row[] = [];
		for (const [sunday, m] of Object.entries(((d as Loose)[key] ?? {}) as Record<string, Record<string, T.AttendanceMark>>))
			for (const [pid, v] of Object.entries(m)) out.push({ id: `${sunday}|${pid}`, sunday, person_id: pid, present: v.present, reason: v.reason ?? null });
		return out;
	},
	load: (d, rows) => {
		const rec: Record<string, Record<string, T.AttendanceMark>> = {};
		for (const r of rows) (rec[r.sunday as string] ??= {})[r.person_id as string] = { present: !!r.present, reason: (r.reason as string | null) ?? null };
		(d as Loose)[key] = rec;
	}
});

export const CODECS: Codec[] = [
	// ---- level 1: 설정 성격 ----
	arr('categories', 'categories', 1),
	{
		name: 'communityList', level: 1, skip: [],
		rows: (d) => (d.communityList ?? []).map((c, i) => ({ id: c.key, name: c.name, from: c.from, to: c.to, sort_order: i })),
		load: (d, rows) => { d.communityList = rows.map((r) => ({ key: r.id, name: r.name as string, from: r.from as number, to: r.to as number })); }
	},
	arr('educationCourses', 'educationCourses', 1),
	{
		name: 'meetings', level: 1, skip: [],
		rows: (d) => d.meetings.map((m) => ({ id: m.meeting, ...m })),
		load: (d, rows) => { d.meetings = rows.map((r) => ({ meeting: r.meeting as T.MeetingOfficer['meeting'], chair: r.chair as string, secretary: r.secretary as string })); }
	},
	{
		name: 'yearSettings', level: 1, skip: [],
		rows: (d) => Object.entries(d.yearSettings ?? {}).map(([y, s]) => ({ id: y, ...(s as unknown as Loose) })) as Row[],
		load: (d, rows) => { d.yearSettings = Object.fromEntries(rows.map((r) => { const { id, ...s } = r; return [id, s]; })) as unknown as Data['yearSettings']; }
	},
	{
		// 문구가 하나도 없으면 기본 문구를 보여 준다(저장하지 않음)
		name: 'smsTemplates', level: 1, skip: [],
		rows: (d) => (d.smsTemplates ?? []).map((t, i) => ({ ...t, sort_order: i })),
		load: (d, rows) => { d.smsTemplates = rows.length ? rows.map((r) => ({ id: r.id, title: r.title as string, body: r.body as string })) : undefined; }
	},
	{
		name: 'site', level: 1, skip: [],
		rows: (d) => {
			const r: Row = { id: '1' };
			for (const k of ['churchInfo', 'certForm', 'codes', 'backup'] as const) if (d[k] !== undefined) r[k] = d[k];
			return [r];
		},
		load: (d, rows) => {
			const r = rows[0] as Loose | undefined;
			if (!r) return;
			d.churchInfo = { ...r.churchInfo, logo: fileUrl(r.churchInfo?.logo), seal: fileUrl(r.churchInfo?.seal) };
			d.certForm = r.certForm; d.codes = r.codes; d.backup = r.backup;
		}
	},
	// ---- level 2: 교인 ----
	arr('people', 'people', 2, ['has_account'], (r) => ({ ...r, photo: fileUrl(r.photo) })),
	// ---- level 3 ----
	arr('smallGroups', 'smallGroups', 3),
	arr('orgUnits', 'orgUnits', 3),
	arr('households', 'households', 3),
	// ---- level 4: 교인에 딸린 기록 ----
	arr('householdMembers', 'householdMembers', 4),
	arr('statusEvents', 'statusEvents', 4),
	arr('faithEvents', 'faithEvents', 4),
	arr('officeTerms', 'officeTerms', 4),
	{
		// 소속: 화면은 (사람, 공동체 이름, 소그룹 이름). 서버는 소그룹 이름으로 찾아 연결하고 공동체는 계산해서 돌려준다.
		name: 'personGroups', level: 4, skip: ['community'],
		rows: (d) => d.personGroups.map((g) => ({ id: g.person_id, ...g })),
		load: (d, rows) => { d.personGroups = rows.map((r) => ({ person_id: r.person_id as string, community: r.community as string, small_group: r.small_group as string })); }
	},
	arr('groupNotes', 'groupNotes', 4),
	marks('attendance', 'attendance'),
	marks('groupAttendance', 'groupAttendance'),
	{
		name: 'worshipTotals', level: 4, skip: [],
		rows: (d) => Object.entries(d.worshipTotals ?? {}).map(([sunday, total]) => ({ id: sunday, sunday, total })),
		load: (d, rows) => { d.worshipTotals = Object.fromEntries(rows.map((r) => [r.sunday as string, r.total as number])); }
	},
	arr('notes', 'notes', 4),
	arr('legacyMemos', 'legacyMemos', 4),
	arr('consents', 'consents', 4),
	arr('visits', 'visits', 4),
	arr('affiliations', 'affiliations', 4),
	arr('educationRecords', 'educationRecords', 4),
	arr('events', 'events', 4),
	arr('certRequests', 'certRequests', 4),
	{ ...arr('changeLog', 'changeLog', 4), append: true },
	{ ...arr('accessLog', 'accessLog', 4), append: true }
];

export const CODEC = new Map(CODECS.map((c) => [c.name, c]));
