// 교회 연간 계획(엑셀)을 읽어 캘린더 일정으로 바꿈.
// 분류 규칙: 이름에 세례·성찬이 있으면 '성례 일정', 절기 이름이 있으면 '교회 절기', 나머지는 '사역 일정'.
// 공휴일(신정·삼일절·대체휴일·추석 등)은 캘린더에 이미 빨간색으로 들어 있으므로 등록하지 않음.
// [실제 시스템] 규칙 낱말은 '관리자 › 교회 설정 › 연간 설정'에서 고칠 수 있게 두면 좋습니다.
import type { ChurchEvent } from './types.ts';
import { addDays, HOLIDAY_WORDS } from './calendar.ts';

export type PlanKind = Exclude<ChurchEvent['kind'], '성도'>;

export const SACRAMENT_WORDS = ['세례', '성찬', '입교'];
export const SEASON_WORDS = [
	'신년주일', '주현절', '사순절', '재의 수요일', '종려주일', '고난주간', '성금요일', '부활주일', '부활절',
	'성령강림', '오순절', '삼위일체', '맥추감사', '추수감사', '종교개혁', '대림절', '대강절', '성탄절', '성탄주일', '송구영신'
];
// 여러 날 이어지는 일정: 적힌 날부터 며칠 동안 (예: 20일 은혜로 기도회 = 5일씩 4회, 월~금)
export const SPAN_RULES: [string, number][] = [['은혜로 기도회', 5]];

const squeeze = (s: string) => s.replace(/\s+/g, '');
export function classifyPlan(title: string): PlanKind {
	const t = squeeze(title);
	if (SACRAMENT_WORDS.some((w) => t.includes(w))) return '성례 일정';
	if (SEASON_WORDS.some((w) => t.includes(squeeze(w)))) return '교회 절기';
	return '사역 일정';
}
export const isHolidayTitle = (title: string) => HOLIDAY_WORDS.some((w) => squeeze(title).includes(squeeze(w)));

export interface PlanRow {
	date: string;
	end_date: string | null;
	title: string;
	note: string | null;
	kind: PlanKind;
}

const pad = (n: number) => String(n).padStart(2, '0');
const iso = (y: number, m: number, d: number) => (m >= 1 && m <= 12 && d >= 1 && d <= 31 ? `${y}-${pad(m)}-${pad(d)}` : null);

// 칸 하나를 날짜로: 엑셀 날짜 칸, '2026-03-01', '2026.3.1', '3/1', '3.1(주일)', '3월 1일'
function cellDate(v: unknown, year: number, month: number | null): string | null {
	if (v instanceof Date && !isNaN(v.getTime())) return iso(v.getUTCFullYear(), v.getUTCMonth() + 1, v.getUTCDate());
	if (typeof v === 'number' && month && v >= 1 && v <= 31) return iso(year, month, v); // '월' 칸 + '일' 칸
	if (typeof v !== 'string') return null;
	const s = v.trim();
	let m = s.match(/(\d{4})\s*[-./년]\s*(\d{1,2})\s*[-./월]\s*(\d{1,2})/);
	if (m) return iso(+m[1], +m[2], +m[3]);
	m = s.match(/^(\d{1,2})\s*[./월]\s*(\d{1,2})/);
	if (m) return iso(year, +m[1], +m[2]);
	m = s.match(/^(\d{1,2})\s*일?(\s*\(|$)/);
	if (m && month) return iso(year, month, +m[1]);
	return null;
}

// 기간 '4/13~4/19', '4.13~19', '4월 13일 ~ 19일' → [시작, 끝]
function cellRange(v: unknown, year: number, month: number | null): [string, string | null] | null {
	if (typeof v === 'string' && /[~∼〜]/.test(v)) {
		const [a, b] = v.split(/[~∼〜]/);
		const start = cellDate(a, year, month);
		if (!start) return null;
		const sm = +start.slice(5, 7);
		const end = cellDate(b.trim(), +start.slice(0, 4), sm) ?? (/^\s*\d{1,2}\s*일?\s*(\(.*)?$/.test(b) ? iso(+start.slice(0, 4), sm, parseInt(b)) : null);
		return [start, end && end > start ? end : null];
	}
	const d = cellDate(v, year, month);
	return d ? [d, null] : null;
}

const text = (v: unknown) => (v === null || v === undefined ? '' : v instanceof Date ? '' : String(v).trim());
const isTitle = (s: string) => s.length >= 2 && !/^[\d\s./~:\-()월일]+$/.test(s);
const WEEKDAY = /^[월화수목금토일](요일)?$/;

// 한 칸에 줄을 바꿔 적은 행사 나누기. 앞 줄이 '~로/~의/~와'처럼 끝나거나 다음 줄이 '-'로 시작하면 한 행사로 이어 붙임
function splitCell(s: string): string[] {
	const out: string[] = [];
	for (const raw of s.split(/\r?\n/)) {
		const l = raw.trim();
		if (!l) continue;
		const prev = out.at(-1);
		if (prev !== undefined && (/^[-–·]/.test(l) || /(로|으로|의|와|과|및|을|를|에|,)$/.test(prev))) out[out.length - 1] = `${prev} ${l}`;
		else out.push(l);
	}
	return out;
}

// 이름 안의 기간 '(27~4일)' → 끝나는 날. 끝 날이 시작 날보다 작으면 다음 달
function inlineSpan(title: string, start: string): { title: string; end: string | null } {
	const m = title.match(/\(\s*(\d{1,2})\s*일?\s*[~∼〜-]\s*(\d{1,2})\s*일?\s*\)/);
	if (!m) return { title, end: null };
	const [y, mo] = start.split('-').map(Number);
	const sd = +m[1], ed = +m[2];
	const end = ed >= sd ? iso(y, mo, ed) : mo === 12 ? iso(y + 1, 1, ed) : iso(y, mo + 1, ed);
	return { title: title.replace(m[0], '').trim(), end };
}

function makeRow(date: string, end: string | null, rawTitle: string, note: string | null): PlanRow {
	const span = inlineSpan(rawTitle, date);
	let end_date = end ?? span.end;
	const rule = SPAN_RULES.find(([w]) => squeeze(span.title).includes(squeeze(w)));
	if (!end_date && rule) end_date = addDays(date, rule[1] - 1);
	return { date, end_date: end_date && end_date > date ? end_date : null, title: span.title, note, kind: classifyPlan(span.title) };
}

// 문서 앞부분의 연도 (예: '2026 교회 연중행사 계획안')
export function guessYear(sheets: { data: unknown[][] }[]): number | null {
	for (const { data } of sheets)
		for (const row of data.slice(0, 4))
			for (const v of row) {
				const m = text(v).match(/(20\d{2})\s*(년도?|교회|연중|연간|행사|계획)/);
				if (m) return +m[1];
			}
	return null;
}

// 머리줄에서 칸 찾기
const H = {
	date: /^(날짜|일자|일시|기간|시작|시작일|행사일)$/,
	end: /^(끝|종료|종료일|끝나는\s*날|마침)$/,
	month: /^월$/,
	day: /^일$/,
	title: /^(행사|행사명|일정|일정명|내용|제목|명칭|계획|사업|사업명)$/,
	note: /^(비고|장소|메모|담당|부서|담당부서)$/
};

// 달력 모양: 한 줄에 '1월 · 2월 · …' 이 나란히 있고, 아래로 날짜 · 요일 · 행사 칸이 반복되는 표
function parseGrid(data: unknown[][], year: number): { date: string; title: string }[] | null {
	const head = data.findIndex((r) => r.filter((v) => /^\d{1,2}\s*월$/.test(text(v))).length >= 3);
	if (head < 0) return null;
	const cols = data[head].map((v, i) => ({ m: text(v).match(/^(\d{1,2})\s*월$/), i })).filter((x) => x.m).map((x) => ({ month: +x.m![1], col: x.i }));
	const out: { date: string; title: string }[] = [];
	for (const row of data.slice(head + 1)) {
		cols.forEach(({ month, col }, k) => {
			const day = parseInt(text(row[col]));
			const date = iso(year, month, day);
			if (!date) return;
			const until = cols[k + 1]?.col ?? row.length;
			const words = row.slice(col + 1, until).map(text).filter((s) => s && !WEEKDAY.test(s));
			for (const w of words) for (const title of splitCell(w)) out.push({ date, title });
		});
	}
	return out;
}

export function parsePlan(sheets: { sheet: string; data: unknown[][] }[], year: number): { rows: PlanRow[]; holidays: number } {
	const found: PlanRow[] = [];
	for (const { data } of sheets) {
		const grid = parseGrid(data, year);
		if (grid) {
			for (const g of grid) found.push(makeRow(g.date, null, g.title, null));
			continue;
		}
		let col: Partial<Record<keyof typeof H, number>> = {};
		let month: number | null = null;
		for (const row of data) {
			const cells = row.map(text);
			// 머리줄이면 칸 위치를 기억
			const hit: typeof col = {};
			cells.forEach((c, i) => {
				const k = c.replace(/\s+/g, ' ');
				for (const key of Object.keys(H) as (keyof typeof H)[]) if (hit[key] === undefined && H[key].test(k)) hit[key] = i;
			});
			if (hit.title !== undefined && (hit.date !== undefined || hit.day !== undefined)) { col = hit; month = null; continue; }

			// 월 칸 (병합되어 첫 줄에만 있는 경우가 많아 앞의 값을 이어 씀)
			if (col.month !== undefined) {
				const mv = cells[col.month].match(/(\d{1,2})/);
				if (mv) month = +mv[1];
			}
			let range: [string, string | null] | null = null;
			let titles: string[] = [];
			let note: string | null = null;
			if (col.title !== undefined) {
				range = cellRange(row[col.date ?? col.day ?? -1], year, month);
				if (range && col.end !== undefined) {
					const e = cellDate(row[col.end], +range[0].slice(0, 4), +range[0].slice(5, 7));
					if (e && e > range[0]) range[1] = e;
				}
				titles = splitCell(cells[col.title]);
				note = col.note !== undefined ? cells[col.note] || null : null;
			} else {
				// 머리줄이 없으면: 날짜로 읽히는 첫 칸 + 그 뒤의 첫 글자 칸
				const di = row.findIndex((v) => cellRange(v, year, month));
				if (di < 0) continue;
				range = cellRange(row[di], year, month);
				const rest = cells.map((c, i) => ({ c, i })).filter(({ c, i }) => i !== di && isTitle(c));
				titles = rest[0] ? splitCell(rest[0].c) : [];
				note = rest[1]?.c ?? null;
			}
			if (!range) continue;
			for (const t of titles) if (isTitle(t)) found.push(makeRow(range[0], range[1], t, note));
		}
	}
	// 공휴일은 빼고, 같은 이름의 여러 날 일정 안에 다시 적힌 날(예: 기도회 둘째 날)도 뺌
	let holidays = 0;
	const rows: PlanRow[] = [];
	for (const r of found.sort((a, b) => a.date.localeCompare(b.date))) {
		if (isHolidayTitle(r.title)) { holidays++; continue; }
		const inside = rows.some((x) => x.end_date && squeeze(x.title) === squeeze(r.title) && r.date > x.date && r.date <= x.end_date);
		if (!inside) rows.push(r);
	}
	return { rows, holidays };
}
