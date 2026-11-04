<?php
declare(strict_types=1);

// 백업: 지금 내려받기, 서버에 보관, 되살리기, 자동 백업 상태. 모두 최고관리자만.
// 백업 파일에는 비밀번호(해시)와 심방 기록까지 모두 들어 있으므로 다른 등급에는 열어 주지 않는다.

function backup_status_payload(): array
{
    return [
        'zlib' => backup_zlib_ok(),
        'lastAuto' => meta_get('backup_last_auto'),
        'lastManual' => meta_get('backup_last_manual'),
        'failedAt' => meta_get('backup_failed_at'),
        'saved' => backup_list(),
        'chunk' => BACKUP_CHUNK,
    ];
}

route('GET', '/backup/status', function () {
    require_super();
    json_out(backup_status_payload());
});

/** 지금 백업을 만들어 바로 내려받는다. (주소를 열면 파일이 내려온다) */
route('GET', '/backup/download', function () {
    require_super();
    $tmp = backup_dir() . '/dl-' . bin2hex(random_bytes(4)) . '.tmp';
    try {
        backup_create($tmp, '직접 내려받기');
    } catch (Throwable $e) {
        cm_log('error', 'backup download: ' . $e->getMessage());
        json_fail('backup_failed', '백업을 만들지 못했습니다. ' . $e->getMessage(), 500);
    }
    meta_set('backup_last_manual', date('Y-m-d H:i:s'));
    audit('백업 내려받기', '', ['bytes' => filesize($tmp)]);
    $fn = '교적-백업-' . date('Y-m-d-His') . '.cmbak';
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"cm-backup.cmbak\"; filename*=UTF-8''" . rawurlencode($fn));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    @unlink($tmp);
    exit;
});

/** 서버에 보관한 백업 파일 내려받기 */
route('GET', '/backup/file/{bname}', function (string $name) {
    require_super();
    $f = backup_dir() . '/' . $name;
    if (!backup_valid_name($name) || !is_file($f)) json_fail('not_found', '백업 파일을 찾을 수 없습니다.', 404);
    audit('보관 백업 내려받기', $name);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: no-store');
    readfile($f);
    exit;
});

/** 지금 백업을 서버에 보관해 둔다. */
route('POST', '/backup/run', function () {
    require_super();
    try {
        backup_create(backup_dir() . '/' . backup_name('manual'), '서버에 직접 보관');
    } catch (Throwable $e) {
        cm_log('error', 'backup run: ' . $e->getMessage());
        json_fail('backup_failed', '백업을 만들지 못했습니다. ' . $e->getMessage(), 500);
    }
    backup_prune('manual', max(1, (int)(backup_cfg()['keep'] ?? 30)));
    audit('백업 서버 보관');
    json_out(backup_status_payload());
});

route('POST', '/backup/delete', function () {
    require_super();
    $name = in_str(body(), 'name', 60, true, '파일 이름');
    if (!backup_valid_name($name)) json_fail('invalid', '파일 이름이 올바르지 않습니다.', 422);
    @unlink(backup_dir() . '/' . $name);
    audit('보관 백업 삭제', $name);
    json_out(backup_status_payload());
});

/** 되살릴 파일을 조각(60KB 정도)으로 받는다. 호스팅이 한 번에 받는 크기가 128KB 로 제한되어 있기 때문이다. */
route('POST', '/backup/upload', function () {
    require_super();
    $b = body();
    $id = (string)($b['id'] ?? '');
    $seq = (int)($b['seq'] ?? -1);
    $data = base64_decode((string)($b['data'] ?? ''), true);
    if ($data === false || $seq < 0 || strlen($data) > BACKUP_CHUNK) json_fail('invalid', '올리는 파일 조각이 올바르지 않습니다.', 422);
    $path = backup_upload_path($id);
    backup_clean_uploads();
    if ($seq === 0) @unlink($path);
    if ($seq > 0 && (!is_file($path) || (int)filesize($path) !== $seq * BACKUP_CHUNK)) json_fail('invalid', '올리는 순서가 맞지 않습니다. 처음부터 다시 올려 주세요.', 409);
    if (file_put_contents($path, $data, FILE_APPEND | LOCK_EX) === false) json_fail('server_error', '파일을 저장하지 못했습니다. data 폴더 권한과 남은 용량을 확인해 주세요.', 500);
    if ((int)filesize($path) > 200_000_000) { @unlink($path); json_fail('invalid', '파일이 너무 큽니다.', 413); }
    json_out(['size' => (int)filesize($path)]);
});

function backup_restore_source(array $b): string
{
    $src = (string)($b['source'] ?? '');
    if (str_starts_with($src, 'upload:')) {
        $path = backup_upload_path(substr($src, 7));
    } elseif (str_starts_with($src, 'saved:')) {
        $name = substr($src, 6);
        if (!backup_valid_name($name)) json_fail('invalid', '파일 이름이 올바르지 않습니다.', 422);
        $path = backup_dir() . '/' . $name;
    } else {
        json_fail('invalid', '되살릴 파일을 골라 주세요.', 422);
    }
    if (!is_file($path)) json_fail('not_found', '되살릴 파일을 찾을 수 없습니다. 다시 올려 주세요.', 404);
    return $path;
}

/** 올린 파일이 올바른 백업인지만 확인해서 내용을 알려 준다. (아직 바꾸지 않는다) */
route('POST', '/backup/check', function () {
    require_super();
    $path = backup_restore_source(body());
    try {
        $info = backup_inspect($path);
    } catch (Throwable $e) {
        json_fail('invalid_backup', $e->getMessage(), 422);
    }
    json_out(['at' => $info['at'], 'version' => $info['version'], 'schema' => $info['schema'], 'rows' => $info['rows'], 'files' => $info['files'], 'note' => $info['note'] ?? '']);
});

/** 백업 파일로 지금 자료를 모두 바꾼다. 비밀번호를 한 번 더 확인한다. 끝나면 모두 로그아웃된다. */
route('POST', '/backup/restore', function () {
    $u = require_super();
    $b = body();
    if (!password_verify((string)($b['password'] ?? ''), (string)$u['password_hash'])) json_fail('invalid_password', '비밀번호가 맞지 않습니다.', 403);
    $path = backup_restore_source($b);
    try {
        $res = backup_restore($path);
    } catch (Throwable $e) {
        cm_log('error', 'backup restore: ' . $e->getMessage());
        json_fail('restore_failed', '되살리지 못했습니다. ' . $e->getMessage(), 500);
    }
    if (str_starts_with((string)($b['source'] ?? ''), 'upload:')) @unlink($path);
    // 사용자 목록이 백업 시점으로 바뀌었으므로 이 세션을 끝낸다.
    $who = (string)$u['name'];
    $_SESSION['uid'] = null;
    audit('백업 되살리기', $who, ['backup' => $res['at'], 'rows' => $res['rows'], 'files' => $res['files']]);
    $_SESSION = [];
    json_out(['restored' => $res, 'relogin' => true]);
});
