<script lang="ts">
	import { auth } from '#lib/registry/auth.svelte.ts';
	// 문자 보내기 창: 교인 목록의 검색 결과에게 SMS·LMS 발송 (서버: /api/sms, 솔라피)
	//  - 문자 수신 거부 · 휴대폰 번호 없음 · 같은 번호(가족이 한 번호를 쓰는 경우)는 자동으로 빼고 보여 줌
	//  - {이름} · {직분} 을 넣으면 받는 사람마다 이름 · 직분(구분 없이, 없으면 '성도')으로 바뀜
	import { registry } from '#lib/registry/store.svelte.ts';
	import type { Person } from '#lib/registry/types.ts';

	let { people, onclose }: { people: Person[]; onclose: () => void } = $props();

	const digits = (s: string) => s.replace(/\D/g, '');
	const isMobile = (n: string) => /^01[016789]\d{7,8}$/.test(n);
	const bytes = (s: string) => [...s].reduce((n, ch) => n + (ch.charCodeAt(0) < 128 ? 1 : 2), 0);
	const SMS_MAX = 90, LMS_MAX = 2000;
	const PRICE = { SMS: 20, LMS: 50 }; // 대략의 건당 요금(원). 실제 요금은 솔라피 요금제를 따름

	// 받는 사람 / 빠지는 사람
	const split = $derived.by(() => {
		const to: Person[] = [], optOut: Person[] = [], noPhone: Person[] = [], dup: Person[] = [];
		const seen = new Set<string>();
		for (const p of people) {
			const n = digits(p.phone ?? '');
			if (p.sms_opt_out) optOut.push(p);
			else if (!isMobile(n)) noPhone.push(p);
			else if (seen.has(n)) dup.push(p);
			else { seen.add(n); to.push(p); }
		}
		return { to, optOut, noPhone, dup };
	});
	const excluded = $derived(split.optOut.length + split.noPhone.length + split.dup.length);

	let text = $state('');

	// ---- 자주 쓰는 문구: 골라서 [ ] 칸만 고쳐 보냄. 지금 내용을 새 문구로 저장하거나 고쳐 저장·삭제 ----
	let tplId = $state('');
	let tplManage = $state(false);
	let tplTitle = $state('');
	const tplSel = $derived(registry.smsTemplates.find((t) => t.id === tplId));
	function pickTpl(id: string) {
		const t = registry.smsTemplates.find((x) => x.id === id);
		if (!t) return;
		if (text.trim() && text !== tplSel?.body && !confirm(`지금 쓴 내용을 '${t.title}' 문구로 바꿉니다.`)) return;
		tplId = id;
		tplTitle = t.title;
		text = t.body;
	}
	function saveTpl(asNew: boolean) {
		if (!text.trim()) return;
		tplId = registry.saveSmsTemplate({ id: asNew ? undefined : tplId, title: tplTitle || '새 문구', body: text });
		tplTitle = tplSel?.title ?? tplTitle;
	}
	function deleteTpl() {
		if (!tplSel || !confirm(`'${tplSel.title}' 문구를 지웁니다.`)) return;
		registry.deleteSmsTemplate(tplSel.id);
		tplId = '';
		tplTitle = '';
	}
	// 아직 고치지 않은 [ ] 칸 (예: [날짜], [장례식장])
	const blanks = $derived([...new Set(text.match(/\[[^\]\n]{1,20}\]/g) ?? [])]);
	let subject = $state('');
	let ta = $state<HTMLTextAreaElement>();
	// {직분}: 구분 없이 직분만 (은퇴권사 → 권사, 서리집사·시무집사 → 집사). 직분이 없으면 '성도'
	const officeOf = (p: Person) => {
		const o = registry.summary(p).office?.office;
		return !o ? '성도' : o === '서리집사' || o === '시무집사' ? '집사' : o;
	};
	const fill = (p: Person) => text.replaceAll('{이름}', p.name).replaceAll('{직분}', officeOf(p));
	// 가장 긴 사람 기준으로 길이를 셈 ({이름} 이 들어가면 이름 길이에 따라 달라짐)
	const maxBytes = $derived(split.to.length ? Math.max(...split.to.map((p) => bytes(fill(p)))) : bytes(text));
	const kind = $derived<'SMS' | 'LMS'>(maxBytes > SMS_MAX ? 'LMS' : 'SMS');
	const tooLong = $derived(maxBytes > LMS_MAX);
	const cost = $derived(split.to.length * PRICE[kind]);
	const tooMany = $derived(split.to.length > 1000); // 서버 한도: 한 번에 1,000명
	// 커서 자리에 {이름} · {직분} 넣기
	function insert(tag: string) {
		const el = ta;
		if (!el) { text += tag; return; }
		const a = el.selectionStart, b = el.selectionEnd;
		text = text.slice(0, a) + tag + text.slice(b);
		requestAnimationFrame(() => { el.focus(); el.selectionStart = el.selectionEnd = a + tag.length; });
	}

	let showOut = $state(false);
	let busy = $state(false);
	let result = $state<{ ok: boolean; msg: string } | null>(null);
	let progress = $state('');
	async function send() {
		if (!text.trim() || !split.to.length || tooLong || tooMany || busy) return;
		if (blanks.length && !confirm(`아직 고치지 않은 칸이 있습니다: ${blanks.join(' ')}\n그대로 보낼까요?`)) return;
		if (!confirm(`${split.to.length}명에게 ${kind === 'SMS' ? '단문' : '장문'} 문자를 보냅니다.\n예상 비용 약 ${cost.toLocaleString()}원\n보낸 뒤에는 취소할 수 없습니다. 보낼까요?`)) return;
		busy = true;
		result = null;
		try {
			// 호스팅이 한 번에 받는 크기(128KB)와 서버의 1회 한도(300건)에 맞춰 나눠서 보낸다.
			const all = split.to.map((p) => ({ to: p.phone, text: fill(p), pid: p.id }));
			const batches: (typeof all)[] = [];
			let cur: typeof all = [], size = 0;
			for (const m of all) {
				const n = JSON.stringify(m).length;
				if (cur.length && (cur.length >= 300 || size + n > 70_000)) { batches.push(cur); cur = []; size = 0; }
				cur.push(m); size += n;
			}
			if (cur.length) batches.push(cur);
			let sent = 0, failed = 0, skipped = 0;
			try {
				for (let i = 0; i < batches.length; i++) {
					progress = batches.length > 1 ? `${i + 1}/${batches.length} 묶음 보내는 중…` : '';
					const d = await auth.api<{ sent: number; failed: number; skipped: number }>('POST', '/sms/send', { subject, messages: batches[i] });
					sent += d.sent; failed += d.failed; skipped += d.skipped;
				}
			} catch (e) {
				const done = sent + failed + skipped;
				throw new Error((e instanceof Error ? e.message : '발송 중 오류가 났습니다.') + (done ? ` (앞의 ${sent}명에게는 이미 보냈습니다. 같은 문자를 다시 보내지 않도록 확인해 주세요.)` : ''));
			} finally { progress = ''; }
			result = { ok: true, msg: `${sent}명에게 보냈습니다.${failed ? ` (실패 ${failed}명)` : ''}${skipped ? ` (수신 거부·중복 ${skipped}명 제외)` : ''}` };
		} catch (e) {
			result = { ok: false, msg: e instanceof Error ? e.message : '발송 중 오류가 났습니다.' };
		} finally {
			busy = false;
		}
	}
</script>

<div class="back" role="presentation" onclick={onclose}></div>
<div class="dlg card" role="dialog" aria-modal="true" aria-label="문자 보내기">
	<div class="head">
		<span class="ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5h16a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5H10l-4.5 3.5V17.5H4A1.5 1.5 0 012.5 16V7A1.5 1.5 0 014 5.5z" /><circle cx="8" cy="11.5" r="1.1" /><circle cx="12" cy="11.5" r="1.1" /><circle cx="16" cy="11.5" r="1.1" /></svg></span>
		<div><h3>문자 보내기</h3><p class="muted">검색 결과 {people.length}명 중 <b class="to">{split.to.length}명</b>에게 보냅니다{excluded ? ` · ${excluded}명 제외` : ''}</p></div>
		<button type="button" class="ghost sm x" onclick={onclose} aria-label="닫기">✕</button>
	</div>

	{#if excluded}
		<button type="button" class="linkish" onclick={() => (showOut = !showOut)}>{showOut ? '제외된 사람 접기' : '제외된 사람 보기'}</button>
		{#if showOut}
			<div class="out">
				{#if split.optOut.length}<p><b>문자 수신 거부</b> {split.optOut.length}명 — {split.optOut.map((p) => p.name).join(', ')}</p>{/if}
				{#if split.noPhone.length}<p><b>휴대폰 번호 없음</b> {split.noPhone.length}명 — {split.noPhone.map((p) => p.name).join(', ')}</p>{/if}
				{#if split.dup.length}<p><b>같은 번호(한 번만 보냄)</b> {split.dup.length}명 — {split.dup.map((p) => p.name).join(', ')}</p>{/if}
			</div>
		{/if}
	{/if}

	<!-- 자주 쓰는 문구 -->
	<div class="tpl">
		<div class="tpl-top"><span class="tpl-h">자주 쓰는 문구</span><button type="button" class="linkish" onclick={() => { tplManage = !tplManage; tplTitle = tplSel?.title ?? ''; }}>{tplManage ? '관리 닫기' : '문구 저장 · 관리'}</button></div>
		<div class="chips">
			{#each registry.smsTemplates as t (t.id)}<button type="button" class="chip" class:sel={tplId === t.id} onclick={() => pickTpl(t.id)}>{t.title}</button>{/each}
		</div>
		{#if tplManage}
			<div class="tpl-manage">
				<input bind:value={tplTitle} placeholder="문구 이름 (예: 장례 안내)" maxlength="20" aria-label="문구 이름" />
				<button type="button" class="ghost sm" onclick={() => saveTpl(true)} disabled={!text.trim()}>지금 내용을 새 문구로 저장</button>
				{#if tplSel}
					<button type="button" class="ghost sm" onclick={() => saveTpl(false)} disabled={!text.trim()}>'{tplSel.title}' 고쳐 저장</button>
					<button type="button" class="ghost sm danger" onclick={deleteTpl}>'{tplSel.title}' 삭제</button>
				{/if}
			</div>
			<p class="muted" style="font-size:.76rem;margin:.3rem 0 0">문구 안의 <b>[ ]</b>는 보낼 때 고쳐 쓰는 칸이고, <b>{'{이름}'}</b> · <b>{'{직분}'}</b>은 받는 사람마다 자동으로 바뀝니다. 저장한 문구는 A 등급 모두가 함께 씁니다.</p>
		{/if}
	</div>

	<label class="lbl">내용
		<textarea bind:this={ta} bind:value={text} rows="6" placeholder="예) {'{이름}'} {'{직분}'}님, 이번 주일은 추수감사주일입니다. 함께 예배드려요. - {registry.churchInfo.name}"></textarea>
	</label>
	<div class="meta">
		<span class="row" style="gap:.35rem">
			<button type="button" class="ghost sm" onclick={() => insert('{이름}')}>+ 이름 넣기</button>
			<button type="button" class="ghost sm" onclick={() => insert('{직분}')} title="은퇴권사 → 권사처럼 직분만 (직분이 없으면 '성도')">+ 직분 넣기</button>
		</span>
		<span class="len" class:warn-t={tooLong}><b>{kind === 'SMS' ? '단문(SMS)' : '장문(LMS)'}</b> {maxBytes} / {kind === 'SMS' ? SMS_MAX : LMS_MAX} 바이트</span>
	</div>
	{#if blanks.length}<p class="blank-warn">아직 고치지 않은 칸: {#each blanks as b}<span>{b}</span>{/each}</p>{/if}
	{#if kind === 'LMS'}<label class="lbl">제목 (장문일 때, 선택)<input bind:value={subject} maxlength="40" placeholder="예) {registry.churchInfo.name} 안내" /></label>{/if}

	{#if text.trim() && split.to.length}
		<div class="preview"><span class="muted">미리보기 · {split.to[0].name}</span><p>{fill(split.to[0])}</p></div>
	{/if}

	<p class="muted note">예상 비용 약 <b>{cost.toLocaleString()}원</b> ({split.to.length}명 × {PRICE[kind]}원, 실제 요금은 솔라피 요금제에 따름) · 광고성 내용이면 맨 앞에 '(광고)'와 수신 거부 방법을 넣어야 합니다.</p>
	{#if tooMany}<p class="warn" style="margin:.4rem 0">한 번에 1,000명까지 보낼 수 있습니다. 검색 조건으로 대상을 좁혀 주세요.</p>{/if}
	{#if result}<p class={result.ok ? 'ok' : 'warn'} style="margin:.4rem 0">{result.msg}</p>{/if}

	<div class="row" style="justify-content:flex-end">
		<button type="button" class="ghost" onclick={onclose}>{result?.ok ? '닫기' : '취소'}</button>
		<button type="button" class="send" onclick={send} disabled={!text.trim() || !split.to.length || tooLong || tooMany || busy}>{busy ? '보내는 중…' : `${split.to.length}명에게 보내기`}</button>
	</div>
</div>

<style>
	.back { position: fixed; inset: 0; z-index: 70; background: rgba(30, 37, 69, 0.45); }
	.dlg { position: fixed; z-index: 71; left: 50%; top: 50%; transform: translate(-50%, -50%); width: min(520px, calc(100vw - 32px)); max-height: calc(100vh - 40px); overflow: auto; margin: 0; }
	.head { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.6rem; }
	.head h3 { margin: 0; }
	.head p { margin: 0.1rem 0 0; font-size: 0.85rem; }
	.head .x { margin-left: auto; align-self: flex-start; }
	.to { color: var(--primary-600); }
	.ic { flex: none; display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #14b4ae, #4558ae); }
	.ic svg { width: 24px; height: 24px; fill: #fff; }
	.ic circle { fill: #14b4ae; }
	.linkish { background: none; border: 0; padding: 0; color: var(--primary); font-size: 0.82rem; box-shadow: none; }
	.out { margin: 0.4rem 0; padding: 0.6rem 0.8rem; border-radius: 10px; background: #f5f6fb; font-size: 0.82rem; }
	.out p { margin: 0.15rem 0; }
	.tpl { margin-top: 0.6rem; padding: 0.65rem 0.75rem; border-radius: 12px; background: #f5f6fb; }
	.tpl-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem; }
	.tpl-h { font-weight: 700; font-size: 0.85rem; }
	.chips { display: flex; flex-wrap: wrap; gap: 0.35rem; }
	.chip { padding: 0.28rem 0.7rem; border-radius: 999px; font-size: 0.82rem; font-weight: 600; background: #fff; color: var(--text); border: 1px solid var(--line); box-shadow: none; }
	.chip:hover { border-color: #14b4ae; color: #0c847f; transform: none; filter: none; }
	.chip.sel { background: linear-gradient(135deg, #14b4ae, #4558ae); color: #fff; border-color: transparent; }
	.tpl-manage { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; margin-top: 0.55rem; }
	.tpl-manage input { flex: 1 1 10rem; padding: 0.35rem 0.6rem; }
	.danger { color: #c92a2a; border-color: #f1b0b0; }
	.blank-warn { margin: 0.4rem 0 0; font-size: 0.8rem; color: #c24a17; display: flex; flex-wrap: wrap; gap: 0.3rem; align-items: center; }
	.blank-warn span { padding: 0.05rem 0.4rem; border-radius: 6px; background: #fff2eb; }
	.lbl { display: flex; flex-direction: column; gap: 0.3rem; margin-top: 0.7rem; font-weight: 600; font-size: 0.85rem; }
	.lbl textarea { resize: vertical; font-size: 0.95rem; line-height: 1.5; }
	.meta { display: flex; justify-content: space-between; align-items: center; margin-top: 0.4rem; }
	.len { font-size: 0.8rem; color: var(--muted); }
	.len.warn-t { color: #c92a2a; }
	.preview { margin-top: 0.7rem; padding: 0.7rem 0.9rem; border-radius: 14px 14px 14px 4px; background: #e8f7f6; font-size: 0.9rem; }
	.preview span { font-size: 0.75rem; }
	.preview p { margin: 0.2rem 0 0; white-space: pre-wrap; }
	.note { font-size: 0.78rem; margin: 0.7rem 0 0.3rem; }
	.send { background: linear-gradient(135deg, #14b4ae, #4558ae); border: 0; color: #fff; }
	.send:hover:not(:disabled) { filter: brightness(1.05); }
</style>
