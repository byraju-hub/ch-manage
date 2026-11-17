<script lang="ts">
	// 개인정보 처리방침: 로그인하지 않아도 누구나 볼 수 있는 화면 (상단 메뉴·로그인 화면의 링크가 여기로 옴)
	// 교회가 직접 설치해서 쓰는 프로그램이라, '교회'는 이 프로그램을 설치한 교회를 말한다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/registry/auth.svelte.ts';
	import { hrefTo } from '#lib/registry/paths.ts';
	import { POLICY_VERSION } from '#lib/registry/types.ts';

	// 시행일은 types.ts 의 POLICY_VERSION 하나로 관리한다. (동의 기록에 남는 '처리방침 판'과 같은 값)
	const [vy, vm, vd] = POLICY_VERSION.split('-').map(Number);
	const VERSION = `${vy}년 ${vm}월 ${vd}일`;
	onMount(() => { void auth.init(); });
	const church = $derived(auth.churchName || '이 교회');
	const pv = $derived(auth.privacy);
</script>

<svelte:head><title>개인정보 처리방침 · {auth.churchName || '교적 관리'}</title><meta name="robots" content="noindex" /></svelte:head>

<div class="pv">
	<header class="pv-top">
		<a class="pv-brand" href={hrefTo('/')}>← {auth.churchName ? auth.churchName + ' 교적 관리' : '교적 관리'}로 돌아가기</a>
	</header>

	<article class="pv-doc">
		<h1>개인정보 처리방침</h1>
		<p class="pv-ver">공고·시행일자 {VERSION}</p>

		<p class="pv-lead">
			<b>{church}</b>(이하 "교회")는 교인과 교회 일을 맡은 분들의 개인정보를 소중하게 다루며, 「개인정보 보호법」 등 관련 법령을 지킵니다.
			이 방침은 교회가 교적 관리 프로그램(ch-manage)을 쓰면서 어떤 개인정보를 어떻게 다루는지 알려 드리기 위한 것입니다.
		</p>

		<nav class="pv-toc" aria-label="차례">
			<a href="#s1">1. 처리하는 항목과 모으는 방법</a>
			<a href="#s2">2. 처리 목적</a>
			<a href="#s3">3. 신앙에 관한 정보</a>
			<a href="#s4">4. 볼 수 있는 사람</a>
			<a href="#s5">5. 제3자 제공</a>
			<a href="#s6">6. 맡기는 곳과 국외 이전</a>
			<a href="#s7">7. 보관 기간</a>
			<a href="#s8">8. 지우는 절차와 방법</a>
			<a href="#s9">9. 내 정보에 대한 권리</a>
			<a href="#s10">10. 쿠키</a>
			<a href="#s11">11. 안전하게 지키는 방법</a>
			<a href="#s12">12. 책임자와 문의·신고</a>
			<a href="#s13">13. 프로그램 제작자의 책임</a>
			<a href="#s14">14. 기타와 방침의 변경</a>
		</nav>

		<section id="s1">
			<h2>1. 처리하는 항목과 모으는 방법</h2>
			<h3>가. 교인 정보 (교적)</h3>
			<ul>
				<li><b>기본 정보</b>: 이름, 성별, 생년월일(양력·음력), 휴대폰 번호, 이메일, 주소, 사진, 교번(등록 번호)</li>
				<li><b>생활 정보</b>: 직업, 직장, 혼인 여부와 결혼일, 차량 번호, 이전 교회, 인도자(전도·인도한 사람), 가족·세대 관계</li>
				<li><b>신앙·교회 기록</b>: 등록·전입·전출·별세·제적 같은 재적 변동, 학습·세례·입교, 직분과 임기, 속한 소그룹과 조직, 교육 이수, 새가족 정착 단계</li>
				<li><b>활동 기록</b>: 주일 출석과 소그룹 출석, 심방 기록, 담당자 메모, 증명서 발급 내역</li>
			</ul>
			<h3>나. 프로그램을 쓰는 분(교역자·교적 담당자·소그룹 인도자 등)의 정보</h3>
			<ul>
				<li>아이디, 비밀번호(되돌릴 수 없게 암호화해서 보관), 이름, 이메일, 연락처, 등급, 가입 신청·승인 시각</li>
			</ul>
			<h3>다. 쓰는 중에 자동으로 남는 정보</h3>
			<ul>
				<li><b>접속·수정 기록</b>: 누가, 언제, 어느 접속 주소(IP)에서, 무엇을 보거나 고치거나 내려받았는지 (비밀번호는 기록하지 않습니다)</li>
				<li><b>로그인 시도 기록</b>: 아이디와 접속 주소(IP). 로그인을 반복해서 틀릴 때 잠시 막기 위한 것으로 하루 뒤 지웁니다.</li>
				<li><b>로그인 쿠키</b>: 10번 항목을 보세요.</li>
			</ul>
			<h3>라. 모으는 방법</h3>
			<ul>
				<li>교인 등록 카드와 교인이 직접 알려 준 내용을 교적 담당자가 입력합니다.</li>
				<li>등록 카드·심방 내용을 사진이나 녹음으로 찍어 입력하는 것을 돕는 기능(AI 분석)을 켠 경우, 그 분석 결과를 담당자가 확인한 뒤 저장합니다.</li>
				<li>엑셀 파일 가져오기, 이전에 쓰던 프로그램의 자료 옮겨오기로 들어오기도 합니다.</li>
				<li>프로그램을 쓰려는 분이 가입을 신청하면 최고관리자가 확인하고 승인합니다.</li>
			</ul>
		</section>

		<section id="s2">
			<h2>2. 처리 목적</h2>
			<ul>
				<li><b>교적 관리</b>: 교인 명부와 재적 변동, 세대·소그룹·조직 편성, 직분과 임기 관리</li>
				<li><b>목양과 돌봄</b>: 출석 확인, 심방과 기도 돌봄, 새가족 정착, 생일 등 교회 일정 챙기기</li>
				<li><b>교회 행정</b>: 교인증명서 등 증명서 발급, 교적부·교회 요람 같은 문서 출력, 통계</li>
				<li><b>연락과 안내</b>: 교회 소식과 일정 안내 (문자 보내기 기능을 켠 경우, 문자 수신을 원하지 않는다고 표시한 교인은 제외합니다)</li>
				<li><b>프로그램 운영과 보안</b>: 사용자 확인, 권한 관리, 접속·수정 기록, 비정상 접근 막기</li>
			</ul>
			<p>이 목적을 벗어나는 일에는 쓰지 않으며, 목적이 달라지면 미리 알리고 필요한 동의를 다시 받습니다.</p>
		</section>

		<section id="s3">
			<h2>3. 신앙에 관한 정보</h2>
			<p>
				세례·입교, 직분, 출석, 심방 기록처럼 신앙과 관련된 정보는 개인정보 보호법이 특별히 조심하라고 한 정보(민감정보)가 될 수 있습니다.
				교회는 교인으로 등록할 때 처리하는 항목과 목적을 알리고 동의를 받는 것을 원칙으로 하며, 교회의 신앙 활동과 교인 관리에 꼭 필요한 범위에서만 처리합니다. 동의한 날짜와 방법(서면·구두 등)은 교인의 교적 카드 '메모·동의' 탭의 '동의 기록'에 남기고, 동의를 거두어들이면 철회한 날짜를 남깁니다.
				교인 본인이 아닌 사람의 신앙 정보를 임의로 모으지 않습니다.
			</p>
			<p>14세 미만 어린이의 정보(교회학교 등)는 부모님 등 법정대리인의 동의를 받아 처리하며, 동의한 보호자의 이름과 날짜를 '동의 기록'에 남깁니다.</p>
		</section>

		<section id="s4">
			<h2>4. 볼 수 있는 사람</h2>
			<ul>
				<li>로그인해야만 볼 수 있습니다. 로그인하지 않으면 교인 정보를 볼 수 있는 길이 없습니다.</li>
				<li>사용자마다 최고관리자가 정해 준 <b>등급</b>이 있고, 등급에 따라 볼 수 있는 화면과 자료가 달라집니다. 이 제한은 화면이 아니라 <b>서버가 요청할 때마다 확인</b>합니다.</li>
				<li>소그룹 인도자는 휴대폰으로 자기 소그룹의 출석 화면만 보며, 교인 전체 자료를 받지 않습니다.</li>
				<li>교인 정보를 보거나 고치거나 내려받으면 그 사람과 시각이 접속·수정 기록에 남고, 최고관리자가 확인할 수 있습니다.</li>
			</ul>
		</section>

		<section id="s5">
			<h2>5. 제3자 제공</h2>
			<p>교회는 교인의 개인정보를 원칙적으로 다른 사람이나 기관에 주지 않습니다. 다만 다음의 경우는 예외입니다.</p>
			<ul>
				<li>교인 본인이 따로 동의한 경우 (예: 본인이 요청한 증명서를 제출처에 내는 경우)</li>
				<li>법령에 정해진 경우, 또는 수사기관이 법령에 따라 적법한 절차로 요구한 경우</li>
				<li>소속 교단·노회에 내는 통계 보고처럼 교인을 알아볼 수 없는 숫자로 바꾸어 주는 경우</li>
			</ul>
		</section>

		<section id="s6">
			<h2>6. 맡기는 곳과 국외 이전</h2>
			<p>이 프로그램은 교회가 계약한 웹호스팅(서버)에 직접 설치해서 쓰며, 교인 정보는 그 서버의 데이터베이스에만 저장됩니다. 교회는 아래의 곳에 개인정보 처리를 맡기거나 정보를 보냅니다.</p>
			<div class="pv-tbl">
				<table>
					<thead><tr><th>받는 곳</th><th>하는 일</th><th>보내는 정보</th><th>언제</th></tr></thead>
					<tbody>
						<tr><td>교회가 계약한 웹호스팅 업체</td><td>서버·데이터베이스·백업 파일 보관</td><td>프로그램에 저장된 모든 정보</td><td>항상</td></tr>
						<tr><td>솔라피(Solapi)</td><td>문자 발송</td><td>받는 사람 번호, 문자 내용</td><td>문자 보내기 기능을 켜고 보낼 때만</td></tr>
						<tr><td>구글(Google, 미국 등)</td><td>사진·녹음의 글자 인식(Gemini)</td><td>분석하려고 올린 사진·녹음 파일</td><td>AI 분석 기능을 켜고 쓸 때만</td></tr>
					</tbody>
				</table>
			</div>
			<p>구글로 보내는 파일은 글자를 읽어 내는 데에만 쓰이고, 교회 서버에는 분석이 끝나면 따로 남기지 않습니다. 이 두 기능은 교회가 직접 키를 넣어 켜야만 작동하며, 켜지 않으면 정보는 밖으로 나가지 않습니다.</p>
			<h3>국외 이전 (AI 분석 기능을 켠 경우에만)</h3>
			<div class="pv-tbl">
				<table>
					<tbody>
						<tr><th>이전받는 자</th><td>구글(Google LLC) · 연락처는 구글의 개인정보 보호 안내(policies.google.com)를 보세요.</td></tr>
						<tr><th>이전되는 나라</th><td>미국 등 구글의 서버가 있는 나라</td></tr>
						<tr><th>이전되는 정보</th><td>분석하려고 올린 등록 카드·심방 내용의 사진이나 녹음 파일 (이름·연락처·주소 등이 찍혀 있을 수 있음)</td></tr>
						<tr><th>시기와 방법</th><td>담당자가 '분석' 기능을 쓸 때마다 인터넷(암호화 연결)으로 전송</td></tr>
						<tr><th>이용 목적</th><td>사진·녹음에서 글자와 내용을 읽어 내는 것</td></tr>
						<tr><th>보유·이용 기간</th><td>교회 서버에는 남기지 않습니다. 구글 쪽의 보관은 구글의 서비스 약관과 정책을 따릅니다.</td></tr>
						<tr><th>거부 방법과 불이익</th><td>이 기능을 쓰지 않으면 됩니다. 쓰지 않아도 교적 관리의 다른 기능은 모두 그대로 쓸 수 있습니다. 내 정보가 이 기능으로 처리되기를 원하지 않으면 아래 12번의 책임자에게 말씀해 주세요.</td></tr>
					</tbody>
				</table>
			</div>
			<p>교회가 부서 관리(dep-manage)나 재정 관리(fin-manage)를 같은 서버에 함께 설치해 쓰는 경우, 교적의 이름·계정 정보를 함께 참조합니다. 그 프로그램에서 처리하는 정보는 해당 프로그램에도 처리방침이 있습니다.</p>
		</section>

		<section id="s7">
			<h2>7. 보관 기간</h2>
			<div class="pv-tbl">
				<table>
					<thead><tr><th>정보</th><th>보관 기간</th></tr></thead>
					<tbody>
						<tr><td>재적 교인의 교적 정보</td><td>재적하는 동안</td></tr>
						<tr><td>전출·별세·제적한 교인의 인적사항<br /><small>(연락처, 주소, 생일, 직업·직장, 사진, 담당자 메모, 수정 이력 등)</small></td><td>그 날부터 <b>5년</b>(교회가 1~30년으로 바꿀 수 있음)이 지나면 삭제</td></tr>
						<tr><td>전출·별세·제적한 교인의 교회 기록<br /><small>(이름, 교번, 재적 변동, 세례, 직분, 출석 통계 등)</small></td><td>교회의 역사 기록으로 계속 보관 (연락처·주소는 이미 지운 뒤)</td></tr>
						<tr><td>접속·수정 기록 (접속 주소 포함)</td><td><b>3년</b>(교회가 2~10년으로 바꿀 수 있고, 법 기준에 따라 2년보다 짧게는 정할 수 없음) 뒤 삭제</td></tr>
						<tr><td>로그인 시도 기록</td><td>하루</td></tr>
						<tr><td>프로그램 사용자 계정 정보</td><td>사용하는 동안. 사용을 마치면 삭제를 요청할 수 있습니다.</td></tr>
						<tr><td>백업 파일</td><td>정해 둔 개수만 남기고 오래된 것부터 삭제 (자동 백업은 기본 최근 30개). 백업 안에도 교인 정보가 들어 있으므로 최고관리자만 받을 수 있습니다.</td></tr>
					</tbody>
				</table>
			</div>
			<p>법령에서 더 오래 보관하라고 정한 정보는 그 기간 동안 보관합니다.</p>
		</section>

		<section id="s8">
			<h2>8. 지우는 절차와 방법</h2>
			<ul>
				<li><b>전자 파일(프로그램 안의 정보)</b>: 보관 기간이 지나면 프로그램이 하루에 한 번 쓰는 중에 자동으로 지웁니다. 인적사항 칸은 비워서 다시 되살릴 수 없게 하고, 사진 파일은 서버에서 지웁니다. 지우기 전에 서버에 한 번 보관되는 백업은 위 백업 보관 개수에 따라 순서대로 사라집니다.</li>
				<li><b>종이 문서</b>: 교적부, 증명서, 요람처럼 출력한 문서는 쓰임이 끝나면 분쇄하거나 소각합니다.</li>
				<li>교인이 삭제를 요청한 경우, 보관해야 할 법령상 이유가 없으면 지체 없이 지웁니다.</li>
			</ul>
		</section>

		<section id="s9">
			<h2>9. 내 정보에 대한 권리</h2>
			<ul>
				<li>교인은 언제든지 자기 정보의 <b>열람, 정정, 삭제, 처리 정지</b>를 요청할 수 있고, 동의했던 것을 거두어들일 수 있습니다.</li>
				<li>교회 사무실이나 교적 담당 교역자에게 말씀하시면 됩니다. 교회는 요청을 받은 날부터 10일 안에 처리하고 결과를 알려 드립니다.</li>
				<li>교인 본인이 어려울 때는 법정대리인이나 위임받은 분이 대신 요청할 수 있습니다.</li>
				<li>문자 수신을 원하지 않는 교인은 그렇게 표시해 두면 문자 발송에서 제외됩니다.</li>
			</ul>
		</section>

		<section id="s10">
			<h2>10. 쿠키</h2>
			<ul>
				<li>이 프로그램은 로그인 상태를 유지하기 위한 <b>세션 쿠키 한 가지</b>만 씁니다. 광고나 이용자 추적을 위한 쿠키는 쓰지 않습니다.</li>
				<li>이 쿠키는 로그인할 때 만들어지고, 로그아웃하거나 30분 동안 쓰지 않으면 끝납니다.</li>
				<li>브라우저 설정에서 쿠키를 막을 수 있지만, 막으면 로그인할 수 없습니다.</li>
			</ul>
		</section>

		<section id="s11">
			<h2>11. 안전하게 지키는 방법</h2>
			<ul>
				<li><b>암호화</b>: 비밀번호는 되돌릴 수 없는 방식으로 암호화하여 저장합니다. 주소가 http로 들어와도 https(암호화 연결)로 보내며, 보안 설정을 더합니다.</li>
				<li><b>접근 제한</b>: 등급별로 볼 수 있는 범위를 나누고, 서버가 요청마다 확인합니다. 임시 비밀번호로 로그인하면 반드시 본인 비밀번호로 바꾸게 합니다.</li>
				<li><b>잠금과 자동 로그아웃</b>: 로그인을 15분 안에 여러 번 틀리면 잠시 막고, 30분 동안 쓰지 않으면 자동으로 로그아웃합니다.</li>
				<li><b>기록 점검</b>: 열람·수정·내려받기를 접속 주소(IP)와 함께 모두 기록하고, 최고관리자가 확인할 수 있습니다. 기록은 고칠 수 없고, 최소 2년 보관합니다.</li>
				<li><b>백업</b>: 자동·수동 백업을 두고, 되살리기 직전의 자료도 한 번 더 보관합니다. 백업 파일은 최고관리자만 받을 수 있습니다.</li>
				<li><b>사람에 대한 관리</b>: 교인 정보를 다루는 사용자를 꼭 필요한 사람으로 줄이고, 비밀번호를 남에게 알려 주지 않도록 안내합니다. 출력한 문서는 잠금 장치가 있는 곳에 둡니다.</li>
			</ul>
		</section>

		<section id="s12">
			<h2>12. 책임자와 문의·신고</h2>
			<p>
				개인정보 보호 책임은 <b>{pv.officer || church + '의 담임목사(또는 교회가 지정한 교적 담당자)'}</b>에게 있습니다.
				개인정보에 관한 문의, 열람·정정·삭제 요청, 불만 처리는 {pv.phone || pv.email ? '아래로' : '교회 사무실이나 교적 담당 교역자에게'} 해 주세요.
			</p>
			{#if pv.officer || pv.phone || pv.email}
				<ul class="pv-contact">
					{#if pv.officer}<li>보호책임자: {pv.officer}</li>{/if}
					{#if pv.phone}<li>전화: {pv.phone}</li>{/if}
					{#if pv.email}<li>이메일: {pv.email}</li>{/if}
				</ul>
			{/if}
			<p>개인정보 침해에 대한 도움이 필요하면 아래 기관에도 문의할 수 있습니다.</p>
			<ul>
				<li>개인정보침해 신고센터 (한국인터넷진흥원): 국번 없이 118 · privacy.kisa.or.kr</li>
				<li>개인정보 분쟁조정위원회: 1833-6972 · kopico.go.kr</li>
				<li>대검찰청 사이버범죄 수사: 국번 없이 1301 · spo.go.kr</li>
				<li>경찰청 사이버범죄 신고: 국번 없이 182 · ecrm.police.go.kr</li>
			</ul>
		</section>

		<section id="s13">
			<h2>13. 프로그램 제작자의 책임</h2>
			<p>
				교적 관리(ch-manage)는 누구나 무료로 설치해서 쓰는 공개 프로그램(AGPL-3.0)입니다.
				교인 정보는 각 교회가 설치한 서버에만 있고, 프로그램 제작자에게 보내지거나 제작자가 볼 수 없습니다.
				따라서 개인정보를 처리하는 주체와 책임은 이 프로그램을 설치해 쓰는 교회에게 있습니다.
			</p>
		</section>

		<section id="s14">
			<h2>14. 기타와 방침의 변경</h2>
			<ul>
				<li>이 프로그램에서 연결해 놓은 다른 사이트(문자·AI 서비스의 안내 화면 등)에는 이 방침이 적용되지 않습니다.</li>
				<li>이 방침의 내용이 바뀌면 시행하기 7일 전부터 교회 안내를 통해 알립니다. 권리에 중요한 변경은 따로 안내합니다.</li>
			</ul>
			<p class="pv-ver">이 방침은 {VERSION}부터 시행합니다.</p>
			<h3>개정 이력</h3>
			<ul class="pv-hist">
				<li><b>2026년 11월 17일</b> · 최초 시행. 접속·수정 기록에 접속 주소(IP)를 남기고 보관을 최소 2년으로 정함, 국외 이전 고지와 동의 기록 안내를 보완함</li>
			</ul>
		</section>

		<p class="pv-note">이 문서는 교적 관리 프로그램의 기본 문안입니다. 교회가 실제로 쓰는 방법과 다르다면 교회 사정에 맞게 고쳐 쓰고, 필요하면 전문가의 검토를 받으세요.</p>
	</article>
</div>

<style>
	.pv { min-height: 100vh; background: #f6f7fb; color: #1f2545; font-size: 1rem; line-height: 1.75; }
	.pv-top { max-width: 820px; margin: 0 auto; padding: 1.1rem 1.25rem 0; }
	.pv-brand { font-size: 0.92rem; color: #4558ae; font-weight: 600; text-decoration: none; }
	.pv-brand:hover { text-decoration: underline; }
	.pv-doc { max-width: 820px; margin: 0.9rem auto 3rem; padding: clamp(1.4rem, 4vw, 2.6rem); background: #fff; border-radius: 18px; box-shadow: 0 6px 24px rgba(30, 37, 69, 0.07); }
	h1 { margin: 0; font-size: clamp(1.6rem, 4.4vw, 2.1rem); letter-spacing: -0.03em; line-height: 1.25; }
	.pv-ver { margin: 0.5rem 0 0; font-size: 0.88rem; color: #6a7090; }
	.pv-lead { margin: 1.2rem 0 0; }
	.pv-toc { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.3rem 1.4rem; margin: 1.5rem 0 0; padding: 1rem 1.2rem; border-radius: 12px; background: #f1f3fb; font-size: 0.92rem; }
	.pv-toc a { color: #34408a; text-decoration: none; padding: 0.1rem 0; }
	.pv-toc a:hover { text-decoration: underline; }
	section { margin-top: 2.1rem; scroll-margin-top: 1rem; }
	h2 { margin: 0 0 0.6rem; font-size: 1.25rem; letter-spacing: -0.02em; line-height: 1.4; }
	h3 { margin: 1.1rem 0 0.3rem; font-size: 1rem; color: #34408a; }
	p { margin: 0.6rem 0 0; }
	ul { margin: 0.4rem 0 0; padding-left: 1.2rem; }
	li { margin: 0.3rem 0; }
	.pv-tbl { margin: 0.8rem 0 0; overflow-x: auto; }
	table { width: 100%; border-collapse: collapse; font-size: 0.92rem; min-width: 520px; }
	th, td { padding: 0.6rem 0.7rem; border: 1px solid #dfe3f1; text-align: left; vertical-align: top; }
	th { background: #f1f3fb; font-weight: 700; }
	td small { color: #6a7090; }
	.pv-contact { list-style: none; padding-left: 0; margin-top: 0.5rem; }
	.pv-contact li { padding: 0.35rem 0.7rem; margin: 0.25rem 0; background: #f1f3fb; border-radius: 8px; }
	.pv-hist { padding-left: 1.2rem; }
	.pv-note { margin-top: 2.2rem; padding-top: 1rem; border-top: 1px solid #dfe3f1; font-size: 0.85rem; color: #6a7090; }
	@media (max-width: 640px) { .pv-toc { grid-template-columns: 1fr; } .pv-doc { border-radius: 0; margin-top: 0.6rem; } }
</style>
