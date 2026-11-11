# ch-manage 구조 설계

> 교회 교적 관리 시스템. 교역자와 교적 담당자에게 무상 배포한다.
> 같은 교회의 부서 관리 시스템 [dep-manage](https://github.com/byraju-hub/dep-manage)와 같은 방식(정적 화면 + PHP API + MariaDB, 설치 마법사)으로 만들어 한 쌍으로 쓸 수 있게 했다.

---

## 1. 목표와 원칙

| 원칙 | 내용 |
|---|---|
| 가장 싼 호스팅에서 동작 | 카페24 최저가 웹호스팅(PHP + MariaDB, Node 불가) 기준 |
| 터미널 없이 설치 | 파일 올리기 → 브라우저에서 `/install/` → 끝 |
| 교회마다 다른 용어 | 공동체·교구·지역, 소그룹·구역·목장·셀 같은 이름을 설정으로 |
| 개인정보 우선 | 종교 정보는 민감정보. 로그인 필수, 권한은 서버가 정하고, 권한이 없는 자료는 내려보내지 않는다 |
| 교회마다 직접 설치 | 한 서버에 여러 교회를 두지 않는다 (운영자가 개인정보 수탁자가 되는 것을 피함) |
| 업그레이드 안전 | 새 버전을 덮어쓰고 `/install/` 을 한 번 열면 끝. 설정·사진·자료는 그대로 |

---

## 2. 전체 구조

```
[브라우저]  Svelte SPA (빌드된 정적 파일)
    │  GET /api/v1/bootstrap        로그인 후 내 등급으로 볼 수 있는 자료 전부
    │  POST /api/v1/sync            바뀐 칸만 저장
    │  세션 쿠키 + X-CSRF-Token
    ▼
[PHP 8.x]   api/index.php  ← 단일 진입점, 작은 라우터 → 컨트롤러 → PDO
    │
    ▼
[MariaDB]   utf8mb4, 표 이름 접두어 cm_
```

- **프론트엔드**: SvelteKit + `adapter-static`(SPA, `fallback: index.html`), Svelte 5, TypeScript
- **백엔드**: 프레임워크 없는 PHP. 작은 라우터와 PDO만 사용
- **인증**: PHP 세션 쿠키(HttpOnly, SameSite=Lax, HTTPS면 Secure). 세션 파일은 `data/sessions` 에 두고 30분 쓰지 않으면 끝난다
- **설치 마법사**: SPA와 별개로 PHP가 직접 그리는 `/install/`

---

## 3. 저장소 구조

```
src/lib/registry/
  store.svelte.ts      화면이 쓰는 데이터 저장소(registry). 읽기는 메모리에서, 쓰기는 save() → sync
  auth.svelte.ts       로그인·가입·사용자 관리 호출
  sync.svelte.ts       바뀐 칸 찾기, /sync 저장, 다른 사람의 저장 확인
  tables.ts            화면 데이터 ↔ 서버 표 변환 (서버의 data_specs 와 짝)
  paths.ts             서브폴더 설치를 위한 주소 도우미 hrefTo()
  sample.ts            샘플 데이터 만들기 (샘플 불러오기에서만 불러옴)
server/
  api/                 index.php, lib/(db·auth·data·router…), controllers/(auth·users·data)
  install/             설치 마법사
  migrations/          001_init.php … (번호 순서로 적용)
  data/                설정(config.php)·세션·사진. 웹에서 직접 열리지 않는다
```

---

## 4. 데이터베이스

모든 표 이름 앞에 접두어(기본 `cm_`)가 붙는다. 같은 DB에 다른 프로그램(dep-manage 는 `dm_`)이 있어도 섞이지 않는다.

| 묶음 | 표 |
|---|---|
| 시스템 | `meta`(설치 버전·자료 버전) |
| 회원·권한 | `grades`, `users`, `login_attempts` |
| 교인 | `categories`(교인구분 3단계), `people`, `households`, `household_members` |
| 이력 | `status_events`(재적), `faith_events`(신급), `office_terms`(직분) |
| 공동체 | `communities`, `small_groups`, `person_groups`, `group_notes` |
| 출석 | `worship_attendance`, `worship_totals`, `group_attendance` |
| 메모·동의·심방 | `notes`, `legacy_memos`, `consents`, `visits` |
| 조직 | `org_units`, `org_unit_staff`, `meeting_officers`, `affiliations` |
| 교육·일정 | `education_courses`, `education_records`, `events` |
| 기록·증명서 | `access_log`, `change_log`, `certificates`, `certificate_requests` |
| 설정·문자 | `site_settings`, `year_settings`, `sms_templates`, `sms_log` |

- 교인 영역의 id 는 화면이 만드는 UUID(`CHAR(36)`), 입력 순서는 `seq`. 교인구분·공동체 id 는 짧은 코드, 사용자 id 는 숫자
- 열 이름은 화면의 데이터 타입(`src/lib/registry/types.ts`)과 같다
- 재적 종류·직분 같은 고정 목록은 DB의 ENUM 이 아니라 PHP에서 검사한다 (새 값이 생겨도 DB를 바꾸지 않아도 됨)
- `is_sample`: 샘플로 넣은 행의 표시. 설정에서 샘플 삭제를 누르면 이 표시가 있는 행만 지운다

---

## 5. 자료를 읽고 쓰는 방식

화면은 로그인하면 `GET /bootstrap` 으로 **내 등급으로 볼 수 있는 표 전부**를 한 번에 받아 `registry.d` 에 담는다. 목록·검색·달력·통계는 전부 메모리에서 계산한다.

쓸 때는 지금까지처럼 `registry.d` 를 고치고 `save()` 를 부른다. `sync.svelte.ts` 가 0.4초 모아서, 서버와 맞춰 둔 마지막 상태와 **줄·칸 단위로** 비교해 바뀐 것만 `POST /sync` 로 보낸다.

서버(`api/lib/data.php` 의 `data_specs()`)는 표마다 정의를 가지고 있다: 열 이름과 종류, 읽기·쓰기 권한, 먼저 만들어져야 하는 순서(level), 이름으로 가리키는 칸(소그룹 이름 → id) 등.

- 한 묶음은 **하나의 트랜잭션**: 전부 저장되거나 전부 거절된다. 거절되면 화면이 안내를 띄우고 서버의 값으로 되돌린다
- 권한은 서버가 다시 계산한다. 심방·인도자 특이사항·이전 프로그램 메모는 A 등급이 아니면 `bootstrap` 에도 들어가지 않는다
- 쓴 사람·시간(메모, 변경 이력, 접속 기록, 증명서 처리자)은 서버가 로그인한 사람으로 채운다
- 교인 삭제는 최고관리자만. 원칙은 지우지 않고 '보관'으로 처리한다
- 다른 사람이 저장하면 `meta.data_version` 이 올라가고, 화면은 1분마다·창으로 돌아올 때 이 값을 물어 달라졌으면 다시 받는다
- 사진·로고·직인은 `data/uploads` 에 두고 PHP가 로그인·권한을 확인한 뒤 내려준다. 직인은 내려받기 권한이 있는 사람만

---

## 6. 권한

| 등급 | 할 수 있는 일 |
|---|---|
| A 전임 교역자 | 교적 수정, 내려받기·출력, **심방** |
| B 교적 관리 | 교적 수정, 내려받기·출력 |
| C 열람 | 보기만 |
| D 소그룹 인도자 | 교적 화면은 열 수 없고, 자기 소그룹의 주일 출석 체크(특이사항·기타 의견)만. 서버는 `/leader/…` 외의 요청을 `guard_leader` 로 막는다 |
| 최고관리자 | 모든 권한 + 가입 승인, 등급 지정·설정, 정지, 임시 비밀번호, 샘플, 일괄 수정, 교인 삭제 |

등급마다 수정·내려받기·사이트 관리는 최고관리자가 바꿀 수 있다. **심방은 A 등급 고정**이고 바꿀 수 없다.

---

## 7. 호스팅 제약 (카페24에서 확인한 것)

- 요청 본문이 **128KB** 를 넘으면 서버에 닿기 전에 `404 Not Found` HTML 로 막힌다. 그래서 `/sync` 는 100KB 이하로 나눠 보내고, 로고·직인 그림은 90KB 안으로 줄여 올린다. 파일을 올리는 기능은 이 한도 안에서 설계한다
- DB 접속 주소가 `localhost` 라서 서버 안(설치 마법사, API)에서만 접속된다
- 서버 안의 파일 저장은 `data/` 한 곳. 웹에서 직접 열리지 않도록 `.htaccess` 로 막는다 (`data/`, `migrations/`, `api/lib/`, `api/controllers/`)

---

## 7-1. 백업 · 정리 · 외부 서비스 (서버 기능)

- **백업** (`lib/backup.php`, `controllers/backup.php`): gzip 줄 단위 JSON. 헤더 → 표의 줄들 → 올린 그림(base64) → 끝 표시(`end`, 줄 수 확인). 끝 표시가 없거나 수가 다르면 되살리지 않는다. 되살리기는 한 번의 거래로 표를 비우고 채우며(FK 확인 끔), 그림은 새 폴더에 풀어 둔 뒤 폴더째 바꾼다. 지금 표에 없는 열은 버리고, 백업에 없는 열은 기본값. 구조 버전이 더 높은 백업은 거절. 되살린 뒤 세션을 끝낸다. 되살릴 파일은 60KB 조각으로 올린다(호스팅 128KB 한도). 외부 서비스 키(`meta.int_*`)는 백업에 넣지 않는다.
- **자동 백업·정리는 예약 작업 없이**: `/version`(화면이 1분마다 부르는 주소)의 응답을 보낸 뒤 `backup_maybe_run()`, `retention_maybe_run()` 을 부른다. 마지막 실행 시각(`meta`)과 설정된 주기로 때를 판단하고 잠금 파일(`data/backups/.lock`)로 동시 실행을 막는다. 실패하면 1시간 뒤 재시도, 알림 이메일은 `mail()` 이 있을 때만.
- **개인정보 정리** (`lib/services.php`): 마지막 재적 상태가 전출·별세·제적이고 날짜가 N년 지난 교인(샘플 제외)의 연락처·주소·생일·직업·메모·사진, 수정 이력(`change_log`)·메모(`notes`·`legacy_memos`)를 지우고 `purged_on` 을 남긴다. 직전에 `pre` 백업을 만든다.
- **외부 서비스** (`lib/services.php`, `controllers/services.php`): 문자는 솔라피 `send-many/detail`(HMAC-SHA256), AI 는 Gemini `generateContent`(JSON 응답). 키는 `meta.int_sms`/`int_ai` 에만 두고 화면에는 끝 4자리만 보낸다. 설정하면 `bootstrap.features` 가 켜지고 화면의 버튼이 나타난다. AI 의 답은 믿지 않고 칸마다 값을 걸러 낸다(날짜 검증, 목록에 있는 값만). 큰 파일은 `/uploads/chunk` 로 받아 쓰고 바로 지운다. 시험용으로 `data/config.php` 의 `sms_base`/`ai_base` 로 주소를 바꿀 수 있다.
- **증명서 번호**: `meta.cert_seq_<연도>` 를 거래 안에서 올려 번호를 정하고 `certificates` 에 남긴다. 번호 모양은 `cert_form`(접두어·형식·자리수·접미어).

---

## 7-2. 보안과 업데이트 안내

- **세션 끊기**: `users.session_epoch` 를 비밀번호 변경·임시 비밀번호 발급 때 올리고, 로그인할 때 세션에 적은 값(`$_SESSION['epoch']`)과 요청마다 비교한다(`current_user()`). 값이 다르면 그 세션은 로그아웃. 비밀번호를 바꾼 그 세션만 새 값으로 이어 가고 세션 번호도 새로 받는다.
- **보안 헤더**: `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`(카메라·마이크는 같은 사이트만), `CSP frame-ancestors 'self'`, https 일 때 `HSTS`. 사이트 폴더의 `.htaccess`(`ch-manage-headers` 블록, `/install/` 이 한 번 덧붙임)와 API 응답(`cm_security_headers()`) 양쪽에 둔다. 화면 쪽 `script-src` 는 SvelteKit 이 인라인 스크립트를 넣으므로 제한하지 않는다.
- **업데이트 마무리 안내**: `api/index.php` 가 DB 의 `schema_version` 이 `migrations/` 의 최신 번호보다 낮으면 `/system/status`·`/auth/me`·`/auth/logout` 외 모두 503 `update_pending`(설치 화면 주소 포함)으로 막는다. `/system/status` 의 `finalize_needed` 는 `.htaccess` 에 규칙 표시가 빠졌을 때 true 이고, 최고관리자 화면 위에 안내를 띄운다.

---

## 7-3. 재정 관리(fin-manage) 연결

재정 관리는 같은 도메인의 다른 폴더(`/fm`)에 설치되고 교적(`cm_`)을 읽기만 한다. 두 프로그램은 세션 쿠키 이름·경로가 달라 세션을 공유할 수 없으므로 **1회용 입장권**으로 넘어간다.

- 교적 화면의 '재정 열기' → `POST /auth/sso-ticket` (로그인한 세션만, 체험·인도자(D) 거절, 사용자당 분당 10회). 서버가 무작위 43자 입장권을 만들어 **sha256 해시만** `cm_sso_tickets`(마이그레이션 007)에 30초 동안 저장하고 원문은 응답으로만 준다.
- 화면이 `<사이트 폴더의 형제>/<재정 폴더>/sso?t=<입장권>` 으로 이동한다. 재정이 해시로 조회·삭제하고 자기 세션을 만든다. 입장권은 사용자에 묶이며 한 번만 쓸 수 있다.
- 재정이 설치되어 있는지는 `<접두어>meta` 표가 같은 DB 에 있는지로 확인한다(`/bootstrap` 의 `features.finance`). 폴더 이름(기본 `fm`)·표 접두어(기본 `fm_`)는 설정 › 외부 서비스에서 바꾼다(`meta.finance`).
- 교적은 재정의 표를 읽거나 쓰지 않고, 헌금 금액을 가져오지도 않는다. 문서 › 기부금 영수증 화면에는 안내와 링크만 보인다. 증명서 대장(`cm_certificates`)과 재정의 영수증 번호는 서로 섞지 않는다.
- `cm_sso_tickets` 는 30초짜리 임시 값이라 백업에 넣지 않는다.

## 8. dep-manage 와 함께 쓰기

같은 교회가 두 프로그램을 함께 쓰면 교육부서(유치부·유초등부·중고등부·청년부) 학생이 양쪽에 있다.

| 항목 | 주인 |
|---|---|
| 이름·성별·생년월일·연락처·주소·세례 | ch-manage (교적) |
| 부서·학년·반·출석·부서 심방·부서 메모 | dep-manage |
| 교사 | 이름·생년월일·연락처는 교적, 직분·임원·담당 반은 부서 관리 |

- **연결 표 `cm_links`** (`person_id`, `dm_kind`=student·teacher, `dm_id`)는 교적 쪽에만 있다. 상대 프로그램의 표를 만들거나 바꾸지 않으므로 한쪽만 쓰거나 나중에 하나를 추가해도 된다. `dm_id` 는 다른 프로그램의 표를 가리켜 외래키를 걸 수 없으므로, 지워진 학생·교사는 '끊어진 연결'로 알려 준다
- **감지**: 같은 DB에 부서 관리 표(접두어 기본 `dm_`, 설정에서 변경)와 연동에 필요한 열이 모두 있을 때만 `available`. 접두어가 같거나 열이 없으면 이유(`same_prefix`·`incompatible`·`not_installed`)와 함께 연동만 꺼지고 교적은 그대로 동작한다
- **처음 연결**: 부서 관리 학생·교사마다 교적 교인과 대응한다. 이름과 생년월일이 같으면 확실, 이름만 같거나 동명이인이면 사람이 고르고, 없으면 상대쪽에 만든다(교적에 만들 때는 화면에서 등록할 때와 같은 검사를 거친다)
- **교적 → 부서 관리**: 교적에서 저장하면 연결된 사람의 바뀐 칸을 바로 맞추고(`dm_after_sync`), 관리자 화면에서 미리보기 후 한꺼번에도 맞춘다. 비어 있는 연락처·주소는 부서 관리의 값을 지우지 않는다. 부서·학년·반은 건드리지 않고, 부서 이름이 다르면 '확인 필요'로만 알린다
- **부서 관리 → 교적**: 연결된 학생의 출석(모임 날짜가 속한 주일로 모음)을 읽어 교적 카드와 교육부서 통계에서 쓴다. 교적의 출석 입력과는 섞이지 않는다
- **dep-manage 쪽**: 연결된 학생·교사의 교적이 주인인 칸은 서버가 수정을 무시하고(`cm_is_linked`), 화면에서는 잠그고 "교적에서 수정" 안내를 보여 준다. 학생·교사를 지우면 연결도 지운다
- 사진(2차, `lib/dmextra.php`): 두 프로그램의 data 폴더가 다르므로 같은 호스팅 계정의 옆 폴더에서 접두어·DB 가 같은 부서 관리를 찾아(또는 최고관리자가 경로를 직접 지정) 읽고 쓴다. 한쪽에만 있으면 복사하고 양쪽에 있으면 그대로 둔다. 부서·학년은 부서 관리가 주인이라 학생의 부서가 바뀌면 교적의 교육부서(와 교인구분)를 맞춘다(수정 이력을 남김)
