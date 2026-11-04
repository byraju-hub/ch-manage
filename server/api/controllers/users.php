<?php
declare(strict_types=1);

// 사용자 관리(최고관리자 전용): 가입 승인, 등급 지정, 정지, 최고관리자 지정, 교인 연결, 임시 비밀번호, 삭제.
// 등급별 할 수 있는 일(수정·내려받기·사이트 관리)과 등급 이름도 여기서 바꾼다.

function users_list(): array
{
    $sql = 'SELECT u.*, d.name AS decided_by_name FROM ' . t('users') . ' u LEFT JOIN ' . t('users') . ' d ON d.id = u.decided_by'
        . " ORDER BY (u.status = '승인 대기') DESC, u.is_super DESC, u.requested_at DESC, u.id DESC";
    return array_map('user_payload', rows($sql));
}

function find_user(int $id): array
{
    $u = row('SELECT * FROM ' . t('users') . ' WHERE id = ?', [$id]);
    if (!$u) json_fail('not_found', '사용자를 찾을 수 없습니다.', 404);
    return $u;
}

function super_count(): int
{
    return (int)val('SELECT COUNT(*) FROM ' . t('users') . " WHERE is_super = 1 AND status = '사용'");
}

route('GET', '/users', function () {
    require_super();
    json_out(['users' => users_list(), 'grades' => grade_defs()]);
});

// 가입 신청 승인: 등급을 정해서 '사용'으로
route('POST', '/users/{id}/approve', function (int $id) {
    $me = require_super();
    $u = find_user($id);
    if ($u['status'] !== '승인 대기') json_fail('invalid', '승인 대기 중인 신청이 아닙니다.', 409);
    $grade = in_enum(body(), 'grade', ['A', 'B', 'C', 'D'], '등급');
    q('UPDATE ' . t('users') . " SET status = '사용', grade = ?, decided_at = NOW(), decided_by = ? WHERE id = ?", [$grade, $me['id'], $id]);
    audit('회원 승인', $u['name'], ['grade' => $grade]);
    json_out(['users' => users_list()]);
});

route('POST', '/users/{id}/grade', function (int $id) {
    require_super();
    $u = find_user($id);
    $grade = in_enum(body(), 'grade', ['A', 'B', 'C', 'D'], '등급');
    q('UPDATE ' . t('users') . ' SET grade = ? WHERE id = ?', [$grade, $id]);
    audit('등급 변경', $u['name'], ['grade' => $grade]);
    json_out(['users' => users_list()]);
});

route('POST', '/users/{id}/status', function (int $id) {
    $me = require_super();
    $u = find_user($id);
    if ($id === (int)$me['id']) json_fail('forbidden', '내 계정은 정지할 수 없습니다.', 403);
    $status = in_enum(body(), 'status', ['사용', '정지'], '상태');
    if ($status === '정지' && (int)$u['is_super'] === 1 && super_count() <= 1) json_fail('forbidden', '마지막 최고관리자는 정지할 수 없습니다.', 403);
    q('UPDATE ' . t('users') . ' SET status = ?, decided_at = NOW(), decided_by = ?, grade = IF(? = \'사용\' AND grade IS NULL, \'C\', grade) WHERE id = ?', [$status, $me['id'], $status, $id]);
    audit('회원 ' . ($status === '사용' ? '사용 재개' : '정지'), $u['name']);
    json_out(['users' => users_list()]);
});

route('POST', '/users/{id}/super', function (int $id) {
    $me = require_super();
    $u = find_user($id);
    $value = !empty(body()['value']);
    if ($id === (int)$me['id'] && !$value) json_fail('forbidden', '내 최고관리자 권한은 스스로 해제할 수 없습니다.', 403);
    if ($value && $u['status'] !== '사용') json_fail('invalid', '승인된 사용자만 최고관리자로 지정할 수 있습니다.', 409);
    q('UPDATE ' . t('users') . ' SET is_super = ? WHERE id = ?', [$value ? 1 : 0, $id]);
    audit($value ? '최고관리자 지정' : '최고관리자 해제', $u['name']);
    json_out(['users' => users_list()]);
});

// 교적의 교인과 연결 (교역자 등). 교인이 아직 서버에 없는 단계에서는 연결할 수 있는 사람이 없다.
route('POST', '/users/{id}/person', function (int $id) {
    require_super();
    $u = find_user($id);
    $pid = body()['personId'] ?? null;
    if ($pid !== null) {
        $pid = strtolower((string)$pid);
        if (!preg_match('/^[0-9a-f-]{36}$/', $pid) || !val('SELECT 1 FROM ' . t('people') . ' WHERE id = ?', [$pid])) json_fail('not_found', '연결할 교인을 찾을 수 없습니다.', 404);
    }
    q('UPDATE ' . t('users') . ' SET person_id = ? WHERE id = ?', [$pid, $id]);
    audit('교인 연결', $u['name']);
    json_out(['users' => users_list()]);
});

// 임시 비밀번호 만들기: 메일로 재설정하는 기능이 없으므로, 최고관리자가 만들어 알려 주고 사용자는 다음 로그인 때 바꾼다.
route('POST', '/users/{id}/reset-password', function (int $id) {
    $me = require_super();
    $u = find_user($id);
    if ($id === (int)$me['id']) json_fail('forbidden', '내 비밀번호는 \'비밀번호 바꾸기\'로 바꿔 주세요.', 403);
    $tmp = temp_password();
    q('UPDATE ' . t('users') . ' SET password_hash = ?, must_change_password = 1, session_epoch = session_epoch + 1 WHERE id = ?', [password_hash($tmp, PASSWORD_DEFAULT), $id]);
    q('DELETE FROM ' . t('login_attempts') . ' WHERE login_id = ?', [$u['login_id']]);
    audit('임시 비밀번호 발급', $u['name']);
    json_out(['tempPassword' => $tmp]);
});

route('DELETE', '/users/{id}', function (int $id) {
    $me = require_super();
    $u = find_user($id);
    if ($id === (int)$me['id']) json_fail('forbidden', '내 계정은 지울 수 없습니다.', 403);
    if ((int)$u['is_super'] === 1 && super_count() <= 1) json_fail('forbidden', '마지막 최고관리자는 지울 수 없습니다.', 403);
    q('DELETE FROM ' . t('users') . ' WHERE id = ?', [$id]);
    audit('회원 삭제', $u['name']);
    json_out(['users' => users_list()]);
});

// 등급 이름 또는 등급별 권한 바꾸기. 심방은 A 등급 고정이라 바꿀 수 없다.
route('POST', '/grades/{grade}', function (string $grade) {
    require_super();
    $b = body();
    if (array_key_exists('name', $b)) {
        $name = in_str($b, 'name', 40, true, '등급 이름');
        q('UPDATE ' . t('grades') . ' SET name = ? WHERE grade = ?', [$name, $grade]);
        audit('등급 이름 변경', $grade, ['name' => $name]);
    } else {
        if ($grade === 'D') json_fail('forbidden', 'D 등급(소그룹 인도자)의 권한은 바꿀 수 없습니다.', 403);
        $perm = in_enum($b, 'perm', ['edit', 'export', 'admin'], '권한');
        $col = ['edit' => 'can_edit', 'export' => 'can_export', 'admin' => 'is_site_admin'][$perm];
        $value = !empty($b['value']) ? 1 : 0;
        q('UPDATE ' . t('grades') . " SET $col = ? WHERE grade = ?", [$value, $grade]);
        audit('등급 권한 ' . ($value ? '부여' : '해제'), $grade, ['perm' => $perm]);
    }
    json_out(['grades' => grade_defs()]);
});
