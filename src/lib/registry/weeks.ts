// 출석 화면들이 함께 쓰는 날짜 도우미. (가상 출석은 샘플 데이터를 넣을 때만 만들어지며 sample-attendance.ts 에 있다)

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
