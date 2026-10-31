<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	import { page } from '$app/state';
	import { registry } from '#lib/registry/store.svelte.ts';
	import PersonPicker from '#lib/components/PersonPicker.svelte';
	import ExportButtons from '#lib/components/ExportButtons.svelte';
	import { ageOf, ageText, fullAddress, officeTitle } from '#lib/registry/derive.ts';
	import { withPdfStyle } from '#lib/pdf-style.ts';
	import { certNo } from '#lib/registry/site-settings.ts';

	// 문서 출력: 화면에서 미리 보고 → 인쇄 또는 PDF 내려받기. 출력할 때마다 접속기록(출력)과 발급번호를 남김
	// [실제 시스템] 증명서 발급 대장을 따로 두고(발급번호·용도·발급자), 교회 직인 이미지를 넣습니다.
	//              PDF는 지금처럼 브라우저에서 만들어도 되고, 서버에서 만들면 글꼴·서식이 더 일정합니다.
	const DOCS = ['교적부', '교인증명서', '세례증명서', '기부금 영수증', '교인 요람', '생일 명단', '직분자 명단', '교회 조직표'] as const;
	type Doc = (typeof DOCS)[number];
	let doc = $state<Doc>((page.url.searchParams.get('doc') as Doc) ?? '교적부');
	let personId = $state(page.url.searchParams.get('person') ?? '');
	let purpose = $state('제출용');
	let month = $state(new Date().getMonth() + 1);
	let comm = $state('전체');
	let consentOnly = $state(true);
	let busy = $state(false);
	let sheet = $state<HTMLElement | null>(null);

	// 홈페이지 증명서 신청: 관리자가 승인한 신청만 직인이 들어감. 교적 관리에서 바로 뽑는 증명서는 늘 직인이 들어감
	let reqId = $state<string | null>(null);
	const req = $derived(reqId ? registry.certRequests.find((r) => r.id === reqId) : undefined);
	const sealOk = $derived(!req || req.status === '승인');
	// 진짜 직인은 이 컴퓨터의 static/private 에만 있음 (저장소·공개 사이트에 없음) → 없으면 '견본' 직인
	// 직인: 기본 설정에서 올린 직인 → 이 컴퓨터의 직인 → 견본 순서
	let sealFail = $state(false);
	const sealSrc = $derived(registry.churchInfo.seal || (sealFail ? hrefTo('/seal-sample.svg') : hrefTo('/private/church-seal.png')));
	const cf = $derived(registry.certForm);
	const pendingReqs = $derived(registry.certRequests.filter((r) => r.status === '대기').length);
	const reqName = (id: string) => registry.person(id)?.name ?? '';
	function openReq(r: { id: string; kind: '교인증명서' | '세례증명서'; person_id: string; purpose: string }) {
		doc = r.kind; personId = r.person_id; purpose = r.purpose; reqId = r.id;
	}
	// 화면에서 다른 문서·교인을 고르면 신청과의 연결을 끊음
	$effect(() => { if (req && (req.person_id !== personId || req.kind !== doc)) reqId = null; });
	const purposeOpts = $derived([...new Set([...registry.certForm.purposes, purpose])]);

	const CHURCH = $derived(registry.churchInfo.name);
	const now = new Date();
	const todayKo = `${now.getFullYear()}년 ${now.getMonth() + 1}월 ${now.getDate()}일`;
	const pastor = $derived(registry.pastorName);
	const p = $derived(personId ? registry.person(personId) : undefined);
	const s = $derived(p ? registry.summary(p) : null);
	const needsPerson = $derived(doc === '교적부' || doc === '교인증명서' || doc === '세례증명서' || doc === '기부금 영수증');

	// ---- 기부금 영수증 ----
	// 헌금 자료는 아직 연동하지 않아(스데반정보 덤프 미정) 기간·내용·금액을 직접 적음. 주민등록번호는 교적에 두지 않으므로 영수증에 빈칸으로 두고 발급할 때 손으로 적음
	// [실제 시스템] 헌금번호 ↔ 교적 ID 가 이어지면 기부 연도만 고르면 금액이 자동으로 채워지게 함
	type DonRow = { from: string; to: string; kind: string; amount: string };
	const donYears = Array.from({ length: 5 }, (_, i) => now.getFullYear() - i);
	let donYear = $state(now.getFullYear() - 1); // 연말정산용은 지난해 기부금
	let donRows = $state<DonRow[]>([{ from: `${now.getFullYear() - 1}-01-01`, to: `${now.getFullYear() - 1}-12-31`, kind: '헌금', amount: '' }]);
	function setDonYear(y: number) {
		donYear = y;
		for (const r of donRows) { r.from = `${y}-01-01`; r.to = `${y}-12-31`; }
	}
	const commas = (digits: string) => (digits ? Number(digits).toLocaleString() : '');
	const donLines = $derived(donRows.map((r) => ({ ...r, n: Number(r.amount) || 0 })).filter((r) => r.n > 0));
	const donTotal = $derived(donLines.reduce((a, r) => a + r.n, 0));
	// 금액을 한글로: 1234000 → 일백이십삼만사천 (영수증은 숫자를 고쳐 쓰기 어렵게 한글 금액을 함께 적음)
	function wonKo(n: number): string {
		const dg = ['', '일', '이', '삼', '사', '오', '육', '칠', '팔', '구'], un = ['', '십', '백', '천'], big = ['', '만', '억', '조'];
		let out = '', g = 0;
		for (; n > 0; g++, n = Math.floor(n / 10000)) {
			const part = n % 10000;
			if (!part) continue;
			let s = '';
			for (let i = 0; i < 4; i++) { const x = Math.floor(part / 10 ** i) % 10; if (x) s = dg[x] + un[i] + s; }
			out = s + big[g] + out;
		}
		return out;
	}
	const issued = $derived(registry.d.accessLog.filter((l) => l.action === '출력' && l.at.startsWith(String(now.getFullYear()))).length);
	const docNo = $derived(certNo(registry.certForm, now.getFullYear(), issued + 1));
	const v = (x: unknown) => (x === null || x === undefined || x === '' ? '' : String(x));
	const md = (iso: string | null) => (iso ? `${+iso.slice(5, 7)}월 ${+iso.slice(8, 10)}일` : '');
	const ymd = (iso: string | null) => (iso ? `${iso.slice(0, 4)}. ${+iso.slice(5, 7)}. ${+iso.slice(8, 10)}.` : '');

	// ---- 교적부 ----
	const hh = $derived(p ? registry.householdOf(p.id) : undefined);
	const family = $derived(hh ? registry.membersOfHousehold(hh.id) : []);
	const sortD = <T extends { event_date?: string | null; start_date?: string | null }>(xs: T[]) => [...xs].sort((a, b) => (a.event_date ?? a.start_date ?? '').localeCompare(b.event_date ?? b.start_date ?? ''));
	const baptism = $derived(p ? sortD(registry.faithOf(p.id)).filter((f) => f.kind === '세례' || f.kind === '유아세례' || f.kind === '입교').at(-1) : undefined);

	// ---- 목록 문서 ----
	const members = $derived(registry.d.people.map((x) => ({ p: x, s: registry.summary(x) })).filter((x) => x.s.memberCert));
	const consented = (id: string) => registry.d.consents.some((c) => c.person_id === id && c.kind === '요람 공개' && !c.revoked_on);
	const directory = $derived(
		members.filter((x) => (comm === '전체' || x.s.community === comm) && (!consentOnly || consented(x.p.id)))
			.sort((a, b) => (a.s.community + a.s.smallGroup).localeCompare(b.s.community + b.s.smallGroup, 'ko') || a.p.name.localeCompare(b.p.name, 'ko'))
	);
	const birthdays = $derived(
		members.filter((x) => x.p.birth_date && +x.p.birth_date.slice(5, 7) === month).sort((a, b) => a.p.birth_date!.slice(8).localeCompare(b.p.birth_date!.slice(8)))
	);
	const officers = $derived(
		registry.d.officeTerms.filter((t) => !t.end_date && t.rank_status !== '사임' && registry.person(t.person_id) && registry.summary(registry.person(t.person_id)!).memberCert)
			.map((t) => ({ t, p: registry.person(t.person_id)! }))
			.sort((a, b) => ['목사', '전도사', '사모', '장로', '시무집사', '권사', '서리집사'].indexOf(a.t.office) - ['목사', '전도사', '사모', '장로', '시무집사', '권사', '서리집사'].indexOf(b.t.office) || a.p.name.localeCompare(b.p.name, 'ko'))
	);
	const committees = $derived(registry.d.orgUnits.filter((o) => !o.parent_id).sort((a, b) => a.sort_order - b.sort_order));
	const deptsOf = (id: string) => registry.d.orgUnits.filter((o) => o.parent_id === id).sort((a, b) => a.sort_order - b.sort_order);
	// 조직표: 위원회를 왼쪽·오른쪽 번갈아 두 줄로 쌓음 (빈칸 없이 위로 붙임). 홀수 개면 마지막은 짧은 쪽으로
	const orgCols = $derived.by(() => {
		const cols: { c: (typeof committees)[number]; ci: number }[][] = [[], []];
		const rows = [0, 0];
		committees.forEach((c, ci) => {
			const last = ci === committees.length - 1 && ci % 2 === 0;
			const k = last ? (rows[1] < rows[0] ? 1 : 0) : ci % 2;
			cols[k].push({ c, ci });
			rows[k] += deptsOf(c.id).length + 2;
		});
		return cols;
	});
	const pname = (id: string | null) => (id ? registry.person(id)?.name ?? '' : '');
	const ready = $derived(!needsPerson || (!!p && (doc !== '세례증명서' || !!baptism) && (doc !== '기부금 영수증' || donTotal > 0)));
	const fileName = $derived(`${doc}${p && needsPerson ? `-${p.name}` : doc === '생일 명단' ? `-${month}월` : ''}-${now.toISOString().slice(0, 10)}.pdf`);

	function record() {
		const tail = doc === '기부금 영수증' ? ` · ${docNo} · ${donYear}년` : needsPerson && doc !== '교적부' ? ` · ${docNo} · ${purpose}` : '';
		registry.log('출력', `${doc}${p && needsPerson ? ` · ${p.name}` : ''}${tail}`);
	}
	// 교회 조직표는 늘 A4 한 장에 나오도록 크기를 줄여서 맞춤
	const onePage = $derived(doc === '교회 조직표');
	function print() {
		record();
		if (onePage && sheet) {
			// 인쇄 영역(A4 297mm - 위아래 여백 24mm)에 들어가도록 비율을 계산해 잠시 축소
			const pxPerMm = 96 / 25.4;
			const h = sheet.scrollHeight / pxPerMm;
			const fit = Math.min(1, (270 / h) * 0.97);
			sheet.style.setProperty('--fit', String(fit));
			window.addEventListener('afterprint', () => sheet?.style.removeProperty('--fit'), { once: true });
		}
		window.print();
	}
	const h2c = { scale: 2, useCORS: true };
	// 한 장짜리 PDF: 문서 전체를 그림 한 장으로 만든 뒤 A4 한 쪽에 비율을 지켜 맞춤
	async function pdfOnePage() {
		if (!sheet) return;
		const html2pdf = (await import('html2pdf.js')).default;
		const { jsPDF } = await import('jspdf');
		const canvas: HTMLCanvasElement = await html2pdf().set({ html2canvas: h2c } as unknown as Record<string, never>).from(sheet).toCanvas().get('canvas');
		const out = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
		const maxW = 210 - 16, maxH = 297 - 16; // 여백 8mm
		const k = Math.min(maxW / canvas.width, maxH / canvas.height);
		const w = canvas.width * k, h = canvas.height * k;
		out.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', (210 - w) / 2, 8, w, h);
		out.save(fileName);
	}
	async function pdf() {
		if (!sheet) return;
		const el = sheet;
		busy = true;
		try {
			await withPdfStyle(el, async () => {
				if (onePage) return pdfOnePage();
				const html2pdf = (await import('html2pdf.js')).default;
				// pagebreak: 표의 줄이 페이지 사이에서 잘리지 않게 함 (타입 정의에는 없지만 html2pdf 가 지원하는 설정)
				const opts = { margin: [10, 10, 12, 10], filename: fileName, image: { type: 'jpeg', quality: 0.96 }, html2canvas: h2c, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }, pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.keep'] } };
				await html2pdf()
					.set(opts as unknown as Record<string, never>)
					.from(el)
					.save();
			});
			record();
		} finally {
			busy = false;
		}
	}
</script>

<div class="no-print">
	<h1>문서 출력</h1>
	{#if !registry.perms.export}
		<div class="warn">문서 출력은 최고관리자에게 내려받기·출력 권한을 받은 사용자만 할 수 있습니다.</div>
	{/if}
	<div class="card">
		<div class="tabs" style="margin-bottom:.8rem">{#each DOCS as d}<button class:on={doc === d} onclick={() => (doc = d)}>{d}</button>{/each}</div>
		<div class="toolbar">
			{#if needsPerson}
				<div style="flex:1 1 320px;max-width:420px"><PersonPicker bind:value={personId} placeholder="교인 이름 검색" /></div>
				{#if doc === '교인증명서' || doc === '세례증명서'}<label class="row" style="gap:.4rem"><span class="muted">용도</span><select bind:value={purpose} style="width:auto">{#each purposeOpts as x}<option>{x}</option>{/each}</select></label>{/if}
			{:else if doc === '생일 명단'}
				<label class="row" style="gap:.4rem"><span class="muted">월</span><select bind:value={month} style="width:auto">{#each Array.from({ length: 12 }, (_, i) => i + 1) as m}<option value={m}>{m}월</option>{/each}</select></label>
			{:else if doc === '교인 요람'}
				<select bind:value={comm} style="width:auto"><option>전체</option>{#each registry.communities as c}<option>{c.name}</option>{/each}</select>
				<label class="row" style="gap:.3rem"><input type="checkbox" bind:checked={consentOnly} />요람 공개에 동의한 사람만</label>
			{/if}
			<span style="flex:1"></span>
			<ExportButtons onprint={print} onpdf={pdf} disabled={!ready || !registry.perms.export} {busy} />
		</div>
		{#if doc === '교인 요람' && consentOnly}<p class="muted" style="margin:0;font-size:.82rem">요람에는 '요람 공개'에 동의한 사람만 나옵니다. (일괄 동의를 받기 전이라 지금은 몇 명만 있습니다. 시험할 때는 체크를 풀어 보세요)</p>{/if}
		{#if doc === '세례증명서' && p && !baptism}<p class="warn" style="margin:.4rem 0 0">이 교인은 세례(유아세례·입교) 기록이 없어 세례증명서를 만들 수 없습니다.</p>{/if}
		{#if doc === '기부금 영수증'}
			<div class="don">
				<div class="row" style="gap:.6rem">
					<label class="row" style="gap:.4rem"><span class="muted">기부 연도</span>
						<select value={donYear} onchange={(e) => setDonYear(+e.currentTarget.value)} style="width:auto">{#each donYears as y}<option value={y}>{y}년</option>{/each}</select></label>
					<span class="muted" style="font-size:.82rem">연말정산용은 지난해 1월 1일 ~ 12월 31일 기부금입니다. 헌금 자료는 아직 연동 전이라 금액을 직접 적습니다.</span>
				</div>
				<div class="tw"><table class="sm don-t">
					<thead><tr><th>기부 기간</th><th>내용</th><th style="text-align:right">금액 (원)</th><th></th></tr></thead>
					<tbody>
						{#each donRows as r, i}
							<tr>
								<td><span class="dates"><input type="date" bind:value={r.from} aria-label="시작일" /> ~ <input type="date" bind:value={r.to} min={r.from} aria-label="끝나는 날" /></span></td>
								<td><input bind:value={r.kind} list="don-kinds" aria-label="내용" /></td>
								<td><input inputmode="numeric" style="text-align:right" value={commas(r.amount)} oninput={(e) => { r.amount = e.currentTarget.value.replace(/\D/g, '').slice(0, 12); e.currentTarget.value = commas(r.amount); }} placeholder="0" aria-label="금액" /></td>
								<td>{#if donRows.length > 1}<button type="button" class="ghost sm" onclick={() => (donRows = donRows.filter((_, k) => k !== i))}>삭제</button>{/if}</td>
							</tr>
						{/each}
					</tbody>
				</table></div>
				<datalist id="don-kinds"><option value="헌금"></option><option value="십일조"></option><option value="감사헌금"></option><option value="선교헌금"></option><option value="건축헌금"></option></datalist>
				<div class="row" style="margin-top:.5rem;gap:.8rem">
					<button type="button" class="ghost sm" onclick={() => (donRows = [...donRows, { from: `${donYear}-01-01`, to: `${donYear}-12-31`, kind: '헌금', amount: '' }])}>+ 줄 추가</button>
					<span>합계 <b>{donTotal.toLocaleString()}원</b>{#if donTotal}<small class="muted"> · 일금 {wonKo(donTotal)}원정</small>{/if}</span>
				</div>
				<p class="muted" style="margin:.5rem 0 0;font-size:.8rem">주민등록번호는 교적에 두지 않아 영수증에 빈칸으로 나옵니다. 출력한 뒤 기부자에게 확인해 손으로 적어 주세요. 교회 고유번호는 관리자 › 교회 설정 › 기본 설정에서 입력합니다.</p>
				{#if p && !donTotal}<p class="warn" style="margin:.5rem 0 0">금액을 적으면 영수증 미리보기가 나옵니다.</p>{/if}
			</div>
		{/if}
		{#if req}
			<p class={req.status === '승인' ? 'ok' : 'warn'} style="margin:.6rem 0 0">
				교인 신청 · {reqName(req.person_id)} · {req.kind} · {req.purpose} —
				{req.status === '승인' ? `${req.decided_by} 승인(${req.decided_at}) · 직인이 들어갑니다.` : req.status === '대기' ? '아직 승인 전이라 직인이 들어가지 않습니다.' : '반려된 신청이라 직인이 들어가지 않습니다.'}
			</p>
		{/if}
	</div>

	<!-- 홈페이지에서 들어온 증명서 신청 -->
	<div class="card" id="cert-req">
		<div class="card-head"><h2>증명서 신청{#if pendingReqs}<span class="tag new">대기 {pendingReqs}건</span>{/if}</h2><span class="sub">관리자가 승인한 신청만 직인이 들어간 증명서로 발급됩니다</span></div>
		<div style="overflow-x:auto">
			<table class="sm">
				<thead><tr><th>신청 일시</th><th>이름</th><th>증명서</th><th>용도</th><th>상태</th><th></th></tr></thead>
				<tbody>
					{#each [...registry.certRequests].sort((a, b) => b.requested_at.localeCompare(a.requested_at)) as r (r.id)}
						<tr class:on-req={r.id === reqId}>
							<td class="nw">{r.requested_at}</td>
							<td class="nw"><a href={hrefTo(`/${r.person_id}`)}>{reqName(r.person_id)}</a></td>
							<td class="nw">{r.kind}</td>
							<td>{r.purpose}</td>
							<td class="nw"><span class="badge" class:inactive={r.status === '대기'} class:long={r.status === '반려'}>{r.status}</span>{#if r.decided_by}<small class="muted"> {r.decided_by}</small>{/if}</td>
							<td class="nw" style="text-align:right">
								{#if r.status === '대기' && registry.canApproveCert}
									<button type="button" class="sm" onclick={() => { registry.decideCertRequest(r.id, true); openReq(r); }}>승인</button>
									<button type="button" class="ghost sm" onclick={() => registry.decideCertRequest(r.id, false)}>반려</button>
								{/if}
								{#if r.status !== '반려'}<button type="button" class="ghost sm" onclick={() => openReq(r)}>{r.status === '승인' ? '발급하기' : '미리 보기'}</button>{/if}
							</td>
						</tr>
					{:else}<tr><td colspan="6" class="empty">들어온 신청이 없습니다.</td></tr>{/each}
				</tbody>
			</table>
		</div>
	</div>
</div>

{#if ready}
	<div class="sheet-wrap">
		<div class="sheet" class:one-page={onePage} bind:this={sheet}>
			{#if doc === '교적부' && p && s}
				<div class="doc-head"><span>{CHURCH}</span><h2>교 적 부</h2><span>출력일 {ymd(now.toISOString().slice(0, 10))}</span></div>
				<table class="dt">
					<tbody>
						<tr>
							<td rowspan="5" class="photo-cell">{#if p.photo}<img src={p.photo} alt="" />{:else}<span>사진</span>{/if}</td>
							<th>이름</th><td>{p.name}{p.name_note ? ` (${p.name_note})` : ''}</td><th>교번</th><td>{v(p.reg_no)}</td>
						</tr>
						<tr><th>생년월일</th><td>{ymd(p.birth_date)} ({p.birth_lunar ? '음력' : '양력'}) · {ageText(p.birth_date)}</td><th>성별</th><td>{p.gender === 'M' ? '남' : p.gender === 'F' ? '여' : ''}</td></tr>
						<tr><th>휴대폰</th><td>{v(p.phone)}</td><th>집전화</th><td>{v(hh?.home_phone)}</td></tr>
						<tr><th>주소</th><td colspan="3">{fullAddress(p, hh)}</td></tr>
						<tr><th>직업 / 직장</th><td>{[p.occupation, p.workplace].filter(Boolean).join(' / ')}</td><th>결혼</th><td>{v(p.marital_status)}{p.wedding_date ? ` · ${ymd(p.wedding_date)}` : ''}</td></tr>
						<tr><th colspan="2">교인구분</th><td>{s.category}</td><th>직분</th><td>{s.title || '성도'}</td></tr>
						<tr><th colspan="2">등록일</th><td>{ymd(s.registered)}</td><th>신급</th><td>{s.faith}</td></tr>
						<tr><th colspan="2">{registry.t1} · {registry.t2}</th><td>{s.community ? `${s.community} · ${s.smallGroup}` : ''}</td><th>전도회</th><td>{v(p.mission_society)}</td></tr>
						<tr><th colspan="2">이전 교회</th><td>{v(p.previous_church)}</td><th>인도자</th><td>{p.guide_person_id ? registry.person(p.guide_person_id)?.name : v(p.guide_name)}</td></tr>
					</tbody>
				</table>

				<h4>가족 (세대 {hh?.no ?? ''})</h4>
				<table class="dt list"><thead><tr><th>관계</th><th>이름</th><th>생년</th><th>직분</th><th>신급</th><th>비고</th></tr></thead><tbody>
					{#each family as m}
						{@const fp = m.person_id ? registry.person(m.person_id) : undefined}
						<tr><td>{m.relation}</td><td>{fp?.name ?? m.name}</td><td>{fp?.birth_date?.slice(0, 4) ?? v(m.birth_year)}</td><td>{fp ? registry.summary(fp).title : ''}</td><td>{fp ? registry.summary(fp).faith : ''}</td><td>{fp ? '' : '교인 아님'}</td></tr>
					{:else}<tr><td colspan="6" class="c">세대 정보 없음</td></tr>{/each}
				</tbody></table>

				<div class="two">
					<div>
						<h4>신급 이력</h4>
						<table class="dt list"><thead><tr><th>구분</th><th>날짜</th><th>교회</th><th>집례</th></tr></thead><tbody>
							{#each sortD(registry.faithOf(p.id)) as e}<tr><td>{e.kind}</td><td>{ymd(e.event_date)}</td><td>{e.church ?? '본 교회'}</td><td>{v(e.officiant)}</td></tr>
							{:else}<tr><td colspan="4" class="c">기록 없음</td></tr>{/each}
						</tbody></table>
					</div>
					<div>
						<h4>직분 이력</h4>
						<table class="dt list"><thead><tr><th>직분</th><th>구분</th><th>임직(시작)</th><th>끝</th></tr></thead><tbody>
							{#each sortD(registry.officesOf(p.id)) as t}<tr><td>{t.office}</td><td>{t.rank_status}</td><td>{ymd(t.start_date)}</td><td>{ymd(t.end_date)}</td></tr>
							{:else}<tr><td colspan="4" class="c">성도</td></tr>{/each}
						</tbody></table>
					</div>
				</div>
				<h4>재적 이력</h4>
				<table class="dt list"><thead><tr><th>기록</th><th>날짜</th><th>사유</th><th>교회</th></tr></thead><tbody>
					{#each sortD(registry.statusOf(p.id)) as e}<tr><td>{e.kind}</td><td>{ymd(e.event_date)}</td><td>{v(e.reason)}</td><td>{v(e.church)}</td></tr>
					{:else}<tr><td colspan="4" class="c">기록 없음</td></tr>{/each}
				</tbody></table>
				<h4>교회봉사</h4>
				<table class="dt list"><thead><tr><th>부서</th><th>역할</th><th>시작</th><th>끝</th></tr></thead><tbody>
					{#each registry.d.affiliations.filter((a) => a.person_id === p.id) as a}<tr><td>{a.org}</td><td>{v(a.role)}</td><td>{ymd(a.start_date)}</td><td>{ymd(a.end_date)}</td></tr>
					{:else}<tr><td colspan="4" class="c">없음</td></tr>{/each}
				</tbody></table>
				<p class="foot-note">이 문서는 개인정보를 담고 있습니다. 용도가 끝나면 파기해 주세요.</p>

			{:else if (doc === '교인증명서' || doc === '세례증명서') && p && s}
				<div class="cert">
					<p class="cert-no">{docNo}</p>
					<h2 class="cert-title">{doc === '교인증명서' ? '교 인 증 명 서' : '세 례 증 명 서'}</h2>
					<table class="dt cert-table"><tbody>
						<tr><th>성 명</th><td>{p.name}</td><th>생년월일</th><td>{ymd(p.birth_date)}</td></tr>
						<tr><th>주 소</th><td colspan="3">{fullAddress(p, hh)}</td></tr>
						{#if doc === '교인증명서'}
							<tr><th>등 록 일</th><td>{ymd(s.registered)}</td><th>직 분</th><td>{s.title || '성도'}</td></tr>
							<tr><th>신 급</th><td>{s.faith}</td><th>용 도</th><td>{purpose}</td></tr>
						{:else if baptism}
							<tr><th>구 분</th><td>{baptism.kind}</td><th>세 례 일</th><td>{ymd(baptism.event_date)}</td></tr>
							<tr><th>세례 교회</th><td>{baptism.church ?? CHURCH}</td><th>집 례 자</th><td>{v(baptism.officiant)}</td></tr>
							<tr><th>용 도</th><td colspan="3">{purpose}</td></tr>
						{/if}
					</tbody></table>
					<p class="cert-body">{doc === '교인증명서' ? cf.memberBody : cf.baptismBody.replace('{구분}', baptism?.kind ?? '세례')}</p>
					<p class="cert-date">{todayKo}</p>
					<!-- 교회 이름 자리에 교회 로고 -->
					<!-- 직인: 교적 관리에서 바로 뽑을 때는 늘, 홈페이지 신청은 관리자가 승인한 뒤에만 -->
					<p class="cert-sign"><img class="cert-logo" src={registry.churchInfo.logo || hrefTo('/church-logo.png')} alt="{registry.churchInfo.denomination} {CHURCH}" width="360" height="67" style="width:70mm;height:auto" /><br />{registry.churchInfo.pastorLabel} <b>{pastor}</b> <span class="seal-wrap">(인){#if sealOk}<img class="seal-img" src={sealSrc} onerror={() => (sealFail = true)} alt="교회 직인" style="width:{cf.sealSize}mm;height:{cf.sealSize}mm" />{/if}</span></p>
					{#if cf.footNote}<p class="foot-note" style="text-align:center;border:0">{cf.footNote}</p>{/if}
				</div>

			{:else if doc === '기부금 영수증' && p}
				<div class="cert rcpt">
					<p class="cert-no">일련번호 : {docNo}</p>
					<h2 class="cert-title">기 부 금 영 수 증</h2>
					<table class="dt rcpt-t"><tbody>
						<tr><th rowspan="3" class="grp">기<br />부<br />자</th><th>성 명</th><td>{p.name}</td></tr>
						<tr><th>주민등록번호</th><td class="blank"></td></tr>
						<tr><th>주 소</th><td>{fullAddress(p, hh)}</td></tr>
						<tr><th rowspan="4" class="grp">기<br />부<br />금<br />단<br />체</th><th>단 체 명</th><td>{registry.churchInfo.denomination} {CHURCH}</td></tr>
						<tr><th>고유번호</th><td>{registry.churchInfo.bizNo}</td></tr>
						<tr><th>소 재 지</th><td>{registry.churchInfo.address}</td></tr>
						<tr><th>대 표 자</th><td>{registry.churchInfo.pastorLabel} {pastor}</td></tr>
					</tbody></table>
					<h4>기부금 모금액</h4>
					<table class="dt list rcpt-m">
						<thead><tr><th>유형</th><th>코드</th><th>기부 기간</th><th>내용</th><th>금액 (원)</th></tr></thead>
						<tbody>
							{#each donLines as r}<tr><td>지정기부금</td><td>41</td><td>{ymd(r.from)} ~ {ymd(r.to)}</td><td>{r.kind}</td><td class="amt">{r.n.toLocaleString()}</td></tr>{/each}
							<tr class="sum"><th colspan="4">합 계</th><td class="amt"><b>{donTotal.toLocaleString()}</b></td></tr>
						</tbody>
					</table>
					<p class="rcpt-won">일금 <b>{wonKo(donTotal)}</b>원정 (₩{donTotal.toLocaleString()})</p>
					<p class="rcpt-body">위와 같이 기부금을 기부받았음을 증명합니다.</p>
					<p class="cert-date">{todayKo}</p>
					<p class="cert-sign"><img class="cert-logo" src={registry.churchInfo.logo || hrefTo('/church-logo.png')} alt="{registry.churchInfo.denomination} {CHURCH}" width="360" height="67" style="width:70mm;height:auto" /><br />{registry.churchInfo.pastorLabel} <b>{pastor}</b> <span class="seal-wrap">(인)<img class="seal-img" src={sealSrc} onerror={() => (sealFail = true)} alt="교회 직인" style="width:{cf.sealSize}mm;height:{cf.sealSize}mm" /></span></p>
					<p class="foot-note" style="text-align:center;border:0">※ 이 영수증은 소득세 및 법인세 신고 시 기부금 영수증으로 사용할 수 있습니다.</p>
				</div>

			{:else if doc === '교인 요람'}
				<div class="doc-head"><span>{CHURCH}</span><h2>교인 요람</h2><span>{comm === '전체' ? `전체 ${registry.t1}` : comm} · {directory.length}명</span></div>
				<table class="dt list zebra"><thead><tr><th>{registry.t1}</th><th>{registry.t2}</th><th>이름</th><th>직분</th><th>휴대폰</th><th>주소</th></tr></thead><tbody>
					{#each directory as { p: x, s: xs } (x.id)}<tr><td>{registry.short(xs.community)}</td><td>{xs.smallGroup}</td><td><b>{x.name}</b></td><td>{xs.title}</td><td>{v(x.phone)}</td><td>{fullAddress(x, registry.householdOf(x.id))}</td></tr>
					{:else}<tr><td colspan="6" class="c">요람에 실을 사람이 없습니다.</td></tr>{/each}
				</tbody></table>
				<p class="foot-note">요람 공개에 동의한 교인만 실었습니다. 교회 밖으로 내보내지 마세요.</p>

			{:else if doc === '생일 명단'}
				<div class="doc-head"><span>{CHURCH}</span><h2>{month}월 생일 명단</h2><span>{birthdays.length}명</span></div>
				<table class="dt list zebra"><thead><tr><th>날짜</th><th>이름</th><th>직분</th><th>양/음</th><th>나이</th><th>{registry.t1} · {registry.t2}</th><th>휴대폰</th></tr></thead><tbody>
					{#each birthdays as { p: x, s: xs } (x.id)}<tr><td class="c">{md(x.birth_date)}</td><td><b>{x.name}</b></td><td>{xs.title}</td><td class="c">{x.birth_lunar ? '음력' : '양력'}</td><td class="c">{(ageOf(x.birth_date) ?? 0) + (+x.birth_date!.slice(5, 7) > now.getMonth() + 1 ? 1 : 0)}</td><td>{xs.smallGroup ? `${registry.short(xs.community)} · ${xs.smallGroup}` : ''}</td><td>{v(x.phone)}</td></tr>
					{:else}<tr><td colspan="7" class="c">이 달 생일자가 없습니다.</td></tr>{/each}
				</tbody></table>
				<p class="foot-note">음력 생일은 해당 연도의 양력 날짜로 바꿔야 정확합니다. (실제 시스템에서 변환)</p>

			{:else if doc === '교회 조직표'}
				<div class="doc-head"><span>{CHURCH}</span><h2>{now.getFullYear()}년도 교회 조직표</h2><span>{todayKo}</span></div>
				<table class="dt list meet-t"><thead><tr><th>회의</th><th>의장</th><th>서기</th></tr></thead><tbody>
					{#each registry.d.meetings as m (m.meeting)}<tr><td class="c"><b>{m.meeting}</b></td><td class="c">{m.chair}</td><td class="c">{m.secretary}</td></tr>{/each}
				</tbody></table>
				<div class="org-cols">
					{#each orgCols as col}<div class="org-col">{#each col as { c } (c.id)}
						<table class="dt list keep">
							<thead><tr><th colspan="3" class="cm-th">{c.name} <span>위원장 {c.leader_id ? `${pname(c.leader_id)} 장로` : ''}</span></th></tr>
								<tr><th>부서</th><th>부서장</th><th>담당자</th></tr></thead>
							<tbody>{#each deptsOf(c.id) as d}<tr><td>{d.name}</td><td class="c">{pname(d.leader_id)}</td><td class="c">{d.staff_ids.map(pname).join(', ')}</td></tr>{/each}</tbody>
						</table>
					{/each}</div>{/each}
				</div>

			{:else if doc === '직분자 명단'}
				<div class="doc-head"><span>{CHURCH}</span><h2>직분자 명단</h2><span>{officers.length}명</span></div>
				<table class="dt list zebra"><thead><tr><th>직분</th><th>구분</th><th>이름</th><th>표시</th><th>임직일</th><th>임직교회</th><th>{registry.t2}</th></tr></thead><tbody>
					{#each officers as { t, p: x } (t.id)}<tr><td>{t.office}</td><td class="c">{t.rank_status}</td><td><b>{x.name}</b></td><td>{officeTitle(t)}</td><td class="c">{ymd(t.start_date)}</td><td>{t.church ?? '본 교회'}</td><td>{registry.summary(x).smallGroup}</td></tr>{/each}
				</tbody></table>
			{/if}
		</div>
	</div>
{:else if needsPerson && !p}
	<p class="empty">교인을 고르면 미리보기가 나옵니다.</p>
{/if}

<style>
	.sheet-wrap { overflow-x: auto; padding: 0.5rem 0 2rem; }
	.sheet { width: 190mm; min-height: 270mm; margin: 0 auto; background: #fff; color: #111; padding: 12mm 12mm 14mm; box-shadow: 0 6px 28px rgba(30, 37, 69, 0.14); border: 1px solid #dfe3ec; font-size: 10.5pt; line-height: 1.5; }
	.doc-head { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2.5px solid #111; padding-bottom: 6px; margin-bottom: 10px; font-size: 9pt; color: #444; }
	.doc-head h2 { margin: 0; font-size: 18pt; letter-spacing: 0.3em; color: #111; }
	h4 { margin: 14px 0 5px; font-size: 10.5pt; padding-left: 7px; border-left: 4px solid #2b3e95; }
	.dt { width: 100%; border-collapse: collapse; border: 1px solid #000; }
	.dt th, .dt td { border: 1px solid #000; padding: 5px 7px; font-size: 9.5pt; text-align: left; vertical-align: middle; background: #fff; color: #111; }
	.dt th { background: #f0f2fa; font-weight: 700; white-space: nowrap; width: 1%; }
	.dt.list th { width: auto; text-align: center; background: #e0e4f4; }
	.dt.zebra tbody tr:nth-child(even) td { background: #f5f6fb; }
	.dt tbody tr:hover { background: none; }
	.dt .c { text-align: center; color: #555; }
	/* 교인 요람 · 생일 명단 · 직분자 명단(zebra), 교회 조직표(meet-t · keep): 표 내용 가운데 정렬 */
	.dt.zebra td, .dt.meet-t td, .dt.keep td { text-align: center; }
	.photo-cell { width: 28mm; text-align: center; }
	.photo-cell img { width: 26mm; height: 34mm; object-fit: cover; display: block; margin: 0 auto; }
	.photo-cell span { display: grid; place-items: center; width: 26mm; height: 34mm; margin: 0 auto; border: 1px dashed #aaa; color: #999; font-size: 9pt; }
	.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
	.meet-t { margin-bottom: 12px; }
	.org-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; align-items: start; }
	.org-col { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
	.cm-th { background: #2b3e95 !important; color: #fff !important; text-align: left !important; font-size: 10pt !important; }
	.cm-th span { float: right; font-weight: 500; font-size: 9pt; }
	.foot-note { margin-top: 14px; font-size: 8.5pt; color: #666; border-top: 1px solid #ccc; padding-top: 6px; }
	.cert { padding: 6mm 4mm; }
	tr.on-req td { background: var(--primary-50); }
	.cert-no { font-size: 10pt; color: #333; margin: 0 0 18mm; }
	/* 기부금 영수증: 증명서 틀을 쓰되 위아래 간격을 줄여 한 장에 */
	.rcpt .cert-no { margin-bottom: 8mm; }
	.rcpt .cert-title { margin-bottom: 9mm; }
	.rcpt-t th { text-align: center; width: 30mm; }
	.rcpt-t th.grp { width: 12mm; line-height: 1.5; background: #e0e4f4; }
	.rcpt-t td { padding: 8px 10px; font-size: 10.5pt; }
	.rcpt-t td.blank { height: 9mm; }
	.rcpt-m td { text-align: center; font-size: 10pt; }
	.rcpt-m td.amt { text-align: right; }
	.rcpt-m tr.sum th { text-align: center; }
	.rcpt-won { text-align: center; font-size: 13pt; margin: 6mm 0 4mm; }
	.rcpt-body { text-align: center; font-size: 12pt; margin: 3mm 0 6mm; }
	.rcpt .cert-date { margin-bottom: 6mm; }
	.rcpt .cert-sign { line-height: 1.8; }
	.rcpt .foot-note { margin-top: 4mm; }
	.don { margin-top: 0.8rem; padding: 0.8rem 0.9rem; border-radius: 12px; background: var(--primary-50); }
	.don-t input { padding: 0.3rem 0.5rem; font-size: 0.88rem; }
	.don-t .dates { display: flex; align-items: center; gap: 0.4rem; white-space: nowrap; }
	.don-t input[type='date'] { width: 9.5rem; min-width: 0; }
	.cert-title { text-align: center; font-size: 24pt; letter-spacing: 0.25em; margin: 0 0 14mm; padding-bottom: 4mm; border-bottom: 3px double #111; }
	.cert-table th { width: 22mm; text-align: center; }
	.cert-table td { padding: 9px 10px; font-size: 11pt; }
	.cert-body { text-align: center; font-size: 13pt; margin: 22mm 0 14mm; }
	.cert-date { text-align: center; font-size: 12pt; margin-bottom: 22mm; }
	.cert-sign { text-align: center; font-size: 14pt; line-height: 2; }
	.cert-logo { display: inline-block; width: 70mm; height: auto; margin-bottom: 4mm; vertical-align: middle; }
	/* 직인: '(인)' 글자 위에 겹쳐 찍힌 모양 */
	.seal-wrap { position: relative; display: inline-block; margin-left: 4mm; }
	.seal-img { position: absolute; left: 50%; top: 50%; width: 22mm; height: 22mm; transform: translate(-50%, -50%) rotate(-3deg); object-fit: contain; mix-blend-mode: multiply; opacity: 0.92; }
	@media print {
		:global(.site-header), :global(.site-footer), :global(.tabbar), :global(.to-top), .no-print { display: none !important; }
		:global(.site > main) { padding: 0 !important; max-width: none !important; }
		:global(body) { background: #fff !important; }
		.sheet-wrap { padding: 0; overflow: visible; }
		.sheet { box-shadow: none; border: 0; width: auto; min-height: 0; padding: 0; }
		.dt tr { break-inside: avoid; }
		.sheet.one-page { zoom: var(--fit, 1); } /* 교회 조직표: 한 장에 맞춰 축소 */
	}
	@page { size: A4; margin: 12mm; }
</style>
