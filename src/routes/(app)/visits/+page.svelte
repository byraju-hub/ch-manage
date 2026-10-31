<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 심방: 심방 목록 · 캘린더 보기 · 소그룹 특이사항 · 등록 (심방 권한이 있는 회원만)
	import { page } from '$app/state';
	import { registry } from '#lib/registry/store.svelte.ts';
	import Calendar from '#lib/components/Calendar.svelte';
	import PersonPicker from '#lib/components/PersonPicker.svelte';
	import NameChips, { type Chip } from '#lib/components/NameChips.svelte';
	import VisitAnalyzer, { type Analysis } from '#lib/components/VisitAnalyzer.svelte';
	import CarePreview from '#lib/components/CarePreview.svelte';
	import Pagination from '#lib/components/Pagination.svelte';
	import { officeKindTitle } from '#lib/registry/derive.ts';
	import { FULL_TIME_CLERGY, type VisitMethod } from '#lib/registry/types.ts';

	const today = new Date().toISOString().slice(0, 10);
	let tab = $state<'list' | 'cal' | 'notes' | 'new'>('cal');
	let q = $state(page.url.searchParams.get('q') ?? '');
	let kind = $state('');
	let pastor = $state('');
	let from = $state(page.url.searchParams.get('from') ?? '');
	let to = $state('');

	const pastors = $derived([...new Set(registry.d.visits.map((v) => v.pastor))].sort((a, b) => a.localeCompare(b, 'ko')));
	const clergy = $derived(registry.d.people.filter((p) => p.clergy_title && FULL_TIME_CLERGY.includes(p.clergy_title)));
	const nameOf = (id: string) => registry.person(id)?.name ?? '';
	const rows = $derived(
		registry.d.visits
			.filter((v) => {
				const t = q.trim().toLowerCase();
				if (t && !`${nameOf(v.person_id)} ${v.content} ${v.scripture ?? ''} ${v.follow_up ?? ''} ${v.companions ?? ''}`.toLowerCase().includes(t)) return false;
				return (!kind || v.kind === kind) && (!pastor || v.pastor === pastor) && (!from || v.visit_date >= from) && (!to || v.visit_date <= to);
			})
			.sort((a, b) => b.visit_date.localeCompare(a.visit_date))
	);

	// 날짜는 26/11/27 처럼, 직분은 교인 목록과 같이 (시무권사 · 은퇴집사 …)
	const ymd = (iso: string) => `${iso.slice(2, 4)}/${iso.slice(5, 7)}/${iso.slice(8, 10)}`;
	const officeOf = (id: string) => { const p = registry.person(id); const o = p ? registry.summary(p).office : null; return p && o ? officeKindTitle(o, p.clergy_title) : ''; };
	// 목록은 한 쪽에 30건씩, 아래 쪽 번호로 넘김. 찾기 조건이 바뀌면 1쪽으로
	const PER = 30;
	let vpage = $state(1);
	let listTop = $state<HTMLElement | null>(null);
	const pages = $derived(Math.max(1, Math.ceil(rows.length / PER)));
	$effect(() => { void q; void kind; void pastor; void from; void to; vpage = 1; });

	// 등록 폼
	let f = $state({ person: page.url.searchParams.get('person') ?? '', date: page.url.searchParams.get('date') ?? today, kind: page.url.searchParams.get('kind') ?? '일반', method: '대면' as VisitMethod, pastor: '', scripture: '', hymn: '', content: '', follow: '' });
	let companions = $state<Chip[]>([]);
	const nn = (x: string) => (x.trim() === '' ? null : x.trim());
	// 등록 후 안내: 심방 내용에서 자동으로 넣은 성도 일정
	let savedMsg = $state('');
	function submit(e: SubmitEvent) {
		e.preventDefault();
		if (!f.person) return;
		const name = nameOf(f.person);
		const added = registry.addVisit({ person_id: f.person, visit_date: f.date, kind: f.kind, method: f.method, pastor: f.pastor || clergy[0]?.name || '담당 교역자', companions: companions.length ? companions.map((c) => c.name).join(', ') : null, scripture: nn(f.scripture), hymn: nn(f.hymn), content: f.content, follow_up: nn(f.follow) });
		const md = (iso: string) => `${+iso.slice(5, 7)}/${+iso.slice(8, 10)}`;
		savedMsg = `${name} 심방을 등록했습니다.` + (added.length ? ` 캘린더 성도 일정에 ${added.map((c) => `${md(c.date)}${c.end_date ? `~${md(c.end_date)}` : ''} ${c.label}`).join(', ')}을(를) 함께 넣었습니다.` : '');
		f.person = ''; f.content = ''; f.follow = ''; f.scripture = ''; f.hymn = ''; companions = [];
		tab = 'list';
	}
	$effect(() => { if (page.url.searchParams.get('person')) tab = 'new'; });

	// 소그룹 특이사항: 홈페이지 출석부에서 소그룹 인도자가 적은 개별 메모를 한곳에 모아 봄
	const commOf = (g: string) => registry.groupDef(g)?.community ?? '';
	let nq = $state('');
	let ncomm = $state('');
	let ngroup = $state('');
	let nfrom = $state('');
	let nto = $state('');
	$effect(() => { void ncomm; ngroup = ''; });
	const ngroupOpts = $derived(registry.groupsIn(ncomm).map((g) => g.name));
	const noteRows = $derived(
		registry.d.groupNotes
			.filter((n) => {
				const t = nq.trim().toLowerCase();
				if (t && !`${n.person_id ? nameOf(n.person_id) : '기타 의견'} ${n.body} ${n.author}`.toLowerCase().includes(t)) return false;
				return (!ncomm || commOf(n.small_group) === ncomm) && (!ngroup || n.small_group === ngroup) && (!nfrom || n.date >= nfrom) && (!nto || n.date <= nto);
			})
			.sort((a, b) => b.date.localeCompare(a.date))
	);

	// 특이사항도 한 쪽에 30건씩, 조건이 바뀌면 1쪽으로
	let npage = $state(1);
	let noteTop = $state<HTMLElement | null>(null);
	const npages = $derived(Math.max(1, Math.ceil(noteRows.length / PER)));
	$effect(() => { void nq; void ncomm; void ngroup; void nfrom; void nto; npage = 1; });

	// 녹음·문서 분석 결과를 입력 칸에 채움
	function applyAnalysis(r: Analysis) {
		if (r.kind) f.kind = r.kind;
		if (r.content) f.content = r.content;
		if (r.scripture) f.scripture = r.scripture;
		if (r.hymn) f.hymn = r.hymn;
		if (r.follow_up) f.follow = r.follow_up;
		if (r.companions?.length) companions = r.companions.map((n) => { const m = registry.d.people.filter((p) => p.name === n); return { id: m.length === 1 ? m[0].id : null, name: n }; });
	}
</script>

<div class="row page-head" style="justify-content:space-between">
	<h1>심방</h1>
	{#if registry.perms.visit && registry.perms.edit}<button type="button" onclick={() => (tab = 'new')}>+ 심방 등록</button>{/if}
</div>
{#if !registry.perms.visit}
	<div class="warn">심방 메뉴는 A 등급(전임 교역자)만 볼 수 있습니다.</div>
{:else}
	<div class="tabs">
		<button class:on={tab === 'cal'} onclick={() => (tab = 'cal')}>캘린더 보기</button>
		<button class:on={tab === 'list'} onclick={() => (tab = 'list')}>심방 목록</button>
		<button class:on={tab === 'notes'} onclick={() => (tab = 'notes')}>{registry.t2} 특이사항</button>
	</div>

	{#if tab === 'list'}
		{#if savedMsg}<p class="ok row" style="justify-content:space-between">{savedMsg}<button type="button" class="ghost xs" onclick={() => (savedMsg = '')}>닫기</button></p>{/if}
		<div class="card">
			<div class="toolbar"><input type="search" placeholder="교인 이름 · 내용 · 말씀 · 동행자 검색" bind:value={q} /></div>
			<div class="toolbar">
				<select bind:value={kind}><option value="">심방 구분 전체</option>{#each registry.codes.visitKinds as k}<option>{k}</option>{/each}</select>
				<select bind:value={pastor}><option value="">담당 교역자 전체</option>{#each pastors as k}<option>{k}</option>{/each}</select>
				<span class="range"><span class="muted">날짜</span><input type="date" bind:value={from} aria-label="시작 날짜" /><span>~</span><input type="date" bind:value={to} aria-label="끝 날짜" /></span>
			</div>
		</div>
		<p class="muted" style="margin:0 0 .5rem;scroll-margin-top:110px" bind:this={listTop}>모두 {rows.length.toLocaleString()}건{#if pages > 1} · {vpage} / {pages}쪽{/if}</p>
		<div class="card" style="padding:0;overflow-x:auto">
			<table class="sm vlist">
				<thead><tr><th>날짜</th><th>구분</th><th>교인</th><th>직분</th><th>담당</th><th>내용</th><th>후속 조치</th></tr></thead>
				<tbody>
					{#each rows.slice((vpage - 1) * PER, vpage * PER) as v (v.id)}
						<tr>
							<td style="white-space:nowrap">{ymd(v.visit_date)}</td>
							<td class="nw">{v.kind}<small class="muted">{v.method}</small></td>
							<td class="nw"><a href={hrefTo(`/${v.person_id}`)}>{nameOf(v.person_id)}</a></td>
							<td class="nw">{officeOf(v.person_id)}</td>
							<td class="nw">{v.pastor}{#if v.companions}<small class="muted">동행 {v.companions}</small>{/if}</td>
							<td class="vc" style="min-width:220px">{v.content}{v.scripture ? ` · ${v.scripture}` : ''}{v.hymn ? ` · ${v.hymn}` : ''}</td>
							<td>{v.follow_up ?? '-'}</td>
						</tr>
					{:else}<tr><td colspan="7" class="muted">조건에 맞는 심방이 없습니다.</td></tr>{/each}
				</tbody>
			</table>
		</div>
		<Pagination bind:page={vpage} {pages} onchange={() => listTop?.scrollIntoView({ block: 'start', behavior: 'smooth' })} />
	{:else if tab === 'cal'}
		<div class="card"><Calendar only={['심방 계획', '성도']} /></div>
		<p class="muted">달력에는 등록된 심방이 날짜별로 표시됩니다. 앞으로의 날짜로 등록하면 심방 계획으로 보입니다.</p>
	{:else if tab === 'notes'}
		<div class="card">
			<div class="toolbar"><input type="search" placeholder="교인 이름 · 내용 · 인도자 검색" bind:value={nq} /></div>
			<div class="toolbar">
				<select bind:value={ncomm} aria-label="{registry.t1} 선택"><option value="">전체 {registry.t1}</option>{#each registry.communities as c}<option value={c.name}>{c.name}</option>{/each}</select>
				<select bind:value={ngroup} disabled={!ncomm} aria-label="{registry.t2} 선택"><option value="">{ncomm ? `전체 ${registry.t2}` : `${registry.t1}을(를) 먼저 고르세요`}</option>{#each ngroupOpts as g}<option value={g}>{g}</option>{/each}</select>
				<span class="range"><span class="muted">날짜</span><input type="date" bind:value={nfrom} aria-label="시작 날짜" /><span>~</span><input type="date" bind:value={nto} aria-label="끝 날짜" /></span>
			</div>
		</div>
		<p class="muted" style="margin:0 0 .5rem;scroll-margin-top:110px" bind:this={noteTop}>모두 {noteRows.length.toLocaleString()}건{#if npages > 1} · {npage} / {npages}쪽{/if} ({registry.t2} 인도자가 적은 특이사항을 모아 보여 줍니다.)</p>
		<div class="card" style="padding:0;overflow-x:auto">
			<table class="sm vlist">
				<thead><tr><th>날짜</th><th>{registry.t1}</th><th>{registry.t2}</th><th>작성</th><th>교인</th><th>특이사항</th></tr></thead>
				<tbody>
					{#each noteRows.slice((npage - 1) * PER, npage * PER) as n (n.id)}
						<tr class:opinion={!n.person_id}>
							<td style="white-space:nowrap">{ymd(n.date)}</td>
							<td style="white-space:nowrap">{registry.short(commOf(n.small_group)) || '-'}</td>
							<td style="white-space:nowrap"><a href={hrefTo(`/communities/${encodeURIComponent(n.small_group)}`)}>{n.small_group}</a></td>
							<td style="white-space:nowrap">{n.author}</td>
							<!-- 교인이 없는 줄은 소그룹 모임 전체에 대한 '기타 의견' (적힌 내용이 있을 때만 줄이 생김) -->
							<td style="white-space:nowrap">{#if n.person_id}<a href={hrefTo(`/${n.person_id}`)}>{nameOf(n.person_id)}</a>{:else}<span class="tag part">기타 의견</span>{/if}</td>
							<td class="vc" style="min-width:220px">{n.body}</td>
						</tr>
					{:else}<tr><td colspan="6" class="muted">조건에 맞는 특이사항이 없습니다.</td></tr>{/each}
				</tbody>
			</table>
		</div>
		<Pagination bind:page={npage} pages={npages} onchange={() => noteTop?.scrollIntoView({ block: 'start', behavior: 'smooth' })} />
	{:else}
		<form class="card form" onsubmit={submit}>
			<h2 class="wide">심방 등록</h2>
			<label class="wide">교인 *<PersonPicker bind:value={f.person} placeholder="심방받을 교인 이름·전화 검색" /></label>
			<label>날짜 *<input type="date" required bind:value={f.date} /></label>
						<label>구분<select bind:value={f.kind}>{#each registry.codes.visitKinds as k}<option>{k}</option>{/each}</select></label>
			<label>방법<select bind:value={f.method}><option>대면</option><option>전화</option></select></label>
			<label>담당 교역자<select bind:value={f.pastor}><option value="">(선택)</option>{#each clergy as c}<option>{c.name}</option>{/each}</select></label>
			<label class="wide">동행자 <span class="muted" style="font-weight:400">(이름을 쓰고 쉼표 · 같은 이름이 여러 명이면 목록에서 선택)</span><NameChips bind:chips={companions} allowFree placeholder="예: 김영수, 이순희" /></label>
			<div class="wide"><VisitAnalyzer onresult={applyAnalysis} /></div>
			<label>말씀<input bind:value={f.scripture} placeholder="시편 23편" /></label>
			<label>찬송<input bind:value={f.hymn} placeholder="찬 305장" /></label>
			<label class="wide"><span class="lt">심방 내용 <small class="hint">(예: 22일 입원, 23일 수술 과 같이 날짜와 함께 기록하면 캘린더의 성도 일정에 자동으로 입력이 됩니다.)</small></span><small class="hint">*자동 등록 해당 문구 : 입원 · 퇴원 · 수술 · 시술 · 검사 · 항암 · 치료 · 출산 · 돌잔치 · 결혼식 · 장례 · 입관 · 발인 · 이사 · 개업 · 입대 · 전역 · 제대 · 입학 · 졸업 · 출국 · 귀국 등</small><textarea rows="5" bind:value={f.content} required></textarea></label>
			<label class="wide">후속 조치<input bind:value={f.follow} /><CarePreview text={`${f.content}\n${f.follow}`} base={f.date} person={f.person} /></label>
			<div class="wide row"><button type="submit" disabled={!f.person}>등록</button><button type="button" class="ghost" onclick={() => (tab = 'list')}>취소</button></div>
		</form>
	{/if}
{/if}

<style>
	/* 소그룹 '기타 의견' 줄은 옅은 주황 바탕으로 구분 */
	tr.opinion td { background: #fffaf6; }
</style>
