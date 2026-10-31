<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 통계: 주일 출석(장년·교육부서) · 소그룹 출석 · 임직 · 세례
	import { page } from '$app/state';
	import { registry } from '#lib/registry/store.svelte.ts';
	import { recentSundays } from '#lib/registry/weeks.ts';
	import { EDU_DEPTS, FAITH_KINDS, OFFICE_NAMES, OFFICE_STATUSES } from '#lib/registry/types.ts';

	const tabs = ['주일 출석', '소그룹 출석', '임직', '세례'];
	let tab = $state(page.url.searchParams.get('tab') ?? '주일 출석');
	const WEEKS = recentSundays(8);
	const md = (iso: string) => `${+iso.slice(5, 7)}/${+iso.slice(8, 10)}`;
	const members = $derived(registry.d.people.map((p) => ({ p, s: registry.summary(p) })).filter((x) => x.s.memberCert));

	// ---- 주일 출석: 장년 / 교육부서 ----
	const attend = (list: typeof members) => WEEKS.map((d, i) => list.filter(({ p }) => registry.attended(p.id, d, WEEKS.length - 1 - i)).length);
	const adult = $derived(members.filter((x) => x.s.cat2 === '장년' || (x.s.cat2 === '새가족' && !x.p.edu_dept)));
	const adultWeekly = $derived(attend(adult));
	const depts = $derived(EDU_DEPTS.map((d) => {
		const l = members.filter((x) => x.p.edu_dept === d);
		return { name: d, total: l.length, weekly: attend(l) };
	}));
	const maxAdult = $derived(Math.max(1, ...adultWeekly));
	// 교육부서 합계 · 전체(장년 + 교육부서)
	const eduTotal = $derived(depts.reduce((s, d) => s + d.total, 0));
	const eduWeekly = $derived(WEEKS.map((_, i) => depts.reduce((s, d) => s + d.weekly[i], 0)));
	const allWeekly = $derived(WEEKS.map((_, i) => adultWeekly[i] + eduWeekly[i]));

	// 꺾은선 그래프: 주마다 점. 세 줄(전체 · 장년 · 교육부서)을 한 축에
	const CW = 900, CH = 300, PL = 44, PR = 130, PT = 16, PB = 30;
	const series = $derived([
		{ name: '전체', color: 'var(--primary)', data: allWeekly },
		{ name: '장년', color: 'var(--teal-600)', data: adultWeekly },
		{ name: '교육부서', color: '#e8590c', data: eduWeekly }
	]);
	const yStep = $derived(Math.max(...allWeekly) > 200 ? 100 : 50);
	const yMax = $derived(Math.ceil(Math.max(10, ...allWeekly) / yStep) * yStep);
	const xAt = (i: number) => PL + (i * (CW - PL - PR)) / (WEEKS.length - 1);
	const yAt = (v: number) => PT + (CH - PT - PB) * (1 - v / yMax);
	const yTicks = $derived(Array.from({ length: yMax / yStep + 1 }, (_, i) => i * yStep));
	let hover = $state<number | null>(null);
	const pct = (n: number, t: number) => (t ? Math.round((n * 100) / t) : 0);

	// ---- 소그룹 출석 ----
	let comm = $state('전체');
	const groupRows = $derived(
		registry.d.smallGroups.filter((g) => comm === '전체' || g.community === comm).sort((a, b) => a.community.localeCompare(b.community, 'ko') || a.sort_order - b.sort_order).map((g) => {
			const l = members.filter((x) => x.s.smallGroup === g.name);
			// 주일 오후 소그룹 모임 출석 (홈페이지 출석부에서 인도자가 등록)
			const weekly = WEEKS.map((d, i) => l.filter(({ p }) => registry.groupAttended(p.id, d, WEEKS.length - 1 - i)).length);
			const avg4 = l.length ? Math.round((weekly.slice(-4).reduce((a, b) => a + b, 0) * 100) / (l.length * 4)) : 0;
			return { g, total: l.length, weekly, avg4 };
		})
	);
	const heat = (v: number) => `color-mix(in srgb, var(--primary) ${Math.round(v * 0.62)}%, #fff)`;

	// ---- 임직 ----
	const live = $derived(registry.d.officeTerms.filter((t) => !t.end_date && t.rank_status !== '사임'));
	const matrix = (office: string, st: string) => live.filter((t) => t.office === office && t.rank_status === st && registry.person(t.person_id)).length;
	const years = Array.from({ length: 10 }, (_, i) => new Date().getFullYear() - i);
	const ordYear = (office: string, y: number) => registry.d.officeTerms.filter((t) => t.office === office && t.start_date?.startsWith(String(y)) && t.rank_status !== '은퇴' && t.rank_status !== '원로').length;
	const ordOffices = ['장로', '시무집사', '권사', '서리집사'];

	// ---- 세례 ----
	const faithYear = (kind: string, y: number) => registry.d.faithEvents.filter((f) => f.kind === kind && f.event_date?.startsWith(String(y))).length;
	const faithNow = $derived.by(() => {
		const m: Record<string, number> = {};
		for (const x of members) m[x.s.faith] = (m[x.s.faith] ?? 0) + 1;
		return m;
	});
</script>

<h1>통계</h1>
<div class="tabs">{#each tabs as t}<button class:on={tab === t} onclick={() => (tab = t)}>{registry.tr(t)}</button>{/each}</div>

{#if tab === '주일 출석'}
	<div class="dash-grid even">
		<section class="card">
			<div class="card-head"><div><h2>장년 주일 오전예배 출석</h2><span class="sub">최근 8주 · 재적 장년·새가족 {adult.length}명</span></div></div>
			<div class="spark">
				{#each adultWeekly as n, i}<div class="col"><span>{n}</span><i style="height:{(n * 100) / maxAdult}%"></i><small>{md(WEEKS[i])}</small></div>{/each}
			</div>
		</section>
		<section class="card">
			<div class="card-head" id="edu"><div><h2>교육부서 이번 주일 출석률</h2><span class="sub">교육부서에 속한 교인의 이번 주일 출석</span></div></div>
			{#each depts as d}
				<div class="hbar"><span class="nm">{d.name}</span><div class="track"><i style="width:{pct(d.weekly[7], d.total)}%"></i></div><span class="v">{d.weekly[7]}/{d.total}명</span></div>
			{/each}
		</section>
	</div>
	<section class="card">
		<div class="card-head"><div><h2>주일 출석 추이</h2><span class="sub">최근 8주 · 장년 주일 오전예배 + 교육부서 · 점에 마우스를 올리면 그 주 인원</span></div>
			<div class="lg">{#each series as s}<span><i style="background:{s.color}"></i>{s.name}</span>{/each}</div>
		</div>
		<div class="line-wrap">
			<svg viewBox="0 0 {CW} {CH}" class="line" role="img" aria-label="최근 8주 주일 출석 꺾은선 그래프 (전체 · 장년 · 교육부서)">
				{#each yTicks as t}
					<line x1={PL} x2={CW - PR} y1={yAt(t)} y2={yAt(t)} class="grid" />
					<text x={PL - 8} y={yAt(t) + 4} text-anchor="end" class="ax">{t}</text>
				{/each}
				{#each WEEKS as d, i}<text x={xAt(i)} y={CH - 8} text-anchor="middle" class="ax">{md(d)}</text>{/each}
				{#if hover !== null}<line x1={xAt(hover)} x2={xAt(hover)} y1={PT} y2={CH - PB} class="cross" />{/if}
				{#each series as s}
					<polyline points={s.data.map((v, i) => `${xAt(i)},${yAt(v)}`).join(' ')} fill="none" stroke={s.color} stroke-width="2" stroke-linejoin="round" />
					{#each s.data as v, i}<circle cx={xAt(i)} cy={yAt(v)} r={hover === i ? 5.5 : 4} fill={s.color} stroke="#fff" stroke-width="2" />{/each}
					<text x={xAt(WEEKS.length - 1) + 10} y={yAt(s.data[s.data.length - 1]) + 4} class="end">{s.name} {s.data[s.data.length - 1]}</text>
				{/each}
				<!-- 마우스 영역: 주마다 세로 띠 -->
				{#each WEEKS as _, i}
					<rect x={xAt(i) - (CW - PL - PR) / (WEEKS.length - 1) / 2} y={PT} width={(CW - PL - PR) / (WEEKS.length - 1)} height={CH - PT - PB} fill="transparent" role="presentation" onmouseenter={() => (hover = i)} onmouseleave={() => (hover = null)} />
				{/each}
			</svg>
			{#if hover !== null}
				<div class="tip" style="left:{(xAt(hover) / CW) * 100}%">
					<b>{md(WEEKS[hover])} 주일</b>
					{#each series as s}<span><i style="background:{s.color}"></i>{s.name} <b>{s.data[hover]}</b>명</span>{/each}
				</div>
			{/if}
		</div>
	</section>
	<div class="card" style="padding:0;overflow-x:auto">
		<table class="sm">
			<thead><tr><th>구분</th><th>재적</th>{#each WEEKS as d}<th style="text-align:right">{md(d)}</th>{/each}</tr></thead>
			<tbody>
				<tr class="sumrow"><td><b>전체 (장년 + 교육부서)</b></td><td><b>{adult.length + eduTotal}</b></td>{#each allWeekly as n}<td style="text-align:right"><b>{n}</b></td>{/each}</tr>
				<tr><td><b>장년</b></td><td>{adult.length}</td>{#each adultWeekly as n}<td style="text-align:right">{n}</td>{/each}</tr>
				<tr><td><b>교육부서 합계</b></td><td>{eduTotal}</td>{#each eduWeekly as n}<td style="text-align:right">{n}</td>{/each}</tr>
				{#each depts as d}<tr><td style="padding-left:1.4rem">{d.name}</td><td>{d.total}</td>{#each d.weekly as n}<td style="text-align:right">{n}</td>{/each}</tr>{/each}
			</tbody>
		</table>
	</div>
{:else if tab === '소그룹 출석'}
	<div class="card">
		<div class="toolbar">
			<select bind:value={comm}><option>전체</option>{#each registry.communities as c}<option>{c.name}</option>{/each}</select>
			<span class="muted">주일 오후 {registry.t2} 모임 출석 (홈페이지 출석부에서 인도자가 등록). 칸의 색이 진할수록 출석률이 높습니다. {registry.t2} 이름을 누르면 상세 화면으로 갑니다.</span>
		</div>
	</div>
	<div class="card" style="padding:0;overflow-x:auto">
		<table class="sm">
			<thead><tr><th>{registry.t1}</th><th>{registry.t2}</th><th>인원</th>{#each WEEKS as d}<th style="text-align:center">{md(d)}</th>{/each}<th>4주 평균</th></tr></thead>
			<tbody>
				{#each groupRows as r (r.g.id)}
					<tr>
						<td>{r.g.community}</td>
						<td><a href={hrefTo(`/communities/${encodeURIComponent(r.g.name)}`)}><b>{r.g.name}</b></a></td>
						<td>{r.total}</td>
						{#each r.weekly as n}{@const v = pct(n, r.total)}<td style="text-align:center;background:{heat(v)};color:{v > 65 ? '#fff' : 'inherit'}">{v}%</td>{/each}
						<td><b>{r.avg4}%</b></td>
					</tr>
				{/each}
			</tbody>
		</table>
	</div>
{:else if tab === '임직'}
	<div class="card" style="padding:0;overflow-x:auto">
		<div class="card-head" style="padding:1.1rem 1.3rem 0"><h2>임직자 현황 <span class="tag">지금 기준</span></h2><span class="sub">직분 × 구분</span></div>
		<table class="sm">
			<thead><tr><th>직분</th>{#each OFFICE_STATUSES as s}<th style="text-align:right">{s}</th>{/each}<th style="text-align:right">합계</th></tr></thead>
			<tbody>
				{#each OFFICE_NAMES as o}
					{@const total = OFFICE_STATUSES.reduce((a, s) => a + matrix(o, s), 0)}
					<tr><td><b>{o}</b></td>{#each OFFICE_STATUSES as s}<td style="text-align:right" class:muted={!matrix(o, s)}>{matrix(o, s) || '-'}</td>{/each}<td style="text-align:right"><b>{total}</b></td></tr>
				{/each}
			</tbody>
		</table>
	</div>
	<div class="card" style="padding:0;overflow-x:auto">
		<div class="card-head" style="padding:1.1rem 1.3rem 0"><h2>연도별 임직</h2><span class="sub">임직일(시작일) 기준 · 최근 10년</span></div>
		<table class="sm">
			<thead><tr><th>연도</th>{#each ordOffices as o}<th style="text-align:right">{o}</th>{/each}</tr></thead>
			<tbody>{#each years as y}<tr><td>{y}</td>{#each ordOffices as o}<td style="text-align:right" class:muted={!ordYear(o, y)}>{ordYear(o, y) || '-'}</td>{/each}</tr>{/each}</tbody>
		</table>
	</div>
{:else}
	<div class="dash-grid even">
		<section class="card">
			<div class="card-head"><h2>재적 교인 신급 현황</h2></div>
			{#each ['세례교인', '유아세례교인', '학습교인', '원입교인', '미확인'] as k}
				<div class="hbar"><span class="nm">{k}</span><div class="track"><i style="width:{pct(faithNow[k] ?? 0, members.length)}%"></i></div><span class="v">{faithNow[k] ?? 0}명</span></div>
			{/each}
			<p class="muted" style="font-size:.82rem">'미확인'은 신급 기록이 아직 없는 사람입니다. 확인이 끝나야 교세 통계가 정확해집니다.</p>
		</section>
		<section class="card" style="padding:0;overflow-x:auto">
			<div class="card-head" style="padding:1.1rem 1.3rem 0"><h2>연도별 신급 기록</h2><span class="sub">최근 10년</span></div>
			<table class="sm">
				<thead><tr><th>연도</th>{#each FAITH_KINDS as k}<th style="text-align:right">{k}</th>{/each}</tr></thead>
				<tbody>{#each years as y}<tr><td>{y}</td>{#each FAITH_KINDS as k}<td style="text-align:right" class:muted={!faithYear(k, y)}>{faithYear(k, y) || '-'}</td>{/each}</tr>{/each}</tbody>
			</table>
		</section>
	</div>
{/if}

<style>
	/* 주일 출석 꺾은선 그래프 */
	.lg { display: flex; gap: 0.9rem; font-size: 0.85rem; color: var(--muted); }
	.lg span { display: inline-flex; align-items: center; gap: 0.3rem; }
	.lg i, .tip i { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
	.line-wrap { position: relative; }
	.line { width: 100%; height: auto; display: block; }
	.line .grid { stroke: var(--line); stroke-width: 1; }
	.line .cross { stroke: var(--line-strong); stroke-dasharray: 3 3; }
	.line .ax { font-size: 11px; fill: var(--muted); }
	.line .end { font-size: 12px; font-weight: 700; fill: var(--text); }
	.tip { position: absolute; top: 0; transform: translateX(-50%); background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 0.45rem 0.65rem; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1); font-size: 0.8rem; display: flex; flex-direction: column; gap: 0.15rem; pointer-events: none; white-space: nowrap; }
	.tip span { display: inline-flex; align-items: center; gap: 0.3rem; }
	.sumrow td { background: var(--primary-50); }
</style>
