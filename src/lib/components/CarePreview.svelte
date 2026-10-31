<script lang="ts">
	// 심방 등록: 내용에 날짜가 적힌 일(입원·수술·자녀 입대 등)을 미리 보여 줌. 등록하면 캘린더 '성도' 일정으로 들어감
	import { registry } from '#lib/registry/store.svelte.ts';
	import { extractCareEvents } from '#lib/registry/care-events.ts';
	import { KIND_COLOR } from '#lib/registry/calendar.ts';

	let { text, base, person }: { text: string; base: string; person: string } = $props();
	const found = $derived(base && person ? registry.resolveCare(person, extractCareEvents(text, base)) : []);
	const md = (iso: string) => `${+iso.slice(5, 7)}/${+iso.slice(8, 10)}`;
</script>

{#if found.length}
	<p class="care-pv">
		<i class="dot" style="background:{KIND_COLOR['성도']}"></i>
		등록하면 캘린더 <b>성도</b> 일정에 함께 들어갑니다:
		{#each found as e, i}<span class="ce">{md(e.date)}{e.end_date ? `~${md(e.end_date)}` : ''} {e.label}</span>{i < found.length - 1 ? ' ' : ''}{/each}
	</p>
{/if}

<style>
	.care-pv { margin: 0.35rem 0 0; font-size: 0.8rem; font-weight: 400; color: var(--muted); display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem; }
	.care-pv .dot { width: 8px; height: 8px; }
	.care-pv b { color: var(--text); }
	.ce { padding: 0.08rem 0.5rem; border-radius: 999px; background: color-mix(in srgb, #c04aa0 12%, #fff); color: #8a2f74; font-weight: 600; }
</style>
