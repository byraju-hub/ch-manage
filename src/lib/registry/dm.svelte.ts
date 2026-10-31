// 부서 관리(dep-manage)와의 연동 상태. (ch-manage 전용 파일)
// 두 프로그램이 같은 DB 에 설치되어 있고 연결된 학생이 있으면, 연결된 학생의 출석을 부서 관리에서 읽어 온다.
// 부서 관리가 없으면 available 이 false 라서 화면에는 아무것도 나타나지 않는다.
import { auth } from './auth.svelte.ts';

export interface DmLinkInfo {
	kind: 'student' | 'teacher';
	dept?: string; // 학생일 때 부서 관리의 부서
	grade?: string | null;
	teacherDept?: string; // 교사로도 연결되어 있으면 그 부서
}

class Dm {
	available = $state(false);
	reason = $state(''); // not_installed · incompatible · same_prefix
	prefix = $state('dm_');
	linkCount = $state(0);
	/** 교인 id → 부서 관리 연결 정보 */
	links = $state<Record<string, DmLinkInfo>>({});
	/** 주일 날짜 → 교인 id → 출석했는가 (부서 관리의 출석 기록을 주일로 모은 것) */
	att = $state<Record<string, Record<string, boolean>>>({});

	clear() {
		this.available = false;
		this.reason = '';
		this.linkCount = 0;
		this.links = {};
		this.att = {};
	}

	/** 상태와 연결된 학생의 최근 출석을 다시 받는다. 부서 관리가 없거나 오류가 나도 교적은 그대로 쓴다. */
	async refresh() {
		try {
			const s = await auth.api<{ available: boolean; reason?: string; prefix: string; links: number }>('GET', '/dm/status');
			this.available = s.available;
			this.reason = s.reason ?? '';
			this.prefix = s.prefix;
			this.linkCount = s.links;
			if (!s.available || s.links === 0) { this.links = {}; this.att = {}; return; }
			const o = await auth.api<{ links: Record<string, DmLinkInfo>; attendance: [string, string, 0 | 1][] }>('GET', '/dm/overview?weeks=26');
			const att: Record<string, Record<string, boolean>> = {};
			for (const [pid, sunday, ok] of o.attendance) (att[sunday] ??= {})[pid] = ok === 1;
			this.links = o.links;
			this.att = att;
		} catch {
			// 연동을 못 읽어도 교적은 그대로 쓴다 (다음 확인 때 다시 시도)
		}
	}
}

export const dm = new Dm();
