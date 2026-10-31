<?php
declare(strict_types=1);

// 부서 관리(dep-manage)와의 연동. 두 프로그램이 같은 DB 에 설치되어 있을 때만 동작한다.
//
// 항목별로 주인이 다르다.
//   교적(ch-manage)이 주인: 이름·성별·생년월일·연락처·주소·세례 → 연결된 학생·교사에게 맞춰 준다(교적 → 부서).
//   부서 관리가 주인: 부서·학년·반·출석·부서 심방 → 교적은 바꾸지 않고, 출석은 읽어서 보여 준다(부서 → 교적).
// 연결 표(links)는 교적 쪽에만 있다. 부서 관리의 표는 읽기와 (교적이 주인인 칸의) 갱신만 한다.
// 부서 관리의 DB 구조가 달라서 필요한 열이 없으면 오류 대신 '연동 안 됨'으로 물러난다.

/** 연동에 꼭 필요한 부서 관리의 표와 열 */
const DM_REQUIRED = [
    'departments' => ['id', 'name', 'is_active'],
    'grade_levels' => ['id', 'department_id', 'label', 'sort'],
    'students' => ['id', 'department_id', 'name', 'gender', 'birth_date', 'phone', 'address', 'baptism', 'grade_level_id', 'status'],
    'teachers' => ['id', 'department_id', 'name', 'birth_date', 'phone', 'status'],
    'attendance' => ['student_id', 'attend_date', 'status'],
];
const DM_STUDENT_FIELDS = ['name' => '이름', 'gender' => '성별', 'birth_date' => '생년월일', 'phone' => '연락처', 'address' => '주소', 'baptism' => '세례'];
const DM_TEACHER_FIELDS = ['name' => '이름', 'birth_date' => '생년월일', 'phone' => '연락처'];
/** 부서 이름 → 교적의 교인구분 2단계 코드 (이름이 같을 때만 자동으로 맞춘다) */
const DM_DEPT_CATEGORY = ['영아부' => 'b', '유치부' => 'k', '유초등부' => 'e', '중고등부' => 'h', '청년부' => 'y'];

function dm_prefix(): string
{
    static $p = null;
    if ($p !== null) return $p;
    $v = val('SELECT v FROM ' . t('meta') . " WHERE k = 'dm_prefix'");
    return $p = (is_string($v) && valid_prefix($v)) ? $v : 'dm_';
}

function dm_t(string $name): string
{
    return '`' . dm_prefix() . $name . '`';
}

/** @return array{available: bool, prefix: string, reason?: string, missing?: string[]} */
function dm_status(): array
{
    static $s = null;
    if ($s !== null) return $s;
    $prefix = dm_prefix();
    if ($prefix === (cm_config()['prefix'] ?? 'cm_')) return $s = ['available' => false, 'prefix' => $prefix, 'reason' => 'same_prefix'];
    $names = array_map(fn($n) => $prefix . $n, array_keys(DM_REQUIRED));
    $have = [];
    foreach (rows('SELECT TABLE_NAME AS tn, COLUMN_NAME AS cn FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . marks($names) . ')', $names) as $r) {
        $have[$r['tn']][$r['cn']] = true;
    }
    $any = false;
    $missing = [];
    foreach (DM_REQUIRED as $tbl => $cols) {
        if (!isset($have[$prefix . $tbl])) { $missing[] = $tbl; continue; }
        $any = true;
        foreach ($cols as $c) if (!isset($have[$prefix . $tbl][$c])) $missing[] = "{$tbl}.{$c}";
    }
    if (!$any) return $s = ['available' => false, 'prefix' => $prefix, 'reason' => 'not_installed'];
    if ($missing) return $s = ['available' => false, 'prefix' => $prefix, 'reason' => 'incompatible', 'missing' => $missing];
    return $s = ['available' => true, 'prefix' => $prefix];
}

function dm_require(): void
{
    if (!dm_status()['available']) json_fail('dm_unavailable', '부서 관리(dep-manage)와 연결할 수 없습니다. 같은 데이터베이스에 설치되어 있는지 확인해 주세요.', 409);
}

// ---------------------------------------------------------------- 값 다루기

function dm_digits(?string $s): string
{
    return preg_replace('/\D/', '', (string)$s) ?? '';
}

/** 01012345678 → 010-1234-5678 (부서 관리가 저장하는 모양과 같게) */
function dm_norm_phone(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '' || preg_match('/[^0-9\-\s.]/u', $raw)) return $raw;
    $d = dm_digits($raw);
    $len = strlen($d);
    if ($len === 11) return substr($d, 0, 3) . '-' . substr($d, 3, 4) . '-' . substr($d, 7);
    if ($len === 10) return str_starts_with($d, '02') ? substr($d, 0, 2) . '-' . substr($d, 2, 4) . '-' . substr($d, 6) : substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6);
    return $raw;
}

function dm_sunday_of(string $iso): string
{
    $ts = strtotime($iso . ' 12:00:00');
    return date('Y-m-d', $ts - (int)date('w', $ts) * 86400);
}

/** 교적의 신급 이력에서 지금 값을 부서 관리의 세례 값으로. 이력이 없으면 null (부서 관리의 값을 그대로 둔다) */
function dm_baptism_of(string $personId): ?string
{
    $rank = ['유아세례' => 1, '학습' => 2, '세례' => 3, '입교' => 4];
    $best = null;
    foreach (rows('SELECT kind, event_date FROM ' . t('faith_events') . ' WHERE person_id = ?', [$personId]) as $e) {
        $key = [(string)($e['event_date'] ?? ''), $rank[$e['kind']] ?? 0];
        if ($best === null || $key > $best[0]) $best = [$key, $e['kind']];
    }
    return $best === null ? null : ($best[1] === '유아세례' ? '유아' : $best[1]);
}

/** 교적 교인 한 명에서 부서 관리로 맞춰 줄 값들. (비어 있는 연락처·주소는 부서 관리의 값을 지우지 않는다) */
function dm_desired(string $kind, array $p): array
{
    $d = ['name' => (string)$p['name']];
    if ($kind === 'student' && in_array($p['gender'], ['M', 'F'], true)) $d['gender'] = $p['gender'] === 'M' ? '남' : '여';
    if ($p['birth_date'] !== null) $d['birth_date'] = (string)$p['birth_date'];
    $phone = dm_norm_phone($p['phone']);
    if ($phone !== '') $d['phone'] = $phone;
    if ($kind === 'student') {
        $addr = trim(trim((string)$p['address1']) . ' ' . trim((string)$p['address2']));
        if ($addr !== '') $d['address'] = $addr;
        $b = dm_baptism_of($p['id']);
        if ($b !== null) $d['baptism'] = $b;
    }
    return $d;
}

/** 지금 부서 관리의 값과 다른 칸들 @return array<int, array{field: string, label: string, from: ?string, to: string}> */
function dm_changes(string $kind, array $person, array $dmRow): array
{
    $labels = $kind === 'student' ? DM_STUDENT_FIELDS : DM_TEACHER_FIELDS;
    $out = [];
    foreach (dm_desired($kind, $person) as $f => $to) {
        if (!isset($labels[$f])) continue;
        $from = $dmRow[$f] === null ? null : (string)$dmRow[$f];
        if ((string)$from !== (string)$to) $out[] = ['field' => $f, 'label' => $labels[$f], 'from' => $from, 'to' => (string)$to];
    }
    return $out;
}

function dm_apply_changes(string $kind, int $dmId, array $changes): void
{
    if (!$changes) return;
    $labels = $kind === 'student' ? DM_STUDENT_FIELDS : DM_TEACHER_FIELDS;
    $sets = [];
    $vals = [];
    foreach ($changes as $c) {
        if (!isset($labels[$c['field']])) continue; // 정해 둔 칸만
        $sets[] = '`' . $c['field'] . '` = ?';
        $vals[] = $c['to'];
    }
    if (!$sets) return;
    $vals[] = $dmId;
    q('UPDATE ' . dm_t($kind === 'student' ? 'students' : 'teachers') . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
}

// ---------------------------------------------------------------- 읽기

function dm_student_rows(?array $ids = null): array
{
    $sql = 'SELECT s.id, s.department_id, s.name, s.gender, s.birth_date, s.phone, s.address, s.baptism, s.status, s.grade_level_id, d.name AS dept, gl.label AS grade'
        . ' FROM ' . dm_t('students') . ' s JOIN ' . dm_t('departments') . ' d ON d.id = s.department_id LEFT JOIN ' . dm_t('grade_levels') . ' gl ON gl.id = s.grade_level_id';
    if ($ids !== null) { $ids = array_values($ids); if (!$ids) return []; return rows($sql . ' WHERE s.id IN (' . marks($ids) . ')', $ids); }
    return rows($sql . ' ORDER BY d.sort, gl.sort, s.name');
}

function dm_teacher_rows(?array $ids = null): array
{
    $sql = 'SELECT t.id, t.department_id, t.name, t.birth_date, t.phone, t.status, d.name AS dept FROM ' . dm_t('teachers') . ' t JOIN ' . dm_t('departments') . ' d ON d.id = t.department_id';
    if ($ids !== null) { $ids = array_values($ids); if (!$ids) return []; return rows($sql . ' WHERE t.id IN (' . marks($ids) . ')', $ids); }
    return rows($sql . ' ORDER BY d.sort, t.name');
}

/** @return array<int, array<string, mixed>> */
function dm_link_rows(): array
{
    return rows('SELECT l.id, l.person_id, l.dm_kind, l.dm_id FROM ' . t('links') . ' l ORDER BY l.id');
}

function dm_person(string $id): ?array
{
    return row('SELECT id, name, gender, birth_date, phone, address1, address2, edu_dept FROM ' . t('people') . ' WHERE id = ?', [$id]);
}

// ---------------------------------------------------------------- 처음 연결할 때의 대응 후보

/**
 * 같은 사람으로 보이는 정도를 판단한다. 확실(sure): 이름과 생년월일이 같거나, 이름과 연락처가 같고 후보가 한 명뿐.
 * 애매(maybe): 이름은 같지만 확실하지 않음(동명이인 등). 없음(none): 같은 이름이 교적에 없음.
 * @param array<int, array<string, mixed>> $people 이미 연결된 교인을 뺀 교적 교인들
 */
function dm_match(array $dm, array $byName): array
{
    $cands = $byName[$dm['name']] ?? [];
    if (!$cands) return ['none', []];
    $birth = $dm['birth_date'] ?? null;
    $phone = dm_digits($dm['phone'] ?? null);
    $exactBirth = array_values(array_filter($cands, fn($p) => $birth && $p['birth_date'] === $birth));
    if (count($exactBirth) === 1) return ['sure', $exactBirth];
    if (count($cands) === 1 && $phone !== '' && dm_digits($cands[0]['phone']) === $phone) return ['sure', $cands];
    return ['maybe', $cands];
}

function dm_candidates(): array
{
    $linked = ['student' => [], 'teacher' => []];
    $linkedPeople = ['student' => [], 'teacher' => []];
    foreach (dm_link_rows() as $l) { $linked[$l['dm_kind']][(int)$l['dm_id']] = true; $linkedPeople[$l['dm_kind']][$l['person_id']] = true; }

    $people = rows('SELECT id, name, birth_date, phone, edu_dept FROM ' . t('people') . " WHERE category_id IS NULL OR category_id NOT IN ('dead', 'move', 'keep') ORDER BY seq");
    $out = ['students' => [], 'teachers' => [], 'cmOnly' => []];
    $matchedPeople = [];
    foreach (['student' => 'students', 'teacher' => 'teachers'] as $kind => $key) {
        $byName = [];
        foreach ($people as $p) if (!isset($linkedPeople[$kind][$p['id']])) $byName[$p['name']][] = $p;
        $dmRows = $kind === 'student' ? dm_student_rows() : dm_teacher_rows();
        foreach ($dmRows as $d) {
            if (isset($linked[$kind][(int)$d['id']]) || $d['status'] === 'inactive') continue;
            [$level, $cands] = dm_match($d, $byName);
            foreach ($cands as $c) $matchedPeople[$kind][$c['id']] = true;
            $out[$key][] = [
                'dmId' => (int)$d['id'], 'name' => $d['name'], 'birthDate' => $d['birth_date'], 'phone' => $d['phone'],
                'dept' => $d['dept'], 'grade' => $d['grade'] ?? null, 'match' => $level,
                'candidates' => array_map(fn($c) => ['personId' => $c['id'], 'name' => $c['name'], 'birthDate' => $c['birth_date'], 'phone' => $c['phone'], 'eduDept' => $c['edu_dept']], $cands),
            ];
        }
    }
    // 교육부서 교인인데 부서 관리에서 짝을 찾지 못한 사람 (부서 관리에 추가할 후보)
    foreach ($people as $p) {
        if ($p['edu_dept'] === null || isset($linkedPeople['student'][$p['id']]) || isset($matchedPeople['student'][$p['id']])) continue;
        $out['cmOnly'][] = ['personId' => $p['id'], 'name' => $p['name'], 'birthDate' => $p['birth_date'], 'eduDept' => $p['edu_dept']];
    }
    return $out;
}

// ---------------------------------------------------------------- 연결 관리

/** @param array<int, array{personId: string, kind: string, dmId: int}> $pairs */
function dm_create_links(array $pairs, int $userId): int
{
    $n = 0;
    foreach ($pairs as $p) {
        $kind = $p['kind'] ?? '';
        $dmId = (int)($p['dmId'] ?? 0);
        $pid = (string)($p['personId'] ?? '');
        if (!in_array($kind, ['student', 'teacher'], true) || $dmId < 1 || !preg_match(DATA_UUID_RX, $pid)) json_fail('invalid', '연결 정보가 올바르지 않습니다.', 422);
        $pid = strtolower($pid);
        if (!dm_person($pid)) json_fail('not_found', '교인을 찾을 수 없습니다.', 404);
        $exists = $kind === 'student' ? dm_student_rows([$dmId]) : dm_teacher_rows([$dmId]);
        if (!$exists) json_fail('not_found', '부서 관리에서 해당 학생·교사를 찾을 수 없습니다.', 404);
        if (val('SELECT 1 FROM ' . t('links') . ' WHERE (dm_kind = ? AND dm_id = ?) OR (dm_kind = ? AND person_id = ?)', [$kind, $dmId, $kind, $pid])) {
            json_fail('duplicate', '이미 연결된 학생·교사이거나 이미 연결된 교인입니다.', 409);
        }
        q('INSERT INTO ' . t('links') . ' (person_id, dm_kind, dm_id, linked_by) VALUES (?,?,?,?)', [$pid, $kind, $dmId, $userId]);
        $n++;
    }
    if ($n) q('UPDATE ' . t('meta') . " SET v = v + 1 WHERE k = 'data_version'"); // 화면이 연결 정보를 다시 받게
    return $n;
}

/** 연결 목록(화면용). 부서 관리 쪽이 지워졌으면 dm 이 null (끊어진 연결). */
function dm_links_view(): array
{
    $links = dm_link_rows();
    $sIds = array_map(fn($l) => (int)$l['dm_id'], array_filter($links, fn($l) => $l['dm_kind'] === 'student'));
    $tIds = array_map(fn($l) => (int)$l['dm_id'], array_filter($links, fn($l) => $l['dm_kind'] === 'teacher'));
    $sMap = array_column(dm_student_rows(array_values($sIds)), null, 'id');
    $tMap = array_column(dm_teacher_rows(array_values($tIds)), null, 'id');
    $pMap = [];
    foreach (rows('SELECT id, name, gender, birth_date, phone, address1, address2, edu_dept FROM ' . t('people') . ' WHERE id IN (SELECT person_id FROM ' . t('links') . ')') as $p) $pMap[$p['id']] = $p;
    $out = [];
    foreach ($links as $l) {
        $dm = ($l['dm_kind'] === 'student' ? $sMap : $tMap)[(int)$l['dm_id']] ?? null;
        $p = $pMap[$l['person_id']] ?? null;
        $out[] = [
            'id' => (int)$l['id'], 'personId' => $l['person_id'], 'personName' => $p['name'] ?? '', 'kind' => $l['dm_kind'], 'dmId' => (int)$l['dm_id'],
            'eduDept' => $p['edu_dept'] ?? null,
            'dm' => $dm ? ['name' => $dm['name'], 'dept' => $dm['dept'], 'grade' => $dm['grade'] ?? null, 'status' => $dm['status'], 'birthDate' => $dm['birth_date']] : null,
            // 부서 이름이 서로 다르면 알려 준다 (정보용. 부서 관리의 부서·학년은 바꾸지 않는다)
            'deptMismatch' => $dm && $l['dm_kind'] === 'student' && $p && $p['edu_dept'] !== null && $p['edu_dept'] !== $dm['dept'],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------- 맞춰 주기 (교적 → 부서 관리)

/** 바뀔 내용 미리보기 @return array<int, array{linkId: int, kind: string, name: string, dept: string, changes: array}> */
function dm_sync_preview(?array $linkIds = null): array
{
    $out = [];
    $views = dm_links_view();
    foreach ($views as $v) {
        if ($v['dm'] === null || ($linkIds !== null && !in_array($v['id'], $linkIds, true))) continue;
        $person = dm_person($v['personId']);
        $dmRow = ($v['kind'] === 'student' ? dm_student_rows([$v['dmId']]) : dm_teacher_rows([$v['dmId']]))[0] ?? null;
        if (!$person || !$dmRow) continue;
        $changes = dm_changes($v['kind'], $person, $dmRow);
        if ($changes) $out[] = ['linkId' => $v['id'], 'kind' => $v['kind'], 'name' => $person['name'], 'dept' => $v['dm']['dept'], 'changes' => $changes];
    }
    return $out;
}

/** 미리보기와 같은 내용을 실제로 적용한다. @return int 바꾼 사람 수 */
function dm_sync_apply(?array $linkIds = null): int
{
    $n = 0;
    $link = fn(int $id) => row('SELECT * FROM ' . t('links') . ' WHERE id = ?', [$id]);
    foreach (dm_sync_preview($linkIds) as $item) {
        $l = $link($item['linkId']);
        if (!$l) continue;
        dm_apply_changes($l['dm_kind'], (int)$l['dm_id'], $item['changes']);
        $n++;
    }
    if ($n) dm_log_in_dm('sync', $n);
    return $n;
}

/** 교적에서 저장한 직후, 연결된 교인의 부서 관리 값을 바로 맞춘다. 실패해도 저장은 막지 않는다. */
function dm_after_sync(array $ops): void
{
    try {
        if (!dm_status()['available']) return;
        $watch = ['name', 'gender', 'birth_date', 'phone', 'address1', 'address2'];
        $ids = [];
        foreach ($ops as $op) {
            if (!is_array($op) || !empty($op['del']) || !isset($op['t'], $op['id'])) continue;
            if ($op['t'] === 'people' && array_intersect($watch, array_keys((array)($op['set'] ?? [])))) $ids[(string)$op['id']] = true;
            if ($op['t'] === 'faithEvents' && isset($op['set']['person_id'])) $ids[(string)$op['set']['person_id']] = true;
        }
        if (!$ids) return;
        $ids = array_keys($ids);
        $linkIds = array_map('intval', array_column(rows('SELECT id FROM ' . t('links') . ' WHERE person_id IN (' . marks($ids) . ')', $ids), 'id'));
        if ($linkIds) dm_sync_apply($linkIds);
    } catch (Throwable $e) {
        cm_log('error', 'dm sync: ' . $e->getMessage());
    }
}

/** 부서 관리의 활동 기록에 남긴다 (누가 바꿨는지는 그쪽 사용자가 아니므로 비워 둔다) */
function dm_log_in_dm(string $what, int $count): void
{
    try {
        q('INSERT INTO ' . dm_t('audit_log') . " (user_id, action, target_type, detail, ip) VALUES (NULL, 'cm.sync', 'student', ?, ?)", ["교적(ch-manage)에서 {$count}명 맞춤", client_ip()]);
    } catch (Throwable) {
    }
}

// ---------------------------------------------------------------- 부서 관리의 출석 (읽기)

/** 교적 화면이 쓸 연결 정보와 연결된 학생의 최근 출석. 출석은 모임 날짜가 속한 주일로 모은다. */
function dm_overview(int $weeks): array
{
    $weeks = max(1, min(52, $weeks));
    $links = dm_link_rows();
    $sLinks = array_filter($links, fn($l) => $l['dm_kind'] === 'student');
    $tLinks = array_filter($links, fn($l) => $l['dm_kind'] === 'teacher');
    $sMap = array_column(dm_student_rows(array_map(fn($l) => (int)$l['dm_id'], $sLinks)), null, 'id');
    $tMap = array_column(dm_teacher_rows(array_map(fn($l) => (int)$l['dm_id'], $tLinks)), null, 'id');
    $info = [];
    $byDm = [];
    foreach ($sLinks as $l) {
        $s = $sMap[(int)$l['dm_id']] ?? null;
        if (!$s) continue;
        $info[$l['person_id']] = ['kind' => 'student', 'dept' => $s['dept'], 'grade' => $s['grade'] ?? null];
        $byDm[(int)$l['dm_id']] = $l['person_id'];
    }
    foreach ($tLinks as $l) {
        $t = $tMap[(int)$l['dm_id']] ?? null;
        if ($t) $info[$l['person_id']] = ($info[$l['person_id']] ?? []) + ['teacherDept' => $t['dept']];
    }
    $since = date('Y-m-d', time() - $weeks * 7 * 86400);
    $present = [];
    if ($byDm) {
        $ids = array_keys($byDm);
        foreach (rows('SELECT student_id, attend_date, status FROM ' . dm_t('attendance') . ' WHERE attend_date >= ? AND student_id IN (' . marks($ids) . ')', [$since, ...$ids]) as $a) {
            $key = $byDm[(int)$a['student_id']] . '|' . dm_sunday_of($a['attend_date']);
            $present[$key] = ($present[$key] ?? false) || $a['status'] !== 'absent';
        }
    }
    $rows = [];
    foreach ($present as $key => $ok) { [$pid, $sun] = explode('|', $key); $rows[] = [$pid, $sun, $ok ? 1 : 0]; }
    return ['links' => (object)$info, 'attendance' => $rows];
}

// ---------------------------------------------------------------- 상대쪽에 만들기

function dm_departments(): array
{
    $out = [];
    foreach (rows('SELECT id, name FROM ' . dm_t('departments') . ' WHERE is_active = 1 ORDER BY sort, id') as $d) {
        $out[(int)$d['id']] = ['id' => (int)$d['id'], 'name' => $d['name'], 'grades' => []];
    }
    foreach (rows('SELECT id, department_id, label FROM ' . dm_t('grade_levels') . ' ORDER BY sort, id') as $g) {
        if (isset($out[(int)$g['department_id']])) $out[(int)$g['department_id']]['grades'][] = ['id' => (int)$g['id'], 'label' => $g['label']];
    }
    return array_values($out);
}

/** 교적의 교인을 부서 관리에 학생·교사로 만들고 연결한다. */
function dm_create_in_dm(array $person, string $kind, int $deptId, int $gradeId, int $userId): int
{
    if (!val('SELECT 1 FROM ' . dm_t('departments') . ' WHERE id = ? AND is_active = 1', [$deptId])) json_fail('invalid', '부서를 선택해 주세요.', 422);
    if (val('SELECT 1 FROM ' . t('links') . ' WHERE person_id = ? AND dm_kind = ?', [$person['id'], $kind])) json_fail('duplicate', '이미 연결된 교인입니다.', 409);
    $d = dm_desired($kind, $person);
    if ($kind === 'student') {
        if (!val('SELECT 1 FROM ' . dm_t('grade_levels') . ' WHERE id = ? AND department_id = ?', [$gradeId, $deptId])) json_fail('invalid', '학년을 선택해 주세요.', 422);
        $id = insert_id(
            'INSERT INTO ' . dm_t('students') . ' (department_id, name, gender, birth_date, phone, grade_level_id, status, baptism, address) VALUES (?,?,?,?,?,?,?,?,?)',
            [$deptId, $d['name'], $d['gender'] ?? null, $d['birth_date'] ?? null, $d['phone'] ?? '', $gradeId, 'active', $d['baptism'] ?? '-', $d['address'] ?? '']
        );
    } else {
        $id = insert_id(
            'INSERT INTO ' . dm_t('teachers') . ' (department_id, name, birth_date, phone, status) VALUES (?,?,?,?,?)',
            [$deptId, $d['name'], $d['birth_date'] ?? null, $d['phone'] ?? '', 'active']
        );
    }
    q('INSERT INTO ' . t('links') . ' (person_id, dm_kind, dm_id, linked_by) VALUES (?,?,?,?)', [$person['id'], $kind, $id, $userId]);
    q('UPDATE ' . t('meta') . " SET v = v + 1 WHERE k = 'data_version'");
    return $id;
}

/** 부서 관리의 학생·교사를 교적에 새 교인으로 등록하고 연결한다. 등록은 화면에서 등록할 때와 같은 검사를 거친다(data_apply). */
function dm_create_in_cm(array $user, string $kind, int $dmId): string
{
    $rows = $kind === 'student' ? dm_student_rows([$dmId]) : dm_teacher_rows([$dmId]);
    if (!$rows) json_fail('not_found', '부서 관리에서 찾을 수 없습니다.', 404);
    $d = $rows[0];
    if (val('SELECT 1 FROM ' . t('links') . ' WHERE dm_kind = ? AND dm_id = ?', [$kind, $dmId])) json_fail('duplicate', '이미 연결되어 있습니다.', 409);
    $uuid = fn() => sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
    $pid = $uuid();
    $code = DM_DEPT_CATEGORY[$d['dept']] ?? null;
    $set = [
        'name' => $d['name'], 'gender' => $kind === 'student' ? (['남' => 'M', '여' => 'F'][$d['gender'] ?? ''] ?? null) : null,
        'birth_date' => $d['birth_date'], 'phone' => $d['phone'] !== '' ? $d['phone'] : null,
        'address1' => $kind === 'student' && ($d['address'] ?? '') !== '' ? $d['address'] : null,
        'category_id' => $kind === 'student' ? ($code ? "m.{$code}.a" : 'm.n.a') : 'm.a.a',
        'edu_dept' => $kind === 'student' ? (isset(DM_DEPT_CATEGORY[$d['dept']]) ? $d['dept'] : null) : null,
    ];
    $ops = [
        ['t' => 'people', 'id' => $pid, 'set' => $set],
        ['t' => 'statusEvents', 'id' => $uuid(), 'set' => ['person_id' => $pid, 'kind' => '등록', 'event_date' => date('Y-m-d'), 'reason' => '부서 관리에서 가져옴']],
        ['t' => 'changeLog', 'id' => $uuid(), 'set' => ['person_id' => $pid, 'at' => date('Y-m-d H:i'), 'field' => '교적 카드', 'after' => '부서 관리(' . $d['dept'] . ')에서 가져와 등록', 'before' => null]],
    ];
    if ($kind === 'student' && isset($d['baptism']) && $d['baptism'] !== '-' && $d['baptism'] !== '') {
        $ops[] = ['t' => 'faithEvents', 'id' => $uuid(), 'set' => ['person_id' => $pid, 'kind' => $d['baptism'] === '유아' ? '유아세례' : $d['baptism'], 'event_date' => null, 'note' => '부서 관리에서 가져옴']];
    }
    data_apply($user, $ops);
    q('INSERT INTO ' . t('links') . ' (person_id, dm_kind, dm_id, linked_by) VALUES (?,?,?,?)', [$pid, $kind, $dmId, $user['id']]);
    return $pid;
}
