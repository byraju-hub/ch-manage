<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	import { dm } from '#lib/registry/dm.svelte.ts';
	import { recentSundays } from '#lib/registry/weeks.ts';
	// 교적 카드: 기본 정보 · 세대 · 신급/직분 · 재적 이력 · 소속 · 교육 · 심방 · 메모/동의 · 변경 이력
	import { page } from '$app/state';
	import { registry } from '#lib/registry/store.svelte.ts';
	import PersonFields from '#lib/components/PersonFields.svelte';
	import NameChips, { type Chip } from '#lib/components/NameChips.svelte';
	import VisitAnalyzer, { type Analysis } from '#lib/components/VisitAnalyzer.svelte';
	import CarePreview from '#lib/components/CarePreview.svelte';
	import { ageOf, ageText, officeWithKind } from '#lib/registry/derive.ts';
	import {
		STATUS_KINDS, FAITH_KINDS, POSITIONS, ORDAINED, parseRank, rankChoices, rankLabel, CONSENT_KINDS, FULL_TIME_CLERGY,
		type StatusKind, type FaithKind, type ConsentKind, type VisitMethod
	} from '#lib/registry/types.ts';

	const p = $derived(registry.person(page.params.id!));
	const s = $derived(p ? registry.summary(p) : null);
	const hh = $derived(p ? registry.householdOf(p.id) : undefined);
	const can = $derived(registry.perms.edit);
	const today = new Date().toISOString().slice(0, 10);

	// '심방' 탭은 A 등급만 보임
	// 부서 관리(dep-manage)와 연결된 교인이면 부서·학년과 최근 출석을 보여 준다
	const dmInfo = $derived(p ? dm.links[p.id] : undefined);
	const dmWeeks = $derived(p ? recentSundays(8).map((d) => ({ d, v: dm.att[d]?.[p.id] })) : []);
	const tabs = $derived(['기본 정보', '세대', '신급·직분', '재적 이력', '소속', '교육', ...(registry.perms.visit ? ['심방'] : []), '메모·동의', '변경 이력']);
	let tab = $state('기본 정보');
	let editing = $state(false);
	let draft = $state<Record<string, any>>({});

	// 열람 기록 (접속기록에 남음)
	let logged = '';
	$effect(() => {
		if (p && logged !== p.id) {
			logged = p.id;
			registry.log('열람', p.name);
		}
	});

	const v = (x: unknown) => (x === null || x === undefined || x === '' ? '-' : String(x));
	const byDate = <T extends { event_date?: string | null; start_date?: string | null }>(xs: T[]) =>
		[...xs].sort((a, b) => (b.event_date ?? b.start_date ?? '').localeCompare(a.event_date ?? a.start_date ?? ''));
	const nn = (x: string) => (x === '' || x === undefined ? null : x);

	const EDIT_KEYS = ['name', 'name_note', 'phone', 'gender', 'birth_date', 'birth_lunar', 'email', 'address_zip', 'address1', 'address2', 'occupation', 'workplace', 'car_number', 'marital_status', 'wedding_date', 'previous_church', 'guide_person_id', 'guide_name', 'sms_opt_out', 'note', 'photo', 'category_id', 'clergy_title', 'edu_dept', 'mission_society', 'big_family_head_id', 'big_family_relation'];
	function startEdit() {
		draft = {};
		for (const k of EDIT_KEYS) draft[k] = (p as any)[k] ?? (k === 'birth_lunar' || k === 'sms_opt_out' ? false : '');
		// 직업과 직장은 한 칸으로 합쳐 보여 줌
		draft.occupation = [p!.occupation, p!.workplace].filter(Boolean).join(' / ');
		draft.workplace = '';
		editing = true;
	}
	// 교인 목록 상세의 '수정'으로 들어오면(?edit=1) 바로 기본 정보 수정 화면으로
	let autoEdited = '';
	$effect(() => {
		if (p && can && page.url.searchParams.get('edit') === '1' && autoEdited !== p.id) {
			autoEdited = p.id;
			tab = '기본 정보';
			startEdit();
		}
	});
	function saveEdit() {
		const patch: Record<string, any> = {};
		for (const k of EDIT_KEYS) patch[k] = typeof draft[k] === 'string' ? nn(draft[k]) : draft[k];
		if (patch.guide_person_id) patch.guide_name = null;
		registry.updatePerson(p!.id, patch);
		editing = false;
	}

	// 이력 입력 폼 상태
	let st = $state<{ kind: StatusKind; date: string; reason: string; church: string }>({ kind: '등록', date: '', reason: '', church: '' });
	let fa = $state<{ kind: FaithKind; date: string; church: string; officiant: string }>({ kind: '세례', date: '', church: '', officiant: '' });
	let of = $state<{ pos: string; rank: string; start: string; end: string; church: string }>({ pos: '서리집사', rank: '서리', start: '', end: '', church: '' });
	const ofPos = $derived(POSITIONS.find((x) => x.label === of.pos)!);
	let noteBody = $state('');
	let cs = $state<{ kind: ConsentKind; method: '서면' | '앱' | '구두'; guardian: string }>({ kind: '교적 등록', method: '서면', guardian: '' });
	let vs = $state({ date: today, kind: '일반', method: '대면' as VisitMethod, pastor: '', scripture: '', hymn: '', content: '', follow: '' });
	let vsCompanions = $state<Chip[]>([]);
	let vsMsg = $state(''); // 심방 내용에서 자동으로 넣은 성도 일정 안내
	function applyAnalysis(r: Analysis) {
		if (r.kind) vs.kind = r.kind;
		if (r.content) vs.content = r.content;
		if (r.scripture) vs.scripture = r.scripture;
		if (r.hymn) vs.hymn = r.hymn;
		if (r.follow_up) vs.follow = r.follow_up;
		if (r.companions?.length) vsCompanions = r.companions.map((n) => { const m = registry.d.people.filter((x) => x.name === n); return { id: m.length === 1 ? m[0].id : null, name: n }; });
	}
	let af = $state({ org: '', role: '', start: '' });
	let grp = $state({ community: '', small_group: '' });
	let ed = $state({ course: 'ec1', start: today, instructor: '' });
	let showMemo = $state(false);

	const myNotes = $derived(p ? registry.d.notes.filter((n) => n.person_id === p.id) : []);
	const memos = $derived(p ? registry.d.legacyMemos.filter((n) => n.person_id === p.id) : []);
	const consents = $derived(p ? registry.d.consents.filter((n) => n.person_id === p.id) : []);
	const logs = $derived(p ? registry.d.changeLog.filter((n) => n.person_id === p.id) : []);
	const visits = $derived(p ? registry.d.visits.filter((n) => n.person_id === p.id) : []);
	const affs = $derived(p ? registry.d.affiliations.filter((n) => n.person_id === p.id) : []);
	const gnotes = $derived(p ? registry.d.groupNotes.filter((n) => n.person_id === p.id) : []);
	const edus = $derived(p ? registry.d.educationRecords.filter((n) => n.person_id === p.id) : []);
	const family = $derived(hh ? registry.membersOfHousehold(hh.id) : []);
	const clergy = $derived(registry.d.people.filter((x) => x.clergy_title && FULL_TIME_CLERGY.includes(x.clergy_title)));
	const depts = $derived(registry.d.orgUnits.filter((o) => o.parent_id));
	const courseName = (id: string) => registry.d.educationCourses.find((c) => c.id === id)?.name ?? '';
	const bigHead = $derived(p?.big_family_head_id ? registry.person(p.big_family_head_id) : undefined);
	const statusOpts = $derived(rankChoices(ofPos.office!));
	$effect(() => { if (!statusOpts.includes(of.rank)) of.rank = statusOpts[0]; });

	function openMemo() {
		showMemo = true;
		registry.log('이전 프로그램 메모 열람', p!.name);
	}
</script>

{#if p && s}
	<div class="row" style="justify-content:space-between">
		<div class="row" style="flex-wrap:nowrap;gap:1rem">
			{#if p.photo}<img class="mugshot" src={p.photo} alt="{p.name} 사진" />{:else}<span class="avatar" style="width:56px;height:56px;font-size:1.3rem">{p.name.slice(0, 1)}</span>{/if}
			<h1 style="margin:0">
				{p.name}{#if s.office}<small class="office-k"> {officeWithKind(s.office, p.clergy_title)}</small>{/if}
				<span class="badge {s.status === '재적' ? '' : s.status === '보류' ? 'inactive' : 'transferred'}">{s.status}</span>
				<span class="badge">{s.faith}</span>
				{#if s.memberCert}<span class="tag now">앱 교인 인증 켜짐</span>{/if}
			</h1>
		</div>
		<div class="row">{#if registry.perms.export}<a class="btn ghost" href={hrefTo(`/documents?doc=교적부&person=${p.id}`)}>교적부 출력</a>{/if}<a class="btn ghost" href={hrefTo('/members')}>← 목록</a></div>
	</div>
	<p class="muted" style="margin-top:.4rem">
		{s.category || '교인구분 없음'} · 등록일 {s.registered ?? '미상'} · {s.community ? `${s.community} › ${s.smallGroup}` : `${registry.t2} 없음`} · 교번 {p.reg_no ?? '미배정'}
		<span class="tag">위 값은 이력에서 자동 계산</span>
	</p>

	<div class="tabs">
		{#each tabs as t}<button class:on={tab === t} onclick={() => (tab = t)}>{t}</button>{/each}
	</div>

	{#if tab === '기본 정보'}
		<div class="card">
			<div class="row" style="justify-content:space-between"><h2>기본 정보</h2>
				{#if can && !editing}<button class="ghost" onclick={startEdit}>수정</button>{/if}
			</div>
			{#if !editing}
				<table class="info">
					<tbody>
						<tr><th>교인구분</th><td>{s.category || '-'}</td><th>교번</th><td>{v(p.reg_no)}</td></tr>
						<tr><th>이름</th><td>{p.name}{p.name_note ? ` (${p.name_note})` : ''}</td><th>성별</th><td>{p.gender === 'M' ? '남' : p.gender === 'F' ? '여' : '-'}</td></tr>
						<tr><th>생년월일</th><td>{v(p.birth_date)} ({p.birth_lunar ? '음력' : '양력'}) · {ageText(p.birth_date)}</td><th>앱 가입</th><td>{p.has_account ? '가입' : '미가입'}</td></tr>
						<tr><th>휴대폰</th><td>{v(p.phone)}{p.sms_opt_out ? ' (문자 수신 거부)' : ''}</td><th>집전화</th><td>{v(hh?.home_phone)}</td></tr>
						<tr><th>주소</th><td colspan="3">{[p.address_zip, p.address1, p.address2].filter(Boolean).join(' ') || (hh?.address ? `${hh.address} (세대 주소)` : '-')}</td></tr>
						<tr><th>{registry.t2}</th><td>{s.community ? `${s.community} › ${s.smallGroup}` : '-'}</td><th>전도회</th><td>{v(p.mission_society)}</td></tr>
						<tr><th>직분</th><td>{s.office ? officeWithKind(s.office, p.clergy_title) : '성도'}</td><th>신급</th><td>{s.faith}</td></tr>
						<tr><th>등록일</th><td>{s.registered ?? '미상'}</td><th>인도자</th><td>{#if p.guide_person_id}<a href={hrefTo(`/${p.guide_person_id}`)}>{registry.person(p.guide_person_id)?.name}</a>{:else}{v(p.guide_name)}{/if}</td></tr>
						<tr><th>결혼</th><td>{v(p.marital_status)}{p.wedding_date ? ` · 결혼기념일 ${p.wedding_date}` : ''}</td><th>직업 / 직장</th><td>{[p.occupation, p.workplace].filter(Boolean).join(' / ') || '-'}</td></tr>
						<tr><th>이메일</th><td>{v(p.email)}</td><th>이전 출석 교회</th><td>{v(p.previous_church)}</td></tr>
						<tr><th>대가족</th><td>{#if bigHead}세대주 <a href={hrefTo(`/${bigHead.id}`)}>{bigHead.name}</a> · {v(p.big_family_relation)}{:else}-{/if}</td><th>신급 확인일</th><td>{v(p.faith_checked_on)}</td></tr>
						<tr><th>차량 번호</th><td colspan="3">{v(p.car_number)}</td></tr>
						{#if dmInfo}
							<tr><th>부서 관리</th><td colspan="3">
								{#if dmInfo.kind === 'student'}{dmInfo.dept}{dmInfo.grade ? ` · ${dmInfo.grade}` : ''} 학생{:else}{dmInfo.teacherDept} 교사{/if}
								{#if dmInfo.teacherDept && dmInfo.kind === 'student'} · {dmInfo.teacherDept} 교사{/if}
								<span class="muted" style="margin-left:.6rem">최근 8주 출석</span>
								<span class="dm-att">{#each dmWeeks as w (w.d)}<span class:on={w.v === true} class:off={w.v === false} title="{w.d} {w.v === undefined ? '기록 없음' : w.v ? '출석' : '결석'}">{w.v === undefined ? '·' : w.v ? '●' : '○'}</span>{/each}</span>
								<small class="muted" style="margin-left:.6rem">부서 관리의 출석입니다. 이름·연락처 등은 여기서 고치면 부서 관리에도 맞춰집니다.</small>
							</td></tr>
						{/if}
						<tr><th>비고</th><td colspan="3">{v(p.note)}</td></tr>
					</tbody>
				</table>
				<p class="muted" style="font-size:.78rem;margin:.5rem 0 0">이름 · 휴대폰 · 성별 · 생년월일 · 이메일은 앱 회원 프로필과 같은 자료입니다. 앱 가입 여부는 자동으로 계산합니다.</p>
			{:else}
				<form class="form" onsubmit={(e) => { e.preventDefault(); saveEdit(); }}>
					<PersonFields bind:f={draft} />
					<div class="wide row"><button type="submit">저장 (변경 이력에 남음)</button><button type="button" class="ghost" onclick={() => (editing = false)}>취소</button></div>
				</form>
			{/if}
		</div>
		<p class="muted">주민등록번호는 교적에 두지 않습니다(기부금영수증용으로 나중에 따로 보관).</p>

	{:else if tab === '세대'}
		<div class="card">
			<h2>세대 {hh ? `${hh.no}` : ''}</h2>
			{#if hh}
				<p class="muted">{hh.zip} {hh.address} {hh.home_phone ? `· 집전화 ${hh.home_phone}` : ''}</p>
				<table class="sm"><thead><tr><th>관계(세대주 기준)</th><th>이름</th><th>교적 연결</th></tr></thead><tbody>
					{#each family as m}
						<tr><td>{m.relation}</td>
							<td>{#if m.person_id}<a href={hrefTo(`/${m.person_id}`)}>{registry.person(m.person_id)?.name}</a>{:else}{m.name}{m.birth_year ? ` (${m.birth_year}년생)` : ''}{/if}</td>
							<td>{m.person_id ? '교인' : '교인 아님 (이름·출생연도만)'}</td></tr>
					{/each}
				</tbody></table>
				<p><a href={hrefTo(`/households/${hh.id}`)}>세대 화면에서 세대원 추가·이동·분가 →</a></p>
			{:else}
				<p class="muted">아직 세대가 없습니다. 담당자 입력으로 채우는 중입니다.</p>
			{/if}
			<h2 style="margin-top:1.2rem">대가족</h2>
			<p class="muted" style="margin-top:0">같은 집에 살지 않는 가족(부모·자녀 세대)을 이어 두는 칸입니다. 세대는 한 곳에만 속하고, 대가족은 따로 표시합니다.</p>
			{#if bigHead}<p>대가족 세대주: <a href={hrefTo(`/${bigHead.id}`)}>{bigHead.name}</a> ({v(p.big_family_relation)})</p>{:else}<p class="muted">등록된 대가족이 없습니다. '기본 정보' 수정에서 고를 수 있습니다.</p>{/if}
			{#if registry.d.people.some((x) => x.big_family_head_id === p.id)}
				<p>이 사람을 대가족 세대주로 둔 사람:
					{#each registry.d.people.filter((x) => x.big_family_head_id === p.id) as x, i}<a href={hrefTo(`/${x.id}`)}>{x.name}</a>{i < registry.d.people.filter((y) => y.big_family_head_id === p.id).length - 1 ? ', ' : ''}{/each}</p>
			{/if}
		</div>

	{:else if tab === '신급·직분'}
		<div class="card">
			<h2>신급 이력 <span class="tag now">지금: {s.faith}</span></h2>
			<table class="sm"><thead><tr><th>구분</th><th>날짜</th><th>받은 교회</th><th>집례자</th></tr></thead><tbody>
				{#each byDate(registry.faithOf(p.id)) as e}<tr><td>{e.kind}</td><td>{e.event_date ?? '미상'}</td><td>{e.church ?? '본 교회'}</td><td>{v(e.officiant)}</td></tr>
				{:else}<tr><td colspan="4" class="muted">기록 없음{p.faith_checked_on ? ` (신급 확인일 ${p.faith_checked_on} → 원입교인)` : ' → 미확인'}</td></tr>{/each}
			</tbody></table>
			{#if can}
				<form class="form" style="margin-top:.7rem" onsubmit={(e) => { e.preventDefault(); registry.addFaith({ person_id: p.id, kind: fa.kind, event_date: nn(fa.date), church: nn(fa.church), officiant: nn(fa.officiant), note: null }); fa.date = ''; }}>
					<label>구분<select bind:value={fa.kind}>{#each FAITH_KINDS as k}<option>{k}</option>{/each}</select></label>
					<label>날짜<input type="date" bind:value={fa.date} /></label>
					<label>받은 교회<input bind:value={fa.church} placeholder="비우면 본 교회" /></label>
					<label>집례자<input bind:value={fa.officiant} /></label>
					<div class="wide"><button type="submit">신급 기록 추가</button></div>
				</form>
			{/if}
		</div>
		<div class="card">
			<h2>직분 이력 {#if s.office}<span class="tag now">지금 직분: {s.title}</span>{/if}</h2>
			<table class="sm"><thead><tr><th>직분</th><th>구분</th><th>임직(시작)</th><th>끝</th><th>임직교회</th><th>비고</th></tr></thead><tbody>
				{#each byDate(registry.officesOf(p.id)) as t}<tr><td>{t.office}</td><td>{t.rank_status}</td><td>{t.start_date ?? '미상'}</td><td>{t.end_date ?? '(지금)'}</td><td>{t.church ?? '본 교회'}</td><td>{v(t.note)}</td></tr>
				{:else}<tr><td colspan="6" class="muted">직분 이력 없음 (성도)</td></tr>{/each}
			</tbody></table>
			{#if can}
				<form class="form" style="margin-top:.7rem" onsubmit={(e) => { e.preventDefault(); registry.addOffice({ person_id: p.id, office: ofPos.office!, rank_status: parseRank(of.rank).rank_status, start_date: nn(of.start), end_date: nn(of.end), church: ORDAINED.includes(ofPos.office!) ? nn(of.church) : null, note: null }); if (parseRank(of.rank).clergy) registry.updatePerson(p.id, { clergy_title: parseRank(of.rank).clergy }); of.start = ''; of.end = ''; of.church = ''; }}>
					<label>직분<select bind:value={of.pos}>{#each POSITIONS.filter((x) => x.office) as o}<option>{o.label}</option>{/each}</select></label>
					<label>구분<select bind:value={of.rank}>{#each statusOpts as o}<option value={o}>{rankLabel(ofPos.office!, o)}</option>{/each}</select></label>
					<label>{ORDAINED.includes(ofPos.office!) ? '임직일' : '시작일'}<input type="date" bind:value={of.start} /></label>
					<label>종료일<input type="date" bind:value={of.end} /></label>
					{#if ORDAINED.includes(ofPos.office!)}<label>임직교회<input bind:value={of.church} placeholder="비우면 본 교회 (타교 임직이면 교회 이름)" /></label>{/if}
					<div class="wide"><button type="submit">직분 기록 추가</button> <span class="muted">표시 미리보기: {officeWithKind({ office: ofPos.office!, ...parseRank(of.rank) }, parseRank(of.rank).clergy)}</span></div>
				</form>
			{/if}
		</div>

	{:else if tab === '재적 이력'}
		<div class="card">
			<h2>재적 이력 <span class="tag now">지금: {s.status}</span></h2>
			<table class="sm"><thead><tr><th>기록</th><th>날짜</th><th>사유</th><th>교회(전입·전출)</th></tr></thead><tbody>
				{#each byDate(registry.statusOf(p.id)) as e}<tr><td>{e.kind}</td><td>{e.event_date ?? '미상'}</td><td>{v(e.reason)}</td><td>{v(e.church)}</td></tr>
				{:else}<tr><td colspan="4" class="muted">기록 없음 (미등록·타교인)</td></tr>{/each}
			</tbody></table>
			<p class="muted">등록·전입·복적 → 재적(인증 켜짐) · 보류 → 보류(켜짐) · 보관·전출·별세·제적 → 꺼짐. 날짜를 모르면 비워 둡니다. 전출·별세·제적 5년 뒤 인적사항은 자동으로 지워집니다. (보관은 지우지 않음)</p>
			{#if can}
				<form class="form" onsubmit={(e) => { e.preventDefault(); registry.addStatus({ person_id: p.id, kind: st.kind, event_date: nn(st.date), reason: nn(st.reason), church: nn(st.church) }); st.date = ''; st.reason = ''; }}>
					<label>기록<select bind:value={st.kind}>{#each STATUS_KINDS as k}<option>{k}</option>{/each}</select></label>
					<label>날짜<input type="date" bind:value={st.date} /></label>
					<label>사유<input bind:value={st.reason} /></label>
					<label>교회<input bind:value={st.church} /></label>
					<div class="wide"><button type="submit">재적 기록 추가</button></div>
				</form>
			{/if}
		</div>

	{:else if tab === '소속'}
		<div class="card">
			<h2>{registry.t1} · {registry.t2} <span class="tag">출석부 자료</span></h2>
			<p>{s.community ? `${s.community} › ${s.smallGroup}` : '소속 없음'}
				{#if s.smallGroup}<a class="tag" href={hrefTo(`/communities/${encodeURIComponent(s.smallGroup)}`)}>{registry.t2} 보기 →</a>{/if}</p>
			<p class="muted">{registry.t1}{registry.josa(registry.t1, '과')} {registry.t2}{registry.josa(registry.t2, '은')} 지금 운영 중인 출석부에서 관리합니다. 장년의 소속만 여기서 바로잡을 수 있고, 이동 이력은 따로 남기지 않습니다.</p>
			{#if can}
				<form class="form" onsubmit={(e) => { e.preventDefault(); registry.setGroup(p.id, grp.community, grp.small_group); grp.community = ''; grp.small_group = ''; }}>
					<label>{registry.t1}<select bind:value={grp.community} onchange={() => (grp.small_group = '')}><option value="">(소속 없음)</option>{#each registry.communities as c}<option>{c.name}</option>{/each}</select></label>
					<label>{registry.t2}<select bind:value={grp.small_group} disabled={!grp.community}><option value="">선택</option>{#each registry.groupsIn(grp.community) as g}<option>{g.name}</option>{/each}</select></label>
					<div class="wide"><button type="submit" class="ghost">소속 바꾸기</button></div>
				</form>
			{/if}
			{#if registry.perms.visit}
			<h2 style="margin-top:1.2rem">인도자 특이사항 <span class="tag">출석부 자료</span></h2>
			{#each gnotes as n (n.id)}<div style="margin-bottom:.4rem"><b>{n.date}</b> · {n.author} ({n.small_group}) — {n.body}</div>{:else}<p class="muted">적힌 특이사항이 없습니다.</p>{/each}
			{/if}
		</div>
		<div class="card">
			<h2>전도회</h2>
			<dl><dt>전도회</dt><dd>{v(p.mission_society)}</dd></dl>
			<p class="muted">'기본 정보' 수정에서 바꿉니다.</p>
		</div>
		<div class="card">
			<h2>교회봉사 <span class="tag">교회 조직표의 부서 · 여러 곳 가능</span></h2>
			<table class="sm"><thead><tr><th>부서</th><th>역할</th><th>시작</th><th>끝</th><th></th></tr></thead><tbody>
				{#each affs as a}<tr><td>{a.org}</td><td>{v(a.role)}</td><td>{a.start_date ?? '미상'}</td><td>{a.end_date ?? '(지금)'}</td>
					<td>{#if can && !a.end_date}<button class="ghost sm" onclick={() => registry.endAffiliation(a.id)}>봉사 마침</button>{/if}</td></tr>
				{:else}<tr><td colspan="5" class="muted">봉사 소속 없음</td></tr>{/each}
			</tbody></table>
			{#if can}
				<form class="form" style="margin-top:.7rem" onsubmit={(e) => { e.preventDefault(); registry.addAffiliation({ person_id: p.id, org: af.org, role: nn(af.role), start_date: nn(af.start), end_date: null }); af.org = ''; af.role = ''; }}>
					<label>부서<select bind:value={af.org} required><option value="">선택</option>{#each depts as d}<option>{d.name}</option>{/each}</select></label>
					<label>역할<input bind:value={af.role} /></label>
					<label>시작일<input type="date" bind:value={af.start} /></label>
					<div class="wide"><button type="submit">봉사 추가</button></div>
				</form>
			{/if}
		</div>

	{:else if tab === '교육'}
		<div class="card">
			<h2>교육 · 수료 <span class="tag">새가족 교육</span></h2>
			<table class="sm"><thead><tr><th>과정</th><th>시작</th><th>수료일</th><th>강사</th><th>상태</th><th></th></tr></thead><tbody>
				{#each edus as r (r.id)}<tr><td>{courseName(r.course_id)}</td><td>{r.start_date ?? '미상'}</td><td>{r.end_date ?? '-'}</td><td>{v(r.instructor)}</td>
					<td><span class="badge" class:inactive={!r.completed}>{r.completed ? '수료' : '진행 중'}</span>{r.note ? ` · ${r.note}` : ''}</td>
					<td>{#if can && !r.completed}<button class="ghost sm" onclick={() => registry.completeEducation(r.id, today)}>오늘 수료 처리</button>{/if}</td></tr>
				{:else}<tr><td colspan="6" class="muted">교육 기록 없음</td></tr>{/each}
			</tbody></table>
			{#if can}
				<form class="form" style="margin-top:.7rem" onsubmit={(e) => { e.preventDefault(); registry.addEducation({ person_id: p.id, course_id: ed.course, start_date: nn(ed.start), end_date: null, completed: false, instructor: nn(ed.instructor), note: null }); ed.instructor = ''; }}>
					<label>과정<select bind:value={ed.course}>{#each registry.d.educationCourses as c}<option value={c.id}>{c.name}</option>{/each}</select></label>
					<label>시작일<input type="date" bind:value={ed.start} /></label>
					<label>강사<input bind:value={ed.instructor} /></label>
					<div class="wide"><button type="submit">교육 시작 기록</button></div>
				</form>
			{/if}
			<p class="muted">지금은 새가족 교육만 운영하며, 수료 여부를 남기는 칸이 필요합니다. 과정은 나중에 늘릴 수 있게 만들었습니다.</p>
		</div>

	{:else if tab === '심방' && registry.perms.visit}
		<div class="card">
			<div class="row" style="justify-content:space-between"><h2>심방 기록</h2><a class="btn ghost" href={hrefTo(`/visits?person=${p.id}`)}>심방 화면에서 등록 →</a></div>
			<p class="muted">심방 기록은 A 등급(전임 교역자)만 볼 수 있습니다.</p>
			{#if registry.perms.visit}
				{#each byDate(visits.map((x) => ({ ...x, event_date: x.visit_date }))) as x (x.id)}
					<div style="margin-bottom:.7rem"><b>{x.visit_date}</b> · {x.kind} · {x.method} · {x.pastor}{x.companions ? ` (동행 ${x.companions})` : ''}
						<br />{x.content}{x.scripture ? ` · ${x.scripture}` : ''}{x.hymn ? ` · ${x.hymn}` : ''}{#if x.follow_up}<br /><span class="muted">후속 조치: {x.follow_up}</span>{/if}</div>
				{:else}<p class="muted">심방 기록이 없습니다.</p>{/each}
				{#if can}
					<form class="form" onsubmit={(e) => { e.preventDefault(); const added = registry.addVisit({ person_id: p.id, visit_date: vs.date, kind: vs.kind, method: vs.method, pastor: vs.pastor || clergy[0]?.name || '담당 교역자', companions: vsCompanions.length ? vsCompanions.map((c) => c.name).join(', ') : null, scripture: nn(vs.scripture), hymn: nn(vs.hymn), content: vs.content, follow_up: nn(vs.follow) }); vsMsg = added.length ? `캘린더 성도 일정에 ${added.map((c) => `${+c.date.slice(5, 7)}/${+c.date.slice(8, 10)} ${c.label}`).join(', ')}을(를) 함께 넣었습니다.` : ''; vs.content = ''; vs.follow = ''; vs.scripture = ''; vs.hymn = ''; vsCompanions = []; }}>
						<label>날짜<input type="date" bind:value={vs.date} required /></label>
						<label>구분<select bind:value={vs.kind}>{#each registry.codes.visitKinds as k}<option>{k}</option>{/each}</select></label>
						<label>방법<select bind:value={vs.method}><option>대면</option><option>전화</option></select></label>
						<label>담당 교역자<select bind:value={vs.pastor}><option value="">(선택)</option>{#each clergy as c}<option>{c.name}</option>{/each}</select></label>
						<label class="wide">동행자 <span class="muted" style="font-weight:400">(이름을 쓰고 쉼표)</span><NameChips bind:chips={vsCompanions} allowFree placeholder="예: 김영수, 이순희" /></label>
						{#if registry.features.ai}<div class="wide"><VisitAnalyzer onresult={applyAnalysis} /></div>{/if}
						<label>말씀<input bind:value={vs.scripture} /></label>
						<label>찬송<input bind:value={vs.hymn} /></label>
						<label class="wide"><span class="lt">심방 내용 <small class="hint">(예: 22일 입원, 23일 수술 과 같이 날짜와 함께 기록하면 캘린더의 성도 일정에 자동으로 입력이 됩니다.)</small></span><small class="hint">*자동 등록 해당 문구 : 입원 · 퇴원 · 수술 · 시술 · 검사 · 항암 · 치료 · 출산 · 돌잔치 · 결혼식 · 장례 · 입관 · 발인 · 이사 · 개업 · 입대 · 전역 · 제대 · 입학 · 졸업 · 출국 · 귀국 등</small><textarea rows="4" bind:value={vs.content} required></textarea></label>
						<label class="wide">후속 조치<input bind:value={vs.follow} /><CarePreview text={`${vs.content}\n${vs.follow}`} base={vs.date} person={p.id} /></label>
						<div class="wide row"><button type="submit">심방 기록 추가</button>{#if vsMsg}<span class="muted" style="font-size:.85rem">{vsMsg}</span>{/if}</div>
					</form>
				{/if}
			{:else}
				<div class="warn">심방 기록은 A 등급만 볼 수 있습니다.</div>
			{/if}
		</div>

	{:else if tab === '메모·동의'}
		<div class="card">
			<h2>담당자 메모 <span class="tag">교적 권한자 모두 열람</span></h2>
			{#each myNotes as n}<div style="margin-bottom:.5rem">{n.body}<br /><span class="muted">{n.author} · {n.created_at}</span></div>{:else}<p class="muted">메모 없음</p>{/each}
			{#if can}
				<form class="row" onsubmit={(e) => { e.preventDefault(); if (noteBody.trim()) { registry.addNote(p.id, noteBody.trim()); noteBody = ''; } }}>
					<input style="flex:1" bind:value={noteBody} placeholder="메모 입력" /><button type="submit">추가</button>
				</form>
			{/if}
		</div>
		{#if registry.perms.visit}
		<div class="card">
			<h2>이전 프로그램 메모 <span class="tag">A 등급만 · 열 때마다 접속기록</span></h2>
			{#if memos.length === 0}
				<p class="muted">이전 프로그램 메모 없음</p>
			{:else if !showMemo}
				<button class="ghost" onclick={openMemo}>열어 보기 (접속기록에 남음)</button>
			{:else}
				{#each memos as m}<div>{m.body}</div>{/each}
			{/if}
		</div>
		{/if}
		<div class="card">
			<h2>동의 기록</h2>
			<table class="sm"><thead><tr><th>종류</th><th>처리방침 판</th><th>동의일</th><th>방법</th><th>보호자</th><th>철회일</th><th></th></tr></thead><tbody>
				{#each consents as c}<tr><td>{c.kind}</td><td>{c.policy_version}</td><td>{c.consent_date ?? '-'}</td><td>{c.method}</td><td>{v(c.guardian_name)}</td><td>{v(c.revoked_on)}</td>
					<td>{#if can && !c.revoked_on}<button class="ghost" onclick={() => registry.revokeConsent(c.id)}>철회</button>{/if}</td></tr>
				{:else}<tr><td colspan="7" class="muted">동의 기록 없음</td></tr>{/each}
			</tbody></table>
			{#if can}
				<form class="form" style="margin-top:.7rem" onsubmit={(e) => { e.preventDefault(); registry.addConsent({ person_id: p.id, kind: cs.kind, policy_version: '2026-01', consent_date: today, method: cs.method, guardian_name: nn(cs.guardian), revoked_on: null }); cs.guardian = ''; }}>
					<label>종류<select bind:value={cs.kind}>{#each CONSENT_KINDS as k}<option>{k}</option>{/each}</select></label>
					<label>방법<select bind:value={cs.method}>{#each ['서면', '앱', '구두'] as k}<option>{k}</option>{/each}</select></label>
					<label>보호자 이름(14세 미만)<input bind:value={cs.guardian} /></label>
					<div class="wide"><button type="submit">동의 기록 추가</button></div>
				</form>
			{/if}
		</div>

	{:else if tab === '변경 이력'}
		<div class="card">
			<h2>변경 이력</h2>
			<p class="muted">수정하면 바뀜 전후 값이 남습니다.</p>
			<table class="sm"><thead><tr><th>시각</th><th>수정자</th><th>항목</th><th>전</th><th>후</th></tr></thead><tbody>
				{#each logs as l}<tr><td>{l.at}</td><td>{l.by}</td><td>{l.field}</td><td>{v(l.before)}</td><td>{v(l.after)}</td></tr>{:else}<tr><td colspan="5" class="muted">변경 이력 없음</td></tr>{/each}
			</tbody></table>
		</div>
	{/if}
{:else}
	<p>교적을 찾을 수 없습니다. <a href={hrefTo('/members')}>목록으로</a></p>
{/if}

<style>
	.mugshot { width: 56px; height: 56px; border-radius: 16px; object-fit: cover; border: 1px solid var(--line-strong); }
	.dm-att { letter-spacing: 0.25em; font-size: 0.9rem; }
	.dm-att .on { color: #14b4ae; }
	.dm-att .off { color: #c9ced9; }
</style>
