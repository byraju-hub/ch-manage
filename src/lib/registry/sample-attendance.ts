// 샘플 데이터 전용: 가상 출석 기록을 만들어 낸다. (설정의 '샘플 데이터 불러오기'에서만 불러옴 — 실제 출석은 서버에 저장된 기록만 쓴다)

import { generated } from './seed.ts';

// 최근 연속으로 결석 중인 사람: 사람 id → 최근 몇 주 연속 결석인지
export const ABSENT_STREAK: Record<string, number> = { p10: 6, p12: 3, ...generated.absentStreak };
// 사람별 평소 출석률 (없으면 0.82)
const RATE: Record<string, number> = { p1: 0.95, p2: 0.95, p3: 0.8, p4: 0.9, p5: 0.9, p6: 0.85, p7: 0.7, p9: 0.9, p13: 0.75, ...generated.attendRate };

function hash(s: string): number {
	let h = 2166136261;
	for (let i = 0; i < s.length; i++) h = Math.imul(h ^ s.charCodeAt(i), 16777619);
	return (h >>> 0) % 1000;
}

const iso = (d: Date) =>
	`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

// 오늘 이전의 가장 가까운 주일부터 거꾸로 n주 (오래된 순)
export function recentSundays(n = 8, today = new Date()): string[] {
	const last = new Date(today.getFullYear(), today.getMonth(), today.getDate());
	last.setDate(last.getDate() - last.getDay());
	const out: string[] = [];
	for (let i = n - 1; i >= 0; i--) {
		const d = new Date(last);
		d.setDate(last.getDate() - i * 7);
		out.push(iso(d));
	}
	return out;
}

// 주일 오전예배 출석 (교적 프로그램의 '출석' 화면에서 등록). weeksFromEnd: 0 = 가장 최근 주일
export function wasPresent(personId: string, sundayIso: string, weeksFromEnd: number): boolean {
	if ((ABSENT_STREAK[personId] ?? 0) > weeksFromEnd) return false;
	return hash(personId + sundayIso) / 1000 < (RATE[personId] ?? 0.82);
}

// 주일 오전예배에 온 사람 중 명단에 체크되지 않는 인원 (방문자·타교인·명단 밖 교인 등) 가상 샘플: 20~44명
export function sampleOthers(sundayIso: string): number {
	return 20 + (hash('others' + sundayIso) % 25);
}

// 주일 오후 소그룹 모임 출석 (홈페이지 출석부에서 소그룹 인도자가 등록. 예배 출석과 별개)
// 가상 샘플: 예배에 나온 사람 중 대부분이 소그룹 모임에도 참석
export function wasPresentGroup(personId: string, sundayIso: string, weeksFromEnd: number): boolean {
	if (!wasPresent(personId, sundayIso, weeksFromEnd)) return hash('g' + personId + sundayIso) / 1000 < 0.05;
	return hash('g' + personId + sundayIso) / 1000 < 0.85;
}
