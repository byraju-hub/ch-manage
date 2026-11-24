<script lang="ts">
	// 교인 등록 › 엑셀로 일괄 등록: 엑셀을 올리면 먼저 분석해서 보여 주고, 확인한 뒤에 한꺼번에 등록
	//  - 바로 등록: 문제가 없는 줄 / 확인 필요: 같은 이름·전화가 이미 있거나 파일 안에 또 있는 줄(하나씩 정함) / 뺌: 이미 있는 같은 사람, 이름이 빈 줄
	import { registry } from '#lib/registry/store.svelte.ts';
	import { hrefTo } from '#lib/registry/paths.ts';
	import { categoryPath, officeKindTitle } from '#lib/registry/derive.ts';
	import { eduDeptOf, findCategory, MEMBER_TEMPLATE_HEAD, MEMBER_TEMPLATE_ROWS, parseMembers, type ImportRow } from '#lib/registry/member-import.ts';
	import type { ConsentMethod } from '#lib/registry/types.ts';

	let fileName = $state('');
	let parsed = $state<ImportRow[]>([]);
	let err = $state('');
	let busy = $state(false);
	let done = $state<{ added: number; households: number } | null>(null);
	let sameAddress = $state(false);
	let consent = $state(false);
	let method = $state<ConsentMethod>('서면');
	let fallback = $state('m.a.a');
	let showAuto = $state(false);
	// 사용자가 고른 것: 바로 등록 줄에서 뺀 줄 / 확인 필요 줄의 결정 (줄 번호가 열쇠)
	let off = $state<Record<number, boolean>>({});
	let decide = $state<Record<number, 'add' | 'skip' | undefined>>({});

	// 교인구분 기본값 후보: 교인 아래의 맨 끝 구분
	const leaves = $derived(registry.d.categories
		.filter((c) => !registry.d.categories.some((k) => k.parent_id === c.id) && categoryPath(registry.d.categories, c.id)[0]?.name === '교인')
		.map((c) => ({ id: c.id, label: categoryPath(registry.d.categories, c.id).map((x) => x.name).join(' › ') })));
	const fallbackId = $derived(leaves.some((l) => l.id === fallback) ? fallback : leaves[0]?.id ?? null);

	type Kind = 'skip' | 'dup' | 'check' | 'ok';
	type Item = ImportRow & { kind: Kind; why: string[] };
	const digits = (s: string | null) => (s ?? '').replace(/\D/g, '');
	const items = $derived.by<Item[]>(() => {
		const cats = registry.d.categories;
		const comms = registry.communities.map((c) => c.name);
		const seen = new Map<string, number>();
		return parsed.map((r0) => {
			const r: Item = { ...r0, warns: [...r0.warns], kind: 'ok', why: [] };
			if (!r.name) { r.kind = 'skip'; return r; }
			// 교인구분 · 교육부서
			const found = findCategory(cats, r.category_text);
			if (r.category_text && !found) r.warns.push(`교인구분 '${r.category_text}'을(를) 찾지 못해 기본 구분으로 넣음`);
			r.category_id = found ?? fallbackId;
			r.edu_dept = eduDeptOf(cats, r.category_id);
			// 교구 · 구역 (있는 것만 넣음)
			if (r.community || r.small_group) {
				if (!r.community || !r.small_group) { r.warns.push(`${registry.t1}과 ${registry.t2}이(가) 둘 다 있어야 넣을 수 있어 비움`); r.community = r.small_group = null; }
				else if (!comms.includes(r.community)) { r.warns.push(`${registry.t1} '${r.community}'이(가) 교회 설정에 없어 비움`); r.community = r.small_group = null; }
				else if (!registry.groupsIn(r.community).some((g) => g.name === r.small_group)) { r.warns.push(`${registry.t2} '${r.small_group}'이(가) ${r.community}에 없어 비움`); r.community = r.small_group = null; }
			}
			// 이미 있는 교인과 견줌: 이름과 (전화 또는 생년월일)이 같으면 같은 사람 → 뺌, 이름만 같거나 전화만 같으면 확인 필요
			const ph = digits(r.phone);
			const same = registry.d.people.filter((p) => p.name === r.name);
			const exact = same.find((p) => (ph.length >= 7 && digits(p.phone) === ph) || (!!r.birth_date && p.birth_date === r.birth_date));
			const phoneOnly = ph.length >= 7 ? registry.d.people.find((p) => p.name !== r.name && digits(p.phone) === ph) : undefined;
			if (exact) { r.kind = 'dup'; return r; }
			if (same.length) r.why.push(`같은 이름의 교인이 이미 있음 (${same.length}명)`);
			if (phoneOnly) r.why.push(`같은 전화번호의 교인 '${phoneOnly.name}'이(가) 이미 있음`);
			// 파일 안에서 같은 사람이 또 나옴
			const k = `${r.name}|${ph.length >= 7 ? ph : r.birth_date ?? ''}`;
			if ((ph.length >= 7 || r.birth_date) && seen.has(k)) r.why.push(`파일의 ${seen.get(k)}줄과 같은 사람 같음`);
			else if (!seen.has(k)) seen.set(k, r.line);
			if (r.why.length) r.kind = 'check';
			return r;
		});
	});
	const autoRows = $derived(items.filter((r) => r.kind === 'ok'));
	const checkRows = $derived(items.filter((r) => r.kind === 'check'));
	const dupRows = $derived(items.filter((r) => r.kind === 'dup'));
	const skipRows = $derived(items.filter((r) => r.kind === 'skip'));
	const undecided = $derived(checkRows.filter((r) => !decide[r.line]).length);
	const chosen = $derived([...autoRows.filter((r) => !off[r.line]), ...checkRows.filter((r) => decide[r.line] === 'add')]);
	const warnCount = $derived(chosen.filter((r) => r.warns.length).length);

	async function pick(e: Event) {
		const input = e.currentTarget as HTMLInputElement;
		const f = input.files?.[0];
		if (!f) return;
		fileName = f.name; busy = true; err = ''; done = null; off = {}; decide = {};
		try {
			const readExcelFile = (await import('read-excel-file/browser')).default;
			const res = parseMembers((await readExcelFile(f)) as { sheet: string; data: unknown[][] }[]);
			parsed = res.rows;
			err = res.error ?? (res.rows.length ? '' : '교인을 찾지 못했습니다. 이름 칸 아래에 교인이 한 줄에 한 명씩 적혀 있는지 확인해 주세요.');
		} catch (x) {
			console.error('교인 엑셀 읽기 실패', x);
			parsed = [];
			err = '엑셀 파일(.xlsx)을 읽지 못했습니다. 다른 이름으로 저장 › Excel 통합 문서(.xlsx)로 저장한 뒤 다시 올려 주세요.';
		} finally {
			busy = false;
			input.value = ''; // 같은 파일을 다시 골라도 읽도록
		}
	}
	function save() {
		if (!chosen.length || undecided) return;
		done = registry.importPeople(chosen, { consent, method, sameAddressHousehold: sameAddress });
		parsed = []; fileName = ''; off = {}; decide = {};
	}
	async function template() {
		const { default: writeXlsxFile } = await import('write-excel-file/browser');
		const head = MEMBER_TEMPLATE_HEAD.map((value) => ({ value, fontWeight: 'bold' as const, backgroundColor: '#E0E4F4' }));
		const data = [head, ...MEMBER_TEMPLATE_ROWS.map((r) => r.map((value) => ({ value })))];
		await writeXlsxFile(data, { sheet: '교인 명단', columns: MEMBER_TEMPLATE_HEAD.map((h) => ({ width: h === '주소' ? 36 : h === '교인구분' ? 18 : 12 })), stickyRowsCount: 1 }).toFile('교인 일괄 등록 양식.xlsx');
	}
	const offLabel = (r: ImportRow) => (r.office ? officeKindTitle({ office: r.office, rank_status: r.office_status }, r.clergy) : '');
	$effect(() => { if (fallbackId && fallback !== fallbackId) fallback = fallbackId; });
</script>

<div class="mi">
	<div class="row">
		<label class="btn ghost sm file-btn">
			<input type="file" accept=".xlsx" onchange={pick} />엑셀 파일 고르기
		</label>
		<span class="muted" style="font-size:.85rem">{busy ? '읽는 중…' : fileName || '선택한 파일 없음'}</span>
		<button type="button" class="ghost sm" style="margin-left:auto" onclick={template}>양식 받기</button>
	</div>
	<p class="muted" style="font-size:.8rem;margin:.5rem 0 0">
		첫 줄에 칸 이름이 있어야 합니다. <b>이름</b> 칸은 꼭 필요하고, 나머지는 있는 것만 읽습니다 (칸 순서는 상관없고, '나이'처럼 모르는 칸은 건너뜁니다).<br />
		읽는 칸: 이름 · 동명이인구분 · 성별 · 생년월일 · 휴대폰 · 주소 · 직분 · 상태 · 교인구분 · 전도회 · 신급 · {registry.t1}(교구) · {registry.t2}(구역) · 등록일 · 차량번호 · 이메일 · 직업 · 직장 · 결혼 여부 · 결혼기념일 · 이전 출석 교회 · 인도자 · 세대주 · 관계 · 비고
	</p>

	<div class="opts">
		<label>교인구분이 비었거나 못 찾을 때
			<select bind:value={fallback}>{#each leaves as l}<option value={l.id}>{l.label}</option>{/each}</select>
		</label>
		<label class="chk"><input type="checkbox" bind:checked={sameAddress} />같은 주소인 사람을 한 세대로 묶기 <span class="muted">(세대주 칸이 없을 때. 관계는 '기타'로 들어가 나중에 고칠 수 있습니다)</span></label>
		<label class="chk"><input type="checkbox" bind:checked={consent} />'교적 등록' 개인정보 동의를 받은 것으로 기록
			<select bind:value={method} disabled={!consent} style="width:auto;padding:.2rem 1.8rem .2rem .5rem">{#each ['서면', '앱', '구두'] as m}<option>{m}</option>{/each}</select>
		</label>
		{#if consent}<p class="muted" style="margin:0;font-size:.78rem">실제로 동의를 받은 경우에만 체크하세요. 체크하지 않으면 동의 기록 없이 등록되고, 나중에 '개인정보 동의' 명단에서 빠진 사람을 확인할 수 있습니다.</p>{/if}
	</div>

	{#if err}<p class="warn" style="margin:.6rem 0 0">{err}</p>{/if}
	{#if done}
		<div class="okbox">
			<b>{done.added}명을 등록했습니다.</b> 새로 만든 세대 {done.households}개.
			<a class="btn ghost sm" href={hrefTo('/members')}>교인 목록 보기</a>
		</div>
	{/if}

	{#if items.length}
		<div class="sum4">
			<div class="s ok3"><b>{autoRows.length}</b><span>바로 등록할 사람</span></div>
			<div class="s chk3"><b>{checkRows.length}</b><span>확인이 필요한 사람</span></div>
			<div class="s dup3"><b>{dupRows.length}</b><span>이미 있어서 뺀 사람</span></div>
			<div class="s dup3"><b>{skipRows.length}</b><span>이름이 없어 뺀 줄</span></div>
		</div>

		{#if checkRows.length}
			<div class="row" style="margin:.9rem 0 .4rem;justify-content:space-between">
				<b>확인이 필요한 사람 {checkRows.length}명 <span class="muted" style="font-weight:400;font-size:.85rem">— 하나씩 등록할지 정해 주세요{undecided ? ` (남은 ${undecided}명)` : ''}</span></b>
				<span class="row" style="gap:.3rem">
					<button type="button" class="ghost sm" onclick={() => checkRows.forEach((r) => (decide[r.line] = 'add'))}>모두 등록</button>
					<button type="button" class="ghost sm" onclick={() => checkRows.forEach((r) => (decide[r.line] = 'skip'))}>모두 빼기</button>
				</span>
			</div>
			<div class="mi-list">
				<table class="sm">
					<thead><tr><th>줄</th><th>이름</th><th>생년월일</th><th>휴대폰</th><th>확인할 점</th><th style="text-align:center">등록할까요?</th></tr></thead>
					<tbody>
						{#each checkRows as r (r.line)}
							<tr class:off={decide[r.line] === 'skip'}>
								<td class="nw">{r.line}</td><td class="nw">{r.name}</td><td class="nw">{r.birth_date ?? ''}</td><td class="nw">{r.phone ?? ''}</td>
								<td class="why">{r.why.join(' · ')}</td>
								<td class="nw" style="text-align:center">
									<button type="button" class="sm yn" class:on={decide[r.line] === 'add'} onclick={() => (decide[r.line] = 'add')}>등록</button>
									<button type="button" class="sm yn" class:on={decide[r.line] === 'skip'} onclick={() => (decide[r.line] = 'skip')}>빼기</button>
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}

		{#if autoRows.length}
			<div class="row" style="margin:.9rem 0 .4rem">
				<b>바로 등록할 사람 {autoRows.length}명</b>
				{#if autoRows.some((r) => r.warns.length)}<span class="muted" style="font-size:.85rem">· 고쳐 읽은 것이 있는 줄 {autoRows.filter((r) => r.warns.length).length}명 (목록에서 확인)</span>{/if}
				<button type="button" class="ghost sm" onclick={() => (showAuto = !showAuto)}>{showAuto ? '접기' : '목록 보기'}</button>
			</div>
			{#if showAuto}
				<div class="mi-list">
					<table class="sm">
						<thead><tr><th><input type="checkbox" checked={autoRows.every((r) => !off[r.line])} onchange={(e) => autoRows.forEach((r) => (off[r.line] = !e.currentTarget.checked))} aria-label="모두 고르기" /></th><th>줄</th><th>이름</th><th>성별</th><th>생년월일</th><th>휴대폰</th><th>직분</th><th>교인구분</th><th>상태</th><th>참고</th></tr></thead>
						<tbody>
							{#each autoRows as r (r.line)}
								<tr class:off={off[r.line]}>
									<td><input type="checkbox" checked={!off[r.line]} onchange={(e) => (off[r.line] = !e.currentTarget.checked)} aria-label="{r.name} 등록" /></td>
									<td class="nw">{r.line}</td><td class="nw">{r.name}{r.name_note ? ` (${r.name_note})` : ''}</td>
									<td>{r.gender === 'M' ? '남' : r.gender === 'F' ? '여' : ''}</td><td class="nw">{r.birth_date ?? ''}</td><td class="nw">{r.phone ?? ''}</td>
									<td class="nw">{offLabel(r)}</td>
									<td class="nw">{categoryPath(registry.d.categories, r.category_id).map((c) => c.name).join(' › ')}</td>
									<td>{r.status === '등록' ? '재적' : r.status}</td>
									<td class="why">{r.warns.join(' · ')}</td>
								</tr>
							{/each}
						</tbody>
					</table>
				</div>
			{/if}
		{/if}

		{#if dupRows.length}
			<p class="muted" style="font-size:.8rem;margin:.6rem 0 0">이미 있는 사람(이름과 전화 또는 생년월일이 같음): {dupRows.map((r) => `${r.name}(${r.line}줄)`).join(', ')}</p>
		{/if}
		{#if skipRows.length}
			<p class="muted" style="font-size:.8rem;margin:.3rem 0 0">이름이 비어 있는 줄: {skipRows.map((r) => `${r.line}줄`).join(', ')}</p>
		{/if}

		<div class="row" style="margin-top:.9rem">
			<button type="button" class="sm" disabled={!chosen.length || undecided > 0} onclick={save}>{chosen.length}명 등록하기</button>
			<span class="muted" style="font-size:.8rem">
				{undecided ? `확인이 필요한 사람 ${undecided}명을 먼저 정해 주세요.` : warnCount ? `등록하는 사람 중 ${warnCount}명은 읽지 못한 칸이 비어 들어갑니다 (바로 등록 목록의 '참고').` : '등록한 뒤에는 교인 목록에서 한 사람씩 고칠 수 있습니다.'}
			</span>
		</div>
	{/if}
</div>

<style>
	.file-btn { position: relative; overflow: hidden; cursor: pointer; }
	.file-btn input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
	.opts { margin-top: 0.8rem; display: grid; gap: 0.5rem; padding: 0.7rem 0.9rem; background: #fff; border: 1px solid var(--line); border-radius: 10px; font-size: 0.85rem; }
	.opts label { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
	.opts select { width: auto; }
	.opts .chk input { width: auto; }
	.okbox { margin-top: 0.8rem; padding: 0.7rem 0.9rem; border-radius: 10px; background: var(--primary-50); border: 1px solid var(--primary); display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
	.mi-list { max-height: 360px; overflow: auto; background: #fff; border: 1px solid var(--line); border-radius: 10px; }
	.mi-list thead th { position: sticky; top: 0; z-index: 1; }
	.mi-list td.nw { white-space: nowrap; }
	tr.off td { opacity: 0.45; }
	.sum4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.6rem; margin-top: 0.9rem; }
	.sum4 .s { padding: 0.7rem 0.9rem; border-radius: 12px; border: 1px solid var(--line); background: #fff; }
	.sum4 b { display: block; font-size: 1.4rem; }
	.sum4 span { font-size: 0.8rem; color: var(--muted); }
	.ok3 b { color: var(--teal-600); } .chk3 b { color: var(--orange-600); } .dup3 b { color: var(--muted); }
	td.why { color: var(--orange-600); font-size: 0.8rem; }
	.yn { padding: 0.2rem 0.55rem; background: #fff; color: var(--muted); border: 1px solid var(--line); box-shadow: none; }
	.yn.on { background: var(--primary); border-color: var(--primary); color: #fff; }
	@media (max-width: 640px) { .sum4 { grid-template-columns: 1fr 1fr; } }
</style>
