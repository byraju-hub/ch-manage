<?php
declare(strict_types=1);

// 부서 관리(dep-manage) 연동 2차: 사진 맞추기, 부서·학년(승급) 맞추기.
//
// 사진: 두 프로그램의 파일 저장 위치(data 폴더)가 달라서, 같은 호스팅 계정 안에서 부서 관리의 data 폴더를 찾아 읽고 쓴다.
//   한쪽에만 사진이 있으면 다른 쪽으로 복사한다. 양쪽에 있으면 건드리지 않는다(어느 쪽이 맞는지 알 수 없으므로).
// 부서·학년: 부서 관리가 주인이다. 학생이 승급해서 부서가 바뀌면 교적의 교육부서(와 교인구분)를 부서 관리에 맞춘다.

/** 부서 관리의 data 폴더. 설정해 둔 경로가 있으면 그것, 없으면 이 사이트의 옆 폴더 중 접두어와 DB 가 같은 부서 관리를 찾는다. */
function dm_data_dir(): ?string
{
    static $d = false;
    if ($d !== false) return $d;
    $set = meta_get('dm_data_dir');
    if ($set !== null && $set !== '' && is_dir($set)) return $d = rtrim(str_replace('\\', '/', $set), '/');
    $cfg = cm_config();
    foreach (glob(dirname(CM_ROOT) . '/*/data/config.php') ?: [] as $f) {
        if (realpath(dirname($f, 2)) === realpath(CM_ROOT)) continue;
        $c = @include $f;
        if (is_array($c) && ($c['prefix'] ?? '') === dm_prefix() && ($c['db']['name'] ?? '') === ($cfg['db']['name'] ?? '?') && ($c['db']['host'] ?? '') === ($cfg['db']['host'] ?? '?')) {
            return $d = str_replace('\\', '/', dirname($f));
        }
    }
    return $d = null;
}

function dm_photo_file(string $token, bool $thumb = false): string
{
    return dm_data_dir() . '/uploads/photos/' . preg_replace('/[^a-f0-9]/', '', $token) . ($thumb ? '_t' : '') . '.jpg';
}

/** 부서 관리 표에 photo_path 열이 있는가 (옛 버전에는 없을 수 있다) */
function dm_has_photo_col(string $table): bool
{
    static $c = [];
    return $c[$table] ??= (bool)val('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [dm_prefix() . $table, 'photo_path']);
}

/** @return array<int, array<string, mixed>> 연결된 교인 중 사진을 맞출 수 있는 항목 */
function dm_photo_items(): array
{
    $dir = dm_data_dir();
    $out = [];
    foreach (['student' => 'students', 'teacher' => 'teachers'] as $kind => $tbl) {
        if (!dm_has_photo_col($tbl)) continue;
        $sql = 'SELECT l.id AS link_id, l.person_id, l.dm_id, p.name, p.photo AS cm_photo, d.photo_path AS dm_photo FROM ' . t('links') . ' l JOIN ' . t('people') . ' p ON p.id = l.person_id JOIN ' . dm_t($tbl) . ' d ON d.id = l.dm_id WHERE l.dm_kind = ?';
        foreach (rows($sql, [$kind]) as $r) {
            $cmFile = $r['cm_photo'] && is_file(data_upload_dir() . '/' . $r['cm_photo']);
            $dmFile = $dir && $r['dm_photo'] && is_file(dm_photo_file((string)$r['dm_photo']));
            if (!$cmFile && !$dmFile) continue;
            $state = $cmFile && $dmFile ? 'both' : ($cmFile ? 'to_dm' : 'to_cm');
            $out[] = ['linkId' => (int)$r['link_id'], 'personId' => $r['person_id'], 'name' => $r['name'], 'kind' => $kind, 'dmId' => (int)$r['dm_id'], 'state' => $state];
        }
    }
    usort($out, fn($a, $b) => strcmp($a['state'] . $a['name'], $b['state'] . $b['name']));
    return $out;
}

function dm_photo_fit(GdImage $src, int $max): GdImage
{
    $w = imagesx($src); $h = imagesy($src);
    $s = min(1.0, $max / max($w, $h));
    $out = imagecreatetruecolor(max(1, (int)round($w * $s)), max(1, (int)round($h * $s)));
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $src, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    return $out;
}

/** 교적의 사진을 부서 관리 쪽에 저장한다. (부서 관리가 하는 것과 같게: 긴 변 640px 와 160px 두 장, JPG) */
function dm_photo_to_dm(array $it): void
{
    if (!extension_loaded('gd')) throw new RuntimeException('이 호스팅에는 이미지 기능(GD)이 없습니다.');
    $rel = (string)val('SELECT photo FROM ' . t('people') . ' WHERE id = ?', [$it['personId']]);
    $bin = @file_get_contents(data_upload_dir() . '/' . $rel);
    $img = $bin === false ? false : @imagecreatefromstring($bin);
    if (!$img) throw new RuntimeException('교적의 사진을 읽을 수 없습니다.');
    $dir = dm_data_dir() . '/uploads/photos';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('부서 관리의 사진 폴더를 만들 수 없습니다.');
    $token = bin2hex(random_bytes(12));
    $ok = imagejpeg(dm_photo_fit($img, 640), "$dir/$token.jpg", 85) && imagejpeg(dm_photo_fit($img, 160), "{$dir}/{$token}_t.jpg", 80);
    if (!$ok) { @unlink("$dir/$token.jpg"); @unlink("{$dir}/{$token}_t.jpg"); throw new RuntimeException('부서 관리의 사진 폴더에 저장하지 못했습니다. 폴더 권한을 확인해 주세요.'); }
    q('UPDATE ' . dm_t($it['kind'] === 'student' ? 'students' : 'teachers') . ' SET photo_path = ? WHERE id = ?', [$token, $it['dmId']]);
}

/** 부서 관리의 사진을 교적 교인의 사진으로 가져온다. */
function dm_photo_to_cm(array $it): void
{
    $tbl = $it['kind'] === 'student' ? 'students' : 'teachers';
    $token = (string)val('SELECT photo_path FROM ' . dm_t($tbl) . ' WHERE id = ?', [$it['dmId']]);
    $bin = $token !== '' && is_file(dm_photo_file($token)) ? (string)file_get_contents(dm_photo_file($token)) : '';
    if ($bin === '') throw new RuntimeException('부서 관리의 사진을 읽을 수 없습니다.');
    try {
        $rel = data_save_image('data:image/jpeg;base64,' . base64_encode($bin), 'photos/' . strtolower($it['personId']), 600_000);
    } catch (SyncError $e) {
        throw new RuntimeException($e->getMessage());
    }
    q('UPDATE ' . t('people') . ' SET photo = ? WHERE id = ?', [$rel, $it['personId']]);
}

// ---------------------------------------------------------------- 부서·학년(승급)

/** 교적에서 쓸 수 있는 교육부서 이름 (올해 연간 설정의 교육부서 기준 + 기본 부서) */
function dm_edu_depts(): array
{
    $names = array_keys(DM_DEPT_CATEGORY);
    $edu = json_decode((string)val('SELECT edu FROM ' . t('year_settings') . ' WHERE year = ?', [(int)date('Y')]), true);
    foreach (is_array($edu) ? $edu : [] as $e) if (is_array($e) && !empty($e['dept'])) $names[] = (string)$e['dept'];
    return array_values(array_unique($names));
}

/** 부서 관리의 부서·학년과 다른 교적 교인의 교육부서(와 교인구분) @return array<int, array<string, mixed>> */
function dm_dept_items(): array
{
    $known = dm_edu_depts();
    $out = [];
    $sql = 'SELECT l.id AS link_id, p.id AS person_id, p.name, p.edu_dept, p.category_id, d.name AS dm_dept, gl.label AS dm_grade'
        . ' FROM ' . t('links') . ' l JOIN ' . t('people') . ' p ON p.id = l.person_id JOIN ' . dm_t('students') . ' s ON s.id = l.dm_id'
        . ' JOIN ' . dm_t('departments') . ' d ON d.id = s.department_id LEFT JOIN ' . dm_t('grade_levels') . " gl ON gl.id = s.grade_level_id WHERE l.dm_kind = 'student' AND s.status <> 'inactive'";
    foreach (rows($sql) as $r) {
        if ((string)$r['edu_dept'] === (string)$r['dm_dept']) continue;
        $item = ['linkId' => (int)$r['link_id'], 'personId' => $r['person_id'], 'name' => $r['name'], 'dmDept' => $r['dm_dept'], 'dmGrade' => $r['dm_grade'], 'cmDept' => $r['edu_dept'], 'category' => null, 'ok' => in_array($r['dm_dept'], $known, true)];
        if ($item['ok'] && isset(DM_DEPT_CATEGORY[$r['dm_dept']]) && preg_match('/^m\.([a-z])\.([a-z])$/', (string)$r['category_id'], $m) && $m[1] !== DM_DEPT_CATEGORY[$r['dm_dept']]) {
            $new = 'm.' . DM_DEPT_CATEGORY[$r['dm_dept']] . '.' . $m[2];
            if (val('SELECT 1 FROM ' . t('categories') . ' WHERE id = ?', [$new])) $item['category'] = ['from' => $r['category_id'], 'to' => $new];
        }
        if (!$item['ok']) $item['note'] = "교적의 교육부서 목록에 '{$r['dm_dept']}'가 없어 맞추지 않습니다. (연간 설정에서 교육부서를 확인해 주세요)";
        $out[] = $item;
    }
    return $out;
}

/** 선택한 연결의 교육부서를 부서 관리에 맞춘다. 화면에서 고칠 때와 같은 저장 경로를 거쳐 수정 이력이 남는다. @return int 바꾼 교인 수 */
function dm_dept_apply(array $user, ?array $linkIds): int
{
    $ops = [];
    foreach (dm_dept_items() as $it) {
        if (!$it['ok'] || ($linkIds !== null && !in_array($it['linkId'], $linkIds, true))) continue;
        $set = ['edu_dept' => $it['dmDept']];
        if ($it['category']) $set['category_id'] = $it['category']['to'];
        $ops[] = ['t' => 'people', 'id' => $it['personId'], 'set' => $set];
        $ops[] = ['t' => 'changeLog', 'id' => new_uuid(), 'set' => ['person_id' => $it['personId'], 'at' => date('Y-m-d H:i'), 'field' => '교육부서', 'before' => $it['cmDept'], 'after' => $it['dmDept'] . '(부서 관리에 맞춤)']];
    }
    if (!$ops) return 0;
    $count = intdiv(count($ops), 2);
    foreach (array_chunk($ops, 2 * 500) as $chunk) {
        try { data_apply($user, $chunk); } catch (SyncError $e) { json_fail('sync_rejected', $e->getMessage(), 422); }
    }
    return $count;
}
