<script lang="ts">
	// 사진을 올리면 자를 부분(3:4)을 고르고, 작은 크기로 줄여서 돌려줍니다.
	let { file, ondone, oncancel }: { file: File; ondone: (dataUrl: string) => void; oncancel: () => void } = $props();

	const FW = 240, FH = 320; // 자르는 틀
	let img = $state<HTMLImageElement | null>(null);
	let src = $state('');
	let zoom = $state(1);
	let ox = $state(0);
	let oy = $state(0);
	let drag: { x: number; y: number; ox: number; oy: number } | null = null;

	const base = $derived(img ? Math.max(FW / img.width, FH / img.height) : 1);
	const scale = $derived(base * zoom);

	$effect(() => {
		const url = URL.createObjectURL(file);
		const im = new Image();
		im.onload = () => {
			img = im; src = url; zoom = 1;
			const b = Math.max(FW / im.width, FH / im.height);
			ox = (FW - im.width * b) / 2; oy = (FH - im.height * b) / 2;
		};
		im.src = url;
		return () => URL.revokeObjectURL(url);
	});

	function clamp() {
		if (!img) return;
		ox = Math.min(0, Math.max(FW - img.width * scale, ox));
		oy = Math.min(0, Math.max(FH - img.height * scale, oy));
	}
	function onZoom(e: Event) {
		if (!img) return;
		const next = +(e.currentTarget as HTMLInputElement).value;
		// 틀의 가운데를 기준으로 확대
		const cx = (FW / 2 - ox) / scale, cy = (FH / 2 - oy) / scale;
		zoom = next;
		const ns = base * next;
		ox = FW / 2 - cx * ns; oy = FH / 2 - cy * ns;
		clamp();
	}
	function down(e: PointerEvent) {
		drag = { x: e.clientX, y: e.clientY, ox, oy };
		(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
	}
	function move(e: PointerEvent) {
		if (!drag) return;
		ox = drag.ox + (e.clientX - drag.x); oy = drag.oy + (e.clientY - drag.y);
		clamp();
	}
	function up() { drag = null; }

	function done() {
		if (!img) return;
		const c = document.createElement('canvas');
		c.width = 300; c.height = 400; // 저장 크기 (용량을 줄이기 위해 작게)
		c.getContext('2d')!.drawImage(img, -ox / scale, -oy / scale, FW / scale, FH / scale, 0, 0, c.width, c.height);
		ondone(c.toDataURL('image/jpeg', 0.82));
	}
</script>

<div class="crop-back" role="dialog" aria-modal="true" aria-label="사진 자르기">
	<div class="crop-box">
		<h3>사진 자르기</h3>
		<p class="muted" style="margin:.1rem 0 .7rem;font-size:.85rem">사진을 끌어서 위치를 맞추고, 아래 막대로 크기를 조절하세요. 저장할 때 용량이 자동으로 줄어듭니다.</p>
		<div class="crop-frame" style="width:{FW}px;height:{FH}px" role="presentation" onpointerdown={down} onpointermove={move} onpointerup={up} onpointercancel={up}>
			{#if img}<img {src} alt="" draggable="false" style="width:{img.width * scale}px;height:{img.height * scale}px;transform:translate({ox}px,{oy}px)" />{:else}<span class="muted" style="padding:1rem">불러오는 중…</span>{/if}
		</div>
		<label class="row" style="margin:.8rem 0;gap:.6rem;flex-wrap:nowrap;font-size:.85rem;color:var(--muted)">크기
			<input type="range" min="1" max="8" step="0.01" value={zoom} oninput={onZoom} style="padding:0" /></label>
		<div class="row"><button type="button" onclick={done} disabled={!img}>이 부분으로 저장</button><button type="button" class="ghost" onclick={oncancel}>취소</button></div>
	</div>
</div>

<style>
	.crop-back { position: fixed; inset: 0; z-index: 100; background: rgba(30, 37, 69, 0.55); display: grid; place-items: center; padding: 1rem; }
	.crop-box { background: #fff; border-radius: 18px; padding: 1.2rem 1.4rem; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3); max-width: 340px; width: 100%; }
	.crop-box h3 { margin: 0 0 0.2rem; }
	.crop-frame { position: relative; overflow: hidden; margin: 0 auto; border-radius: 10px; background: #1e2545; touch-action: none; cursor: grab; outline: 2px solid var(--primary); }
	.crop-frame:active { cursor: grabbing; }
	.crop-frame img { position: absolute; left: 0; top: 0; max-width: none; user-select: none; -webkit-user-drag: none; }
</style>
