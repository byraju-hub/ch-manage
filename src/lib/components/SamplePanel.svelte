<script lang="ts">
	// 샘플 데이터 안내 (최고관리자에게만 보임): 비어 있으면 '샘플 불러오기', 샘플이 들어 있으면 '샘플 삭제'
	import { onMount } from 'svelte';
	import { registry } from '#lib/registry/store.svelte.ts';

	let status = $state<{ people: number; samplePeople: number } | null>(null);
	let busy = $state(false);
	let msg = $state('');

	async function refresh() { status = await registry.sampleStatus(); }
	onMount(refresh);

	async function load() {
		if (!confirm('체험용 가상 교인·출석·심방 자료를 넣습니다. 몇십 초 걸릴 수 있어요.\n나중에 설정에서 한 번에 지울 수 있고, 직접 입력한 자료는 지워지지 않습니다.')) return;
		busy = true; msg = '';
		msg = (await registry.loadSample()) || '샘플 데이터를 넣었습니다.';
		await refresh();
		busy = false;
	}
	async function remove() {
		if (!confirm('샘플로 넣은 자료를 모두 지웁니다. 직접 입력한 자료는 그대로 남습니다.')) return;
		busy = true; msg = '';
		msg = (await registry.deleteSample()) || '샘플 데이터를 지웠습니다.';
		await refresh();
		busy = false;
	}
</script>

{#if registry.isSuper && status && (status.people === 0 || status.samplePeople > 0 || msg)}
	<section class="card sample">
		{#if status.people === 0}
			<b>아직 교인이 없습니다.</b>
			<p class="muted">교인을 직접 등록하거나, 체험용 샘플 데이터로 먼저 둘러볼 수 있어요.</p>
			<button type="button" onclick={load} disabled={busy}>{busy ? '넣는 중…' : '샘플 데이터 불러오기'}</button>
		{:else if status.samplePeople > 0}
			<b>체험용 샘플 데이터가 들어 있습니다. ({status.samplePeople}명)</b>
			<p class="muted">실제 교인을 등록하기 전에 샘플을 지우세요. 직접 입력한 자료는 지워지지 않습니다.</p>
			<button type="button" class="ghost" onclick={remove} disabled={busy}>{busy ? '지우는 중…' : '샘플 데이터 삭제'}</button>
		{/if}
		{#if msg}<p class="muted" style="margin-top:.5rem">{msg}</p>{/if}
	</section>
{/if}

<style>
	.sample { margin-bottom: 1rem; }
	.sample p { margin: 0.3rem 0 0.7rem; }
</style>
