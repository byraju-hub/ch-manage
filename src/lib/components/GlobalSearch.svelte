<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 대시보드 통합 검색창: 교인·소그룹·세대·심방·메모·조직을 한 번에 찾음
	import { registry } from '#lib/registry/store.svelte.ts';
	import { ageText } from '#lib/registry/derive.ts';

	let q = $state('');
	let focused = $state(false);
	const r = $derived(q.trim().length >= 1 ? registry.search(q) : null);
	const hasAny = $derived(!!r && (r.people.length || r.groups.length || r.households.length || r.visits.length || r.notes.length || r.orgs.length));
	const nameOf = (id: string) => registry.person(id)?.name ?? '';
	const cut = (s: string, n = 40) => (s.length > n ? s.slice(0, n) + '…' : s);
	let timer: ReturnType<typeof setTimeout>;
	function logSoon() {
		clearTimeout(timer);
		timer = setTimeout(() => q.trim().length >= 2 && registry.log('검색', q.trim()), 900);
	}
</script>

<div class="gsearch" role="search" onfocusin={() => (focused = true)} onfocusout={(e) => { if (!(e.currentTarget as HTMLElement).contains(e.relatedTarget as Node)) focused = false; }}>
	<div class="gs-box">
		<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
		<input type="search" bind:value={q} oninput={logSoon} placeholder="무엇이든 검색하세요! (이름 · 전화 · {registry.t2} · 직분 · 메모 · 심방 등)" aria-label="통합 검색" />
	</div>
	{#if focused && r}
		<div class="gs-pop">
			{#if !hasAny}
				<p class="empty" style="padding:1rem 0">검색 결과가 없습니다.</p>
			{/if}
			{#if r.people.length}
				<h4>교인 <span>{r.total}명</span></h4>
				{#each r.people as p (p.id)}
					{@const s = registry.summary(p)}
					<a class="gs-row" href={hrefTo(`/${p.id}`)}>
						<span class="avatar">{p.name.slice(0, 1)}</span>
						<span class="grow"><b>{p.name}{p.name_note ? ` (${p.name_note})` : ''} {s.title}</b>
							<small>{s.category || s.status} · {s.smallGroup ? `${s.community} ${s.smallGroup}` : `${registry.t2} 없음`} · {p.phone ?? '전화 없음'} · {ageText(p.birth_date)}</small></span>
					</a>
				{/each}
				{#if r.total > r.people.length}<a class="gs-more" href={hrefTo(`/members?q=${encodeURIComponent(q)}`)}>교인 {r.total}명 모두 보기 →</a>{/if}
			{/if}
			{#if r.groups.length}
				<h4>{registry.t1} · {registry.t2}</h4>
				{#each r.groups as g (g.id)}
					<a class="gs-row" href={hrefTo(`/communities/${encodeURIComponent(g.name)}`)}><span class="avatar warm">{g.name.slice(0, 1)}</span>
						<span class="grow"><b>{g.name}</b><small>{g.community} · {g.place ?? ''}{g.leader_id ? ` · 인도자 ${nameOf(g.leader_id)}` : ''}</small></span></a>
				{/each}
			{/if}
			{#if r.households.length}
				<h4>세대</h4>
				{#each r.households as h (h.id)}
					<a class="gs-row" href={hrefTo(`/households/${h.id}`)}><span class="avatar rose">{h.no.slice(-3)}</span><span class="grow"><b>{h.no}</b><small>{h.address ?? '주소 없음'}</small></span></a>
				{/each}
			{/if}
			{#if r.visits.length}
				<h4>심방</h4>
				{#each r.visits as v (v.id)}
					<a class="gs-row" href={hrefTo(`/${v.person_id}`)}><span class="avatar">{nameOf(v.person_id).slice(0, 1)}</span><span class="grow"><b>{nameOf(v.person_id)} · {v.kind}</b><small>{v.visit_date} · {v.pastor} · {cut(v.content)}</small></span></a>
				{/each}
			{/if}
			{#if r.notes.length}
				<h4>메모 · 특이사항</h4>
				{#each r.notes as n}
					<a class="gs-row" href={hrefTo(`/${n.p.id}`)}><span class="avatar warm">{n.p.name.slice(0, 1)}</span><span class="grow"><b>{n.p.name}</b><small>{n.kind} · {cut(n.body)}</small></span></a>
				{/each}
			{/if}
			{#if r.orgs.length}
				<h4>조직 · 부서</h4>
				{#each r.orgs as o (o.id)}
					<a class="gs-row" href={hrefTo('/orgs')}><span class="avatar">{o.name.slice(0, 1)}</span><span class="grow"><b>{o.name}</b><small>교회 조직표</small></span></a>
				{/each}
			{/if}
		</div>
	{/if}
</div>
