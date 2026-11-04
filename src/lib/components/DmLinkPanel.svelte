<script lang="ts">
	// 관리자 › 프로그램 설정 › 부서 관리 연결 (최고관리자)
	// 같은 DB 에 설치된 부서 관리(dep-manage)의 학생·교사를 교적의 교인과 연결한다.
	//   교적이 주인인 칸(이름·성별·생년월일·연락처·주소·세례)은 교적 → 부서 관리로 맞춰 주고,
	//   부서·학년·반·출석은 부서 관리가 주인이라 바꾸지 않는다. (출석은 읽어서 교적 화면에 보여 줌)
	import { onMount } from 'svelte';
	import { hrefTo } from '#lib/registry/paths.ts';
	import { auth } from '#lib/registry/auth.svelte.ts';
	import { dm } from '#lib/registry/dm.svelte.ts';
	import { registry } from '#lib/registry/store.svelte.ts';

	type Kind = 'student' | 'teacher';
	interface Cand { personId: string; name: string; birthDate: string | null; phone: string | null; eduDept: string | null }
	interface DmRow { dmId: number; name: string; birthDate: string | null; phone: string; dept: string; grade: string | null; match: 'sure' | 'maybe' | 'none'; candidates: Cand[] }
	interface CmOnly { personId: string; name: string; birthDate: string | null; eduDept: string | null }
	interface LinkRow { id: number; personId: string; personName: string; kind: Kind; dmId: number; eduDept: string | null; deptMismatch: boolean; dm: { name: string; dept: string; grade: string | null; status: string; birthDate: string | null } | null }
	interface PreviewItem { linkId: number; kind: Kind; name: string; dept: string; changes: { field: string; label: string; from: string | null; to: string }[] }
	interface Dept { id: number; name: string; grades: { id: number; label: string }[] }
	interface PhotoItem { linkId: number; personId: string; name: string; kind: Kind; state: 'to_dm' | 'to_cm' | 'both' }
	interface DeptItem { linkId: number; personId: string; name: string; dmDept: string; dmGrade: string | null; cmDept: string | null; ok: boolean; category: { from: string; to: string } | null; note?: string }

	const KIND: Record<Kind, string> = { student: '학생', teacher: '교사' };
	let busy = $state(false);
	let msg = $state('');
	let err = $state('');
	let cand = $state<{ students: DmRow[]; teachers: DmRow[]; cmOnly: CmOnly[] } | null>(null);
	let links = $state<LinkRow[]>([]);
	let preview = $state<PreviewItem[] | null>(null);
	let depts = $state<Dept[]>([]);
	let pick = $state<Record<string, string>>({}); // '애매' 줄에서 고른 교인
	let off = $state<Record<string, boolean>>({}); // '확실' 줄에서 체크를 뺀 것
	let addTo = $state<Record<string, { deptId: number; gradeId: number }>>({}); // 부서 관리에 추가할 때 고른 부서·학년
	let prefixInput = $state(dm.prefix);
	let photos = $state<{ dataDirFound: boolean; dataDir: string | null; gd: boolean; items: PhotoItem[] } | null>(null);
	let dataDirInput = $state('');
	let deptItems = $state<DeptItem[] | null>(null);
	const loadPhotos = () => run(async () => { photos = await auth.api('GET', '/dm/photos/preview'); });
	const syncPhotos = () => run(async () => {
		const r = await auth.api<{ toDm: number; toCm: number; failed: string[] }>('POST', '/dm/photos/sync', {});
		msg = `사진을 맞췄습니다. 교적 → 부서 관리 ${r.toDm}장, 부서 관리 → 교적 ${r.toCm}장${r.failed.length ? ` · 실패 ${r.failed.length}건: ${r.failed.join(' / ')}` : ''}`;
		await registry.reload(); photos = await auth.api('GET', '/dm/photos/preview');
	});
	const saveDataDir = () => run(async () => { await auth.api('POST', '/dm/data-dir', { path: dataDirInput }); photos = await auth.api('GET', '/dm/photos/preview'); msg = dataDirInput ? '폴더를 저장했습니다.' : '자동으로 찾도록 되돌렸습니다.'; });
	const loadDept = () => run(async () => { deptItems = (await auth.api<{ items: DeptItem[] }>('GET', '/dm/dept/preview')).items; });
	const syncDept = () => run(async () => {
		const r = await auth.api<{ synced: number }>('POST', '/dm/dept/sync', {});
		msg = `${r.synced}명의 교육부서를 부서 관리에 맞췄습니다.`;
		await registry.reload();
		deptItems = (await auth.api<{ items: DeptItem[] }>('GET', '/dm/dept/preview')).items;
	});

	async function run(fn: () => Promise<void>) {
		busy = true; msg = ''; err = '';
		try { await fn(); } catch (e) { err = e instanceof Error ? e.message : '처리하지 못했습니다.'; } finally { busy = false; }
	}
	async function loadLinks() {
		if (!dm.available) return;
		links = (await auth.api<{ links: LinkRow[] }>('GET', '/dm/links')).links;
	}
	/** 연결이 바뀐 뒤: 교적 자료(연결 정보)·부서 관리 출석·목록을 모두 새로 받는다 */
	async function after() {
		await registry.reload();
		await dm.refresh();
		await loadLinks();
		preview = null;
		if (cand) cand = await auth.api('GET', '/dm/candidates');
	}
	onMount(() => { void run(async () => { await dm.refresh(); await loadLinks(); }); });

	const loadCandidates = () => run(async () => { cand = await auth.api('GET', '/dm/candidates'); off = {}; pick = {}; });
	const sure = (rows: DmRow[]) => rows.filter((r) => r.match === 'sure');
	const key = (kind: Kind, id: number) => `${kind}:${id}`;

	const linkSure = (kind: Kind) => run(async () => {
		const rows = sure(kind === 'student' ? cand!.students : cand!.teachers).filter((r) => !off[key(kind, r.dmId)]);
		if (!rows.length) { err = '연결할 항목을 골라 주세요.'; return; }
		const r = await auth.api<{ linked: number }>('POST', '/dm/links', { links: rows.map((x) => ({ personId: x.candidates[0].personId, kind, dmId: x.dmId })) });
		await after();
		msg = `${r.linked}명을 연결했습니다.`;
	});
	const linkOne = (kind: Kind, dmId: number, personId: string) => run(async () => {
		await auth.api('POST', '/dm/links', { links: [{ personId, kind, dmId }] });
		await after();
		msg = '연결했습니다.';
	});
	const createInCm = (kind: Kind, dmId: number) => run(async () => {
		if (!confirm('부서 관리의 이 사람을 교적에 새 교인으로 등록하고 연결합니다.')) return;
		await auth.api('POST', '/dm/create-in-cm', { kind, dmId });
		await after();
		msg = '교적에 등록하고 연결했습니다.';
	});
	const unlink = (l: LinkRow) => run(async () => {
		if (!confirm(`${l.personName}님의 연결을 끊습니다. 교적과 부서 관리의 자료는 그대로 남습니다.`)) return;
		await auth.api('DELETE', `/dm/links/${l.id}`);
		await after();
		msg = '연결을 끊었습니다.';
	});
	async function ensureDepts() { if (!depts.length) depts = (await auth.api<{ departments: Dept[] }>('GET', '/dm/departments')).departments; }
	const toggleAdd = (personId: string) => run(async () => {
		await ensureDepts();
		if (addTo[personId]) { const { [personId]: _, ...rest } = addTo; addTo = rest; return; }
		const d = depts[0];
		addTo = { ...addTo, [personId]: { deptId: d?.id ?? 0, gradeId: d?.grades[0]?.id ?? 0 } };
	});
	const setDept = (personId: string, deptId: number) => { addTo[personId] = { deptId, gradeId: depts.find((d) => d.id === deptId)?.grades[0]?.id ?? 0 }; };
	const createInDm = (personId: string) => run(async () => {
		const a = addTo[personId];
		await auth.api('POST', '/dm/create-in-dm', { personId, kind: 'student', deptId: a.deptId, gradeId: a.gradeId });
		const { [personId]: _, ...rest } = addTo; addTo = rest;
		await after();
		msg = '부서 관리에 학생으로 추가하고 연결했습니다.';
	});
	const loadPreview = () => run(async () => { preview = (await auth.api<{ items: PreviewItem[] }>('GET', '/dm/sync/preview')).items; });
	const applyPreview = () => run(async () => {
		const r = await auth.api<{ synced: number }>('POST', '/dm/sync', { linkIds: preview!.map((p) => p.linkId) });
		preview = null;
		await after();
		msg = `${r.synced}명의 부서 관리 자료를 교적에 맞췄습니다.`;
	});
	const savePrefix = () => run(async () => {
		await auth.api('POST', '/dm/prefix', { prefix: prefixInput.trim() });
		await dm.refresh();
		await loadLinks();
		msg = dm.available ? '부서 관리를 찾았습니다.' : '저장했습니다. (이 접두어의 부서 관리는 아직 찾지 못했습니다)';
	});
	const who = (c: Cand) => `${c.name}${c.birthDate ? ` · ${c.birthDate}` : ''}${c.phone ? ` · ${c.phone}` : ''}`;
</script>

<p class="muted">같은 데이터베이스에 설치된 부서 관리(dep-manage)의 학생·교사를 교인과 연결합니다. 연결하면 <b>이름·성별·생년월일·연락처·주소·세례</b>는 교적에서 고치는 대로 부서 관리에 맞춰지고, <b>부서·학년·반·출석</b>은 부서 관리의 것을 그대로 둡니다(출석은 교적 화면에서 읽어 보여 줍니다).</p>

{#if msg}<div class="ok">{msg}</div>{/if}
{#if err}<div class="warn">{err}</div>{/if}

{#if !dm.available}
	<section class="card">
		<h2>부서 관리를 찾지 못했습니다</h2>
		{#if dm.reason === 'incompatible'}
			<p class="muted">부서 관리가 설치되어 있지만 이 프로그램이 읽을 수 없는 구조입니다. 부서 관리를 최신 버전으로 올린 뒤 다시 열어 주세요.</p>
		{:else if dm.reason === 'same_prefix'}
			<p class="muted">부서 관리의 표 접두어가 이 프로그램과 같습니다. 아래에서 다른 접두어를 입력해 주세요.</p>
		{:else}
			<p class="muted">같은 데이터베이스에 부서 관리(dep-manage)가 설치되어 있지 않습니다. 부서 관리를 설치하면 이 화면에서 연결할 수 있어요. 한쪽만 써도 아무 문제 없습니다.</p>
		{/if}
		<div class="row" style="gap:.5rem;align-items:center">
			<label class="row" style="gap:.4rem">부서 관리 표 접두어 <input bind:value={prefixInput} style="width:7rem" /></label>
			<button type="button" class="ghost sm" onclick={savePrefix} disabled={busy}>다시 찾기</button>
		</div>
	</section>
{:else}
	<section class="card">
		<div class="card-head"><h2>연결 목록</h2><span class="sub">연결 {links.length}건 · 부서 관리 표 접두어 <code>{dm.prefix}</code></span></div>
		<div style="overflow-x:auto">
			<table class="sm">
				<thead><tr><th>교인</th><th>종류</th><th>부서 관리</th><th>부서·학년</th><th></th></tr></thead>
				<tbody>
					{#each links as l (l.id)}
						<tr>
							<td><a href={hrefTo(`/${l.personId}`)}>{l.personName}</a></td>
							<td>{KIND[l.kind]}</td>
							{#if l.dm}
								<td>{l.dm.name}{l.dm.status === 'inactive' ? ' (비활동)' : ''}</td>
								<td>{l.dm.dept}{l.dm.grade ? ` · ${l.dm.grade}` : ''}{#if l.deptMismatch} <span class="tag part" title="교적의 교육부서와 부서 관리의 부서 이름이 다릅니다. 확인해 주세요.">확인 필요: 교적 {l.eduDept}</span>{/if}</td>
							{:else}
								<td colspan="2"><span class="tag part">끊어진 연결</span> <small class="muted">부서 관리에서 지워진 {KIND[l.kind]}입니다. 연결을 끊어 주세요.</small></td>
							{/if}
							<td><button type="button" class="ghost sm" onclick={() => unlink(l)} disabled={busy}>연결 끊기</button></td>
						</tr>
					{:else}<tr><td colspan="5" class="muted">아직 연결된 사람이 없습니다. 아래 '처음 연결하기'를 눌러 보세요.</td></tr>{/each}
				</tbody>
			</table>
		</div>
	</section>

	<section class="card">
		<div class="card-head"><h2>교적 → 부서 관리 맞추기</h2><span class="sub">교적에서 저장할 때 연결된 사람은 자동으로 맞춰집니다. 여기서는 지금까지 어긋난 것을 한꺼번에 맞춥니다.</span></div>
		{#if preview === null}
			<button type="button" onclick={loadPreview} disabled={busy || !links.length}>바뀔 내용 미리 보기</button>
		{:else if !preview.length}
			<p class="muted">맞출 것이 없습니다. 연결된 사람 모두 교적과 같습니다.</p>
		{:else}
			<div style="overflow-x:auto"><table class="sm">
				<thead><tr><th>이름</th><th>부서</th><th>바뀌는 내용 (부서 관리 → 교적 값)</th></tr></thead>
				<tbody>{#each preview as p (p.linkId)}
					<tr><td>{p.name}</td><td>{p.dept}</td><td>{#each p.changes as c}<div><b>{c.label}</b>: {c.from || '(없음)'} → {c.to}</div>{/each}</td></tr>
				{/each}</tbody>
			</table></div>
			<div class="row" style="margin-top:.7rem;gap:.5rem">
				<button type="button" onclick={applyPreview} disabled={busy}>{preview.length}명 모두 적용</button>
				<button type="button" class="ghost" onclick={() => (preview = null)}>닫기</button>
			</div>
		{/if}
	</section>

	<section class="card">
		<div class="card-head"><h2>사진 맞추기</h2><span class="sub">연결된 사람의 사진이 한쪽에만 있으면 다른 쪽으로 복사합니다. 양쪽에 있으면 그대로 둡니다.</span></div>
		{#if photos === null}
			<button type="button" onclick={loadPhotos} disabled={busy || !links.length}>사진 상태 확인</button>
		{:else}
			{#if !photos.dataDirFound}
				<p class="warn">부서 관리의 파일 폴더(data)를 자동으로 찾지 못했습니다. 호스팅에서 부서 관리가 설치된 폴더의 <code>data</code> 폴더 전체 경로를 알려 주세요. (예: /home/아이디/www/dm/data)</p>
				<div class="row" style="gap:.5rem"><input bind:value={dataDirInput} placeholder="/home/아이디/www/dm/data" style="max-width:26rem" /><button type="button" onclick={saveDataDir} disabled={busy || !dataDirInput}>저장</button></div>
			{:else}
				{@const toDm = photos.items.filter((i) => i.state === 'to_dm')}
				{@const toCm = photos.items.filter((i) => i.state === 'to_cm')}
				{@const both = photos.items.filter((i) => i.state === 'both')}
				{#if !photos.items.length}
					<p class="muted">사진이 있는 연결된 사람이 없습니다.</p>
				{:else}
					<p style="margin:0 0 .5rem">교적 → 부서 관리 <b>{toDm.length}장</b> · 부서 관리 → 교적 <b>{toCm.length}장</b> · 양쪽에 있어 그대로 둠 <b>{both.length}장</b></p>
					{#if toDm.length || toCm.length}
						<div style="overflow-x:auto"><table class="sm"><tbody>{#each [...toDm, ...toCm] as i (i.kind + i.linkId)}
							<tr><td>{i.name}</td><td class="muted">{KIND[i.kind]}</td><td>{i.state === 'to_dm' ? '교적의 사진을 부서 관리로' : '부서 관리의 사진을 교적으로'}</td></tr>
						{/each}</tbody></table></div>
						{#if toDm.length && !photos.gd}<p class="warn">이 호스팅에는 이미지 기능(GD)이 없어 교적 → 부서 관리 복사는 실패할 수 있습니다.</p>{/if}
						<div class="row" style="margin-top:.7rem;gap:.5rem"><button type="button" onclick={syncPhotos} disabled={busy}>{toDm.length + toCm.length}장 맞추기</button><button type="button" class="ghost" onclick={() => (photos = null)}>닫기</button></div>
					{:else}
						<p class="muted">맞출 사진이 없습니다.</p>
					{/if}
				{/if}
			{/if}
		{/if}
	</section>

	<section class="card">
		<div class="card-head"><h2>부서·학년(승급) 맞추기</h2><span class="sub">부서와 학년은 부서 관리가 주인입니다. 학생이 승급해서 부서가 바뀌면 교적의 교육부서(와 교인구분)를 부서 관리에 맞춥니다.</span></div>
		{#if deptItems === null}
			<button type="button" onclick={loadDept} disabled={busy || !links.length}>어긋난 학생 확인</button>
		{:else if !deptItems.length}
			<p class="muted">맞출 것이 없습니다. 연결된 학생의 부서가 모두 같습니다.</p>
			<button type="button" class="ghost" onclick={() => (deptItems = null)}>닫기</button>
		{:else}
			<div style="overflow-x:auto"><table class="sm">
				<thead><tr><th>이름</th><th>교적의 교육부서</th><th>부서 관리의 부서·학년</th><th></th></tr></thead>
				<tbody>{#each deptItems as d (d.linkId)}
					<tr><td>{d.name}</td><td>{d.cmDept ?? '(없음)'}</td><td>{d.dmDept}{d.dmGrade ? ` · ${d.dmGrade}` : ''}{#if d.category}<div class="muted" style="font-size:.8rem">교인구분 {d.category.from} → {d.category.to}</div>{/if}</td>
					<td>{#if d.ok}<span class="tag">맞춤</span>{:else}<small class="muted">{d.note}</small>{/if}</td></tr>
				{/each}</tbody>
			</table></div>
			<div class="row" style="margin-top:.7rem;gap:.5rem">
				<button type="button" onclick={syncDept} disabled={busy || !deptItems.some((d) => d.ok)}>{deptItems.filter((d) => d.ok).length}명 맞추기</button>
				<button type="button" class="ghost" onclick={() => (deptItems = null)}>닫기</button>
			</div>
		{/if}
	</section>

	<section class="card">
		<div class="card-head"><h2>처음 연결하기</h2><span class="sub">이름과 생년월일이 같으면 '확실', 이름만 같으면 직접 고릅니다.</span></div>
		{#if !cand}
			<button type="button" onclick={loadCandidates} disabled={busy}>연결할 후보 불러오기</button>
		{:else}
			{#each [['student', '학생', cand.students], ['teacher', '교사', cand.teachers]] as [kind, label, rows] (kind)}
				{@const k = kind as Kind}
				{@const list = rows as DmRow[]}
				<h3 style="margin:1rem 0 .4rem">{label}</h3>
				{#if !list.length}<p class="muted">연결할 {label}이 없습니다.</p>{:else}
					{#if sure(list).length}
						<p><b>확실한 후보 {sure(list).length}명</b> <small class="muted">이름과 생년월일(또는 연락처)이 같습니다. 체크를 빼면 제외됩니다.</small></p>
						<div style="overflow-x:auto"><table class="sm">
							<tbody>{#each sure(list) as r (r.dmId)}
								<tr><td style="width:2rem"><input type="checkbox" checked={!off[key(k, r.dmId)]} onchange={(e) => (off[key(k, r.dmId)] = !e.currentTarget.checked)} aria-label="{r.name} 연결" /></td>
								<td>{r.name}</td><td>{r.dept}{r.grade ? ` · ${r.grade}` : ''}</td><td class="muted">{r.birthDate ?? '생일 없음'}</td></tr>
							{/each}</tbody>
						</table></div>
						<button type="button" onclick={() => linkSure(k)} disabled={busy} style="margin:.5rem 0 1rem">확실한 {label} 연결하기</button>
					{/if}
					{#if list.some((r) => r.match === 'maybe')}
						<p><b>직접 골라야 하는 {label}</b> <small class="muted">이름은 같지만 확실하지 않습니다. (동명이인 등)</small></p>
						<div style="overflow-x:auto"><table class="sm">
							<tbody>{#each list.filter((r) => r.match === 'maybe') as r (r.dmId)}
								<tr>
									<td>{r.name} <small class="muted">{r.dept} · {r.birthDate ?? '생일 없음'}{r.phone ? ` · ${r.phone}` : ''}</small></td>
									<td><select value={pick[key(k, r.dmId)] ?? ''} onchange={(e) => (pick[key(k, r.dmId)] = e.currentTarget.value)} style="max-width:22rem">
										<option value="">교적에서 고르기</option>{#each r.candidates as c (c.personId)}<option value={c.personId}>{who(c)}</option>{/each}
									</select></td>
									<td><button type="button" class="ghost sm" disabled={busy || !pick[key(k, r.dmId)]} onclick={() => linkOne(k, r.dmId, pick[key(k, r.dmId)])}>연결</button></td>
								</tr>
							{/each}</tbody>
						</table></div>
					{/if}
					{#if list.some((r) => r.match === 'none')}
						<p style="margin-top:1rem"><b>교적에 없는 {label}</b> <small class="muted">같은 이름의 교인이 없습니다.</small></p>
						<div style="overflow-x:auto"><table class="sm">
							<tbody>{#each list.filter((r) => r.match === 'none') as r (r.dmId)}
								<tr><td>{r.name}</td><td>{r.dept}{r.grade ? ` · ${r.grade}` : ''}</td><td class="muted">{r.birthDate ?? '생일 없음'}</td>
								<td><button type="button" class="ghost sm" onclick={() => createInCm(k, r.dmId)} disabled={busy}>교적에 등록하고 연결</button></td></tr>
							{/each}</tbody>
						</table></div>
					{/if}
				{/if}
			{/each}

			<h3 style="margin:1.2rem 0 .4rem">부서 관리에 없는 교육부서 교인</h3>
			{#if !cand.cmOnly.length}<p class="muted">없습니다.</p>{:else}
				<p class="muted" style="font-size:.85rem">교적의 교육부서에 있지만 부서 관리에서 짝을 찾지 못한 교인입니다. 부서 관리에 학생으로 추가하려면 부서와 학년을 고르세요.</p>
				<div style="overflow-x:auto"><table class="sm">
					<tbody>{#each cand.cmOnly as c (c.personId)}
						<tr>
							<td><a href={hrefTo(`/${c.personId}`)}>{c.name}</a></td><td class="muted">{c.eduDept} · {c.birthDate ?? '생일 없음'}</td>
							<td>
								{#if addTo[c.personId]}
									<select value={addTo[c.personId].deptId} onchange={(e) => setDept(c.personId, +e.currentTarget.value)}>{#each depts as d (d.id)}<option value={d.id}>{d.name}</option>{/each}</select>
									<select bind:value={addTo[c.personId].gradeId}>{#each depts.find((d) => d.id === addTo[c.personId].deptId)?.grades ?? [] as g (g.id)}<option value={g.id}>{g.label}</option>{/each}</select>
									<button type="button" class="sm" onclick={() => createInDm(c.personId)} disabled={busy}>추가</button>
								{/if}
								<button type="button" class="ghost sm" onclick={() => toggleAdd(c.personId)} disabled={busy}>{addTo[c.personId] ? '취소' : '부서 관리에 학생으로 추가…'}</button>
							</td>
						</tr>
					{/each}</tbody>
				</table></div>
			{/if}
			<p style="margin-top:1rem"><button type="button" class="ghost sm" onclick={() => (cand = null)}>후보 닫기</button></p>
		{/if}
	</section>
{/if}
