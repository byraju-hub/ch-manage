<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 공동체 편성표: 공동체별 소그룹 · 인도자 · 모임 장소 · 이번 주 출석
	import { registry } from '#lib/registry/store.svelte.ts';
	import { recentSundays } from '#lib/registry/weeks.ts';
	import CommunityIcon from '#lib/components/CommunityIcon.svelte';
	import { bornText } from '#lib/registry/year-settings.ts';

	const WEEKS = recentSundays(8);
	const latest = WEEKS[WEEKS.length - 1];

	// 소그룹별 인원·이번 주 출석
	const stat = $derived.by(() => {
		const m = new Map<string, { total: number; present: number }>();
		for (const g of registry.d.personGroups) {
			const p = registry.person(g.person_id);
			if (!p || !registry.summary(p).memberCert) continue;
			const cur = m.get(g.small_group) ?? { total: 0, present: 0 };
			cur.total++;
			if (registry.groupAttended(p.id, latest, 0)) cur.present++;
			m.set(g.small_group, cur);
		}
		return m;
	});
	const groups = $derived(registry.d.smallGroups);
	const nameOf = (id: string | null) => (id ? registry.person(id)?.name ?? '' : '');
	// 공동체 재적·출석 합계 (care=true 면 자녀돌봄만 따로)
	const sum = (c: string, care = false) => {
		let t = 0, p = 0;
		for (const g of groups.filter((x) => x.community === c && x.care === care)) { t += stat.get(g.name)?.total ?? 0; p += stat.get(g.name)?.present ?? 0; }
		return { t, p };
	};
	const pct = (x: { t: number; p: number }) => (x.t ? Math.round((x.p * 100) / x.t) : 0);
</script>

<div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:.6rem;margin-bottom:.6rem">
	<h1 style="margin:0">{registry.t1} 편성표 <small class="muted">3개 {registry.t1} · {registry.t2} {groups.filter((g) => !g.care).length}개 + 자녀돌봄</small></h1>
</div>
<p class="muted" style="margin-top:0">{registry.t1}{registry.josa(registry.t1, '과')} {registry.t2} 편성표입니다. 출석은 현재 운영 중인 홈페이지의 자료를 그대로 공유합니다.</p>


{#each registry.communities as c}
	{@const t = sum(c.name)}
	{@const k = sum(c.name, true)}
	<div class="card" style="padding:0">
		<div class="card-head" style="padding:1.1rem 1.3rem 0">
			<div><h2><CommunityIcon name={c.name} />{c.name} <span class="tag">{bornText(registry.ys.communities[c.name] ?? { from: c.from, to: c.to })}</span></h2>
				<span class="sub">재적 {t.t}명 · <b class="att">{WEEKS[7].slice(5).replace('-', '/')} 출석 {t.p}명 ({pct(t)}%)</b>{#if k.t}<span class="muted"> &nbsp;|&nbsp; 자녀돌봄 {k.t}명 · <b class="att">출석 {k.p}명 ({pct(k)}%)</b></span>{/if}</span></div>
		</div>
		<div style="overflow-x:auto">
			<!-- 공동체마다 칸 폭이 같도록 비율로 고정 (소그룹 6자 : 인도자 4자 : 모임 장소 9자 : 인원 4자 : 출석 4자) -->
			<!-- 휴대폰에서는 글자를 조금 줄이고 이름·숫자가 한 줄에 들어가도록 비율을 살짝 조정 -->
			<table class="sm comm">
				<colgroup><col class="c-g" /><col class="c-l" /><col class="c-p" /><col class="c-n" /><col class="c-n" /></colgroup>
				<thead><tr><th>{registry.t2}</th><th>인도자</th><th>모임 장소</th><th>인원</th><th>출석</th></tr></thead>
				<tbody>
					{#each groups.filter((g) => g.community === c.name).sort((a, b) => a.sort_order - b.sort_order) as g (g.id)}
						{@const s = stat.get(g.name)}
						<tr>
							<td><a href={hrefTo(`/communities/${encodeURIComponent(g.name)}`)}><b>{g.name}</b></a></td>
							<td>{#if g.leader_id}<a href={hrefTo(`/${g.leader_id}`)}>{nameOf(g.leader_id)}</a>{:else}<span class="muted">-</span>{/if}</td>
							<td>{g.place ?? '-'}</td>
							<td style="white-space:nowrap">{s?.total ?? 0}명</td>
							<td style="white-space:nowrap">{s ? `${s.present}명` : '-'}</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	</div>
{/each}

<style>
	.att { color: var(--orange-600); font-weight: 700; } /* 이번 주 출석: 주황색으로 눈에 띄게 */
	.comm { table-layout: fixed; width: 100%; }
	.comm :is(th, td) { text-align: center; } /* 표 안 내용은 모두 가운데 정렬 */
	.c-g { width: 22%; } .c-l { width: 15%; } .c-p { width: 33%; } .c-n { width: 15%; }
	.comm td:nth-child(2), .comm td:nth-child(4), .comm td:nth-child(5) { white-space: nowrap; }
	@media (max-width: 720px) {
		.c-g { width: 24%; } .c-l { width: 17%; } .c-p { width: 33%; } .c-n { width: 13%; }
		.comm :is(th, td) { font-size: 0.8rem; padding: 0.5rem 0.35rem; }
	}
</style>
