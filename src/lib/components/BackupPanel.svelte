<script lang="ts">
	// 관리자 › 프로그램 설정 › 백업: 자동 백업 설정, 지금 내려받기, 백업 파일로 되살리기
	// [실제 시스템] 자동 백업은 서버(Supabase 예약 백업 · 매일 스냅샷)가 하고, 이 화면은 주기 · 보관 개수 · 실패 알림만 정합니다.
	//   '지금 내려받기'는 전체 교적이 담긴 파일이라 최고관리자만, 암호를 걸어 내려받게 해야 합니다.
	import { registry } from '#lib/registry/store.svelte.ts';
	import type { BackupCfg } from '#lib/registry/site-settings.ts';

	let cfg = $state<BackupCfg>(structuredClone($state.snapshot(registry.backupCfg)) as BackupCfg);
	let msg = $state('');
	let err = $state('');
	const can = $derived(registry.canSetup);

	import { auth } from '#lib/registry/auth.svelte.ts';
	async function toggleDemo(on: boolean) {
		err = msg = '';
		try { await auth.setDemoAccount(on); msg = on ? '체험 계정(demo / 1234)을 켰습니다.' : '체험 계정을 껐습니다.'; }
		catch (e) { err = e instanceof Error ? e.message : '바꾸지 못했습니다.'; }
	}
	function save() { registry.saveSite({ backup: cfg }, '백업 설정 저장'); msg = '백업 설정을 저장했습니다.'; }
	function download() {
		if (!registry.isSuper) { err = '데이터 전체 내려받기는 최고관리자만 할 수 있습니다.'; return; }
		const blob = new Blob([registry.backupJson()], { type: 'application/json' });
		const a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = `교적-백업-${new Date().toISOString().slice(0, 16).replace(/[T:]/g, '-')}.json`;
		a.click();
		URL.revokeObjectURL(a.href);
		cfg.last = registry.backupCfg.last;
		msg = '백업 파일을 내려받았습니다. 안전한 곳(암호가 걸린 저장 장치)에 보관해 주세요.';
	}
	async function restore(e: Event) {
		const f = (e.currentTarget as HTMLInputElement).files?.[0];
		(e.currentTarget as HTMLInputElement).value = '';
		if (!f) return;
		if (!confirm(`'${f.name}' 파일의 내용으로 지금 교적 데이터를 모두 바꿉니다.\n지금 데이터는 사라집니다. 먼저 '지금 백업 내려받기'를 해 두셨나요?`)) return;
		const e2 = registry.restoreJson(await f.text());
		err = e2; msg = e2 ? '' : '백업 파일로 되살렸습니다.';
	}
</script>

{#if !can}<div class="warn">백업 설정은 최고관리자·사이트 관리자만 바꿀 수 있습니다.</div>{/if}
{#if msg}<p class="ok">{msg}</p>{/if}
{#if err}<p class="warn">{err}</p>{/if}

<div class="dash-grid even">
	<section class="card">
		<h2>자동 백업</h2>
		<label class="row" style="gap:.4rem;margin-bottom:.7rem"><input type="checkbox" bind:checked={cfg.auto} style="width:auto" />자동 백업 사용</label>
		<div class="form">
			<label>주기<select bind:value={cfg.cycle} disabled={!cfg.auto}><option>매일</option><option>매주</option><option>매월</option></select></label>
			<label>시각<input type="time" bind:value={cfg.time} disabled={!cfg.auto} /></label>
			<label><span class="lt">보관 개수 <small class="hint">(오래된 것부터 지움)</small></span><input type="number" min="1" max="365" bind:value={cfg.keep} disabled={!cfg.auto} /></label>
			<label><span class="lt">실패 알림 <small class="hint">(이메일)</small></span><input type="email" bind:value={cfg.notify} placeholder="admin@example.com" /></label>
		</div>
		<p class="muted" style="font-size:.8rem;margin:.6rem 0 0">{cfg.auto ? `${cfg.cycle} ${cfg.time}에 서버가 백업하고, 최근 ${cfg.keep}개를 보관합니다.` : '자동 백업을 쓰지 않습니다. 정기적으로 직접 내려받아 주세요.'} (자동 백업은 아직 준비 중이라 설정만 저장되고 실제로 백업되지는 않습니다.)</p>
		<div class="row" style="justify-content:flex-end;margin-top:.7rem"><button type="button" onclick={save} disabled={!can}>백업 설정 저장</button></div>
	</section>
	<section class="card">
		<h2>직접 백업 · 되살리기</h2>
		<p class="muted" style="font-size:.85rem;margin:0 0 .7rem">마지막으로 직접 내려받은 때: <b style="color:var(--text)">{cfg.last ?? '없음'}</b></p>
		<div class="row">
			<button type="button" onclick={download} disabled={!registry.isSuper}>지금 백업 내려받기</button>
			<label class="btn ghost file-btn" class:disabled={!registry.isSuper}>백업 파일로 되살리기<input type="file" accept=".json,application/json" disabled={!registry.isSuper} onchange={restore} /></label>
		</div>
		<p class="muted" style="font-size:.8rem;margin:.7rem 0 0">교적 전체가 담긴 파일입니다. 최고관리자만 내려받고 되살릴 수 있고, 접속기록에 남습니다. 되살리면 지금 데이터가 백업 파일 내용으로 바뀝니다.</p>
	</section>
</div>

{#if registry.isSuper && !auth.user?.is_demo}
	<section class="card" style="margin-top:1rem">
		<h2>체험 계정 (데모 사이트용)</h2>
		<p class="muted" style="font-size:.85rem;margin:0 0 .7rem">아이디 <b>demo</b> / 비밀번호 <b>1234</b>로 누구나 들어와 모든 화면을 볼 수 있지만, 저장하거나 바꿀 수는 없습니다. 소개 페이지에 올리는 <b>체험용 사이트</b>에서만 켜세요. 실제 교인 자료가 있는 사이트에서는 켜지 마세요.</p>
		<label class="row" style="gap:.4rem"><input type="checkbox" style="width:auto" checked={auth.demoAccount} onchange={(e) => toggleDemo(e.currentTarget.checked)} />체험 계정 사용</label>
	</section>
{/if}

<style>
	.file-btn { position: relative; overflow: hidden; cursor: pointer; }
	.file-btn input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
	.file-btn.disabled { opacity: 0.45; pointer-events: none; }
</style>
