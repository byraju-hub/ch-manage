<script lang="ts">
	// 관리자 › 프로그램 설정 › 외부 서비스: 문자 발송(솔라피)과 AI 분석(구글 Gemini). 최고관리자만.
	// 키는 서버에만 저장하고 화면으로는 돌려받지 않습니다(끝 4자리만 보임). 설정하지 않으면 해당 버튼은 화면에 나타나지 않습니다.
	import { onMount } from 'svelte';
	import { registry } from '#lib/registry/store.svelte.ts';
	import { auth } from '#lib/registry/auth.svelte.ts';

	interface Info {
		sms: { configured: boolean; sender: string; keyTail: string; secretSet: boolean };
		ai: { configured: boolean; model: string; defaultModel: string; keyTail: string };
		balance?: { balance: number; point: number };
	}
	let info = $state<Info | null>(null);
	let msg = $state('');
	let err = $state('');
	let busy = $state('');
	let smsKey = $state(''), smsSecret = $state(''), smsSender = $state('');
	let aiKey = $state(''), aiModel = $state('');
	let finPath = $state('fm'), finPrefix = $state('fm_'), finInstalled = $state(false);

	async function loadFin() {
		try { const c = await auth.api<{ path: string; prefix: string; installed: boolean }>('GET', '/finance/config'); finPath = c.path; finPrefix = c.prefix; finInstalled = c.installed; } catch { /* 위쪽 오류 표시로 충분 */ }
	}
	async function saveFin() {
		err = msg = ''; busy = 'fin';
		try {
			const c = await auth.api<{ path: string; prefix: string; installed: boolean }>('PUT', '/finance/config', { path: finPath, prefix: finPrefix });
			finPath = c.path; finPrefix = c.prefix; finInstalled = c.installed;
			msg = c.installed ? '재정 관리 연결 정보를 저장했습니다. 재정 관리가 설치되어 있어 상단에 [재정 열기]가 나타납니다.' : '저장했습니다. 이 데이터베이스에서 재정 관리(표 이름이 위 접두어로 시작)를 찾지 못해 [재정 열기]는 아직 나타나지 않습니다.';
			await refreshFeatures();
		} catch (e) { err = e instanceof Error ? e.message : '저장하지 못했습니다.'; } finally { busy = ''; }
	}

	async function load() {
		try {
			info = await auth.api<Info>('GET', '/integrations');
			smsSender = info.sms.sender; aiModel = info.ai.model;
		} catch (e) { err = e instanceof Error ? e.message : '불러오지 못했습니다.'; }
	}
	onMount(() => { load(); loadFin(); });
	const refreshFeatures = async () => { try { await registry.reload(); } catch { /* 다음 접속 때 반영됨 */ } };

	async function saveSms() {
		err = msg = ''; busy = 'sms';
		try {
			info = await auth.api<Info>('POST', '/integrations/sms', { key: smsKey, secret: smsSecret, sender: smsSender });
			smsKey = smsSecret = ''; smsSender = info.sms.sender;
			msg = `문자 서비스를 연결했습니다.${info.balance ? ` 현재 잔액 ${info.balance.balance.toLocaleString()}원` : ''}`;
			await refreshFeatures();
		} catch (e) { err = e instanceof Error ? e.message : '저장하지 못했습니다.'; } finally { busy = ''; }
	}
	async function saveAi() {
		err = msg = ''; busy = 'ai';
		try {
			info = await auth.api<Info>('POST', '/integrations/ai', { key: aiKey, model: aiModel });
			aiKey = ''; aiModel = info.ai.model;
			msg = 'AI 서비스를 연결했습니다. (연결 확인을 마쳤습니다)';
			await refreshFeatures();
		} catch (e) { err = e instanceof Error ? e.message : '저장하지 못했습니다.'; } finally { busy = ''; }
	}
	async function remove(kind: 'sms' | 'ai') {
		if (!confirm(kind === 'sms' ? '문자 서비스 연결을 끊습니다. 저장된 키가 지워지고 문자 보내기 버튼이 사라집니다.' : 'AI 서비스 연결을 끊습니다. 저장된 키가 지워지고 카드 읽기·심방 분석이 사라집니다.')) return;
		err = msg = '';
		try { info = await auth.api<Info>('POST', '/integrations/delete', { kind }); msg = '연결을 끊었습니다.'; await refreshFeatures(); }
		catch (e) { err = e instanceof Error ? e.message : '처리하지 못했습니다.'; }
	}
</script>

{#if !registry.isSuper}
	<div class="warn">외부 서비스 설정은 최고관리자만 할 수 있습니다.</div>
{:else}
	<p class="muted" style="margin:0 0 .8rem;font-size:.88rem">쓰고 싶은 기능만 연결하세요. 연결하지 않으면 그 기능의 버튼은 화면에 나타나지 않습니다. 사용 요금은 각 서비스에 직접 내며, 키는 이 사이트의 서버에만 저장됩니다(백업 파일에도 넣지 않습니다).</p>
	{#if msg}<p class="ok">{msg}</p>{/if}
	{#if err}<p class="warn">{err}</p>{/if}

	<div class="dash-grid even">
		<section class="card">
			<h2>문자 보내기 <small class="hint">(솔라피)</small> {#if info?.sms.configured}<span class="tag">연결됨</span>{/if}</h2>
			<p class="muted" style="font-size:.82rem;margin:0 0 .6rem">교인 목록에서 검색한 사람들에게 문자(SMS·LMS)를 보냅니다. <a href="https://solapi.com" target="_blank" rel="noopener">solapi.com</a>에서 가입하고, 발신번호를 등록한 뒤 <b>API Key·API Secret</b>을 만들어 넣으세요. 하루 최대 3,000건까지 보낼 수 있습니다.</p>
			<div class="form">
				<label>API Key{#if info?.sms.keyTail} <small class="hint">(저장됨 {info.sms.keyTail} · 바꿀 때만 입력)</small>{/if}<input bind:value={smsKey} autocomplete="off" placeholder={info?.sms.keyTail ? '그대로 두려면 비워 두세요' : 'NCS…'} /></label>
				<label>API Secret{#if info?.sms.secretSet} <small class="hint">(저장됨 · 바꿀 때만 입력)</small>{/if}<input type="password" bind:value={smsSecret} autocomplete="new-password" placeholder={info?.sms.secretSet ? '그대로 두려면 비워 두세요' : ''} /></label>
				<label>발신번호 <small class="hint">(솔라피에 등록한 번호)</small><input bind:value={smsSender} inputmode="tel" placeholder="02-000-0000 또는 010-0000-0000" /></label>
			</div>
			<div class="row" style="justify-content:flex-end;gap:.5rem;margin-top:.7rem">
				{#if info?.sms.configured}<button type="button" class="ghost" onclick={() => remove('sms')}>연결 끊기</button>{/if}
				<button type="button" onclick={saveSms} disabled={busy !== '' || !smsSender || (!info?.sms.configured && (!smsKey || !smsSecret))}>{busy === 'sms' ? '확인하는 중…' : '저장하고 연결 확인'}</button>
			</div>
		</section>

		<section class="card">
			<h2>AI 분석 <small class="hint">(구글 Gemini)</small> {#if info?.ai.configured}<span class="tag">연결됨</span>{/if}</h2>
			<p class="muted" style="font-size:.82rem;margin:0 0 .6rem">① 교인 등록카드 사진을 읽어 등록 칸을 채우고, ② 심방 녹음·문서를 분석해 심방 기록 칸을 채웁니다. <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com</a>에서 API 키를 만들어 넣으세요. <b>올린 사진·녹음은 분석을 위해 구글 서버로 전송됩니다.</b> 심방 내용에는 건강 같은 민감한 정보가 있을 수 있으니, 교인에게 알리고 동의를 받은 뒤 쓰세요. 파일 자체는 이 서버에 남기지 않습니다.</p>
			<div class="form">
				<label>API 키{#if info?.ai.keyTail} <small class="hint">(저장됨 {info.ai.keyTail} · 바꿀 때만 입력)</small>{/if}<input type="password" bind:value={aiKey} autocomplete="new-password" placeholder={info?.ai.keyTail ? '그대로 두려면 비워 두세요' : 'AIza…'} /></label>
				<label>모델 <small class="hint">(비워 두면 {info?.ai.defaultModel ?? 'gemini-2.5-flash'})</small><input bind:value={aiModel} placeholder={info?.ai.defaultModel ?? ''} /></label>
			</div>
			<div class="row" style="justify-content:flex-end;gap:.5rem;margin-top:.7rem">
				{#if info?.ai.configured}<button type="button" class="ghost" onclick={() => remove('ai')}>연결 끊기</button>{/if}
				<button type="button" onclick={saveAi} disabled={busy !== '' || (!info?.ai.configured && !aiKey)}>{busy === 'ai' ? '확인하는 중…' : '저장하고 연결 확인'}</button>
			</div>
		</section>
	</div>

	<section class="card" style="margin-top:1rem">
		<h2>재정 관리 연결 <small class="hint">(fin-manage)</small> {#if finInstalled}<span class="tag">설치됨</span>{/if}</h2>
		<p class="muted" style="font-size:.82rem;margin:0 0 .6rem">같은 데이터베이스에 재정 관리가 설치되어 있으면 상단에 <b>재정 열기</b> 버튼이 나타나 비밀번호를 다시 넣지 않고 넘어갈 수 있어요. 폴더 이름과 표 접두어를 바꿔 설치했다면 여기에서 맞춰 주세요. 재정 사용 여부(승인)는 재정 관리에서 따로 정해요.</p>
		<div class="form">
			<label>재정 관리 폴더 이름 <small class="hint">(이 사이트와 같은 위치의 폴더, 기본 fm)</small><input bind:value={finPath} placeholder="fm" autocomplete="off" /></label>
			<label>재정 관리 표 접두어 <small class="hint">(기본 fm_)</small><input bind:value={finPrefix} placeholder="fm_" autocomplete="off" /></label>
		</div>
		<div class="row" style="justify-content:flex-end;margin-top:.7rem"><button type="button" onclick={saveFin} disabled={busy !== ''}>{busy === 'fin' ? '저장하는 중…' : '저장'}</button></div>
	</section>
{/if}
