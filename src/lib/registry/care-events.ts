// 심방 내용에서 날짜가 적힌 성도 일정 뽑기: '22일 입원, 23일 수술' → 10/22 입원, 10/23 수술
// 달이 없으면 심방한 달로 봄 (심방일보다 일주일 넘게 앞선 날이면 다음 달). '10월 22일', '10/22', '22~25일 입원'도 읽음.
// [실제 시스템] 문장이 복잡하면 심방 분석(AI)에서 일정도 함께 뽑게 하면 더 정확합니다.

// 일정으로 볼 낱말 (날짜 바로 뒤에 오는 낱말이 이것으로 시작해야 함)
export const CARE_WORDS = [
	'입원', '퇴원', '수술', '시술', '검사', '항암', '방사선', '투석', '치료', '재활',
	'출산', '돌잔치', '돌', '백일', '회갑', '칠순', '팔순', '구순', '결혼식', '약혼', '상견례',
	'장례', '발인', '입관', '추도', '이사', '입주', '개업', '입대', '전역', '제대', '입학', '졸업', '시험', '수능', '면접', '취업', '입사', '출국', '귀국'
].sort((a, b) => b.length - a.length);

// 날짜와 일 사이에 오는 '누구': '22일 자녀 입대', '22일 민준 입대', '22일 큰아들 졸업'
export const FAMILY_WORDS = ['자녀', '아들', '딸', '큰아들', '작은아들', '막내아들', '큰딸', '작은딸', '막내딸', '막내', '손자', '손녀', '손주', '남편', '아내', '배우자', '며느리', '사위'];

// who: 날짜 뒤에 적힌 사람 (없으면 심방받은 본인)
export interface CareEvent { date: string; end_date: string | null; title: string; who: string | null }

const pad = (n: number) => String(n).padStart(2, '0');
function resolve(base: string, month: number | null, day: number): string | null {
	const [by, bm, bd] = base.split('-').map(Number);
	let y = by, m = month ?? bm;
	if (!month && day < bd - 7) m++; // '3일 수술' (28일 심방) → 다음 달 3일
	if (month && m < bm - 6) y++; // 12월 심방에 '1월 5일' → 다음 해
	if (m > 12) { m = 1; y++; }
	const last = new Date(y, m, 0).getDate();
	return day >= 1 && day <= last ? `${y}-${pad(m)}-${pad(day)}` : null;
}

export function extractCareEvents(text: string, base: string): CareEvent[] {
	const out: CareEvent[] = [];
	// (월) 일 [~ (월) 일] [에|부터|날|예정] 낱말   또는   월/일 낱말
	const re = /(?:(\d{1,2})\s*월\s*)?(\d{1,2})\s*일(?:\s*[~∼〜-]\s*(?:(\d{1,2})\s*월\s*)?(\d{1,2})\s*일)?|(\d{1,2})\s*[./]\s*(\d{1,2})(?:\s*[~∼〜-]\s*(\d{1,2})(?:\s*[./]\s*(\d{1,2}))?)?/g;
	for (const m of text.matchAll(re)) {
		let after = text.slice(m.index! + m[0].length).replace(/^\s*(에|에는|부터|날|경|쯤|예정으로|오전|오후|\(.\))*\s*/, '');
		let word = CARE_WORDS.find((w) => after.startsWith(w));
		let who: string | null = null;
		if (!word) {
			// 사이에 사람이 낀 경우: 가족 낱말 또는 이름(2~3자) + 조사
			const s = after.match(/^([가-힣]{1,5}?)(?:이가|이는|이|가|는|의)?\s+/);
			const rest = s ? after.slice(s[0].length) : '';
			const w2 = s ? CARE_WORDS.find((w) => rest.startsWith(w)) : undefined;
			if (!s || !w2 || !(FAMILY_WORDS.includes(s[1]) || /^[가-힣]{2,3}$/.test(s[1]))) continue;
			who = s[1]; word = w2; after = rest;
		}
		let date: string | null, end: string | null = null;
		// '다음 달 15일' → 심방한 달의 다음 달
		const nextMonth = !m[1] && /다음\s*달\s*$/.test(text.slice(0, m.index));
		if (nextMonth) m[1] = String((+base.slice(5, 7) % 12) + 1);
		if (m[2]) {
			date = resolve(base, m[1] ? +m[1] : null, +m[2]);
			if (m[4]) end = resolve(base, m[3] ? +m[3] : m[1] ? +m[1] : null, +m[4]);
		} else {
			date = resolve(base, +m[5], +m[6]);
			if (m[7]) end = m[8] ? resolve(base, +m[7], +m[8]) : resolve(base, +m[5], +m[7]);
		}
		if (!date) continue;
		// '항암 치료', '정밀 검사'처럼 뒤에 붙는 말까지
		const tail = after.slice(word.length).match(/^\s*(치료|검사|수술|예배)/)?.[1];
		const title = word === '돌' ? '돌잔치' : tail ? `${word} ${tail}` : word;
		if (!out.some((e) => e.date === date && e.title === title && e.who === who)) out.push({ date, end_date: end && end > date ? end : null, title, who });
	}
	return out.sort((a, b) => a.date.localeCompare(b.date));
}
