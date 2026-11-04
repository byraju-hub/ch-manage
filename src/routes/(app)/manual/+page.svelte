<script lang="ts">
	import { onMount } from 'svelte';
	import { hrefTo } from '#lib/registry/paths.ts';
	import { registry } from '#lib/registry/store.svelte.ts';

	// 프로그램 매뉴얼: 화면 순서대로 쓰는 방법을 정리한 안내서. 대시보드의 '프로그램 매뉴얼' 버튼에서 열린다.
	// 목차(왼쪽)·검색·지금 읽는 곳 표시가 있고, 인쇄할 수 있다. 내용을 고칠 때는 아래 GROUPS(목차)와 본문 <section> 의 id 를 맞춘다.
	const GROUPS = $derived([
		{ name: '시작하기', items: [['start', '처음 시작하기'], ['roles', '누가 무엇을 할 수 있나요'], ['home', '대시보드']] },
		{ name: '교적', items: [['members', '교인 찾기와 목록'], ['card', '교인 카드'], ['new', '교인 등록과 새가족'], ['house', '세대']] },
		{ name: '모임 · 출석', items: [['groups', `${registry.t1} · ${registry.t2}`], ['attend', '주일 출석'], ['leader', `${registry.t2} 인도자 화면`], ['visit', '심방']] },
		{ name: '기록 · 출력', items: [['org', '교회 조직'], ['stats', '통계와 명단'], ['docs', '문서 출력']] },
		{ name: '관리', items: [['settings', '교회 설정'], ['users', '사용자 관리'], ['backup', '백업과 개인정보 정리'], ['ext', '문자 · AI · 부서 관리 연결'], ['sample', '샘플 데이터와 업데이트']] },
		{ name: '도움말', items: [['faq', '자주 묻는 질문']] }
	] as { name: string; items: [string, string][] }[]);
	const ALL = $derived(GROUPS.flatMap((g) => g.items));

	let q = $state('');
	let active = $state('start');
	let texts: Record<string, string> = {};
	let hits = $state<Set<string> | null>(null);

	onMount(() => {
		const secs = [...document.querySelectorAll<HTMLElement>('section[data-sec]')];
		texts = Object.fromEntries(secs.map((s) => [s.id, (s.textContent ?? '').toLowerCase()]));
		const io = new IntersectionObserver(
			(es) => { for (const e of es) if (e.isIntersecting) active = e.target.id; },
			{ rootMargin: '-15% 0px -70% 0px' }
		);
		secs.forEach((s) => io.observe(s));
		return () => io.disconnect();
	});

	// 검색: 띄어 쓴 낱말이 모두 들어 있는 항목만 남긴다
	$effect(() => {
		const words = q.trim().toLowerCase().split(/\s+/).filter(Boolean);
		hits = words.length ? new Set(Object.keys(texts).filter((id) => words.every((w) => texts[id].includes(w)))) : null;
	});
	const show = (id: string) => hits === null || hits.has(id);
</script>

<div class="manual">
	<header class="hero">
		<div class="hero-ic" aria-hidden="true">
			<svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 016.5 3H20v15H6.5A2.5 2.5 0 004 20.5v-15z" /><path d="M4 20.5A2.5 2.5 0 016.5 18H20v3H6.5A2.5 2.5 0 014 20.5z" /><path d="M9 7.5h7M9 11h5" /></svg>
		</div>
		<div class="hero-tx">
			<h1>프로그램 매뉴얼</h1>
			<p>{registry.churchInfo.name || '교회'} 교적 프로그램을 쓰는 방법을 화면 순서대로 정리했어요. 처음이라면 위에서부터 차근차근, 궁금한 게 있으면 아래 검색칸에 적어 보세요.</p>
		</div>
		<div class="hero-act">
			<label class="msearch"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
				<input type="search" bind:value={q} placeholder="매뉴얼에서 찾기 (예: 백업, 출석, 비밀번호)" aria-label="매뉴얼 검색" /></label>
			<button type="button" class="ghost sm print" onclick={() => window.print()}>인쇄</button>
		</div>
	</header>

	<div class="layout">
		<nav class="toc" aria-label="목차">
			{#each GROUPS as g (g.name)}
				<b>{g.name}</b>
				{#each g.items as [id, title] (id)}
					<a href="#{id}" class:on={active === id} class:dim={!show(id)}>{title}</a>
				{/each}
			{/each}
		</nav>

		<div class="body">
			{#if hits && hits.size === 0}<p class="none">'{q}'에 맞는 내용이 없어요. 다른 낱말로 찾아 보세요.</p>{/if}

			<section id="start" data-sec hidden={!show('start')}>
				<h2><i>1</i>처음 시작하기</h2>
				<ol class="steps">
					<li>받은 주소로 들어가 <b>아이디와 비밀번호</b>로 로그인해요. 처음 받은 <b>임시 비밀번호</b>로 들어가면 바로 새 비밀번호(8자 이상)를 정하게 돼요.</li>
					<li>계정이 없으면 로그인 화면의 <b>가입 신청</b>을 눌러 신청해요. 최고관리자가 <b>승인하고 등급을 정해 주면</b> 그때부터 쓸 수 있어요.</li>
					<li>비밀번호를 여러 번 틀리면 잠시 로그인이 막혀요. 몇 분 뒤에 다시 해 보세요. 비밀번호를 잊었다면 <b>최고관리자에게 임시 비밀번호</b>를 부탁하세요.</li>
					<li>쓰지 않고 <b>30분</b>이 지나면 개인정보 보호를 위해 자동으로 로그아웃돼요. 다시 로그인하면 돼요.</li>
					<li>어느 화면에서든 맨 위 <b>교회 이름(로고)</b>을 누르면 대시보드로 돌아와요. 휴대폰에서는 아래쪽 탭(홈·교인·등록·심방·메뉴)을 쓰고, 나머지는 <b>메뉴</b>에 있어요.</li>
				</ol>
				<div class="box tip"><b>저장 버튼이 따로 없어요.</b> 고친 칸은 자동으로 서버에 저장돼요. 여러 명이 함께 써도 서로 다른 칸을 고치면 덮어쓰지 않고, 다른 사람이 저장한 내용은 1분 안에 내 화면에 나타나요.</div>
				<h3>메뉴 한눈에 보기</h3>
				<div class="scroll"><table>
					<thead><tr><th>메뉴</th><th>여기서 하는 일</th></tr></thead>
					<tbody>
						<tr><td><b>교적</b></td><td>교인 목록, 교인 등록, 새가족 정착, 세대 목록</td></tr>
						<tr><td><b>{registry.t1}</b></td><td>{registry.t1} 편성표, {registry.t2}별 명단</td></tr>
						<tr><td><b>출석</b></td><td>주일 오전예배 출석 등록</td></tr>
						<tr><td><b>심방</b></td><td>심방 기록과 캘린더 (A 등급만)</td></tr>
						<tr><td><b>조직</b></td><td>교회 조직표, 당회, 시무집사회·시무권사회, 서리집사, 전도회</td></tr>
						<tr><td><b>통계</b></td><td>통계, 명단(생일·직분자·세례 대상자·교세), 문서 출력</td></tr>
						<tr><td><b>관리자</b></td><td>교회 설정, 사용자 설정, 프로그램 설정 (권한이 있는 사람만)</td></tr>
					</tbody>
				</table></div>
			</section>

			<section id="roles" data-sec hidden={!show('roles')}>
				<h2><i>2</i>누가 무엇을 할 수 있나요</h2>
				<p>로그인한 사람의 <b>등급</b>에 따라 보이는 화면과 할 수 있는 일이 달라요. 등급은 가입을 승인할 때 최고관리자가 정해요.</p>
				<div class="scroll"><table>
					<thead><tr><th>등급</th><th>누구</th><th>할 수 있는 일</th></tr></thead>
					<tbody>
						<tr><td><span class="role su">최고관리자</span></td><td>교적 전체 담당자</td><td>모든 기능. 가입 승인·등급 지정, 교회·프로그램 설정, 백업, 샘플 데이터, 일괄 수정, 외부 서비스·부서 관리 연결</td></tr>
						<tr><td><span class="role a">A</span></td><td>전임 교역자</td><td>교적 보기·수정, 내려받기·출력, <b>심방</b>, 문자 보내기, 인도자 특이사항 보기</td></tr>
						<tr><td><span class="role b">B</span></td><td>교적 관리</td><td>교적 보기·수정, 내려받기·출력 (심방은 볼 수 없어요)</td></tr>
						<tr><td><span class="role c">C</span></td><td>열람</td><td>꼭 필요한 정보만 보기</td></tr>
						<tr><td><span class="role d">D</span></td><td>{registry.t2} 인도자</td><td>교적 화면은 열 수 없고, <b>자기가 인도하는 {registry.t2}의 주일 출석 체크</b>(특이사항·기타 의견 포함)만 해요</td></tr>
					</tbody>
				</table></div>
				<ul>
					<li>A·B·C 등급은 <b>수정 · 내려받기/출력 · 사이트 관리</b> 권한을 최고관리자가 등급별로 켜고 끌 수 있어요. (<span class="path">관리자 › 사용자 설정 › 등급 설정</span>)</li>
					<li><b>심방은 A 등급 고정</b>이에요. 심방 기록, 인도자 특이사항, 이전 프로그램에서 옮겨 온 메모는 권한이 없는 사람에게는 서버가 아예 보내지 않아요.</li>
					<li>모든 권한은 화면이 아니라 <b>서버가 요청마다 다시 확인</b>해요. 누가 언제 무엇을 보고 고쳤는지는 <b>접속 기록</b>에 남아요.</li>
				</ul>
			</section>

			<section id="home" data-sec hidden={!show('home')}>
				<h2><i>3</i>대시보드</h2>
				<p>로그인하면 처음 보이는 화면이에요. 지금 교회 상황을 한눈에 보고, 필요한 곳으로 바로 갈 수 있어요.</p>
				<ul>
					<li><b>통합 검색</b>: 맨 위 검색칸에 이름·전화·{registry.t2}·직분·메모 무엇이든 적으면 한꺼번에 찾아요. (심방 내용은 A 등급만 검색돼요)</li>
					<li><b>현황 배너</b>: 재적 교인 수와 이번 주일 출석을 보여 주고, 교인 목록·교인 등록·통계로 바로 가는 버튼이 있어요. 연간 설정에 표어를 넣으면 배너에 나와요.</li>
					<li><b>캘린더</b>: 절기·성례·사역·심방·성도 일정·생일·공휴일이 색으로 나뉘어 보여요. 위의 색 이름을 누르면 그 종류만 볼 수 있고, <b>+ 일정 추가</b>로 일정을 넣을 수 있어요.</li>
					<li><b>처리할 일</b>(관리자만): 승인을 기다리는 가입 신청이 있으면 주황색으로 깜빡여요. 처리하면 사라져요.</li>
					<li><b>장기 결석 알림</b>: 예배출석·가끔출석 교인 중 정해진 주(기본 9주) 이상 나오지 않은 사람을 알려 줘요. 목록에서 바로 <b>장기결석자</b>로 바꿀 수도 있어요. 출석 기록이 하나도 없으면 알림을 만들지 않아요.</li>
					<li><b>새가족</b>: 최근 등록한 새가족과 정착 단계를 보여 줘요. <b>최근 심방</b>은 A 등급에게만 보여요.</li>
					<li><b>바로 가기</b>와 이 <b>프로그램 매뉴얼</b> 버튼이 맨 아래에 있어요.</li>
				</ul>
			</section>

			<section id="members" data-sec hidden={!show('members')}>
				<h2><i>4</i>교인 찾기와 목록</h2>
				<p><span class="path">교적 › 교인 목록</span>은 왼쪽에 목록, 오른쪽에 선택한 교인의 상세가 나오는 화면이에요.</p>
				<ul>
					<li><b>검색</b>은 세 가지 방식이 있어요. <b>기본</b>은 한 칸에 무엇이든, <b>복수</b>는 여러 낱말을 모두 만족하는 사람, <b>항목별</b>은 이름·전화·직분처럼 칸을 정해서 찾아요.</li>
					<li><b>조건 필터</b>로 교인구분, {registry.t1}, {registry.t2}, 직분, 나이, 재적 상태 등을 겹쳐서 걸러 낼 수 있어요.</li>
					<li><b>교인 목록 항목 설정</b>에서 목록에 보일 칸과 순서를 내 마음대로 정해요.</li>
					<li><b>내려받기</b>(권한 필요): 검색 결과를 필요한 칸만 골라 엑셀(.xlsx)로 받아요. 받은 기록은 접속 기록에 남아요.</li>
					<li><b>일괄 수정</b>(최고관리자): 검색창 옆 버튼을 누르면 체크칸이 나타나요. 체크한 교인들의 교인구분 등을 한꺼번에 바꿔요.</li>
					<li><b>문자 보내기</b>(A 등급, 문자 서비스 연결 시): 지금 검색된 사람들에게 문자를 보내요. (아래 '문자 · AI · 부서 관리 연결' 참고)</li>
				</ul>
				<div class="box tip">전화번호는 <b>하이픈 없이 숫자 3자리 이상만</b> 적어도 찾아져요. 같은 이름이 여러 명이면 이름 옆에 <b>A, B, C</b>가 붙어 구분돼요.</div>
			</section>

			<section id="card" data-sec hidden={!show('card')}>
				<h2><i>5</i>교인 카드</h2>
				<p>교인 이름을 누르면 열리는 상세 화면이에요. 위쪽 탭으로 내용을 나눠 보고, <b>수정</b> 버튼으로 고쳐요.</p>
				<div class="scroll"><table>
					<thead><tr><th>탭</th><th>들어 있는 것</th></tr></thead>
					<tbody>
						<tr><td><b>기본 정보</b></td><td>사진, 이름, 성별, 생년월일(음력 표시 가능), 연락처, 주소, 직업, 결혼 정보, 이전 교회, 인도자, 문자 수신 거부</td></tr>
						<tr><td><b>세대</b></td><td>같은 세대 구성원과 관계, 대가족(세대와 별개로 묶는 가족)</td></tr>
						<tr><td><b>신급·직분</b></td><td>학습·세례·유아세례·입교 이력, 목사·전도사·장로·시무집사·권사·서리집사 직분 이력(임직일·교회·구분)</td></tr>
						<tr><td><b>재적 이력</b></td><td>등록·전입·복적·보류·보관·전출·별세·제적 기록. <b>지금 재적 상태는 이 이력에서 자동으로 계산</b>돼요</td></tr>
						<tr><td><b>소속</b></td><td>{registry.t1}·{registry.t2}, 전도회, 교회봉사(조직표의 부서와 맡은 일)</td></tr>
						<tr><td><b>교육</b></td><td>새가족 교육 등 교육 과정과 수료 기록</td></tr>
						<tr><td><b>심방</b></td><td>심방 기록 (A 등급만 보여요)</td></tr>
						<tr><td><b>메모·동의</b></td><td>담당자 메모(교적 사용자 모두 열람), 개인정보·요람 공개 동의 기록</td></tr>
						<tr><td><b>변경 이력</b></td><td>누가 언제 무엇을 어떻게 바꿨는지 앞뒤 값</td></tr>
					</tbody>
				</table></div>
				<ul>
					<li><b>사진</b>은 수정 화면에서 올려요. 올린 사진은 자동으로 크기가 줄어요. 사진은 로그인한 사람에게만 보여요.</li>
					<li>값을 고치면 <b>변경 이력</b>에 자동으로 남아요. 잘못 고쳤다면 이력을 보고 이전 값으로 다시 고치면 돼요.</li>
					<li>이사·결혼·사망처럼 상태가 바뀌면 칸을 직접 고치지 말고 <b>재적 기록 추가</b>로 남기세요. 전출·별세·제적은 교인을 지우는 것이 아니라 <b>기록</b>이에요.</li>
				</ul>
				<div class="box caution">교회를 떠난 사람의 카드를 지우지 말고 <b>재적 이력(전출·별세·제적 등)</b>으로 남기세요. 그러면 통계와 기록이 유지되고, 정해진 기간이 지난 뒤에는 인적사항만 자동으로 정리돼요(아래 '백업과 개인정보 정리').</div>
			</section>

			<section id="new" data-sec hidden={!show('new')}>
				<h2><i>6</i>교인 등록과 새가족</h2>
				<ol class="steps">
					<li><span class="path">교적 › 교인 등록</span>을 열어요. 빨간 <b>*</b> 표시는 꼭 적어야 해요.</li>
					<li>이름과 전화를 적으면 <b>같은 이름·전화의 교인이 이미 있는지</b> 먼저 알려 줘요. 중복 등록을 막으니 꼭 확인하세요.</li>
					<li>생년월일에 맞는 <b>교인구분</b>과 {registry.t1}·{registry.t2}를 정해요. 같은 이름이 있으면 등록 순서대로 A, B, C가 붙어요.</li>
					<li><b>세대</b>: 새 세대를 만들거나, 세대주와의 관계를 골라 기존 세대에 넣어요.</li>
					<li><b>신급·직분</b>, 교회 등록일, 교회봉사를 적고, <b>개인정보 동의</b>(수집·이용, 요람 공개 등)를 기록해요.</li>
					<li><b>등록</b>을 누르면 교인 카드가 열려요.</li>
				</ol>
				<div class="box tip"><b>등록카드를 찍어서 등록</b>할 수도 있어요. 관리자가 AI 서비스를 연결해 두면 교인 등록 화면 위에 <b>카드 촬영</b> 칸이 나타나요. 사진을 올리면 읽은 내용이 칸에 채워지고, 글씨가 불분명한 칸은 따로 알려 줘요. 채워진 내용은 <b>꼭 눈으로 확인</b>한 뒤 등록하세요.</div>
				<h3>새가족 정착</h3>
				<p><span class="path">교적 › 새가족 정착</span>에서 새가족이 <b>등록 → 새가족 교육 → 수료 → {registry.t2}·부서 배정 → 정착 완료</b> 단계를 거치는 것을 챙겨요. 단계마다 한 번씩 눌러 기록하고, <b>정착 완료</b>를 누르면 교인구분이 '새가족'에서 장년(또는 나이에 맞는 부서)으로 바뀌어요.</p>
			</section>

			<section id="house" data-sec hidden={!show('house')}>
				<h2><i>7</i>세대</h2>
				<ul>
					<li><span class="path">교적 › 세대 목록</span>에서 세대별 구성원을 봐요. 세대 번호(H-001 …)는 자동으로 붙어요.</li>
					<li><b>+ 세대 만들기</b>로 새 세대를 만들고, 구성원의 관계(세대주·배우자·자녀 등)를 정해요.</li>
					<li>페이지 아래의 <b>아직 세대가 없는 교인</b> 목록에서 세대를 정해 주면 돼요.</li>
					<li>주소가 비어 있는 교인은 <b>세대의 주소</b>를 따라가요. 이사하면 세대 주소만 고치면 돼요.</li>
					<li>세대를 나누거나(분가) 합칠 때는 교인 카드의 <b>세대 탭</b>에서 소속을 바꿔요.</li>
				</ul>
			</section>

			<section id="groups" data-sec hidden={!show('groups')}>
				<h2><i>8</i>{registry.t1} · {registry.t2}</h2>
				<p>교회마다 부르는 이름이 달라서, 큰 모임은 <b>{registry.t1}</b>, 작은 모임은 <b>{registry.t2}</b>로 보여요. (설치할 때 정한 이름이에요)</p>
				<ul>
					<li><b>{registry.t1} 편성표</b>: 모든 {registry.t1}과 {registry.t2}, 인도자, 모임 장소를 한눈에 봐요. {registry.t2} 이름을 누르면 그 모임 페이지가 열려요.</li>
					<li><b>{registry.t2} 페이지</b>: 재적 인원, 인도자, 모임 장소, 최근 8주 모임 출석 그래프, 개인별 출석 점(●출석 ○결석), 출석률이 있어요.</li>
					<li><b>출석률</b>은 올해 그 {registry.t2}가 <b>출석을 기록한 주일만</b> 기준으로 계산해요. 기록하지 않은 주일은 결석으로 세지 않아요.</li>
					<li><b>인도자 특이사항</b>(A 등급): 인도자가 출석을 체크하며 적은 교인별 사유(출장, 입원 등)와 모임 전체에 대한 기타 의견을 모아 봐요. 여기서 직접 메모를 추가할 수도 있어요.</li>
					<li><b>{registry.t2}별 명단</b>: 모임별 명단과 개인 출석률을 <b>인쇄·엑셀·PDF</b>로 낼 수 있어요.</li>
					<li>{registry.t1}·{registry.t2} 편성(만들기, 이름 바꾸기, 인도자 지정, 교인 배정)은 <span class="path">관리자 › 교회 설정 › {registry.t2} 편성</span>에서 해요.</li>
				</ul>
			</section>

			<section id="attend" data-sec hidden={!show('attend')}>
				<h2><i>9</i>주일 출석</h2>
				<p><span class="path">출석</span> 메뉴에서 <b>주일 오전예배</b> 출석을 등록해요.</p>
				<ol class="steps">
					<li>맨 위에서 <b>주일 날짜</b>를 골라요. 기본은 오늘(주일이면) 또는 지난 주일이에요. 올해의 주일을 모두 고를 수 있어요.</li>
					<li>명단은 {registry.t1} → {registry.t2} 순서로 나열되고, 소그룹이 없는 사람은 맨 아래 <b>미지정</b>에 모여요. 이름 옆 <b>스위치</b>를 켜면 출석이에요.</li>
					<li>결석 사유 같은 메모는 옆 <b>특이사항</b> 칸에 적어요.</li>
					<li><b>전체 출석 인원</b>은 따로 적어요. 방문자처럼 명단에 없는 사람까지 포함한 숫자예요. (전체 − 명단 체크 = 그 외 인원)</li>
					<li><b>저장</b>을 누르면 끝이에요.</li>
				</ol>
				<ul>
					<li>명단에는 처음에 <b>예배출석 · 가끔출석</b>인 장년·새가족만 보여요. 불출석·장기결석자도 보려면 위의 체크를 켜세요.</li>
					<li>이름·직분·{registry.t2}로 <b>검색</b>하면 명단이 좁혀져요. 합계는 검색과 상관없이 전체 기준이에요.</li>
					<li><b>입력하지 않은 주일은 '기록 없음'</b>이에요. 결석으로 세지 않으니, 못 쓴 주일을 걱정하지 않아도 돼요.</li>
					<li>주일 오후 <b>{registry.t2} 모임 출석</b>은 인도자(D 등급)가 자기 화면에서 따로 체크해요.</li>
				</ul>
			</section>

			<section id="leader" data-sec hidden={!show('leader')}>
				<h2><i>10</i>{registry.t2} 인도자 화면 <span class="role d">D 등급</span></h2>
				<p>인도자는 교인 정보를 볼 필요 없이, 휴대폰으로 <b>자기 {registry.t2}의 출석만</b> 체크해요.</p>
				<h3>관리자가 해 줄 일 (처음 한 번)</h3>
				<ol class="steps">
					<li>인도자가 로그인 화면에서 <b>가입 신청</b>을 해요.</li>
					<li><span class="path">관리자 › 사용자 설정 › 가입 신청</span>에서 등급을 <b>D</b>로 승인해요.</li>
					<li><span class="path">사용자 목록</span>의 <b>연결된 교인</b> 칸에서 그 인도자를 교적의 교인과 연결해요.</li>
					<li><span class="path">관리자 › 교회 설정 › {registry.t2} 편성</span>에서 그 교인을 {registry.t2}의 <b>인도자</b>로 지정해요. (연결된 교인이 인도자로 지정된 모임만 보여요)</li>
				</ol>
				<h3>인도자가 하는 일</h3>
				<ul>
					<li>로그인하면 내 {registry.t2} 교인 목록이 나와요. <b>모임에 온 사람을 체크</b>하고, 필요하면 교인별 <b>특이사항</b>과 모임 전체의 <b>기타 의견</b>을 적고 <b>저장</b>해요.</li>
					<li>지난 12주 안의 주일은 고칠 수 있어요. 출석 체크 중에는 4분마다 자동으로 로그인이 유지돼요.</li>
					<li>혹시 저장할 때 로그인이 풀렸어도 <b>적은 내용은 그대로</b> 남아 있어요. 나타난 칸에 <b>비밀번호만 다시 입력</b>하면 이어서 저장돼요.</li>
				</ul>
			</section>

			<section id="visit" data-sec hidden={!show('visit')}>
				<h2><i>11</i>심방 <span class="role a">A 등급</span></h2>
				<p>심방은 <b>A 등급(전임 교역자)</b>만 보고 쓸 수 있어요.</p>
				<ul>
					<li><b>캘린더 보기 / 심방 목록 / {registry.t2} 특이사항</b> 세 가지 탭이 있어요.</li>
					<li><b>+ 심방 등록</b>: 날짜, 구분(대심방·새신자·일반·결혼·출산·병문안·장례 등, 코드표에서 바꿀 수 있어요), 방법(대면·전화), 담당 교역자, 동행자, 말씀, 찬송, 내용, 후속 조치를 적어요. 교인 카드의 <b>심방 탭</b>에서도 등록해요.</li>
					<li><b>날짜와 함께 적으면 일정이 자동으로 만들어져요.</b> 예) "22일 입원, 23일 수술"이라고 적으면 캘린더의 성도 일정에 들어가요. 입원·퇴원·수술·검사·출산·결혼식·장례·이사·입학·졸업 같은 낱말을 알아봐요. 등록 전에 미리보기가 보여요.</li>
					<li><b>녹음·문서로 채우기</b>(AI 서비스 연결 시): 심방 녹음(휴대폰에서 바로 녹음도 가능)이나 PDF·텍스트를 올리면 내용을 분석해 구분·말씀·찬송·후속 조치 칸을 채워 줘요. 받아쓴 원문도 볼 수 있어요. 채워진 내용은 꼭 확인하고 고쳐서 등록하세요.</li>
				</ul>
				<div class="box caution">AI 분석을 쓰면 올린 녹음·문서가 <b>외부 AI 서비스(구글)로 전송</b>돼요. 심방 내용에는 건강 같은 민감한 정보가 있을 수 있으니 교인에게 알리고 동의를 받은 뒤 쓰세요. 파일 자체는 이 서버에 남지 않아요.</div>
			</section>

			<section id="org" data-sec hidden={!show('org')}>
				<h2><i>12</i>교회 조직</h2>
				<ul>
					<li><span class="path">조직</span> 메뉴에는 <b>교회 조직표 · 당회 · 시무집사회 · 시무권사회 · 서리집사 · 남전도회 · 여전도회</b> 탭이 있어요.</li>
					<li><b>교회 조직표</b>는 위원회와 그 아래 부서, 맡은 사람(담당 교역자·부장·봉사자)을 한 장으로 보여 줘요. 인쇄하면 A4 한 장에 맞춰서 나와요.</li>
					<li>당회·집사회·권사회 명단은 교인 카드의 <b>직분 이력</b>에서 자동으로 만들어져요. 직분을 입력해 두면 별도로 적을 필요가 없어요.</li>
					<li>조직표를 고치는 곳은 <span class="path">관리자 › 교회 설정 › 교회 조직표</span>예요. 위원회·부서를 만들고, 담당자를 정해요. <b>같은 이름의 부서는 만들 수 없어요.</b></li>
				</ul>
			</section>

			<section id="stats" data-sec hidden={!show('stats')}>
				<h2><i>13</i>통계와 명단</h2>
				<h3>통계</h3>
				<ul>
					<li><span class="path">통계</span>에는 <b>주일 출석 · {registry.t2} 출석 · 임직 · 세례</b> 탭이 있어요.</li>
					<li><b>주일 출석</b>: 장년 주일 오전예배 출석 추이 그래프와 교육부서 이번 주일 출석률. <b>임직</b>: 연도별 임직과 임직자 현황. <b>세례</b>: 재적 교인의 신급 현황과 연도별 신급 기록.</li>
				</ul>
				<h3>명단</h3>
				<ul>
					<li><span class="path">통계 › 명단</span>에는 <b>생일 · 직분자 명단 · 학습·세례 대상자 · 교세 통계</b> 탭이 있어요. 생일은 월별로, 교세 통계는 성별·나이대·신급별로 보여 줘요.</li>
					<li>모든 통계는 <b>입력된 기록</b>으로만 계산해요. 출석을 입력하지 않은 주일은 '기록 없음'으로 두고 계산에서 빼요.</li>
				</ul>
			</section>

			<section id="docs" data-sec hidden={!show('docs')}>
				<h2><i>14</i>문서 출력</h2>
				<p><span class="path">통계 › 문서 출력</span>에서 문서를 고르면 화면에서 미리 보고, <b>인쇄</b> 또는 <b>PDF</b>로 내보내요. (내려받기·출력 권한이 있는 등급만)</p>
				<div class="scroll"><table>
					<thead><tr><th>문서</th><th>설명</th></tr></thead>
					<tbody>
						<tr><td><b>교적부</b></td><td>한 교인의 기본 정보·세대·신급·직분·이력을 한 장에 (번호 없음)</td></tr>
						<tr><td><b>교인증명서</b></td><td>용도(제출용·이명용 등)를 골라 발급. 발급 번호가 붙고 직인이 찍혀요</td></tr>
						<tr><td><b>세례증명서</b></td><td>세례(유아세례·입교) 기록이 있는 교인만 발급할 수 있어요</td></tr>
						<tr><td><b>기부금 영수증</b></td><td>기부 연도·기간·내용·금액을 적으면 합계와 <b>한글 금액</b>이 함께 나와요</td></tr>
						<tr><td><b>교인 요람</b></td><td>{registry.t1}별 교인 주소록. 기본은 <b>'요람 공개'에 동의한 사람만</b></td></tr>
						<tr><td><b>생일 명단 · 직분자 명단 · 교회 조직표</b></td><td>월별 생일, 현재 직분자, 조직표를 인쇄용으로</td></tr>
					</tbody>
				</table></div>
				<ul>
					<li><b>발급 번호</b>(제 2026-001 호 …)는 <b>서버가 정해서 겹치지 않게</b> 붙이고, <b>발급 대장</b>에 종류·교인·용도·발급자가 기록돼요. 인쇄나 PDF를 만드는 그 순간 번호가 정해지니, 같은 문서를 다시 뽑으면 새 번호가 붙어요.</li>
					<li>번호 모양(접두어·형식·자리수)과 직인 크기, 증명서 본문 문구는 <span class="path">관리자 › 교회 설정 › 기본 설정</span>에서 바꿔요.</li>
					<li><b>직인</b>은 내려받기·출력 권한이 있는 사람에게만 서버가 내려 줘요. 교회 로고와 직인은 기본 설정에서 올려요.</li>
				</ul>
			</section>

			<section id="settings" data-sec hidden={!show('settings')}>
				<h2><i>15</i>교회 설정</h2>
				<p><span class="path">관리자 › 교회 설정</span> (최고관리자 또는 사이트 관리 권한)</p>
				<div class="scroll"><table>
					<thead><tr><th>탭</th><th>무엇을 설정하나요</th></tr></thead>
					<tbody>
						<tr><td><b>기본 설정</b></td><td>교회 이름·교단·주소·연락처·창립일, 서명 직함·이름, 교회 <b>로고</b>·<b>직인</b>·<b>탭 아이콘</b>(브라우저 탭과 제목 옆에 보이는 작은 그림, 안 올리면 파란 바탕 십자가), 증명서 번호 모양·본문·용도</td></tr>
						<tr><td><b>연간 설정</b></td><td>해마다 새로 정해요. 표어·말씀, 나이 계산 방식(만 나이·한국 나이·연 나이), 출생 연도별 {registry.t1}·전도회, 교육부서 기준, 장기 결석 알림 기준(주), 새가족 기간, 연간 계획표 엑셀 등록</td></tr>
						<tr><td><b>교회 조직표</b></td><td>위원회·부서 만들기, 담당자 지정</td></tr>
						<tr><td><b>{registry.t2} 편성</b></td><td>{registry.t1}·{registry.t2} 만들기, 이름 바꾸기, 모임 장소, 인도자 지정, 교인 배정</td></tr>
						<tr><td><b>교인구분</b></td><td>교인 분류(3단계까지). 이름을 바꾸면 그 구분의 모든 교인에게 적용돼요. 교인이 있는 구분은 지울 수 없어요</td></tr>
						<tr><td><b>코드표</b></td><td>전도회 목록, 심방 구분처럼 교회마다 다른 목록 (직분·교역자 직책은 고정)</td></tr>
						<tr><td><b>확인 목록</b></td><td>가입은 승인했는데 아직 교적의 교인과 연결되지 않은 사용자. 같은 이름·전화의 교인이 있으면 '연결' 후보로 보여 줘요</td></tr>
					</tbody>
				</table></div>
				<div class="box tip"><b>새해 첫 일</b>: 연간 설정에서 새 연도를 만들고(지난해 것을 가져와 고쳐요), 출생 연도별 {registry.t1} 기준과 교육부서 기준을 확인하세요. 나이와 부서 구분이 모두 이 기준을 따라가요.</div>
			</section>

			<section id="users" data-sec hidden={!show('users')}>
				<h2><i>16</i>사용자 관리</h2>
				<p><span class="path">관리자 › 사용자 설정</span> (최고관리자)</p>
				<ul>
					<li><b>가입 신청</b>: 신청한 사람의 이름·연락처를 확인하고 <b>승인(등급 지정)</b> 또는 거절해요.</li>
					<li><b>사용자 목록</b>: 등급 바꾸기, 정지·다시 사용, <b>임시 비밀번호 만들기</b>(비밀번호를 잊었을 때), 교적의 교인과 연결(인도자 D 등급에 꼭 필요).</li>
					<li><b>등급 설정</b>: A·B·C 등급별로 <b>수정 · 내려받기/출력 · 사이트 관리</b> 권한을 켜고 꺼요.</li>
					<li><b>접속 기록</b>: 로그인·검색·열람·수정·내려받기·출력·권한 변경이 <b>누가 언제</b> 했는지 남아 있어요. 정해진 기간(기본 3년)이 지나면 정리돼요.</li>
				</ul>
				<div class="box tip">임시 비밀번호를 받은 사람은 <b>첫 로그인 때 바로 새 비밀번호를 정해야</b> 해요. 임시 비밀번호는 알려 주는 즉시 쓰게 하세요.</div>
			</section>

			<section id="backup" data-sec hidden={!show('backup')}>
				<h2><i>17</i>백업과 개인정보 정리</h2>
				<p><span class="path">관리자 › 프로그램 설정 › 백업</span> (최고관리자)</p>
				<h3>백업</h3>
				<ul>
					<li><b>지금 백업 내려받기</b>: 교인·출석·심방·사용자·접속 기록과 올린 사진이 파일 하나(<code>.cmbak</code>)로 내려와요. 비밀번호 정보도 들어 있으니 <b>안전한 곳에만</b> 보관하세요.</li>
					<li><b>자동 백업</b>: 주기(매일·매주·매월)와 시각, 보관 개수를 정해 두면 서버가 알아서 만들고 오래된 것부터 지워요. 정해진 시각 이후 <b>누군가 프로그램을 쓰고 있을 때</b> 만들어지니, 그 시각에 아무도 접속하지 않으면 다음 접속 때 만들어져요. 실패하면 알림 이메일을 보낼 수 있어요.</li>
					<li><b>서버에 보관된 백업</b>은 목록에서 내려받기·되살리기·삭제를 할 수 있어요. <b>서버에 지금 보관</b>을 누르면 바로 하나 만들어 둬요.</li>
				</ul>
				<h3>되살리기</h3>
				<ol class="steps">
					<li><b>백업 파일로 되살리기</b>로 파일을 고르거나, 서버 보관 목록에서 <b>되살리기</b>를 눌러요.</li>
					<li>파일을 확인해서 <b>만든 때·자료 양</b>을 보여 줘요. 맞는 파일인지 확인하세요.</li>
					<li>"이해했고 바꿉니다"에 체크하고 <b>내 비밀번호</b>를 입력한 뒤 <b>되살리기</b>를 눌러요.</li>
					<li>지금 자료가 모두 그 백업의 내용으로 바뀌고, <b>모두 로그아웃</b>돼요. 다시 로그인하면 돼요.</li>
				</ol>
				<div class="box caution">되살리면 <b>백업 이후에 입력한 내용은 사라져요.</b> 그래서 되살리기 직전의 자료를 서버에 <b>자동으로 한 번 보관</b>(목록의 '되살리기 직전')해 둬요. 잘못 되살렸다면 그 보관본으로 다시 되돌릴 수 있어요. 끊긴 파일이나 백업이 아닌 파일은 거절하고 자료를 건드리지 않아요.</div>
				<div class="box tip">서버에 보관한 백업은 호스팅 안에 있어서 호스팅 자체에 문제가 생기면 함께 잃을 수 있어요. <b>가끔은 내 컴퓨터로도 내려받아 두세요.</b> 업데이트 전에는 꼭 한 번 받아 두세요.</div>
				<h3>개인정보 정리</h3>
				<ul>
					<li>마지막 재적 상태가 <b>전출·별세·제적</b>이고 그날부터 정해진 기간(기본 <b>5년</b>)이 지난 교인의 <b>인적사항</b>(연락처·주소·생년월일·직업·메모·사진·수정 이력)을 지워요. <b>이름·교번·재적 이력·세례·직분 같은 교회 기록은 보관</b>해요.</li>
					<li>접속·수정 기록도 정해진 기간(기본 3년)이 지나면 지워요.</li>
					<li>자동(하루에 한 번 확인)으로 켜져 있고, 기간은 바꿀 수 있어요. <b>지금 정리</b> 버튼으로 바로 실행할 수도 있어요. 샘플 교인은 대상이 아니고, 정리 직전의 자료는 서버에 한 번 보관돼요.</li>
				</ul>
			</section>

			<section id="ext" data-sec hidden={!show('ext')}>
				<h2><i>18</i>문자 · AI · 부서 관리 연결</h2>
				<p>꼭 필요하지 않은 기능은 <b>연결해야만 켜져요</b>. 연결하지 않으면 그 기능의 버튼은 화면에 나타나지 않아요. 설정은 <span class="path">관리자 › 프로그램 설정 › 외부 서비스</span>(최고관리자)에서 해요. 사용 요금은 각 서비스에 직접 내요.</p>
				<h3>문자 보내기 (솔라피)</h3>
				<ol class="steps">
					<li>solapi.com에 가입하고 <b>발신번호를 등록</b>한 뒤 API Key와 API Secret을 만들어요.</li>
					<li>외부 서비스 탭에 Key·Secret·발신번호를 넣고 <b>저장하고 연결 확인</b>을 눌러요. 맞으면 잔액이 보여요.</li>
					<li>교인 목록에서 보낼 사람들을 검색한 뒤 <b>문자 보내기</b>를 눌러요. (A 등급)</li>
					<li>글을 쓰거나 <b>자주 쓰는 문구</b>를 골라요. <b>{'{이름}'}</b>, <b>{'{직분}'}</b>을 넣으면 받는 사람마다 이름·직분으로 바뀌고, [ ] 칸은 고쳐서 보내요. 90바이트(한글 약 45자)가 넘으면 자동으로 장문(LMS)이 돼요.</li>
					<li><b>수신 거부한 사람, 번호가 없는 사람, 같은 번호</b>는 자동으로 빠지고, 몇 명에게 가는지·예상 비용을 확인한 뒤 보내요. 보낸 뒤에는 취소할 수 없어요.</li>
				</ol>
				<p class="note">하루에 보낼 수 있는 문자는 최대 3,000건이에요. 보낸 기록은 접속 기록에 남아요. 처음에는 <b>한두 명에게 먼저</b> 보내 확인해 보세요.</p>
				<h3>AI 분석 (구글 Gemini)</h3>
				<ul>
					<li>aistudio.google.com에서 <b>API 키</b>를 만들어 넣으면 켜져요. <b>① 등록카드 읽기</b>(교인 등록 화면), <b>② 심방 녹음·문서 분석</b>(심방 화면)이 나타나요.</li>
					<li>올린 사진·녹음은 분석을 위해 <b>구글 서버로 전송</b>돼요. 서버에는 남기지 않아요. 결과는 틀릴 수 있으니 <b>꼭 확인하고 고쳐서</b> 쓰세요.</li>
					<li>키는 서버에만 저장되고, 화면에는 끝 4자리만 보이며, 백업 파일에도 들어가지 않아요. '연결 끊기'로 언제든 지울 수 있어요.</li>
				</ul>
				<h3>부서 관리(dep-manage) 연결</h3>
				<p>교회 부서(유치부·유초등부·중고등부·청년부)를 관리하는 짝 프로그램을 <b>같은 데이터베이스</b>에 설치했다면, 학생·교사를 교인과 연결할 수 있어요. 연결 메뉴는 부서 관리가 있을 때만 나타나요. (<span class="path">관리자 › 프로그램 설정 › 부서 관리 연결</span>)</p>
				<ul>
					<li><b>처음 연결</b>: <b>연결할 후보 불러오기</b>를 누르면 이름과 생년월일이 같은 학생은 '확실'로 한꺼번에 연결하고, 이름만 같으면 직접 골라요. 한쪽에만 있는 사람은 상대 프로그램에 만들 수 있어요.</li>
					<li><b>인적사항</b>(이름·성별·생년월일·연락처·주소·세례)은 <b>교적이 주인</b>이에요. 교적에서 고치면 연결된 학생·교사에게 자동으로 맞춰져요.</li>
					<li><b>부서·학년·반·출석</b>은 <b>부서 관리가 주인</b>이에요. 부서 관리의 출석은 교적 카드와 교육부서 통계에서 읽어서 보여 줘요. 학생이 승급해 부서가 바뀌면 <b>부서·학년(승급) 맞추기</b>로 교적의 교육부서를 맞춰요.</li>
					<li><b>사진 맞추기</b>: 한쪽에만 사진이 있으면 다른 쪽으로 복사해요. 양쪽에 있으면 그대로 둬요.</li>
				</ul>
			</section>

			<section id="sample" data-sec hidden={!show('sample')}>
				<h2><i>19</i>샘플 데이터와 업데이트</h2>
				<h3>샘플 데이터</h3>
				<ul>
					<li>처음 둘러볼 때는 대시보드의 <b>샘플 데이터 불러오기</b>(최고관리자)로 만들어 낸 가상 교인을 넣어 화면을 미리 볼 수 있어요. (교인이 없을 때만 넣을 수 있어요)</li>
					<li>실제로 쓰기 전에는 <b>샘플 데이터 삭제</b>를 눌러 지우고 시작하세요. 직접 입력한 자료는 그대로 남아요.</li>
				</ul>
				<h3>새 버전으로 업데이트</h3>
				<ol class="steps">
					<li>먼저 <b>백업</b>을 내려받아요.</li>
					<li>새 압축 파일을 풀어, 안의 내용을 호스팅의 같은 위치에 <b>덮어써서</b> 올려요. (설정 파일이 있는 <code>data</code> 폴더는 그대로 남아요)</li>
					<li>브라우저에서 <code>…/install/</code> 주소를 <b>한 번 열어요.</b> "화면 파일을 마무리했어요"가 나오면 성공이에요. (데이터베이스 변경도 이때 적용돼요)</li>
					<li>Ctrl+F5로 강력 새로고침을 한 번 해요.</li>
				</ol>
				<div class="box caution">3번을 빼먹으면 새 화면이 제대로 보이지 않아요.</div>
			</section>

			<section id="faq" data-sec hidden={!show('faq')}>
				<h2><i>20</i>자주 묻는 질문</h2>
				<dl>
					<dt>비밀번호를 잊었어요.</dt>
					<dd>최고관리자에게 <b>임시 비밀번호</b>를 부탁하세요. 받은 임시 비밀번호로 로그인하면 바로 새 비밀번호를 정하게 돼요. 최고관리자 본인은 이슈 게시판(GitHub)에 문의해 주세요.</dd>
					<dt>로그인이 자꾸 풀려요.</dt>
					<dd>쓰지 않고 30분이 지나면 자동으로 로그아웃돼요. 인도자 화면은 출석을 체크하는 동안 자동으로 유지돼요.</dd>
					<dt>메뉴가 안 보이거나 "권한이 없다"고 나와요.</dt>
					<dd>등급에 따라 보이는 메뉴가 달라요. 심방은 A 등급만, 설정은 최고관리자(또는 사이트 관리 권한)만 볼 수 있어요. 필요하면 최고관리자에게 권한을 요청하세요.</dd>
					<dt>정보를 잘못 고치거나 지웠어요.</dt>
					<dd>교인 카드의 <b>변경 이력</b>에서 이전 값을 확인해 다시 고치세요. 크게 잘못됐다면 백업이 있을 때 <b>백업 파일로 되살리기</b>로 되돌릴 수 있어요. (그 뒤에 입력한 내용은 사라져요)</dd>
					<dt>같은 사람이 두 번 등록됐어요.</dt>
					<dd>카드를 합치는 기능은 없어요. 정보가 더 많은 쪽을 남기고, 다른 쪽에는 재적 이력을 <b>제적(사유: 중복 등록)</b>으로 남겨 목록에서 빠지게 하세요. 등록할 때 '같은 이름·전화가 이미 있다'는 안내를 꼭 확인하면 막을 수 있어요.</dd>
					<dt>출석률이 생각과 달라요.</dt>
					<dd>출석률은 {registry.t2}가 <b>출석을 기록한 주일만</b> 기준으로 계산해요. 기록하지 않은 주일은 빠져요. 그리고 그 교인이 등록한 날 이후의 주일만 세어요.</dd>
					<dt>저장할 때 "서버에서 문제가 생겼습니다 (404)"가 나와요.</dt>
					<dd>호스팅이 한 번에 받는 크기를 제한해서 생기는 일이에요. 큰 사진을 올렸다면 줄여서 다시 올려 보세요. 계속되면 문의해 주세요.</dd>
					<dt>자동 백업이 안 만들어져요.</dt>
					<dd>설정 화면의 '마지막 자동 백업' 시각을 확인하세요. 그 시각 이후 누군가 프로그램을 쓰고 있어야 만들어져요. 실패했다면 안내가 보이고, 호스팅에 압축 기능(zlib)이 없을 때도 실패할 수 있어요.</dd>
					<dt>문자 보내기·카드 촬영 버튼이 안 보여요.</dt>
					<dd>외부 서비스를 연결해야 나타나요. 최고관리자가 <b>프로그램 설정 › 외부 서비스</b>에서 연결하세요. 문자는 A 등급에게만 보여요.</dd>
					<dt>화면 글자가 작아요.</dt>
					<dd>브라우저의 글자 크기·확대 설정을 키우면 화면도 함께 커져요. (Ctrl과 + 키)</dd>
					<dt>개인정보는 안전한가요?</dt>
					<dd>교인 정보는 교회가 직접 설치한 호스팅 안에만 있고, 로그인하지 않으면 볼 수 없어요. 권한은 서버가 매번 확인하고, 열람·수정·내려받기는 접속 기록에 남아요. 비밀번호는 복원할 수 없는 방식으로 저장돼요.</dd>
				</dl>
				<div class="box">더 궁금한 점이나 제안은 <a href="https://github.com/byraju-hub/ch-manage/issues" target="_blank" rel="noopener">GitHub 이슈</a>에 남기거나 byraju@naver.com 으로 문의해 주세요.</div>
			</section>

			<p class="end"><a href="#start">↑ 맨 위로</a> · <a href={hrefTo('/')}>대시보드로 돌아가기</a></p>
		</div>
	</div>
</div>

<style>
	.manual { max-width: 1180px; margin: 0 auto; }
	.hero { display: grid; grid-template-columns: auto 1fr; gap: 1rem 1.2rem; align-items: center; padding: 1.4rem 1.6rem; margin-bottom: 1.1rem; border-radius: 20px; color: #fff;
		background: radial-gradient(120% 160% at 100% 0%, rgba(20, 180, 174, 0.55), transparent 55%), linear-gradient(120deg, var(--navy), #4558ae); box-shadow: var(--shadow-md); }
	.hero-ic { width: 64px; height: 64px; border-radius: 18px; display: grid; place-items: center; background: rgba(255, 255, 255, 0.16); border: 1px solid rgba(255, 255, 255, 0.3); }
	.hero-ic svg { width: 34px; height: 34px; fill: none; stroke: #fff; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
	.hero h1 { margin: 0 0 0.25rem; color: #fff; font-size: 1.7rem; }
	.hero p { margin: 0; color: rgba(255, 255, 255, 0.88); line-height: 1.6; font-size: 0.95rem; }
	.hero-act { grid-column: 1 / -1; display: flex; gap: 0.6rem; align-items: center; }
	.msearch { flex: 1; position: relative; display: block; }
	.msearch svg { position: absolute; left: 14px; top: 50%; width: 18px; height: 18px; transform: translateY(-50%); fill: none; stroke: var(--muted); stroke-width: 2; stroke-linecap: round; }
	.msearch input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.6rem; border-radius: 999px; border: 0; font-size: 0.97rem; background: #fff; color: var(--text); box-shadow: 0 2px 10px rgba(0, 0, 0, 0.12); }
	.print { background: rgba(255, 255, 255, 0.16); color: #fff; border-color: rgba(255, 255, 255, 0.4); }

	.layout { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 1.2rem; align-items: start; }
	.toc { position: sticky; top: 76px; max-height: calc(100vh - 96px); overflow: auto; display: flex; flex-direction: column; gap: 0.1rem; padding: 0.9rem 0.8rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-sm); font-size: 0.9rem; }
	.toc b { font-size: 0.72rem; letter-spacing: 0.04em; color: var(--muted); margin: 0.7rem 0.5rem 0.2rem; }
	.toc b:first-child { margin-top: 0; }
	.toc a { color: var(--text); padding: 0.35rem 0.6rem; border-radius: 8px; border-left: 3px solid transparent; transition: background 0.15s, color 0.15s; }
	.toc a:hover { background: var(--primary-50); text-decoration: none; }
	.toc a.on { background: var(--primary-50); color: var(--primary-600); font-weight: 700; border-left-color: var(--primary); }
	.toc a.dim { opacity: 0.35; }

	.body { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
	section[data-sec] { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 1.3rem 1.6rem 1.5rem; box-shadow: var(--shadow-sm); scroll-margin-top: 76px; line-height: 1.75; }
	section[hidden] { display: none; }
	section h2 { display: flex; align-items: center; gap: 0.7rem; margin: 0 0 0.7rem; font-size: 1.3rem; color: var(--primary-600); }
	section h2 i { flex: none; width: 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; font-style: normal; font-size: 0.95rem; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--primary-600), var(--teal-600)); }
	section h3 { margin: 1.2rem 0 0.4rem; font-size: 1.02rem; color: var(--text); padding-left: 0.6rem; border-left: 4px solid var(--teal); }
	section p { margin: 0.4rem 0; }
	section ul, section ol { margin: 0.4rem 0; padding-left: 1.3rem; display: flex; flex-direction: column; gap: 0.4rem; }
	ol.steps { list-style: none; padding-left: 0; counter-reset: st; }
	ol.steps li { position: relative; padding-left: 2.4rem; counter-increment: st; }
	ol.steps li::before { content: counter(st); position: absolute; left: 0; top: 0.1rem; width: 1.65rem; height: 1.65rem; border-radius: 50%; display: grid; place-items: center; font-size: 0.82rem; font-weight: 800; background: var(--primary-100); color: var(--primary-600); }
	b { color: var(--text); }
	code { background: var(--primary-50); padding: 0.05rem 0.4rem; border-radius: 6px; font-size: 0.88em; }
	.path { display: inline-block; padding: 0.05rem 0.55rem; border-radius: 999px; background: var(--primary-50); color: var(--primary-600); font-size: 0.86em; font-weight: 600; border: 1px solid var(--primary-100); }
	.role { display: inline-block; padding: 0.05rem 0.6rem; border-radius: 999px; font-size: 0.8rem; font-weight: 800; vertical-align: middle; }
	.role.su { background: #2b3e95; color: #fff; }
	.role.a { background: var(--orange-100); color: #a83c10; }
	.role.b { background: var(--teal-100); color: var(--teal-600); }
	.role.c { background: #e8eaf3; color: #4a5070; }
	.role.d { background: #fff1b8; color: #7a5a00; }
	.box { margin: 0.8rem 0 0.2rem; padding: 0.8rem 1rem; border-radius: var(--radius-sm); background: var(--primary-50); border: 1px solid var(--primary-100); }
	.box.tip { background: var(--teal-50); border-color: var(--teal-100); }
	.box.tip::before { content: '💡 '; }
	.box.caution { background: var(--orange-50); border-color: var(--orange-100); }
	.box.caution::before { content: '⚠️ '; }
	.note { color: var(--muted); font-size: 0.9rem; }
	.scroll { overflow-x: auto; margin: 0.5rem 0; }
	table { width: 100%; border-collapse: collapse; font-size: 0.92rem; min-width: 520px; }
	th, td { padding: 0.55rem 0.7rem; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
	th { background: var(--primary-50); color: var(--primary-600); font-size: 0.8rem; white-space: nowrap; }
	td:first-child { white-space: nowrap; }
	dl { margin: 0.3rem 0; }
	dt { font-weight: 800; margin-top: 0.9rem; color: var(--primary-600); }
	dt::before { content: 'Q. '; color: var(--orange); }
	dd { margin: 0.2rem 0 0; padding-left: 1.3rem; color: var(--text); }
	dd::before { content: 'A. '; margin-left: -1.3rem; color: var(--teal-600); font-weight: 800; }
	.none { text-align: center; padding: 2rem 0; color: var(--muted); }
	.end { text-align: center; color: var(--muted); margin: 0.4rem 0 1rem; }

	@media (max-width: 900px) {
		.layout { grid-template-columns: minmax(0, 1fr); }
		.toc { position: static; max-height: none; flex-direction: row; flex-wrap: wrap; gap: 0.3rem; }
		.toc b { width: 100%; margin: 0.4rem 0 0; }
		.toc a { border-left: 0; background: var(--primary-50); }
		.hero { padding: 1.1rem; }
		.hero h1 { font-size: 1.4rem; }
		section[data-sec] { padding: 1.1rem 1rem 1.2rem; }
	}
	@media print {
		.hero-act, .toc { display: none; }
		.layout { display: block; }
		section[data-sec] { break-inside: avoid-page; box-shadow: none; }
	}
</style>
