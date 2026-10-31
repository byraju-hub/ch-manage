<?php
declare(strict_types=1);

// 세션, CSRF 방어, 등급(권한) 계산.
//
// 등급: A 전임 교역자 > B 교적 관리 > C 열람. 최고관리자(is_super)는 모든 권한을 갖는다.
// 심방(심방 기록·소그룹 특이사항·이전 프로그램 메모)은 A 등급만이다. 등급 설정이나 최고관리자 여부와 관계없이 고정.

const SESSION_IDLE_SECONDS = 30 * 60; // 30분 동안 쓰지 않으면 로그아웃 (화면의 자동 로그아웃과 같은 값)
const MIN_PASSWORD = 8;

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // 같은 도메인의 다른 프로그램과 쿠키가 섞이지 않도록 이 사이트 폴더(예: /cm)에만 쿠키를 보낸다.
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    // 세션 파일은 이 프로그램 전용 폴더에 둔다. (공용 폴더는 다른 프로그램이 정한 짧은 만료 시간에 지워질 수 있다.)
    $dir = CM_DATA . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)(SESSION_IDLE_SECONDS * 4));
    session_name('cm_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $base === '' ? '/' : $base,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // 오래 쓰지 않은 세션은 끝낸다.
    if (isset($_SESSION['uid'], $_SESSION['seen']) && time() - (int)$_SESSION['seen'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
    }
    if (isset($_SESSION['uid'])) $_SESSION['seen'] = time();
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function is_unsafe_method(): bool
{
    return !in_array(req_method(), ['GET', 'HEAD', 'OPTIONS'], true);
}

/** 다른 사이트에서 보낸 요청이면 막는다 (Origin 의 주소가 이 사이트와 다를 때). */
function check_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return;
    $oh = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $hh = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($oh !== $hh) json_fail('forbidden_origin', '허용되지 않는 요청입니다.', 403);
}

function verify_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $have = $_SESSION['csrf'] ?? '';
    if ($have === '' || !is_string($sent) || !hash_equals($have, $sent)) {
        json_fail('csrf', '페이지가 오래되었습니다. 새로고침한 뒤 다시 시도해 주세요.', 403);
    }
}

/** @return array<string, mixed>|null 로그인한 사용자 (승인되어 '사용' 상태인 계정만) */
function current_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    session_boot();
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return $u = null;
    $row = row('SELECT * FROM ' . t('users') . ' WHERE id = ?', [$id]);
    if (!$row || $row['status'] !== '사용') {
        unset($_SESSION['uid']);
        return $u = null;
    }
    return $u = $row;
}

/** 로그인을 요구한다. 변경 요청이면 CSRF 토큰도 확인한다. */
function require_user(bool $allowPasswordChange = false): array
{
    check_origin();
    $u = current_user();
    if (!$u) json_fail('unauthorized', '로그인이 필요합니다.', 401);
    if (is_unsafe_method()) verify_csrf();
    if ((int)$u['must_change_password'] === 1 && !$allowPasswordChange) {
        json_fail('password_change_required', '비밀번호를 먼저 바꿔 주세요.', 403);
    }
    return $u;
}

/** 최고관리자만 (가입 승인·등급 지정·등급 설정) */
function require_super(): array
{
    $u = require_user();
    if ((int)$u['is_super'] !== 1) json_fail('forbidden', '최고관리자만 할 수 있습니다.', 403);
    return $u;
}

/** 등급별 권한 목록(grades 표)을 화면이 쓰는 모양으로. */
function grade_defs(): array
{
    $out = [];
    foreach (rows('SELECT * FROM ' . t('grades') . ' ORDER BY grade') as $g) {
        $out[] = [
            'grade' => $g['grade'], 'name' => $g['name'],
            'perms' => [
                'edit' => (int)$g['can_edit'] === 1, 'export' => (int)$g['can_export'] === 1,
                'visit' => $g['grade'] === 'A', // 심방은 A 등급 고정
                'admin' => (int)$g['is_site_admin'] === 1,
            ],
        ];
    }
    return $out;
}

/** 이 사용자가 할 수 있는 일. 서버는 모든 요청에서 이 값으로 다시 확인한다. */
function perms_of(array $u): array
{
    $none = ['edit' => false, 'export' => false, 'visit' => false, 'admin' => false];
    if ($u['status'] !== '사용') return $none;
    $visit = $u['grade'] === 'A';
    if ((int)$u['is_super'] === 1) return ['edit' => true, 'export' => true, 'visit' => $visit, 'admin' => true];
    foreach (grade_defs() as $g) {
        if ($g['grade'] === $u['grade']) return array_merge($g['perms'], ['visit' => $visit]);
    }
    return $none;
}

function require_perm(string $perm): array
{
    $u = require_user();
    if (!(perms_of($u)[$perm] ?? false)) json_fail('forbidden', '이 작업을 할 권한이 없습니다.', 403);
    return $u;
}

/** 화면이 쓰는 회원 모양. 비밀번호 관련 값은 절대 넣지 않는다. */
function user_payload(array $u): array
{
    return [
        'id' => (string)$u['id'],
        'login_id' => $u['login_id'],
        'name' => $u['name'],
        'email' => $u['email'] === '' ? null : $u['email'],
        'phone' => $u['phone'] === '' ? null : $u['phone'],
        'person_id' => $u['person_id'] === null ? null : (string)$u['person_id'],
        'status' => $u['status'],
        'grade' => $u['grade'],
        'is_super' => (int)$u['is_super'] === 1,
        'is_demo' => (int)($u['is_demo'] ?? 0) === 1,
        'requested_at' => substr((string)$u['requested_at'], 0, 10),
        'decided_at' => $u['decided_at'] === null ? null : substr((string)$u['decided_at'], 0, 10),
        'decided_by' => $u['decided_by_name'] ?? null,
        'note' => $u['note'] === '' ? null : $u['note'],
        'must_change_password' => (int)$u['must_change_password'] === 1,
    ];
}

/** 접속 기록. 쓴 사람 이름은 로그인한 사용자에서 가져온다. 기록이 실패해도 본 작업은 막지 않되, 실패는 app.log 에 남긴다. */
function audit(string $action, string $target = '', ?array $detail = null): void
{
    try {
        session_boot();
        $uid = $_SESSION['uid'] ?? null;
        $who = $uid ? (string)val('SELECT name FROM ' . t('users') . ' WHERE id = ?', [$uid]) : '';
        // 자세한 내용(detail)은 한 줄로 줄여 대상 칸에 붙인다 (접속 기록 표에는 대상 칸 하나뿐)
        if ($detail) $target = trim($target . ' ' . json_encode($detail, JSON_UNESCAPED_UNICODE));
        q(
            'INSERT INTO ' . t('access_log') . ' (id, user_id, who, action, target) VALUES (?,?,?,?,?)',
            [new_uuid(), $uid, mb_substr($who, 0, 50), mb_substr($action, 0, 40), mb_substr($target, 0, 100)]
        );
    } catch (Throwable $e) {
        cm_log('error', 'audit: ' . $e->getMessage());
    }
}

/** 소그룹 인도자(D 등급)인가. 최고관리자로 지정되면 인도자 전용 제한을 받지 않는다. */
function is_leader_user(array $u): bool
{
    return $u['status'] === '사용' && ($u['grade'] ?? null) === 'D' && (int)$u['is_super'] !== 1;
}
