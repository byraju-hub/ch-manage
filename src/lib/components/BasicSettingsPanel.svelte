<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 관리자 › 교회 설정 › 기본 설정: 교회 기본 정보 · 로고 · 직인 · 증명서 양식
	import { registry } from '#lib/registry/store.svelte.ts';
	import { UNIT1_OPTIONS, UNIT2_OPTIONS, certNo, shrinkImage, squareIcon, type CertForm, type ChurchInfo } from '#lib/registry/site-settings.ts';

	let info = $state<ChurchInfo>(structuredClone($state.snapshot(registry.churchInfo)) as ChurchInfo);
	let cert = $state<CertForm>(structuredClone($state.snapshot(registry.certForm)) as CertForm);
	let purposeText = $state(cert.purposes.join(', '));
	let msg = $state('');
	let err = $state('');
	const can = $derived(registry.canSetup);

	// 그림(로고·직인·탭 아이콘)은 이 화면의 복사본이 아니라 '서버와 맞춰진 값'(registry)을 기준으로 보낸다.
	// 복사본에는 방금 올린 큰 그림(데이터)이 그대로 남아 있어서, 다른 칸을 저장할 때 다시 보내면 한 번에 보낼 수 있는 크기를 넘는다.
	type ImgKind = 'logo' | 'seal' | 'favicon';
	function payload(change?: { kind: ImgKind; value: string | null }): ChurchInfo {
		const base = registry.churchInfo;
		const out = { ...info, logo: base.logo, seal: base.seal, favicon: base.favicon ?? null } as ChurchInfo;
		if (change) out[change.kind] = change.value;
		return out;
	}
	function saveInfo() {
		if (!info.name.trim()) { err = '교회 이름을 적어 주세요.'; return; }
		if (!info.unit1.trim() || !info.unit2.trim()) { err = '모임 이름(1단계 · 2단계)을 적어 주세요.'; return; }
		err = '';
		registry.saveSite({ churchInfo: payload() }, '기본 설정 · 교회 정보 저장');
		msg = '교회 정보를 저장했습니다. 화면 위쪽 이름과 문서에 바로 반영됩니다.';
	}
	function saveCert() {
		cert.purposes = purposeText.split(/[,\n]/).map((x) => x.trim()).filter(Boolean);
		registry.saveSite({ certForm: cert }, '기본 설정 · 증명서 양식 저장');
		msg = '증명서 양식을 저장했습니다.';
	}
	async function pick(e: Event, kind: 'logo' | 'seal') {
		const f = (e.currentTarget as HTMLInputElement).files?.[0];
		(e.currentTarget as HTMLInputElement).value = '';
		if (!f) return;
		if (!/^image\//.test(f.type)) { err = '그림 파일(PNG · JPG)을 골라 주세요.'; return; }
		try {
			const img = await shrinkImage(f, kind === 'logo' ? 900 : 500);
			info[kind] = img; // 이 화면에 바로 보이게
			err = '';
			registry.saveSite({ churchInfo: payload({ kind, value: img }) }, `기본 설정 · ${kind === 'logo' ? '로고' : '직인'} 올림`);
			msg = `${kind === 'logo' ? '로고' : '직인'}를 올렸습니다.`;
		} catch {
			err = '그림을 읽지 못했습니다.';
		}
	}
	async function pickFavicon(e: Event) {
		const f = (e.currentTarget as HTMLInputElement).files?.[0];
		(e.currentTarget as HTMLInputElement).value = '';
		if (!f) return;
		if (!/^image\//.test(f.type)) { err = '그림 파일(PNG · JPG)을 골라 주세요.'; return; }
		try {
			const img = await squareIcon(f);
			info.favicon = img;
			err = '';
			registry.saveSite({ churchInfo: payload({ kind: 'favicon', value: img }) }, '기본 설정 · 탭 아이콘 올림');
			msg = '탭 아이콘을 올렸습니다. 브라우저가 이전 아이콘을 기억하고 있으면 Ctrl+F5 로 새로고침해 주세요.';
		} catch {
			err = '그림을 읽지 못했습니다.';
		}
	}
	function clearFavicon() {
		info.favicon = null;
		registry.saveSite({ churchInfo: payload({ kind: 'favicon', value: null }) }, '기본 설정 · 탭 아이콘 지움');
		msg = '탭 아이콘을 기본(푸른 배경의 십자가)으로 되돌렸습니다.';
	}
	// 도로명 주소 검색: 고르면 주소가 들어가고, 이어서 상세주소(층·호)를 적을 수 있게 커서를 둠
	let addrInput = $state<HTMLInputElement>();
	function searchAddress() {
		err = '';
		const open = () => new (window as any).daum.Postcode({
			oncomplete: (d: any) => {
				info.address = (d.roadAddress || d.jibunAddress) + ' ';
				setTimeout(() => { addrInput?.focus(); addrInput?.setSelectionRange(info.address.length, info.address.length); });
			}
		}).open();
		if ((window as any).daum?.Postcode) return open();
		const sc = document.createElement('script');
		sc.src = 'https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
		sc.onload = open;
		sc.onerror = () => (err = '주소 검색 서비스에 연결할 수 없습니다. 직접 입력해 주세요. (인터넷 연결이 필요합니다)');
		document.head.appendChild(sc);
	}
	function clearImg(kind: 'logo' | 'seal') {
		info[kind] = null;
		registry.saveSite({ churchInfo: payload({ kind, value: null }) }, `기본 설정 · ${kind === 'logo' ? '로고' : '직인'} 지움`);
		msg = `${kind === 'logo' ? '로고' : '직인'}를 지우고 기본으로 되돌렸습니다.`;
	}
</script>

{#if !can}<div class="warn">기본 설정은 최고관리자·사이트 관리자만 바꿀 수 있습니다.</div>{/if}
{#if msg}<p class="ok">{msg}</p>{/if}
{#if err}<p class="warn">{err}</p>{/if}

<section class="card">
	<div class="card-head"><h2>교회 기본 정보</h2><span class="sub">화면 위쪽 '○○교회 교적 관리', 메뉴 이름, 증명서·문서에 쓰입니다</span></div>
	<div class="form">
		<label><span class="lt">교회 이름<span class="req">*</span></span><input bind:value={info.name} placeholder="예: 은혜교회" /></label>
		<label>교단<input bind:value={info.denomination} placeholder="예: 대한예수교장로회" /></label>
		<label>창립일<input type="date" bind:value={info.founded} /></label>
		<label><span class="lt">고유번호 <small class="hint">(기부금 영수증에 쓰임)</small></span><input bind:value={info.bizNo} placeholder="예: 000-00-00000" /></label>
		<label class="wide"><span class="lt">주소 <small class="hint">(검색해서 고른 뒤 상세주소를 이어 적으세요)</small></span>
			<span class="row" style="flex-wrap:nowrap"><input bind:this={addrInput} bind:value={info.address} placeholder="주소 검색 버튼을 누르세요" /><button type="button" class="ghost" onclick={searchAddress} disabled={!can}>주소 검색</button></span>
		</label>
		<label>대표 전화<input bind:value={info.phone} placeholder="032-000-0000" /></label>
		<label>팩스<input bind:value={info.fax} /></label>
		<label>이메일<input type="email" bind:value={info.email} /></label>
		<label>홈페이지<input bind:value={info.homepage} placeholder="https://" /></label>
		<label>서명 직함<input bind:value={info.pastorLabel} placeholder="담임목사" /></label>
		<label><span class="lt">서명 이름 <small class="hint">(비우면 교적의 담임목사)</small></span><input bind:value={info.pastorName} /></label>
	</div>
	<!-- 모임 이름 (2단계): 화면 전체의 '공동체'·'소그룹'이 이 이름으로 바뀜 -->
	<div class="units">
		<b>모임 이름</b>
		<label>1단계
			<span class="row" style="gap:.3rem;flex-wrap:nowrap">
				<select value={UNIT1_OPTIONS.includes(info.unit1) ? info.unit1 : '직접 입력'} onchange={(e) => (info.unit1 = e.currentTarget.value === '직접 입력' ? '' : e.currentTarget.value)}>{#each [...UNIT1_OPTIONS, '직접 입력'] as o}<option>{o}</option>{/each}</select>
				{#if !UNIT1_OPTIONS.includes(info.unit1)}<input bind:value={info.unit1} placeholder="예: 권역" style="width:8rem" />{/if}
			</span>
		</label>
		<label>2단계
			<span class="row" style="gap:.3rem;flex-wrap:nowrap">
				<select value={UNIT2_OPTIONS.includes(info.unit2) ? info.unit2 : '직접 입력'} onchange={(e) => (info.unit2 = e.currentTarget.value === '직접 입력' ? '' : e.currentTarget.value)}>{#each [...UNIT2_OPTIONS, '직접 입력'] as o}<option>{o}</option>{/each}</select>
				{#if !UNIT2_OPTIONS.includes(info.unit2)}<input bind:value={info.unit2} placeholder="예: 가정교회" style="width:8rem" />{/if}
			</span>
		</label>
		<span class="muted" style="font-size:.8rem">예) {info.unit1 || '1단계'} 편성표 · {info.unit2 || '2단계'}별 명단 · {info.unit2 || '2단계'} 특이사항 — 메뉴와 모든 화면에 적용됩니다.</span>
	</div>
	<div class="row" style="justify-content:flex-end;margin-top:.8rem"><button type="button" onclick={saveInfo} disabled={!can}>교회 정보 저장</button></div>
</section>

<div class="dash-grid even">
	<section class="card">
		<div class="card-head"><h2>교회 로고</h2><span class="sub">증명서 아래 교회 이름 자리에 들어갑니다</span></div>
		<div class="img-box"><img src={info.logo || hrefTo('/church-logo.png') + '?v=2'} alt="교회 로고" style="max-height:70px" /></div>
		<div class="row" style="margin-top:.7rem">
			<label class="btn ghost sm file-btn">로고 올리기<input type="file" accept="image/*" disabled={!can} onchange={(e) => pick(e, 'logo')} /></label>
			{#if info.logo}<button type="button" class="ghost sm" onclick={() => clearImg('logo')} disabled={!can}>기본 로고로</button>{/if}
			<span class="muted" style="font-size:.8rem">{info.logo ? '올린 로고를 쓰는 중' : '기본 로고를 쓰는 중'}</span>
		</div>
	</section>
	<section class="card">
		<div class="card-head"><h2>교회 직인</h2><span class="sub">관리자가 승인한 증명서에만 찍힙니다</span></div>
		<div class="img-box">{#if info.seal}<img src={info.seal} alt="교회 직인" style="max-height:110px" />{:else}<img src={hrefTo('/private/church-seal.png')} alt="교회 직인" style="max-height:110px" onerror={(e) => ((e.currentTarget as HTMLImageElement).src = hrefTo('/seal-sample.svg'))} />{/if}</div>
		<div class="row" style="margin-top:.7rem">
			<label class="btn ghost sm file-btn">직인 올리기<input type="file" accept="image/*" disabled={!can} onchange={(e) => pick(e, 'seal')} /></label>
			{#if info.seal}<button type="button" class="ghost sm" onclick={() => clearImg('seal')} disabled={!can}>올린 직인 지우기</button>{/if}
		</div>
		<p class="muted" style="font-size:.8rem;margin:.5rem 0 0">배경이 투명한 PNG가 가장 깔끔합니다. 올린 그림은 서버에 저장됩니다. 직인은 증명서를 만들 수 있는 사람(내려받기 권한)에게만 보이고, 그림 크기는 자동으로 줄어듭니다.</p>
	</section>
</div>

<section class="card">
	<div class="card-head"><h2>탭 아이콘 (파비콘)</h2><span class="sub">인터넷 창 탭, 즐겨찾기, 화면 왼쪽 위 제목 옆에 나오는 작은 그림입니다</span></div>
	<div class="row" style="align-items:center;gap:1rem">
		<div class="img-box"><img src={info.favicon || hrefTo('/apple-touch-icon.png') + '?v=2'} alt="탭 아이콘" width="64" height="64" style="border-radius:12px" /></div>
		<div>
			<div class="row">
				<label class="btn ghost sm file-btn">아이콘 올리기<input type="file" accept="image/*" disabled={!can} onchange={pickFavicon} /></label>
				{#if info.favicon}<button type="button" class="ghost sm" onclick={clearFavicon} disabled={!can}>기본 아이콘으로</button>{/if}
				<span class="muted" style="font-size:.8rem">{info.favicon ? '올린 아이콘을 쓰는 중' : '기본 아이콘(푸른 배경의 십자가)을 쓰는 중'}</span>
			</div>
			<p class="muted" style="font-size:.8rem;margin:.5rem 0 0">정사각형 그림이 가장 좋습니다. 직사각형이면 가운데를 정사각형으로 잘라 씁니다. 로고 그림을 그대로 올려도 됩니다.</p>
		</div>
	</div>
</section>

<section class="card">
	<div class="card-head"><h2>증명서 양식</h2><span class="sub">교인증명서 · 세례증명서</span></div>
	<div class="form">
		<label><span class="lt">발급 번호 <small class="hint">(미리보기: {certNo(cert, new Date().getFullYear(), 1)})</small></span>
			<span class="row" style="gap:.3rem;flex-wrap:nowrap"><input bind:value={cert.prefix} style="width:3.5rem" aria-label="앞 글자" /><input bind:value={cert.format} aria-label="번호 모양" /><input bind:value={cert.suffix} style="width:3.5rem" aria-label="뒤 글자" /></span></label>
		<label><span class="lt">번호 자릿수 <small class="hint">({'{번호}'} = 올해 발급 순서)</small></span><input type="number" min="1" max="6" bind:value={cert.digits} /></label>
		<label class="wide"><span class="lt">용도 목록 <small class="hint">(쉼표로 나눔)</small></span><input bind:value={purposeText} /></label>
		<label class="wide">교인증명서 본문<input bind:value={cert.memberBody} /></label>
		<label class="wide"><span class="lt">세례증명서 본문 <small class="hint">({'{구분}'} = 세례 · 유아세례 · 입교)</small></span><input bind:value={cert.baptismBody} /></label>
		<label class="wide"><span class="lt">아래쪽 안내 문구 <small class="hint">(비우면 없음)</small></span><input bind:value={cert.footNote} placeholder="예: 이 증명서는 발급일로부터 3개월간 유효합니다." /></label>
		<label><span class="lt">직인 크기 <small class="hint">(mm)</small></span><input type="number" min="12" max="40" bind:value={cert.sealSize} /></label>
	</div>
	<div class="row" style="justify-content:space-between;margin-top:.8rem">
		<a href={hrefTo(`/documents?doc=${encodeURIComponent('교인증명서')}`)} class="muted" style="font-size:.85rem">문서 출력에서 미리 보기 →</a>
		<button type="button" onclick={saveCert} disabled={!can}>증명서 양식 저장</button>
	</div>
</section>

<style>
	.units { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem 1.2rem; margin-top: 1rem; padding: 0.8rem 0.9rem; border-radius: 12px; background: var(--primary-50); }
	/* '1단계' 글자 옆에 드롭다운이 한 줄로 */
	.units label { display: flex; flex-direction: row; align-items: center; gap: 0.45rem; font-size: 0.82rem; font-weight: 600; color: var(--muted); white-space: nowrap; }
	.units select { width: auto; }
	.img-box { display: grid; place-items: center; min-height: 120px; padding: 0.8rem; border: 1px dashed var(--line-strong); border-radius: 12px; background: #fff; }
	.img-box img { max-width: 100%; object-fit: contain; }
	.file-btn { position: relative; overflow: hidden; cursor: pointer; }
	.file-btn input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
</style>
