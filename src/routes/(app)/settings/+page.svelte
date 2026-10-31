<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 관리자 › 교회 설정(기본 설정 · 연간 설정 · 교회 조직표 · 소그룹 편성)
	//        › 프로그램 설정(교인구분 · 코드표 · 확인 목록 · 백업)
	// 같은 화면에서 주소의 ?tab= 으로 탭을 고르고, 탭이 속한 묶음의 탭만 보여 줌
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import CandidatesPanel from '#lib/components/CandidatesPanel.svelte';
	import YearSettingsPanel from '#lib/components/YearSettingsPanel.svelte';
	import BasicSettingsPanel from '#lib/components/BasicSettingsPanel.svelte';
	import BackupPanel from '#lib/components/BackupPanel.svelte';
	import DmLinkPanel from '#lib/components/DmLinkPanel.svelte';
	import { dm } from '#lib/registry/dm.svelte.ts';
	import CodesPanel from '#lib/components/CodesPanel.svelte';
	import { registry } from '#lib/registry/store.svelte.ts';
	import PersonPicker from '#lib/components/PersonPicker.svelte';
	import NameChips from '#lib/components/NameChips.svelte';
	import { MAX_CATEGORY_DEPTH, OFFICE_STATUS_OPTIONS, rankChoices, rankLabel, type OfficeName } from '#lib/registry/types.ts';

	const GROUPS = [
		{ title: '교회 설정', tabs: ['기본 설정', '연간 설정', '교회 조직표', '소그룹 편성'] },
		{ title: '프로그램 설정', tabs: ['교인구분', '코드표', '확인 목록', '백업', '부서 관리 연결'] }
	];
	const tab = $derived(GROUPS.some((g) => g.tabs.includes(page.url.searchParams.get('tab') ?? '')) ? page.url.searchParams.get('tab')! : '기본 설정');
	const group = $derived(GROUPS.find((g) => g.tabs.includes(tab))!);
	// '부서 관리 연결'은 부서 관리(dep-manage)가 같은 DB 에 있을 때(또는 구조가 달라 확인이 필요할 때) 최고관리자에게만 보인다.
	const shownTabs = $derived(group.tabs.filter((t) => t !== '부서 관리 연결' || t === tab || (registry.isSuper && (dm.available || dm.reason === 'incompatible'))));
	function setTab(t: string) {
		msg = '';
		goto(hrefTo(`/settings?tab=${encodeURIComponent(t)}`), { replaceState: true, reset: false });
	}
	let newComm = $state('');
	let msg = $state('');
	let addFor = $state<string | null>(null);
	let addName = $state('');
	let orgName = $state('');
	let deptFor = $state('');
	let deptName = $state('');

	const roots = $derived(registry.childrenOf(null));
	function add(parent: string | null) {
		if (!registry.addCategory(parent, addName)) msg = `${MAX_CATEGORY_DEPTH}단계까지만 만들 수 있습니다.`;
		else msg = '';
		addName = ''; addFor = null;
	}
	// 끌어서 순서 바꾸기: 같은 상위 구분 안에서만 옮김
	let drag = $state<{ id: string; parent: string | null } | null>(null);
	let drop = $state<{ id: string; before: boolean } | null>(null);
	function over(e: DragEvent, id: string, parent: string | null) {
		if (!drag || drag.parent !== parent || drag.id === id) return;
		e.preventDefault();
		const r = (e.currentTarget as HTMLElement).getBoundingClientRect();
		drop = { id, before: e.clientY < r.top + r.height / 2 };
	}
	function dropOn(e: DragEvent, idx: number) {
		e.preventDefault();
		if (drag && drop) {
			const from = registry.childrenOf(drag.parent).findIndex((c) => c.id === drag!.id);
			let to = drop.before ? idx : idx + 1;
			if (from < to) to -= 1;
			registry.moveCategory(drag.id, to);
		}
		drag = null; drop = null;
	}
	// 소그룹 편성: 추가 · 수정(이름·공동체·인도자·장소) · 삭제
	let sgAdd = $state<{ community: string; name: string; leader: string; place: string } | null>(null);
	let sgEdit = $state<{ id: string; community: string; name: string; leader: string; place: string } | null>(null);
	let sgMsg = $state('');
	function del(id: string) {
		msg = registry.deleteCategory(id) ? '' : '이 구분에 속한 교인이 있어 지울 수 없습니다. 먼저 다른 구분으로 옮겨 주세요.';
	}
	const committees = $derived(registry.d.orgUnits.filter((o) => !o.parent_id).sort((a, b) => a.sort_order - b.sort_order));
</script>

<h1>{group.title} <span class="tag">사이트 관리자</span></h1>
{#if !registry.perms.admin}
	<div class="warn">사이트 관리자 권한이 필요합니다. (최고관리자에게 사이트 관리자 권한을 받아야 합니다)</div>
{:else}
	<div class="tabs">{#each shownTabs as t}<button class:on={tab === t} onclick={() => setTab(t)}>{registry.tr(t)}</button>{/each}</div>
	{#if msg}<div class="warn">{msg}</div>{/if}

	{#if tab === '교인구분'}
		<div class="card">
			<h2>교인구분 <span class="tag">{MAX_CATEGORY_DEPTH}단계까지</span></h2>
			<p class="muted">교인 카드의 '교인구분'에 쓰는 분류입니다. 이름을 바꾸면 그 구분의 모든 교인에게 바로 적용됩니다. 교인이 있는 구분은 지울 수 없습니다.</p>
			{#snippet node(id: string, name: string, level: number, parent: string | null, idx: number, total: number)}
				{@const usage = registry.categoryUsage(id).count}
				<div
					class="cat-row"
					class:drop-before={drop?.id === id && drop.before}
					class:drop-after={drop?.id === id && !drop.before}
					class:dragging={drag?.id === id}
					style="padding-left:{(level - 1) * 1.6}rem"
					role="listitem"
					ondragover={(e) => over(e, id, parent)}
					ondragleave={() => { if (drop?.id === id) drop = null; }}
					ondrop={(e) => dropOn(e, idx)}
				>
					<span class="grip" draggable="true" role="button" tabindex="-1" aria-label="끌어서 순서 바꾸기" title="끌어서 순서 바꾸기"
						ondragstart={(e) => { drag = { id, parent }; e.dataTransfer?.setData('text/plain', id); const row = (e.currentTarget as HTMLElement).parentElement; if (row) e.dataTransfer?.setDragImage(row, 20, 16); }}
						ondragend={() => { drag = null; drop = null; }}>
						<svg viewBox="0 0 10 16" aria-hidden="true"><circle cx="3" cy="3" r="1.4" /><circle cx="7" cy="3" r="1.4" /><circle cx="3" cy="8" r="1.4" /><circle cx="7" cy="8" r="1.4" /><circle cx="3" cy="13" r="1.4" /><circle cx="7" cy="13" r="1.4" /></svg>
					</span>
					<span class="lvl">{level}</span>
					<input value={name} onchange={(e) => registry.renameCategory(id, e.currentTarget.value)} aria-label="구분 이름" />
					<span class="muted" style="font-size:.8rem;white-space:nowrap">{usage}명</span>
					{#if level < MAX_CATEGORY_DEPTH}<button type="button" class="ghost sm" onclick={() => { addFor = addFor === id ? null : id; addName = ''; }}>+ 하위</button>{/if}
					<button type="button" class="ghost sm" onclick={() => del(id)} disabled={usage > 0}>삭제</button>
					<span class="move">
						<button type="button" class="ghost sm" disabled={idx === 0} onclick={() => registry.moveCategory(id, idx - 1)} aria-label="{name} 위로">▲</button>
						<button type="button" class="ghost sm" disabled={idx === total - 1} onclick={() => registry.moveCategory(id, idx + 1)} aria-label="{name} 아래로">▼</button>
					</span>
				</div>
				{@const kids = registry.childrenOf(id)}
				{#each kids as c, i (c.id)}{@render node(c.id, c.name, level + 1, id, i, kids.length)}{/each}
				<!-- 새 하위 구분 입력 칸은 하위 목록의 맨 아래에 (추가하면 맨 아래에 붙음) -->
				{#if addFor === id}
					<form class="cat-row" style="padding-left:{level * 1.6 + 1.6}rem" onsubmit={(e) => { e.preventDefault(); add(id); }}>
						<span class="lvl">{level + 1}</span><input bind:value={addName} placeholder="새 구분 이름" /><button type="submit" class="sm">추가</button>
					</form>
				{/if}
			{/snippet}
			<p class="muted" style="font-size:.82rem;margin:.2rem 0 .6rem">왼쪽 <b>⠿</b> 손잡이를 끌거나 ▲▼ 를 눌러 같은 단계 안에서 순서를 바꿀 수 있습니다. 바꾼 순서는 교인 카드·목록의 선택 목록에 그대로 쓰입니다.</p>
			<div role="list">
				{#each roots as c, i (c.id)}{@render node(c.id, c.name, 1, null, i, roots.length)}{/each}
			</div>
			<form class="cat-row" style="margin-top:.8rem" onsubmit={(e) => { e.preventDefault(); add(null); }}>
				<span class="lvl">1</span><input bind:value={addName} placeholder="새 1단계 구분 이름" /><button type="submit" class="sm">1단계 추가</button>
			</form>
		</div>
	{:else if tab === '소그룹 편성'}
		<div class="card" style="padding:0;overflow-x:auto">
			<div class="card-head" style="padding:1.1rem 1.3rem 0">
				<div><h2>{registry.t2} 편성 <span class="tag">출석부 자료</span></h2><span class="sub">{registry.t2}{registry.josa(registry.t2, '을')} 추가·수정·삭제합니다. 이름을 바꾸면 소속 교인과 인도자 특이사항에도 바로 적용됩니다.</span></div>
				{#if !sgAdd}<button type="button" class="sm" onclick={() => { sgAdd = { community: registry.communities[0].name, name: '', leader: '', place: '' }; sgEdit = null; sgMsg = ''; }}>+ {registry.t2}</button>{/if}
			</div>
			{#if sgMsg}<div class="warn" style="margin:.8rem 1.3rem 0">{sgMsg}</div>{/if}
			<!-- 공동체(1단계) 추가 · 이름 바꾸기 · 삭제: 소속 교인 · 소그룹 · 연간 설정 기준에 함께 반영 -->
			<div class="comm-names">
				<span class="muted">{registry.t1}</span>
				{#each registry.communities as c (c.key)}
					<form class="row" style="gap:.3rem;flex-wrap:nowrap" onsubmit={(e) => { e.preventDefault(); const inp = (e.currentTarget as HTMLFormElement).querySelector('input')!; sgMsg = registry.renameCommunity(c.name, inp.value); if (sgMsg) inp.value = c.name; }}>
						<input value={c.name} aria-label="{c.name} 이름" style="width:9.5rem" />
						<button type="submit" class="ghost sm">바꾸기</button>
						<button type="button" class="ghost sm" onclick={() => { if (confirm(`'${c.name}' ${registry.t1}${registry.josa(registry.t1, '을')} 삭제합니다.`)) sgMsg = registry.removeCommunity(c.name); }}>삭제</button>
					</form>
				{/each}
				<form class="row" style="gap:.3rem;flex-wrap:nowrap" onsubmit={(e) => { e.preventDefault(); sgMsg = registry.addCommunity(newComm); if (!sgMsg) newComm = ''; }}>
					<input bind:value={newComm} placeholder="새 {registry.t1} 이름" aria-label="새 {registry.t1} 이름" style="width:9.5rem" />
					<button type="submit" class="sm">+ {registry.t1}</button>
				</form>
			</div>
			<p class="muted" style="font-size:.8rem;margin:.4rem 1.3rem 0">{registry.t2}{registry.josa(registry.t2, '이')}나 소속 교인이 있는 {registry.t1}{registry.josa(registry.t1, '은')} 지울 수 없습니다. 새 {registry.t1}의 출생 연도 기준은 연간 설정에서 정하세요.</p>
			{#if sgAdd}
				<form class="sg-add" onsubmit={(e) => { e.preventDefault(); sgMsg = registry.addSmallGroup(sgAdd!.community, sgAdd!.name, sgAdd!.place, sgAdd!.leader || null) ?? ''; if (!sgMsg) sgAdd = null; }}>
					<label>{registry.t1}<select bind:value={sgAdd.community}>{#each registry.communities as c}<option>{c.name}</option>{/each}</select></label>
					<label>{registry.t2} 이름 *<input bind:value={sgAdd.name} required placeholder="예: 새소망" /></label>
					<label>인도자<PersonPicker bind:value={sgAdd.leader} placeholder="인도자 검색" /></label>
					<label>모임 장소<input bind:value={sgAdd.place} placeholder="예: 다니엘관 101호" /></label>
					<div class="row"><button type="submit" class="sm">추가</button><button type="button" class="ghost sm" onclick={() => { sgAdd = null; sgMsg = ''; }}>취소</button></div>
				</form>
			{/if}
			<table class="sm">
				<thead><tr><th>{registry.t1}</th><th>{registry.t2}</th><th style="min-width:220px">인도자</th><th>모임 장소</th><th>인원</th><th></th></tr></thead>
				<tbody>
					{#each registry.communities as c, ci}
						{#each registry.groupsIn(c.name) as g (g.id)}
							{#if sgEdit?.id === g.id}
								<tr class="editing">
									<td><select bind:value={sgEdit.community} aria-label="{registry.t1}">{#each registry.communities as x}<option>{x.name}</option>{/each}</select></td>
									<td><input bind:value={sgEdit.name} aria-label="{registry.t2} 이름" /></td>
									<td><PersonPicker bind:value={sgEdit.leader} placeholder="인도자 검색" /></td>
									<td><input bind:value={sgEdit.place} aria-label="모임 장소" /></td>
									<td style="white-space:nowrap">{registry.groupMemberCount(g.name)}명</td>
									<td class="row" style="flex-wrap:nowrap">
										<button type="button" class="sm" onclick={() => { sgMsg = registry.editSmallGroup(g.id, { name: sgEdit!.name, community: sgEdit!.community, place: sgEdit!.place, leader_id: sgEdit!.leader || null }) ?? ''; if (!sgMsg) sgEdit = null; }}>저장</button>
										<button type="button" class="ghost sm" onclick={() => { sgEdit = null; sgMsg = ''; }}>취소</button>
									</td>
								</tr>
							{:else}
								<!-- 공동체 구분: 가운데(줄기) 공동체는 옅은 회색 음영 -->
								<tr class:shade={ci % 2 === 1}>
									<td>{c.name}</td>
									<td><b>{g.name}</b>{#if g.care}<span class="tag part">자녀 돌봄</span>{/if}</td>
									<td>{#if g.leader_id}<a href={hrefTo(`/${g.leader_id}`)}>{registry.person(g.leader_id)?.name}</a>{:else}<span class="muted">-</span>{/if}</td>
									<td>{g.place ?? '-'}</td>
									<td style="white-space:nowrap">{registry.groupMemberCount(g.name)}명</td>
									<td class="row" style="flex-wrap:nowrap">
										<button type="button" class="ghost sm" onclick={() => { sgEdit = { id: g.id, community: g.community, name: g.name, leader: g.leader_id ?? '', place: g.place ?? '' }; sgAdd = null; sgMsg = ''; }}>수정</button>
										<button type="button" class="ghost sm" onclick={() => { if (confirm(`'${g.name}' ${registry.t2}을(를) 삭제합니다.`)) sgMsg = registry.deleteSmallGroup(g.id) ?? ''; }}>삭제</button>
									</td>
								</tr>
							{/if}
						{/each}
					{/each}
				</tbody>
			</table>
		</div>
	{:else if tab === '기본 설정'}
		<BasicSettingsPanel />
	{:else if tab === '확인 목록'}
		<CandidatesPanel />
	{:else if tab === '백업'}
		<BackupPanel />
	{:else if tab === '부서 관리 연결'}
		{#if registry.isSuper}<DmLinkPanel />{:else}<div class="warn">부서 관리 연결은 최고관리자만 할 수 있습니다.</div>{/if}
	{:else if tab === '연간 설정'}
		<YearSettingsPanel />
	{:else if tab === '교회 조직표'}
		<div class="card">
			<h2>회의 의장 · 서기</h2>
			<table class="sm"><thead><tr><th>회의</th><th>의장</th><th>서기</th></tr></thead><tbody>
				{#each registry.d.meetings as m (m.meeting)}
					<tr><td><b>{m.meeting}</b></td>
						<td><input value={m.chair} onchange={(e) => registry.setMeeting(m.meeting, 'chair', e.currentTarget.value)} placeholder="예: 정담임 목사" /></td>
						<td><input value={m.secretary} onchange={(e) => registry.setMeeting(m.meeting, 'secretary', e.currentTarget.value)} placeholder="예: 홍길동 장로" /></td></tr>
				{/each}
			</tbody></table>
		</div>
		<div class="card">
			<h2>교회 조직표 <span class="tag">위원회 / 부서</span></h2>
			<p class="muted">위원회마다 위원장(장로), 부서마다 부서장 · 교역자(각 한 명)와 담당자(여러 명)를 정합니다. 조직 › 교회 조직표에는 부서장과 교역자만 보입니다. ▲▼로 순서를 바꿉니다. 교인 카드의 '교회봉사'에서 이 부서 목록을 고릅니다. 봉사자가 있는 부서는 지울 수 없습니다.</p>
			{#each committees as c, ci (c.id)}
				<div class="org-edit">
					<div class="cat-row">
						<span class="lvl">{ci + 1}</span><input value={c.name} onchange={(e) => registry.renameOrgUnit(c.id, e.currentTarget.value)} style="max-width:12rem" />
						<span class="muted" style="font-size:.8rem">위원장</span>
						<div style="width:14rem"><PersonPicker compact value={c.leader_id ?? ''} placeholder="장로 검색" onpick={(id) => registry.setOrgLeader(c.id, id)} /></div>
						<button type="button" class="ghost sm" onclick={() => registry.moveOrgUnit(c.id, -1)} aria-label="위로">▲</button>
						<button type="button" class="ghost sm" onclick={() => registry.moveOrgUnit(c.id, 1)} aria-label="아래로">▼</button>
						<button type="button" class="ghost sm" onclick={() => { if (!registry.deleteOrgUnit(c.id)) msg = '봉사자가 있는 부서가 있어 지울 수 없습니다.'; else msg = ''; }}>삭제</button>
					</div>
					<table class="sm" style="margin-top:.4rem">
						<thead><tr><th>부서</th><th style="min-width:13rem">부서장</th><th style="min-width:13rem">교역자</th><th style="min-width:16rem">담당자 (이름을 쓰고 쉼표)</th><th>봉사</th><th></th></tr></thead>
						<tbody>
							{#each registry.d.orgUnits.filter((o) => o.parent_id === c.id).sort((a, b) => a.sort_order - b.sort_order) as d (d.id)}
								<tr>
									<td><input value={d.name} onchange={(e) => registry.renameOrgUnit(d.id, e.currentTarget.value)} style="min-width:9rem" /></td>
									<td><PersonPicker compact value={d.leader_id ?? ''} placeholder="부서장 검색" onpick={(id) => registry.setOrgLeader(d.id, id)} /></td>
									<td><div class="row" style="gap:.35rem;flex-wrap:nowrap"><div style="flex:1;min-width:0"><PersonPicker nameOnly only={(p) => !!p.clergy_title} value={d.clergy_id ?? ''} placeholder="교역자 검색" onpick={(id) => registry.setOrgClergy(d.id, id)} /></div>{#if d.clergy_id}<button type="button" class="ghost sm" onclick={() => registry.setOrgClergy(d.id, null)} title="이 부서의 교역자 비우기">삭제</button>{/if}</div></td>
									<td><NameChips chips={d.staff_ids.map((id) => ({ id, name: registry.person(id)?.name ?? '' }))} onchange={(cs) => registry.setOrgStaff(d.id, cs.filter((x) => x.id).map((x) => x.id!))} placeholder="담당자 이름," /></td>
									<td style="white-space:nowrap">{registry.d.affiliations.filter((a) => a.org === d.name && !a.end_date).length}명</td>
									<td style="white-space:nowrap">
										<button type="button" class="ghost sm" onclick={() => registry.moveOrgUnit(d.id, -1)} aria-label="위로">▲</button>
										<button type="button" class="ghost sm" onclick={() => registry.moveOrgUnit(d.id, 1)} aria-label="아래로">▼</button>
										<button type="button" class="ghost sm" onclick={() => { if (!registry.deleteOrgUnit(d.id)) msg = '봉사자가 있는 부서는 지울 수 없습니다.'; else msg = ''; }}>삭제</button>
									</td>
								</tr>
							{/each}
						</tbody>
					</table>
				</div>
			{/each}
			<form class="cat-row" style="margin-top:.8rem" onsubmit={(e) => { e.preventDefault(); registry.addOrgUnit(null, orgName); orgName = ''; }}>
				<span class="lvl">+</span><input bind:value={orgName} placeholder="새 위원회 이름" /><button type="submit" class="sm">위원회 추가</button>
			</form>
			<form class="cat-row" onsubmit={(e) => { e.preventDefault(); if (deptFor) registry.addOrgUnit(deptFor, deptName); deptName = ''; }}>
				<span class="lvl">+</span>
				<select bind:value={deptFor} style="max-width:12rem"><option value="">위원회 선택</option>{#each committees as c}<option value={c.id}>{c.name}</option>{/each}</select>
				<input bind:value={deptName} placeholder="새 부서 이름" /><button type="submit" class="sm" disabled={!deptFor}>부서 추가</button>
			</form>
		</div>
	{:else}
		<div class="dash-grid even">
			<section class="card">
				<h2>직분과 구분 <span class="tag">고정 코드표</span></h2>
				<table class="sm"><thead><tr><th>직분</th><th>고를 수 있는 구분</th></tr></thead><tbody>
					{#each Object.entries(OFFICE_STATUS_OPTIONS) as [o, st]}<tr><td><b>{o}</b></td><td>{rankChoices(o as OfficeName).map((r) => rankLabel(o as OfficeName, r)).join(' · ')}</td></tr>{/each}
				</tbody></table>
				<p class="muted" style="font-size:.82rem">임직일과 임직교회는 장로·시무집사·권사에게 적습니다. 표시되는 직분 이름은 구분에서 자동으로 만들어집니다 (예: 시무 → 권사, 은퇴 → 은퇴권사).</p>
			</section>
			<CodesPanel />
		</div>
	{/if}
{/if}

<style>
	.comm-names { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.9rem; margin: 0.8rem 1.3rem 0; padding: 0.7rem 0.9rem; border-radius: 12px; background: var(--primary-50); }
	.org-edit { padding: 0.8rem 0; border-bottom: 1px dashed var(--line-strong); overflow-x: auto; }
</style>
