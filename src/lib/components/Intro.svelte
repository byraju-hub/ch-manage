<script lang="ts">
	// 소개 화면: 로그아웃 상태에서 '/' 로 들어오면 보임. '데모 사이트 구경하기'는 가상 데이터의 최고관리자(정담임 목사)로 바로 로그인.
	// [시험판 전용] 실제 서비스에서는 소개 화면을 홈페이지에 두고, 이 앱은 로그인 화면부터 시작해도 됩니다.
	import { registry } from '#lib/registry/store.svelte.ts';

	let { ondemo }: { ondemo: () => void } = $props();

	// 최신 버전: GitHub Releases 의 가장 새 릴리즈 (새 릴리즈를 만들면 이 주소는 저절로 새 것을 가리킴)
	const REPO = 'https://github.com/byraju-hub/church-manage';

	const features = $derived([
		{ t: '교인 목록 · 교적 카드', d: '이름·전화·소그룹·직분 무엇이든 한 번에 검색합니다. 여러 명을 한꺼번에 찾고, 엑셀로 내려받고, 일괄 수정도 합니다.' },
		{ t: '교인 등록', d: '등록카드 사진을 올리면 칸이 자동으로 채워집니다. 주소 검색, 동명이인 자동 구분까지. (시험판에서는 예시로 보여 줍니다)' },
		{ t: `${registry.t1} · ${registry.t2}`, d: `편성표, 인도자와 모임 장소, 올해 출석률. 명단은 인쇄·엑셀·PDF로 뽑습니다. '${registry.t1}'·'${registry.t2}' 이름은 교구·구역 등으로 바꿔 쓸 수 있습니다.` },
		{ t: '주일 출석', d: '주일 오전예배 출석을 체크하고, 오래 나오지 않은 교인은 대시보드에서 알려 줍니다.' },
		{ t: '심방 (전임 교역자)', d: "심방 기록과 캘린더. 녹음이나 문서를 올리면 내용을 정리해 주고, '입원·수술' 같은 일정은 캘린더에 자동으로 들어갑니다." },
		{ t: '캘린더', d: '교회 절기·성례·사역·심방·성도·생일·공휴일을 색으로 구분합니다. 연간 계획 엑셀을 올려 한꺼번에 등록합니다.' },
		{ t: '교회 조직', d: '교회 조직표, 당회·시무집사회·시무권사회·서리집사·전도회 명단을 인쇄·엑셀·PDF로 뽑습니다.' },
		{ t: '통계 · 문서 출력', d: '주일 출석 그래프, 임직·세례 통계. 교적부·증명서·기부금 영수증·교인 요람을 인쇄하거나 PDF로 만듭니다.' },
		{ t: '회원 · 권한 · 설정', d: '프로그램에 가입하면 관리자가 승인하고 등급(A·B·C)을 정합니다. 등급에 따라 볼 수 있는 화면이 달라집니다. 교회 정보·로고·직인, 나이 계산 방식, 백업도 관리자가 직접 바꿉니다.' }
	]);

	const shots = [
		{ k: '대시보드', src: '/intro/dashboard.jpg', cap: '처리할 일 알림, 현황 배너, 캘린더, 숫자 카드' },
		{ k: '교인 목록', src: '/intro/members.jpg', cap: '무엇이든 검색, 여러 조건 필터, 오른쪽에 상세' },
		{ k: '소그룹', src: '/intro/group.jpg', cap: '인도자·모임 장소, 출석 추이, 출석률 정렬' },
		{ k: '통계', src: '/intro/stats.jpg', cap: '전체·장년·교육부서 주일 출석 꺾은선 그래프' },
		{ k: '조직', src: '/intro/orgs.jpg', cap: '당회 서기와 위원장 순서, 인쇄·엑셀·PDF' }
	];
	let cur = $state(0);
</script>

<div class="intro">
	<section class="top">
		<img src="/logo.png" alt="" width="72" height="72" class="logo" />
		<span class="tag part">시험판</span>
		<h1 class="brand-font">{registry.churchInfo.name} 교적 관리</h1>
		<p class="sub">교인 명단 · 출석 · 심방 · 조직 · 문서 출력을 한곳에서.</p>
		<div class="cta">
			<button type="button" class="big" onclick={ondemo}>데모 사이트 구경하기</button>
			<a class="btn ghost big" href="{REPO}/releases/latest" target="_blank" rel="noopener">최신 버전 내려받기</a>
			<a class="btn ghost big" href="https://github.com/byraju-hub/ch-manage#설치해서-쓰기" target="_blank" rel="noopener">설치 방법</a>
			<a class="btn ghost big" href={REPO} target="_blank" rel="noopener">GitHub</a>
		</div>
		<p class="hint">가입이나 비밀번호 없이, 버튼 하나로 바로 들어갑니다.</p>
	</section>

	<section>
		<h2>이런 프로그램이에요</h2>
		<p class="lead">
			교회 교역자와 담당자를 위한 교적 관리 웹 프로그램입니다.<br />
			데모 사이트를 구경하고 구현하길 원하는 교회는 문의(<a href="mailto:byraju@naver.com">byraju@naver.com</a>)해 주세요.
		</p>
	</section>

	<section>
		<h2>주요 기능</h2>
		<div class="grid">
			{#each features as f}
				<div class="feat"><h3>{f.t}</h3><p>{f.d}</p></div>
			{/each}
		</div>
	</section>

	<section>
		<h2>화면 미리보기</h2>
		<div class="shot-tabs" role="tablist">
			{#each shots as s, i}<button type="button" role="tab" aria-selected={cur === i} class="ghost sm" class:on={cur === i} onclick={() => (cur = i)}>{s.k}</button>{/each}
		</div>
		<figure class="shot">
			<img src={shots[cur].src} alt="{shots[cur].k} 화면" loading="lazy" />
			<figcaption>{shots[cur].cap}</figcaption>
		</figure>
	</section>

	<section class="demo">
		<h2>직접 써 보기</h2>
		<p class="lead">버튼을 누르면 가상 데이터가 들어 있는 시험판에 <b>정담임 목사(최고관리자)</b>로 바로 로그인합니다.</p>
		<ul>
			<li>모든 화면을 볼 수 있고, 등록·수정도 직접 해 볼 수 있습니다.</li>
			<li>입력한 내용은 <b>이 브라우저에만</b> 저장되어 다른 사람에게 보이지 않습니다.</li>
			<li>로그인한 뒤 위쪽 이름을 누르면 다른 등급으로 바꿔 보거나 데이터를 처음 상태로 되돌릴 수 있습니다.</li>
			<li>문자 발송·AI 분석은 연결하지 않아 예시 화면으로 동작합니다.</li>
		</ul>
		<button type="button" class="big" onclick={ondemo}>데모 사이트 구경하기</button>
	</section>

	<section class="note">
		<h2>알아 두세요</h2>
		<ul>
			<li>모든 이름·전화번호·주소는 만들어 낸 값입니다. 실제 교인 정보는 들어 있지 않습니다.</li>
			<li>카카오 로그인은 실제로 연결하지 않았습니다. 데모는 가상 계정으로 바로 들어갑니다.</li>
			<li>소스 코드는 <a href={REPO} target="_blank" rel="noopener">GitHub</a>에 공개되어 있습니다.</li>
		</ul>
	</section>

	<p class="credit">제작: <a href="https://github.com/byraju-hub" target="_blank" rel="noopener">byraju</a> · 문의: <a href="mailto:byraju@naver.com">byraju@naver.com</a><br />
		<a href="{REPO}/blob/main/LICENSE" target="_blank" rel="noopener">GNU AGPL-3.0 라이선스</a> · 누구나 자유롭게 설치해서 쓰고 고칠 수 있어요</p>
</div>

<style>
	.intro { max-width: 980px; margin: 0 auto; display: grid; gap: 2.4rem; }
	.intro section { min-width: 0; }
	h2 { margin: 0 0 0.8rem; font-size: 1.3rem; }
	.lead { margin: 0; font-size: 1.02rem; line-height: 1.75; color: var(--text); }

	.top { text-align: center; padding: 2.4rem 1rem 2.2rem; border-radius: 24px; background: linear-gradient(135deg, #34489e 0%, #4f6cb8 55%, #5bb7b2 100%); color: #fff; box-shadow: 0 14px 40px rgba(43, 62, 149, 0.22); }
	.top .logo { display: block; margin: 0 auto 0.7rem; border-radius: 20px; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22); }
	.top .tag { background: rgba(255, 255, 255, 0.2); color: #fff; }
	.top h1 { margin: 0.5rem 0 0.4rem; color: #fff; font-size: 2.3rem; }
	.top .sub { margin: 0; opacity: 0.94; font-size: 1.05rem; }
	.cta { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.7rem; margin-top: 1.6rem; }
	.big { padding: 0.8rem 1.6rem; font-size: 1.02rem; }
	.cta .big:not(.ghost) { background: #fff; color: #2b3e95; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18); }
	.cta .ghost.big { background: transparent; color: #fff; border-color: rgba(255, 255, 255, 0.65); }
	.cta .ghost.big:hover { background: rgba(255, 255, 255, 0.14); }
	.top p.hint { margin: 0.9rem 0 0; font-size: 0.85rem; color: rgba(255, 255, 255, 0.92); }

	.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 0.9rem; }
	.feat { padding: 1rem 1.1rem; border: 1px solid var(--line); border-top: 4px solid var(--teal-600); border-radius: 14px; background: #fff; }
	.feat:nth-child(3n + 2) { border-top-color: var(--primary); }
	.feat:nth-child(3n) { border-top-color: #f0642a; }
	.feat h3 { margin: 0 0 0.35rem; font-size: 1rem; }
	.feat p { margin: 0; font-size: 0.9rem; line-height: 1.65; color: var(--muted); }

	.shot-tabs { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.8rem; }
	.shot-tabs button.on { background: var(--primary); border-color: var(--primary); color: #fff; }
	.shot { margin: 0; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; background: #fff; box-shadow: var(--shadow-md); }
	.shot img { display: block; width: 100%; height: auto; }
	.shot figcaption { padding: 0.6rem 1rem; font-size: 0.86rem; color: var(--muted); border-top: 1px solid var(--line); }

	.credit { margin: 0; text-align: center; font-size: 0.9rem; color: var(--muted); }
	.credit a { font-weight: 600; }
	.demo, .note { padding: 1.3rem 1.4rem; border-radius: 18px; }
	.demo { background: var(--primary-50); }
	.note { border: 1px dashed var(--line-strong); background: #fff; }
	.demo ul, .note ul { margin: 0.8rem 0 1.1rem; padding-left: 1.2rem; display: grid; gap: 0.35rem; line-height: 1.65; }
	.note ul { margin-bottom: 0; font-size: 0.92rem; color: var(--muted); }

	@media (max-width: 640px) {
		.top h1 { font-size: 1.7rem; }
		.intro { gap: 1.8rem; }
	}
</style>
