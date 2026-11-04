<script lang="ts">
	import { auth } from '#lib/registry/auth.svelte.ts';
	import { uploadChunks } from '#lib/registry/upload.ts';
	// 교인 등록카드를 찍거나 사진을 올리면 서버(/api/v1/card-scan, AI)가 읽어서 등록 칸을 채움
	// 휴대폰에서는 '카드 촬영'을 누르면 바로 카메라가 열림. 사진은 보내기 전에 줄여서 보냄 (서버에 저장하지 않음)
	export type CardResult = Record<string, any>;
	let { onresult }: { onresult: (r: CardResult) => string[] } = $props();

	const LABEL: Record<string, string> = {
		name: '이름', gender: '성별', birth_date: '생년월일', birth_lunar: '양력/음력', phone: '휴대폰', email: '이메일',
		address1: '주소', address2: '상세주소', address_zip: '우편번호', occupation: '직업/직장', marital_status: '결혼 여부',
		wedding_date: '결혼기념일', previous_church: '이전 출석 교회', guide_name: '인도자', faith_kind: '신급', faith_date: '신급일',
		faith_church: '신급교회', office: '직분', reg_date: '교회 등록일', family: '가족', memo: '기타 메모'
	};
	let busy = $state(false);
	let msg = $state('');
	let err = $state('');
	let preview = $state('');
	let filled = $state<string[]>([]);
	let uncertain = $state<string[]>([]);

	// 긴 쪽 1800px, JPEG 로 줄임 (글씨는 읽히면서 전송은 빠르게)
	async function shrink(file: File): Promise<Blob> {
		const img = await createImageBitmap(file).catch(() => null);
		if (!img) return file;
		const s = Math.min(1, 1800 / Math.max(img.width, img.height));
		const c = document.createElement('canvas');
		c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
		c.getContext('2d')!.drawImage(img, 0, 0, c.width, c.height);
		return await new Promise<Blob>((res) => c.toBlob((b) => res(b ?? file), 'image/jpeg', 0.85));
	}

	async function pick(e: Event) {
		const input = e.currentTarget as HTMLInputElement;
		const file = input.files?.[0];
		input.value = '';
		if (!file) return;
		err = ''; msg = ''; filled = []; uncertain = [];
		busy = true;
		try {
			const blob = await shrink(file);
			if (preview) URL.revokeObjectURL(preview);
			preview = URL.createObjectURL(blob);
			msg = '사진을 올리는 중…';
			const id = await uploadChunks(blob);
			msg = '카드를 읽는 중입니다. 잠시 걸립니다…';
			const data = await auth.api<{ result: CardResult }>('POST', '/card-scan', { id });
			filled = onresult(data.result);
			uncertain = (data.result?.uncertain ?? []).map((k: string) => LABEL[k] ?? k);
			msg = filled.length ? '카드 내용을 아래 칸에 채웠습니다. 틀린 곳이 없는지 꼭 확인해 주세요.' : '카드에서 읽은 내용이 없습니다. 더 밝고 반듯하게 다시 찍어 주세요.';
		} catch (e2) {
			err = e2 instanceof Error ? e2.message : '분석 중 오류가 났습니다.';
		} finally {
			busy = false;
		}
	}
</script>

<div class="card scan">
	<div class="scan-head">
		<div>
			<h3>등록카드 촬영으로 채우기</h3>
			<p class="muted">작성한 교인 등록카드를 찍거나 사진을 올리면 내용을 읽어 아래 칸을 채웁니다. 채운 뒤 꼭 확인하고 고쳐 주세요.</p>
		</div>
		<div class="row btns">
			<label class="btn" class:disabled={busy}>{busy ? '읽는 중…' : '카드 촬영'}<input type="file" accept="image/*" capture="environment" onchange={pick} disabled={busy} hidden /></label>
			<label class="btn ghost" class:disabled={busy}>사진 올리기<input type="file" accept="image/*" onchange={pick} disabled={busy} hidden /></label>
		</div>
	</div>
	{#if preview || msg || err}
		<div class="scan-body">
			{#if preview}<img src={preview} alt="찍은 등록카드" />{/if}
			<div class="grow">
				{#if busy}<p class="muted">카드를 읽고 있습니다. 10~20초쯤 걸립니다…</p>{/if}
				{#if err}<p class="bad">{err}</p>{/if}
				{#if msg}<p class="ok">{msg}</p>{/if}
				{#if filled.length}<p class="muted small">채운 칸: {filled.join(' · ')}</p>{/if}
				{#if uncertain.length}<p class="small warn-t">글씨가 확실하지 않은 칸: {uncertain.join(' · ')} — 특히 확인해 주세요.</p>{/if}
			</div>
		</div>
	{/if}
	<p class="muted small" style="margin:.5rem 0 0">카드 사진은 내용을 읽는 데만 쓰고 저장하지 않습니다. (분석은 외부 AI 서비스를 사용)</p>
</div>

<style>
	.scan { border: 1.5px dashed var(--primary-100, #c9d6ff); }
	.scan-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.8rem; }
	.scan h3 { margin: 0 0 0.2rem; }
	.scan p { margin: 0; }
	.btns { gap: 0.5rem; flex-wrap: nowrap; }
	.btn.disabled { opacity: 0.6; pointer-events: none; }
	.scan-body { display: flex; gap: 0.9rem; align-items: flex-start; margin-top: 0.8rem; }
	.scan-body img { width: 120px; max-height: 160px; object-fit: contain; border-radius: 8px; border: 1px solid var(--line-strong); background: #f5f6fb; flex: none; }
	.small { font-size: 0.82rem; margin-top: 0.3rem !important; }
	.ok { color: var(--teal-600); font-weight: 600; }
	.bad { color: var(--orange-600); font-weight: 600; }
	.warn-t { color: var(--orange-600); }
</style>
