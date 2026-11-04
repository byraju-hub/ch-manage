<script lang="ts">
	// 관리자 › 프로그램 설정 › 백업 아래: 개인정보 정리. 전출·별세·제적 후 오래된 교인의 인적사항, 오래된 접속·수정 기록을 정리한다. 최고관리자만.
	import { onMount } from 'svelte';
	import { registry } from '#lib/registry/store.svelte.ts';
	import { auth } from '#lib/registry/auth.svelte.ts';

	interface St { cfg: { auto: boolean; years: number; logYears: number }; due: number; last: string | null; result: { at: string; people: number; logs: number } | null; oldLogs: number }
	let st = $state<St | null>(null);
	let cfg = $state({ auto: true, years: 5, logYears: 3 });
	let msg = $state('');
	let err = $state('');
	let busy = $state(false);

	async function load() {
		try { st = await auth.api<St>('GET', '/retention/status'); cfg = { ...st.cfg }; } catch (e) { err = e instanceof Error ? e.message : '불러오지 못했습니다.'; }
	}
	onMount(load);
	async function save() {
		err = msg = '';
		try { st = await auth.api<St>('POST', '/retention/config', cfg); cfg = { ...st.cfg }; msg = '저장했습니다.'; } catch (e) { err = e instanceof Error ? e.message : '저장하지 못했습니다.'; }
	}
	async function run() {
		if (!st) return;
		if (!confirm(`전출·별세·제적 후 ${st.cfg.years}년이 지난 교인 ${st.due}명의 인적사항(연락처·주소·생년월일·직업·메모·사진·수정 이력)을 지우고, ${st.cfg.logYears}년이 지난 접속·수정 기록 ${st.oldLogs}건도 지웁니다.\n되돌릴 수 없습니다(정리 직전 자료는 서버에 한 번 보관됩니다). 진행할까요?`)) return;
		err = msg = ''; busy = true;
		try {
			st = await auth.api<St>('POST', '/retention/run');
			msg = `정리했습니다. 교인 ${st.result?.people ?? 0}명, 기록 ${st.result?.logs ?? 0}건`;
			await registry.reload();
		} catch (e) { err = e instanceof Error ? e.message : '정리하지 못했습니다.'; } finally { busy = false; }
	}
</script>

{#if registry.isSuper}
	<section class="card" style="margin-top:1rem">
		<h2>개인정보 정리</h2>
		<p class="muted" style="font-size:.85rem;margin:0 0 .7rem">전출·별세·제적(마지막 재적 상태) 후 정해진 기간이 지나면 그 교인의 <b>인적사항</b>(연락처·주소·생년월일·직업·메모·사진·수정 이력)을 지웁니다. 이름·교번·재적 이력·세례·직분 같은 <b>교회 기록은 그대로 보관</b>합니다. 접속·수정 기록도 기간이 지나면 지웁니다. 정리 직전의 자료는 서버에 한 번 보관되어, 잘못 지웠을 때 되살릴 수 있습니다.</p>
		{#if msg}<p class="ok">{msg}</p>{/if}
		{#if err}<p class="warn">{err}</p>{/if}
		<label class="row" style="gap:.4rem;margin-bottom:.6rem"><input type="checkbox" style="width:auto" bind:checked={cfg.auto} />자동으로 정리 <small class="hint">(프로그램을 쓰는 중에 하루에 한 번 확인합니다)</small></label>
		<div class="form">
			<label>인적사항을 지우기까지 <small class="hint">(전출·별세·제적 후 몇 년)</small><input type="number" min="1" max="30" bind:value={cfg.years} /></label>
			<label>접속·수정 기록 보관 <small class="hint">(몇 년)</small><input type="number" min="1" max="10" bind:value={cfg.logYears} /></label>
		</div>
		{#if st}
			<p class="muted" style="font-size:.85rem;margin:.6rem 0 0">지금 정리 대상: 교인 <b style="color:var(--text)">{st.due}명</b>, 오래된 기록 <b style="color:var(--text)">{st.oldLogs}건</b>{#if st.result} · 마지막 정리 {st.result.at} (교인 {st.result.people}명, 기록 {st.result.logs}건){/if}</p>
		{/if}
		<div class="row" style="justify-content:flex-end;gap:.5rem;margin-top:.7rem">
			<button type="button" class="ghost" onclick={run} disabled={busy || !st || (st.due === 0 && st.oldLogs === 0)}>{busy ? '정리하는 중…' : '지금 정리'}</button>
			<button type="button" onclick={save}>설정 저장</button>
		</div>
	</section>
{/if}
