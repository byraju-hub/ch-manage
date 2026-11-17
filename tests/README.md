# 시험 스크립트

운영 서버가 아니라 **내 컴퓨터에 만든 시험 사이트**에서 돌리는 스크립트입니다. (시험 계정·자료는 모두 가짜입니다.)
Windows + XAMPP(PHP 8.x, MariaDB) + Edge 에서 만들었고, 다른 환경이면 환경 변수로 위치를 알려 주세요.

| 환경 변수 | 기본값 |
|---|---|
| `CM_MYSQL` | `C:/xampp/mysql/bin/mysql.exe` |
| `CM_BROWSER` | `C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe` |

## 시험 사이트 만들기
1. MariaDB 를 포트 3307 로 띄운다. (예: `mysqld --no-defaults --datadir=tests/env/mdata --port=3307 --bind-address=127.0.0.1`, 아이디 root / 비밀번호 root)
2. `bash tests/env/fresh.sh` — `tests/env/www/cm` 에 서버 파일을 복사하고 설치까지 자동으로 진행 (관리자 `admin` / `password1`, 교회 이름 `시험교회`)
3. 화면도 시험하려면: `BASE_PATH=/cm npm run build` 후 `build/` 의 내용을 `tests/env/www/cm/` 에 복사
4. PHP 서버: `php -S 127.0.0.1:8090 -t tests/env/www tests/env/router.php`  (경로는 절대 경로로)

## 스크립트
| 파일 | 하는 일 | 실행 |
|---|---|---|
| `sso-test.mjs` | 서버 쪽: 재정 입장권·백업·설정 (39개) | `node tests/sso-test.mjs http://127.0.0.1:8090/cm` (새로 설치한 직후) |
| `ui-sso.cjs` | 화면: 재정 버튼·설정 칸·기부금 영수증 안내 | `node tests/ui-sso.cjs <결과 폴더>` |
| `ui-nav.cjs` | 화면: 상단 메뉴가 폭마다 한 줄로 나오는지 | `node tests/ui-nav.cjs <결과 폴더>` |
| `priv-check.cjs`, `foot-check.cjs`, `contact-check.cjs` | 개인정보 처리방침 화면·링크·책임자 칸 | `node tests/<파일> <결과 폴더>` |
| `pdf-check.cjs` | 문서 PDF 내려받기, 엑셀 내려받기 | `node tests/pdf-check.cjs <결과 폴더>` (샘플 자료를 자동으로 넣음) |

참고: 화면 시험은 `ui-sso.cjs` 처럼 이름 그대로가 아닌 버튼 글자("재정 열기" → "재정")가 바뀌면 고쳐야 할 수 있습니다.
