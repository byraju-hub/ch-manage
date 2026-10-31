<script lang="ts">
	// 인쇄 · 엑셀 · PDF 버튼 (모든 화면에서 같은 순서·같은 아이콘). 넘겨준 기능의 버튼만 보임
	let {
		onprint,
		onexcel,
		onpdf,
		disabled = false,
		busy = false,
		title = ''
	}: { onprint?: () => void; onexcel?: () => void; onpdf?: () => void; disabled?: boolean; busy?: boolean; title?: string } = $props();
</script>

<span class="exp">
	{#if onprint}
		<button type="button" class="ghost" onclick={onprint} {disabled} title={title || '인쇄'}>인쇄</button>
	{/if}
	{#if onexcel}
		<button type="button" class="ghost xl" onclick={onexcel} disabled={disabled || busy} title={title || '엑셀(.xlsx)로 내보내기'} aria-label="엑셀로 내보내기">
			<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="20" height="18" rx="3" fill="#1D6F42" /><path d="M7.5 7.5l4 4.5-4 4.5M16.5 7.5l-4 4.5 4 4.5" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
			엑셀
		</button>
	{/if}
	{#if onpdf}
		<button type="button" class="ghost pdf" onclick={onpdf} disabled={disabled || busy} title={title || 'PDF로 저장'} aria-label="PDF로 저장">
			<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="4" fill="#E2231A" /><path d="M6.2 17.3c1.2-.7 3.6-4.7 5.1-9.4.4-1.3.1-2.3-.5-2.3s-.9 1-.5 2.6c.8 3 3.6 6.8 6.4 7.6 1.6.5 2.2-.2 1.6-.8-.7-.7-3.9-.4-7 .7-3 1.1-5.1 2.7-5.1 1.6z" fill="none" stroke="#fff" stroke-width="1.4" stroke-linejoin="round" /></svg>
			{busy ? '만드는 중…' : 'PDF'}
		</button>
	{/if}
</span>

<style>
	.exp { display: inline-flex; flex-wrap: wrap; gap: 0.4rem; }
	.xl, .pdf { display: inline-flex; align-items: center; gap: 0.35rem; }
	.xl svg, .pdf svg { width: 18px; height: 18px; }
	.xl { color: #1d6f42; border-color: #9fd3b4; }
	.xl:hover:not(:disabled) { background: #ebf7f0; color: #1d6f42; border-color: #1d6f42; }
	.pdf { color: #c4161c; border-color: #f2b1ae; }
	.pdf:hover:not(:disabled) { background: #fdecea; color: #c4161c; border-color: #e2231a; }
</style>
