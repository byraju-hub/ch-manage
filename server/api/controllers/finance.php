<?php
declare(strict_types=1);

// 재정 관리 연결: 1회용 입장권 발급, 재정 폴더 이름 설정.

route('POST', '/auth/sso-ticket', function () {
    $u = require_user();
    if ((int)($u['is_demo'] ?? 0) === 1) json_fail('demo_readonly', '체험 계정에서는 재정 관리를 열 수 없어요.', 403);
    if (is_leader_user($u)) json_fail('forbidden', '소그룹 인도자는 재정 관리를 열 수 없습니다.', 403);
    if (!finance_installed()) json_fail('finance_not_installed', '재정 관리가 설치되어 있지 않습니다.', 404);

    $tbl = t('sso_tickets');
    // 사용자당 분당 발급 횟수를 제한한다.
    if ((int)val("SELECT COUNT(*) FROM {$tbl} WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)", [$u['id']]) >= SSO_PER_MINUTE) {
        json_fail('too_many', '잠시 뒤에 다시 시도해 주세요.', 429);
    }
    q("DELETE FROM {$tbl} WHERE expires_at < (NOW() - INTERVAL 1 DAY)");
    // 원문은 응답으로만 내보내고, DB 에는 해시만 둔다.
    $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    q("INSERT INTO {$tbl} (token_hash, user_id, expires_at) VALUES (?, ?, NOW() + INTERVAL " . SSO_TTL_SECONDS . ' SECOND)', [hash('sha256', $ticket), $u['id']]);
    audit('재정 입장권 발급');
    header('Cache-Control: no-store');
    json_out(['ticket' => $ticket, 'path' => finance_config()['path']]);
});

// 재정 폴더 이름·표 접두어 (최고관리자만)
route('GET', '/finance/config', function () {
    require_super();
    $c = finance_config();
    json_out($c + ['installed' => finance_installed()]);
});

route('PUT', '/finance/config', function () {
    require_super();
    $b = body();
    $path = trim(in_str($b, 'path', 50));
    $prefix = trim(in_str($b, 'prefix', 20));
    if ($path === '') $path = 'fm';
    if ($prefix === '') $prefix = 'fm_';
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,50}$/', $path) || $path === '.' || $path === '..') json_fail('invalid', '폴더 이름은 영문, 숫자, - _ . 으로 적어 주세요.', 422);
    if (!preg_match('/^[a-z0-9_]{1,20}$/', $prefix)) json_fail('invalid', '표 접두어는 영문 소문자, 숫자, _ 로 적어 주세요. (예: fm_)', 422);
    meta_set('finance', json_encode(['path' => $path, 'prefix' => $prefix], JSON_UNESCAPED_UNICODE));
    audit('재정 연결 설정 변경');
    json_out(['path' => $path, 'prefix' => $prefix, 'installed' => finance_installed()]);
});
