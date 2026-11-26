<script lang="ts">
	// 관리자 › 프로그램 설정 › 코드표 중 바꿀 수 있는 목록: 전도회(선교회) · 심방 구분
	// (직분 · 교역자 직책은 교단 헌법과 권한 규칙에 묶여 있어 고정)
	import { registry } from '#lib/registry/store.svelte.ts';
	import { CLERGY_TITLES, FULL_TIME_CLERGY } from '#lib/registry/types.ts';
	import { guessGender, type Codes as C } from '#lib/registry/site-settings.ts';

	let codes = $state<C>(structuredClone($state.snapshot(registry.codes)) as C);
	// 전도회(선교회)는 이름을 고칠 수 있어 따로 들고 있음. orig = 저장돼 있는 이름 (새로 넣은 것은 빈 값)
	let seq = 0;
	const toRows = () => registry.codes.missionSocieties.map((name) => ({ key: ++seq, orig: name, name, gender: registry.societyGender(name) }));
	let rows = $state(toRows());
	let addName = $state('');
	let addGender = $state<'M' | 'F' | ''>('');
	let addKind = $state('');
	let msg = $state('');
	let err = $state('');
	const can = $derived(registry.canSetup);
	// 지금 쓰고 있는 사람 · 기록 수 (지워도 이미 적힌 값은 그대로 남음)
	const members = (name: string) => (name ? registry.d.people.filter((p) => p.mission_society === name).length : 0);
	const visits = (v: string) => registry.d.visits.filter((x) => x.kind === v).length;
	function pushSociety() {
		const name = addName.trim();
		if (!name || rows.some((r) => r.name.trim() === name)) return;
		rows.push({ key: ++seq, orig: '', name, gender: addGender || guessGender(name) });
		addName = ''; addGender = '';
	}
	function pushKind() {
		const v = addKind.trim();
		if (!v || codes.visitKinds.includes(v)) return;
		codes.visitKinds = [...codes.visitKinds, v]; addKind = '';
	}
	function swapped<T>(list: T[], i: number, d: -1 | 1): T[] {
		const a = [...list]; const j = i + d;
		if (j < 0 || j >= a.length) return list;
		[a[i], a[j]] = [a[j], a[i]]; return a;
	}
	function save() {
		msg = '';
		const renamed = rows.filter((r) => r.orig && r.orig !== r.name.trim());
		const n = renamed.reduce((s, r) => s + members(r.orig), 0);
		if (renamed.length && !confirm(`${renamed.map((r) => `${r.orig} → ${r.name.trim()}`).join('\n')}\n\n이름을 바꾸면 교인 ${n}명의 카드와 연간 설정의 이름도 함께 바뀝니다. 계속할까요?`)) return;
		err = registry.saveSocieties($state.snapshot(rows), { visitKinds: $state.snapshot(codes.visitKinds) });
		if (err) return;
		rows = toRows();
		msg = '코드표를 저장했습니다.';
	}
</script>

<section class="card">
	<h2>교역자 직책 <span class="tag">고정</span></h2>
	<p>{CLERGY_TITLES.join(' · ')}</p>
	<p class="muted">심방 기록을 볼 수 있는 전임 교역자: <b style="color:var(--text)">{FULL_TIME_CLERGY.join(' · ')}</b></p>
	<h2 style="margin-top:1.2rem">{registry.tm} <span class="tag part">바꿀 수 있음</span></h2>
	<p class="muted" style="font-size:.8rem;margin:0 0 .5rem">이름 칸을 고친 뒤 아래 '코드표 저장'을 누르면, 그 모임에 속한 교인 카드의 이름도 함께 바뀝니다.</p>
	<div class="tw"><table class="sm soc">
		<thead><tr><th>이름</th><th>구분</th><th>인원</th><th>순서 · 빼기</th></tr></thead>
		<tbody>
			{#each rows as r, i (r.key)}
				<tr>
					<td><input bind:value={r.name} maxlength="40" disabled={!can} aria-label="{r.orig || r.name} 이름" /></td>
					<td><select bind:value={r.gender} disabled={!can} aria-label="{r.name} 구분"><option value="">선택</option><option value="M">남</option><option value="F">여</option></select></td>
					<td class="nw">{members(r.orig)}명</td>
					<td class="nw">
						<button type="button" class="ghost xs" onclick={() => (rows = swapped(rows, i, -1))} aria-label="{r.name} 위로" disabled={!can || i === 0}>↑</button>
						<button type="button" class="ghost xs" onclick={() => (rows = swapped(rows, i, 1))} aria-label="{r.name} 아래로" disabled={!can || i === rows.length - 1}>↓</button>
						<button type="button" class="ghost xs" onclick={() => (rows = rows.filter((x) => x.key !== r.key))} aria-label="{r.name} 빼기" disabled={!can}>빼기</button>
					</td>
				</tr>
			{/each}
		</tbody>
	</table></div>
	<form class="row" style="margin-top:.5rem;flex-wrap:nowrap" onsubmit={(e) => { e.preventDefault(); pushSociety(); }}>
		<input bind:value={addName} maxlength="40" placeholder="예: 7남{registry.tm}" disabled={!can} />
		<select bind:value={addGender} style="width:auto" aria-label="새 모임 구분" disabled={!can}><option value="">구분 자동</option><option value="M">남</option><option value="F">여</option></select>
		<button type="submit" class="ghost sm" disabled={!can}>추가</button>
	</form>

	<h2 style="margin-top:1.2rem">심방 구분 <span class="tag part">바꿀 수 있음</span></h2>
	<div class="code-list">
		{#each codes.visitKinds as v, i (v)}
			<span class="code">
				{v}{#if visits(v)}<small>{visits(v)}건</small>{/if}
				<button type="button" class="x" onclick={() => (codes.visitKinds = swapped(codes.visitKinds, i, -1))} aria-label="{v} 앞으로" disabled={!can}>‹</button>
				<button type="button" class="x" onclick={() => (codes.visitKinds = swapped(codes.visitKinds, i, 1))} aria-label="{v} 뒤로" disabled={!can}>›</button>
				<button type="button" class="x" onclick={() => (codes.visitKinds = codes.visitKinds.filter((x) => x !== v))} aria-label="{v} 빼기" disabled={!can}>×</button>
			</span>
		{/each}
	</div>
	<form class="row" style="margin-top:.5rem;flex-wrap:nowrap" onsubmit={(e) => { e.preventDefault(); pushKind(); }}>
		<input bind:value={addKind} placeholder="예: 위로" disabled={!can} /><button type="submit" class="ghost sm" disabled={!can}>추가</button>
	</form>
	<p class="muted" style="font-size:.8rem;margin:.7rem 0 0">목록에서 빼도 이미 그 값이 적힌 교인·심방 기록은 그대로 남습니다. {registry.tm}를 새로 넣으면 '연간 설정'에서 출생 연도를 정해 주세요.</p>
	{#if err}<p class="warn" style="margin:.6rem 0 0">{err}</p>{/if}
	{#if msg}<p class="ok" style="margin:.6rem 0 0">{msg}</p>{/if}
	<div class="row" style="justify-content:flex-end;margin-top:.7rem"><button type="button" onclick={save} disabled={!can}>코드표 저장</button></div>
</section>

<style>
	.soc { max-width: 34rem; }
	.soc input { min-width: 9rem; }
	.soc select { width: auto; }
	.code-list { display: flex; flex-wrap: wrap; gap: 0.35rem; }
	.code { display: inline-flex; align-items: center; gap: 0.15rem; padding: 0.2rem 0.3rem 0.2rem 0.65rem; border-radius: 999px; background: var(--primary-50); border: 1px solid var(--primary-100); font-size: 0.85rem; font-weight: 600; }
	.code small { margin: 0 0.2rem 0 0.3rem; color: var(--muted); font-weight: 500; }
	.x { padding: 0 0.3rem; min-width: 1.3rem; height: 1.3rem; border-radius: 999px; background: transparent; color: var(--muted); border: 0; box-shadow: none; font-size: 0.9rem; }
	.x:hover:not(:disabled) { background: #fff; color: var(--primary-600); transform: none; box-shadow: none; filter: none; }
</style>
