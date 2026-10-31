<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 교회 조직표: 당회 · 시무집사회 · 시무권사회 · 남/여전도회 · 교회 조직표
	import { registry } from '#lib/registry/store.svelte.ts';
	import { withPdfStyle } from '#lib/pdf-style.ts';
	import ExportButtons from '#lib/components/ExportButtons.svelte';

	// 순서: 교회 조직표가 맨 앞이고, 상단 메뉴 '조직'을 누르면 교회 조직표가 먼저 보임
	const tabs = ['교회 조직표', '당회', '시무집사회', '시무권사회', '서리집사', '남전도회', '여전도회'];
	const YEAR = new Date().getFullYear();
	let tab = $state('교회 조직표');
	let pickedSociety = $state('1남전도회');
	let pickedOrg = $state('');

	// 직분회: 지금 시무 중인 사람만
	const holders = (office: string) =>
		registry.d.officeTerms
			.filter((t) => t.office === office && !t.end_date && t.rank_status === '시무')
			.map((t) => ({ t, p: registry.person(t.person_id)!, s: registry.summary(registry.person(t.person_id)!) }))
			.filter((x) => x.p && x.s.memberCert)
			.sort((a, b) => (a.t.start_date ?? '').localeCompare(b.t.start_date ?? '') || a.p.name.localeCompare(b.p.name, 'ko'));
	const others = (office: string) =>
		registry.d.officeTerms
			.filter((t) => t.office === office && !t.end_date && t.rank_status !== '시무' && t.rank_status !== '사임')
			.map((t) => ({ t, p: registry.person(t.person_id)! }))
			.filter((x) => x.p);

	const society = $derived(
		(name: string) => registry.d.people.filter((p) => p.mission_society === name && registry.summary(p).memberCert)
	);
	const males = $derived(registry.codes.missionSocieties.filter((s) => s.includes('남')));
	const females = $derived(registry.codes.missionSocieties.filter((s) => s.includes('여')));
	const count = (name: string) => society(name).length;

	const units = $derived(registry.d.orgUnits);
	const committees = $derived(units.filter((u) => !u.parent_id).sort((a, b) => a.sort_order - b.sort_order));
	const deptMembers = (name: string) => registry.d.affiliations.filter((a) => a.org === name && !a.end_date);
	const nameOf = (id: string) => registry.person(id)?.name ?? '';

	// 당회: 당회 서기 → 교회 조직표 순서의 위원장 → 나머지 시무장로(임직 순). 역할은 카드에 함께 표시
	const office = $derived(tab === '당회' ? '장로' : tab === '시무집사회' ? '시무집사' : '권사');
	const roles = $derived.by(() => {
		const m = new Map<string, string>();
		if (tab !== '당회') return m;
		const sec = registry.d.meetings.find((x) => x.meeting === '당회')?.secretary.split(' ')[0];
		const s = holders('장로').find((x) => x.p.name === sec);
		if (s) m.set(s.p.id, '당회 서기');
		for (const c of committees) if (c.leader_id) m.set(c.leader_id, [m.get(c.leader_id), `${c.name} 위원장`].filter(Boolean).join(' · '));
		return m;
	});
	const officeList = $derived.by(() => {
		const list = holders(office);
		if (tab !== '당회') return list;
		const order = [...roles.keys()];
		const rank = (id: string) => (order.includes(id) ? order.indexOf(id) : order.length);
		return list.sort((a, b) => rank(a.p.id) - rank(b.p.id));
	});
	const STATUS_ORDER = ['원로', '은퇴', '협동', '휴직', '명예', '전입'];
	const restList = $derived(others(office).sort((a, b) => STATUS_ORDER.indexOf(a.t.rank_status) - STATUS_ORDER.indexOf(b.t.rank_status) || a.p.name.localeCompare(b.p.name, 'ko')));
	const restTitle = $derived(tab === '당회' ? '시무장로 외 장로 명단' : tab === '시무집사회' ? '시무집사 외 집사 명단' : '시무권사 외 권사 명단');
	const restHint = $derived(tab === '당회' ? '원로 · 은퇴 · 협동 · 휴직' : tab === '시무집사회' ? '은퇴 · 협동 · 전입' : '은퇴 · 협동 · 전입 · 명예');

	// 서리집사: 올해 임명된(끝나지 않은) 서리·명예집사를 남자 서리집사 · 남자 명예집사 · 여자 서리집사로 나눔
	const deaconBoxes = $derived.by(() => {
		const terms = registry.d.officeTerms.filter((t) => t.office === '서리집사' && !t.end_date);
		const pick = (status: string, gender: 'M' | 'F') => {
			const ids = new Set(terms.filter((t) => t.rank_status === status).map((t) => t.person_id));
			return [...ids].map((id) => registry.person(id)).filter((p) => !!p && p.gender === gender && registry.summary(p).memberCert)
				.map((p) => ({ p: p!, s: registry.summary(p!) })).sort((a, b) => a.p.name.localeCompare(b.p.name, 'ko'));
		};
		return [
			{ title: '남자 서리집사', list: pick('서리', 'M') },
			{ title: '남자 명예집사', list: pick('명예', 'M') },
			{ title: '여자 서리집사', list: pick('서리', 'F') }
		];
	});
	const memberTitle = $derived(office === '시무집사' ? '시무집사' : office === '장로' ? '시무장로' : '시무권사');

	// ---- 인쇄 · 엑셀 · PDF: 지금 탭의 명단 (전도회는 그 탭의 전도회 전체) ----
	type Sec = { title: string; heads: string[]; rows: (string | number)[][] };
	const today = new Date().toISOString().slice(0, 10);
	const sections = $derived.by((): Sec[] => {
		if (tab === '남전도회' || tab === '여전도회') {
			return (tab === '남전도회' ? males : females).map((n) => ({
				title: n,
				heads: ['번호', '이름', '직분', '나이', registry.t2, '휴대폰'],
				rows: society(n).map((p) => ({ p, s: registry.summary(p) })).sort((a, b) => a.p.name.localeCompare(b.p.name, 'ko'))
					.map(({ p, s }, i) => [i + 1, p.name, s.title || '', p.birth_date ? new Date().getFullYear() - +p.birth_date.slice(0, 4) : '', s.smallGroup || '', p.phone ?? ''])
			}));
		}
		if (tab === '교회 조직표') return [];
		if (tab === '서리집사') {
			return deaconBoxes.map((b) => ({
				title: `${YEAR}년 ${b.title} (총 ${b.list.length}명)`,
				heads: ['번호', '이름', registry.t1, registry.t2],
				rows: b.list.map(({ p, s }, i) => [i + 1, p.name, s.community || '', s.smallGroup || ''])
			}));
		}
		const heads = ['번호', '이름', '직분', '비고', '임직일', '임직교회'];
		const out: Sec[] = [{ title: `${tab} (${officeList.length}명)`, heads, rows: officeList.map(({ t, p }, i) => [i + 1, p.name, memberTitle, roles.get(p.id) ?? '', t.start_date ?? '', t.church ?? '본 교회']) }];
		if (restList.length) out.push({ title: `${restTitle} (${restList.length}명)`, heads, rows: restList.map(({ t, p }, i) => [i + 1, p.name, `${t.rank_status}${office === '시무집사' ? '집사' : office}`, '', t.start_date ?? '', t.church ?? '본 교회']) });
		return out;
	});
	let sheet = $state<HTMLElement>();
	let busy = $state(false);
	const fileBase = $derived(`${tab}-${today}`);
	function printNow() {
		registry.log('출력', `조직 · ${tab} 인쇄`);
		window.print();
	}
	async function excel() {
		busy = true;
		try {
			const writeXlsxFile = (await import('write-excel-file/browser')).default;
			const border = { borderStyle: 'thin' as const, borderColor: '#999999', align: 'center' as const, alignVertical: 'center' as const };
			const heads = ['구분', ...sections[0].heads];
			const data = [
				heads.map((h) => ({ value: h, fontWeight: 'bold' as const, backgroundColor: '#E9ECEF', ...border })),
				...sections.flatMap((sec) => sec.rows.map((r) => [{ value: sec.title.replace(/ \((총 )?\d+명\)$/, ''), ...border }, ...r.map((v) => (typeof v === 'number' ? { value: v, type: Number, ...border } : { value: v, ...border }))]))
			];
			await writeXlsxFile(data, { sheet: tab, columns: heads.map((h) => ({ width: h === '번호' || h === '나이' ? 6 : h === '비고' ? 22 : 13 })), stickyRowsCount: 1 }).toFile(`${fileBase}.xlsx`);
			registry.log('내려받기', `조직 · ${tab} 엑셀`);
		} finally {
			busy = false;
		}
	}
	async function pdf() {
		if (!sheet) return;
		busy = true;
		try {
			const html2pdf = (await import('html2pdf.js')).default;
			const opts = { margin: [20, 18, 20, 18], filename: `${fileBase}.pdf`, image: { type: 'jpeg', quality: 0.96 }, html2canvas: { scale: 2, useCORS: true }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }, pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.sec-head'] } };
			const el = sheet;
			await withPdfStyle(el, () => html2pdf().set(opts as unknown as Record<string, never>).from(el).save());
			registry.log('출력', `조직 · ${tab} PDF`);
		} finally {
			busy = false;
		}
	}
</script>

<div class="no-print">
<h1>{tab === '서리집사' ? `${YEAR}년 서리집사` : tab}</h1>
<div class="tabs">{#each tabs as t}<button class:on={tab === t} onclick={() => (tab = t)}>{t}</button>{/each}</div>

{#if tab !== '교회 조직표'}
	<div class="out-row">
		<ExportButtons onprint={printNow} onexcel={excel} onpdf={pdf} disabled={!registry.perms.export} {busy} title={registry.perms.export ? '' : '내려받기·출력 권한이 필요합니다'} />
	</div>
{/if}

{#if tab === '당회' || tab === '시무집사회' || tab === '시무권사회'}
	{@const list = officeList}
	<div class="card">
		<div class="card-head"><h2>{tab} <span class="tag">{office} 시무 {list.length}명</span></h2><span class="sub">{office === '장로' ? '당회 서기, 교회 조직표 순서의 위원장, 그 밖의 시무장로 순입니다.' : office === '시무집사' ? '집사 중 시무집사인 교인입니다.' : '권사 중 시무권사인 교인입니다.'}</span></div>
		<div class="org-grid">
			{#each list as { t, p } (t.id)}
				<a class="org-card" href={hrefTo(`/${p.id}`)} style="text-decoration:none"><b>{p.name} {office === '시무집사' ? '집사' : office}</b>{#if roles.get(p.id)}<em class="role">{roles.get(p.id)}</em>{/if}<span>임직 {t.start_date ?? '미상'}{t.church ? ` · ${t.church}` : ''}</span></a>
			{:else}<p class="empty">해당자가 없습니다.</p>{/each}
		</div>
	</div>
	{@const rest = restList}
	{#if rest.length}
		<div class="card">
			<h2>{restTitle} <span class="muted" style="font-weight:400">({restHint})</span></h2>
			<table class="sm"><thead><tr><th>이름</th><th>구분</th><th>시작</th><th>임직교회</th></tr></thead><tbody>
				{#each rest as { t, p } (t.id)}<tr><td><a href={hrefTo(`/${p.id}`)}>{p.name}</a></td><td>{t.rank_status}{office}</td><td>{t.start_date ?? '미상'}</td><td>{t.church ?? '본 교회'}</td></tr>{/each}
			</tbody></table>
		</div>
	{/if}
{:else if tab === '서리집사'}
	<div class="deacon-grid">
		{#each deaconBoxes as b}
			<section class="card deacon">
				<h2>{YEAR}년 {b.title}</h2>
				<div class="dnames">
					{#each b.list as { p } (p.id)}<a href={hrefTo(`/${p.id}`)}>{p.name}</a>{:else}<span class="muted">없음</span>{/each}
				</div>
				<p class="dtotal">총 <b>{b.list.length}</b>명</p>
			</section>
		{/each}
	</div>
{:else if tab === '남전도회' || tab === '여전도회'}
	{@const names = tab === '남전도회' ? males : females}
	<div class="card">
		<div class="card-head"><h2>{tab} <span class="tag">{names.length}개</span></h2><span class="sub">교인 카드의 '전도회' 칸으로 모입니다</span></div>
		<div class="org-grid">
			{#each names as n}
				<button type="button" class="org-card" class:on={pickedSociety === n} onclick={() => (pickedSociety = n)}><b>{n}</b><span>{count(n)}명</span></button>
			{/each}
		</div>
	</div>
	{#if names.includes(pickedSociety)}
		<div class="card" style="padding:0;overflow-x:auto">
			<table class="sm"><thead><tr><th>{pickedSociety}</th><th>직분</th><th>나이</th><th>{registry.t2}</th><th>휴대폰</th></tr></thead><tbody>
				{#each society(pickedSociety).map((p) => ({ p, s: registry.summary(p) })).sort((a, b) => a.p.name.localeCompare(b.p.name, 'ko')) as { p, s } (p.id)}
					<tr><td><a href={hrefTo(`/${p.id}`)}>{p.name}</a></td><td>{s.title || '-'}</td><td>{p.birth_date ? new Date().getFullYear() - +p.birth_date.slice(0, 4) : '-'}</td><td>{s.smallGroup || '-'}</td><td>{p.phone ?? '-'}</td></tr>
				{:else}<tr><td colspan="5" class="muted">회원이 없습니다.</td></tr>{/each}
			</tbody></table>
		</div>
	{/if}
{:else}
	<div class="org-head">
		<div><h2 style="margin:0">2026년도 교회 조직표</h2><span class="muted" style="font-size:.85rem">위원회 9개 · 부서 {units.filter((u) => u.parent_id).length}개 · 위원장 · 부서장 · 교역자는 '관리자 › 교회 설정'에서 바꿉니다</span></div>
		{#if registry.perms.export}<a class="btn ghost" href={hrefTo('/documents?doc=교회 조직표')}>조직표 출력</a>{/if}
	</div>

	<div class="card" style="padding:0;overflow-x:auto">
		<table class="sm meet">
			<thead><tr><th>회의</th><th>의장</th><th>서기</th></tr></thead>
			<tbody>{#each registry.d.meetings as m (m.meeting)}<tr><td><b>{m.meeting}</b></td><td>{m.chair}</td><td>{m.secretary}</td></tr>{/each}</tbody>
		</table>
	</div>

	<div class="org-grid2">
		{#each committees as c (c.id)}
			<section class="card committee">
				<div class="cm-head">
					<div><h3>{c.name}</h3><small>위원장 {#if c.leader_id}<a href={hrefTo(`/${c.leader_id}`)}>{nameOf(c.leader_id)}</a> 장로{:else}<span class="muted">미정</span>{/if}</small></div>
				</div>
				<table class="sm">
					<thead><tr><th>부서</th><th>부서장</th><th>교역자</th><th style="text-align:right">봉사</th></tr></thead>
					<tbody>
						{#each units.filter((u) => u.parent_id === c.id).sort((a, b) => a.sort_order - b.sort_order) as d (d.id)}
							<tr class="pick" class:on={pickedOrg === d.name} onclick={() => (pickedOrg = pickedOrg === d.name ? '' : d.name)}>
								<td><b>{d.name}</b></td>
								<td>{#if d.leader_id}<a href={hrefTo(`/${d.leader_id}`)} onclick={(e) => e.stopPropagation()}>{nameOf(d.leader_id)}</a>{:else}<span class="muted">-</span>{/if}</td>
								<td>{#if d.clergy_id}<a href={hrefTo(`/${d.clergy_id}`)} onclick={(e) => e.stopPropagation()}>{nameOf(d.clergy_id)}</a>{:else}<span class="muted">-</span>{/if}</td>
								<td style="text-align:right">{deptMembers(d.name).length}명</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</section>
		{/each}
	</div>

	{#if pickedOrg}
		<div class="card">
			<h2>{pickedOrg} 봉사자</h2>
			<table class="sm"><thead><tr><th>이름</th><th>역할</th><th>시작</th></tr></thead><tbody>
				{#each deptMembers(pickedOrg) as a (a.id)}<tr><td><a href={hrefTo(`/${a.person_id}`)}>{nameOf(a.person_id)}</a></td><td>{a.role ?? '-'}</td><td>{a.start_date ?? '미상'}</td></tr>
				{:else}<tr><td colspan="3" class="muted">봉사자가 없습니다.</td></tr>{/each}
			</tbody></table>
		</div>
	{/if}
{/if}
</div>

<!-- 인쇄·PDF용 명단: 화면에는 보이지 않고, 출력·PDF 저장 때만 씀 -->
<div class="print-wrap">
	<div class="sheet" bind:this={sheet}>
		<div class="sh-title"><h1>{tab} 명단</h1><p>{registry.churchInfo.name} · {today} 기준</p></div>
		{#each sections as sec}
			<div class="sec-head">{sec.title}</div>
			<table class="rt">
				<thead><tr>{#each sec.heads as h}<th>{h}</th>{/each}</tr></thead>
				<tbody>
					{#each sec.rows as r}<tr>{#each r as v}<td>{v}</td>{/each}</tr>{:else}<tr><td colspan={sec.heads.length}>없음</td></tr>{/each}
				</tbody>
			</table>
		{/each}
		<p class="sh-foot">{registry.churchInfo.name} · 개인정보가 담긴 문서이니 사용 후 파기해 주세요.</p>
	</div>
</div>

<style>
	.deacon-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; align-items: start; }
	.deacon-grid .card { margin: 0; }
	.deacon h2 { margin: 0 0 0.8rem; font-size: 1.05rem; }
	.dnames { display: flex; flex-wrap: wrap; gap: 0.35rem 0.9rem; line-height: 1.7; }
	.dtotal { margin: 0.9rem 0 0; padding-top: 0.6rem; border-top: 1px solid var(--line); text-align: right; color: var(--muted); }
	.dtotal b { color: var(--text); }
	.out-row { display: flex; justify-content: flex-end; margin: -0.4rem 0 0.8rem; }
	.role { display: block; margin: 0.15rem 0 0.1rem; font-style: normal; font-size: 0.8rem; font-weight: 600; color: var(--primary); }
	.print-wrap { position: fixed; left: -10000px; top: 0; width: 174mm; height: 0; overflow: hidden; }
	.sheet { width: 174mm; background: #fff; color: #111; font-size: 10pt; font-family: 'Pretendard', 'Malgun Gothic', sans-serif; }
	.sh-title { text-align: center; margin-bottom: 6mm; padding-bottom: 3mm; border-bottom: 2px solid #111; }
	.sh-title h1 { font-size: 18pt; margin: 0 0 1.5mm; letter-spacing: 0.08em; }
	.sh-title p { margin: 0; font-size: 9.5pt; color: #444; }
	.sec-head { margin: 5mm 0 1.5mm; font-size: 11pt; font-weight: 700; break-after: avoid; }
	.rt { width: 100%; border-collapse: collapse; }
	.rt th, .rt td { border: 1px solid #000; padding: 1.6mm 2mm; text-align: center; font-size: 9.5pt; }
	.rt th { background: #f1f3f5; font-weight: 700; }
	.sh-foot { margin-top: 6mm; font-size: 8.5pt; color: #666; text-align: center; }
	@media print {
		:global(.site-header), :global(.site-footer), :global(.tabbar), :global(.to-top), :global(.warn), .no-print { display: none !important; }
		:global(.site > main) { padding: 0 !important; max-width: none !important; }
		:global(body) { background: #fff !important; }
		.print-wrap { position: static; width: auto; height: auto; overflow: visible; }
		.sheet { width: auto; }
	}
	@page { size: A4; margin: 20mm 18mm; }
	.org-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 0.8rem; flex-wrap: wrap; margin-bottom: 0.9rem; }
	.meet td, .meet th { text-align: center; }
	.org-grid2 { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1rem; align-items: start; }
	.org-grid2 .card { margin: 0; padding: 0; }
	.cm-head { display: flex; align-items: center; gap: 0.7rem; padding: 0.85rem 1rem; background: linear-gradient(120deg, #34489e, #4f6cb8); color: #fff; }
	.cm-head h3 { margin: 0; font-size: 1.02rem; }
	.cm-head small { opacity: 0.92; font-size: 0.82rem; }
	.cm-head a { color: #fff; text-decoration: underline; text-underline-offset: 2px; }
	.committee td { white-space: nowrap; }
</style>
