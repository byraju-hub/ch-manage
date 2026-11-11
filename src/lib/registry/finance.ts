// 재정 관리(fin-manage)로 넘어가기. 서버에서 30초짜리 1회용 입장권을 받아, 같은 도메인의 형제 폴더(기본 /fm)의 /sso 로 보낸다.
// 비밀번호나 세션을 넘기지 않는다. 입장권이 거절되면 재정 쪽의 로그인 창이 그대로 열린다.
import { auth } from './auth.svelte.ts';
import { hrefTo } from './paths.ts';

/** 이 사이트 폴더의 부모 주소. 사이트가 /cm 이면 '' (맨 위), /church/cm 이면 '/church' */
function parentBase(): string {
	const root = hrefTo('/').replace(/\/$/, '');
	return root.slice(0, root.lastIndexOf('/'));
}

/** 재정 관리를 연다. 실패하면 오류 문장을 돌려주고, 성공하면 이 창이 재정으로 넘어간다. */
export async function openFinance(): Promise<string | null> {
	try {
		const r = await auth.api<{ ticket: string; path: string }>('POST', '/auth/sso-ticket');
		window.location.assign(`${parentBase()}/${r.path}/sso?t=${encodeURIComponent(r.ticket)}`);
		return null;
	} catch (e) {
		return e instanceof Error ? e.message : '재정 관리를 열지 못했습니다.';
	}
}
