// 연간 설정: 해마다 새로 정하는 기준 (관리자 › 교회 설정 › 연간 설정)
//  - 나이 계산 방식 (만 나이 · 한국 나이 · 연 나이)
//  - 공동체별 출생 연도, 남·여전도회별 출생 연도
//  - 교육부서 학년(나이) 기준과 부서 이동(진급) 날짜
//  - 올해 표어·말씀, 장기 결석 기준, 새가족 기간
// [실제 시스템] year_settings 표에 연도별로 한 줄씩 저장하고, 새해가 되면 지난해 설정을 복사해 고쳐 씁니다.
import type { EduDept } from './types.ts';

export type AgeMode = '만 나이' | '한국 나이' | '연 나이';
export const AGE_MODES: { mode: AgeMode; desc: string }[] = [
	{ mode: '만 나이', desc: '생일이 지나야 한 살 더함 (법적 기준, 2023년부터 공식)' },
	{ mode: '한국 나이', desc: '태어나면 1살, 해가 바뀌면 한 살 더함 (세는 나이)' },
	{ mode: '연 나이', desc: '올해 연도 - 출생 연도 (학년·병역 기준)' }
];

export interface BornRange { from: number; to: number } // 출생 연도 (포함)
// 교육부서 기준: '학교 나이'(올해 - 출생 연도)로 정함. 7 = 초1, 13 = 중1, 16 = 고1, 19 = 고3 졸업 후
export interface EduRule { dept: EduDept | '장년'; from: number; to: number }

export interface YearSettings {
	year: number;
	motto: string;
	verse: string;
	ageMode: AgeMode;
	communities: Record<string, BornRange>;
	societies: Record<string, BornRange>;
	edu: EduRule[];
	promoteDate: string; // 교육부서 진급(이동) 날짜
	absentWeeks: number; // 장기 결석 알림 기준 (주)
	newcomerDays: number; // 새가족으로 보는 기간 (일)
}

// 학교 나이(올해 - 출생 연도, 7 = 초1)를 고른 나이 계산 방식의 나이로 보여 줌
// 연 나이: 초1 = 7세 · 한국 나이: 초1 = 8세 · 만 나이: 초1 = 만 6~7세 (생일 전·후)
// 0은 어느 방식이든 0세 (영아부는 태아부터라 시작이 늘 0세). 저장하는 값은 늘 학교 나이라 방식을 바꿔도 부서 기준은 그대로
export function ageInMode(n: number, mode: AgeMode = '연 나이'): string {
	if (n <= 0) return '0세';
	if (mode === '한국 나이') return `${n + 1}세`;
	if (mode === '만 나이') return `만 ${n - 1}~${n}세`;
	return `${n}세`;
}
// 학교 나이 → 학년 이름 (7 = 초1 … 18 = 고3). 나이는 고른 방식으로 함께 적음
export function gradeLabel(n: number, mode: AgeMode = '연 나이'): string {
	const a = ageInMode(n, mode);
	if (n <= 0) return '0세 (태아 포함)';
	if (n <= 6) return `${a} (미취학)`;
	if (n <= 12) return `초${n - 6} (${a})`;
	if (n <= 15) return `중${n - 12} (${a})`;
	if (n <= 18) return `고${n - 15} (${a})`;
	return a;
}
export const GRADE_OPTIONS = Array.from({ length: 46 }, (_, i) => i); // 0 ~ 45

export function defaultSettings(year: number): YearSettings {
	// 2026년 기준값을 해마다 그대로 옮김 (출생 연도는 올해와의 차이로 맞춤)
	const k = year - 2026;
	const r = (from: number, to: number): BornRange => ({ from: from + (from <= 1900 ? 0 : k), to: to + (to >= 2100 ? 0 : k) });
	const male: [number, number][] = [[1900, 1954], [1955, 1961], [1962, 1968], [1969, 1976], [1977, 1984], [1985, 2100]];
	const female: [number, number][] = [[1900, 1947], [1948, 1951], [1952, 1955], [1956, 1959], [1960, 1963], [1964, 1967], [1968, 1971], [1972, 1975], [1976, 1979], [1980, 2100]];
	return {
		year,
		motto: year === 2026 ? '소그룹과 함께 마음을 같이하는 교회' : '',
		verse: year === 2026 ? '행 2:46' : '',
		ageMode: '만 나이',
		// 공동체는 해마다 출생 연도를 그대로 두는 경우가 많아 옮기지 않음
		communities: { '뿌리 공동체': { from: 1900, to: 1955 }, '줄기 공동체': { from: 1956, to: 1970 }, '열매 공동체': { from: 1971, to: 2100 } },
		societies: Object.fromEntries([
			...male.map(([a, b], i) => [`${i + 1}남전도회`, r(a, b)]),
			...female.map(([a, b], i) => [`${i + 1}여전도회`, r(a, b)])
		]),
		edu: [
			{ dept: '영아부', from: 0, to: 3 },
			{ dept: '유치부', from: 4, to: 6 },
			{ dept: '유초등부', from: 7, to: 12 },
			{ dept: '중고등부', from: 13, to: 18 },
			{ dept: '청년부', from: 19, to: 39 },
			{ dept: '장년', from: 40, to: 200 }
		],
		promoteDate: `${year}-01-04`,
		absentWeeks: 9,
		newcomerDays: 60
	};
}

// 출생 연도 범위를 글로: '55년 이전 출생', '56~70년 출생', '71년 이후 출생'
export function bornText(r: BornRange): string {
	const yy = (y: number) => String(y % 100).padStart(2, '0');
	if (r.from <= 1900 && r.to >= 2100) return '출생 연도 기준 없음';
	if (r.from <= 1900) return `${yy(r.to)}년 이전 출생`;
	if (r.to >= 2100) return `${yy(r.from)}년 이후 출생`;
	return `${yy(r.from)}~${yy(r.to)}년 출생`;
}
export const inRange = (y: number, r: BornRange) => y >= r.from && y <= r.to;

// 학교 나이로 교육부서 정하기 (없으면 null)
export function eduDeptFor(birthYear: number, s: YearSettings): EduRule['dept'] | null {
	const n = s.year - birthYear;
	return s.edu.find((e) => n >= e.from && n <= e.to)?.dept ?? null;
}
