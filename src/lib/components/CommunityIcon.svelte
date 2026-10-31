<script lang="ts">
	// 공동체 이름 앞 선 아이콘: 뿌리(땅 아래로 뻗은 뿌리) · 줄기(새싹 줄기와 잎) · 열매(잎 달린 열매)
	import { registry } from '#lib/registry/store.svelte.ts';
	let { name }: { name: string } = $props();
	// 이름을 바꿔도 같은 아이콘이 나오도록 처음 이름으로 고름
	const key = $derived(registry.communityKey(name));
</script>

<svg class="comm-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
	{#if key.startsWith('뿌리')}<path d="M12 3v9M4 12h16" /><path d="M12 12v8M12 14c-1 2.5-3 4-5.5 5M12 14c1 2.5 3 4 5.5 5M9 12.5c-.5 1.5-1.8 2.5-3.5 3M15 12.5c.5 1.5 1.8 2.5 3.5 3" />
	{:else if key.startsWith('줄기')}<path d="M12 21V8" /><path d="M12 14c-3.5 0-6-2.2-6-5.5 3.5 0 6 2.2 6 5.5zM12 10c0-3.3 2.5-5.5 6-5.5 0 3.3-2.5 5.5-6 5.5z" />
	{:else if key.startsWith('열매')}<path d="M12 8c-2.2-1.5-6.5-1-6.5 4 0 4.5 3.5 9.5 6.5 8.5 3 1 6.5-4 6.5-8.5 0-5-4.3-5.5-6.5-4z" /><path d="M12 8V4.5M12 6c1-1.8 3-2.6 4.5-2.2-.4 1.7-2.6 2.6-4.5 2.2z" />
	{:else if name !== '미지정'}<!-- 새로 만든 공동체: 둘러앉은 사람들 --><circle cx="12" cy="7" r="2.5" /><circle cx="6" cy="15" r="2.5" /><circle cx="18" cy="15" r="2.5" /><path d="M9.5 9.5 7.5 12.5M14.5 9.5l2 3M8.8 16h6.4" />
	{:else}<!-- 미지정: 점선 동그라미 안의 물음표 --><circle cx="12" cy="12" r="9" stroke-dasharray="3 3" /><path d="M9.8 9.5a2.3 2.3 0 114.1 1.4c-.7.8-1.9 1.2-1.9 2.4M12 16.5v.01" />{/if}
</svg>

<style>
	.comm-ico { width: 1.15em; height: 1.15em; vertical-align: -0.18em; margin-right: 0.3rem; color: var(--teal-600); }
</style>
