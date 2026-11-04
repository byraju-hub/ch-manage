<script lang="ts">
	// 관리자 › 프로그램 설정 › 백업: 자동 백업 설정, 지금 내려받기, 서버 보관 목록, 백업 파일로 되살리기
	// 백업은 서버가 만든다(모든 표와 올린 그림이 파일 하나로 묶임). 비밀번호(해시)와 심방 기록까지 들어 있어 최고관리자만 쓴다.
	import { onMount } from 'svelte';
	import { registry } from '#lib/registry/store.svelte.ts';
	import type { BackupCfg } from '#lib/registry/site-settings.ts';
	import { auth } from '#lib/registry/auth.svelte.ts';
	import { hrefTo } from '#lib/registry/paths.ts';

	interface Saved { name: string; kind: 'auto' | 'manual' | 'pre'; size: number; at: string }
	interface Status { zlib: boolean; lastAuto: string | null; lastManual: string | null; failedAt: string | null; saved: Saved[]; chunk: number }
	interface Info { at: string; version: string; schema: number; rows: number; files: number; note: string }

	let cfg = $state<BackupCfg>(structuredClone($state.snapshot(registry.backupCfg)) as BackupCfg);
	let msg = $state('');
	let err = $state('');
	let busy = $state('');
	let st = $state<Status | null>(null);
	let pick = $state<{ source: string; label: string; info: Info } | null>(null); // 되살리기 전 확인 중인 파일
	let pw = $state('');
	let sure = $state(false);
	const can = $derived(registry.canSetup);
	const kindName = { auto: '자동', manual: '직접 보관', pre: '되살리기 직전' } as const;

	async function toggleDemo(on: boolean) {
		err = msg = '';
		try { await auth.setDemoAccount(on); msg = on ? '체험 계정(demo / 1234)을 켰습니다.' : '체험 계정을 껐습니다.'; }
		catch (e) { err = e instanceof Error ? e.message : '바꾸지 못했습니다.'; }
	}
	const fail = (e: unknown, what: string) => { msg = ''; err = e instanceof Error ? e.message : `${what}하지 못했습니다.`; };
	const size = (n: number) => (n >= 1e6 ? (n / 1e6).toFixed(1) + 'MB' : Math.max(1, Math.round(n / 1e3)) + 'KB');

	async function load() {
		if (!registry.isSuper) return;
		try { st = await auth.api<Status>('GET', '/backup/status'); } catch (e) { fail(e, '불러오'); }
	}
	onMount(load);

	function save() { registry.saveSite({ backup: cfg }, '백업 설정 저장'); err = ''; msg = '백업 설정을 저장했습니다.'; }

	// 주소를 열면 파일이 내려온다 (로그인 쿠키로 확인). 끝난 뒤 '마지막으로 받은 때'를 새로 읽는다.
	function download(url: string) {
		err = ''; msg = '백업 파일을 만들어 내려받는 중입니다. 자료가 많으면 잠시 걸립니다.';
		const a = document.createElement('a'); a.href = url; a.click();
		setTimeout(load, 4000);
	}
	async function keepOnServer() {
		err = msg = ''; busy = 'keep';
		try { st = await auth.api<Status>('POST', '/backup/run'); msg = '서버에 백업을 보관했습니다.'; }
		catch (e) { fail(e, '보관'); } finally { busy = ''; }
	}
	async function remove(name: string) {
		if (!confirm('이 백업을 서버에서 지웁니다. 되돌릴 수 없습니다.')) return;
		err = msg = '';
		try { st = await auth.api<Status>('POST', '/backup/delete', { name }); msg = '지웠습니다.'; } catch (e) { fail(e, '지우'); }
	}

	// 되살릴 파일을 60KB 조각으로 올린다. (호스팅이 한 번에 받는 크기가 128KB 로 제한됨)
	async function chooseFile(e: Event) {
		const input = e.currentTarget as HTMLInputElement;
		const f = input.files?.[0]; input.value = '';
		if (!f) return;
		err = msg = ''; pick = null; busy = 'upload';
		try {
			const id = Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, '0')).join('');
			const chunk = st?.chunk ?? 60000;
			const buf = new Uint8Array(await f.arrayBuffer());
			for (let seq = 0; seq * chunk < buf.length; seq++) {
				const part = buf.subarray(seq * chunk, (seq + 1) * chunk);
				let bin = ''; for (let i = 0; i < part.length; i += 8192) bin += String.fromCharCode(...part.subarray(i, i + 8192));
				msg = `파일을 올리는 중… ${Math.min(100, Math.round(((seq + 1) * chunk * 100) / buf.length))}%`;
				await auth.api('POST', '/backup/upload', { id, seq, data: btoa(bin) });
			}
			await check(`upload:${id}`, f.name);
		} catch (e) { fail(e, '올리'); } finally { busy = ''; }
	}
	async function check(source: string, label: string) {
		err = ''; msg = '파일을 확인하는 중…';
		try { pick = { source, label, info: await auth.api<Info>('POST', '/backup/check', { source }) }; msg = ''; pw = ''; sure = false; }
		catch (e) { fail(e, '확인'); }
	}
	async function restore() {
		if (!pick) return;
		err = msg = ''; busy = 'restore';
		try {
			await auth.api('POST', '/backup/restore', { source: pick.source, password: pw }, { keep: true });
			msg = '되살렸습니다. 모두 로그아웃되었습니다. 다시 로그인해 주세요.';
			pick = null;
			setTimeout(() => { location.href = hrefTo('/'); }, 2500);
		} catch (e) { fail(e, '되살리'); } finally { busy = ''; }
	}
</script>

{#if !can}<div class="warn">백업 설정은 최고관리자·사이트 관리자만 바꿀 수 있습니다.</div>{/if}
{#if msg}<p class="ok">{msg}</p>{/if}
{#if err}<p class="warn">{err}</p>{/if}
{#if st && !st.zlib}<p class="warn">이 호스팅에는 압축 기능(zlib)이 없어 백업을 만들 수 없습니다. 호스팅에 문의해 주세요.</p>{/if}

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
		<p class="muted" style="font-size:.8rem;margin:.6rem 0 0">{cfg.auto ? `${cfg.cycle} ${cfg.time} 이후에 누군가 프로그램을 쓰고 있으면 서버가 백업하고, 최근 ${cfg.keep}개를 서버에 보관합니다. (호스팅의 예약 작업을 쓰지 않아서, 그 시각에 아무도 접속하지 않으면 다음 접속 때 만들어집니다.)` : '자동 백업을 쓰지 않습니다. 정기적으로 직접 내려받아 주세요.'}</p>
		{#if registry.isSuper && st}
			<p class="muted" style="font-size:.85rem;margin:.6rem 0 0">마지막 자동 백업: <b style="color:var(--text)">{st.lastAuto ?? '아직 없음'}</b>{#if st.failedAt}<br /><span class="bad">최근 자동 백업이 실패했습니다 ({st.failedAt}). 서버 로그(data/app.log)를 확인해 주세요.</span>{/if}</p>
		{/if}
		<div class="row" style="justify-content:flex-end;margin-top:.7rem"><button type="button" onclick={save} disabled={!can}>백업 설정 저장</button></div>
	</section>
	<section class="card">
		<h2>직접 백업 · 되살리기</h2>
		<p class="muted" style="font-size:.85rem;margin:0 0 .7rem">마지막으로 직접 내려받은 때: <b style="color:var(--text)">{st?.lastManual ?? '없음'}</b></p>
		<div class="row" style="flex-wrap:wrap">
			<button type="button" onclick={() => download(hrefTo('/api/v1/backup/download'))} disabled={!registry.isSuper || !st?.zlib}>지금 백업 내려받기</button>
			<button type="button" class="ghost" onclick={keepOnServer} disabled={!registry.isSuper || !st?.zlib || busy !== ''}>서버에 지금 보관</button>
			<label class="btn ghost file-btn" class:disabled={!registry.isSuper || busy !== ''}>백업 파일로 되살리기<input type="file" accept=".cmbak,application/octet-stream" disabled={!registry.isSuper || busy !== ''} onchange={chooseFile} /></label>
		</div>
		<p class="muted" style="font-size:.8rem;margin:.7rem 0 0">교적 전체(교인·출석·심방·사용자·접속 기록)와 올린 그림이 파일 하나로 내려옵니다. 비밀번호 정보도 들어 있으니 안전한 곳에만 보관해 주세요. 최고관리자만 내려받고 되살릴 수 있고, 접속 기록에 남습니다.</p>
	</section>
</div>

{#if pick}
	<section class="card" style="margin-top:1rem">
		<h2>이 백업으로 되살릴까요?</h2>
		<p style="margin:0 0 .5rem"><b>{pick.label}</b> · 만든 때 {pick.info.at} · 프로그램 {pick.info.version} · 자료 {pick.info.rows.toLocaleString()}줄, 그림 {pick.info.files}개{pick.info.note ? ` · ${pick.info.note}` : ''}</p>
		<p class="warn" style="margin:.4rem 0">되살리면 <b>지금 자료가 모두 이 백업의 내용으로 바뀝니다.</b> 그 뒤에 입력한 교인·출석·사용자 변경은 사라지고, 모두 로그아웃됩니다. 되살리기 직전의 자료는 서버에 자동으로 한 번 보관됩니다(아래 목록의 '되살리기 직전').</p>
		<label class="row" style="gap:.4rem;margin:.5rem 0"><input type="checkbox" style="width:auto" bind:checked={sure} />위 내용을 이해했고, 지금 자료를 바꿉니다.</label>
		<div class="form"><label>내 비밀번호 확인<input type="password" bind:value={pw} autocomplete="current-password" /></label></div>
		<div class="row" style="justify-content:flex-end;margin-top:.7rem;gap:.5rem">
			<button type="button" class="ghost" onclick={() => (pick = null)} disabled={busy !== ''}>취소</button>
			<button type="button" onclick={restore} disabled={!sure || !pw || busy !== ''}>{busy === 'restore' ? '되살리는 중…' : '되살리기'}</button>
		</div>
	</section>
{/if}

{#if registry.isSuper && st}
	<section class="card" style="margin-top:1rem">
		<h2>서버에 보관된 백업 <small class="hint">({st.saved.length}개)</small></h2>
		{#if st.saved.length === 0}
			<p class="muted" style="margin:0">아직 없습니다. 자동 백업을 켜 두거나 '서버에 지금 보관'을 눌러 보세요.</p>
		{:else}
			<table class="sm">
				<thead><tr><th>만든 때</th><th>종류</th><th>크기</th><th></th></tr></thead>
				<tbody>
					{#each st.saved as b (b.name)}
						<tr>
							<td>{b.at}</td><td>{kindName[b.kind]}</td><td>{size(b.size)}</td>
							<td class="row" style="gap:.4rem;justify-content:flex-end">
								<button type="button" class="ghost" onclick={() => download(hrefTo('/api/v1/backup/file/' + b.name))}>내려받기</button>
								<button type="button" class="ghost" onclick={() => check('saved:' + b.name, b.at + ' ' + kindName[b.kind] + ' 백업')} disabled={busy !== ''}>되살리기</button>
								<button type="button" class="ghost" onclick={() => remove(b.name)}>삭제</button>
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
		{/if}
		<p class="muted" style="font-size:.8rem;margin:.6rem 0 0">서버에 보관한 백업은 호스팅 안(data/backups)에 있어서 호스팅 자체에 문제가 생기면 함께 잃을 수 있습니다. 가끔 '지금 백업 내려받기'로 내 컴퓨터에도 받아 두세요.</p>
	</section>
{/if}

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
