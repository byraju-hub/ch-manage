// 교인 목록 엑셀(.xlsx) 내려받기: 제목 줄 굵게·회색, 모든 칸 테두리, 첫 줄 고정.
// 교인 목록 화면과 '항목 설정' 화면이 같이 쓴다. (엑셀 라이브러리는 누를 때 불러온다)
import { todayIso } from './calendar.ts';

export async function saveMemberSheet(opts: {
	header: string[]; // 제목 줄
	body: string[][]; // 교인 한 명이 한 줄
	widths: number[]; // 칸 너비
	headBg: string; // 제목 줄 배경색
	centerBody?: boolean; // 본문도 가운데 정렬
}): Promise<void> {
	const { default: writeXlsxFile } = await import('write-excel-file/browser');
	const st = { borderStyle: 'thin' as const, borderColor: '#999999', alignVertical: 'center' as const };
	const center = { align: 'center' as const };
	const data = [
		opts.header.map((value) => ({ value, fontWeight: 'bold' as const, backgroundColor: opts.headBg, ...center, ...st })),
		...opts.body.map((r) => r.map((value) => ({ value, ...(opts.centerBody ? center : {}), ...st })))
	];
	await writeXlsxFile(data, { sheet: '교인 목록', columns: opts.widths.map((width) => ({ width })), stickyRowsCount: 1 }).toFile(`교인목록-${todayIso()}.xlsx`);
}
