<script lang="ts">
	// 관리자 › 교회 설정 › 연간 설정: 교회 연간 계획 엑셀을 올려 교회 절기 · 사역 일정 · 성례 일정을 한꺼번에 등록 (대시보드 캘린더에 나옴)
	import { registry } from '#lib/registry/store.svelte.ts';
	import { KIND_COLOR } from '#lib/registry/calendar.ts';
	import { guessYear, parsePlan, SACRAMENT_WORDS, SEASON_WORDS, type PlanKind, type PlanRow } from '#lib/registry/plan-import.ts';

	let { ondone, initYear }: { ondone:(firstDate: string | null, count: number) => void; initYear?: number } = $props();
	const KINDS: PlanKind[] = ['교회 절기', '사역 일정', '성례 일정'];
	const thisYear = new Date().getFullYear();
	// svelte-ignore state_referenced_locally
	let year = $state(initYear ?? thisYear);
	let fileName = $state('');
	let sheets = $state<{ sheet: string; data: unknown[][] }[] | null>(null);
	// 분석 결과: 이미 있음(빼 둠) · 확인 필요(넣을지 직접 정함) · 바로 넣음
	type Row = PlanRow & { dup: boolean; check: string | null; on: boolean; decide: 'add' | 'skip' | null };
	let rows = $state<Row[]>([]);
	let err = $state('');
	let holidays = $state(0);
	let busy = $state(false);

	const sq = (s: string) => s.replace(/\s+/g, '');
	const md = (iso: string) => `${+iso.slice(5, 7)}/${+iso.slice(8, 10)}`;
	const exists = (r: PlanRow) => registry.d.events.some((e) => e.date === r.date && sq(e.title) === sq(r.title));
	// 캘린더에 있는 일정과 견줘 확인이 필요한 까닭 (없으면 바로 넣음)
	function reason(r: PlanRow): string | null {
		const plan = registry.d.events.filter((e) => e.kind !== '성도');
		const why: string[] = [];
		if (+r.date.slice(0, 4) !== year) why.push(`${year}년이 아닌 날짜`);
		if (/주일/.test(r.title) && new Date(`${r.date}T00:00`).getDay() !== 0) why.push('이름에 "주일"이 있는데 주일이 아님');
		const near = plan.find((e) => sq(e.title) === sq(r.title) && e.date !== r.date && Math.abs(+new Date(e.date) - +new Date(r.date)) <= 14 * 864e5);
		if (near) why.push(`같은 이름의 일정이 ${md(near.date)}에 이미 있음`);
		const same = plan.filter((e) => e.date === r.date && sq(e.title) !== sq(r.title) && (sq(e.title).includes(sq(r.title)) || sq(r.title).includes(sq(e.title))));
		if (same.length) why.push(`같은 날 비슷한 일정 '${same[0].title}'이(가) 있음`);
		if (sq(r.title).length < 3) why.push('이름이 너무 짧음');
		if (r.title.length > 40) why.push('이름이 너무 김 (두 행사가 붙었을 수 있음)');
		if (r.end_date && +new Date(r.end_date) - +new Date(r.date) > 31 * 864e5) why.push('기간이 한 달보다 김');
		return why.length ? why.join(' · ') : null;
	}
	function build() {
		if (!sheets) return;
		const res = parsePlan(sheets, year);
		holidays = res.holidays;
		rows = res.rows.map((r) => {
			const dup = exists(r);
			const check = dup ? null : reason(r);
			return { ...r, dup, check, on: !dup && !check, decide: null };
		});
		err = rows.length ? '' : '일정을 찾지 못했습니다. 날짜 칸과 일정(행사) 칸이 있는지 확인해 주세요.';
	}
	const autoRows = $derived(rows.filter((r) => !r.dup && !r.check));
	const checkRows = $derived(rows.filter((r) => r.check));
	const dupRows = $derived(rows.filter((r) => r.dup));
	const undecided = $derived(checkRows.filter((r) => !r.decide).length);
	let showAuto = $state(false);
	async function pick(e: Event) {
		const f = (e.currentTarget as HTMLInputElement).files?.[0];
		if (!f) return;
		fileName = f.name;
		busy = true; err = '';
		try {
			const readExcelFile = (await import('read-excel-file/browser')).default;
			sheets = (await readExcelFile(f)) as { sheet: string; data: unknown[][] }[];
			// 문서 제목(예: '2026 교회 연중행사 계획안')이나 파일 이름에 연도가 있으면 그 연도로
			const y = guessYear(sheets) ?? +(f.name.match(/(?:^|\D)(20\d{2})(?:\D|$)/)?.[1] ?? 0);
			if (y) year = y;
			build();
		} catch (x) {
			console.error('연간 계획 읽기 실패', x);
			sheets = null; rows = [];
			err = '엑셀 파일(.xlsx)을 읽지 못했습니다. 다른 이름으로 저장 › Excel 통합 문서(.xlsx)로 저장한 뒤 다시 올려 주세요.';
		} finally {
			busy = false;
		}
	}
	const chosen = $derived([...autoRows.filter((r) => r.on), ...checkRows.filter((r) => r.decide === 'add')]);
	function save() {
		if (!chosen.length || undecided) return;
		registry.addEvents(chosen.map(({ kind, date, end_date, title, note }) => ({ kind, date, end_date, title, note, person_id: null })));
		ondone([...chosen].sort((a, b) => a.date.localeCompare(b.date))[0]?.date ?? null, chosen.length);
	}
	// 양식 내려받기: 날짜 · 끝나는 날 · 일정 · 비고
	async function template() {
		const { default: writeXlsxFile } = await import('write-excel-file/browser');
		const head = ['날짜', '끝나는 날', '일정', '비고'].map((value) => ({ value, fontWeight: 'bold' as const, backgroundColor: '#E0E4F4' }));
		const ex: [string, string, string, string][] = [
			[`${year}-01-04`, '', '신년주일', ''],
			[`${year}-03-29`, '', '종려주일', ''],
			[`${year}-03-30`, `${year}-04-04`, '고난주간 특별새벽기도회', '본당'],
			[`${year}-04-05`, '', '부활주일 세례식 · 성찬식', ''],
			[`${year}-05-23`, `${year}-05-24`, '전교인 수련회', '수양관']
		];
		const data = [head, ...ex.map((r) => r.map((value) => ({ value })))];
		await writeXlsxFile(data, { sheet: '연간 계획', columns: [{ width: 14 }, { width: 14 }, { width: 30 }, { width: 16 }] }).toFile(`교회 연간 계획 양식-${year}.xlsx`);
	}
</script>

<div class="plan">
	<div class="row">
		<label class="btn ghost sm file-btn">
			<input type="file" accept=".xlsx" onchange={pick} />엑셀 파일 고르기
		</label>
		<span class="muted" style="font-size:.85rem">{busy ? '읽는 중…' : fileName || '선택한 파일 없음'}</span>
		<label class="row" style="gap:.35rem;font-size:.85rem;margin-left:auto">연도
			<select bind:value={year} onchange={build} style="width:auto;padding:.3rem 2rem .3rem .6rem">
				{#each [thisYear - 1, thisYear, thisYear + 1, thisYear + 2] as y}<option value={y}>{y}년</option>{/each}
			</select>
		</label>
		<button type="button" class="ghost sm" onclick={template}>양식 받기</button>
	</div>
	<ul class="rule">
		<li><i class="dot" style="background:{KIND_COLOR['성례 일정']}"></i><b>성례 일정</b> — 이름에 {SACRAMENT_WORDS.join(' · ')}</li>
		<li><i class="dot" style="background:{KIND_COLOR['교회 절기']}"></i><b>교회 절기</b> — {SEASON_WORDS.join(' · ')}</li>
		<li><i class="dot" style="background:{KIND_COLOR['사역 일정']}"></i><b>사역 일정</b> — 그 밖의 모든 일정 (어린이주일 · 부부주일 같은 특별 주일 포함)</li>
		<li><i class="dot" style="background:{KIND_COLOR['공휴일']}"></i><b>공휴일</b> — 캘린더에 이미 빨간색으로 들어 있어 올리지 않음</li>
	</ul>
	<p class="muted" style="font-size:.8rem;margin:.3rem 0 0">
		첫 줄에 '날짜' · '일정(행사)' 칸 이름이 있으면 가장 정확합니다. '월' · '일' 칸으로 나뉜 표, '4/13~4/19' 같은 기간도 읽습니다. 날짜에 연도가 없으면 위의 연도로 넣습니다.
	</p>
	{#if err}<p class="warn" style="margin:.6rem 0 0">{err}</p>{/if}

	{#if rows.length}
		<!-- 분석 결과 요약 -->
		<div class="sum3">
			<div class="s ok3"><b>{autoRows.length}</b><span>바로 넣을 일정</span></div>
			<div class="s chk3"><b>{checkRows.length}</b><span>확인이 필요한 일정</span></div>
			<div class="s dup3"><b>{dupRows.length + holidays}</b><span>뺀 일정 (이미 있음 {dupRows.length} · 공휴일 {holidays})</span></div>
		</div>

		{#if checkRows.length}
			<div class="row" style="margin:.9rem 0 .4rem;justify-content:space-between">
				<b>확인이 필요한 일정 {checkRows.length}건 <span class="muted" style="font-weight:400;font-size:.85rem">— 하나씩 넣을지 정해 주세요{undecided ? ` (남은 ${undecided}건)` : ''}</span></b>
				<span class="row" style="gap:.3rem">
					<button type="button" class="ghost sm" onclick={() => checkRows.forEach((r) => (r.decide = 'add'))}>모두 넣기</button>
					<button type="button" class="ghost sm" onclick={() => checkRows.forEach((r) => (r.decide = 'skip'))}>모두 빼기</button>
				</span>
			</div>
			<div class="plan-list">
				<table class="sm">
					<thead><tr><th>날짜</th><th>일정</th><th>확인할 점</th><th>종류</th><th style="text-align:center">넣을까요?</th></tr></thead>
					<tbody>
						{#each checkRows as r}
							<tr class:off={r.decide === 'skip'}>
								<td class="nw">{md(r.date)}{r.end_date ? ` ~ ${md(r.end_date)}` : ''}</td>
								<td>{r.title}{#if r.note}<small class="muted"> · {r.note}</small>{/if}</td>
								<td class="why">{r.check}</td>
								<td><select bind:value={r.kind} style="--c:{KIND_COLOR[r.kind]}" class="kind">{#each KINDS as k}<option>{k}</option>{/each}</select></td>
								<td class="nw" style="text-align:center">
									<button type="button" class="sm yn" class:on={r.decide === 'add'} onclick={() => (r.decide = 'add')}>넣기</button>
									<button type="button" class="sm yn" class:on={r.decide === 'skip'} onclick={() => (r.decide = 'skip')}>빼기</button>
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}

		{#if autoRows.length}
			<div class="row" style="margin:.9rem 0 .4rem">
				<b>바로 넣을 일정 {autoRows.length}건</b>
				{#each KINDS as k}<span class="muted" style="font-size:.85rem"><i class="dot" style="background:{KIND_COLOR[k]}"></i> {k} {autoRows.filter((r) => r.kind === k).length}</span>{/each}
				<button type="button" class="ghost sm" onclick={() => (showAuto = !showAuto)}>{showAuto ? '접기' : '목록 보기'}</button>
			</div>
			{#if showAuto}
				<div class="plan-list">
					<table class="sm">
						<thead><tr><th><input type="checkbox" checked={autoRows.every((r) => r.on)} onchange={(e) => autoRows.forEach((r) => (r.on = e.currentTarget.checked))} aria-label="모두 고르기" /></th><th>날짜</th><th>일정</th><th>종류</th><th>비고</th></tr></thead>
						<tbody>
							{#each autoRows as r}
								<tr class:off={!r.on}>
									<td><input type="checkbox" bind:checked={r.on} aria-label="{r.title} 등록" /></td>
									<td class="nw">{md(r.date)}{r.end_date ? ` ~ ${md(r.end_date)}` : ''}</td>
									<td>{r.title}</td>
									<td><select bind:value={r.kind} style="--c:{KIND_COLOR[r.kind]}" class="kind">{#each KINDS as k}<option>{k}</option>{/each}</select></td>
									<td class="muted">{r.note ?? ''}</td>
								</tr>
							{/each}
						</tbody>
					</table>
				</div>
			{/if}
		{/if}

		{#if dupRows.length}
			<p class="muted" style="font-size:.8rem;margin:.6rem 0 0">이미 캘린더에 있는 일정: {dupRows.map((r) => `${md(r.date)} ${r.title}`).join(', ')}</p>
		{/if}

		<div class="row" style="margin-top:.9rem">
			<button type="button" class="sm" disabled={!chosen.length || undecided > 0} onclick={save}>{chosen.length}건 캘린더에 넣기</button>
			<span class="muted" style="font-size:.8rem">{undecided ? `확인이 필요한 일정 ${undecided}건을 먼저 정해 주세요.` : '종류가 틀린 일정은 넣기 전에 바꿀 수 있습니다.'}</span>
		</div>
	{/if}
</div>

<style>
	.file-btn { position: relative; overflow: hidden; cursor: pointer; }
	.file-btn input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
	.rule { margin: 0.7rem 0 0; padding: 0.6rem 0.8rem; list-style: none; background: #fff; border: 1px solid var(--line); border-radius: 10px; font-size: 0.82rem; display: grid; gap: 0.25rem; }
	.rule li { display: flex; gap: 0.4rem; align-items: baseline; }
	.rule b { flex: none; white-space: nowrap; }
	.rule .dot, .row .dot { width: 8px; height: 8px; }
	.plan-list { max-height: 360px; overflow: auto; background: #fff; border: 1px solid var(--line); border-radius: 10px; }
	.plan-list thead th { position: sticky; top: 0; z-index: 1; }
	.plan-list td.nw { white-space: nowrap; }
	tr.off td { opacity: 0.45; }
	.sum3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.6rem; margin-top: 0.9rem; }
	.sum3 .s { padding: 0.7rem 0.9rem; border-radius: 12px; border: 1px solid var(--line); background: #fff; }
	.sum3 b { display: block; font-size: 1.4rem; }
	.sum3 span { font-size: 0.8rem; color: var(--muted); }
	.ok3 b { color: var(--teal-600); } .chk3 b { color: var(--orange-600); } .dup3 b { color: var(--muted); }
	td.why { color: var(--orange-600); font-size: 0.8rem; }
	.yn { padding: 0.2rem 0.55rem; background: #fff; color: var(--muted); border: 1px solid var(--line); box-shadow: none; }
	.yn.on { background: var(--primary); border-color: var(--primary); color: #fff; }
	@media (max-width: 640px) { .sum3 { grid-template-columns: 1fr; } }
	select.kind { width: auto; padding: 0.25rem 1.9rem 0.25rem 0.55rem; font-size: 0.82rem; border-left: 4px solid var(--c); }
</style>
