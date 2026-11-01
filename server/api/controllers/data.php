<?php
declare(strict_types=1);

// 교적 데이터: 한 번에 받기(bootstrap), 바뀐 것만 저장하기(sync), 사진·로고·직인 파일, 샘플 데이터.

/** 내가 볼 수 있는 모든 데이터. 로그인할 때와 서버 자료가 바뀌었을 때 화면이 부른다. */
route('GET', '/bootstrap', function () {
    $u = require_user();
    json_out(data_bootstrap($u));
});

/** 서버 자료의 버전. 다른 사람이 저장하면 올라간다. 화면이 가끔 물어보고, 달라졌으면 다시 받는다. */
route('GET', '/version', function () {
    require_user();
    json_out(['version' => data_version()]);
});

/** 바뀐 칸 저장. 한 번에 몇 곳을 고쳐도 전부 되거나 전부 안 되거나 둘 중 하나. */
route('POST', '/sync', function () {
    $u = require_user();
    $ops = body()['ops'] ?? null;
    if (!is_array($ops) || count($ops) > DATA_MAX_OPS) json_fail('invalid', '저장 요청이 올바르지 않습니다.', 422);
    try {
        $res = data_apply($u, $ops);
    } catch (SyncError $e) {
        json_fail('sync_rejected', ($e->table !== '' ? "[{$e->table}] " : '') . $e->getMessage(), 422);
    }
    dm_after_sync($ops); // 부서 관리와 연결된 교인이면 바뀐 인적사항을 부서 관리에도 맞춘다 (실패해도 저장은 그대로)
    json_out($res);
});

function data_serve_image(?string $rel): never
{
    if (!$rel || str_contains($rel, '..')) json_fail('not_found', '파일을 찾을 수 없습니다.', 404);
    $f = data_upload_dir() . '/' . $rel;
    if (!is_file($f)) json_fail('not_found', '파일을 찾을 수 없습니다.', 404);
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: private, max-age=86400'); // 주소 뒤의 ?v= 가 바뀌면 새로 받는다
    header('X-Content-Type-Options: nosniff');
    readfile($f);
    exit;
}

route('GET', '/files/photo/{uuid}', function (string $id) {
    require_user();
    data_serve_image(val('SELECT photo FROM ' . t('people') . ' WHERE id = ?', [strtolower($id)]));
});

/** 교회가 올린 탭 아이콘. 없으면 기본 아이콘(사이트 폴더의 favicon-32.png)으로 보낸다. */
function serve_favicon(): never
{
    $rel = val('SELECT favicon_path FROM ' . t('site_settings') . ' WHERE id = 1');
    if ($rel && !str_contains((string)$rel, '..') && is_file(data_upload_dir() . '/' . $rel)) {
        $f = data_upload_dir() . '/' . $rel;
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'image/png';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($f));
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($f);
        exit;
    }
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    header('Cache-Control: public, max-age=3600');
    header('Location: ' . $base . '/favicon-32.png', true, 302);
    exit;
}

// 교회 로고, 직인. 직인은 증명서를 만들 수 있는 사람(내려받기 권한)만.
route('GET', '/files/{name}', function (string $name) {
    if ($name === 'favicon') serve_favicon(); // 탭 아이콘은 로그인 화면에서도 보여야 하므로 로그인 없이 열린다
    $u = require_user();
    if ($name === 'seal' && !perms_of($u)['export']) json_fail('forbidden', '이 작업을 할 권한이 없습니다.', 403);
    data_serve_image(val('SELECT ' . ($name === 'seal' ? 'seal_path' : 'logo_path') . ' FROM ' . t('site_settings') . ' WHERE id = 1'));
});

// ===== 샘플 데이터: 화면의 가상 자료를 넣어 본 것 (is_sample 표시). 지우면 직접 입력한 자료는 그대로 남는다. =====
route('GET', '/sample/status', function () {
    require_super();
    json_out([
        'people' => (int)val('SELECT COUNT(*) FROM ' . t('people')),
        'samplePeople' => (int)val('SELECT COUNT(*) FROM ' . t('people') . ' WHERE is_sample = 1'),
    ]);
});

route('POST', '/sample/delete', function () {
    $u = require_super();
    $pdo = db();
    $photos = array_column(rows('SELECT photo FROM ' . t('people') . ' WHERE is_sample = 1 AND photo IS NOT NULL'), 'photo');
    $pdo->beginTransaction();
    try {
        $n = [];
        // 교인을 지우면 이력·출석·세대원 등 딸린 기록이 함께 지워진다. 소그룹·공동체는 그다음.
        foreach (['visits', 'events', 'people', 'households', 'small_groups', 'communities', 'org_units', 'education_courses'] as $tbl) {
            $n[$tbl] = q('DELETE FROM ' . t($tbl) . ' WHERE is_sample = 1')->rowCount();
        }
        q('UPDATE ' . t('meta') . " SET v = v + 1 WHERE k = 'data_version'");
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        cm_log('error', 'sample delete: ' . $e->getMessage());
        json_fail('sample_delete_failed', '샘플을 지우지 못했습니다. 샘플 교인과 직접 입력한 자료가 연결되어 있을 수 있습니다.', 409);
    }
    foreach ($photos as $p) data_delete_file($p);
    audit('샘플 데이터 삭제', '', $n);
    json_out(['deleted' => $n]);
});
