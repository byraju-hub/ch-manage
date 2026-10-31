<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 교인 목록 (항목 설정): 상세 화면 없이 보는 목록. 교인 등록 때 적는 칸 중 고른 칸만 보임.
	// 보기 형식: 한 줄 보기(표) / 카드 보기(왼쪽 사진·이름, 오른쪽 항목). 정렬, 보이는 그대로 엑셀 내려받기.
	// 고른 칸과 보기 형식은 이 브라우저에 기억해 둠
	import { registry } from '#lib/registry/store.svelte.ts';
	import ExportButtons from '#lib/components/ExportButtons.svelte';
	import BulkEditPanel from '#lib/components/BulkEditPanel.svelte';
	import { ageOf, officeKindTitle } from '#lib/registry/derive.ts';
	import type { Person } from '#lib/registry/types.ts';

	type Sum = ReturnType<typeof registry.summary>;
	interface Col { label: string; get: (p: Person, s: Sum) => string; num?: (p: Person) => number | null; w?: number; photo?: boolean }
	const hhOf = (p: Person) => registry.householdOf(p.id);
	const relOf = (p: Person) => { const h = hhOf(p); return h ? registry.membersOfHousehold(h.id).find((m) => m.person_id === p.id)?.relation ?? '' : ''; };
	const COLS: Col[] = [
		{ label: '사진', get: () => '', photo: true },
		{ label: '이름', get: (p) => p.name, w: 10 },
		{ label: '직분', get: (p, s) => (s.office ? officeKindTitle(s.office, p.clergy_title) : ''), w: 10 },
		{ label: '동명이인 구분', get: (p) => p.name_note ?? '' },
		{ label: '성별', get: (p) => (p.gender === 'M' ? '남' : p.gender === 'F' ? '여' : ''), w: 6 },
		{ label: '생년월일', get: (p) => p.birth_date ?? '', w: 12 },
		{ label: '양력/음력', get: (p) => (p.birth_date ? (p.birth_lunar ? '음력' : '양력') : ''), w: 8 },
		{ label: '나이', get: (p) => String(ageOf(p.birth_date) ?? ''), num: (p) => ageOf(p.birth_date), w: 6 },
		{ label: '휴대폰', get: (p) => p.phone ?? '', w: 15 },
		{ label: '집전화', get: (p) => hhOf(p)?.home_phone ?? '', w: 14 },
		{ label: '이메일', get: (p) => p.email ?? '', w: 22 },
		{ label: '우편번호', get: (p) => p.address_zip ?? hhOf(p)?.zip ?? '', w: 8 },
		{ label: '주소', get: (p) => [p.address1, p.address2].filter(Boolean).join(' ') || hhOf(p)?.address || '', w: 40 },
		{ label: '세대 번호', get: (p) => hhOf(p)?.no ?? '', w: 10 },
		{ label: '세대 관계', get: (p) => relOf(p), w: 8 },
		{ label: '교역자 직책', get: (p) => p.clergy_title ?? '' },
		{ label: '교인구분', get: (_, s) => s.category, w: 24 },
		{ label: '상태', get: (_, s) => s.status, w: 8 },
		{ label: '신급', get: (_, s) => s.faith, w: 10 },
		{ label: '등록일', get: (_, s) => s.registered ?? '', w: 12 },
		{ label: '공동체', get: (_, s) => s.community, w: 12 },
		{ label: '소그룹', get: (_, s) => s.smallGroup, w: 12 },
		{ label: '교육부서', get: (p) => p.edu_dept ?? '' },
		{ label: '전도회', get: (p) => p.mission_society ?? '' },
		{ label: '직업', get: (p) => p.occupation ?? '' },
		{ label: '직장', get: (p) => p.workplace ?? '', w: 16 },
		{ label: '차량 번호', get: (p) => p.car_number ?? '' },
		{ label: '결혼 여부', get: (p) => p.marital_status ?? '', w: 8 },
		{ label: '결혼기념일', get: (p) => p.wedding_date ?? '' },
		{ label: '인도자', get: (p) => registry.person(p.guide_person_id ?? '')?.name ?? p.guide_name ?? '' },
		{ label: '이전 출석 교회', get: (p) => p.previous_church ?? '', w: 16 },
		{ label: '교적 번호', get: (p) => p.reg_no ?? '', w: 10 },
		{ label: '앱 가입', get: (p) => (p.has_account ? '가입' : ''), w: 8 },
		{ label: '문자 수신', get: (p) => (p.sms_opt_out ? '거부' : '받음'), w: 8 },
		{ label: '메모', get: (p) => p.note ?? '', w: 30 }
	];
	const DEFAULT = ['이름', '직분', '생년월일', '휴대폰', '집전화', '주소'];
	const STORE = 'members-columns-v1';
	const load = (): string[] => {
		try { const v = JSON.parse(localStorage.getItem(STORE) ?? 'null'); if (Array.isArray(v) && v.length) return v.filter((x) => COLS.some((c) => c.label === x)); } catch {}
		return DEFAULT;
	};
	let picked = $state<string[]>(load());
	$effect(() => { try { localStorage.setItem(STORE, JSON.stringify(picked)); } catch {} });
	const cols = $derived(COLS.filter((c) => picked.includes(c.label)));
	const showPhoto = $derived(picked.includes('사진'));
	// 카드 보기에서 오른쪽에 늘어놓는 항목 (사진·이름은 왼쪽에 따로)
	const cardCols = $derived(cols.filter((c) => !c.photo && c.label !== '이름'));
	const VIEW = 'members-columns-view-v1';
	let view = $state<'list' | 'card'>((() => { try { return localStorage.getItem(VIEW) === 'card' ? 'card' : 'list'; } catch { return 'list'; } })());
	$effect(() => { try { localStorage.setItem(VIEW, view); } catch {} });
	let setOpen = $state(false);

	// 찾기 · 상태
	let q = $state('');
	let status = $state('재적');
	const statuses = ['재적', '보류', '보관', '전출', '별세', '제적', '미등록'];
	const hit = $derived(registry.matcher(q));
	// 교인구분 3단계 (예: 장년 › 예배출석, 장기결석자만 모으기)
	let c1 = $state('');
	let c2 = $state('');
	let c3 = $state('');
	const l1opts = $derived(registry.childrenOf(null));
	const l2opts = $derived([...new Set(registry.d.categories.filter((c) => c.parent_id && registry.d.categories.find((x) => x.id === c.parent_id)?.parent_id === null && (!c1 || registry.d.categories.find((x) => x.id === c.parent_id)?.name === c1)).map((c) => c.name))]);
	const l3opts = $derived([...new Set(registry.d.categories.filter((c) => { const par = registry.d.categories.find((x) => x.id === c.parent_id); return !!par?.parent_id && (!c2 || par.name === c2); }).map((c) => c.name))]);
	const base = $derived(
		registry.d.people
			.map((p) => ({ p, s: registry.summary(p) }))
			.filter(({ p, s }) => (!status || s.status === status) && (!c1 || s.cat1 === c1) && (!c2 || s.cat2 === c2) && (!c3 || s.cat3 === c3) && (!hit || hit(registry.personHay(p))))
	);

	// 정렬: 칸 제목을 누르면 오름차순 → 내림차순. 빈 칸은 늘 맨 뒤
	let sortBy = $state('이름');
	let asc = $state(true);
	function sortOn(label: string) {
		if (sortBy === label) asc = !asc;
		else { sortBy = label; asc = true; }
	}
	const rows = $derived.by(() => {
		const c = COLS.find((x) => x.label === sortBy) ?? COLS[0];
		const key = base.map((r) => ({ r, v: c.get(r.p, r.s), n: c.num?.(r.p) ?? null }));
		key.sort((a, b) => {
			const ea = a.v === '', eb = b.v === '';
			if (ea !== eb) return ea ? 1 : -1;
			const d = c.num ? (a.n ?? 0) - (b.n ?? 0) : a.v.localeCompare(b.v, 'ko', { numeric: true });
			return (asc ? d : -d) || a.r.p.name.localeCompare(b.r.p.name, 'ko');
		});
		return key.map((k) => k.r);
	});
	const STEP = $derived(view === 'card' ? 60 : 300);
	let shown = $state(300);
	$effect(() => { void q; void status; void c1; void c2; void c3; shown = STEP; });

	// 일괄 수정 (최고관리자만): 버튼을 누르면 체크칸이 생기고, 체크한 교인을 한꺼번에 바꿈
	let bulkOpen = $state(false);
	const bulkOn = $derived(bulkOpen && registry.isSuper);
	let checked = $state<Set<string>>(new Set());
	const checkedIds = $derived(rows.filter(({ p }) => checked.has(p.id)).map(({ p }) => p.id));
	const allChecked = $derived(rows.length > 0 && checkedIds.length === rows.length);
	function toggle(id: string) { const n = new Set(checked); if (n.has(id)) n.delete(id); else n.add(id); checked = n; }
	function toggleAll() { checked = allChecked ? new Set() : new Set(rows.map(({ p }) => p.id)); }

	// 엑셀: 보이는 칸 · 보이는 순서 그대로
	let busy = $state(false);
	async function excel() {
		busy = true;
		try {
			const { default: writeXlsxFile } = await import('write-excel-file/browser');
			const st = { borderStyle: 'thin' as const, borderColor: '#999999', alignVertical: 'center' as const };
			const xcols = cols.filter((c) => !c.photo); // 사진은 엑셀에 넣지 않음
			const data = [
				xcols.map((c) => ({ value: registry.tr(c.label), fontWeight: 'bold' as const, backgroundColor: '#E0E4F4', align: 'center' as const, ...st })),
				...rows.map(({ p, s }) => xcols.map((c) => ({ value: c.get(p, s), ...st })))
			];
			await writeXlsxFile(data, { sheet: '교인 목록', columns: xcols.map((c) => ({ width: c.w ?? 12 })), stickyRowsCount: 1 }).toFile(`교인목록-${new Date().toISOString().slice(0, 10)}.xlsx`);
			registry.log('내려받기', `교인 목록(항목 설정) ${rows.length}명 · ${xcols.map((c) => c.label).join('/')}`);
		} finally {
			busy = false;
		}
	}
</script>

<div class="row page-head" style="justify-content:space-between">
	<h1>교인 목록 항목 설정 <small class="muted">({rows.length}명)</small></h1>
	<a class="btn ghost" href={hrefTo('/members')}>← 교인 목록</a>
</div>

<div class="card">
	<div class="toolbar">
		<div class="gs-box m-search">
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
			<input type="search" placeholder="무엇이든 검색하세요! (이름 · 전화 · {registry.t2} · 직분 · 주소 · 직업 · 차량 번호 · 메모 등)" aria-label="교인 검색" bind:value={q} />
		</div>
	</div>
	<div class="toolbar">
		<select bind:value={c1} onchange={() => { c2 = ''; c3 = ''; }} style="width:auto"><option value="">교인구분 전체</option>{#each l1opts as o}<option>{o.name}</option>{/each}</select>
		<select bind:value={c2} onchange={() => (c3 = '')} style="width:auto"><option value="">2단계 전체</option>{#each l2opts as o}<option>{o}</option>{/each}</select>
		<select bind:value={c3} style="width:auto"><option value="">3단계 전체</option>{#each l3opts as o}<option>{o}</option>{/each}</select>
		<select bind:value={status} style="width:auto"><option value="">상태 전체</option>{#each statuses as x}<option>{x}</option>{/each}</select>
	</div>
	<div class="toolbar">
		<button type="button" class="ghost" onclick={() => (setOpen = !setOpen)} aria-expanded={setOpen}>{setOpen ? '항목 고르기 닫기' : `보일 항목 고르기 (${picked.length})`}</button>
		<div class="seg" role="tablist" aria-label="보기 형식">
			<button type="button" class:on={view === 'list'} onclick={() => { view = 'list'; shown = 300; }}>한 줄 보기</button>
			<button type="button" class:on={view === 'card'} onclick={() => { view = 'card'; shown = 60; }}>카드 보기</button>
		</div>
		<label class="row sortsel" style="gap:.35rem">정렬
			<select bind:value={sortBy} style="width:auto">{#each cols.filter((c) => !c.photo) as c}<option value={c.label}>{registry.tr(c.label)}</option>{/each}</select>
			<button type="button" class="ghost sm" onclick={() => (asc = !asc)} title="순서 바꾸기">{asc ? '▲ 오름차순' : '▼ 내림차순'}</button>
		</label>
		<span style="flex:1"></span>
		{#if registry.isSuper}<button type="button" class="ghost" class:on-bulk={bulkOpen} onclick={() => { bulkOpen = !bulkOpen; if (!bulkOpen) checked = new Set(); }} aria-expanded={bulkOpen}>{bulkOpen ? '일괄 수정 닫기' : '일괄 수정'}</button>{/if}
		{#if registry.perms.export}<ExportButtons onexcel={excel} disabled={!rows.length || !cols.length} {busy} />{/if}
	</div>
	{#if setOpen}
		<div class="col-set">
			<div class="cols">{#each COLS as c}<label><input type="checkbox" value={c.label} bind:group={picked} />{registry.tr(c.label)}</label>{/each}</div>
			<div class="row" style="margin-top:.6rem">
				<button type="button" class="ghost sm" onclick={() => (picked = COLS.map((c) => c.label))}>모두</button>
				<button type="button" class="ghost sm" onclick={() => (picked = [...DEFAULT])}>기본 (이름 · 직분 · 생년월일 · 휴대폰 · 집전화 · 주소)</button>
				<span class="muted" style="font-size:.8rem">고른 항목은 이 브라우저에 기억됩니다.</span>
			</div>
		</div>
	{/if}
</div>

{#if bulkOn}
	<BulkEditPanel ids={checkedIds} onclear={() => (checked = new Set())} onclose={() => { bulkOpen = false; checked = new Set(); }} />
{/if}

<div class="card" style="padding:0">
	<p class="result-count"><span class="rc-mark">◎</span> 검색 결과 총 <b>{rows.length.toLocaleString()}</b>명{#if rows.length > shown}<span class="muted"> · 지금 {shown.toLocaleString()}명까지 보임</span>{/if}</p>
	{#if view === 'list'}
		<div class="wide-table">
			<table class="sm ctable">
				<thead>
					<tr>
						{#if bulkOn}<th class="ck"><input type="checkbox" checked={allChecked} indeterminate={checkedIds.length > 0 && !allChecked} onchange={toggleAll} aria-label="검색 결과 전체 선택" title="검색 결과 {rows.length}명 전체 선택" /></th>{/if}
						<th class="no">번호</th>
						{#each cols as c (c.label)}
							<th>{#if c.photo}{c.label}{:else}<button type="button" class="sort" class:on={sortBy === c.label} onclick={() => sortOn(c.label)} title="{registry.tr(c.label)}(으)로 정렬">{registry.tr(c.label)}<i>{sortBy === c.label ? (asc ? '▲' : '▼') : '↕'}</i></button>{/if}</th>
						{/each}
					</tr>
				</thead>
				<tbody>
					{#each rows.slice(0, shown) as { p, s }, i (p.id)}
						<tr class:checked={checked.has(p.id)}>
							{#if bulkOn}<td class="ck"><input type="checkbox" checked={checked.has(p.id)} onchange={() => toggle(p.id)} aria-label="{p.name} 선택" /></td>{/if}
							<td class="no">{i + 1}</td>
							{#each cols as c (c.label)}
								<td class:ph-cell={c.photo}>{#if c.photo}{#if p.photo}<img class="ph-s" src={p.photo} alt="{p.name} 사진" />{:else}<span class="ph-s none">{p.name.slice(0, 1)}</span>{/if}{:else if c.label === '이름'}<a href={hrefTo(`/${p.id}`)}><b>{p.name}</b></a>{:else}{c.get(p, s)}{/if}</td>
							{/each}
						</tr>
					{:else}
						<tr><td colspan={cols.length + (bulkOn ? 2 : 1)} class="empty">해당하는 교인이 없습니다.</td></tr>
					{/each}
				</tbody>
			</table>
		</div>
	{:else}
		<!-- 카드 보기: 왼쪽 번호 · 사진 · 이름, 오른쪽에 고른 항목을 세 묶음으로 -->
		{#each rows.slice(0, shown) as { p, s }, i (p.id)}
			<div class="pcard" class:checked={checked.has(p.id)}>
				<div class="pc-no">{#if bulkOn}<input type="checkbox" checked={checked.has(p.id)} onchange={() => toggle(p.id)} aria-label="{p.name} 선택" />{/if}{i + 1}</div>
				<div class="pc-left">
					{#if showPhoto}
						{#if p.photo}<img class="ph-l" src={p.photo} alt="{p.name} 사진" />{:else}<span class="ph-l none">{p.name.slice(0, 1)}</span>{/if}
					{/if}
					<a href={hrefTo(`/${p.id}`)} class="pc-name"><b>{p.name}</b>{#if s.office}<small> {officeKindTitle(s.office, p.clergy_title)}</small>{/if}</a>
				</div>
				<dl class="pc-grid">
					{#each cardCols as c (c.label)}<dt>{registry.tr(c.label)}</dt><dd>{c.get(p, s) || '-'}</dd>{/each}
				</dl>
				<a class="btn ghost xs pc-open" href={hrefTo(`/${p.id}`)}>교적 카드</a>
			</div>
		{:else}
			<p class="empty">해당하는 교인이 없습니다.</p>
		{/each}
	{/if}
	{#if rows.length > shown}<p style="text-align:center;padding:.6rem"><button type="button" class="ghost" onclick={() => (shown += STEP)}>더 보기 ({rows.length - shown}명 남음)</button></p>{/if}
</div>

<style>
	.m-search { flex: 1; min-width: 0; box-shadow: none; }
	.rc-mark { color: var(--orange); }
	.on-bulk { background: var(--primary-50); border-color: var(--primary); color: var(--primary-600); }
	.ctable th.ck, .ctable td.ck { width: 2.2rem; text-align: center; }
	.ck input, .pc-no input { width: auto; cursor: pointer; }
	.pc-no { display: flex; flex-direction: column; align-items: center; gap: 0.4rem; }
	tr.checked td, .pcard.checked { background: var(--primary-50); }
	.result-count { margin: 0; padding: 0.8rem 1.3rem; border-bottom: 1px solid var(--line); font-size: 0.92rem; }
	.result-count b { color: var(--primary-600); font-size: 1.05rem; }
	.result-count .muted { font-size: 0.82rem; }
	.col-set { padding: 0.8rem 0.9rem; border-radius: 12px; background: var(--primary-50); }
	.col-set .cols { gap: 0.45rem 1.1rem; }
	.sortsel { font-size: 0.85rem; color: var(--muted); font-weight: 600; }
	.wide-table { overflow-x: auto; }
	.ctable th, .ctable td { white-space: nowrap; }
	/* 칸과 칸 사이 옅은 세로 구분선 */
	.ctable th + th, .ctable td + td { border-left: 1px solid var(--line); }
	.ctable th.no, .ctable td.no { width: 3rem; text-align: center; color: var(--muted); }
	.ctable td.ph-cell { padding-top: 0.3rem; padding-bottom: 0.3rem; }
	.sort { padding: 0; background: none; border: 0; box-shadow: none; color: inherit; font: inherit; font-weight: 600; display: inline-flex; gap: 0.3rem; align-items: center; }
	.sort:hover { color: var(--primary-600); background: none; transform: none; box-shadow: none; filter: none; }
	.sort.on { color: var(--primary-600); }
	.sort i { font-style: normal; font-size: 0.7rem; opacity: 0.6; }
	/* 사진: 한 줄 보기는 작게, 카드 보기는 증명사진 크기 */
	.ph-s, .ph-l { display: block; object-fit: cover; border: 1px solid var(--line-strong); background: var(--primary-50); }
	.ph-s { width: 30px; height: 40px; border-radius: 5px; }
	.ph-l { width: 84px; height: 108px; border-radius: 8px; }
	.none { display: grid; place-items: center; color: var(--primary-600); font-weight: 800; }
	.ph-s.none { font-size: 0.85rem; }
	.ph-l.none { font-size: 1.8rem; }
	.pcard { display: grid; grid-template-columns: 2.4rem 7rem 1fr auto; gap: 1rem; align-items: start; padding: 1rem 1.2rem; border-bottom: 1px solid var(--line); }
	.pcard:last-of-type { border-bottom: 0; }
	.pc-no { color: var(--muted); font-size: 0.85rem; padding-top: 0.2rem; text-align: center; }
	.pc-left { display: flex; flex-direction: column; align-items: center; gap: 0.45rem; text-align: center; }
	.pc-name { color: var(--text); line-height: 1.3; }
	.pc-name small { display: block; color: var(--primary-600); font-weight: 600; font-size: 0.78rem; }
	/* 항목 칸: 바깥 테두리 없이, 안쪽의 줄(행)과 묶음(열) 사이에만 옅은 구분선 */
	.pc-grid { --n: 3; display: grid; grid-template-columns: repeat(3, 6.5rem minmax(0, 1fr)); gap: 0; margin: 0; font-size: 0.88rem; align-content: start; }
	.pc-grid dt, .pc-grid dd { margin: 0; padding: 0.38rem 0.6rem; border-top: 1px solid var(--line); }
	.pc-grid dt { color: var(--muted); font-weight: 700; font-size: 0.8rem; }
	.pc-grid dd { overflow-wrap: anywhere; position: relative; }
	/* 항목 이름과 내용 사이: 옅은 주황의 짧은 세로선 (|) */
	.pc-grid dd::before { content: ''; position: absolute; left: 0; top: 28%; bottom: 28%; width: 1.5px; border-radius: 1px; background: color-mix(in srgb, var(--orange) 40%, #fff); }
	.pc-grid > :nth-child(-n + 6) { border-top: 0; } /* 첫 줄 위에는 선 없음 */
	.pc-grid dt:not(:nth-child(6n + 1)) { border-left: 1px solid var(--line); } /* 묶음 사이 세로선 */
	@media (max-width: 1000px) {
		.pc-grid { grid-template-columns: repeat(2, 6rem minmax(0, 1fr)); }
		.pc-grid > :nth-child(-n + 6) { border-top: 1px solid var(--line); }
		.pc-grid > :nth-child(-n + 4) { border-top: 0; }
		.pc-grid dt:not(:nth-child(6n + 1)) { border-left: 0; }
		.pc-grid dt:nth-child(4n + 3) { border-left: 1px solid var(--line); }
	}
	@media (max-width: 720px) {
		.pcard { grid-template-columns: 1fr; gap: 0.6rem; padding: 0.9rem; }
		.pc-no { text-align: left; }
		.pc-left { flex-direction: row; text-align: left; }
		.pc-grid { grid-template-columns: 5.5rem minmax(0, 1fr); }
		.pc-grid > :nth-child(-n + 4) { border-top: 1px solid var(--line); }
		.pc-grid > :nth-child(-n + 2) { border-top: 0; }
		.pc-grid dt:nth-child(4n + 3) { border-left: 0; }
		.pc-open { justify-self: start; }
	}
</style>
