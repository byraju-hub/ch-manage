<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 모든 화면 공통: 글꼴·스타일, 파비콘, 휴대폰 확대 막기
	import './app.css';

	import { onMount } from 'svelte';
	import { registry } from '#lib/registry/store.svelte.ts';

	let { children } = $props();

	// 휴대폰에서 손가락으로 벌려 확대(핀치 줌)하지 못하게 막음. iOS Safari 는 viewport 설정을 무시해서 따로 막아야 함
	onMount(() => {
		const stop = (e: Event) => e.preventDefault();
		const pinch = (e: TouchEvent) => { if (e.touches.length > 1) e.preventDefault(); };
		document.addEventListener('gesturestart', stop, { passive: false });
		document.addEventListener('gesturechange', stop, { passive: false });
		document.addEventListener('touchmove', pinch, { passive: false });
		return () => {
			document.removeEventListener('gesturestart', stop);
			document.removeEventListener('gesturechange', stop);
			document.removeEventListener('touchmove', pinch);
		};
	});
</script>

<svelte:head><title>{registry.churchInfo.name} 교적 관리</title><link rel="icon" type="image/png" href={registry.churchInfo.favicon || hrefTo('/api/v1/files/favicon')} /><link rel="apple-touch-icon" href={registry.churchInfo.favicon || hrefTo('/api/v1/files/favicon')} /></svelte:head>

{@render children()}
