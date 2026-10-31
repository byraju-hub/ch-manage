// 교적 데이터 타입. 서버 표(server/migrations/001_init.php)와 열 이름이 같고, 서버의 저장 규칙은 server/api/lib/data.php 에 있습니다.

// '보관' = 삭제할 수 없는 교적을 넣어 두는 구분 (5년 자동 삭제에서 제외)
export type StatusKind = '등록' | '전입' | '복적' | '보류' | '보관' | '전출' | '별세' | '제적';
export type FaithKind = '학습' | '세례' | '유아세례' | '입교';
export type OfficeName = '목사' | '전도사' | '사모' | '장로' | '시무집사' | '권사' | '서리집사';
export type OfficeStatus = '시무' | '은퇴' | '원로' | '명예' | '협동' | '사임' | '전입' | '서리' | '휴직';
export type ClergyTitle = '담임목사' | '부목사' | '교육목사' | '전임전도사' | '교육전도사';
export type EduDept = '청년부' | '중고등부' | '유초등부' | '유치부' | '영아부';
export type MaritalStatus = '미혼' | '기혼' | '이혼' | '사별' | '기타';
export type ConsentKind = '교적 등록' | '민감정보' | '요람 공개' | '보호자 동의';
export type ConsentMethod = '서면' | '앱' | '구두';
export type VisitMethod = '대면' | '전화';

export const STATUS_KINDS: StatusKind[] = ['등록', '전입', '복적', '보류', '보관', '전출', '별세', '제적'];
export const FAITH_KINDS: FaithKind[] = ['학습', '세례', '유아세례', '입교'];
// 직분 높은 순 (이 교회는 '안수집사' 대신 '시무집사')
export const OFFICE_NAMES: OfficeName[] = ['목사', '전도사', '사모', '장로', '시무집사', '권사', '서리집사'];
export const OFFICE_STATUSES: OfficeStatus[] = ['시무', '은퇴', '원로', '명예', '협동', '사임', '전입', '서리', '휴직'];
// 직분마다 고를 수 있는 구분 ('교적 프로그램 구조' 문서)
export const OFFICE_STATUS_OPTIONS: Record<OfficeName, OfficeStatus[]> = {
	목사: ['시무', '원로', '은퇴', '협동', '사임'],
	전도사: ['시무', '은퇴', '사임'],
	사모: ['시무', '은퇴'],
	장로: ['시무', '원로', '은퇴', '협동', '휴직'],
	시무집사: ['시무', '은퇴', '협동', '전입'],
	권사: ['시무', '은퇴', '협동', '전입', '명예'],
	서리집사: ['서리', '명예']
};
// 임직일·임직교회를 기록하는 중직자
export const ORDAINED: OfficeName[] = ['장로', '시무집사', '권사'];
export const CLERGY_TITLES: ClergyTitle[] = ['담임목사', '부목사', '교육목사', '전임전도사', '교육전도사'];
// 심방 기록은 전임 교역자까지만 볼 수 있음 (교육전도사 제외)
export const FULL_TIME_CLERGY: ClergyTitle[] = ['담임목사', '부목사', '교육목사', '전임전도사'];
// 직분 선택 목록 (목사·전도사는 하나로 고르고, 담임·부·교육 같은 직책은 구분에서 고름)
export interface Position { label: string; office: OfficeName | null }
export const POSITIONS: Position[] = [
	{ label: '성도', office: null },
	...OFFICE_NAMES.map((o) => ({ label: o, office: o }))
];
// 목사·전도사의 구분 목록: 지금 맡은 직책(담임목사 등)이 '시무'를 대신함
const CLERGY_BY_OFFICE: Partial<Record<OfficeName, ClergyTitle[]>> = {
	목사: ['담임목사', '부목사', '교육목사'],
	전도사: ['전임전도사', '교육전도사']
};
export function rankChoices(o: OfficeName): string[] {
	const c = CLERGY_BY_OFFICE[o];
	return c ? [...c, ...OFFICE_STATUS_OPTIONS[o].filter((s) => s !== '시무')] : OFFICE_STATUS_OPTIONS[o];
}
// 구분 선택 칸에 보이는 이름: 직분까지 붙여서 (시무 → 시무장로, 서리 → 서리집사, 명예(서리집사) → 명예집사, 담임목사는 그대로)
export function rankLabel(o: OfficeName, choice: string): string {
	if ((CLERGY_TITLES as string[]).includes(choice)) return choice;
	if (o === '사모') return choice === '시무' ? '사모' : `${choice}사모`; // 사모 · 은퇴사모
	const base = o === '시무집사' || o === '서리집사' ? '집사' : o;
	return `${choice}${base}`;
}
// 고른 구분을 저장 값으로: 직책이면 구분 '시무' + 교역자 직책, 아니면 구분 그대로
export function parseRank(choice: string): { rank_status: OfficeStatus; clergy: ClergyTitle | null } {
	return (CLERGY_TITLES as string[]).includes(choice)
		? { rank_status: '시무', clergy: choice as ClergyTitle }
		: { rank_status: choice as OfficeStatus, clergy: null };
}
// 동명이인 구분: A, B, C ...
export const NAME_NOTES = Array.from({ length: 26 }, (_, i) => String.fromCharCode(65 + i));
export const EDU_DEPTS: EduDept[] = ['영아부', '유치부', '유초등부', '중고등부', '청년부'];
export const MARITAL_STATUSES: MaritalStatus[] = ['미혼', '기혼', '이혼', '사별', '기타'];
// 전도회: 남 1~6, 여 1~10
export const MISSION_SOCIETIES: string[] = [
	...Array.from({ length: 6 }, (_, i) => `${i + 1}남전도회`),
	...Array.from({ length: 10 }, (_, i) => `${i + 1}여전도회`)
];
export const RELATIONS = ['본인', '배우자', '자녀', '부모', '며느리', '사위', '손자녀', '형제자매', '기타'];
export const CONSENT_KINDS: ConsentKind[] = ['교적 등록', '민감정보', '요람 공개', '보호자 동의'];
// 기본 심방 구분 목록 (교회 설정 › 코드표에서 바꿀 수 있음)
export const VISIT_KINDS = ['대심방', '새신자', '일반', '결혼', '출산', '돌', '입학', '졸업', '회갑', '장례', '추도', '개업', '이사', '병문안', '전도'];
export const MAX_CATEGORY_DEPTH = 3;

export interface Person {
	id: string;
	name: string;
	name_note: string | null; // 동명이인 구분 (예: 1975년생)
	// 앱 프로필(custom_users)에 있는 칸
	phone: string | null;
	gender: 'M' | 'F' | null;
	birth_date: string | null;
	email: string | null;
	has_account: boolean; // 앱 가입 여부 (계산)
	// 교적 카드(registry.people)에 있는 칸
	birth_lunar: boolean;
	reg_no: string | null;
	address_zip: string | null;
	address1: string | null;
	address2: string | null;
	occupation: string | null;
	workplace: string | null;
	car_number?: string | null; // 차량 번호 (주차 봉사·연락용, 예: 12가 3456). 여러 대면 쉼표로
	marital_status: MaritalStatus | null;
	wedding_date: string | null;
	previous_church: string | null;
	guide_person_id: string | null;
	guide_name: string | null;
	sms_opt_out: boolean;
	faith_checked_on: string | null;
	note: string | null;
	purged_on: string | null;
	photo: string | null; // 사진 (작은 이미지)
	category_id: string | null; // 교인구분 (3단계 카테고리 중 하나)
	clergy_title: ClergyTitle | null; // 교역자 직책 (구분과 별개)
	edu_dept: EduDept | null; // 교육부서 (청년·교육부서인 경우)
	mission_society: string | null; // 전도회 (예: 3남전도회, 5여전도회)
	big_family_head_id: string | null; // 대가족 세대주
	big_family_relation: string | null; // 대가족 세대주 기준 관계
}

// 교인구분: 3단계까지 펼쳐지는 카테고리 (관리자 화면에서 이름을 바꿀 수 있음)
export interface Category {
	id: string;
	parent_id: string | null;
	name: string;
	sort_order: number;
}

export interface Household {
	id: string;
	no: string;
	zip: string | null;
	address: string | null;
	home_phone: string | null;
	note: string | null;
}

export interface HouseholdMember {
	id: string;
	household_id: string;
	person_id: string | null; // 교인이 아니면 null
	name: string; // 교인이 아닐 때만 사용
	birth_year: number | null;
	relation: string; // 세대주 기준
	sort_order: number;
	note: string | null;
}

export interface StatusEvent {
	id: string;
	person_id: string;
	kind: StatusKind;
	event_date: string | null;
	reason: string | null;
	church: string | null; // 전입·전출 교회
}

export interface FaithEvent {
	id: string;
	person_id: string;
	kind: FaithKind;
	event_date: string | null;
	church: string | null; // 받은 교회(비면 본 교회)
	officiant: string | null;
	note: string | null;
}

export interface OfficeTerm {
	id: string;
	person_id: string;
	office: OfficeName;
	rank_status: OfficeStatus;
	start_date: string | null; // 임직일
	end_date: string | null;
	church: string | null; // 임직교회 (비면 본 교회, 타교 임직이면 그 교회 이름)
	note: string | null; // 무임 등은 여기에 적음 (장로 휴직은 구분 '휴직')
}

// 소그룹 편성: 공동체(뿌리·줄기·열매) 아래 소그룹. 출석부의 편성표 자료
export interface SmallGroup {
	id: string;
	community: string;
	name: string;
	place: string | null; // 모임 장소
	leader_id: string | null; // 인도자
	care: boolean; // 돌봄 편성 (예: 자녀 돌봄 모임)
	sort_order: number;
}

export interface PersonGroup {
	person_id: string;
	community: string; // 출석부의 공동체
	small_group: string; // 출석부의 소그룹
}

// 출석부에서 인도자가 쓰는 개별 특이사항. person_id 가 없으면 그 주 소그룹 모임 전체에 대한 '기타 의견'
export interface GroupNote {
	id: string;
	person_id: string | null;
	small_group: string;
	date: string;
	body: string;
	author: string;
}

export interface Note {
	id: string;
	person_id: string;
	body: string;
	author: string;
	created_at: string;
}

export interface LegacyMemo {
	id: string;
	person_id: string;
	body: string;
}

export interface Consent {
	id: string;
	person_id: string;
	kind: ConsentKind;
	policy_version: string;
	consent_date: string | null;
	method: ConsentMethod;
	guardian_name: string | null;
	revoked_on: string | null;
}

export interface ChangeLogEntry {
	id: string;
	person_id: string;
	at: string;
	by: string;
	field: string;
	before: string | null;
	after: string | null;
}

export interface AccessLogEntry {
	id: string;
	at: string;
	who: string;
	action: string; // 검색, 열람, 수정, 내려받기, 출력
	target: string;
}

// ---- 심방 ----
export interface Visit {
	id: string;
	person_id: string;
	visit_date: string;
	kind: string; // 심방 구분 (VISIT_KINDS)
	method: VisitMethod;
	pastor: string; // 담당 교역자
	companions: string | null; // 동행자
	scripture: string | null; // 말씀
	hymn: string | null; // 찬송
	content: string;
	follow_up: string | null;
}

// ---- 출석 ----
// 주일 오전예배 출석(교적 '출석' 화면)과 주일 오후 소그룹 모임 출석(홈페이지 출석부)은 같은 모양으로 따로 저장합니다.
export interface AttendanceMark {
	present: boolean;
	reason: string | null; // 특이사항·사유
}

// ---- 일정 (대시보드 캘린더) ----
export type EventKind = '공휴일' | '생일' | '성도' | '교회 절기' | '사역 일정' | '성례 일정' | '심방 계획';
// 캘린더 버튼 순서: 절기 · 성례 · 사역 · 심방 · 성도 · 생일 · 공휴일
export const EVENT_KINDS: EventKind[] = ['교회 절기', '성례 일정', '사역 일정', '심방 계획', '성도', '생일', '공휴일'];
export interface ChurchEvent {
	id: string;
	kind: Exclude<EventKind, '공휴일' | '생일' | '심방 계획'>;
	date: string;
	end_date: string | null;
	title: string;
	person_id: string | null; // 성도(수술·입원 등)
	note: string | null;
}

// ---- 교육 (새가족 교육 등) ----
export interface EducationCourse {
	id: string;
	name: string;
}
export interface EducationRecord {
	id: string;
	person_id: string;
	course_id: string;
	start_date: string | null;
	end_date: string | null; // 수료일
	completed: boolean;
	instructor: string | null;
	note: string | null;
}

// ---- 교회 조직표 (위원회 / 부서) ----
export interface OrgUnit {
	id: string;
	parent_id: string | null;
	name: string;
	sort_order: number;
	leader_id: string | null; // 위원회: 위원장(장로) / 부서: 부서장
	staff_ids: string[]; // 부서 담당자 (여러 명 가능) — 설정 화면에서만 관리, 조직 화면에는 보이지 않음
	clergy_id?: string | null; // 부서 담당 교역자 (한 명)
}

// 공동의회·당회·제직회 의장과 서기 (이름과 직분을 그대로 적음)
export interface MeetingOfficer {
	meeting: '공동의회' | '당회' | '제직회';
	chair: string;
	secretary: string;
}

export interface Affiliation {
	id: string;
	person_id: string;
	org: string; // 교회조직표의 부서 (복수 등록 가능)
	role: string | null;
	start_date: string | null;
	end_date: string | null;
}

// 교적 권한: 승인된 사용자는 기본(열람) + 아래 항목을 최고관리자가 켜고 끔
// 홈페이지에서 교인이 신청한 증명서. 관리자가 승인해야 직인이 들어간 증명서를 발급할 수 있음
export type CertKind = '교인증명서' | '세례증명서';
export interface CertRequest {
	id: string;
	person_id: string;
	kind: CertKind;
	purpose: string; // 용도 (제출처)
	requested_at: string; // 신청 일시
	status: '대기' | '승인' | '반려';
	decided_at: string | null;
	decided_by: string | null;
}

export interface Perms {
	edit: boolean; // 교적 수정·등록
	export: boolean; // 내려받기·문서 출력
	visit: boolean; // 심방(열람·등록·녹음 분석), 이전 프로그램 메모, 인도자 특이사항
	admin: boolean; // 사이트 관리자: 설정(교인구분·소그룹·조직표)
}

// 교적 프로그램 회원: 가입 신청(아이디·비밀번호) → 최고관리자가 회원 목록에서 승인하고 등급(A·B·C)을 정함 → 사용
// 회원 정보와 권한 확인은 서버(PHP)가 맡습니다. (src/lib/registry/auth.svelte.ts)
export type UserStatus = '승인 대기' | '사용' | '정지';
// A 전임 교역자(수정·출력·심방) > B 교적 관리(수정·출력) > C 열람
export type Grade = 'A' | 'B' | 'C' | 'D'; // D = 소그룹 인도자 (자기 소그룹 출석 체크만)
export const GRADES: Grade[] = ['A', 'B', 'C', 'D'];
// 등급마다 할 수 있는 일 (최고관리자가 '관리자 › 사용자 설정 › 등급 설정'에서 바꿈)
export interface GradeDef {
	grade: Grade;
	name: string;
	perms: Perms;
}
export interface AppUser {
	id: string;
	name: string;
	login_id: string;
	email: string | null;
	phone: string | null;
	person_id: string | null; // 교적의 교인과 연결 (교역자 등)
	status: UserStatus;
	grade: Grade | null; // 승인 전에는 없음
	is_super: boolean; // 최고관리자: 회원 승인·등급 지정·등급 설정 (모든 권한)
	is_demo?: boolean; // 체험 계정: 모든 화면을 볼 수 있지만 서버가 보기 외의 요청을 막음
	requested_at: string;
	decided_at: string | null;
	decided_by: string | null;
	note: string | null;
	must_change_password?: boolean; // 임시 비밀번호로 재설정된 계정: 다음 로그인 때 바꿔야 함
}
