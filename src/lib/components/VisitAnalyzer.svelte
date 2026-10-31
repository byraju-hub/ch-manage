<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 심방 녹음·문서를 올리면 분석해서 심방 기록 칸을 채워 줍니다. (서버: /api/visit-analyze)
	export interface Analysis { kind: string | null; content: string | null; scripture: string | null; hymn: string | null; follow_up: string | null; companions: string[] }
	let { onresult }: { onresult: (r: Analysis) => void } = $props();

	let file = $state<File | null>(null);
	let busy = $state(false);
	let msg = $state('');
	let err = $state('');
	let transcript = $state<string | null>(null);
	let open = $state(false);

	// ---- 휴대폰·PC에서 바로 녹음 (심방 현장에서 녹음 → 정지하면 바로 분석) ----
	// 브라우저 녹음은 https 주소(배포 사이트) 또는 localhost 에서만 됩니다.
	let rec = $state<MediaRecorder | null>(null);
	let secs = $state(0);
	let timer: ReturnType<typeof setInterval> | undefined;
	const canRecord = typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia && typeof MediaRecorder !== 'undefined';
	async function startRec() {
		err = ''; msg = '';
		try {
			const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
			const mr = new MediaRecorder(stream);
			const chunks: Blob[] = [];
			mr.ondataavailable = (e) => e.data.size && chunks.push(e.data);
			mr.onstop = () => {
				stream.getTracks().forEach((t) => t.stop());
				clearInterval(timer);
				const type = mr.mimeType || 'audio/webm';
				const ext = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm';
				file = new File(chunks, `심방녹음-${new Date().toISOString().slice(0, 16).replace(/[:T]/g, '')}.${ext}`, { type });
				rec = null;
				run();
			};
			mr.start();
			rec = mr; secs = 0;
			timer = setInterval(() => secs++, 1000);
		} catch {
			err = '마이크를 쓸 수 없습니다. 브라우저에서 마이크 권한을 허용해 주세요.';
		}
	}
	function stopRec() { rec?.stop(); }
	const mmss = (n: number) => `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;

	async function run() {
		if (!file) return;
		busy = true; err = ''; msg = ''; transcript = null;
		try {
			const fd = new FormData();
			fd.append('file', file);
			// [실제 시스템] 로그인 세션 토큰을 함께 보내 서버에서 심방 권한을 확인합니다.
			const r = await fetch(hrefTo('/api/v1/visit-analyze'), { method: 'POST', body: fd, headers: { 'x-visit-analyze': '1' } });
			const j = await r.json();
			if (!r.ok) throw new Error(j.error ?? '분석에 실패했습니다.');
			onresult(j.result);
			transcript = j.transcript;
			msg = j.demo ? `${j.note}` : '분석 결과를 아래 칸에 채웠습니다. 내용을 확인하고 고쳐서 등록하세요.';
		} catch (e) {
			err = e instanceof Error ? e.message : '분석에 실패했습니다.';
		} finally {
			busy = false;
		}
	}
</script>

<div class="analyzer">
	<div class="row" style="justify-content:space-between;flex-wrap:wrap">
		<b>녹음·문서로 채우기 <span class="tag part">AI 분석</span></b>
		<span class="muted" style="font-size:.78rem">녹음(m4a·mp3·wav) · PDF · 텍스트 / 24MB 이하</span>
	</div>
	{#if canRecord}
		<div class="row" style="margin-top:.5rem">
			{#if rec}
				<span class="rec-dot"></span><b>녹음 중 {mmss(secs)}</b>
				<button type="button" class="danger sm" onclick={stopRec}>정지하고 분석</button>
			{:else}
				<button type="button" class="sm" onclick={startRec} disabled={busy}>● 바로 녹음</button>
				<span class="muted" style="font-size:.8rem">또는 파일을 고르세요</span>
			{/if}
		</div>
	{/if}
	<div class="row" style="margin-top:.5rem;flex-wrap:nowrap">
		<input type="file" accept="audio/*,video/mp4,.m4a,.mp3,.wav,.pdf,.txt,.md,text/plain,application/pdf" onchange={(e) => { file = e.currentTarget.files?.[0] ?? null; msg = ''; err = ''; }} style="padding:.35rem" />
		<button type="button" onclick={run} disabled={!file || busy}>{busy ? '분석 중…' : '분석해서 채우기'}</button>
	</div>
	{#if msg}<p class="ok" style="margin:.6rem 0 0">{msg}</p>{/if}
	{#if err}<p class="warn" style="margin:.6rem 0 0">{err}</p>{/if}
	{#if transcript}
		<button type="button" class="ghost sm" style="margin-top:.5rem" onclick={() => (open = !open)}>{open ? '받아쓰기 원문 접기' : '받아쓰기 원문 보기'}</button>
		{#if open}<pre class="transcript">{transcript}</pre>{/if}
	{/if}
	<p class="muted" style="font-size:.76rem;margin:.6rem 0 0">※ 올린 파일의 내용은 분석을 위해 외부 AI 서비스로 전송됩니다. 심방 내용에는 건강 등 민감한 정보가 있을 수 있어, 실제 도입 전에 동의 절차와 보관 방침을 정해야 합니다. 파일 자체는 저장하지 않습니다.</p>
</div>

<style>
	.analyzer { padding: 0.9rem 1rem; border: 1px dashed var(--primary); border-radius: 12px; background: var(--primary-50); }
	.rec-dot { width: 12px; height: 12px; border-radius: 50%; background: #e03131; animation: blink 1s infinite; }
	@keyframes blink { 50% { opacity: 0.25; } }
	.transcript { white-space: pre-wrap; max-height: 220px; overflow: auto; margin: 0.5rem 0 0; padding: 0.7rem; background: #fff; border: 1px solid var(--line); border-radius: 10px; font: inherit; font-size: 0.85rem; }
</style>
