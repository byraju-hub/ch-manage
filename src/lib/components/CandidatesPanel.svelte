<script lang="ts">
	import { hrefTo } from '#lib/registry/paths.ts';
	// 관리자 › 프로그램 설정 › 확인 목록: 가입을 승인해 쓰고 있는데 교적의 교인과 아직 연결되지 않은 사용자.
	// 같은 이름·전화의 교인이 있으면 '연결' 후보로 보여 줍니다. (최고관리자만 연결할 수 있음)
	import { registry } from '#lib/registry/store.svelte.ts';
	import type { AppUser, Person } from '#lib/registry/types.ts';

	const digits = (v: string | null | undefined) => (v ?? '').replace(/\D/g, '');
	const list = $derived(
		registry.users
			.filter((u) => u.status === '사용' && !u.person_id)
			.map((u) => {
				const sameName = registry.d.people.filter((p) => p.name === u.name);
				const phone = digits(u.phone);
				const exact = phone ? sameName.find((p) => digits(p.phone) === phone) : undefined;
				const match = exact ?? sameName[0];
				const reason = exact ? '같은 이름·전화의 교적이 있음' : match ? '같은 이름의 교적이 있음 (전화는 다르거나 없음)' : '교적에 없는 사람';
				return { u, match, reason };
			})
	);
	let msg = $state('');
	async function link(u: AppUser, p: Person) {
		await registry.linkUserPerson(u.id, p.id);
		msg = registry.opError || `${u.name}님을 ${p.name} 교적과 연결했습니다.`;
	}
</script>

<p class="muted">가입을 승인했지만 교적의 교인과 연결되지 않은 사용자입니다. 교인과 연결해 두면 앱 가입 여부가 교적에 표시됩니다.</p>
{#if msg}<div class="ok">{msg}</div>{/if}
<div class="card" style="padding:0">
	<table>
		<thead><tr><th>사용자</th><th>전화</th><th>사유</th><th>기존 교적</th><th></th></tr></thead>
		<tbody>
			{#each list as { u, match, reason } (u.id)}
				<tr>
					<td>{u.name}<br /><small class="muted">{u.login_id}</small></td><td>{u.phone ?? '-'}</td><td>{reason}</td>
					<td>{#if match}<a href={hrefTo(`/${match.id}`)}>{match.name} 교적 보기</a>{:else}-{/if}</td>
					<td class="row">
						{#if !registry.isSuper}<span class="muted">최고관리자만</span>
						{:else if match}<button onclick={() => link(u, match)}>이 교적과 연결</button>
						{:else}<a class="btn ghost sm" href={hrefTo('/new')}>교적 등록하러 가기</a>{/if}
					</td>
				</tr>
			{:else}
				<tr><td colspan="5" class="muted">확인할 사람이 없습니다.</td></tr>
			{/each}
		</tbody>
	</table>
</div>
