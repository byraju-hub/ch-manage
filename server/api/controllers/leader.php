<?php
declare(strict_types=1);

// 소그룹 인도자(D 등급) 전용: 내가 인도하는 소그룹의 주일 모임 출석 체크.
// 교적 전체를 받는 주소는 쓰지 못하고(router.php guard_leader), 여기서도 내 소그룹 소속 교인만 읽고 쓴다.
// 출석은 group_attendance, 특이사항(교인별)은 그 표의 reason, 기타 의견(소그룹 전체)은 group_notes 에 저장한다.

const LEADER_WEEKS = 12;   // 고칠 수 있는 지난 주일 수
const OFFICE_ORDER = ['목사', '전도사', '사모', '장로', '시무집사', '권사', '서리집사'];

function require_leader(): array
{
    $u = require_user();
    if (!is_leader_user($u)) json_fail('forbidden', '소그룹 인도자만 쓸 수 있는 화면입니다.', 403);
    return $u;
}

/** 내가 인도하는 소그룹들. 교인과 연결되지 않았으면 빈 목록. */
function leader_groups(array $u): array
{
    if (empty($u['person_id'])) return [];
    return rows('SELECT g.id, g.name, g.place, c.name AS community FROM ' . t('small_groups') . ' g JOIN ' . t('communities') . ' c ON c.id = g.community_id WHERE g.leader_id = ? ORDER BY g.sort_order, g.name', [$u['person_id']]);
}

function leader_group(array $u, string $id): array
{
    foreach (leader_groups($u) as $g) if ($g['id'] === $id) return $g;
    json_fail('forbidden', '내가 인도하는 소그룹이 아닙니다.', 403);
}

/** 오늘 이전의 가장 가까운 주일 (오늘이 주일이면 오늘) */
function last_sunday(): string
{
    return date('Y-m-d', strtotime('today -' . (int)date('w') . ' days'));
}

function leader_sundays(): array
{
    $last = strtotime(last_sunday());
    $out = [];
    for ($i = 0; $i < LEADER_WEEKS; $i++) $out[] = date('Y-m-d', strtotime("-{$i} weeks", $last));
    return $out;
}

function leader_check_sunday(string $s): void
{
    if (!in_array($s, leader_sundays(), true)) json_fail('invalid', '최근 ' . LEADER_WEEKS . '주 안의 주일만 고를 수 있습니다.', 422);
}

/** 소그룹 구성원: 재적 중(등록·전입·복적·보류)인 교인. 직분과 올해 출석률을 함께 준다. */
function leader_members(array $g, string $leaderPersonId): array
{
    $people = rows('SELECT p.id, p.name, p.name_note FROM ' . t('person_groups') . ' pg JOIN ' . t('people') . ' p ON p.id = pg.person_id WHERE pg.small_group_id = ?', [$g['id']]);
    if (!$people) return [];
    $ids = array_column($people, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));

    // 재적 중인지: 날짜가 가장 늦은 재적 이력(날짜를 모르면 가장 오래된 것, 같으면 입력 순서)
    $statusOn = ['등록' => true, '전입' => true, '복적' => true, '보류' => true];
    $last = [];
    $registered = [];
    foreach (rows('SELECT person_id, kind, event_date FROM ' . t('status_events') . " WHERE person_id IN ($in) ORDER BY COALESCE(event_date, '0000-00-00'), seq", $ids) as $e) {
        $last[$e['person_id']] = $e['kind'];
        if (($e['kind'] === '등록' || $e['kind'] === '전입') && !isset($registered[$e['person_id']])) $registered[$e['person_id']] = $e['event_date'];
    }
    // 직분: 끝나지 않았고 사임이 아닌 것 중 가장 높은 것
    $office = [];
    foreach (rows('SELECT person_id, office, rank_status FROM ' . t('office_terms') . " WHERE person_id IN ($in) AND end_date IS NULL AND rank_status <> '사임'", $ids) as $o) {
        $rank = array_search($o['office'], OFFICE_ORDER, true);
        $rank = $rank === false ? 99 : $rank;
        if (!isset($office[$o['person_id']]) || $rank < $office[$o['person_id']][0]) $office[$o['person_id']] = [$rank, $o];
    }
    // 올해 출석률: 올해 1월 1일부터 지난 주일까지, 올해 등록한 사람은 등록일 이후만
    $year = date('Y');
    $sundays = [];
    for ($d = strtotime("$year-01-01"); $d <= strtotime(last_sunday()); $d += 86400) {
        if (date('w', $d) === '0') $sundays[] = date('Y-m-d', $d);
    }
    $present = [];
    foreach (rows('SELECT person_id, sunday FROM ' . t('group_attendance') . " WHERE present = 1 AND sunday >= ? AND person_id IN ($in)", ["$year-01-01", ...$ids]) as $a) {
        $present[$a['person_id']][$a['sunday']] = true;
    }

    $out = [];
    foreach ($people as $p) {
        if (!($statusOn[$last[$p['id']] ?? ''] ?? false)) continue;
        $reg = $registered[$p['id']] ?? null;
        $weeks = array_filter($sundays, fn($s) => !$reg || $s >= $reg);
        $n = count($weeks);
        $att = count(array_filter($weeks, fn($s) => isset($present[$p['id']][$s])));
        $title = '';
        if (isset($office[$p['id']])) {
            $o = $office[$p['id']][1];
            if ($o['office'] === '서리집사') {
                $title = $o['rank_status'] === '명예' ? '명예집사' : '집사';
            } else {
                $base = ($o['office'] === '시무집사' && $o['rank_status'] !== '시무') ? '집사' : $o['office'];
                $title = $o['rank_status'] === '시무' ? $base : $o['rank_status'] . $base;
            }
        }
        $out[] = ['id' => $p['id'], 'name' => $p['name'] . ($p['name_note'] ?? ''), 'office' => $title, 'isLeader' => $p['id'] === $leaderPersonId, 'rate' => $n ? (int)round($att * 100 / $n) : 0];
    }
    return $out;
}

// 내 정보와 내가 인도하는 소그룹 목록
route('GET', '/leader/groups', function () {
    $u = require_leader();
    $s = row("SELECT JSON_VALUE(church_info, '$.unit2') AS unit2 FROM " . t('site_settings') . ' WHERE id = 1');
    json_out([
        'name' => $u['name'],
        'linked' => !empty($u['person_id']),
        'unit' => ($s['unit2'] ?? '') ?: '소그룹',
        'groups' => leader_groups($u),
        'sundays' => leader_sundays(),
    ]);
});

// 한 소그룹의 명단과 그 주일의 출석·특이사항·기타 의견
route('GET', '/leader/roster', function () {
    $u = require_leader();
    $g = leader_group($u, (string)($_GET['group'] ?? ''));
    $sunday = (string)($_GET['sunday'] ?? last_sunday());
    leader_check_sunday($sunday);
    $members = leader_members($g, (string)$u['person_id']);
    $att = [];
    if ($members) {
        $ids = array_column($members, 'id');
        foreach (rows('SELECT person_id, present, reason FROM ' . t('group_attendance') . ' WHERE sunday = ? AND person_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [$sunday, ...$ids]) as $a) {
            $att[$a['person_id']] = $a;
        }
    }
    foreach ($members as &$m) {
        $a = $att[$m['id']] ?? null;
        $m['present'] = $a ? (int)$a['present'] === 1 : false;
        $m['reason'] = $a['reason'] ?? '';
        $m['recorded'] = $a !== null;
    }
    unset($m);
    $note = val('SELECT body FROM ' . t('group_notes') . ' WHERE person_id IS NULL AND small_group_id = ? AND `date` = ? ORDER BY seq DESC LIMIT 1', [$g['id'], $sunday]);
    json_out(['group' => $g, 'sunday' => $sunday, 'members' => $members, 'note' => (string)($note ?? '')]);
});

// 저장: 이 주일의 출석(체크 안 한 사람은 결석), 교인별 특이사항, 소그룹 기타 의견
route('POST', '/leader/save', function () {
    $u = require_leader();
    $b = body();
    $g = leader_group($u, (string)($b['group'] ?? ''));
    $sunday = (string)($b['sunday'] ?? '');
    leader_check_sunday($sunday);
    $items = $b['items'] ?? null;
    if (!is_array($items) || count($items) > 300) json_fail('invalid', '저장할 내용이 올바르지 않습니다.', 422);
    $note = trim((string)($b['note'] ?? ''));
    if (mb_strlen($note) > 2000) json_fail('invalid', '기타 의견은 2,000자 이하로 적어 주세요.', 422);

    $ids = array_column(rows('SELECT person_id FROM ' . t('person_groups') . ' WHERE small_group_id = ?', [$g['id']]), 'person_id');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $n = 0;
        foreach ($items as $it) {
            $pid = strtolower((string)($it['id'] ?? ''));
            if (!in_array($pid, $ids, true)) json_fail('forbidden', '내 소그룹 구성원이 아닌 교인이 들어 있습니다.', 403);
            $reason = trim((string)($it['reason'] ?? ''));
            if (mb_strlen($reason) > 255) json_fail('invalid', '특이사항은 255자 이하로 적어 주세요.', 422);
            q('INSERT INTO ' . t('group_attendance') . ' (sunday, person_id, present, reason) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE present = VALUES(present), reason = VALUES(reason)',
                [$sunday, $pid, !empty($it['present']) ? 1 : 0, $reason === '' ? null : $reason]);
            $n++;
        }
        $old = row('SELECT id FROM ' . t('group_notes') . ' WHERE person_id IS NULL AND small_group_id = ? AND `date` = ? ORDER BY seq DESC LIMIT 1', [$g['id'], $sunday]);
        if ($note === '') {
            if ($old) q('DELETE FROM ' . t('group_notes') . ' WHERE person_id IS NULL AND small_group_id = ? AND `date` = ?', [$g['id'], $sunday]);
        } elseif ($old) {
            q('UPDATE ' . t('group_notes') . ' SET body = ?, author = ? WHERE id = ?', [$note, $u['name'], $old['id']]);
        } else {
            q('INSERT INTO ' . t('group_notes') . ' (id, person_id, small_group_id, `date`, body, author) VALUES (?, NULL, ?, ?, ?, ?)', [new_uuid(), $g['id'], $sunday, $note, $u['name']]);
        }
        q('UPDATE ' . t('meta') . " SET v = v + 1 WHERE k = 'data_version'"); // 교적 화면이 새 출석을 받아 가도록
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit('소그룹 출석 저장', $g['name'] . ' ' . $sunday, ['count' => $n]);
    json_out(['saved' => $n]);
});
