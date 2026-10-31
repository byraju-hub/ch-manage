// PDF로 저장할 때만 쓰는 모양: 화면용 종이 테두리·그림자는 빼고, 표 선은 검정 얇은 선으로 또렷하게.
// (html2canvas 는 겹친 표 선을 두 번 그려 굵어지므로, 칸마다 오른쪽·아래 선만 긋고 표에 위·왼쪽 선을 그음)
const CSS = `
.pdf-capture { border: 0 !important; box-shadow: none !important; }
.pdf-capture table { border-collapse: separate !important; border-spacing: 0 !important; border: 0 !important; border-top: 1px solid #000 !important; border-left: 1px solid #000 !important; }
.pdf-capture th, .pdf-capture td { border: 0 !important; border-right: 1px solid #000 !important; border-bottom: 1px solid #000 !important; }
`;

export async function withPdfStyle<T>(el: HTMLElement, run: () => Promise<T>): Promise<T> {
	const style = document.createElement('style');
	style.textContent = CSS;
	document.head.append(style);
	el.classList.add('pdf-capture');
	try {
		return await run();
	} finally {
		el.classList.remove('pdf-capture');
		style.remove();
	}
}
