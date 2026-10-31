<script lang="ts">
	// 교인 한 명을 이름·전화로 찾아 고르는 칸
	import { registry } from '#lib/registry/store.svelte.ts';

	// compact: 고른 뒤 이름만 보임(소그룹·교인구분 없이). nameOnly: '바꾸기' 버튼도 없이 이름만 (이름을 누르면 다시 검색). only: 고를 수 있는 사람 거르기
	let { value = $bindable(''), placeholder = '이름·전화 검색', onpick, compact = false, nameOnly = false, only }: { value?: string; placeholder?: string; onpick?: (id: string) => void; compact?: boolean; nameOnly?: boolean; only?: (p: import('#lib/registry/types.ts').Person) => boolean } = $props();
	let q = $state('');
	let open = $state(false);
	let input = $state<HTMLInputElement | null>(null);
	// 검색 목록은 화면 위에 떠서 보이게 함 (카드·표 안에서 잘리지 않도록 입력 칸 위치를 따라감)
	let pos = $state({ top: 0, left: 0, width: 0, maxH: 300, up: false });
	const picked = $derived(value ? registry.person(value) : undefined);
	const matches = $derived.by(() => {
		const t = q.trim().toLowerCase();
		if (!t) return [];
		const d = t.replace(/\D/g, '');
		return registry.d.people.filter((p) => (!only || only(p)) && p.name.toLowerCase().includes(t) || (d.length >= 3 && (p.phone ?? '').replace(/\D/g, '').includes(d))).slice(0, 8);
	});
	function place() {
		if (!input) return;
		const r = input.getBoundingClientRect();
		// 휴대폰 자판이 올라와 있으면 보이는 화면 높이(visualViewport)로 계산. 웬만하면 입력 칸 아래에, 입력 칸과 같은 폭으로
		const vv = window.visualViewport;
		const vh = vv ? vv.offsetTop + vv.height : window.innerHeight;
		const below = vh - r.bottom - 8;
		const up = below < 140 && r.top > below;
		pos = { top: up ? r.top - 4 : r.bottom + 4, left: r.left, width: r.width, maxH: Math.max(140, Math.min(320, up ? r.top - 12 : below)), up };
	}
	$effect(() => {
		if (!open || !matches.length) return;
		place();
		window.addEventListener('scroll', place, true);
		window.addEventListener('resize', place);
		window.visualViewport?.addEventListener('resize', place);
		return () => {
			window.removeEventListener('scroll', place, true);
			window.removeEventListener('resize', place);
			window.visualViewport?.removeEventListener('resize', place);
		};
	});
	function pick(id: string) { value = id; q = ''; open = false; onpick?.(id); }
</script>

<div class="picker" role="group" onfocusin={() => (open = true)} onfocusout={(e) => { if (!(e.currentTarget as HTMLElement).contains(e.relatedTarget as Node)) open = false; }}>
	{#if picked && nameOnly}
		<button type="button" class="picked name-only" title="눌러서 다른 사람으로 바꾸기" onclick={() => { value = ''; }}><b>{picked.name}</b>{#if picked.name_note}<span class="muted"> ({picked.name_note})</span>{/if}</button>
	{:else if picked}
		<div class="picked"><b>{picked.name}</b>{#if picked.name_note}<span class="muted"> ({picked.name_note})</span>{/if}{#if !compact}<span class="muted"> · {registry.summary(picked).smallGroup || registry.summary(picked).category}</span>{/if}
			<button type="button" class="ghost sm" onclick={() => { value = ''; }}>바꾸기</button></div>
	{:else}
		<input bind:this={input} bind:value={q} {placeholder} autocomplete="off" />
		{#if open && matches.length}
			<div class="pop" class:up={pos.up} style="top:{pos.top}px;left:{pos.left}px;width:{pos.width}px;max-height:{pos.maxH}px">
				{#each matches as p (p.id)}
					<button type="button" class="opt" onclick={() => pick(p.id)}><b>{p.name}</b>{p.name_note ? ` (${p.name_note})` : ''}<small>{p.birth_date?.slice(0, 4) ?? ''}년생 · {p.phone ?? '전화 없음'} · {registry.summary(p).smallGroup || registry.summary(p).category}</small></button>
				{/each}
			</div>
		{/if}
	{/if}
</div>

<style>
	.picker { position: relative; }
	.picked { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding: 0.35rem 0.6rem; border: 1px solid var(--line-strong); border-radius: 10px; background: #fff; font-weight: 400; color: var(--text); }
	.name-only { width: 100%; justify-content: flex-start; box-shadow: none; font: inherit; cursor: pointer; }
	.name-only:hover { border-color: var(--primary); background: var(--primary-50); transform: none; box-shadow: none; filter: none; color: var(--text); }
	.pop { position: fixed; z-index: 80; max-height: 50vh; overflow: auto; background: #fff; border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 12px 30px rgba(30, 37, 69, 0.18); padding: 0.3rem; }
	.pop.up { transform: translateY(-100%); }
	.opt { display: block; width: 100%; text-align: left; font-weight: 500; color: var(--text); background: transparent; box-shadow: none; border-radius: 9px; padding: 0.45rem 0.6rem; }
	.opt:hover { background: var(--primary-50); transform: none; box-shadow: none; filter: none; color: var(--text); }
	.opt small { display: block; color: var(--muted); font-weight: 400; font-size: 0.76rem; }
</style>
