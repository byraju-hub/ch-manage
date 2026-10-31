<script lang="ts">
	// 쪽 번호: ‹ 1 … 4 5 [6] 7 8 … 20 ›  (지금 쪽 앞뒤 2쪽과 처음·끝 쪽을 보여 줌)
	let { page = $bindable(1), pages, onchange }: { page?: number; pages: number; onchange?: () => void } = $props();

	const items = $derived.by(() => {
		const out: (number | '…')[] = [];
		for (let i = 1; i <= pages; i++) {
			if (i === 1 || i === pages || Math.abs(i - page) <= 2) out.push(i);
			else if (out.at(-1) !== '…') out.push('…');
		}
		return out;
	});
	function go(n: number) {
		const p = Math.min(pages, Math.max(1, n));
		if (p === page) return;
		page = p;
		onchange?.();
	}
</script>

{#if pages > 1}
	<nav class="pager" aria-label="쪽 이동">
		<button type="button" class="pg arrow" onclick={() => go(page - 1)} disabled={page <= 1} aria-label="이전 쪽">‹</button>
		{#each items as it, i (i)}
			{#if it === '…'}<span class="gap">…</span>
			{:else}<button type="button" class="pg" class:on={it === page} aria-current={it === page ? 'page' : undefined} onclick={() => go(it)}>{it}</button>{/if}
		{/each}
		<button type="button" class="pg arrow" onclick={() => go(page + 1)} disabled={page >= pages} aria-label="다음 쪽">›</button>
	</nav>
{/if}

<style>
	.pager { display: flex; justify-content: center; align-items: center; gap: 0.3rem; flex-wrap: wrap; padding: 0.9rem 0.5rem; }
	.pg { min-width: 2.2rem; height: 2.2rem; padding: 0 0.55rem; border-radius: 9px; font-size: 0.88rem; font-weight: 600; color: var(--text); background: #fff; border: 1px solid var(--line-strong); box-shadow: none; }
	.pg:hover:not(:disabled) { color: var(--primary-600); background: var(--primary-50); border-color: var(--primary); transform: none; box-shadow: none; filter: none; }
	.pg.on { color: #fff; background: var(--primary); border-color: var(--primary); }
	.pg.arrow { font-size: 1.1rem; line-height: 1; }
	.gap { color: var(--muted); padding: 0 0.2rem; }
</style>
