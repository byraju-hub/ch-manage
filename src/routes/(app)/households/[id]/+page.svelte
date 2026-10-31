<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 세대 상세: 세대원 추가 · 관계 바꾸기 · 분가
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { registry } from '#lib/registry/store.svelte.ts';
	import { RELATIONS } from '#lib/registry/types.ts';

	const h = $derived(registry.d.households.find((x) => x.id === page.params.id));
	const members = $derived(h ? registry.membersOfHousehold(h.id) : []);
	const can = $derived(registry.perms.edit);

	let addPerson = $state('');
	let addRel = $state('자녀');
	let nmName = $state('');
	let nmYear = $state('');
	let nmRel = $state('부모');
	let checked = $state<string[]>([]);

	const candidates = $derived(registry.d.people.filter((p) => !members.some((m) => m.person_id === p.id)));
	const nameOf = (m: { person_id: string | null; name: string }) => (m.person_id ? registry.person(m.person_id)?.name : m.name);

	function split() {
		const nh = registry.split(checked);
		checked = [];
		goto(hrefTo(`/households/${nh.id}`));
	}
</script>

{#if h}
	<div class="row" style="justify-content:space-between">
		<h1>세대 {h.no}</h1><a class="btn ghost" href={hrefTo('/households')}>← 세대 목록</a>
	</div>
	<div class="card">
		<p class="muted">{h.zip ?? ''} {h.address ?? '주소 없음'} {h.home_phone ? `· 집전화 ${h.home_phone}` : ''}</p>
		<p class="muted">세대마다 '본인'(세대주)은 한 명이고, 다른 세대원의 관계는 세대주 기준입니다. 한 사람은 한 세대에만 속합니다.</p>
		<table class="sm">
			<thead><tr><th></th><th>이름</th><th>관계</th><th>구분</th></tr></thead>
			<tbody>
				{#each members as m (m.id)}
					<tr>
						<td>{#if can}<input type="checkbox" style="width:auto" value={m.id} bind:group={checked} />{/if}</td>
						<td>{#if m.person_id}<a href={hrefTo(`/${m.person_id}`)}>{nameOf(m)}</a>{:else}{m.name}{m.birth_year ? ` (${m.birth_year}년생)` : ''}{/if}</td>
						<td>
							{#if can}
								<select value={m.relation} onchange={(e) => registry.setRelation(m.id, e.currentTarget.value)} style="width:auto">{#each RELATIONS as r}<option>{r}</option>{/each}</select>
							{:else}{m.relation}{/if}
						</td>
						<td>{m.person_id ? '교인' : '교인 아님'} {#if can}<button class="ghost" onclick={() => registry.removeFromHousehold(m.id)}>빼기</button>{/if}</td>
					</tr>
				{/each}
			</tbody>
		</table>
		{#if can}
			<p><button class="ghost" disabled={!checked.length} onclick={split}>선택한 {checked.length}명 분가 (새 세대로 옮김)</button></p>
		{/if}
	</div>
	{#if can}
		<div class="card">
			<h2>교인을 세대원으로 추가·이동</h2>
			<form class="row" onsubmit={(e) => { e.preventDefault(); if (addPerson) { registry.assignToHousehold(addPerson, h.id, addRel); addPerson = ''; } }}>
				<select bind:value={addPerson} style="width:auto"><option value="">교인 선택</option>{#each candidates as p}<option value={p.id}>{p.name}{p.name_note ? ` (${p.name_note})` : ''}{registry.memberOf(p.id) ? ' — 다른 세대에서 이동' : ''}</option>{/each}</select>
				<select bind:value={addRel} style="width:auto">{#each RELATIONS as r}<option>{r}</option>{/each}</select>
				<button type="submit" disabled={!addPerson}>추가</button>
			</form>
			<h2 style="margin-top:1rem">교인이 아닌 가족 추가 <span class="muted" style="font-weight:400">(이름·출생연도만)</span></h2>
			<form class="row" onsubmit={(e) => { e.preventDefault(); registry.addNonMember(h.id, nmName, nmYear ? +nmYear : null, nmRel); nmName = ''; nmYear = ''; }}>
				<input style="width:9rem" placeholder="이름" bind:value={nmName} required />
				<input style="width:7rem" type="number" placeholder="출생연도" bind:value={nmYear} />
				<select bind:value={nmRel} style="width:auto">{#each RELATIONS as r}<option>{r}</option>{/each}</select>
				<button type="submit">추가</button>
			</form>
		</div>
	{:else}
		<p class="muted">세대 수정은 '수정' 권한이 필요합니다.</p>
	{/if}
{:else}
	<p>세대를 찾을 수 없습니다. <a href={hrefTo('/households')}>목록으로</a></p>
{/if}
