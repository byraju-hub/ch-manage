<script lang="ts">
	// 관리자 › 프로그램 설정 › 코드표 중 바꿀 수 있는 목록: 전도회 · 심방 구분
	// (직분 · 교역자 직책은 교단 헌법과 권한 규칙에 묶여 있어 고정)
	import { registry } from '#lib/registry/store.svelte.ts';
	import { CLERGY_TITLES, FULL_TIME_CLERGY } from '#lib/registry/types.ts';
	import type { Codes as C } from '#lib/registry/site-settings.ts';

	let codes = $state<C>(structuredClone($state.snapshot(registry.codes)) as C);
	let add = $state<Record<keyof C, string>>({ visitKinds: '', missionSocieties: '' });
	let msg = $state('');
	const can = $derived(registry.canSetup);
	// 지금 쓰고 있는 사람 · 기록 수 (지워도 이미 적힌 값은 그대로 남음)
	const used = (k: keyof C, v: string) => (k === 'missionSocieties' ? registry.d.people.filter((p) => p.mission_society === v).length : registry.d.visits.filter((x) => x.kind === v).length);
	function push(k: keyof C) {
		const v = add[k].trim();
		if (!v || codes[k].includes(v)) return;
		codes[k] = [...codes[k], v]; add[k] = '';
	}
	const remove = (k: keyof C, v: string) => (codes[k] = codes[k].filter((x) => x !== v));
	function move(k: keyof C, i: number, d: -1 | 1) {
		const a = [...codes[k]]; const j = i + d;
		if (j < 0 || j >= a.length) return;
		[a[i], a[j]] = [a[j], a[i]]; codes[k] = a;
	}
	function save() { registry.saveSite({ codes }, '코드표 저장 (전도회 · 심방 구분)'); msg = '코드표를 저장했습니다.'; }
	const LISTS: { k: keyof C; title: string; unit: string; ph: string }[] = [
		{ k: 'missionSocieties', title: '전도회', unit: '명', ph: '예: 7남전도회' },
		{ k: 'visitKinds', title: '심방 구분', unit: '건', ph: '예: 위로' }
	];
</script>

<section class="card">
	<h2>교역자 직책 <span class="tag">고정</span></h2>
	<p>{CLERGY_TITLES.join(' · ')}</p>
	<p class="muted">심방 기록을 볼 수 있는 전임 교역자: <b style="color:var(--text)">{FULL_TIME_CLERGY.join(' · ')}</b></p>
	{#each LISTS as L}
		<h2 style="margin-top:1.2rem">{L.title} <span class="tag part">바꿀 수 있음</span></h2>
		<div class="code-list">
			{#each codes[L.k] as v, i (v)}
				<span class="code">
					{v}{#if used(L.k, v)}<small>{used(L.k, v)}{L.unit}</small>{/if}
					<button type="button" class="x" onclick={() => move(L.k, i, -1)} aria-label="{v} 앞으로" disabled={!can}>‹</button>
					<button type="button" class="x" onclick={() => move(L.k, i, 1)} aria-label="{v} 뒤로" disabled={!can}>›</button>
					<button type="button" class="x" onclick={() => remove(L.k, v)} aria-label="{v} 빼기" disabled={!can}>×</button>
				</span>
			{/each}
		</div>
		<form class="row" style="margin-top:.5rem;flex-wrap:nowrap" onsubmit={(e) => { e.preventDefault(); push(L.k); }}>
			<input bind:value={add[L.k]} placeholder={L.ph} disabled={!can} /><button type="submit" class="ghost sm" disabled={!can}>추가</button>
		</form>
	{/each}
	<p class="muted" style="font-size:.8rem;margin:.7rem 0 0">목록에서 빼도 이미 그 값이 적힌 교인·심방 기록은 그대로 남습니다. 전도회를 새로 넣으면 '연간 설정'에서 출생 연도를 정해 주세요.</p>
	{#if msg}<p class="ok" style="margin:.6rem 0 0">{msg}</p>{/if}
	<div class="row" style="justify-content:flex-end;margin-top:.7rem"><button type="button" onclick={save} disabled={!can}>코드표 저장</button></div>
</section>

<style>
	.code-list { display: flex; flex-wrap: wrap; gap: 0.35rem; }
	.code { display: inline-flex; align-items: center; gap: 0.15rem; padding: 0.2rem 0.3rem 0.2rem 0.65rem; border-radius: 999px; background: var(--primary-50); border: 1px solid var(--primary-100); font-size: 0.85rem; font-weight: 600; }
	.code small { margin: 0 0.2rem 0 0.3rem; color: var(--muted); font-weight: 500; }
	.x { padding: 0 0.3rem; min-width: 1.3rem; height: 1.3rem; border-radius: 999px; background: transparent; color: var(--muted); border: 0; box-shadow: none; font-size: 0.9rem; }
	.x:hover:not(:disabled) { background: #fff; color: var(--primary-600); transform: none; box-shadow: none; filter: none; }
</style>
