<script lang="ts">
	// 세대주(대가족) / 관계 입력 한 줄: [이름] [검색어] [검색] 의 [관계] [세대주정보가져오기]
	import { registry } from '#lib/registry/store.svelte.ts';
	import { RELATIONS } from '#lib/registry/types.ts';

	let {
		label = '신앙세대주 / 관계', required = false, headId = $bindable(''), relation = $bindable('본인'), withSelf = true, note = '', onfetch
	}: { label?: string; required?: boolean; headId?: string; relation?: string; withSelf?: boolean; note?: string; onfetch?: (id: string) => void } = $props();

	let q = $state('');
	let results = $state<string[]>([]);
	const head = $derived(headId ? registry.person(headId) : undefined);
	const options = $derived(withSelf ? RELATIONS : RELATIONS.filter((r) => r !== '본인'));

	function search() {
		const t = q.trim().toLowerCase();
		const d = t.replace(/\D/g, '');
		results = t ? registry.d.people.filter((p) => p.name.toLowerCase().includes(t) || (d.length >= 3 && (p.phone ?? '').replace(/\D/g, '').includes(d))).slice(0, 8).map((p) => p.id) : [];
	}
	function choose(id: string) {
		headId = id; results = []; q = '';
		if (relation === '본인') relation = '배우자';
	}
	function setRelation(e: Event) {
		relation = (e.currentTarget as HTMLSelectElement).value;
		if (relation === '본인') headId = '';
	}
</script>

<div class="hr-row">
	<span class="hr-label">{label}{#if required}<b class="req">(*)</b>{/if}</span>
	<input class="hr-name" readonly tabindex="-1" value={relation === '본인' ? '(본인)' : head ? head.name : ''} placeholder="이름" aria-label="선택한 사람" />
	<input class="hr-q" bind:value={q} placeholder="이름·전화" disabled={relation === '본인'} onkeydown={(e) => { if (e.key === 'Enter') { e.preventDefault(); search(); } }} aria-label="검색어" />
	<button type="button" class="ghost sm" onclick={search} disabled={relation === '본인'}>검색</button>
	<span>의</span>
	<select class="hr-rel" value={relation} onchange={setRelation} aria-label="관계">{#each options as r}<option>{r}</option>{/each}</select>
	{#if onfetch}<button type="button" class="ghost sm" onclick={() => headId && onfetch(headId)} disabled={!headId || relation === '본인'}>세대주정보가져오기</button>{/if}
	{#if note}<span class="hr-note">{note}</span>{/if}
</div>
{#if results.length}
	<div class="hr-results">
		{#each results as id (id)}
			{@const p = registry.person(id)!}
			<button type="button" class="opt" onclick={() => choose(id)}><b>{p.name}</b>{p.name_note ? ` (${p.name_note})` : ''} <small>{p.birth_date?.slice(0, 4) ?? ''}년생 · {p.phone ?? '전화 없음'} · {registry.householdOf(id)?.address ?? '세대 없음'}</small></button>
		{/each}
	</div>
{:else if q && relation !== '본인'}
	<span class="muted" style="font-size:.78rem">'검색'을 누르면 결과가 나옵니다.</span>
{/if}

<style>
	.hr-row { display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap; padding: 0.5rem 0.2rem; font-weight: 400; color: var(--text); }
	.hr-label { font-weight: 700; font-size: 0.86rem; min-width: 9.2rem; color: var(--text); }
	.req { color: var(--orange-600); margin-left: 0.1rem; }
	.hr-name { width: 7rem; background: #eceff5; font-weight: 600; }
	.hr-q { width: 9rem; }
	.hr-rel { width: 8rem; }
	.hr-note { color: var(--primary-600); font-size: 0.82rem; font-weight: 600; }
	.hr-results { margin: 0.2rem 0 0.4rem 9.6rem; display: grid; gap: 2px; max-width: 30rem; border: 1px solid var(--line); border-radius: 10px; padding: 0.25rem; background: #fff; }
	.opt { display: block; text-align: left; font-weight: 500; color: var(--text); background: transparent; box-shadow: none; border-radius: 8px; padding: 0.4rem 0.6rem; }
	.opt:hover { background: var(--primary-50); transform: none; box-shadow: none; filter: none; color: var(--text); }
	.opt small { color: var(--muted); font-weight: 400; margin-left: 0.4rem; }
</style>
