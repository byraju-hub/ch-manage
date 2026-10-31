<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	import { registry } from '#lib/registry/store.svelte.ts';
	import { ageOf, ageText } from '#lib/registry/derive.ts';
	import { fixCat } from '#lib/registry/config.ts';
	import type { Person } from '#lib/registry/types.ts';

	// 새가족 정착 흐름: 등록 → 새가족 교육 → 수료 → 소그룹 배정 → 정착 완료
	// '정착 완료'를 누르면 교인구분 2단계가 새가족 → 장년(또는 나이에 맞는 부서)으로 바뀝니다.
	// [실제 시스템] 단계는 교육 기록·소그룹 소속·교인구분에서 계산하므로 따로 저장하지 않습니다.
	const STAGES = ['등록', '교육 중', '수료', '소그룹 배정', '정착 완료'] as const;
	type Stage = (typeof STAGES)[number];
	const today = new Date().toISOString().slice(0, 10);
	const dayMs = 864e5;

	const list = $derived.by(() => {
		const out: { p: Person; stage: Stage; reg: string; days: number }[] = [];
		for (const p of registry.d.people) {
			const ev = registry.statusOf(p.id);
			const isNew = ev.some((e) => e.reason === '새가족');
			const s = registry.summary(p);
			if (!s.memberCert || (!isNew && s.cat2 !== '새가족')) continue;
			const reg = s.registered ?? today;
			const days = Math.round((+new Date(today) - +new Date(reg)) / dayMs);
			if (s.cat2 !== '새가족' && days > 365) continue; // 정착한 지 오래된 사람은 뺌
			const ed = registry.d.educationRecords.filter((r) => r.person_id === p.id);
			let stage: Stage;
			if (s.cat2 !== '새가족') stage = '정착 완료';
			// 14세 미만 어린이는 새가족 교육 없이 교육부서로 바로 (정착 완료만 누르면 됨)
			else if ((ageOf(p.birth_date) ?? 30) < 14) stage = '소그룹 배정';
			else if (!ed.length) stage = '등록';
			else if (!ed.some((r) => r.completed)) stage = '교육 중';
			else if (!s.smallGroup && (ageOf(p.birth_date) ?? 30) >= 20) stage = '수료';
			else stage = '소그룹 배정';
			out.push({ p, stage, reg, days });
		}
		return out.sort((a, b) => b.reg.localeCompare(a.reg));
	});
	const by = (st: Stage) => list.filter((x) => x.stage === st);
	const pick = $state<Record<string, { c: string; g: string }>>({});
	const commByAge = (p: Person) => {
		const y = Number(p.birth_date?.slice(0, 4) ?? 1980);
		return registry.communities.find((c) => y >= c.from && y <= c.to)?.name ?? '';
	};
	// 수료 단계의 사람에게 공동체(출생 연도 기준) 기본값을 미리 채워 둠
	$effect(() => {
		for (const x of list) if (x.stage === '수료' && !pick[x.p.id]) pick[x.p.id] = { c: commByAge(x.p), g: '' };
	});
	const guide = (p: Person) => (p.guide_person_id ? registry.person(p.guide_person_id)?.name : p.guide_name) ?? '';

	function startEdu(p: Person) { registry.addEducation({ person_id: p.id, course_id: 'ec1', start_date: today, end_date: null, completed: false, instructor: null, note: '진행 중' }); }
	function finishEdu(p: Person) {
		const r = registry.d.educationRecords.find((x) => x.person_id === p.id && !x.completed);
		if (r) registry.completeEducation(r.id, today);
	}
	function assign(p: Person) {
		const v = pick[p.id] ?? { c: commByAge(p), g: '' };
		if (v.c && v.g) registry.setGroup(p.id, v.c, v.g);
	}
	// 정착 완료: 나이에 맞는 2단계로 (20세 이상 → 장년)
	function settle(p: Person) {
		const age = ageOf(p.birth_date) ?? 30;
		const cur = p.category_id ?? 'm.n.a';
		if (!cur.startsWith('m.')) return; // 미등록/타교인 등은 하위 단계가 없으므로 그대로
		const l3 = cur.split('.')[2] ?? 'a';
		const l2 = age <= 2 ? 'b' : age <= 6 ? 'k' : age <= 12 ? 'e' : age <= 18 ? 'h' : age <= 34 && p.marital_status !== '기혼' ? 'y' : 'a';
		registry.updatePerson(p.id, { category_id: fixCat(`m.${l2}.${l3}`) });
	}
	const avgDays = $derived.by(() => {
		const done = by('정착 완료');
		return done.length ? Math.round(done.reduce((a, b) => a + b.days, 0) / done.length) : 0;
	});
</script>

<h1>새가족 정착</h1>
<p class="muted" style="margin-top:-.6rem">등록 → 새가족 교육 → 수료 → {registry.t2} 배정 → 정착 완료. 단계마다 다음으로 넘기는 버튼이 있습니다. (최근 1년 새가족)</p>

<div class="pipe-sum">
	{#each STAGES as st, i}
		<div class="ps"><span>{i + 1}</span><b>{by(st).length}</b><small>{st}</small></div>
		{#if i < STAGES.length - 1}<i class="arrow">›</i>{/if}
	{/each}
</div>
{#if avgDays}<p class="muted">정착 완료까지 등록 후 평균 {avgDays}일</p>{/if}

<div class="pipe">
	{#each STAGES as st}
		<section class="pipe-col">
			<h3>{st === '소그룹 배정' ? `${registry.t2}·부서 배정` : st} <span>{by(st).length}</span></h3>
			{#each by(st).slice(0, st === '정착 완료' ? 12 : 60) as x (x.p.id)}
				{@const s = registry.summary(x.p)}
				<div class="pcard">
					<div class="row" style="justify-content:space-between;flex-wrap:nowrap">
						<a href={hrefTo(`/${x.p.id}`)}><b>{x.p.name}</b></a>
						<span class="dday" class:today={x.days > 60 && st !== '정착 완료'}>{x.days}일째</span>
					</div>
					<small class="muted">등록 {x.reg} · {ageText(x.p.birth_date)}{guide(x.p) ? ` · 인도 ${guide(x.p)}` : ''}</small>
					{#if s.smallGroup}<small class="muted">{s.community} › {s.smallGroup}</small>{/if}
					{#if registry.perms.edit}
						{#if st === '등록'}<button class="ghost sm" onclick={() => startEdu(x.p)}>새가족 교육 시작</button>
						{:else if st === '교육 중'}<button class="ghost sm" onclick={() => finishEdu(x.p)}>수료 처리</button>
						{:else if st === '수료'}
							{@const v = pick[x.p.id]}
							{#if v}
							<div class="row" style="flex-wrap:nowrap;gap:.3rem">
								<select bind:value={v.c} style="padding:.3rem .5rem;font-size:.8rem">{#each registry.communities as c}<option>{c.name}</option>{/each}</select>
								<select bind:value={v.g} style="padding:.3rem .5rem;font-size:.8rem"><option value="">{registry.t2}</option>{#each registry.groupsIn(v.c).filter((g) => !g.care) as g}<option>{g.name}</option>{/each}</select>
							</div>
							<button class="ghost sm" disabled={!v.g} onclick={() => assign(x.p)}>{registry.t2} 배정</button>
							{/if}
						{:else if st === '소그룹 배정'}<button class="sm" onclick={() => settle(x.p)}>정착 완료</button>
						{/if}
					{/if}
				</div>
			{:else}<p class="empty" style="padding:.8rem 0">없음</p>{/each}
		</section>
	{/each}
</div>

<style>
	.pipe-sum { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.6rem; }
	.ps { display: flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1rem; background: #fff; border: 1px solid var(--line); border-radius: 14px; box-shadow: var(--shadow-sm); }
	.ps span { width: 1.4rem; height: 1.4rem; border-radius: 50%; display: grid; place-items: center; font-size: 0.72rem; font-weight: 800; background: var(--primary); color: #fff; }
	.ps b { font-size: 1.3rem; }
	.ps small { color: var(--muted); font-weight: 600; }
	.arrow { font-style: normal; color: var(--muted); font-size: 1.3rem; }
	.pipe { display: grid; grid-template-columns: repeat(5, minmax(200px, 1fr)); gap: 0.8rem; overflow-x: auto; padding-bottom: 0.5rem; align-items: start; }
	.pipe-col { background: var(--primary-50); border-radius: 14px; padding: 0.7rem; max-height: 75vh; overflow: auto; }
	.pipe-col h3 { margin: 0.1rem 0.2rem 0.6rem; font-size: 0.92rem; display: flex; justify-content: space-between; }
	.pipe-col h3 span { color: var(--muted); }
	.pcard { display: flex; flex-direction: column; gap: 0.3rem; background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 0.65rem 0.7rem; margin-bottom: 0.5rem; box-shadow: var(--shadow-sm); }
	.pcard small { font-size: 0.76rem; }
</style>
