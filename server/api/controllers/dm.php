<?php
declare(strict_types=1);

// 부서 관리(dep-manage) 연동 API. 연결을 만들고 고치는 일은 최고관리자만, 출석 읽기는 로그인한 사용자 모두.
// 상세한 규칙은 lib/dm.php 에 있다.

/** 연동이 되는지, 연결이 몇 개인지. 부서 관리가 없으면 available=false (화면은 연동 메뉴를 숨긴다). */
route('GET', '/dm/status', function () {
    require_user();
    $s = dm_status();
    json_out($s + ['links' => $s['available'] ? (int)val('SELECT COUNT(*) FROM ' . t('links')) : 0]);
});

/** 화면이 쓰는 연결 정보와 연결된 학생의 최근 출석 */
route('GET', '/dm/overview', function () {
    require_user();
    if (!dm_status()['available']) json_out(['available' => false, 'links' => (object)[], 'attendance' => []]);
    $weeks = (int)($_GET['weeks'] ?? 26);
    json_out(['available' => true] + dm_overview($weeks));
});

route('POST', '/dm/prefix', function () {
    require_super();
    $p = trim((string)(body()['prefix'] ?? ''));
    if (!valid_prefix($p)) json_fail('invalid', '접두어는 영문 소문자로 시작하고 밑줄(_)로 끝나게 써 주세요. 예: dm_', 422);
    q('INSERT INTO ' . t('meta') . " (k, v) VALUES ('dm_prefix', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$p]);
    audit('부서 관리 접두어 변경', $p);
    json_out(['saved' => true]);
});

route('GET', '/dm/candidates', function () {
    require_super();
    dm_require();
    json_out(dm_candidates());
});

route('GET', '/dm/links', function () {
    require_super();
    dm_require();
    json_out(['links' => dm_links_view()]);
});

route('POST', '/dm/links', function () {
    $u = require_super();
    dm_require();
    $pairs = body()['links'] ?? null;
    if (!is_array($pairs) || count($pairs) < 1 || count($pairs) > 500) json_fail('invalid', '연결할 목록이 올바르지 않습니다. (한 번에 500건까지)', 422);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $n = dm_create_links($pairs, (int)$u['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit('부서 관리 연결', '', ['count' => $n]);
    json_out(['linked' => $n]);
});

route('DELETE', '/dm/links/{id}', function (int $id) {
    require_super();
    if (!val('SELECT 1 FROM ' . t('links') . ' WHERE id = ?', [$id])) json_fail('not_found', '연결을 찾을 수 없습니다.', 404);
    q('DELETE FROM ' . t('links') . ' WHERE id = ?', [$id]);
    q('UPDATE ' . t('meta') . " SET v = v + 1 WHERE k = 'data_version'");
    audit('부서 관리 연결 끊기', (string)$id);
    json_out(['removed' => true]);
});

route('GET', '/dm/sync/preview', function () {
    require_super();
    dm_require();
    json_out(['items' => dm_sync_preview()]);
});

route('POST', '/dm/sync', function () {
    require_super();
    dm_require();
    $ids = body()['linkIds'] ?? null;
    if ($ids !== null && !is_array($ids)) json_fail('invalid', '목록이 올바르지 않습니다.', 422);
    $n = dm_sync_apply($ids === null ? null : array_map('intval', $ids));
    audit('부서 관리 맞추기', '', ['count' => $n]);
    json_out(['synced' => $n]);
});

route('GET', '/dm/departments', function () {
    require_super();
    dm_require();
    json_out(['departments' => dm_departments()]);
});

route('POST', '/dm/create-in-dm', function () {
    $u = require_super();
    dm_require();
    $b = body();
    $kind = in_enum($b, 'kind', ['student', 'teacher'], '종류');
    $pid = strtolower((string)($b['personId'] ?? ''));
    $p = preg_match(DATA_UUID_RX, $pid) ? dm_person($pid) : null;
    if (!$p) json_fail('not_found', '교인을 찾을 수 없습니다.', 404);
    $id = dm_create_in_dm($p, $kind, (int)($b['deptId'] ?? 0), (int)($b['gradeId'] ?? 0), (int)$u['id']);
    audit('부서 관리에 만들기', $p['name'], ['kind' => $kind]);
    json_out(['dmId' => $id]);
});

route('POST', '/dm/create-in-cm', function () {
    $u = require_super();
    dm_require();
    $b = body();
    $kind = in_enum($b, 'kind', ['student', 'teacher'], '종류');
    try {
        $pid = dm_create_in_cm($u, $kind, (int)($b['dmId'] ?? 0));
    } catch (SyncError $e) {
        json_fail('sync_rejected', $e->getMessage(), 422);
    }
    audit('교적에 만들기', $pid, ['kind' => $kind]);
    json_out(['personId' => $pid]);
});
