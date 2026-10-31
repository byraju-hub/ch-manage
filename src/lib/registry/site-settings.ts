// 관리자 › 교회 설정 › 기본 설정 · 프로그램 설정 › 코드표 · 백업: 교회 기본 정보, 로고·직인, 증명서 양식, 바꿀 수 있는 코드표, 백업 설정
// [실제 시스템] church_settings 표 한 줄(JSON)로 저장. 로고·직인 이미지는 비공개 저장소(관리자만 읽기)에 두고 경로만 저장.
import { MISSION_SOCIETIES, VISIT_KINDS } from './types.ts';

export interface ChurchInfo {
	name: string;
	denomination: string; // 교단
	address: string;
	phone: string;
	fax: string;
	email: string;
	homepage: string;
	founded: string; // 창립일
	bizNo: string; // 고유번호(사업자등록번호) — 기부금 영수증의 기부금 단체 칸에 쓰임
	pastorLabel: string; // 서명 줄의 직함 (담임목사)
	pastorName: string; // 비우면 교적의 '담임목사'를 씀
	logo: string | null; // 올린 로고 (작게 줄인 그림). 없으면 기본 로고
	seal: string | null; // 올린 직인. 없으면 이 컴퓨터의 직인 → 견본
	unit1: string; // 1단계 모임 이름: 공동체 · 교구 · 지역 …
	unit2: string; // 2단계 모임 이름: 소그룹 · 구역 · 목장 · 셀 …
}
export interface CertForm {
	prefix: string; // 제
	format: string; // {연도}-{번호}
	digits: number; // 번호 자릿수
	suffix: string; // 호
	purposes: string[]; // 용도 목록
	memberBody: string; // 교인증명서 본문
	baptismBody: string; // 세례증명서 본문 ({구분} = 세례·유아세례·입교)
	footNote: string; // 아래쪽 안내
	sealSize: number; // 직인 크기 (mm)
}
export interface Codes {
	visitKinds: string[];
	missionSocieties: string[];
}
export interface BackupCfg {
	auto: boolean;
	cycle: '매일' | '매주' | '매월';
	time: string; // 03:00
	keep: number; // 보관할 백업 개수
	notify: string; // 실패 알림 받을 이메일
	last: string | null; // 마지막으로 직접 내려받은 시각
}

export const DEFAULT_CHURCH: ChurchInfo = {
	name: '', denomination: '', address: '', phone: '', fax: '', email: '', homepage: '',
	founded: '', bizNo: '', pastorLabel: '담임목사', pastorName: '', logo: null, seal: null, unit1: '공동체', unit2: '소그룹'
};
export const DEFAULT_CERT: CertForm = {
	prefix: '제', format: '{연도}-{번호}', digits: 3, suffix: '호',
	purposes: ['제출용', '이명용', '학교 제출용', '직장 제출용', '기타'],
	memberBody: '위 사람은 본 교회에 등록된 교인임을 증명합니다.',
	baptismBody: '위 사람은 위와 같이 {구분}를 받았음을 증명합니다.',
	footNote: '',
	sealSize: 22
};
export const DEFAULT_CODES: Codes = { visitKinds: [...VISIT_KINDS], missionSocieties: [...MISSION_SOCIETIES] };
export const DEFAULT_BACKUP: BackupCfg = { auto: true, cycle: '매일', time: '03:00', keep: 30, notify: '', last: null };

// 증명서 번호: 제 2026-001 호
export function certNo(f: CertForm, year: number, n: number): string {
	const body = f.format.replace('{연도}', String(year)).replace('{번호}', String(n).padStart(Math.max(1, f.digits), '0'));
	return [f.prefix, body, f.suffix].filter(Boolean).join(' ');
}

// 올린 그림을 작게 줄여 데이터 주소로 (브라우저 저장 공간을 아끼려고 긴 변 600px 이하 PNG)
export function shrinkImage(file: File, max = 600, maxBytes = 90_000): Promise<string> {
	// 호스팅이 한 번에 받는 크기에 한도(128KB)가 있어서, 그림이 maxBytes 를 넘으면 조금씩 줄여서 맞춘다
	return new Promise((resolve, reject) => {
		const img = new Image();
		img.onload = () => {
			const draw = (k: number) => {
				const c = document.createElement('canvas');
				c.width = Math.max(1, Math.round(img.width * k)); c.height = Math.max(1, Math.round(img.height * k));
				c.getContext('2d')!.drawImage(img, 0, 0, c.width, c.height);
				return c.toDataURL('image/png');
			};
			let k = Math.min(1, max / Math.max(img.width, img.height));
			let url = draw(k);
			while (url.length > maxBytes && k > 0.1) { k *= 0.8; url = draw(k); }
			URL.revokeObjectURL(img.src);
			resolve(url);
		};
		img.onerror = reject;
		img.src = URL.createObjectURL(file);
	});
}

export const UNIT1_OPTIONS = ['공동체', '교구', '지역', '선교회'];
export const UNIT2_OPTIONS = ['소그룹', '구역', '목장', '셀', '다락방'];
