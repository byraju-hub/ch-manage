<script lang="ts">
	// 관리자 › 사용자 설정: 가입 승인, 등급(A·B·C) 지정·설정, 접속기록
	import { page } from '$app/state';
	import { registry } from '#lib/registry/store.svelte.ts';
	import PersonPicker from '#lib/components/PersonPicker.svelte';
	import { GRADES, type Grade, type Perms } from '#lib/registry/types.ts';

	// 사용자 설정: 가입을 신청한 회원을 최고관리자가 승인하고 등급(A 전임 교역자 · B 교적 관리 · C 열람)을 정합니다.
	// 승인·등급·정지는 서버(PHP)가 최고관리자인지 다시 확인한 뒤 처리합니다.
	const tabs = ['사용자 목록', '가입 신청', '등급 설정', '접속 기록'];
	// 대시보드 알림에서 '/admin?tab=가입 신청'으로 들어오면 그 탭부터
	let tab = $state(tabs.includes(page.url.searchParams.get('tab') ?? '') ? page.url.searchParams.get('tab')! : '사용자 목록');
	const pending = $derived(registry.users.filter((u) => u.status === '승인 대기'));
	const active = $derived(registry.users.filter((u) => u.status !== '승인 대기'));
	const PERMS: { key: keyof Perms; label: string; hint: string }[] = [
		{ key: 'edit', label: '수정', hint: '교적 등록·수정, 출석 등록, 새가족 정착 처리' },
		{ key: 'export', label: '내려받기·출력', hint: '목록 내려받기, 교적부·증명서·요람 출력(PDF)' },
		{ key: 'visit', label: '심방', hint: `A 등급만 (바꿀 수 없음): 심방 메뉴(목록·등록·녹음 분석·${registry.t2} 특이사항), 교적 카드의 심방·이전 프로그램 메모, 대시보드 최근 심방` },
		{ key: 'admin', label: '사이트 관리자', hint: '관리자 › 교회 설정 · 프로그램 설정' }
	];
	const nameOf = (id: string | null) => (id ? registry.person(id)?.name ?? '' : '');
	let approveGrade = $state<Record<string, Grade>>({});
	let q = $state('');
	// 임시 비밀번호: 발급한 직후 한 번만 보여 줌 (서버에는 암호화된 값만 남음)
	let temp = $state<{ name: string; login: string; pw: string } | null>(null);
	async function resetPw(u: { id: string; name: string; login_id: string }) {
		if (!confirm(`${u.name} 회원의 임시 비밀번호를 새로 만듭니다.
기존 비밀번호는 쓸 수 없게 됩니다.`)) return;
		const pw = await registry.resetUserPassword(u.id);
		temp = pw ? { name: u.name, login: u.login_id, pw } : null;
	}
	const shown = $derived(active.filter((u) => !q || `${u.name} ${u.login_id} ${u.email ?? ''} ${u.note ?? ''}`.includes(q)));
	let logQ = $state('');
	const logs = $derived(registry.d.accessLog.filter((l) => !logQ || `${l.who} ${l.action} ${l.target}`.includes(logQ)).slice(0, 200));
	const count = (g: Grade) => registry.users.filter((u) => u.status === '사용' && u.grade === g && !u.is_super).length;
</script>

<h1>사용자 설정 <span class="tag">관리자</span></h1>
<p class="muted" style="margin-top:-.6rem">교적 관리는 <b>가입을 신청</b>한 뒤 <b>최고관리자</b>가 승인하고 <b>등급</b>을 정해 준 사람만 씁니다. 등급마다 할 수 있는 일은 '등급 설정'에서 정합니다.</p>

{#if !registry.isSuper}
	<div class="warn">회원 승인과 등급 지정은 <b>최고관리자</b>만 할 수 있습니다. 지금은 보기만 됩니다.</div>
{/if}
{#if registry.opError}<div class="warn">{registry.opError}</div>{/if}
{#if temp}
	<div class="card">
		<b>{temp.name}님의 임시 비밀번호</b>
		<p style="font-size:1.3rem;letter-spacing:.08em;margin:.4rem 0"><code>{temp.pw}</code></p>
		<p class="muted" style="font-size:.84rem">아이디 <b>{temp.login}</b> · 이 화면을 닫으면 다시 볼 수 없습니다. 본인에게 전달하면, 로그인할 때 새 비밀번호를 정하게 됩니다.</p>
		<button type="button" class="ghost sm" onclick={() => (temp = null)}>닫기</button>
	</div>
{/if}

<div class="tabs">
	{#each tabs as t}<button class:on={tab === t} onclick={() => (tab = t)}>{t}{t === '가입 신청' && pending.length ? ` (${pending.length})` : ''}</button>{/each}
</div>

{#if tab === '사용자 목록'}
	<div class="kpis" style="margin-bottom:1rem">
		{#each GRADES as g}<div class="kpi"><div><b>{count(g)}</b><span>{registry.gradeDef(g)?.name}</span></div></div>{/each}
		<div class="kpi"><div><b>{registry.users.filter((u) => u.is_super).length}</b><span>최고관리자</span></div></div>
	</div>
	<div class="card">
		<div class="toolbar" style="margin:0"><input type="search" placeholder="이름 · 아이디 · 이메일 검색" bind:value={q} /></div>
	</div>
	<div class="card" style="padding:0;overflow-x:auto">
		<table class="sm">
			<thead><tr><th>회원</th><th>아이디</th><th>가입일</th><th>연결된 교인</th><th>상태</th><th>등급</th><th style="text-align:center">최고관리자</th><th></th></tr></thead>
			<tbody>
				{#each shown as u (u.id)}
					<tr>
						<td><b>{u.name}</b>{#if u.id === registry.me?.id}<span class="tag now">나</span>{/if}<br /><span class="muted" style="font-size:.78rem">{u.note ?? ''}</span></td>
						<td>{u.login_id}<br /><span class="muted" style="font-size:.78rem">{u.email ?? ''}</span></td>
						<td style="white-space:nowrap">{u.requested_at}</td>
						<td style="min-width:170px">{#if registry.isSuper}<PersonPicker value={u.person_id ?? ''} placeholder="교인 검색" onpick={(id) => registry.linkUserPerson(u.id, id)} />{:else}{nameOf(u.person_id) || '-'}{/if}</td>
						<td>
							{#if registry.isSuper && u.id !== registry.me?.id}
								<select value={u.status} onchange={(e) => registry.setUserStatus(u.id, e.currentTarget.value as '사용' | '정지')} style="width:auto"><option>사용</option><option>정지</option></select>
							{:else}<span class="badge" class:inactive={u.status !== '사용'}>{u.status}</span>{/if}
						</td>
						<td>
							{#if u.is_super}<span class="badge">모든 권한</span>
							{:else if registry.isSuper}
								<select value={u.grade ?? 'C'} onchange={(e) => registry.setUserGrade(u.id, e.currentTarget.value as Grade)} style="width:auto">
									{#each GRADES as g}<option value={g}>{registry.gradeDef(g)?.name}</option>{/each}
								</select>
							{:else}{registry.gradeDef(u.grade)?.name ?? '-'}{/if}
						</td>
						<td style="text-align:center"><input type="checkbox" checked={u.is_super} disabled={!registry.isSuper || u.id === registry.me?.id} onchange={(e) => registry.setUserSuper(u.id, e.currentTarget.checked)} aria-label="{u.name} 최고관리자" /></td>
						<td style="white-space:nowrap">{#if registry.isSuper && u.id !== registry.me?.id}<button class="ghost sm" onclick={() => resetPw(u)}>임시 비밀번호</button> <button class="ghost sm" onclick={() => { if (confirm(`${u.name} 회원을 지웁니다.`)) registry.removeUser(u.id); }}>삭제</button>{/if}</td>
					</tr>
				{:else}<tr><td colspan="8" class="muted">회원이 없습니다.</td></tr>{/each}
			</tbody>
		</table>
	</div>
{:else if tab === '가입 신청'}
	<div class="card">
		{#each pending as u (u.id)}
			<div class="li">
				<span class="avatar warm">{u.name.slice(0, 1)}</span>
				<div class="grow"><b>{u.name} <small class="muted" style="font-weight:500">· {u.login_id}</small></b><small>신청 {u.requested_at}{u.email ? ` · ${u.email}` : ''}{u.phone ? ` · ${u.phone}` : ''}{u.note ? ` · ${u.note}` : ''}</small></div>
				{#if registry.isSuper}
					<select value={approveGrade[u.id] ?? 'C'} onchange={(e) => (approveGrade[u.id] = e.currentTarget.value as Grade)} style="width:auto">
						{#each GRADES as g}<option value={g}>{registry.gradeDef(g)?.name}</option>{/each}
					</select>
					<button class="sm" onclick={() => registry.approveUser(u.id, approveGrade[u.id] ?? 'C')}>승인</button>
					<button class="ghost sm" onclick={() => { if (confirm(`${u.name}님의 가입 신청을 거절합니다.`)) registry.removeUser(u.id); }}>거절</button>
				{/if}
			</div>
		{:else}<p class="empty">가입 신청이 없습니다.</p>{/each}
		<p class="muted" style="font-size:.82rem">교인이 아니거나 누구인지 확인되지 않는 신청은 거절하세요. 승인할 때 등급을 고르고, 나중에 사용자 목록에서 바꿀 수 있습니다.</p>
	</div>
{:else if tab === '등급 설정'}
	<div class="card" style="padding:0;overflow-x:auto">
		<div class="card-head" style="padding:1.1rem 1.3rem 0"><h2>등급마다 할 수 있는 일</h2><span class="sub">모든 등급은 기본으로 교적 열람 가능 · 최고관리자는 모든 권한</span></div>
		<table class="sm">
			<thead><tr><th>등급</th><th>이름</th>{#each PERMS as p}<th style="text-align:center" title={p.hint}>{p.label}</th>{/each}<th>회원</th></tr></thead>
			<tbody>
				{#each GRADES as g}
					{@const d = registry.gradeDef(g)!}
					<tr>
						<td><b>{g}</b></td>
						<td style="min-width:180px">{#if registry.isSuper}<input value={d.name} onchange={(e) => registry.renameGrade(g, e.currentTarget.value)} />{:else}{d.name}{/if}</td>
						{#each PERMS as p}<td style="text-align:center"><input type="checkbox" checked={p.key === 'visit' ? g === 'A' : d.perms[p.key]} disabled={!registry.isSuper || p.key === 'visit'} title={p.key === 'visit' ? '심방은 A 등급만 (고정)' : undefined} onchange={(e) => registry.setGradePerm(g, p.key, e.currentTarget.checked)} aria-label="{d.name} {p.label}" /></td>{/each}
						<td>{count(g)}명</td>
					</tr>
				{/each}
				<tr><td><b>최고관리자</b></td><td>지정된 회원</td>{#each PERMS as p}<td style="text-align:center">{p.key === 'visit' ? 'A 등급일 때만' : '✓'}</td>{/each}<td>{registry.users.filter((u) => u.is_super).length}명</td></tr>
			</tbody>
		</table>
	</div>
	<div class="card">
		<h2>권한 설명</h2>
		<table class="sm"><tbody>
			<tr><td><b>열람 (모든 등급)</b></td><td>교인 목록·교적 카드·{registry.t1}·조직·통계</td></tr>
			{#each PERMS as p}<tr><td><b>{p.label}</b></td><td>{p.hint}</td></tr>{/each}
			<tr><td><b>최고관리자</b></td><td>모든 권한 + 가입 승인, 등급 지정, 등급 설정, 사용 정지</td></tr>
		</tbody></table>
		<p class="muted" style="font-size:.82rem">기본값: A 전임 교역자(수정·출력·심방) / B 교적 관리(수정·출력) / C 열람. 심방은 A 등급(전임 교역자)만 볼 수 있고 바꿀 수 없습니다. 달력의 심방 계획(이름·날짜)은 모든 회원에게 보입니다.</p>
	</div>
{:else}
	<div class="card">
		<div class="toolbar"><input type="search" placeholder="회원 · 행동 · 대상 검색" bind:value={logQ} /></div>
		<p class="muted" style="margin:0 0 .6rem">로그인 · 검색 · 열람 · 수정 · 내려받기 · 출력 · 심방 · 회원·등급 변경을 남깁니다. (3년 보관)</p>
		<table class="sm"><thead><tr><th>시각</th><th>누가</th><th>무엇을</th><th>대상</th></tr></thead><tbody>
			{#each logs as l (l.id)}<tr><td style="white-space:nowrap">{l.at}</td><td>{l.who}</td><td>{l.action}</td><td>{l.target}</td></tr>
			{:else}<tr><td colspan="4" class="muted">아직 기록이 없습니다.</td></tr>{/each}
		</tbody></table>
	</div>
{/if}

<style>
</style>
