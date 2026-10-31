<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 교인 목록: 검색(기본·복수·항목별)과 필터, 왼쪽 목록 · 오른쪽 상세, 내려받기
	import { page } from '$app/state';
	import { registry } from '#lib/registry/store.svelte.ts';
	import { ageOf, ageText, officeWithKind, officeKindTitle } from '#lib/registry/derive.ts';
	import NameChips, { type Chip } from '#lib/components/NameChips.svelte';
	import ExportButtons from '#lib/components/ExportButtons.svelte';
	import SmsDialog from '#lib/components/SmsDialog.svelte';
	import BulkEditPanel from '#lib/components/BulkEditPanel.svelte';

	// 검색 방식: 기본(한 줄) / 복수(여러 명을 한꺼번에) / 항목별(칸을 골라서)
	let mode = $state<'basic' | 'multi' | 'fields'>('basic');
	const FIELDS = ['이름', '전화', '주소', '직업·직장', '차량 번호', '메모', '인도자', '이전 교회'];
	let chips = $state<Chip[]>([]);
	let fieldsPicked = $state<string[]>(['이름', '전화']);
	let q = $state(page.url.searchParams.get('q') ?? '');
	let c1 = $state('');
	let c2 = $state(page.url.searchParams.get('l2') ?? '');
	let c3 = $state(page.url.searchParams.get('l3') ?? '');
	let society = $state('');
	// 기본은 재적 교인만 보임 (복수 검색으로 이름을 고른 경우는 상태와 관계없이 보임)
	let status = $state('재적');
	let faith = $state('');
	let office = $state('');
	let community = $state('');
	let smallGroup = $state('');
	let gender = $state('');
	let ageMin = $state('');
	let ageMax = $state('');
	let regFrom = $state('');
	let regTo = $state('');
	let account = $state('');
	let searched = $state(false);
	let selId = $state('');
	let shown = $state(200);

	const digits = (x: string) => x.replace(/\D/g, '');
	// 복수 검색: 쉼표·줄바꿈으로 여러 사람을 한꺼번에
	const chipIds = $derived(new Set(chips.filter((c) => c.id).map((c) => c.id!)));
	const basicHit = $derived(mode === 'basic' ? registry.matcher(q) : null);
	function matchText(p: any): boolean {
		if (mode === 'multi') return !chips.length || chipIds.has(p.id);
		const t = q.trim();
		if (!t) return true;
		if (mode === 'fields') {
			const hh = registry.householdOf(p.id);
			const hay: Record<string, string> = {
				이름: `${p.name} ${p.name_note ?? ''}`, 전화: p.phone ?? '', 주소: `${p.address1 ?? ''} ${p.address2 ?? ''} ${hh?.address ?? ''}`,
				'직업·직장': `${p.occupation ?? ''} ${p.workplace ?? ''}`, '차량 번호': p.car_number ?? '', 메모: p.note ?? '', 인도자: `${p.guide_name ?? ''} ${registry.person(p.guide_person_id ?? '')?.name ?? ''}`, '이전 교회': p.previous_church ?? ''
			};
			const words = t.toLowerCase().split(/\s+/);
			return words.every((w) => fieldsPicked.some((f) => hay[f].toLowerCase().includes(w) || (digits(w).length >= 4 && digits(hay[f]).includes(digits(w)))));
		}
		// 기본 검색: 대시보드 통합 검색처럼 이름 · 전화 · 소그룹 · 직분 · 주소 · 직업 · 차량 번호 · 메모 등 무엇이든
		return !!basicHit?.(registry.personHay(p));
	}
	const l1opts = $derived(registry.childrenOf(null));
	const l2opts = $derived([...new Set(registry.d.categories.filter((c) => c.parent_id && registry.d.categories.find((x) => x.id === c.parent_id)?.parent_id === null && (!c1 || registry.d.categories.find((x) => x.id === c.parent_id)?.name === c1)).map((c) => c.name))]);
	const l3opts = $derived([...new Set(registry.d.categories.filter((c) => c.parent_id && registry.d.categories.find((x) => x.id === c.parent_id)?.parent_id).map((c) => c.name))]);

	const rows = $derived(
		registry.d.people.map((p) => ({ p, s: registry.summary(p) })).filter(({ p, s }) => {
			if (!matchText(p)) return false;
			if (c1 && s.cat1 !== c1) return false;
			if (c2 && s.cat2 !== c2) return false;
			if (c3 && s.cat3 !== c3) return false;
			if (society && p.mission_society !== society) return false;
			const age = ageOf(p.birth_date);
			return ((mode === 'multi' && chips.length) || !status || s.status === status) && (!faith || s.faith === faith) &&
				(!office || s.title === office) && (!community || s.community === community) &&
				(!smallGroup || s.smallGroup === smallGroup) && (!gender || p.gender === gender) &&
				(!ageMin || (age !== null && age >= +ageMin)) && (!ageMax || (age !== null && age <= +ageMax)) &&
				(!regFrom || (s.registered !== null && s.registered >= regFrom)) &&
				(!regTo || (s.registered !== null && s.registered <= regTo)) &&
				(!account || (account === 'y') === p.has_account);
		}).sort((a, b) => a.p.name.localeCompare(b.p.name, 'ko'))
	);

	const sel = $derived(selId ? registry.person(selId) : undefined);
	// 상세를 열면 교적 카드를 연 것과 같이 열람 기록을 남김
	let loggedId = '';
	$effect(() => {
		if (sel && loggedId !== sel.id) { loggedId = sel.id; registry.log('열람', sel.name); }
	});
	const selSummary = $derived(sel ? registry.summary(sel) : null);
	const selFamily = $derived.by(() => { const h = sel ? registry.householdOf(sel.id) : undefined; return h ? registry.membersOfHousehold(h.id) : []; });
	const selNotes = $derived(sel ? registry.d.notes.filter((n) => n.person_id === sel.id).slice(0, 3) : []);
	const selVisits = $derived(sel ? registry.d.visits.filter((v) => v.person_id === sel.id && v.visit_date <= new Date().toISOString().slice(0, 10)).sort((a, b) => b.visit_date.localeCompare(a.visit_date)).slice(0, 2) : []);
	// 조건이 바뀌면 목록을 처음 200명으로 되돌림
	$effect(() => { rows.length; shown = 200; });

	const uniq = (xs: string[]) => [...new Set(xs.filter(Boolean))].sort((a, b) => a.localeCompare(b, 'ko'));
	const summaries = $derived(registry.d.people.map((p) => registry.summary(p)));
	const communities = $derived(uniq(registry.d.personGroups.map((g) => g.community)));
	const groups = $derived(uniq(registry.d.personGroups.filter((g) => !community || g.community === community).map((g) => g.small_group)));
	const titles = $derived(uniq(summaries.map((s) => s.title)));

	// 내려받기: 필요한 칸만 골라서 엑셀(.xlsx)
	const COLS: Record<string, (p: any, s: any) => string> = {
		이름: (p) => p.name, 동명이인구분: (p) => p.name_note ?? '', 성별: (p) => (p.gender === 'M' ? '남' : p.gender === 'F' ? '여' : ''),
		생년월일: (p) => p.birth_date ?? '', 나이: (p) => String(ageOf(p.birth_date) ?? ''), 휴대폰: (p) => p.phone ?? '',
		주소: (p) => [p.address1, p.address2].filter(Boolean).join(' ') || registry.householdOf(p.id)?.address || '',
		직분: (_, s) => s.title, 상태: (_, s) => s.status, 교인구분: (_, s) => s.category, 전도회: (p) => p.mission_society ?? '', 신급: (_, s) => s.faith, 공동체: (_, s) => s.community,
		소그룹: (_, s) => s.smallGroup, 등록일: (_, s) => s.registered ?? '', 차량번호: (p) => p.car_number ?? ''
	};
	// 처음에는 모든 칸을 골라 둠
	let picked = $state<string[]>(Object.keys(COLS));

	// 고른 순서가 아니라 위 칸 순서대로, 제목 줄 굵게·회색, 모든 칸 가운데 정렬·테두리
	let xlBusy = $state(false);
	async function download() {
		xlBusy = true;
		try {
			const writeXlsxFile = (await import('write-excel-file/browser')).default;
			const cols = Object.keys(COLS).filter((c) => picked.includes(c));
			const st = { borderStyle: 'thin' as const, borderColor: '#999999', align: 'center' as const, alignVertical: 'center' as const };
			const data = [
				cols.map((c) => ({ value: registry.tr(c), fontWeight: 'bold' as const, backgroundColor: '#E9ECEF', ...st })),
				...rows.map(({ p, s }) => cols.map((c) => ({ value: COLS[c](p, s), ...st })))
			];
			const W: Record<string, number> = { 이름: 10, 동명이인구분: 8, 성별: 6, 생년월일: 12, 나이: 6, 휴대폰: 15, 주소: 40, 직분: 10, 상태: 8, 교인구분: 24, 전도회: 10, 신급: 8, 공동체: 12, 소그룹: 12, 등록일: 12, 차량번호: 12 };
			await writeXlsxFile(data, { sheet: '교인 목록', columns: cols.map((c) => ({ width: W[c] ?? 12 })), stickyRowsCount: 1 }).toFile(`교인목록-${new Date().toISOString().slice(0, 10)}.xlsx`);
			registry.log('내려받기', `${rows.length}명 · ${cols.join('/')}`);
		} finally {
			xlBusy = false;
		}
	}
	function runSearch() {
		if (!searched || q) registry.log('검색', q || '(조건 필터)');
		searched = true;
	}

	// ---- 일괄 수정: 검색 결과에서 체크한 교인을 한꺼번에 (수정 권한이 있을 때만) ----
	// 문자 보내기: A 등급만 (검색 결과 전체에게. 수신 거부·번호 없음은 창에서 자동 제외)
	const canSms = $derived(registry.me?.status === '사용' && registry.me?.grade === 'A');
	let smsOpen = $state(false);
	// 평소에는 접혀 있고, 검색창 옆 '일괄 수정' 버튼을 누르면 펼쳐짐 (체크칸도 그때만 보임)
	let bulkOpen = $state(false);
	// 일괄 수정은 최고관리자만
	const bulkOn = $derived(bulkOpen && registry.isSuper);
	let checked = $state<Set<string>>(new Set());
	const checkedRows = $derived(rows.filter(({ p }) => checked.has(p.id)));
	const allChecked = $derived(rows.length > 0 && checkedRows.length === rows.length);
	function toggle(id: string) { const n = new Set(checked); if (n.has(id)) n.delete(id); else n.add(id); checked = n; }
	function toggleAll() { checked = allChecked ? new Set() : new Set(rows.map(({ p }) => p.id)); }

</script>

<div class="row page-head" style="justify-content:space-between">
	<div class="row" style="gap:.7rem"><h1>교인 목록 <small class="muted">({rows.length}명)</small></h1><a class="btn ghost sm" href={hrefTo('/members/columns')}>교인 목록 항목 설정</a></div>
	<div class="row" style="gap:.5rem">
		{#if canSms}
			<button type="button" class="sms-btn" onclick={() => (smsOpen = true)} disabled={!rows.length} title="검색 결과 {rows.length}명에게 문자 보내기">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5H10l-4.5 3.5V17.5H4A1.5 1.5 0 012.5 16V7A1.5 1.5 0 014 5.5z" /><circle cx="8" cy="11.5" r="1.1" /><circle cx="12" cy="11.5" r="1.1" /><circle cx="16" cy="11.5" r="1.1" /></svg>
				문자 보내기<span class="cnt">{rows.length}</span>
			</button>
		{/if}
		{#if registry.perms.edit}<a class="btn" href={hrefTo('/new')}>+ 교인 등록</a>{/if}
	</div>
</div>

<div class="card">
	<div class="toolbar">
		<div class="seg" role="tablist" aria-label="검색 방식">
			<button type="button" class:on={mode === 'basic'} onclick={() => (mode = 'basic')}>기본 검색</button>
			<button type="button" class:on={mode === 'multi'} onclick={() => (mode = 'multi')}>복수 검색</button>
			<button type="button" class:on={mode === 'fields'} onclick={() => (mode = 'fields')}>항목별 검색</button>
		</div>
		{#if registry.isSuper}<span style="flex:1"></span><button type="button" class="ghost sm" class:on-bulk={bulkOpen} onclick={() => { bulkOpen = !bulkOpen; if (!bulkOpen) checked = new Set(); }} aria-expanded={bulkOpen}>{bulkOpen ? '일괄 수정 닫기' : '일괄 수정'}</button>{/if}
	</div>
	<div class="toolbar">
		{#if mode === 'multi'}
			<div style="flex:1">
				<NameChips bind:chips placeholder="이름을 쓰고 쉼표(,) — 예: 김영수, 이순희" />
				<p class="muted" style="margin:.3rem 0 0;font-size:.8rem">교적에 있는 이름이 그대로 쓰입니다. 같은 이름이 한 명이면 바로 들어가고, 여러 명이면 목록에서 고릅니다. 이름 목록을 붙여 넣어도 됩니다.</p>
			</div>
		{:else}
			{#if mode === 'basic'}
				<div class="gs-box m-search">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
					<input type="search" placeholder="무엇이든 검색하세요! (이름 · 전화 · {registry.t2} · 직분 · 주소 · 직업 · 차량 번호 · 메모 등)" aria-label="교인 검색" bind:value={q} onchange={runSearch} />
				</div>
			{:else}
				<input type="search" placeholder="아래에서 고른 항목 안에서 찾습니다 (공백으로 여러 단어)" bind:value={q} onchange={runSearch} />
			{/if}
		{/if}
	</div>
	{#if mode === 'fields'}
		<div class="toolbar"><span class="muted">검색할 항목</span><div class="cols">{#each FIELDS as f}<label><input type="checkbox" value={f} bind:group={fieldsPicked} />{f}</label>{/each}</div></div>
	{/if}
	<div class="toolbar">
		<select bind:value={c1} onchange={() => { c2 = ''; c3 = ''; }}><option value="">교인구분 전체</option>{#each l1opts as o}<option>{o.name}</option>{/each}</select>
		<select bind:value={c2}><option value="">2단계 전체</option>{#each l2opts as o}<option>{o}</option>{/each}</select>
		<select bind:value={c3}><option value="">3단계 전체</option>{#each l3opts as o}<option>{o}</option>{/each}</select>
		<select bind:value={society}><option value="">전도회 전체</option>{#each registry.codes.missionSocieties as o}<option>{o}</option>{/each}</select>
	</div>
	<div class="toolbar">
		<select bind:value={status}><option value="">재적상태 전체</option>{#each ['재적', '보류', '보관', '전출', '별세', '제적', '미등록'] as v}<option>{v}</option>{/each}</select>
		<select bind:value={faith}><option value="">신급 전체</option>{#each ['세례교인', '유아세례교인', '학습교인', '원입교인', '미확인'] as v}<option>{v}</option>{/each}</select>
		<select bind:value={office}><option value="">직분 전체</option>{#each titles as v}<option>{v}</option>{/each}</select>
		<select bind:value={community} onchange={() => (smallGroup = '')}><option value="">{registry.t1} 전체</option>{#each communities as v}<option>{v}</option>{/each}</select>
		<select bind:value={smallGroup}><option value="">{registry.t2} 전체</option>{#each groups as v}<option>{v}</option>{/each}</select>
		<select bind:value={gender}><option value="">성별 전체</option><option value="M">남</option><option value="F">여</option></select>
		<select bind:value={account}><option value="">앱 가입 전체</option><option value="y">가입</option><option value="n">미가입</option></select>
	</div>
	<div class="toolbar">
		<span class="range"><span class="muted">나이</span><input type="number" style="width:5rem" placeholder="이상" bind:value={ageMin} /><span>~</span><input type="number" style="width:5rem" placeholder="이하" bind:value={ageMax} /></span>
		<span class="range"><span class="muted">등록일</span><input type="date" bind:value={regFrom} aria-label="등록일 시작" /><span>~</span><input type="date" bind:value={regTo} aria-label="등록일 끝" /></span>
	</div>
</div>

{#if bulkOn}
	<!-- 일괄 수정: 목록에서 체크한 교인만 -->
	<BulkEditPanel ids={checkedRows.map(({ p }) => p.id)} onclear={() => (checked = new Set())} onclose={() => { bulkOpen = false; checked = new Set(); }} />
{/if}

<div class="split">
	<div class="card list-pane" style="padding:0">
		<table class="mlist">
			<thead><tr>{#if bulkOn}<th class="ck"><input type="checkbox" checked={allChecked} indeterminate={checkedRows.length > 0 && !allChecked} onchange={toggleAll} aria-label="검색 결과 전체 선택" title="검색 결과 {rows.length}명 전체 선택" /></th>{/if}<th>이름</th><th>직분</th><th>상태</th><th>교인구분</th><th>{registry.t2}</th></tr></thead>
			<tbody>
				{#each rows.slice(0, shown) as { p, s } (p.id)}
					<tr class="pick" class:on={selId === p.id} class:checked={checked.has(p.id)} tabindex="0" onclick={() => (selId = p.id)} onkeydown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selId = p.id; } }}>
						{#if bulkOn}<td class="ck" onclick={(e) => e.stopPropagation()}><input type="checkbox" checked={checked.has(p.id)} onchange={() => toggle(p.id)} aria-label="{p.name} 선택" /></td>{/if}
						<td><b>{p.name}</b>{#if p.name_note}<span class="muted"> ({p.name_note})</span>{/if}</td>
						<td>{s.office ? officeKindTitle(s.office, p.clergy_title) : '-'}</td>
						<!-- 상태: 교인구분 3단계(예배출석·가끔출석·불출석·장기결석자). 1단계만 있는 구분(미등록/타교인·별세 등)은 '-' -->
						<td>{#if s.cat3}<span class="badge {s.cat3 === '가끔출석' ? 'inactive' : s.cat3 === '불출석' ? 'transferred' : s.cat3 === '장기결석자' ? 'long' : ''}">{s.cat3 === '장기결석자' ? '장결자' : s.cat3}</span>{:else}-{/if}</td>
						<td>{s.cat2 || s.cat1 || '-'}</td>
						<td>{s.smallGroup || '-'}</td>
					</tr>
				{:else}
					<tr><td colspan="6" class="muted">조건에 맞는 교인이 없습니다.</td></tr>
				{/each}
			</tbody>
		</table>
		{#if rows.length > shown}<div style="padding:.7rem;text-align:center"><button class="ghost sm" onclick={() => (shown += 200)}>더 보기 ({rows.length - shown}명 남음)</button></div>{/if}
	</div>

	{#if selId}<button type="button" class="pane-back" aria-label="상세 닫기" onclick={() => (selId = '')}></button>{/if}
	<aside class="card detail-pane" class:open={!!selId}>
		{#if selId}<button type="button" class="ghost sm pane-close" onclick={() => (selId = '')}>닫기 ✕</button>{/if}
		{#if sel && selSummary}
			{@const p = sel}
			{@const s = selSummary}
			{@const hh = registry.householdOf(p.id)}
			<div class="row" style="flex-wrap:nowrap;gap:.9rem;margin-bottom:.8rem">
				{#if p.photo}<img class="mugshot2" src={p.photo} alt="{p.name} 사진" />{:else}<span class="avatar" style="width:64px;height:64px;font-size:1.5rem;border-radius:16px">{p.name.slice(0, 1)}</span>{/if}
				<div>
					<h2 style="margin:0">{p.name}{#if p.name_note}<small class="muted"> ({p.name_note})</small>{/if} {#if s.office}<small class="office-k">{officeWithKind(s.office, p.clergy_title)}</small>{/if}</h2>
					<span class="badge {s.status === '재적' ? '' : s.status === '보류' ? 'inactive' : 'transferred'}">{s.status}</span>
					
				</div>
			</div>
			<dl class="compact">
				<dt>교인구분</dt><dd>{s.category || '-'}</dd>
				<dt>생년월일</dt><dd>{p.birth_date ?? '-'}{p.birth_lunar ? ' (음력)' : ''} · {ageText(p.birth_date)} · {p.gender === 'M' ? '남' : p.gender === 'F' ? '여' : '-'}</dd>
				<dt>휴대폰</dt><dd>{p.phone ?? '-'}{p.sms_opt_out ? ' (문자 수신 거부)' : ''}</dd>
				<dt>집전화</dt><dd>{hh?.home_phone ?? '-'}</dd>
				<dt>주소</dt><dd>{[p.address1, p.address2].filter(Boolean).join(' ') || hh?.address || '-'}</dd>
				<dt>{registry.t2}</dt><dd>{s.community ? `${s.community} › ${s.smallGroup}` : '-'}</dd>
				<dt>전도회</dt><dd>{p.mission_society ?? '-'}</dd>
				<dt>직분</dt><dd>{s.office ? officeWithKind(s.office, p.clergy_title) : '성도'}</dd>
				<dt>신급</dt><dd>{s.faith}</dd>
				<dt>등록일</dt><dd>{s.registered ?? '미상'}{p.reg_no ? ` · 교번 ${p.reg_no}` : ''}</dd>
				<dt>인도자</dt><dd>{#if p.guide_person_id}{registry.person(p.guide_person_id)?.name}{:else}-{/if}</dd>
				<dt>결혼</dt><dd>{p.marital_status ?? '-'}{p.wedding_date ? ` · ${p.wedding_date}` : ''}</dd>
				<dt>직업 / 직장</dt><dd>{[p.occupation, p.workplace].filter(Boolean).join(' / ') || '-'}</dd>
				{#if p.car_number}<dt>차량 번호</dt><dd>{p.car_number}</dd>{/if}
			</dl>
			{#if selFamily.length}
				<h3 class="mini-h">세대 구성 · {hh?.no}</h3>
				<table class="fam">
					<thead><tr><th>관계</th><th>이름</th><th>나이</th><th>직분 · 신급</th></tr></thead>
					<tbody>
						{#each selFamily as m (m.id)}
							{@const fp = m.person_id ? registry.person(m.person_id) : undefined}
							<tr class:me={m.person_id === p.id}>
								<td>{m.relation}</td>
								<td>{#if fp}<button type="button" class="linkish" onclick={() => (selId = fp.id)}>{fp.name}</button>{:else}{m.name}{/if}</td>
								<td>{fp ? (ageOf(fp.birth_date) ?? '-') : m.birth_year ? new Date().getFullYear() - m.birth_year : '-'}</td>
								<td>{#if fp}{registry.summary(fp).title || registry.summary(fp).faith}{:else}<span class="muted">교인 아님</span>{/if}</td>
							</tr>
						{/each}
					</tbody>
				</table>
			{/if}
			{#if p.note}<h3 class="mini-h">기타 메모</h3><p class="memo-box">{p.note}</p>{/if}
			{#if selNotes.length}<h3 class="mini-h">담당자 메모</h3>{#each selNotes as n}<p class="muted" style="margin:.2rem 0">{n.created_at} · {n.body}</p>{/each}{/if}
			{#if registry.perms.visit && selVisits.length}<h3 class="mini-h">최근 심방</h3>{#each selVisits as v}<p class="muted" style="margin:.2rem 0">{v.visit_date} · {v.kind} · {v.pastor} — {v.content}</p>{/each}{/if}
			<div class="row" style="margin-top:1rem"><a class="btn" href={hrefTo(`/${p.id}`)}>교적 카드 열기</a>{#if registry.perms.edit}<a class="btn ghost" href={hrefTo(`/${p.id}?edit=1`)}>수정</a>{/if}</div>
		{:else}
			<p class="empty">왼쪽 목록에서 교인을 누르면<br />여기에 자세한 내용이 나옵니다.</p>
		{/if}
	</aside>
</div>

{#if registry.perms.export}
	<div class="card">
		<h2>내려받기 <span class="muted" style="font-weight:400">— 필요한 칸만 골라 엑셀로 · 용도를 적어야 함(실제 시스템)</span></h2>
		<div class="cols">
			{#each Object.keys(COLS) as c}<label><input type="checkbox" value={c} bind:group={picked} />{registry.tr(c)}</label>{/each}
		</div>
		<p class="row" style="align-items:center"><ExportButtons onexcel={download} disabled={!picked.length} busy={xlBusy} /><span class="muted">현재 목록 {rows.length}명</span></p>
	</div>
{:else}
	<p class="muted">내려받기 권한이 꺼져 있습니다. (최고관리자에게 내려받기·출력 권한을 받아야 합니다)</p>
{/if}

{#if smsOpen}<SmsDialog people={rows.map(({ p }) => p)} onclose={() => (smsOpen = false)} />{/if}

<style>
	/* 일괄 수정 상자: 선택한 교인이 있으면 파란 테두리로 강조 */
	th.ck, td.ck { width: 2.2rem; text-align: center; padding-left: 0.6rem; padding-right: 0.2rem; }
	td.ck input, th.ck input { width: auto; cursor: pointer; }
	tr.checked td { background: var(--primary-50); }
	/* 문자 보내기 버튼: 초록 → 하늘색, 말풍선 아이콘과 인원 */
	.sms-btn { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.62rem 1rem; border: 0; border-radius: 12px; color: #fff; font-weight: 700; background: linear-gradient(135deg, #14b4ae, #4558ae); box-shadow: 0 6px 16px rgba(20, 180, 174, 0.28); }
	.sms-btn:hover:not(:disabled) { filter: brightness(1.06); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(20, 180, 174, 0.35); }
	.m-search { flex: 1; min-width: 0; box-shadow: none; }
	.sms-btn svg { width: 20px; height: 20px; fill: #fff; }
	.sms-btn svg circle { fill: #14b4ae; }
	.sms-btn .cnt { padding: 0.05rem 0.5rem; border-radius: 999px; background: rgba(255, 255, 255, 0.25); font-size: 0.78rem; }
	.on-bulk { background: var(--primary-50); border-color: var(--primary); color: var(--primary-600); }
</style>