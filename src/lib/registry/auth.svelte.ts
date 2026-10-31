// 로그인·가입·회원 관리: 서버(PHP, /api/v1)와 이야기하는 부분. (ch-manage 전용 파일)
// 권한은 서버가 계산해서 내려 주고, 화면은 그 값을 보여 주기만 합니다. 화면의 확인은 편의일 뿐이고,
// 서버는 모든 요청에서 로그인·등급·CSRF 를 다시 확인합니다.
import { hrefTo } from './paths.ts';
import type { AppUser, Grade, GradeDef, Perms } from './types.ts';

export class ApiError extends Error {
	constructor(public code: string, message: string, public status: number) {
		super(message);
	}
}

const NO_PERMS: Perms = { edit: false, export: false, visit: false, admin: false };
const apiBase = () => hrefTo('/api/v1'); // 서브폴더 설치도 맞도록 호출할 때 계산

interface MePayload {
	church?: string; // 교회 이름 (로그인 전 화면에 보임)
	demoAccount?: boolean; // 체험 계정(demo)이 켜져 있는지
	user: AppUser | null;
	perms?: Perms;
	grades?: GradeDef[];
	csrf?: string;
}

class Auth {
	ready = $state(false); // 처음 로그인 상태를 서버에 물어본 뒤 true
	notInstalled = $state(false);
	churchName = $state(''); // 교회 이름: 로그인하기 전에도 서버가 알려 줌
	netError = $state('');
	demoAccount = $state(false); // 로그인 화면에 체험 계정 안내를 보일지
	user = $state<AppUser | null>(null);
	perms = $state<Perms>(NO_PERMS);
	grades = $state<GradeDef[]>([]);
	users = $state<AppUser[]>([]); // 최고관리자만 받아 옴
	opError = $state(''); // 회원 관리 중 생긴 오류 (관리자 화면에 보임)
	private csrf = '';

	/** 서버 호출. 실패하면 ApiError. 로그인이 풀렸으면(401) 화면도 로그아웃 상태로 */
	async api<T = unknown>(method: string, path: string, body?: unknown): Promise<T> {
		let res: Response;
		try {
			res = await fetch(apiBase() + path, {
				method,
				credentials: 'same-origin',
				headers: { ...(body !== undefined ? { 'content-type': 'application/json' } : {}), ...(this.csrf ? { 'x-csrf-token': this.csrf } : {}) },
				body: body === undefined ? undefined : JSON.stringify(body)
			});
		} catch {
			throw new ApiError('network', '서버에 연결하지 못했습니다. 인터넷 연결을 확인해 주세요.', 0);
		}
		let j: { ok?: boolean; data?: T; error?: { code: string; message: string } } | null = null;
		let raw = '';
		try { raw = await res.text(); j = JSON.parse(raw); } catch { /* JSON 이 아닌 응답: 호스팅의 보안 장치나 서버 오류 */ }
		if (!j || !j.ok) {
			const code = j?.error?.code ?? 'server_error';
			if (res.status === 401 && !path.startsWith('/auth/')) this.clear();
			// 서버가 보낸 문장이 없으면(호스팅이 먼저 막은 경우 등) 번호를 보여 줘서 원인을 찾기 쉽게 한다
			const why = res.status === 413 || res.status === 404 && path === '/sync' ? ' (한 번에 보내는 양이 호스팅 한도를 넘었을 수 있습니다)' : '';
			throw new ApiError(code, j?.error?.message ?? `서버 응답을 읽지 못했습니다. (HTTP ${res.status})${why}`, res.status);
		}
		return j.data as T;
	}

	private clear() {
		this.user = null;
		this.perms = NO_PERMS;
		this.users = [];
	}
	private apply(d: MePayload) {
		this.user = d.user;
		if (d.church !== undefined) this.churchName = d.church;
		if (d.demoAccount !== undefined) this.demoAccount = d.demoAccount;
		this.perms = d.perms ?? NO_PERMS;
		if (d.grades) this.grades = d.grades;
		if (d.csrf) this.csrf = d.csrf;
		if (!d.user) this.users = [];
	}

	async init() {
		try {
			const d = await this.api<MePayload>('GET', '/auth/me');
			this.apply(d);
			if (this.user?.is_super && !this.user.is_demo) await this.loadUsers();
		} catch (e) {
			if (e instanceof ApiError && e.code === 'not_installed') this.notInstalled = true;
			else this.netError = e instanceof Error ? e.message : '서버에 연결하지 못했습니다.';
		} finally {
			this.ready = true;
		}
	}

	async login(loginId: string, password: string) {
		this.apply(await this.api<MePayload>('POST', '/auth/login', { loginId, password }));
		if (this.user?.is_super && !this.user.is_demo) await this.loadUsers();
	}

	/** 체험 계정 켜기·끄기 (최고관리자). 아이디 demo / 비밀번호 1234, 보기만 가능 */
	async setDemoAccount(on: boolean) {
		if (on) await this.api('POST', '/system/demo-account');
		else await this.api('DELETE', '/system/demo-account');
		this.demoAccount = on;
		await this.loadUsers();
	}

	async logout() {
		try { this.apply({ user: null, ...(await this.api<{ csrf: string }>('POST', '/auth/logout')), church: this.churchName }); } catch { this.clear(); }
	}

	/** 가입 신청: 최고관리자가 승인하기 전에는 로그인할 수 없다 */
	async signup(f: { loginId: string; name: string; password: string; email: string; phone: string }) {
		await this.api('POST', '/auth/signup', f);
	}

	async changePassword(current: string, next: string) {
		await this.api('POST', '/auth/password', { current, next });
		const d = await this.api<MePayload>('GET', '/auth/me'); // 임시 비밀번호 표시를 지운 상태로 다시 받음
		this.apply(d);
		if (this.user?.is_super && !this.user.is_demo) await this.loadUsers();
	}

	// ---- 회원 관리 (최고관리자) ----
	async loadUsers() {
		const d = await this.api<{ users: AppUser[]; grades: GradeDef[] }>('GET', '/users');
		this.users = d.users;
		this.grades = d.grades;
	}

	/** 승인·등급·정지·최고관리자·교인 연결. 오류는 opError 에 담아 관리자 화면에 보여 줌 */
	async userAction(id: string, action: 'approve' | 'grade' | 'status' | 'super' | 'person', body: Record<string, unknown>) {
		this.opError = '';
		try {
			const d = await this.api<{ users: AppUser[] }>('POST', `/users/${id}/${action}`, body);
			this.users = d.users;
		} catch (e) {
			this.opError = e instanceof Error ? e.message : '처리하지 못했습니다.';
			await this.loadUsers().catch(() => {}); // 화면을 서버 상태에 다시 맞춤 (체크박스 등)
		}
	}
	async removeUser(id: string) {
		this.opError = '';
		try {
			this.users = (await this.api<{ users: AppUser[] }>('DELETE', `/users/${id}`)).users;
		} catch (e) {
			this.opError = e instanceof Error ? e.message : '처리하지 못했습니다.';
		}
	}
	/** 임시 비밀번호를 만들어 돌려줌 (사용자는 다음 로그인 때 바꿔야 함). 실패하면 null */
	async resetPassword(id: string): Promise<string | null> {
		this.opError = '';
		try {
			return (await this.api<{ tempPassword: string }>('POST', `/users/${id}/reset-password`)).tempPassword;
		} catch (e) {
			this.opError = e instanceof Error ? e.message : '처리하지 못했습니다.';
			return null;
		}
	}
	async gradeAction(grade: Grade, body: { name: string } | { perm: 'edit' | 'export' | 'admin'; value: boolean }) {
		this.opError = '';
		try {
			this.grades = (await this.api<{ grades: GradeDef[] }>('POST', `/grades/${grade}`, body)).grades;
		} catch (e) {
			this.opError = e instanceof Error ? e.message : '처리하지 못했습니다.';
			await this.loadUsers().catch(() => {});
		}
	}
}

export const auth = new Auth();
