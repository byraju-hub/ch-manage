<script lang="ts">
	// 교인 카드 입력 칸 묶음 (교인 등록과 교적 카드 수정에서 함께 씀)
	import type { Snippet } from 'svelte';
	import { registry } from '#lib/registry/store.svelte.ts';
	import PersonPicker from '#lib/components/PersonPicker.svelte';
	import HeadRelation from '#lib/components/HeadRelation.svelte';
	import PhotoCropper from '#lib/components/PhotoCropper.svelte';
	import { ageOf, ageText, categoryPath, formatMobile } from '#lib/registry/derive.ts';
	import { EDU_DEPTS, MARITAL_STATUSES, NAME_NOTES } from '#lib/registry/types.ts';

	// f: 교인 카드의 칸들(빈 칸은 빈 문자열). 등록 화면과 카드 수정 화면이 함께 씀
	let { f = $bindable(), household, autoNote = false }: { f: Record<string, any>; household?: Snippet; autoNote?: boolean } = $props();
	// 새로 등록할 때는 동명이인 구분을 등록 순서대로 자동으로 붙임
	const nextNote = $derived(autoNote && f.name?.trim() ? registry.nextNameNote(f.name) : null);

	// ---- 교인구분 3단계 ----
	const initPath = categoryPath(registry.d.categories, f.category_id || null).map((c) => c.id);
	let l1 = $state(initPath[0] ?? '');
	let l2 = $state(initPath[1] ?? '');
	let l3 = $state(initPath[2] ?? '');
	// 별세·이사/이민·보관은 재적 이력을 남기면 자동으로 바뀌므로 직접 고르지 않음
	const FIXED = ['dead', 'move', 'keep'];
	const o1 = $derived(registry.childrenOf(null).filter((c) => !FIXED.includes(c.id) || c.id === l1));
	const o2 = $derived(l1 ? registry.childrenOf(l1) : []);
	const o3 = $derived(l2 ? registry.childrenOf(l2) : []);
	function pickL1() { l2 = ''; l3 = ''; sync(); }
	function pickL2() { l3 = ''; sync(); }
	function sync() {
		f.category_id = l3 || l2 || l1 || '';
		// 2단계가 청년부·중고등부 같은 교육부서이면 교육부서도 함께 맞춤
		const name = registry.d.categories.find((c) => c.id === l2)?.name ?? '';
		if ((EDU_DEPTS as string[]).includes(name)) f.edu_dept = name;
		else if (name === '장년') f.edu_dept = '';
	}

	// ---- 사진: 올리면 자르는 창이 뜨고, 작게 줄여 저장 ----
	let cropFile = $state<File | null>(null);
	function onPhoto(e: Event) {
		const input = e.currentTarget as HTMLInputElement;
		cropFile = input.files?.[0] ?? null;
		input.value = '';
	}

	// ---- 도로명 주소 검색: 주소를 고르면 우편번호도 함께 들어감 ----
	let addrMsg = $state('');
	function searchAddress() {
		addrMsg = '';
		const open = () => new (window as any).daum.Postcode({
			oncomplete: (d: any) => { f.address_zip = d.zonecode; f.address1 = d.roadAddress || d.jibunAddress; }
		}).open();
		if ((window as any).daum?.Postcode) return open();
		const sc = document.createElement('script');
		sc.src = 'https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
		sc.onload = open;
		sc.onerror = () => (addrMsg = '주소 검색 서비스에 연결할 수 없습니다. 직접 입력해 주세요. (인터넷 연결이 필요합니다)');
		document.head.appendChild(sc);
	}
	const age = $derived(f.birth_date ? ageOf(f.birth_date) : null);
	const noteOptions = $derived(f.name_note && !NAME_NOTES.includes(f.name_note) ? [...NAME_NOTES, f.name_note] : NAME_NOTES);
</script>

{#if cropFile}
	<PhotoCropper file={cropFile} oncancel={() => (cropFile = null)} ondone={(url) => { f.photo = url; cropFile = null; }} />
{/if}

<div class="sec">
	<h3>기본 정보</h3>
	<div class="form">
		<label><span class="lt">이름<b class="req">*</b></span><input required bind:value={f.name} /></label>
		{#if autoNote}
			<label><span class="lt">동명이인 구분 <small class="hint">(자동)</small></span><input readonly tabindex="-1" value={nextNote ? `${nextNote} (같은 이름이 있어 등록 순서대로)` : '해당 없음'} /></label>
		{:else}
			<label>동명이인 구분<select bind:value={f.name_note}><option value="">해당 없음</option>{#each noteOptions as n}<option>{n}</option>{/each}</select></label>
		{/if}
		<label><span class="lt">생년월일<b class="req">*</b></span><input type="date" required bind:value={f.birth_date} /></label>
		<label>양력 / 음력<select bind:value={f.birth_lunar}><option value={false}>양력</option><option value={true}>음력</option></select></label>
		<label><span class="lt">나이 <small class="hint">(자동)</small></span><input value={age === null ? '' : ageText(f.birth_date)} readonly tabindex="-1" /></label>
		<label><span class="lt">성별<b class="req">*</b></span><select required bind:value={f.gender}><option value="">선택</option><option value="M">남</option><option value="F">여</option></select></label>
		<label>
			<span class="row" style="justify-content:space-between;flex-wrap:nowrap"><span>휴대폰<b class="req">*</b></span>
				<span class="mini"><input type="checkbox" bind:checked={f.sms_opt_out} />문자 수신 거부</span></span>
			<input required value={f.phone} oninput={(e) => (e.currentTarget.value = f.phone = formatMobile(e.currentTarget.value))} inputmode="numeric" maxlength="13" placeholder="010-0000-0000" />
		</label>
		<label>이메일<input type="email" bind:value={f.email} /></label>
		<label class="photo-box"><span class="lt">사진<b class="req">*</b></span>
			<span class="row" style="flex-wrap:nowrap">
				{#if f.photo}<img src={f.photo} alt="사진 미리보기" class="photo" />{:else}<span class="photo empty">없음</span>{/if}
				<input type="file" accept="image/*" onchange={onPhoto} style="padding:.35rem" />
				{#if f.photo}<button type="button" class="ghost sm" onclick={() => (f.photo = '')}>지우기</button>{/if}
			</span>
		</label>
	</div>
</div>

{@render household?.()}

<div class="sec">
	<h3>주소</h3>
	<div class="form">
		<label class="wide"><span class="lt">주소<b class="req">*</b> <small class="hint">(검색해서 고르면 우편번호가 함께 들어갑니다)</small></span>
			<span class="row" style="flex-wrap:nowrap"><input required bind:value={f.address1} placeholder="주소 검색 버튼을 누르세요" /><button type="button" class="ghost" onclick={searchAddress}>주소 검색</button></span>
			{#if f.address_zip}<span class="muted" style="font-weight:400">우편번호 {f.address_zip}</span>{/if}
			{#if addrMsg}<span class="muted" style="font-weight:400">{addrMsg}</span>{/if}
		</label>
		<label class="wide">상세주소<input bind:value={f.address2} /></label>
	</div>
</div>

<div class="sec">
	<h3>직업 · 직장 · 차량</h3>
	<div class="form">
		<label>직업 / 직장<input bind:value={f.occupation} placeholder="예: 목사 / 은혜교회" /></label>
		<label>차량 번호<input bind:value={f.car_number} placeholder="예: 12가 3456 (여러 대면 쉼표)" /></label>
	</div>
</div>

<div class="sec">
	<h3>결혼</h3>
	<div class="form">
		<label>결혼 여부<select bind:value={f.marital_status}><option value="">-</option>{#each MARITAL_STATUSES as m}<option>{m}</option>{/each}</select></label>
		<label>결혼기념일<input type="date" bind:value={f.wedding_date} /></label>
	</div>
</div>

<div class="sec">
	<h3>교회 등록 · 인도</h3>
	<div class="form">
		<label>이전 출석 교회<input bind:value={f.previous_church} /></label>
		<label><span class="lt">인도자 <small class="hint">(교인만 선택)</small></span><PersonPicker bind:value={f.guide_person_id} placeholder="인도한 교인 이름 검색" /></label>
		<div class="wide"><HeadRelation label="대가족 / 관계" bind:headId={f.big_family_head_id} bind:relation={f.big_family_relation} withSelf={false} note="같은 집이 아닌 가족을 이을 때만 선택" /></div>
		<label class="wide">기타 메모<textarea rows="7" bind:value={f.note}></textarea></label>
	</div>
</div>

<div class="sec">
	<h3>교인구분 · 전도회</h3>
	<div class="form">
		<label>교인구분 (1단계)<select bind:value={l1} onchange={pickL1}><option value="">선택</option>{#each o1 as c}<option value={c.id}>{c.name}</option>{/each}</select></label>
		<label>2단계<select bind:value={l2} onchange={pickL2} disabled={!o2.length}><option value="">{o2.length ? '선택' : '-'}</option>{#each o2 as c}<option value={c.id}>{c.name}</option>{/each}</select></label>
		<label>3단계<select bind:value={l3} onchange={sync} disabled={!o3.length}><option value="">{o3.length ? '선택' : '-'}</option>{#each o3 as c}<option value={c.id}>{c.name}</option>{/each}</select></label>
		<label>전도회<select bind:value={f.mission_society}>
			<option value="">해당 없음</option>
			<optgroup label="남전도회">{#each registry.codes.missionSocieties.filter((s) => s.includes('남')) as s}<option>{s}</option>{/each}</optgroup>
			<optgroup label="여전도회">{#each registry.codes.missionSocieties.filter((s) => s.includes('여')) as s}<option>{s}</option>{/each}</optgroup>
		</select></label>
	</div>
</div>

<style>
	.photo { width: 96px; height: 128px; border-radius: 10px; object-fit: cover; border: 1px solid var(--line-strong); flex: none; }
	.photo.empty { display: grid; place-items: center; background: var(--primary-50); color: var(--muted); font-size: 0.72rem; font-weight: 500; }
	.mini { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.72rem; font-weight: 500; color: var(--muted); cursor: pointer; }
	.mini input { width: 14px; height: 14px; }
</style>
