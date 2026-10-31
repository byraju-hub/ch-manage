<?php
declare(strict_types=1);

// 첫 번째 데이터베이스 구조. {p} 는 테이블 접두어(기본 cm_)로 바뀐다.
// 이미 설치된 곳을 바꿀 때는 이 파일을 고치지 말고 002_..., 003_... 파일을 새로 만든다.
//
// 원본: supabase/migrations/0001_registry.sql (Supabase 기준안)을 MariaDB 로 옮긴 것.
//  - 열 이름은 화면의 데이터 모양(src/lib/registry/types.ts)과 같게 했다. 화면이 바뀐 칸만 서버로 보내고(sync),
//    서버(api/lib/data.php)가 표마다 권한과 값을 확인한 뒤 저장한다.
//  - 교인 영역의 id 는 화면이 만드는 UUID(CHAR 36). 교인구분·공동체 id 는 짧은 글자 코드.
//  - 고정된 목록(재적 종류, 직분 등)은 DB 가 아니라 PHP(data.php)에서 확인한다. 새 값이 생겨도 DB 를 바꾸지 않아도 된다.
//  - RLS(행 수준 보안) 대신 PHP 가 등급별로 읽기·쓰기를 막는다. (심방·이전 프로그램 메모·소그룹 특이사항은 A 등급만)
//  - is_sample: 1 이면 체험용 샘플 데이터. 설정의 "샘플 데이터 삭제"가 이 표시가 있는 행만 지운다.
//
// 표를 만드는 순서가 곧 외래키 순서다. (참조되는 표를 먼저 만든다.)

$ENGINE = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    // ===== 시스템 =====
    "CREATE TABLE `{p}meta` (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v TEXT NOT NULL
    ) $ENGINE",
    "INSERT INTO `{p}meta` (k, v) VALUES ('data_version', '1')",

    // ===== 회원·권한 =====
    // 등급마다 할 수 있는 일 (최고관리자가 바꿈). A 전임 교역자 > B 교적 관리 > C 열람
    "CREATE TABLE `{p}grades` (
        grade CHAR(1) NOT NULL PRIMARY KEY,
        name VARCHAR(40) NOT NULL,
        can_edit TINYINT(1) NOT NULL DEFAULT 0,
        can_export TINYINT(1) NOT NULL DEFAULT 0,
        can_visit TINYINT(1) NOT NULL DEFAULT 0,
        is_site_admin TINYINT(1) NOT NULL DEFAULT 0,
        CHECK (grade IN ('A','B','C'))
    ) $ENGINE",
    "INSERT INTO `{p}grades` (grade, name, can_edit, can_export, can_visit, is_site_admin) VALUES
        ('A', 'A · 전임 교역자', 1, 1, 1, 0),
        ('B', 'B · 교적 관리', 1, 1, 0, 0),
        ('C', 'C · 열람', 0, 0, 0, 0)",

    // ===== 교인구분 (최대 3단계) =====
    // 'dead'·'move'·'keep'·'m.a.a' 같은 고정 id 는 지우지 못하게 한다. (PHP 에서 확인)
    "CREATE TABLE `{p}categories` (
        id VARCHAR(40) NOT NULL PRIMARY KEY,
        parent_id VARCHAR(40) NULL,
        name VARCHAR(40) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        CONSTRAINT fk_{p}cat_parent FOREIGN KEY (parent_id) REFERENCES `{p}categories`(id)
    ) $ENGINE",

    // ===== 교적 카드 (사람당 1장) =====
    // 원본은 이름·성별·생년월일·휴대폰·이메일을 외부 프로필 표에 두었지만, 단독 설치라 여기에 직접 둔다.
    "CREATE TABLE `{p}people` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        name VARCHAR(50) NOT NULL,
        name_note VARCHAR(10) NULL,                 -- 동명이인 구분: 같은 이름이 있으면 등록 순서대로 A, B, C … (화면이 붙임)
        phone VARCHAR(30) NULL,
        gender CHAR(1) NULL,                        -- M / F
        birth_date DATE NULL,
        email VARCHAR(100) NULL,
        birth_lunar TINYINT(1) NOT NULL DEFAULT 0,  -- 음력 여부
        reg_no VARCHAR(30) NULL,                    -- 교번
        address_zip VARCHAR(10) NULL,
        address1 VARCHAR(255) NULL,                 -- 비면 세대 주소 사용
        address2 VARCHAR(255) NULL,
        occupation VARCHAR(100) NULL,
        workplace VARCHAR(100) NULL,                -- 옛 자료용. 새 입력은 occupation 한 칸에
        car_number VARCHAR(100) NULL,
        marital_status VARCHAR(10) NULL,
        wedding_date DATE NULL,
        previous_church VARCHAR(100) NULL,
        guide_person_id CHAR(36) NULL,              -- 인도자: 교인이면
        guide_name VARCHAR(50) NULL,                -- 인도자: 교인이 아니면 이름만
        sms_opt_out TINYINT(1) NOT NULL DEFAULT 0,  -- 문자 수신 거부
        faith_checked_on DATE NULL,                 -- 신급 확인일 (원입교인 판정)
        note TEXT NULL,
        purged_on DATE NULL,                        -- 전출·별세·제적 5년 뒤 인적사항 지운 날 (보관은 지우지 않음)
        photo VARCHAR(255) NULL,                    -- 사진 파일 경로 (서버 data 폴더 안)
        category_id VARCHAR(40) NULL,               -- 교인구분 (3단계 중 한 칸)
        clergy_title VARCHAR(20) NULL,
        edu_dept VARCHAR(20) NULL,
        mission_society VARCHAR(40) NULL,
        big_family_head_id CHAR(36) NULL,           -- 대가족 세대주 (세대와 별개)
        big_family_relation VARCHAR(30) NULL,
        is_sample TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_reg_no (reg_no),
        INDEX idx_name (name),
        INDEX idx_birth (birth_date),
        INDEX idx_phone (phone),
        INDEX idx_category (category_id),
        CONSTRAINT fk_{p}ppl_guide FOREIGN KEY (guide_person_id) REFERENCES `{p}people`(id) ON DELETE SET NULL,
        CONSTRAINT fk_{p}ppl_bfh FOREIGN KEY (big_family_head_id) REFERENCES `{p}people`(id) ON DELETE SET NULL,
        CONSTRAINT fk_{p}ppl_cat FOREIGN KEY (category_id) REFERENCES `{p}categories`(id)
    ) $ENGINE",

    // 가입 신청 → 최고관리자 승인 → 등급(A·B·C) 지정 → 사용. 아이디·비밀번호 로그인.
    "CREATE TABLE `{p}users` (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        login_id VARCHAR(50) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        name VARCHAR(50) NOT NULL,                  -- 실명 (가입 때 입력)
        phone VARCHAR(20) NOT NULL DEFAULT '',
        email VARCHAR(100) NOT NULL DEFAULT '',
        person_id CHAR(36) NULL,                    -- 교적의 교인과 연결
        status ENUM('승인 대기','사용','정지') NOT NULL DEFAULT '승인 대기',
        grade CHAR(1) NULL,                         -- 승인 전에는 비어 있음
        is_super TINYINT(1) NOT NULL DEFAULT 0,     -- 최고관리자: 가입 승인·등급 지정·등급 설정 (모든 권한)
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at DATETIME NULL,
        decided_by INT UNSIGNED NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        last_login_at DATETIME NULL,
        must_change_password TINYINT(1) NOT NULL DEFAULT 0, -- 임시 비밀번호로 재설정된 계정: 다음 로그인 때 바꿔야 함
        UNIQUE KEY uniq_login (login_id),
        CONSTRAINT fk_{p}usr_grade FOREIGN KEY (grade) REFERENCES `{p}grades`(grade),
        CONSTRAINT fk_{p}usr_person FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE SET NULL
    ) $ENGINE",

    // 로그인 시도 기록 (같은 아이디·같은 주소에서 연달아 틀리면 잠시 막는 데 쓴다)
    "CREATE TABLE `{p}login_attempts` (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        login_id VARCHAR(50) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login (login_id, at),
        INDEX idx_ip (ip, at)
    ) $ENGINE",

    // ===== 세대(가족): 한 사람은 한 세대에만. 교인이 아닌 가족은 이름·출생연도만 =====
    // 세대마다 세대주('본인')가 한 명이라는 규칙은 화면이 지킨다. (여러 줄을 한 번에 저장할 때 순서가 꼬일 수 있어 DB 제약으로 두지 않음)
    "CREATE TABLE `{p}households` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        no VARCHAR(30) NOT NULL,
        zip VARCHAR(10) NULL,
        address VARCHAR(255) NULL,
        home_phone VARCHAR(30) NULL,
        note TEXT NULL,
        is_sample TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_no (no)
    ) $ENGINE",
    "CREATE TABLE `{p}household_members` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        household_id CHAR(36) NOT NULL,
        person_id CHAR(36) NULL,                    -- 교인이 아니면 비어 있음. UNIQUE: 한 사람은 한 세대에만
        name VARCHAR(50) NULL,                      -- 교인이 아닐 때만 사용
        birth_year SMALLINT NULL,
        relation VARCHAR(20) NOT NULL,              -- 본인·배우자·자녀·부모·… (세대주 기준)
        sort_order INT NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        UNIQUE KEY uniq_person (person_id),
        INDEX idx_household (household_id),
        CONSTRAINT fk_{p}hm_hh FOREIGN KEY (household_id) REFERENCES `{p}households`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}hm_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",

    // ===== 이력: 지금 값(재적 상태·신급·직분·등록일)은 이력에서 계산 =====
    "CREATE TABLE `{p}status_events` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NOT NULL,
        kind VARCHAR(10) NOT NULL,                  -- 등록·전입·복적·보류·보관·전출·별세·제적
        event_date DATE NULL,
        reason VARCHAR(255) NULL,
        church VARCHAR(100) NULL,                   -- 전입·전출 교회
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}se_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",
    "CREATE TABLE `{p}faith_events` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NOT NULL,
        kind VARCHAR(10) NOT NULL,                  -- 학습·세례·유아세례·입교
        event_date DATE NULL,                       -- 신급일
        church VARCHAR(100) NULL,                   -- 신급교회 (비면 본 교회)
        officiant VARCHAR(50) NULL,                 -- 집례자
        note VARCHAR(255) NULL,
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}fe_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",
    // 직분마다 고를 수 있는 구분이 다르다. 어떤 조합이 가능한지는 화면이 확인한다.
    "CREATE TABLE `{p}office_terms` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NOT NULL,
        office VARCHAR(10) NOT NULL,                -- 목사·전도사·사모·장로·시무집사·권사·서리집사
        rank_status VARCHAR(10) NOT NULL,           -- 시무·은퇴·원로·명예·협동·사임·전입·서리·휴직
        start_date DATE NULL,                       -- 임직일
        end_date DATE NULL,
        church VARCHAR(100) NULL,                   -- 임직교회 (비면 본 교회)
        note VARCHAR(255) NULL,                     -- 휴직·무임 등
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}ot_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",

    // ===== 공동체·소그룹 =====
    // 이름은 교회가 고른다 (공동체·교구·지역 / 소그룹·구역·목장·셀). 이름 설정은 site_settings 에 있다.
    // id 는 처음 만들 때의 이름(아이콘을 고를 때 쓰는 key). 이름을 바꿔도 id 는 그대로다.
    "CREATE TABLE `{p}communities` (
        id VARCHAR(40) NOT NULL PRIMARY KEY,
        name VARCHAR(40) NOT NULL,
        born_from SMALLINT NOT NULL DEFAULT 1900,   -- 연간 설정이 없을 때 쓰는 출생 연도 기준
        born_to SMALLINT NOT NULL DEFAULT 2100,
        sort_order INT NOT NULL DEFAULT 0,
        is_sample TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_name (name)
    ) $ENGINE",
    "CREATE TABLE `{p}small_groups` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        community_id VARCHAR(40) NOT NULL,          -- 소그룹·소속 교인이 있으면 공동체를 지우지 못함
        name VARCHAR(60) NOT NULL,
        place VARCHAR(100) NULL,
        leader_id CHAR(36) NULL,                    -- 인도자
        care TINYINT(1) NOT NULL DEFAULT 0,         -- 돌봄 편성 (예: 자녀 돌봄 모임)
        sort_order INT NOT NULL DEFAULT 0,
        is_sample TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_name (name),
        CONSTRAINT fk_{p}sg_comm FOREIGN KEY (community_id) REFERENCES `{p}communities`(id),
        CONSTRAINT fk_{p}sg_leader FOREIGN KEY (leader_id) REFERENCES `{p}people`(id) ON DELETE SET NULL
    ) $ENGINE",
    // 장년만 소그룹에 속한다
    "CREATE TABLE `{p}person_groups` (
        person_id CHAR(36) NOT NULL PRIMARY KEY,
        small_group_id CHAR(36) NOT NULL,
        CONSTRAINT fk_{p}pg_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}pg_sg FOREIGN KEY (small_group_id) REFERENCES `{p}small_groups`(id)
    ) $ENGINE",
    // 인도자 특이사항: 심방 권한자(A 등급)만 열람
    "CREATE TABLE `{p}group_notes` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NULL,                    -- 비어 있으면 소그룹 전체에 대한 '기타 의견'
        small_group_id CHAR(36) NULL,
        `date` DATE NOT NULL,
        body TEXT NOT NULL,
        author VARCHAR(50) NULL,
        CONSTRAINT fk_{p}gn_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}gn_sg FOREIGN KEY (small_group_id) REFERENCES `{p}small_groups`(id) ON DELETE SET NULL
    ) $ENGINE",

    // ===== 출석: 두 가지를 따로 저장한다. 소그룹에 속한 교인은 한 주일에 기록이 2개 생긴다. =====
    // 1) 주일 오전예배 출석: '출석' 화면에서 명단 체크
    "CREATE TABLE `{p}worship_attendance` (
        sunday DATE NOT NULL,
        person_id CHAR(36) NOT NULL,
        present TINYINT(1) NOT NULL,
        reason VARCHAR(255) NULL,
        PRIMARY KEY (sunday, person_id),
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}wa_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",
    // 주일 오전예배 전체 출석 인원 (명단 밖 방문자 등 포함, 따로 입력). 그 외 인원 = total - 명단 체크 인원
    "CREATE TABLE `{p}worship_totals` (
        sunday DATE NOT NULL PRIMARY KEY,
        total INT UNSIGNED NOT NULL
    ) $ENGINE",
    // 2) 주일 오후 소그룹 모임 출석
    "CREATE TABLE `{p}group_attendance` (
        sunday DATE NOT NULL,
        person_id CHAR(36) NOT NULL,
        present TINYINT(1) NOT NULL,
        reason VARCHAR(255) NULL,
        PRIMARY KEY (sunday, person_id),
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}ga_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",

    // ===== 메모·동의 =====
    "CREATE TABLE `{p}notes` (                      -- 담당자 메모: 교적 사용자 모두 열람
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NOT NULL,
        body TEXT NOT NULL,
        author VARCHAR(50) NULL,                    -- 쓴 사람 이름 (서버가 로그인한 사람으로 채움)
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}nt_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",
    "CREATE TABLE `{p}legacy_memos` (               -- 이전 프로그램에서 옮겨 온 메모: 심방 권한자만, 열 때마다 접속기록
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NOT NULL,
        body TEXT NOT NULL,
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}lm_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",
    "CREATE TABLE `{p}consents` (                   -- 새 프로그램 시작 시 일괄 동의
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NOT NULL,
        kind VARCHAR(20) NOT NULL,                  -- 교적 등록·민감정보·요람 공개·보호자 동의
        policy_version VARCHAR(30) NOT NULL,
        consent_date DATE NULL,
        method VARCHAR(10) NOT NULL,                -- 서면·앱·구두
        guardian_name VARCHAR(50) NULL,
        revoked_on DATE NULL,
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}cs_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",

    // ===== 심방: A 등급만 열람·등록 (상태 없이 등록, 앞 날짜면 달력에 계획으로 표시) =====
    // 녹음·문서 분석은 파일을 저장하지 않고 결과만 이 칸들에 채운다.
    "CREATE TABLE `{p}visits` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NOT NULL,
        visit_date DATE NOT NULL,
        kind VARCHAR(30) NOT NULL,                  -- 심방 구분 (코드표에서 바꿀 수 있음)
        method VARCHAR(10) NOT NULL,                -- 대면·전화
        pastor VARCHAR(50) NOT NULL,                -- 담당 교역자
        companions VARCHAR(255) NULL,               -- 동행자
        scripture VARCHAR(100) NULL,
        hymn VARCHAR(100) NULL,
        content TEXT NOT NULL,
        follow_up TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_sample TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_person (person_id, visit_date),
        INDEX idx_date (visit_date),
        CONSTRAINT fk_{p}vs_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",

    // ===== 교회 조직표 (위원회 > 부서), 교회봉사 소속 (교인마다 최대 3개) =====
    "CREATE TABLE `{p}org_units` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        parent_id CHAR(36) NULL,
        name VARCHAR(60) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        leader_id CHAR(36) NULL,                    -- 위원회: 위원장(장로) / 부서: 부서장
        clergy_id CHAR(36) NULL,                    -- 부서 교역자 (한 명, 없을 수 있음)
        is_sample TINYINT(1) NOT NULL DEFAULT 0,
        CONSTRAINT fk_{p}ou_parent FOREIGN KEY (parent_id) REFERENCES `{p}org_units`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}ou_leader FOREIGN KEY (leader_id) REFERENCES `{p}people`(id) ON DELETE SET NULL,
        CONSTRAINT fk_{p}ou_clergy FOREIGN KEY (clergy_id) REFERENCES `{p}people`(id) ON DELETE SET NULL
    ) $ENGINE",
    "CREATE TABLE `{p}org_unit_staff` (             -- 부서 담당자 (여러 명)
        org_unit_id CHAR(36) NOT NULL,
        person_id CHAR(36) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (org_unit_id, person_id),
        CONSTRAINT fk_{p}ous_ou FOREIGN KEY (org_unit_id) REFERENCES `{p}org_units`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}ous_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",
    "CREATE TABLE `{p}meeting_officers` (           -- 공동의회·당회·제직회 의장·서기 (이름과 직분을 그대로 적음)
        meeting VARCHAR(10) NOT NULL PRIMARY KEY,
        chair VARCHAR(80) NOT NULL DEFAULT '',
        secretary VARCHAR(80) NOT NULL DEFAULT ''
    ) $ENGINE",
    "CREATE TABLE `{p}affiliations` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NOT NULL,
        org_unit_id CHAR(36) NOT NULL,
        role VARCHAR(40) NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}af_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}af_ou FOREIGN KEY (org_unit_id) REFERENCES `{p}org_units`(id) ON DELETE CASCADE
    ) $ENGINE",

    // ===== 교육 (지금은 새가족 교육만) · 일정(캘린더) =====
    "CREATE TABLE `{p}education_courses` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        name VARCHAR(60) NOT NULL,
        is_sample TINYINT(1) NOT NULL DEFAULT 0
    ) $ENGINE",
    "CREATE TABLE `{p}education_records` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NOT NULL,
        course_id CHAR(36) NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,                         -- 수료일
        completed TINYINT(1) NOT NULL DEFAULT 0,
        instructor VARCHAR(50) NULL,
        note VARCHAR(255) NULL,
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}er_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}er_course FOREIGN KEY (course_id) REFERENCES `{p}education_courses`(id) ON DELETE CASCADE
    ) $ENGINE",
    // 생일·심방 계획은 다른 표에서 계산한다. 교회 절기(부활주일 등)는 해마다 계산해 넣는다.
    "CREATE TABLE `{p}events` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        kind VARCHAR(10) NOT NULL,                  -- 성도·교회 절기·사역 일정·성례 일정
        `date` DATE NOT NULL,
        end_date DATE NULL,
        title VARCHAR(200) NOT NULL,
        person_id CHAR(36) NULL,
        note VARCHAR(255) NULL,
        is_sample TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_date (`date`),
        CONSTRAINT fk_{p}ev_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",

    // ===== 기록 =====
    // 로그인·검색·열람·수정·내려받기·출력·심방·권한 변경, 3년 보관. 쓴 사람과 시간은 서버가 채운다.
    // 사용자를 지워도 기록은 남도록 사용자 표에는 외래키를 걸지 않는다.
    "CREATE TABLE `{p}access_log` (
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        user_id INT UNSIGNED NULL,
        who VARCHAR(50) NOT NULL DEFAULT '',
        action VARCHAR(40) NOT NULL,
        target VARCHAR(100) NOT NULL DEFAULT '',
        INDEX idx_at (at),
        INDEX idx_user (user_id, at)
    ) $ENGINE",
    "CREATE TABLE `{p}change_log` (                 -- 수정 전후 값
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NULL,
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        by_user_id INT UNSIGNED NULL,
        by_name VARCHAR(50) NOT NULL DEFAULT '',
        field VARCHAR(60) NOT NULL,
        before_value TEXT NULL,
        after_value TEXT NULL,
        INDEX idx_person (person_id, at),
        INDEX idx_at (at),
        CONSTRAINT fk_{p}cl_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",
    "CREATE TABLE `{p}certificates` (               -- 증명서 발급 대장 (교인증명서·세례증명서·기부금 영수증 …)
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_no VARCHAR(40) NOT NULL,                -- 예: 제 2026-001 호
        kind VARCHAR(30) NOT NULL,
        person_id CHAR(36) NULL,
        purpose VARCHAR(100) NOT NULL DEFAULT '',
        issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        issued_by INT UNSIGNED NULL,
        UNIQUE KEY uniq_doc_no (doc_no),
        CONSTRAINT fk_{p}ce_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE SET NULL
    ) $ENGINE",
    "CREATE TABLE `{p}certificate_requests` (       -- 홈페이지 증명서 신청: 관리자가 승인해야 직인이 찍힌 증명서가 나감
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        person_id CHAR(36) NOT NULL,
        kind VARCHAR(30) NOT NULL,
        purpose VARCHAR(100) NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(10) NOT NULL DEFAULT '대기', -- 대기·승인·반려
        decided_at DATETIME NULL,
        decided_by VARCHAR(50) NULL,                -- 처리한 사람 이름 (서버가 채움)
        certificate_id BIGINT UNSIGNED NULL,
        CONSTRAINT fk_{p}cr_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}cr_cert FOREIGN KEY (certificate_id) REFERENCES `{p}certificates`(id) ON DELETE SET NULL
    ) $ENGINE",

    // ===== 설정: 교회 기본 설정(한 줄) · 연도별 연간 설정 =====
    "CREATE TABLE `{p}site_settings` (
        id TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
        church_info JSON NOT NULL,                  -- 이름·교단·주소·연락처·창립일·서명 직함/이름, 모임 이름 unit1·unit2
        logo_path VARCHAR(255) NULL,                -- 서버 data 폴더 안의 경로
        seal_path VARCHAR(255) NULL,                -- 직인은 서버에서만 꺼냄
        cert_form JSON NOT NULL,                    -- 발급 번호 모양·용도·본문·안내 문구·직인 크기
        codes JSON NOT NULL,                        -- 바꿀 수 있는 코드표: 전도회 목록·심방 구분
        backup JSON NOT NULL,                       -- 자동 백업 주기·보관 개수
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL,
        CHECK (id = 1)
    ) $ENGINE",
    "CREATE TABLE `{p}year_settings` (              -- 해마다 한 줄, 새해에 지난해 것을 복사해 고침
        year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
        motto VARCHAR(200) NOT NULL DEFAULT '',     -- 표어·말씀 (대시보드 배너)
        verse VARCHAR(100) NOT NULL DEFAULT '',
        age_mode VARCHAR(10) NOT NULL DEFAULT '만 나이',  -- 만 나이·한국 나이·연 나이
        communities JSON NOT NULL,                  -- 공동체별 출생 연도 {이름: {from, to}}
        societies JSON NOT NULL,                    -- 남·여전도회별 출생 연도
        edu JSON NOT NULL,                          -- 교육부서 기준 [{dept, from, to}] (학교 나이 = 올해 - 출생 연도, 7 = 초1)
        promote_date DATE NULL,                     -- 교육부서 진급(이동) 날짜
        absent_weeks SMALLINT UNSIGNED NOT NULL DEFAULT 9,   -- 장기 결석 알림 기준(주)
        newcomer_days SMALLINT UNSIGNED NOT NULL DEFAULT 60  -- 새가족으로 보는 기간(일)
    ) $ENGINE",

    // ===== 문자 발송: 자주 쓰는 문구 · 발송 기록 =====
    "CREATE TABLE `{p}sms_templates` (              -- [ ] 는 고쳐 쓰는 칸, {이름}·{직분} 은 자동 치환
        id CHAR(36) NOT NULL PRIMARY KEY,
        seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, -- 입력 순서 (화면이 입력 순서로 보여 주는 곳이 있음)
        title VARCHAR(60) NOT NULL,
        body TEXT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $ENGINE",
    "CREATE TABLE `{p}sms_log` (                    -- 누가 언제 몇 명에게 무엇을
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_by INT UNSIGNED NULL,
        kind VARCHAR(5) NOT NULL,                   -- SMS·LMS
        subject VARCHAR(100) NOT NULL DEFAULT '',
        body TEXT NOT NULL,
        recipient_ids JSON NOT NULL,
        sent_count INT UNSIGNED NOT NULL,
        failed_count INT UNSIGNED NOT NULL DEFAULT 0,
        provider_group_id VARCHAR(80) NOT NULL DEFAULT '',  -- 문자 업체의 그룹 ID (결과 조회용)
        INDEX idx_at (sent_at)
    ) $ENGINE",
];
