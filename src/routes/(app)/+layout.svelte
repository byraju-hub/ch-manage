<script lang="ts">
	// 교적 화면 공통 틀: 로그인(아이디·비밀번호), 상단 메뉴, 휴대폰 아래 메뉴
	import { onMount } from 'svelte';
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { registry } from '#lib/registry/store.svelte.ts';
	import { auth } from '#lib/registry/auth.svelte.ts';
	import { hrefTo, stripBase } from '#lib/registry/paths.ts';
	import { sync } from '#lib/registry/sync.svelte.ts';
	import type { AppUser } from '#lib/registry/types.ts';
	import { formatMobile } from '#lib/registry/derive.ts';
	import { openFinance } from '#lib/registry/finance.ts';
	import LeaderAttendance from '#lib/components/LeaderAttendance.svelte';

	let { children } = $props();

	// 로그인: 아이디·비밀번호. 처음이면 '가입 신청' → 최고관리자가 사용자 목록에서 승인하고 등급(A·B·C) 지정 → 사용
	// 30분 동안 쓰지 않으면 로그아웃 (서버 세션도 같은 시간에 끝남)
	const IDLE_MS = 30 * 60 * 1000;
	let mode = $state<'login' | 'signup' | 'sent'>('login');
	const EMPTY_SU = { loginId: '', name: '', password: '', password2: '', email: '', phone: '' };
	let lf = $state({ loginId: '', password: '' });
	let su = $state({ ...EMPTY_SU });
	let pwf = $state({ current: '', next: '', next2: '' });
	let pwOpen = $state(false);
	let busy = $state(false);
	let loginMsg = $state('');
	let finMsg = $state('');
	const showFinance = $derived(registry.features.finance.available && !auth.user?.is_demo);
	async function goFinance() { finMsg = (await openFinance()) ?? ''; }
	let last = Date.now();
	let menuOpen = $state(false);
	let userOpen = $state(false);
	let scrollY = $state(0);
	// 스크롤을 내리는 만큼 상단바가 조금씩 줄어듦 (맨 위 0 → 160px 내리면 1 = 지금 높이, 최소)
	// 단계로 바뀌지 않고 스크롤 위치를 그대로 따라가서 자연스럽게 작아짐
	const SHRINK_PX = 160;
	const hbP = $derived(Math.round(Math.min(1, Math.max(0, scrollY / SHRINK_PX)) * 100) / 100);
	const toTop = () => window.scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
	const me = $derived(registry.me);
	const mustChange = $derived(!!me && !!me.must_change_password);
	// 소그룹 인도자(D 등급): 교적 자료를 받지 않고, 자기 소그룹 출석 화면만 본다
	const isLeader = $derived(!!auth.user && auth.user.grade === 'D' && !auth.user.is_super && auth.user.status === '사용');
	const unlocked = $derived(!!me && me.status === '사용' && !mustChange && registry.loaded);

	// 로그인하면 내 등급으로 볼 수 있는 자료를 서버에서 받고, 로그아웃·세션 만료 때는 화면의 자료를 모두 지운다
	$effect(() => {
		const ready = !!auth.user && auth.user.status === '사용' && !auth.user.must_change_password && !isLeader;
		if (ready && !registry.loaded && !registry.loading && !registry.loadError) void registry.load();
		if (!auth.user && (registry.loaded || registry.loadError)) registry.unload();
	});

	onMount(() => {
		// 소개 페이지의 '데모 사이트 구경하기'(?demo=1)로 들어오면 체험 계정으로 바로 로그인한다.
		auth.init().then(async () => {
			if (page.url.searchParams.get('demo') === '1' && auth.demoAccount && !auth.user) {
				try { await auth.login('demo', '1234'); last = Date.now(); } catch { /* 안 되면 로그인 화면 그대로 */ }
			}
		});
		const touch = () => (last = Date.now());
		const outside = (e: MouseEvent) => {
			if (!(e.target as HTMLElement).closest?.('.perm')) userOpen = false;
		};
		window.addEventListener('click', touch);
		window.addEventListener('click', outside);
		window.addEventListener('keydown', touch);
		const t = setInterval(() => {
			if ((unlocked || (isLeader && !mustChange)) && Date.now() - last > IDLE_MS) logout();
		}, 30000);
		return () => {
			window.removeEventListener('click', touch);
			window.removeEventListener('click', outside);
			window.removeEventListener('keydown', touch);
			clearInterval(t);
		};
	});

	// 화면을 옮기면 모바일 메뉴를 닫음
	$effect(() => {
		page.url.pathname;
		menuOpen = false;
	});

	async function logout() {
		userOpen = false;
		pwOpen = false;
		await registry.logout();
		mode = 'login';
		loginMsg = '';
		lf = { loginId: '', password: '' };
		goto(hrefTo('/')); // 로그아웃하면 처음 화면으로
	}
	const msgOf = (e: unknown, fallback: string) => (e instanceof Error ? e.message : fallback);
	async function doLogin(e: SubmitEvent) {
		e.preventDefault();
		loginMsg = '';
		busy = true;
		try {
			await auth.login(lf.loginId.trim(), lf.password);
			last = Date.now();
			lf = { loginId: '', password: '' };
		} catch (err) {
			loginMsg = msgOf(err, '로그인하지 못했습니다.');
			lf.password = '';
		} finally {
			busy = false;
		}
	}
	async function signUp(e: SubmitEvent) {
		e.preventDefault();
		loginMsg = '';
		if (su.password !== su.password2) { loginMsg = '비밀번호가 서로 일치하지 않습니다.'; return; }
		busy = true;
		try {
			await auth.signup({ loginId: su.loginId.trim(), name: su.name.trim(), password: su.password, email: su.email.trim(), phone: su.phone.trim() });
			su = { ...EMPTY_SU };
			mode = 'sent';
		} catch (err) {
			loginMsg = msgOf(err, '가입 신청을 보내지 못했습니다.');
		} finally {
			busy = false;
		}
	}
	// 비밀번호 바꾸기: 임시 비밀번호로 로그인했을 때(필수)와 이름 메뉴에서(선택)
	async function changePw(e: SubmitEvent) {
		e.preventDefault();
		loginMsg = '';
		if (pwf.next !== pwf.next2) { loginMsg = '새 비밀번호가 서로 일치하지 않습니다.'; return; }
		busy = true;
		try {
			await auth.changePassword(pwf.current, pwf.next);
			pwf = { current: '', next: '', next2: '' };
			pwOpen = false;
			loginMsg = '';
		} catch (err) {
			loginMsg = msgOf(err, '비밀번호를 바꾸지 못했습니다.');
		} finally {
			busy = false;
		}
	}
	const roleOf = (u: AppUser) => (u.is_super ? '최고관리자' : (registry.gradeDef(u.grade)?.name ?? '등급 없음'));

	// 상단 메뉴 ('교적 프로그램 구조' 문서 순서)
	type Item = { href: string; label: string; hint?: string };
	// 메뉴 이름의 '공동체'·'소그룹'은 기본 설정에서 고른 이름(교구·구역 등)을 따름
	const memberMenu: Item[] = $derived([
		{ href: '/members', label: '교인 목록', hint: '기본 · 복수 · 항목별 검색' },
		{ href: '/new', label: '교인 등록', hint: '등록 카드 촬영 후 등록 가능' },
		{ href: '/newcomers', label: '새가족 정착', hint: `등록 → 교육 → 수료 → ${registry.t2}` },
		{ href: '/households', label: '세대 목록', hint: '세대 구성 & 분리' }
	]);
	const commMenu: Item[] = $derived([
		{ href: '/communities', label: `${registry.t1} 편성표`, hint: `${registry.t2} · 모임 장소 · 출석 · 특이사항` },
		{ href: '/communities/roster', label: `${registry.t2}별 명단`, hint: `각 ${registry.t2} 명단 · 개인 출석률` }
	]);
	const statMenu: Item[] = $derived([
		{ href: '/stats', label: '통계', hint: `출석 · ${registry.t2} · 임직 · 세례` },
		{ href: '/reports', label: '명단', hint: '생일 · 직분자 · 세례 대상자' },
		{ href: '/documents', label: '문서 출력', hint: '교적부 · 증명서 · 요람 · PDF' }
	]);
	// 관리자 메뉴를 누르면 첫 항목(교회 설정 › 기본 설정)으로
	const path = $derived(stripBase(page.url.pathname));
	const PROGRAM_TABS =['교인구분', '코드표', '확인 목록', '백업', '부서 관리 연결'];
	const adminMenu: Item[] = $derived([
		{ href: '/settings', label: '교회 설정', hint: `기본 · 연간 · 조직표 · ${registry.t2}` },
		{ href: '/admin', label: '사용자 설정', hint: '목록 · 가입 · 등급 · 기록' },
		{ href: `/settings?tab=${encodeURIComponent('교인구분')}`, label: '프로그램 설정', hint: '교인구분 · 코드표 · 확인 목록 · 백업' }
	]);
	// 교회 설정과 프로그램 설정은 같은 화면(/settings)이라 탭으로 어느 쪽인지 가림
	const isProgram = $derived(path === '/settings' && PROGRAM_TABS.includes(page.url.searchParams.get('tab') ?? ''));
	const menuOn = (m: Item) => (m.href.startsWith('/settings?') ? isProgram : m.href === '/settings' ? path === '/settings' && !isProgram : path === m.href);
	const inMenu = (items: Item[]) => items.some((i) => path === i.href || path.startsWith(i.href + '/'));
	// 교적 카드(/p10 같은 주소)는 '교적'에 속한 화면으로 봄
	const known = ['/', '/members', '/new', '/newcomers', '/households', '/communities', '/attendance', '/visits', '/orgs', '/stats', '/reports', '/documents', '/admin', '/settings'];
	const isCard = $derived(path !== '/' && !known.some((p) => path === p || path.startsWith(p + '/')));
	const showAdmin = $derived(registry.perms.admin || registry.isSuper);

	// 새 버전 파일을 올린 뒤 /install/ 을 열지 않아 .htaccess 규칙(https 이동·보안 헤더)이 빠졌으면 최고관리자에게 알린다.
	let sysWarn = $state(false);
	let sysChecked = false;
	$effect(() => {
		if (sysChecked || !unlocked || !auth.user?.is_super || auth.user.is_demo) return;
		sysChecked = true;
		auth.api<{ finalize_needed?: boolean }>('GET', '/system/status').then((s) => (sysWarn = !!s.finalize_needed)).catch(() => {});
	});
</script>

<svelte:window bind:scrollY />

<div class="site">
	<header class="site-header" class:compact={hbP >= 1} style:--hb-p={hbP}>
		<div class="bar">
			<a class="brand" href={hrefTo('/')}>
				<img class="logo" src={registry.churchInfo.favicon || hrefTo('/logo.png') + '?v=2'} alt="" width="38" height="38" />
				<span><b>{registry.churchInfo.name} 교적 관리</b></span>
			</a>

			{#if unlocked}
				<button class="burger" type="button" aria-label="메뉴 열기" aria-expanded={menuOpen} onclick={() => (menuOpen = !menuOpen)}>
					<span></span><span></span><span></span>
				</button>

				<nav class="main-nav" class:open={menuOpen} aria-label="주 메뉴">
					<a href={hrefTo('/')} class:active={path === '/'}>대시보드</a>

					{#snippet dd(label: string, items: Item[], active: boolean)}
						<div class="dd" class:active>
							<!-- 메뉴 이름을 누르면 첫 번째 하위 메뉴로 이동 (마우스를 올리면 하위 메뉴가 펼쳐짐) -->
							<a href={hrefTo(items[0].href)} class="dd-btn" aria-haspopup="true">
								{label}
								<svg width="10" height="10" viewBox="0 0 12 8" aria-hidden="true"><path d="M1 1.5l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
							</a>
							<div class="dd-menu">
								{#each items as m}
									<a href={hrefTo(m.href)} class:active={menuOn(m)}><b>{m.label}</b>{#if m.hint}<small>{m.hint}</small>{/if}</a>
								{/each}
							</div>
						</div>
					{/snippet}
					{@render dd('교적', memberMenu, inMenu(memberMenu) || isCard)}
					{@render dd(registry.t1, commMenu, path.startsWith('/communities'))}
					<a href={hrefTo('/attendance')} class:active={path.startsWith('/attendance')}>출석</a>
					{#if registry.perms.visit}<a href={hrefTo('/visits')} class:active={path.startsWith('/visits')}>심방</a>{/if}
					<a href={hrefTo('/orgs')} class:active={path.startsWith('/orgs')}>조직</a>
					{@render dd('통계', statMenu, inMenu(statMenu))}
					{#if showAdmin}{@render dd('관리자', adminMenu, path === '/settings' || path === '/admin')}{/if}

					<div class="nav-tools">
						{#if showFinance}
							<button type="button" class="tool-btn fin-btn" onclick={goFinance} title="재정 관리 열기 (새로 로그인하지 않아도 돼요)" aria-label="재정 관리 열기">
								재정
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" /></svg>
							</button>
						{/if}
						<div class="perm">
							<button type="button" class="tool-btn" aria-expanded={userOpen} onclick={() => (userOpen = !userOpen)}>
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 21v-1a6 6 0 0112 0v1" /></svg>
								{me?.name} <small class="role">{me ? roleOf(me) : ''}</small>
							</button>
							{#if userOpen}
								<div class="perm-panel">
									<b>{me?.name}</b> <span class="tag">{me ? roleOf(me) : ''}</span>
									<p class="muted">등급은 최고관리자가 '관리자 › 사용자 설정'에서 정합니다.</p>
									<hr />
									{#if !pwOpen}
										<button type="button" class="ghost sm" onclick={() => { pwOpen = true; loginMsg = ''; }}>비밀번호 바꾸기</button>
									{:else}
										<form onsubmit={changePw}>
											<input type="password" bind:value={pwf.current} placeholder="현재 비밀번호" autocomplete="current-password" required style="margin:.2rem 0" />
											<input type="password" bind:value={pwf.next} placeholder="새 비밀번호 (8자 이상)" autocomplete="new-password" minlength="8" required style="margin:.2rem 0" />
											<input type="password" bind:value={pwf.next2} placeholder="새 비밀번호 확인" autocomplete="new-password" required style="margin:.2rem 0" />
											{#if loginMsg}<p style="color:#a83c10;font-size:.82rem;margin:.3rem 0">{loginMsg}</p>{/if}
											<p class="muted" style="font-size:.78rem;margin:.2rem 0">바꾸면 다른 기기에서 로그인한 것은 풀려요.</p>
											<button type="submit" class="sm" disabled={busy}>바꾸기</button>
											<button type="button" class="ghost sm" onclick={() => { pwOpen = false; loginMsg = ''; }}>취소</button>
										</form>
									{/if}
								</div>
							{/if}
						</div>
						<button type="button" class="tool-btn" onclick={logout} title="로그아웃" aria-label="로그아웃">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4" /><path d="M10 17l5-5-5-5M15 12H3" /></svg>
						</button>
					</div>
				</nav>
			{/if}
		</div>
	</header>

	<main>
		{#if finMsg}<p class="sys-warn" role="alert">{finMsg}</p>{/if}
		{#if unlocked && sysWarn}<p class="sys-warn" role="alert"><b>마무리가 덜 된 것이 있어요.</b> 새 버전 파일을 올린 뒤 아직 설치 화면을 열지 않아서, 보안 설정(https 이동·보안 헤더)이 적용되지 않았어요. <a href={hrefTo('/install/')} data-sveltekit-reload>여기를 눌러</a> 한 번 열어 주세요. (열기만 하면 자동으로 마무리돼요)</p>{/if}
		{#if unlocked && auth.user?.is_demo}<p class="demo-bar" role="status">체험 계정으로 보고 있어요. 모든 화면을 볼 수 있지만, 저장하거나 바꿀 수는 없어요. (바꾼 것은 화면에서만 바뀌고 새로고침하면 돌아와요)</p>{/if}
		{#if !auth.ready}
			<div class="card lock"><p class="muted">불러오는 중…</p></div>
		{:else if auth.notInstalled}
			<div class="card lock">
				<h2>아직 설치되지 않았습니다</h2>
				<p class="muted">주소 끝에 <b>/install/</b> 을 붙여 설치를 먼저 진행해 주세요.</p>
			</div>
		{:else if auth.netError}
			<div class="card lock">
				<h2>서버에 연결하지 못했습니다</h2>
				<p class="muted">{auth.netError}</p>
				<p><button type="button" onclick={() => location.reload()}>다시 시도</button></p>
			</div>
		{:else if mustChange}
			<div class="card lock">
				<h2>새 비밀번호를 정해 주세요</h2>
				<p class="muted">임시 비밀번호로 로그인했습니다.<br />계속 쓰려면 나만 아는 비밀번호로 바꿔 주세요.</p>
				<form onsubmit={changePw}>
					<input type="password" bind:value={pwf.current} placeholder="임시 비밀번호" autocomplete="current-password" required style="margin:.25rem 0" />
					<input type="password" bind:value={pwf.next} placeholder="새 비밀번호 (8자 이상)" autocomplete="new-password" minlength="8" required style="margin:.25rem 0" />
					<input type="password" bind:value={pwf.next2} placeholder="새 비밀번호 확인" autocomplete="new-password" required style="margin:.25rem 0" />
					{#if loginMsg}<p style="color:#a83c10;font-size:.88rem;margin:.5rem 0">{loginMsg}</p>{/if}
					<button type="submit" style="width:100%;margin-top:.5rem" disabled={busy}>비밀번호 바꾸기</button>
				</form>
				<p><button type="button" class="ghost sm" onclick={logout}>로그아웃</button></p>
			</div>
		{:else if isLeader}
			<LeaderAttendance onlogout={logout} />
		{:else if me && me.status === '사용' && !mustChange && !registry.loaded}
			<div class="card lock">
				{#if registry.loadError}
					<h2>자료를 불러오지 못했습니다</h2>
					<p class="muted">{registry.loadError}</p>
					<p><button type="button" onclick={() => registry.load()}>다시 시도</button> <button type="button" class="ghost" onclick={logout}>로그아웃</button></p>
				{:else}
					<p class="muted">교적 자료를 불러오는 중…</p>
				{/if}
			</div>
		{:else if !unlocked}
			<div class="card lock">
				<img src={registry.churchInfo.favicon || hrefTo('/api/v1/files/favicon?size=180')} alt="" width="64" height="64" style="display:block;margin:0 auto .8rem;border-radius:18px;box-shadow:0 6px 18px rgba(43,62,149,.25)" />
				{#if mode === 'login'}
					<h2 class="brand-font">{registry.churchInfo.name} 교적 관리</h2>
					<form onsubmit={doLogin}>
						<input bind:value={lf.loginId} placeholder="아이디" autocomplete="username" autocapitalize="off" required style="margin:.25rem 0" />
						<input type="password" bind:value={lf.password} placeholder="비밀번호" autocomplete="current-password" required style="margin:.25rem 0" />
						{#if loginMsg}<p style="color:#a83c10;font-size:.88rem;margin:.5rem 0">{loginMsg}</p>{/if}
						<button type="submit" style="width:100%;margin-top:.5rem" disabled={busy}>로그인</button>
					</form>
					{#if auth.demoAccount}<p class="demo-hint">체험해 보기 · 아이디 <b>demo</b> / 비밀번호 <b>1234</b> (보기만 할 수 있어요)</p>{/if}
					<p class="muted" style="font-size:.82rem;margin-top:1rem">처음이신가요? 가입을 신청하면 최고관리자가 승인한 뒤 쓸 수 있습니다.<br />로그인하면 교적 열람 기록이 남습니다.</p>
					<p><button type="button" class="ghost sm" onclick={() => { loginMsg = ''; mode = 'signup'; }}>가입 신청</button></p>
				{:else if mode === 'signup'}
					<h2>가입 신청</h2>
					<p class="muted" style="font-size:.85rem">실명을 정확히 적어 주세요. 누구인지 확인되지 않는 신청은 승인되지 않을 수 있습니다.</p>
					<form onsubmit={signUp} style="text-align:left">
						<input bind:value={su.name} placeholder="실명 (필수)" required style="margin:.25rem 0" />
						<input bind:value={su.loginId} placeholder="아이디 (영문·숫자, 3자 이상)" autocomplete="username" autocapitalize="off" minlength="3" maxlength="50" required style="margin:.25rem 0" />
						<input type="password" bind:value={su.password} placeholder="비밀번호 (8자 이상)" autocomplete="new-password" minlength="8" required style="margin:.25rem 0" />
						<input type="password" bind:value={su.password2} placeholder="비밀번호 확인" autocomplete="new-password" required style="margin:.25rem 0" />
						<input type="email" bind:value={su.email} placeholder="이메일 (선택)" style="margin:.25rem 0" />
						<input value={su.phone} oninput={(e) => (e.currentTarget.value = su.phone = formatMobile(e.currentTarget.value))} inputmode="numeric" maxlength="13" placeholder="휴대폰 (선택)" style="margin:.25rem 0" />
						{#if loginMsg}<p style="color:#a83c10;font-size:.88rem;margin:.5rem 0">{loginMsg}</p>{/if}
						<button type="submit" style="width:100%;margin-top:.5rem" disabled={busy}>가입 신청</button>
					</form>
					<p><button type="button" class="ghost sm" onclick={() => { loginMsg = ''; mode = 'login'; }}>돌아가기</button></p>
				{:else}
					<h2>가입 신청이 접수되었습니다</h2>
					<p class="muted">최고관리자가 사용자 목록에서 승인하고 등급을 정하면 아이디와 비밀번호로 로그인해 쓸 수 있습니다.</p>
					<p><button type="button" class="ghost" onclick={() => { loginMsg = ''; mode = 'login'; }}>로그인 화면으로</button></p>
				{/if}
			</div>
		{:else}
			{#if registry.saveError}<div class="warn">{registry.saveError}</div>{/if}
			{@render children()}
		{/if}
	</main>

	{#if unlocked}
		<!-- 휴대폰 아래쪽 메뉴: 자주 쓰는 화면을 엄지로 바로 -->
		<nav class="tabbar" aria-label="빠른 메뉴">
			<a href={hrefTo('/')} class:on={path === '/'}><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-8 9 8" /><path d="M5 10v10h14V10" /></svg>홈</a>
			<a href={hrefTo('/members')} class:on={path.startsWith('/members') || isCard}><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="4" /><path d="M2 21v-1a7 7 0 0114 0v1" /><path d="M17 4a4 4 0 010 8M22 21v-1a6 6 0 00-4-5.6" /></svg>교인</a>
			{#if registry.perms.edit}<a href={hrefTo('/new')} class:on={path === '/new'}><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 8v8M8 12h8" /></svg>등록</a>{/if}
			{#if registry.perms.visit}<a href={hrefTo('/visits')} class:on={path.startsWith('/visits')}><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V9l8-6 8 6v12" /><path d="M9 21v-6h6v6" /></svg>심방</a>{/if}
			<button type="button" class:on={menuOpen} onclick={() => (menuOpen = !menuOpen)}><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>메뉴</button>
		</nav>
	{/if}

	{#if scrollY > 400}
		<!-- 스크롤을 내리면 오른쪽 아래에 '맨 위로' 버튼 -->
		<button type="button" class="to-top" class:above-save={isLeader} onclick={toTop} aria-label="맨 위로" title="맨 위로">
			<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5" /><path d="M5 12l7-7 7 7" /></svg>
		</button>
	{/if}

	<footer class="site-footer">
		<div class="foot">
			<div class="foot-brand">
				<b>{registry.churchInfo.name} 교적 관리</b>
				<small class="foot-note">모든 조회와 수정은 접속기록에 남습니다.</small>
			</div>
			<div class="foot-copy">
				<span>ⓒ {new Date().getFullYear()} {registry.churchInfo.name}. All Rights Reserved.</span>
				<small>프로그램 <a href="https://github.com/byraju-hub/ch-manage" target="_blank" rel="noopener">ch-manage</a> · GNU AGPL-3.0</small>
			</div>
		</div>
	</footer>
	{#if sync.status === 'saving'}<div class="saving" role="status">저장 중…</div>{/if}
</div>

<style>
	.fin-btn { color: #0f6b40; font-weight: 700; }
	.demo-hint { background: var(--accent-soft, #e6eafa); border-radius: 10px; padding: 8px 12px; font-size: 0.85rem; text-align: center; margin: 0.8rem 0 0; }
	.sys-warn { background: #fff2eb; border: 1px solid #fde0d0; color: #7a3410; border-radius: 10px; padding: 10px 14px; font-size: 0.9rem; margin: 0 0 0.8rem; line-height: 1.6; }
	.sys-warn a { font-weight: 700; text-decoration: underline; }
	.demo-bar { background: #e8f1ff; border: 1px solid #b9d2ff; color: #1b4aa8; border-radius: 10px; padding: 8px 14px; font-weight: 600; text-align: center; font-size: 0.88rem; margin: 0 0 0.8rem; }
	.to-top {
		position: fixed; right: 1.2rem; bottom: calc(1.2rem + env(safe-area-inset-bottom)); z-index: 35;
		display: grid; place-items: center; width: 46px; height: 46px; padding: 0; border-radius: 50%;
		color: #fff; background: var(--primary); border: 0; box-shadow: var(--shadow-md);
	}
	.to-top svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
	@media (max-width: 960px) {
		.to-top { bottom: calc(4.6rem + env(safe-area-inset-bottom)); right: 0.9rem; width: 42px; height: 42px; }
	}
	/* 소그룹 인도자 화면은 아래에 '저장' 줄이 고정되어 있으므로, 맨 위로 버튼을 그 위로 올려 가리지 않게 한다 */
	.to-top.above-save { bottom: calc(5.6rem + env(safe-area-inset-bottom)); }
	.saving { position: fixed; left: 1rem; bottom: 1rem; z-index: 60; padding: 0.35rem 0.8rem; border-radius: 999px; background: rgba(23, 32, 42, 0.86); color: #fff; font-size: 0.8rem; }
</style>
