<?php
declare(strict_types=1);

// 로그인, 로그아웃, 내 정보, 가입 신청, 비밀번호 바꾸기.
// 가입 신청 → 최고관리자가 승인하고 등급(A·B·C)을 정함 → 그때부터 로그인해서 쓸 수 있다.

/** 화면이 로그인 상태를 알기 위한 묶음. 로그인하지 않았으면 user 가 null. */
function me_payload(?array $u): array
{
    $church = (string)val("SELECT JSON_VALUE(church_info, '$.name') FROM " . t('site_settings') . ' WHERE id = 1');
    if (!$u) return ['church' => $church, 'user' => null, 'demoAccount' => demo_account_exists(), 'csrf' => csrf_token()];
    $dec = row('SELECT name FROM ' . t('users') . ' WHERE id = ?', [$u['decided_by'] ?? 0]);
    $u['decided_by_name'] = $dec['name'] ?? null;
    return ['church' => $church, 'user' => user_payload($u), 'perms' => perms_of($u), 'grades' => grade_defs(), 'csrf' => csrf_token()];
}

const DEMO_LOGIN = 'demo';
const DEMO_PASSWORD = '1234';

/** 체험 계정이 켜져 있는지 (로그인 화면이 안내를 보여 주는 데 씀). 아직 데이터베이스 변경 전이면 없는 것으로 본다. */
function demo_account_exists(): bool
{
    try {
        return (bool)val('SELECT 1 FROM ' . t('users') . ' WHERE login_id = ? AND is_demo = 1', [DEMO_LOGIN]);
    } catch (Throwable) {
        return false;
    }
}

/** 15분 안에 실패가 많으면 잠시 막는다. 아이디 기준 5번, 접속 주소 기준 20번. */
function login_throttled(string $loginId): bool
{
    $byId = (int)val('SELECT COUNT(*) FROM ' . t('login_attempts') . ' WHERE login_id = ? AND at > (NOW() - INTERVAL 15 MINUTE)', [$loginId]);
    $byIp = (int)val('SELECT COUNT(*) FROM ' . t('login_attempts') . ' WHERE ip = ? AND at > (NOW() - INTERVAL 15 MINUTE)', [client_ip()]);
    return $byId >= 5 || $byIp >= 20;
}

function check_new_password(string $pw): void
{
    if (mb_strlen($pw) < MIN_PASSWORD) json_fail('weak_password', '비밀번호는 ' . MIN_PASSWORD . '자 이상으로 만들어 주세요.', 422);
    if (mb_strlen($pw) > 200) json_fail('invalid', '비밀번호가 너무 깁니다.', 422);
}

function check_login_id(string $id): void
{
    if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $id)) {
        json_fail('invalid', '아이디는 영문, 숫자, - _ . 으로 3자 이상 만들어 주세요.', 422);
    }
}

route('GET', '/auth/me', function () {
    check_origin();
    json_out(me_payload(current_user()));
});

route('POST', '/auth/login', function () {
    check_origin();
    session_boot();
    $b = body();
    $loginId = in_str($b, 'loginId', 50, true, '아이디');
    $pw = (string)($b['password'] ?? '');
    if ($pw === '') json_fail('invalid', '비밀번호를 입력해 주세요.', 422);

    if (login_throttled($loginId)) {
        json_fail('throttled', '로그인에 여러 번 실패했습니다. 15분 뒤에 다시 시도해 주세요.', 429);
    }
    $u = row('SELECT * FROM ' . t('users') . ' WHERE login_id = ?', [$loginId]);
    if (!$u) {
        // 없는 아이디도 같은 시간이 걸리게 해서, 응답 시간으로 계정 유무가 드러나지 않게 한다.
        static $dummy = null;
        $dummy ??= password_hash('cm-dummy-password', PASSWORD_DEFAULT);
        password_verify($pw, $dummy);
    }
    if (!$u || !password_verify($pw, $u['password_hash'])) {
        q('INSERT INTO ' . t('login_attempts') . ' (ip, login_id) VALUES (?, ?)', [client_ip(), $loginId]);
        json_fail('bad_credentials', '아이디 또는 비밀번호가 맞지 않습니다.', 401);
    }
    if ($u['status'] === '승인 대기') json_fail('pending', '가입 신청이 접수되었습니다. 최고관리자가 승인하면 로그인할 수 있어요.', 403);
    if ($u['status'] !== '사용') json_fail('disabled', '사용이 정지된 계정입니다. 최고관리자에게 문의해 주세요.', 403);

    session_regenerate_id(true); // 세션 고정 공격 방지
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['seen'] = time();
    unset($_SESSION['csrf']);
    q('UPDATE ' . t('users') . ' SET last_login_at = NOW() WHERE id = ?', [$u['id']]);
    q('DELETE FROM ' . t('login_attempts') . ' WHERE login_id = ?', [$loginId]);
    if (random_int(1, 20) === 1) q('DELETE FROM ' . t('login_attempts') . ' WHERE at < (NOW() - INTERVAL 1 DAY)'); // 오래된 시도 기록 정리
    audit('로그인', $u['name']);
    json_out(me_payload($u));
});

route('POST', '/auth/logout', function () {
    check_origin();
    $u = current_user();
    if ($u) audit('로그아웃', $u['name']);
    session_boot();
    $_SESSION = [];
    session_regenerate_id(true);
    json_out(['csrf' => csrf_token()]);
});

// 가입 신청: 로그인 없이 누구나 낼 수 있지만, 승인 전에는 아무것도 볼 수 없다. 같은 주소에서 1시간에 5번까지.
route('POST', '/auth/signup', function () {
    check_origin();
    session_boot();
    $b = body();
    $loginId = in_str($b, 'loginId', 50, true, '아이디');
    check_login_id($loginId);
    $name = in_str($b, 'name', 50, true, '이름');
    $pw = (string)($b['password'] ?? '');
    check_new_password($pw);
    $phone = in_str($b, 'phone', 20);
    $email = in_str($b, 'email', 100);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_fail('invalid', '이메일 주소 모양이 올바르지 않습니다.', 422);

    if ((int)val('SELECT COUNT(*) FROM ' . t('login_attempts') . " WHERE login_id = '#signup' AND ip = ? AND at > (NOW() - INTERVAL 1 HOUR)", [client_ip()]) >= 5) {
        json_fail('throttled', '가입 신청을 너무 많이 했습니다. 잠시 뒤에 다시 시도해 주세요.', 429);
    }
    q('INSERT INTO ' . t('login_attempts') . " (ip, login_id) VALUES (?, '#signup')", [client_ip()]);

    if (val('SELECT 1 FROM ' . t('users') . ' WHERE login_id = ?', [$loginId])) {
        json_fail('duplicate', '이미 사용 중인 아이디입니다. 다른 아이디를 써 주세요.', 409);
    }
    $id = insert_id(
        'INSERT INTO ' . t('users') . ' (login_id, password_hash, name, phone, email) VALUES (?,?,?,?,?)',
        [$loginId, password_hash($pw, PASSWORD_DEFAULT), $name, $phone, $email]
    );
    audit('가입 신청', $name, ['userId' => $id]);
    json_out(['requested' => true], 201);
});

route('POST', '/auth/password', function () {
    $u = require_user(true);
    $b = body();
    $cur = (string)($b['current'] ?? '');
    $new = (string)($b['next'] ?? '');
    if (!password_verify($cur, $u['password_hash'])) {
        q('INSERT INTO ' . t('login_attempts') . ' (ip, login_id) VALUES (?, ?)', [client_ip(), $u['login_id']]);
        json_fail('bad_credentials', '현재 비밀번호가 맞지 않습니다.', 401);
    }
    check_new_password($new);
    if ($new === $cur) json_fail('invalid', '지금 쓰는 비밀번호와 다르게 만들어 주세요.', 422);
    q('UPDATE ' . t('users') . ' SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    audit('비밀번호 변경', $u['name']);
    json_out(null);
});

// 체험 계정: 아이디 demo / 비밀번호 1234. 모든 화면을 볼 수 있지만 서버가 보기 외의 요청을 막는다. 데모 사이트용이다.
route('POST', '/system/demo-account', function () {
    $u = require_super();
    $hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
    $cur = row('SELECT id, is_demo FROM ' . t('users') . ' WHERE login_id = ?', [DEMO_LOGIN]);
    if ($cur && (int)$cur['is_demo'] !== 1) json_fail('taken', "'" . DEMO_LOGIN . "' 아이디를 이미 다른 사람이 쓰고 있어요.", 409);
    if ($cur) {
        q('UPDATE ' . t('users') . " SET password_hash = ?, status = '사용', grade = 'A', is_super = 1 WHERE id = ?", [$hash, $cur['id']]);
    } else {
        q('INSERT INTO ' . t('users') . " (login_id, password_hash, name, status, grade, is_super, is_demo, decided_at, decided_by) VALUES (?, ?, '체험 계정', '사용', 'A', 1, 1, NOW(), ?)", [DEMO_LOGIN, $hash, $u['id']]);
    }
    audit('체험 계정 켜기', DEMO_LOGIN);
    json_out(['loginId' => DEMO_LOGIN]);
});

route('DELETE', '/system/demo-account', function () {
    require_super();
    q('DELETE FROM ' . t('users') . ' WHERE login_id = ? AND is_demo = 1', [DEMO_LOGIN]);
    audit('체험 계정 끄기', DEMO_LOGIN);
    json_out(null);
});
