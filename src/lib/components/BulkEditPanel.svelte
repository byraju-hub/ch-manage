<script lang="ts">
	// 일괄 수정 (최고관리자만): 목록에서 체크한 교인의 교인구분 · 신급 · 직분 · 공동체·소그룹을 한꺼번에 바꿈.
	// 교인 목록과 '교인 목록 항목 설정' 화면이 함께 씀. 바꾼 내용은 각 교인의 변경 이력에 남음
	import { registry } from '#lib/registry/store.svelte.ts';
	import { categoryPath } from '#lib/registry/derive.ts';
	import { OFFICE_NAMES, rankChoices, rankLabel, type FaithKind, type OfficeName } from '#lib/registry/types.ts';

	let { ids, onclear, onclose }: { ids: string[]; onclear: () => void; onclose: () => void } = $props();

	let bk = $state<'category' | 'faith' | 'office' | 'group'>('category');
	// 교인구분: 1 → 2 → 3단계 (고른 데까지 적용)
	let bc1 = $state(''), bc2 = $state(''), bc3 = $state('');
	const bCat = $derived(bc3 || bc2 || bc1);
	let bFaith = $state<FaithKind>('세례');
	let bOffice = $state<OfficeName>('서리집사');
	let bRank = $state('서리');
	$effect(() => { bRank = rankChoices(bOffice)[0]; });
	let bDate = $state(new Date().toISOString().slice(0, 10));
	let bEndOld = $state(true);
	let bComm = $state('');
	let bGroup = $state('');
	let bMsg = $state('');
	// 다시 체크하기 시작하면 앞의 안내는 지움
	$effect(() => { if (ids.length) bMsg = ''; });
	const bLabel = $derived(
		bk === 'category' ? `교인구분을 '${categoryPath(registry.d.categories, bCat || null).map((c) => c.name).join(' › ')}'(으)로`
		: bk === 'faith' ? `신급 '${bFaith}' 기록(${bDate || '날짜 미상'})을 추가`
		: bk === 'office' ? `직분을 '${bOffice} · ${bRank}'(${bDate || '날짜 미상'})(으)로${bEndOld ? ' 바꾸고 지금 직분은 마침 처리' : ' 추가'}`
		: bComm && bGroup ? `${registry.t2}을(를) '${bComm} › ${bGroup}'(으)로` : `${registry.t1}·${registry.t2} 소속을 없앰`
	);
	const bReady = $derived(ids.length > 0 && (bk !== 'category' || !!bCat) && (bk !== 'group' || !bComm || !!bGroup));
	function apply() {
		if (!bReady) return;
		if (!confirm(`선택한 ${ids.length}명의 ${bLabel} 바꿉니다.\n각 교인의 변경 이력에 남습니다. 계속할까요?`)) return;
		const list = [...ids];
		const n = registry.bulkEdit(list, bk === 'category' ? { kind: 'category', category_id: bCat }
			: bk === 'faith' ? { kind: 'faith', faith: bFaith, date: bDate || null }
			: bk === 'office' ? { kind: 'office', office: bOffice, rank: bRank, date: bDate || null, endOld: bEndOld }
			: { kind: 'group', community: bGroup ? bComm : '', small_group: bGroup });
		onclear();
		bMsg = `${n}명을 바꿨습니다.${list.length - n ? ` (이미 같은 값인 ${list.length - n}명은 그대로)` : ''}`;
	}
</script>

<div class="card bulk" class:active={ids.length > 0}>
	<div class="bulk-top">
		<b>일괄 수정</b>
		<span class="muted">{ids.length ? `${ids.length}명 선택됨` : '아래 목록에서 교인을 체크하세요 (맨 위 체크는 검색 결과 전체)'}</span>
		{#if ids.length}<button type="button" class="ghost sm" onclick={onclear}>선택 해제</button>{/if}
		<span style="flex:1"></span><button type="button" class="ghost sm" onclick={onclose}>닫기</button>
	</div>
	<div class="toolbar" style="margin:.6rem 0 0">
		<div class="seg" role="tablist" aria-label="일괄 수정 항목">
			<button type="button" class:on={bk === 'category'} onclick={() => (bk = 'category')}>교인구분</button>
			<button type="button" class:on={bk === 'faith'} onclick={() => (bk = 'faith')}>신급</button>
			<button type="button" class:on={bk === 'office'} onclick={() => (bk = 'office')}>직분</button>
			<button type="button" class:on={bk === 'group'} onclick={() => (bk = 'group')}>{registry.t1}·{registry.t2}</button>
		</div>
	</div>
	<div class="toolbar" style="margin:.6rem 0 0">
		{#if bk === 'category'}
			<select bind:value={bc1} onchange={() => { bc2 = ''; bc3 = ''; }} aria-label="1단계"><option value="">1단계 선택</option>{#each registry.childrenOf(null) as c}<option value={c.id}>{c.name}</option>{/each}</select>
			{#if bc1 && registry.childrenOf(bc1).length}<select bind:value={bc2} onchange={() => (bc3 = '')} aria-label="2단계"><option value="">2단계 선택</option>{#each registry.childrenOf(bc1) as c}<option value={c.id}>{c.name}</option>{/each}</select>{/if}
			{#if bc2 && registry.childrenOf(bc2).length}<select bind:value={bc3} aria-label="3단계"><option value="">3단계 선택</option>{#each registry.childrenOf(bc2) as c}<option value={c.id}>{c.name}</option>{/each}</select>{/if}
		{:else if bk === 'faith'}
			<select bind:value={bFaith} aria-label="신급">{#each ['학습', '세례', '유아세례', '입교'] as f}<option>{f}</option>{/each}</select>
			<span class="muted">받은 날</span><input type="date" bind:value={bDate} />
			<span class="muted" style="font-size:.8rem">같은 기록이 이미 있는 교인은 건너뜁니다.</span>
		{:else if bk === 'office'}
			<select bind:value={bOffice} aria-label="직분">{#each OFFICE_NAMES as o}<option>{o}</option>{/each}</select>
			<select bind:value={bRank} aria-label="구분">{#each rankChoices(bOffice) as r}<option value={r}>{rankLabel(bOffice, r)}</option>{/each}</select>
			<span class="muted">임직(시작)일</span><input type="date" bind:value={bDate} />
			<label class="row" style="gap:.3rem"><input type="checkbox" bind:checked={bEndOld} />지금 직분은 마침 처리</label>
		{:else}
			<select bind:value={bComm} onchange={() => (bGroup = '')} aria-label="{registry.t1}"><option value="">소속 없음 (빼기)</option>{#each registry.communities as c}<option>{c.name}</option>{/each}</select>
			{#if bComm}<select bind:value={bGroup} aria-label="{registry.t2}"><option value="">{registry.t2} 선택</option>{#each registry.groupsIn(bComm) as g}<option>{g.name}</option>{/each}</select>{/if}
		{/if}
		<span style="flex:1"></span>
		<button type="button" onclick={apply} disabled={!bReady}>선택한 {ids.length}명에 적용</button>
	</div>
	{#if bMsg}<p class="ok" style="margin:.6rem 0 0">{bMsg}</p>{/if}
</div>

<style>
	.bulk { border: 1px dashed var(--line); }
	.bulk.active { border: 1.5px solid var(--primary); background: var(--primary-50); }
	.bulk-top { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; }
</style>
