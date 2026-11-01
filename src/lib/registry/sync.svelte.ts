// 바뀐 칸만 서버에 저장하고, 다른 사람이 저장했는지 가끔 확인하는 엔진. (ch-manage 전용 파일)
//
//  - 화면은 지금까지처럼 registry.d 를 고치고 save() 를 부른다. save() 는 여기의 schedule() 로 이어진다.
//  - schedule() 이 0.4초 모아서 flush() 하면, 서버와 맞춰 둔 마지막 상태(shadow)와 지금 상태를 줄·칸 단위로 비교해
//    바뀐 것만 /sync 로 보낸다. 서버는 권한과 값을 확인하고 한 묶음을 전부 저장하거나 전부 거절한다.
//  - 거절되면 안내를 띄우고 서버의 값으로 화면을 다시 맞춘다(저장되지 않은 변경은 버려진다).
//  - 1분마다, 그리고 창으로 돌아왔을 때 서버 버전을 물어보고 달라졌으면 다시 받는다.
import { auth, ApiError } from './auth.svelte.ts';
import { CODECS, type Row } from './tables.ts';
import type { Data } from './store.svelte.ts';
import { hrefTo } from './paths.ts';

type Ser = Record<string, string>; // 칸 이름 → 값을 글자로 바꾼 것 (바뀌었는지 비교용)
interface Op { t: string; id: string; set?: Record<string, unknown>; del?: true; sample?: true }
interface Pending { op: Op; ser: Ser | null; level: number; size: number }

export interface SyncHost {
	data(): Data;
	reload(): Promise<void>;
	setSaveError(msg: string): void;
	/** 1분마다 함께 확인할 것 (부서 관리의 출석 등, 교적 자료의 버전과 따로 바뀌는 것) */
	refreshExtras?(): Promise<void>;
	/** 서버가 사진·로고를 파일로 저장한 뒤 돌려준 주소로 화면 데이터를 바꿈 */
	applyFileUrl(t: string, id: string, field: string, sent: unknown, url: string): void;
}

const ser = (v: unknown) => (v === undefined ? 'null' : JSON.stringify(v));
const serRow = (r: Row, skip: string[]): Ser => {
	const o: Ser = {};
	for (const [k, v] of Object.entries(r)) if (k !== 'id' && !skip.includes(k)) o[k] = ser(v);
	return o;
};

const MAX_OPS = 400; // 한 번에 보내는 최대 줄 수
// 한 번에 보내는 최대 크기(바이트). 카페24 앞단은 요청 본문이 128KB 를 넘으면 서버에 닿기 전에 404 로 막는다. 그래서 여유를 두고 100KB.
const MAX_BYTES = 100_000;
const MAX_ONE = 120_000; // 한 줄(사진 등)이 이보다 크면 보낼 수 없다
const enc = new TextEncoder();
const bytesOf = (s: string) => enc.encode(s).length; // 한글은 글자당 3바이트라 글자 수가 아니라 바이트로 잰다

export class Sync {
	version = 0;
	status = $state<'idle' | 'saving' | 'error'>('idle');
	/** 샘플 데이터를 넣을 때: 새 줄에 샘플 표시를 붙인다 */
	sampleMode = false;
	private shadow = new Map<string, Map<string, Ser>>();
	private host!: SyncHost;
	private timer: ReturnType<typeof setTimeout> | null = null;
	private pollTimer: ReturnType<typeof setInterval> | null = null;
	private inflight = false;
	private dirty = false;
	private refreshWanted = false;
	private onVisible = () => { if (document.visibilityState === 'visible') void this.check(); };
	private onUnload = (e: BeforeUnloadEvent) => { if (this.hasPending()) { e.preventDefault(); e.returnValue = ''; } };

	attach(host: SyncHost) { this.host = host; }

	/** 서버에서 받은 상태를 '서버와 같은 상태'로 기록한다 */
	baseline(d: Data, version: number) {
		this.shadow = new Map();
		for (const c of CODECS) {
			const m = new Map<string, Ser>();
			for (const r of c.rows(d)) m.set(r.id, serRow(r, c.skip));
			this.shadow.set(c.name, m);
		}
		this.version = version;
		this.dirty = false;
	}

	hasPending() { return this.inflight || this.dirty || this.timer !== null; }

	start() {
		this.stop();
		this.pollTimer = setInterval(() => void this.check(), 60_000);
		document.addEventListener('visibilitychange', this.onVisible);
		window.addEventListener('beforeunload', this.onUnload);
	}
	stop() {
		if (this.pollTimer) clearInterval(this.pollTimer);
		if (this.timer) clearTimeout(this.timer);
		this.pollTimer = this.timer = null;
		document.removeEventListener('visibilitychange', this.onVisible);
		window.removeEventListener('beforeunload', this.onUnload);
		this.dirty = false;
		this.status = 'idle';
	}

	schedule() {
		this.dirty = true;
		if (this.timer) clearTimeout(this.timer);
		this.timer = setTimeout(() => { this.timer = null; void this.flush(); }, 400);
	}

	/** 지금 바로 저장 (샘플 넣기 등) */
	async flushNow() {
		if (this.timer) { clearTimeout(this.timer); this.timer = null; }
		await this.flush();
	}

	/** 다른 사람이 저장했으면 다시 받는다. 내가 저장 중이거나 저장 대기 중이면 건너뜀 */
	async check() {
		if (this.hasPending()) return;
		try {
			const v = (await auth.api<{ version: number }>('GET', '/version')).version;
			if (v !== this.version && !this.hasPending()) await this.host.reload();
			await this.host.refreshExtras?.();
		} catch { /* 연결이 잠깐 끊긴 것: 다음에 다시 확인 */ }
	}

	/** 서버와 맞춰 둔 상태와 지금 상태를 비교해서 바뀐 것만 만든다 */
	private diff(snap: Data): Pending[] {
		const out: Pending[] = [];
		for (const c of CODECS) {
			const old = this.shadow.get(c.name) ?? new Map<string, Ser>();
			const seen = new Set<string>();
			for (const r of c.rows(snap)) {
				seen.add(r.id);
				const cur = serRow(r, c.skip);
				const o = old.get(r.id);
				let set: Record<string, unknown> | null = null;
				if (!o) {
					set = {};
					for (const [k, v] of Object.entries(r)) if (k !== 'id' && !c.skip.includes(k)) set[k] = v === undefined ? null : v;
				} else if (!c.append) {
					for (const k of Object.keys(cur)) if (cur[k] !== o[k]) (set ??= {})[k] = r[k] === undefined ? null : r[k];
					for (const k of Object.keys(o)) if (!(k in cur)) (set ??= {})[k] = null; // 칸이 사라짐 = 비움
				}
				if (set) {
					const op: Op = { t: c.name, id: r.id, set };
					if (!o && this.sampleMode) op.sample = true;
					out.push({ op, ser: cur, level: c.level, size: bytesOf(JSON.stringify(op)) });
				}
			}
			if (!c.append) for (const id of old.keys()) if (!seen.has(id)) out.push({ op: { t: c.name, id, del: true }, ser: null, level: c.level, size: 60 });
		}
		// 지우기는 딸린 표부터, 저장은 먼저 만들어져야 하는 표부터
		return [
			...out.filter((p) => p.op.del).sort((a, b) => b.level - a.level),
			...out.filter((p) => !p.op.del).sort((a, b) => a.level - b.level)
		];
	}

	private async flush() {
		if (auth.user?.is_demo) { this.dirty = false; return; } // 체험 계정은 저장하지 않음 (화면에서만 바뀜)
		if (this.inflight) { this.dirty = true; return; }
		const pend = this.diff($state.snapshot(this.host.data()) as Data);
		if (!pend.length) { this.dirty = false; if (this.status === 'saving') this.status = 'idle'; return; }
		this.inflight = true;
		this.dirty = false;
		this.status = 'saving';
		try {
			let chunk: Pending[] = [];
			let bytes = 0;
			const send = async () => {
				if (!chunk.length) return;
				const batch = chunk;
				chunk = []; bytes = 0;
				const res = await auth.api<{ version: number; prev: number; files: Record<string, string> }>('POST', '/sync', { ops: batch.map((p) => p.op) });
				if (res.prev !== this.version) this.refreshWanted = true; // 그 사이 다른 사람도 저장했음
				this.version = res.version;
				for (const [key, url] of Object.entries(res.files ?? {})) {
					const [t, id, field] = key.split('|');
					const p = batch.find((x) => x.op.t === t && x.op.id === id);
					const sent = p?.op.set?.[field === 'logo' || field === 'seal' || field === 'favicon' ? 'churchInfo' : field];
					this.host.applyFileUrl(t, id, field, sent, url);
					if (p?.ser) {
						// 파일로 저장된 칸은 서버가 준 주소를 '서버와 같은 값'으로 기록한다
						if (t === 'people') p.ser[field] = ser(hrefTo('/api/v1' + url));
					}
				}
				for (const p of batch) {
					const m = this.shadow.get(p.op.t) ?? new Map<string, Ser>();
					if (p.ser) m.set(p.op.id, p.ser); else m.delete(p.op.id);
					this.shadow.set(p.op.t, m);
				}
			};
			for (const p of pend) {
				if (p.size > MAX_ONE) throw new ApiError('too_large', `한 번에 저장할 수 있는 크기를 넘었습니다. (${p.op.t}) 사진이나 그림이라면 더 작은 파일로 올려 주세요.`, 0);
				if (chunk.length >= MAX_OPS || (chunk.length && bytes + p.size > MAX_BYTES)) await send();
				chunk.push(p); bytes += p.size;
			}
			await send();
			this.host.setSaveError('');
			this.status = 'idle';
		} catch (e) {
			this.status = 'error';
			const msg = e instanceof ApiError ? e.message : '저장하지 못했습니다.';
			this.inflight = false;
			// 저장되지 않은 변경을 버리고 서버의 값으로 되돌린다
			if (!(e instanceof ApiError && e.status === 401)) {
				try { await this.host.reload(); } catch { /* 연결 문제: 화면은 그대로 둠 */ }
			}
			this.host.setSaveError(`저장하지 못했습니다: ${msg} 방금 고친 내용은 반영되지 않았습니다. 화면을 서버의 값으로 되돌렸습니다.`);
			return;
		}
		this.inflight = false;
		if (this.dirty) { await this.flush(); return; }
		if (this.refreshWanted) { this.refreshWanted = false; await this.host.reload(); }
	}
}

export const sync = new Sync();
