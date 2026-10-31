<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 대시보드·심방 화면의 달력. 생일·기념일·일정·심방을 색으로 구분하고, 항목을 누르면 그것만 보여 줌
	import { tick } from 'svelte';
	import { registry } from '#lib/registry/store.svelte.ts';
	import { EVENT_KINDS, type EventKind } from '#lib/registry/types.ts';
	import { KIND_COLOR, KIND_LABEL, isoDate, isHoliday, type CalItem } from '#lib/registry/calendar.ts';

	let { only = null }: { only?: EventKind[] | null } = $props();
	const now = new Date();
	let y = $state(now.getFullYear());
	let m = $state(now.getMonth() + 1);
	// 처음 보여 줄 항목 (only 는 화면마다 고정이라 처음 값만 씀)
	// svelte-ignore state_referenced_locally
	// 공휴일은 어느 화면에서나 버튼 맨 끝에 둠
	let on = $state<EventKind[]>(only ? [...only, '공휴일'] : [...EVENT_KINDS]);
	const kinds = $derived<EventKind[]>(only ? [...only, '공휴일'] : EVENT_KINDS);
	let sel = $state<string | null>(isoDate(now));

	const items = $derived(registry.calendarItems(y, m, new Set<string>(on)));
	const order = (k: EventKind) => (k === '공휴일' ? -1 : k === '생일' ? 99 : EVENT_KINDS.indexOf(k));

	// 날짜별로 묶기 (여러 날에 걸친 일정은 해당 달 안의 모든 날에 표시)
	const byDate = $derived.by(() => {
		const map = new Map<string, CalItem[]>();
		const mm = String(m).padStart(2, '0');
		const last = new Date(y, m, 0).getDate();
		for (const it of items) {
			const end = it.end_date ?? it.date;
			for (let d = 1; d <= last; d++) {
				const iso = `${y}-${mm}-${String(d).padStart(2, '0')}`;
				if (iso >= it.date && iso <= end) {
					const l = map.get(iso);
					if (l) l.push(it); else map.set(iso, [it]);
				}
			}
		}
		for (const l of map.values()) l.sort((a, b) => order(a.kind) - order(b.kind) || a.title.localeCompare(b.title, 'ko'));
		return map;
	});

	const cells = $derived.by(() => {
		const first = new Date(y, m - 1, 1);
		const start = new Date(y, m - 1, 1 - first.getDay());
		return Array.from({ length: 42 }, (_, i) => {
			const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
			return { iso: isoDate(d), day: d.getDate(), inMonth: d.getMonth() === m - 1, dow: d.getDay() };
		});
	});
	const weeks = $derived(Math.ceil((cells.findLastIndex((c) => c.inMonth) + 1) / 7));
	const todayIso = isoDate(now);
	const selItems = $derived(sel ? byDate.get(sel) ?? [] : []);
	const selLabel = $derived(sel ? new Date(sel).toLocaleDateString('ko-KR', { month: 'long', day: 'numeric', weekday: 'short' }) : '');
	const hasBaptism = $derived(selItems.some((i) => i.title.startsWith('세례식')));
	const candidates = $derived(hasBaptism ? registry.baptismCandidates() : []);

	function go(delta: number) {
		const d = new Date(y, m - 1 + delta, 1);
		y = d.getFullYear(); m = d.getMonth() + 1;
		sel = null;
	}
	function toToday() { y = now.getFullYear(); m = now.getMonth() + 1; sel = todayIso; }
	// 항목을 누르면 그 항목만 보이고, 같은 항목을 한 번 더 누르면 모두 보입니다.
	const solo = $derived(on.length === 1 ? on[0] : null);
	function pick(k: EventKind) { on = solo === k ? [...kinds] : [k]; }

	// 일정 추가·수정 (교회 절기 · 사역 일정 · 성례 일정)
	type EditKind = '교회 절기' | '사역 일정' | '성례 일정';
	const EDIT_KINDS: EditKind[] = ['교회 절기', '사역 일정', '성례 일정'];
	const editKinds = $derived(EDIT_KINDS.filter((k) => kinds.includes(k)));
	const canEdit = $derived(registry.perms.edit && editKinds.length > 0);
	let formEl = $state<HTMLFormElement | null>(null);
	const showForm = () => tick().then(() => formEl?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
	let form = $state<{ id: string | null; kind: EditKind; title: string; date: string; end: string; note: string } | null>(null);
	function openNew() {
		form = { id: null, kind: editKinds[0], title: '', date: sel ?? todayIso, end: '', note: '' };
		showForm();
	}
	function openEdit(eventId: string) {
		const e = registry.d.events.find((x) => x.id === eventId);
		if (!e) return;
		form = { id: e.id, kind: e.kind as EditKind, title: e.title, date: e.date, end: e.end_date ?? '', note: e.note ?? '' };
		showForm();
	}
	const editable = (it: CalItem) => canEdit && !!it.event_id && (EDIT_KINDS as string[]).includes(it.kind);
	function saveForm(e: SubmitEvent) {
		e.preventDefault();
		if (!form || !form.title.trim() || !form.date) return;
		const end = form.end && form.end > form.date ? form.end : null;
		const data = { kind: form.kind, title: form.title.trim(), date: form.date, end_date: end, note: form.note.trim() || null };
		if (form.id) registry.updateEvent(form.id, data);
		else registry.addEvent({ ...data, person_id: null });
		// 저장한 날짜로 달력을 옮기고, 그 항목이 보이도록
		const [yy, mo] = form.date.split('-').map(Number);
		y = yy; m = mo; sel = form.date;
		if (!on.includes(form.kind)) on = [...on, form.kind];
		form = null;
	}
	function removeForm() {
		if (form?.id && confirm(`'${form.title}' 일정을 지웁니다.`)) { registry.removeEvent(form.id); form = null; }
	}
</script>

<div class="cal">
	<div class="cal-top">
		<div class="row">
			<button type="button" class="ghost sm" onclick={() => go(-1)} aria-label="이전 달">‹</button>
			<h3>{y}년 {m}월</h3>
			<button type="button" class="ghost sm" onclick={() => go(1)} aria-label="다음 달">›</button>
			<button type="button" class="ghost sm" onclick={toToday}>오늘</button>
			{#if canEdit}<button type="button" class="sm" onclick={openNew}>+ 일정 추가</button>{/if}
		</div>
		<div class="legend">
			{#each kinds as k}
				<button type="button" class="chip" class:off={!on.includes(k)} class:sel={solo === k} onclick={() => pick(k)} style="--c:{KIND_COLOR[k]}" title={k}><i></i>{KIND_LABEL[k]}</button>
			{/each}
		</div>
	</div>

	<div class="cal-grid">
		{#each ['일', '월', '화', '수', '목', '금', '토'] as w, i}<div class="dow" class:sun={i === 0}>{w}</div>{/each}
		{#each cells.slice(0, weeks * 7) as c (c.iso)}
			{@const list = c.inMonth ? byDate.get(c.iso) ?? [] : []}
			<button type="button" class="day" class:out={!c.inMonth} class:today={c.iso === todayIso} class:sel={c.iso === sel} class:sun={c.dow === 0 || (on.includes('공휴일') && isHoliday(c.iso))} onclick={() => c.inMonth && (sel = c.iso)}>
				<span class="n">{c.day}</span>
				{#each list.slice(0, 3) as it}
					<span class="ev" style="--c:{KIND_COLOR[it.kind]}" title={it.title}>{it.title}</span>
				{/each}
				{#if list.length > 3}<span class="more">+{list.length - 3}</span>{/if}
			</button>
		{/each}
	</div>

	{#if form}
		<form class="cal-detail cal-form" bind:this={formEl} onsubmit={saveForm}>
			<b>{form.id ? '일정 수정' : '일정 추가'}</b>
			<div class="form" style="margin-top:.5rem">
				<label>종류<select bind:value={form.kind}>{#each editKinds as k}<option>{k}</option>{/each}</select></label>
				<label class="wide">제목 <input required bind:value={form.title} placeholder="예: 전교인 수련회, 성찬식" /></label>
				<label>날짜<input type="date" required bind:value={form.date} /></label>
				<label><span class="lt">끝나는 날 <small class="hint">(하루면 비움)</small></span><input type="date" bind:value={form.end} min={form.date} /></label>
				<label class="wide">메모<input bind:value={form.note} placeholder="장소 · 담당 등" /></label>
			</div>
			<div class="row" style="margin-top:.7rem">
				<button type="submit" class="sm">저장</button>
				<button type="button" class="ghost sm" onclick={() => (form = null)}>취소</button>
				{#if form.id}<button type="button" class="ghost sm" style="margin-left:auto;color:var(--orange-600)" onclick={removeForm}>삭제</button>{/if}
			</div>
		</form>
	{/if}

	{#if sel}
		<div class="cal-detail">
			<b>{selLabel}</b>
			{#each selItems as it}
				<div class="li" style="padding:.45rem 0">
					<i class="dot" style="background:{KIND_COLOR[it.kind]}"></i>
					<div class="grow">
						<b>{#if it.href}<a href={hrefTo(it.href)}>{it.title}</a>{:else}{it.title}{/if}</b>
						<small>{it.kind}{it.sub ? ` · ${it.sub}` : ''}{it.end_date ? ` · ~${it.end_date.slice(5)}` : ''}</small>
					</div>
					{#if editable(it)}<button type="button" class="ghost sm" onclick={() => openEdit(it.event_id!)}>수정</button>{/if}
				</div>
			{:else}
				<p class="muted" style="margin:.4rem 0 0">이 날은 일정이 없습니다.</p>
			{/each}
			{#if hasBaptism}
				<div class="candidates">
					<b>세례 대상자 (학습교인 {candidates.length}명)</b>
					<p class="muted" style="margin:.2rem 0 0">
						{#each candidates.slice(0, 18) as { p }, i}<a href={hrefTo(`/${p.id}`)}>{p.name}</a>{i < Math.min(candidates.length, 18) - 1 ? ', ' : ''}{/each}
						{#if candidates.length > 18} 외 {candidates.length - 18}명{/if}
						{#if !candidates.length}해당자가 없습니다.{/if}
					</p>
				</div>
			{/if}
		</div>
	{/if}
</div>
