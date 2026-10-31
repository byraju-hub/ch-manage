<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 이름을 쉼표로 이어 쓰는 입력 (심방 동행자, 교인 복수 검색, 부서 담당자)
	import { registry } from '#lib/registry/store.svelte.ts';
	import type { Person } from '#lib/registry/types.ts';

	// 이름을 쓰고 쉼표(,)를 넣으면 교적에서 찾아 칸으로 만듭니다.
	//  - 이름이 한 명이면 바로 들어가고, 같은 이름이 여러 명이면 목록에서 고릅니다.
	//  - allowFree: 교적에 없는 이름(교인 아님)도 그대로 넣을 수 있음
	export interface Chip { id: string | null; name: string }
	let { chips = $bindable([]), allowFree = false, placeholder = '이름을 쓰고 쉼표(,)', onchange }: { chips?: Chip[]; allowFree?: boolean; placeholder?: string; onchange?: (chips: Chip[]) => void } = $props();
	function setChips(next: Chip[]) { chips = next; onchange?.(next); }

	let text = $state('');
	let pending = $state<{ term: string; cands: Person[] }[]>([]);

	const label = (p: Person) => {
		const s = registry.summary(p);
		return `${p.name}${p.name_note ? ` (${p.name_note})` : ''} · ${p.birth_date?.slice(0, 4) ?? '?'}년생 · ${s.smallGroup || s.category || '-'}${p.phone ? ` · ${p.phone.slice(-4)}` : ''}`;
	};
	function add(c: Chip) {
		if (c.id ? chips.some((x) => x.id === c.id) : chips.some((x) => !x.id && x.name === c.name)) return;
		setChips([...chips, c]);
	}
	function commit(raw: string) {
		const t = raw.trim();
		if (!t) return;
		const found = registry.d.people.filter((p) => p.name === t);
		if (found.length === 1) add({ id: found[0].id, name: found[0].name });
		else if (found.length > 1) { if (!pending.some((x) => x.term === t)) pending = [...pending, { term: t, cands: found }]; }
		else add({ id: null, name: t });
	}
	function onInput() {
		if (!/[,，\n]/.test(text)) return;
		const parts = text.split(/[,，\n]/);
		text = parts.pop() ?? '';
		parts.forEach(commit);
	}
	function onKey(e: KeyboardEvent) {
		if (e.key === 'Enter') { e.preventDefault(); commit(text); text = ''; }
		else if (e.key === 'Backspace' && !text && chips.length) setChips(chips.slice(0, -1));
	}
	function choose(term: string, p: Person) {
		add({ id: p.id, name: p.name });
		pending = pending.filter((x) => x.term !== term);
	}
	const missing = $derived(!allowFree && chips.some((c) => !c.id));
</script>

<div class="nc">
	<div class="nc-box">
		{#each chips as c, i (c.id ?? `n-${c.name}`)}
			<span class="nc-chip" class:bad={!c.id && !allowFree} class:free={!c.id && allowFree}>
				{#if c.id}<a href={hrefTo(`/${c.id}`)} target="_blank" rel="noreferrer">{c.name}</a>{:else}{c.name}{/if}
				<button type="button" aria-label="{c.name} 빼기" onclick={() => setChips(chips.filter((_, j) => j !== i))}>×</button>
			</span>
		{/each}
		<input bind:value={text} oninput={onInput} onkeydown={onKey} onblur={() => { commit(text); text = ''; }} {placeholder} />
	</div>
	{#each pending as q (q.term)}
		<div class="nc-pick">
			<b>'{q.term}'</b> 이름의 교인이 {q.cands.length}명 있습니다. 누구인가요?
			<div class="nc-cands">
				{#each q.cands as p (p.id)}<button type="button" class="ghost sm" onclick={() => choose(q.term, p)}>{label(p)}</button>{/each}
				<button type="button" class="ghost sm" onclick={() => (pending = pending.filter((x) => x !== q))}>취소</button>
			</div>
		</div>
	{/each}
	{#if missing}<p class="muted" style="margin:.3rem 0 0;font-size:.8rem;color:#a83c10">붉은 이름은 교적에 없습니다. 이름을 다시 확인해 주세요.</p>{/if}
	{#if allowFree && chips.some((c) => !c.id)}<p class="muted" style="margin:.3rem 0 0;font-size:.78rem">회색 이름은 교적에 없는 사람(교인 아님)으로 그대로 기록됩니다.</p>{/if}
</div>

<style>
	.nc-box { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; padding: 0.35rem 0.5rem; border: 1px solid var(--line-strong); border-radius: 10px; background: #fff; }
	.nc-box:focus-within { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(43, 62, 149, 0.15); }
	.nc-box input { flex: 1; min-width: 9rem; border: 0; box-shadow: none; padding: 0.25rem 0.2rem; }
	.nc-box input:focus { box-shadow: none; }
	.nc-chip { display: inline-flex; align-items: center; gap: 0.2rem; padding: 0.15rem 0.2rem 0.15rem 0.6rem; font-size: 0.86rem; font-weight: 600; border-radius: 999px; background: var(--primary-100); color: var(--primary-600); }
	.nc-chip a { color: inherit; }
	.nc-chip.bad { background: var(--orange-100); color: #a83c10; }
	.nc-chip.free { background: #eceff4; color: #4b5568; }
	.nc-chip button { width: 1.2rem; height: 1.2rem; padding: 0; border-radius: 50%; background: transparent; color: inherit; box-shadow: none; font-size: 0.95rem; line-height: 1; }
	.nc-chip button:hover { background: rgba(0, 0, 0, 0.1); transform: none; box-shadow: none; filter: none; color: inherit; }
	.nc-pick { margin-top: 0.4rem; padding: 0.55rem 0.7rem; background: var(--orange-50); border: 1px solid var(--orange-100); border-radius: 10px; font-size: 0.86rem; font-weight: 400; color: var(--text); }
	.nc-cands { display: flex; gap: 0.35rem; flex-wrap: wrap; margin-top: 0.4rem; }
</style>
