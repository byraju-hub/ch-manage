// 대시보드 캘린더: 해마다 날짜가 정해지는 교회 절기·기념일을 계산합니다.
// (교회 창립 기념일 날짜는 가상입니다. 실제 날짜는 관리자 화면에서 정하게 됩니다.)

export const pad2 = (n: number) => String(n).padStart(2, '0');
export const isoDate = (d: Date) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
const at = (y: number, m: number, d: number) => new Date(y, m - 1, d);
export function addDays(iso: string, n: number): string {
	const [y, m, d] = iso.split('-').map(Number);
	const x = at(y, m, d);
	x.setDate(x.getDate() + n);
	return isoDate(x);
}

// 부활주일 (서방 교회 기준)
export function easter(y: number): string {
	const a = y % 19, b = Math.floor(y / 100), c = y % 100;
	const d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25);
	const g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
	const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7;
	const m = Math.floor((a + 11 * h + 22 * l) / 451);
	const month = Math.floor((h + l - 7 * m + 114) / 31);
	const day = ((h + l - 7 * m + 114) % 31) + 1;
	return `${y}-${pad2(month)}-${pad2(day)}`;
}

// 그 달의 n번째 일요일 (n=-1이면 마지막)
export function nthSunday(y: number, m: number, n: number): string {
	if (n > 0) {
		const first = at(y, m, 1);
		return isoDate(at(y, m, 1 + ((7 - first.getDay()) % 7) + (n - 1) * 7));
	}
	const last = at(y, m + 1, 0);
	return isoDate(at(y, m, last.getDate() - last.getDay()));
}

export interface Seasonal { date: string; end_date: string | null; title: string }

export function churchSeasons(y: number): Seasonal[] {
	const e = easter(y);
	const s = (date: string, title: string, end_date: string | null = null): Seasonal => ({ date, end_date, title });
	return [
		s(`${y}-01-01`, '신년 감사 예배'),
		s(`${y}-05-10`, '교회 창립 기념일 (가상 날짜)'),
		s(nthSunday(y, 5, 1), '어린이주일'),
		s(nthSunday(y, 5, 2), '어버이주일'),
		s(addDays(e, -7), '고난주간 시작 (종려주일)', addDays(e, -1)),
		s(e, '부활주일'),
		s(addDays(e, 49), '성령강림주일'),
		s(nthSunday(y, 7, 1), '맥추감사주일'),
		s(nthSunday(y, 10, -1), '종교개혁주일'),
		s(nthSunday(y, 11, 3), '추수감사주일'),
		s(`${y}-12-25`, '성탄절'),
		s(`${y}-12-31`, '송구영신 예배')
	];
}

// ---- 한국 공휴일 (캘린더에 빨간색으로 기본 표시) ----
// 양력 공휴일은 계산하고, 설날·추석·부처님오신날(음력)은 해마다 날짜표로 넣습니다.
// [실제 시스템] 한국천문연구원 특일 정보 API(공공데이터포털)로 해마다 받아 오면 임시공휴일·선거일까지 정확합니다.
const LUNAR: Record<number, { seol: string; chuseok: string; buddha: string }> = {
	2025: { seol: '01-29', chuseok: '10-06', buddha: '05-05' },
	2026: { seol: '02-17', chuseok: '09-25', buddha: '05-24' },
	2027: { seol: '02-07', chuseok: '09-15', buddha: '05-13' },
	2028: { seol: '01-27', chuseok: '10-03', buddha: '05-02' },
	2029: { seol: '02-13', chuseok: '09-22', buddha: '05-20' },
	2030: { seol: '02-03', chuseok: '09-12', buddha: '05-09' }
};
const dow = (iso: string) => { const [y, m, d] = iso.split('-').map(Number); return at(y, m, d).getDay(); };
const holidayCache = new Map<number, { date: string; title: string }[]>();
export function koreanHolidays(y: number): { date: string; title: string }[] {
	const hit = holidayCache.get(y);
	if (hit) return hit;
	const list: { date: string; title: string; sub?: 'weekend' | 'sunday' }[] = [
		{ date: `${y}-01-01`, title: '신정' },
		{ date: `${y}-03-01`, title: '삼일절', sub: 'weekend' },
		{ date: `${y}-05-05`, title: '어린이날', sub: 'weekend' },
		{ date: `${y}-06-06`, title: '현충일' },
		{ date: `${y}-08-15`, title: '광복절', sub: 'weekend' },
		{ date: `${y}-10-03`, title: '개천절', sub: 'weekend' },
		{ date: `${y}-10-09`, title: '한글날', sub: 'weekend' },
		{ date: `${y}-12-25`, title: '성탄절', sub: 'weekend' }
	];
	const lunar = LUNAR[y];
	if (lunar) {
		const seol = `${y}-${lunar.seol}`, chu = `${y}-${lunar.chuseok}`;
		for (const [day, name] of [[seol, '설날'], [chu, '추석']] as const)
			for (const k of [-1, 0, 1]) list.push({ date: addDays(day, k), title: k ? `${name} 연휴` : name, sub: 'sunday' });
		list.push({ date: `${y}-${lunar.buddha}`, title: '부처님오신날', sub: 'weekend' });
	}
	// 대체공휴일: 설날·추석은 일요일이나 다른 공휴일과 겹칠 때, 그 밖(삼일절·어린이날·광복절·개천절·한글날·성탄절·부처님오신날)은 주말이나 겹칠 때
	const taken = new Set(list.map((h) => h.date));
	const extra: { date: string; title: string }[] = [];
	const overlap = (h: (typeof list)[number]) => list.filter((x) => x.date === h.date).length > 1;
	for (const h of list) {
		if (!h.sub) continue;
		const d = dow(h.date);
		const need = h.sub === 'weekend' ? d === 0 || d === 6 || overlap(h) : d === 0 || overlap(h);
		if (!need) continue;
		let alt = addDays(h.date, 1);
		while (dow(alt) === 0 || dow(alt) === 6 || taken.has(alt)) alt = addDays(alt, 1);
		taken.add(alt);
		extra.push({ date: alt, title: '대체공휴일' });
	}
	// 겹친 날은 한 번만 (이름을 함께 적음)
	const byDate = new Map<string, string[]>();
	for (const h of [...list, ...extra]) byDate.set(h.date, [...(byDate.get(h.date) ?? []), h.title]);
	const out = [...byDate.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([date, t]) => ({ date, title: [...new Set(t)].join(' · ') }));
	holidayCache.set(y, out);
	return out;
}
export const isHoliday = (iso: string) => koreanHolidays(+iso.slice(0, 4)).some((h) => h.date === iso);
// 연간 계획 엑셀에서 공휴일 줄을 알아보는 낱말 (캘린더에 이미 있으므로 등록하지 않음)
export const HOLIDAY_WORDS = ['신정', '구정', '설날', '설 연휴', '삼일절', '3·1절', '대체휴일', '대체공휴일', '어린이날', '석탄일', '석가탄신일', '부처님오신날', '현충일', '광복절', '추석', '개천절', '한글날'];

// 달력에 쓰는 한 줄
export interface CalItem {
	date: string;
	end_date?: string | null;
	kind: import('./types.ts').EventKind;
	title: string;
	sub?: string;
	person_id?: string | null;
	href?: string;
	event_id?: string; // 직접 등록한 일정 (수정·삭제 가능)
}

// 캘린더 버튼에 쓰는 두 글자 이름
export const KIND_LABEL: Record<import('./types.ts').EventKind, string> = {
	공휴일: '공휴일', 생일: '생일', 성도: '성도', '교회 절기': '절기', '사역 일정': '사역', '성례 일정': '성례', '심방 계획': '심방'
};
export const KIND_COLOR: Record<import('./types.ts').EventKind, string> = {
	공휴일: '#d63c3c',
	생일: '#f0642a',
	'성도': '#c04aa0',
	'교회 절기': '#4558ae',
	'사역 일정': '#14b4ae',
	'성례 일정': '#d9a400',
	'심방 계획': '#4a8f2f'
};
