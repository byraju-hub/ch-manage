<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 교인 등록: 등록카드 순서대로 입력 (세대·신급·직분·소속·봉사·동의까지 한 번에). 위쪽에서 등록카드를 찍으면 칸을 자동으로 채움
	import { goto } from '$app/navigation';
	import { registry } from '#lib/registry/store.svelte.ts';
	import PersonFields from '#lib/components/PersonFields.svelte';
	import HeadRelation from '#lib/components/HeadRelation.svelte';
	import CardScan from '#lib/components/CardScan.svelte';
	import { categoryPath, formatMobile } from '#lib/registry/derive.ts';
	import {
		CONSENT_KINDS, FAITH_KINDS, ORDAINED, POSITIONS, parseRank, rankChoices, rankLabel,
		type ConsentKind, type ConsentMethod, type FaithKind, type Person
	} from '#lib/registry/types.ts';

	const today = new Date().toISOString().slice(0, 10);
	let f = $state<Record<string, any>>({
		name: '', name_note: '', phone: '', gender: '', birth_date: '', birth_lunar: false, sms_opt_out: false, email: '', photo: '',
		address_zip: '', address1: '', address2: '', occupation: '', workplace: '', car_number: '', marital_status: '', wedding_date: '',
		previous_church: '', guide_person_id: '', guide_name: '', note: '', category_id: 'm.n.a', edu_dept: '', mission_society: '',
		clergy_title: '', big_family_head_id: '', big_family_relation: ''
	});
	let regDate = $state(today);
	// 세대
	let hhWith = $state('');
	let hhRelation = $state('본인');
	// 신급 · 직분
	let faith = $state<{ kind: FaithKind | '해당사항 없음'; date: string; church: string; officiant: string }>({ kind: '해당사항 없음', date: '', church: '', officiant: '' });
	let office = $state<{ pos: string; status: string; date: string; church: string }>({ pos: '성도', status: '시무', date: '', church: '' });
	let services = $state<string[]>(['', '', '']);
	let community = $state('');
	let smallGroup = $state('');
	// 동의
	let consents = $state<ConsentKind[]>(['교적 등록']);
	let method = $state<ConsentMethod>('서면');
	let ignoreDup = $state(false);

	const dups = $derived(f.name || f.phone ? registry.findDuplicates(f.name.trim(), f.phone) : []);
	const blocked = $derived(dups.length > 0 && !ignoreDup);
	const nn = (x: string) => (x.trim() === '' ? null : x.trim());
	const catPath = $derived(categoryPath(registry.d.categories, f.category_id || null));
	const isAdult = $derived(catPath[1]?.name === '장년');
	const position = $derived(POSITIONS.find((x) => x.label === office.pos)!);
	const statusOptions = $derived(position.office ? rankChoices(position.office) : []);
	const committees = $derived(registry.d.orgUnits.filter((o) => !o.parent_id).sort((a, b) => a.sort_order - b.sort_order));
	const missing = $derived([!f.photo && '사진', faith.kind === '해당사항 없음' && '신급', office.pos === '성도' && '직분'].filter(Boolean) as string[]);

	$effect(() => { if (statusOptions.length && !statusOptions.includes(office.status)) office.status = statusOptions[0]; });

	// 세대주 정보 가져오기: 주소와 우편번호를 세대주 것으로 채움
	function fetchHead(id: string) {
		const h = registry.householdOf(id);
		const p = registry.person(id);
		f.address1 = p?.address1 || h?.address || f.address1;
		f.address_zip = p?.address_zip || h?.zip || f.address_zip;
		if (p?.address2) f.address2 = p.address2;
	}

	// 등록카드 사진에서 읽은 내용으로 칸 채우기. 읽은 값이 있는 칸만 바꾸고, 채운 칸 이름을 돌려줌
	function applyCard(r: Record<string, any>): string[] {
		const done: string[] = [];
		const put = (key: string, label: string, v: unknown) => { if (v !== null && v !== undefined && v !== '') { f[key] = v; done.push(label); } };
		put('name', '이름', r.name);
		put('gender', '성별', r.gender);
		put('birth_date', '생년월일', r.birth_date);
		if (r.birth_date) f.birth_lunar = !!r.birth_lunar;
		put('phone', '휴대폰', r.phone ? formatMobile(r.phone) : null);
		put('email', '이메일', r.email);
		put('address1', '주소', r.address1);
		put('address2', '상세주소', r.address2);
		put('address_zip', '우편번호', r.address_zip);
		put('occupation', '직업/직장', r.occupation);
		put('marital_status', '결혼 여부', r.marital_status);
		put('wedding_date', '결혼기념일', r.wedding_date);
		put('previous_church', '이전 출석 교회', r.previous_church);
		const memo: string[] = [];
		// 인도자: 같은 이름의 교인이 한 명뿐이면 바로 고르고, 아니면 메모에 남김
		if (r.guide_name) {
			const same = registry.d.people.filter((p) => p.name === r.guide_name);
			if (same.length === 1) { f.guide_person_id = same[0].id; done.push('인도자'); }
			else memo.push(`인도자: ${r.guide_name}${same.length > 1 ? ' (동명이인 있음, 확인 필요)' : ''}`);
		}
		if (r.faith_kind) { faith = { kind: r.faith_kind, date: r.faith_date ?? '', church: r.faith_church ?? '', officiant: '' }; done.push('신급'); }
		if (r.office && POSITIONS.some((x) => x.label === r.office)) { office.pos = r.office; done.push('직분'); }
		if (r.reg_date) { regDate = r.reg_date; done.push('교회 등록일'); }
		if (r.family?.length) memo.push(`카드에 적힌 가족: ${r.family.map((m: any) => `${m.name}${m.relation ? `(${m.relation}${m.birth_year ? `, ${m.birth_year}년생` : ''})` : ''}`).join(', ')}`);
		if (r.memo) memo.push(r.memo);
		if (memo.length) { f.note = [f.note, ...memo].filter(Boolean).join('\n'); done.push('기타 메모'); }
		return done;
	}

	function submit(e: SubmitEvent) {
		e.preventDefault();
		const person: Omit<Person, 'id'> = {
			name: f.name.trim(), name_note: nn(f.name_note), phone: nn(f.phone), gender: f.gender || null,
			birth_date: nn(f.birth_date), email: nn(f.email), has_account: false, birth_lunar: f.birth_lunar, reg_no: null,
			address_zip: nn(f.address_zip), address1: nn(f.address1), address2: nn(f.address2),
			occupation: nn(f.occupation), workplace: nn(f.workplace), car_number: nn(f.car_number), marital_status: (nn(f.marital_status) as Person['marital_status']),
			wedding_date: nn(f.wedding_date), previous_church: nn(f.previous_church),
			guide_person_id: nn(f.guide_person_id), guide_name: f.guide_person_id ? null : nn(f.guide_name),
			sms_opt_out: f.sms_opt_out, faith_checked_on: null, note: nn(f.note), purged_on: null,
			photo: nn(f.photo), category_id: nn(f.category_id), clergy_title: position.office ? parseRank(office.status).clergy : null,
			edu_dept: nn(f.edu_dept) as Person['edu_dept'], mission_society: nn(f.mission_society),
			big_family_head_id: nn(f.big_family_head_id), big_family_relation: f.big_family_head_id ? nn(f.big_family_relation) : null
		};
		const id = registry.createPerson(person, nn(regDate), consents, method);
		// 세대
		const target = hhRelation !== '본인' && hhWith ? registry.householdOf(hhWith) : undefined;
		if (target) registry.assignToHousehold(id, target.id, hhRelation);
		else { const h = registry.createHousehold(person.address1); registry.assignToHousehold(id, h.id, '본인'); }
		// 신급
		if (faith.kind !== '해당사항 없음') registry.addFaith({ person_id: id, kind: faith.kind, event_date: nn(faith.date), church: nn(faith.church), officiant: nn(faith.officiant), note: null });
		else registry.updatePerson(id, { faith_checked_on: regDate });
		// 직분 · 임직
		if (position.office) {
			const ordained = ORDAINED.includes(position.office);
			registry.addOffice({ person_id: id, office: position.office, rank_status: parseRank(office.status).rank_status, start_date: ordained ? nn(office.date) : null, end_date: null, church: ordained ? nn(office.church) : null, note: null });
		}
		for (const org of new Set(services.filter(Boolean))) registry.addAffiliation({ person_id: id, org, role: null, start_date: regDate, end_date: null });
		if (isAdult && community && smallGroup) registry.setGroup(id, community, smallGroup);
		goto(hrefTo(`/${id}`));
	}
</script>

<h1>교인 등록</h1>
{#if !registry.perms.edit}
	<div class="warn">수정 권한이 꺼져 있어 등록할 수 없습니다. (최고관리자에게 수정 권한을 받아야 합니다)</div>
{:else}
	<CardScan onresult={applyCard} />
	<p class="muted"><b class="req">*</b> 표시는 필수 항목입니다. 같은 이름·전화가 있으면 먼저 보여 줍니다.</p>
	<form class="form card" onsubmit={submit}>
		<PersonFields bind:f autoNote>
			{#snippet household()}
				<div class="sec">
					<h3>세대주 · 관계</h3>
					<HeadRelation label="신앙세대주 / 관계" required bind:headId={hhWith} bind:relation={hhRelation} onfetch={fetchHead} note="※세대주일 경우에는, 관계만 선택(본인)하시면 됩니다." />
					{#if hhRelation !== '본인' && hhWith && !registry.householdOf(hhWith)}<div class="warn" style="margin-top:.5rem">그 교인은 아직 세대가 없어 새 세대로 등록됩니다.</div>{/if}
				</div>
			{/snippet}
		</PersonFields>

		{#if dups.length}
			<div class="wide warn">
				<b>같은 이름 또는 전화번호의 교인이 이미 있습니다.</b>
				<ul>{#each dups as d}<li><a href={hrefTo(`/${d.id}`)} target="_blank">{d.name}</a>{d.name_note ? ` (${d.name_note})` : ''} · {d.phone ?? '전화 없음'}</li>{/each}</ul>
				<label style="flex-direction:row;gap:.4rem"><input type="checkbox" style="width:auto" bind:checked={ignoreDup} />다른 사람입니다. 계속 등록 (동명이인이면 위 '동명이인 구분'을 골라 주세요)</label>
			</div>
		{/if}

		<div class="sec">
			<h3>신급 · 직분</h3>
			<div class="form">
				<label><span class="lt">신급<b class="req">*</b></span><select bind:value={faith.kind}><option>해당사항 없음</option>{#each FAITH_KINDS as k}<option>{k}</option>{/each}</select></label>
				<label>신급일<input type="date" bind:value={faith.date} disabled={faith.kind === '해당사항 없음'} /></label>
				<label>신급교회<input bind:value={faith.church} placeholder="비우면 본 교회" disabled={faith.kind === '해당사항 없음'} /></label>
				<label>집례자<input bind:value={faith.officiant} disabled={faith.kind === '해당사항 없음'} /></label>
				<label><span class="lt">직분<b class="req">*</b></span><select bind:value={office.pos}>{#each POSITIONS as o}<option>{o.label}</option>{/each}</select></label>
				{#if position.office}
					<label>구분<select bind:value={office.status}>{#each statusOptions as s}<option value={s}>{rankLabel(position.office!, s)}</option>{/each}</select></label>
					{#if ORDAINED.includes(position.office)}
						<label>임직일<input type="date" bind:value={office.date} /></label>
						<label>임직교회<input bind:value={office.church} placeholder="비우면 본 교회" /></label>
					{/if}
				{/if}
			</div>
		</div>

		<div class="sec">
			<h3>교회 등록일 · {registry.t1} · 교회봉사</h3>
			<div class="form">
				<label>교회 등록일<input type="date" bind:value={regDate} /></label>
				{#if isAdult}
					<label>{registry.t1}<select bind:value={community} onchange={() => (smallGroup = '')}><option value="">선택</option>{#each registry.communities as c}<option>{c.name}</option>{/each}</select></label>
					<label>{registry.t2}<select bind:value={smallGroup} disabled={!community}><option value="">선택</option>{#each registry.groupsIn(community) as g}<option>{g.name}</option>{/each}</select></label>
				{:else}
					<p class="wide muted" style="margin:0">{registry.t1}·{registry.t2}{registry.josa(registry.t2, '은')} 교인구분 2단계가 '장년'일 때 고릅니다.</p>
				{/if}
				{#each [0, 1, 2] as i}
					<label>봉사 부서 {i + 1}
						<select bind:value={services[i]}>
							<option value="">선택 안 함</option>
							{#each committees as c}
								<optgroup label={c.name}>{#each registry.d.orgUnits.filter((o) => o.parent_id === c.id) as d}<option value={d.name} disabled={services.some((x, j) => j !== i && x === d.name)}>{d.name}</option>{/each}</optgroup>
							{/each}
						</select>
					</label>
				{/each}
			</div>
		</div>

		<div class="sec">
			<h3>개인정보 동의 <span class="muted" style="font-weight:400">(서면 동의는 담당자가 입력)</span></h3>
			<div class="cols">
				{#each CONSENT_KINDS as k}<label><input type="checkbox" value={k} bind:group={consents} />{k}</label>{/each}
				<label>방법<select bind:value={method} style="width:auto">{#each ['서면', '앱', '구두'] as m}<option>{m}</option>{/each}</select></label>
			</div>
		</div>

		{#if missing.length}<div class="wide warn">필수 항목 중 아직 비어 있는 것: <b>{missing.join(' · ')}</b> (시험 중이라 그대로 등록할 수 있습니다)</div>{/if}
		<div class="wide row"><button type="submit" disabled={!f.name.trim() || blocked}>등록</button><a class="btn ghost" href={hrefTo('/members')}>취소</a></div>
	</form>
{/if}
