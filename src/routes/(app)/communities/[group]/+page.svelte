<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 소그룹 상세: 출석 추이, 인도자 특이사항(심방 권한자만), 구성원 명단(이름 · 직분 · 생년월일 · 나이 · 휴대폰)과 8주 출석
	import { page } from '$app/state';
	import { registry } from '#lib/registry/store.svelte.ts';
	import { recentSundays } from '#lib/registry/weeks.ts';
	import { ageOf, officeKindTitle } from '#lib/registry/derive.ts';

	const WEEKS = recentSundays(8);
	// 올해(1월 1일 ~ 지난 주일) 소그룹 모임 출석률. 올해 등록한 사람은 등록일 이후 주일만 셈 (소그룹별 명단과 같은 계산)
	const YEAR = new Date().getFullYear();
	const YEAR_WEEKS = recentSundays(60).filter((d) => d.startsWith(`${YEAR}-`));
	const rateOf = (id: string, registered: string | null) => {
		const ws = recordedWeeks.map((d, i) => ({ d, back: recordedWeeks.length - 1 - i })).filter((w) => !registered || w.d >= registered);
		if (!ws.length) return 0;
		return Math.round((ws.filter((w) => registry.groupAttended(id, w.d, w.back)).length * 100) / ws.length);
	};
	const g = $derived(registry.groupDef(decodeURIComponent(page.params.group!)));
	// 정렬: 인도자는 늘 맨 위, 그 아래는 고른 순서 (기본: 출석률 높은 순 / 가나다 순 / 나이 많은 순)
	const SORTS = ['출석률 순', '가나다 순', '나이 순'] as const;
	let sortBy = $state<(typeof SORTS)[number]>('출석률 순');
	// 이 소그룹이 출석을 기록한 주일만 센다 (기록이 없는 주일은 뺌)
	const recordedWeeks = $derived(g ? YEAR_WEEKS.filter((d) => registry.groupRecorded(d, registry.membersOfGroup(g.name).map((p) => p.id))) : []);
	type M = { p: { name: string; birth_date: string | null }; rate: number };
	const byName = (a: M, b: M) => a.p.name.localeCompare(b.p.name, 'ko');
	const members = $derived.by(() => {
		if (!g) return [];
		const list = registry.membersOfGroup(g.name)
			.map((p) => ({ p, s: registry.summary(p) }))
			.filter((x) => x.s.memberCert)
			.map((x) => ({ ...x, rate: rateOf(x.p.id, x.s.registered ?? null) }));
		// 나이 순: 생년월일이 빠른(나이 많은) 사람부터, 생년월일 없는 사람은 맨 아래
		const cmp = sortBy === '출석률 순' ? (a: M, b: M) => b.rate - a.rate || byName(a, b)
			: sortBy === '나이 순' ? (a: M, b: M) => (a.p.birth_date ?? '9999').localeCompare(b.p.birth_date ?? '9999') || byName(a, b)
			: byName;
		return list.sort((a, b) => Number(b.p.id === g.leader_id) - Number(a.p.id === g.leader_id) || cmp(a, b));
	});
	const weekly = $derived(WEEKS.map((d, i) => ({ d, n: members.filter(({ p }) => registry.groupAttended(p.id, d, WEEKS.length - 1 - i)).length })));
	const maxN = $derived(Math.max(1, ...weekly.map((w) => w.n)));
	// 인도자가 출석 체크 화면에서 교인별로 적은 특이사항 (최근 8주, 최신 주일부터)
	const reasons = $derived(
		WEEKS.flatMap((d) => members.map(({ p }) => ({ d, p, text: registry.d.groupAttendance?.[d]?.[p.id]?.reason ?? '' })).filter((x) => x.text)).sort((a, b) => b.d.localeCompare(a.d))
	);
	const notes = $derived(g ? registry.d.groupNotes.filter((n) => n.small_group === g.name) : []);
	const nameOf = (id: string | null) => (id ? registry.person(id)?.name ?? '' : '');
	let noteFor = $state('');
	let noteBody = $state('');
	const md = (iso: string) => `${+iso.slice(5, 7)}/${+iso.slice(8, 10)}`;
</script>

{#if g}
	<div class="row" style="justify-content:space-between">
		<h1>{g.name} <span class="unit">{registry.t2}</span> <small class="muted">&nbsp;[ {g.community} ]</small></h1>
		<div class="row"><a class="btn ghost" href={hrefTo('/communities')}>← 편성표</a></div>
	</div>

	<div class="kpis">
		<div class="kpi"><div><b>{members.length}</b><span>재적 인원</span></div></div>
		<div class="kpi"><div><b>{g.leader_id ? nameOf(g.leader_id) : '-'}</b><span>인도자{g.leader_id ? '' : ' (미정)'}</span></div></div>
		<div class="kpi"><div><b style="font-size:1.2rem">{g.place ?? '-'}</b><span>모임 장소</span></div></div>
		<div class="kpi"><div><b>{weekly[7].n}<small class="muted" style="font-size:.9rem"> / {members.length}</small></b><span>이번 주일 {registry.t2} 모임 출석</span></div></div>
	</div>

	<div class="dash-grid even" style={registry.perms.visit ? undefined : 'grid-template-columns:1fr'}>
		<section class="card">
			<div class="card-head"><h2>{registry.t2} 모임 출석 추이</h2><span class="sub">최근 8주 · 인도자 등록 기준</span></div>
			<div class="spark">
				{#each weekly as w}
					<div class="col"><span>{w.n}</span><i style="height:{(w.n * 100) / maxN}%"></i><small>{md(w.d)}</small></div>
				{/each}
			</div>
		</section>
		{#if registry.perms.visit}
		<section class="card">
			<div class="card-head"><h2>인도자 특이사항</h2><span class="sub">(인도자가 출석 체크 화면에서 적는 교인별 메모와 기타 의견)</span></div>
			{#each notes.slice(0, 8) as n (n.id)}
				<div class="li">
					{#if n.person_id}
						<span class="avatar warm">{nameOf(n.person_id).slice(0, 1)}</span>
						<div class="grow"><b><a href={hrefTo(`/${n.person_id}`)}>{nameOf(n.person_id)}</a></b><small>{n.date} · {n.author} — {n.body}</small></div>
					{:else}
						<span class="avatar">모</span>
						<div class="grow"><b><span class="tag part" style="margin:0">기타 의견</span></b><small>{n.date} · {n.author} — {n.body}</small></div>
					{/if}
				</div>
			{:else}{#if !reasons.length}<p class="empty">적힌 특이사항이 없습니다.</p>{/if}{/each}
			{#each reasons as r (r.d + r.p.id)}
				<div class="li">
					<span class="avatar warm">{r.p.name.slice(0, 1)}</span>
					<div class="grow"><b><a href={hrefTo(`/${r.p.id}`)}>{r.p.name}</a></b><small>{r.d} 출석 체크 · 인도자 — {r.text}</small></div>
				</div>
			{/each}
			{#if registry.perms.edit}
				<form class="row" style="margin-top:.8rem;flex-wrap:nowrap" onsubmit={(e) => { e.preventDefault(); if (noteFor && noteBody.trim()) { registry.addGroupNote({ person_id: noteFor, small_group: g!.name, date: new Date().toISOString().slice(0, 10), body: noteBody.trim(), author: nameOf(g!.leader_id) || '인도자' }); noteBody = ''; } }}>
					<select bind:value={noteFor} style="width:9rem"><option value="">교인 선택</option>{#each members as { p }}<option value={p.id}>{p.name}</option>{/each}</select>
					<input bind:value={noteBody} placeholder="특이사항 입력" /><button type="submit">추가</button>
				</form>
			{/if}
		</section>
		{/if}
	</div>

	<div class="sort-row">
		<span class="muted">정렬</span>
		{#each SORTS as s}<button type="button" class="ghost sm" class:on={sortBy === s} onclick={() => (sortBy = s)}>{s}</button>{/each}
		<span class="muted" style="font-size:.8rem">인도자는 늘 맨 위 · 출석률은 올해 {registry.t2} 모임 중 출석을 기록한 주일 기준 · 나이 순은 나이 많은 순</span>
	</div>
	<div class="card" style="padding:0;overflow-x:auto">
		<table class="sm" style="table-layout:fixed;min-width:900px">
			<!-- 칸 너비 비율: 이름 14 · 직분 10 · 생년월일 10 · 나이 5 · 휴대폰 12 · 출석률 7 · 주일 8칸이 나머지를 똑같이 (휴대폰에서는 옆으로 밀어서 봄) -->
			<colgroup><col style="width:14%" /><col style="width:10%" /><col style="width:10%" /><col style="width:5%" /><col style="width:12%" /><col style="width:7%" />{#each WEEKS as _}<col style="width:{42 / WEEKS.length}%" />{/each}</colgroup>
			<thead><tr><th>이름</th><th>직분</th><th>생년월일</th><th style="text-align:center">나이</th><th>휴대폰</th><th style="text-align:center">출석률</th>{#each WEEKS as d}<th style="text-align:center">{md(d)}</th>{/each}</tr></thead>
			<tbody>
				{#each members as { p, s, rate } (p.id)}
					<tr>
						<td class="nw"><a href={hrefTo(`/${p.id}`)}>{p.name}</a>{#if p.id === g.leader_id}<span class="tag now">인도자</span>{/if}</td>
						<td class="nw">{s.office ? officeKindTitle(s.office, p.clergy_title) : '-'}</td>
						<td class="nw">{p.birth_date ?? '-'}{p.birth_lunar ? ' (음)' : ''}</td>
						<td style="text-align:center">{ageOf(p.birth_date) ?? '-'}</td>
						<td class="nw">{p.phone ?? '-'}</td>
						<td style="text-align:center"><b>{recordedWeeks.length ? `${rate}%` : '-'}</b></td>
						{#each WEEKS as d, i}
							{@const ok = registry.groupAttended(p.id, d, WEEKS.length - 1 - i)}
							<td style="text-align:center" title={registry.perms.visit ? (registry.d.groupAttendance?.[d]?.[p.id]?.reason ?? undefined) : undefined}><span class="pdot" class:on={ok}></span></td>
						{/each}
					</tr>
				{:else}<tr><td colspan="14" class="muted">소속 교인이 없습니다.</td></tr>{/each}
			</tbody>
		</table>
	</div>
{:else}
	<p>{registry.t2}{registry.josa(registry.t2, '을')} 찾을 수 없습니다. <a href={hrefTo('/communities')}>편성표로</a></p>
{/if}

<style>
	/* 제목의 '소그룹': 소그룹 이름(1.7rem)과 [ 공동체 ](0.95rem) 사이 크기 */
	h1 .unit { font-size: 1.3rem; font-weight: 700; }
	.sort-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; margin: 1rem 0 0.6rem; }
	.sort-row button.on { background: var(--primary); border-color: var(--primary); color: #fff; }
</style>
