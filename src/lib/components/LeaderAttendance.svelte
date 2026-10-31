<script lang="ts">
	// 소그룹 인도자(D 등급) 전용 화면: 내가 인도하는 소그룹의 주일 모임 출석을 체크하고, 특이사항·기타 의견을 적는다.
	// 교적 전체 자료는 받지 않는다. 서버(/leader/…)가 내 소그룹의 명단만 준다.
	import { onMount } from 'svelte';
	import { auth, ApiError } from '#lib/registry/auth.svelte.ts';

	let { onlogout }: { onlogout: () => void } = $props();

	interface Group { id: string; name: string; place: string | null; community: string }
	interface Member { id: string; name: string; office: string; isLeader: boolean; rate: number; present: boolean; reason: string; recorded: boolean }
	interface Info { name: string; linked: boolean; unit: string; groups: Group[]; sundays: string[] }

	let info = $state<Info | null>(null);
	let groupId = $state('');
	let sunday = $state('');
	let members = $state<Member[]>([]);
	let note = $state('');
	let sortBy = $state<'rate' | 'name'>('rate');
	let loading = $state(true);
	let saving = $state(false);
	let dirty = $state(false);
	let msg = $state('');
	let err = $state('');

	const md = (iso: string) => `${+iso.slice(5, 7)}월 ${+iso.slice(8, 10)}일`;
	const errOf = (e: unknown, fallback: string) => (e instanceof ApiError || e instanceof Error ? e.message : fallback);
	const group = $derived(info?.groups.find((g) => g.id === groupId));

	// 인도자는 늘 맨 위, 그 아래는 고른 순서
	const shown = $derived(
		[...members].sort((a, b) =>
			Number(b.isLeader) - Number(a.isLeader) || (sortBy === 'rate' ? b.rate - a.rate : 0) || a.name.localeCompare(b.name, 'ko')
		)
	);
	const presentCount = $derived(members.filter((m) => m.present).length);

	async function loadInfo() {
		try {
			info = await auth.api<Info>('GET', '/leader/groups');
			sunday = info.sundays[0] ?? '';
			groupId = info.groups[0]?.id ?? '';
			if (groupId) await loadRoster();
		} catch (e) {
			err = errOf(e, '불러오지 못했습니다.');
		} finally {
			loading = false;
		}
	}

	async function loadRoster() {
		err = msg = '';
		loading = true;
		try {
			const d = await auth.api<{ members: Member[]; note: string }>('GET', `/leader/roster?group=${encodeURIComponent(groupId)}&sunday=${sunday}`);
			members = d.members;
			note = d.note;
			dirty = false;
		} catch (e) {
			err = errOf(e, '명단을 불러오지 못했습니다.');
		} finally {
			loading = false;
		}
	}

	// 날짜·소그룹을 바꾸기 전에 저장하지 않은 내용이 있으면 물어봄
	async function change(kind: 'group' | 'sunday', value: string) {
		if (dirty && !confirm('저장하지 않은 내용이 있어요. 바꾸면 사라집니다. 계속할까요?')) {
			// 선택 칸을 원래 값으로 되돌림
			if (kind === 'group') { const g = groupId; groupId = ''; groupId = g; } else { const s = sunday; sunday = ''; sunday = s; }
			return;
		}
		if (kind === 'group') groupId = value; else sunday = value;
		await loadRoster();
	}

	const mark = () => { dirty = true; msg = ''; };
	function allPresent() {
		const all = members.every((m) => m.present);
		for (const m of members) m.present = !all;
		mark();
	}

	async function save() {
		err = msg = '';
		saving = true;
		try {
			await auth.api('POST', '/leader/save', {
				group: groupId, sunday, note,
				items: members.map((m) => ({ id: m.id, present: m.present, reason: m.reason }))
			});
			dirty = false;
			msg = `${md(sunday)} 출석을 저장했어요. (출석 ${presentCount}명 / ${members.length}명)`;
		} catch (e) {
			err = errOf(e, '저장하지 못했습니다. 잠시 뒤 다시 눌러 주세요.');
		} finally {
			saving = false;
		}
	}

	onMount(() => {
		void loadInfo();
		const warn = (e: BeforeUnloadEvent) => { if (dirty) e.preventDefault(); };
		window.addEventListener('beforeunload', warn);
		return () => window.removeEventListener('beforeunload', warn);
	});
</script>

<div class="leader">
	<div class="top">
		<div>
			<h1>출석 체크</h1>
			<p class="who">{info?.name ?? auth.user?.name}님 · {info?.unit ?? '소그룹'} 인도자</p>
		</div>
		<button type="button" class="ghost sm" onclick={onlogout}>로그아웃</button>
	</div>

	{#if info && !info.linked}
		<div class="card empty"><b>아직 연결된 교인이 없어요.</b><br />최고관리자에게 "내 계정을 교인과 연결해 달라"고 요청해 주세요.</div>
	{:else if info && info.groups.length === 0}
		<div class="card empty"><b>인도하는 {info.unit}이 없어요.</b><br />최고관리자에게 {info.unit}의 인도자로 등록해 달라고 요청해 주세요.</div>
	{:else if info}
		<div class="pickers">
			{#if info.groups.length > 1}
				<select aria-label="{info.unit} 고르기" value={groupId} onchange={(e) => change('group', e.currentTarget.value)}>
					{#each info.groups as g (g.id)}<option value={g.id}>{g.name}</option>{/each}
				</select>
			{/if}
			<select aria-label="정렬" bind:value={sortBy}>
				<option value="rate">출석률 높은순</option>
				<option value="name">이름순</option>
			</select>
			<select aria-label="주일 날짜" value={sunday} onchange={(e) => change('sunday', e.currentTarget.value)}>
				{#each info.sundays as s, i (s)}<option value={s}>{md(s)}{i === 0 ? ' (최근)' : ''}</option>{/each}
			</select>
		</div>

		{#if group}<p class="sub">{group.name} · {group.community}{group.place ? ` · ${group.place}` : ''}</p>{/if}

		{#if err}<p class="warn" role="alert">{err}</p>{/if}
		{#if msg}<p class="ok" role="status">{msg}</p>{/if}

		{#if loading}
			<p class="muted center">불러오는 중…</p>
		{:else}
			<div class="bar">
				<span>출석 <b>{presentCount}</b> / {members.length}명</span>
				<button type="button" class="ghost sm" onclick={allPresent} disabled={!members.length}>{members.length > 0 && members.every((m) => m.present) ? '전원 해제' : '전원 출석'}</button>
			</div>

			<ul class="list">
				{#each shown as m (m.id)}
					<li class:on={m.present}>
						<label class="chk">
							<input type="checkbox" bind:checked={m.present} onchange={mark} aria-label="{m.name} 출석" />
							<span class="box" aria-hidden="true"></span>
						</label>
						<div class="who2">
							<div class="nm"><b>{m.name}</b>{#if m.isLeader}<span class="badge">리더</span>{/if}</div>
							<small>{m.office ? `${m.office} · ` : ''}출석 {m.rate}%</small>
						</div>
						<input class="reason" bind:value={m.reason} oninput={mark} maxlength="255" placeholder="특이사항 (이 주차)" aria-label="{m.name} 특이사항" />
					</li>
				{:else}
					<li class="none">명단이 비어 있어요.</li>
				{/each}
			</ul>

			<label class="etc">
				<span>기타 의견 <small>({md(sunday)} 모임 전체에 대해)</small></span>
				<textarea bind:value={note} oninput={mark} rows="3" maxlength="2000" placeholder="이번 모임에서 전할 말씀이나 나눔 내용이 있으면 적어 주세요."></textarea>
			</label>

			<div class="save">
				<span class="hint">{dirty ? '저장하지 않은 내용이 있어요' : ''}</span>
				<button type="button" onclick={save} disabled={saving || !members.length}>{saving ? '저장하는 중…' : '저장'}</button>
			</div>
		{/if}
	{:else if err}
		<p class="warn" role="alert">{err}</p>
	{:else}
		<p class="muted center">불러오는 중…</p>
	{/if}
</div>

<style>
	.leader { max-width: 720px; margin: 0 auto; padding: 0.4rem 0.2rem 6rem; }
	.top { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.8rem; margin-bottom: 0.6rem; }
	h1 { margin: 0; font-size: 1.9rem; }
	.who { margin: 0.2rem 0 0; color: var(--muted, #5b6678); font-size: 0.9rem; }
	.pickers { display: flex; flex-wrap: wrap; gap: 0.6rem; margin: 0.6rem 0 0.4rem; }
	.pickers select { width: auto; min-width: 9rem; font-size: 1.05rem; padding: 0.7rem 0.9rem; border-radius: 16px; }
	.sub { margin: 0.2rem 0 0.8rem; color: var(--muted, #5b6678); font-size: 0.88rem; }
	.center { text-align: center; padding: 2rem 0; }
	.empty { text-align: center; padding: 2rem 1rem; line-height: 1.8; }
	.bar { display: flex; justify-content: space-between; align-items: center; margin: 0.6rem 0 0.4rem; font-size: 0.95rem; }
	.list { list-style: none; margin: 0; padding: 0; background: var(--card, #fff); border: 1px solid var(--line, #dde2f3); border-radius: 20px; overflow: hidden; }
	.list li { display: grid; grid-template-columns: auto 1fr; gap: 0.5rem 0.9rem; align-items: center; padding: 0.9rem 1rem; border-top: 1px solid var(--line, #eef0f8); }
	.list li:first-child { border-top: 0; }
	.list li:nth-child(even) { background: color-mix(in srgb, var(--accent-soft, #eef1fb) 45%, transparent); }
	.list li.on .box { background: var(--accent, #2b3e95); border-color: var(--accent, #2b3e95); }
	.list li.on .box::after { opacity: 1; }
	.list li.none { display: block; text-align: center; color: var(--muted, #5b6678); }
	.chk { position: relative; display: inline-flex; cursor: pointer; padding: 0.2rem; }
	.chk input { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
	.box { width: 2.6rem; height: 2.6rem; border-radius: 12px; border: 2px solid #c9cfdf; background: var(--card, #fff); display: inline-flex; align-items: center; justify-content: center; transition: background 0.12s, border-color 0.12s; }
	.box::after { content: ''; width: 0.65rem; height: 1.15rem; border: solid #fff; border-width: 0 0.28rem 0.28rem 0; transform: rotate(45deg) translate(-2px, -2px); opacity: 0; }
	.chk input:focus-visible + .box { outline: 3px solid var(--accent, #2b3e95); outline-offset: 2px; }
	.who2 { min-width: 0; }
	.nm { display: flex; align-items: center; gap: 0.45rem; font-size: 1.15rem; }
	.badge { font-size: 0.72rem; font-weight: 700; color: #8a6d4b; letter-spacing: 0.02em; }
	.who2 small { color: var(--muted, #8a93a6); font-size: 0.85rem; }
	.reason { grid-column: 1 / -1; font-size: 1rem; padding: 0.7rem 0.9rem; border-radius: 14px; }
	.etc { display: block; margin-top: 1.2rem; }
	.etc span { display: block; font-weight: 700; margin-bottom: 0.3rem; }
	.etc small { font-weight: 400; color: var(--muted, #5b6678); }
	.etc textarea { width: 100%; font: inherit; padding: 0.8rem 0.9rem; border-radius: 14px; resize: vertical; }
	.save { position: fixed; left: 0; right: 0; bottom: 0; display: flex; justify-content: flex-end; align-items: center; gap: 0.8rem; padding: 0.7rem 1rem calc(0.7rem + env(safe-area-inset-bottom)); background: color-mix(in srgb, var(--card, #fff) 94%, transparent); backdrop-filter: blur(6px); border-top: 1px solid var(--line, #dde2f3); }
	.save button { min-width: 8rem; font-size: 1.05rem; padding: 0.8rem 1.4rem; }
	.hint { color: #a83c10; font-size: 0.85rem; }
	@media (min-width: 560px) {
		.list li { grid-template-columns: auto minmax(9rem, 1fr) minmax(12rem, 1.3fr); }
		.reason { grid-column: auto; }
	}
</style>
