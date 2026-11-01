<?php
declare(strict_types=1);

// 교적 데이터 읽기(bootstrap)와 저장(sync).
//
// 화면(src/lib/registry)은 로그인하면 내 등급으로 볼 수 있는 데이터를 한 번에 받고(bootstrap),
// 바뀐 칸만 모아서 보낸다(sync). 서버는 표마다 아래 정의(data_specs)에 따라 권한·값·연결(외래키)을 확인하고 저장한다.
// 화면이 보내는 값은 믿지 않는다: 권한은 서버가 다시 계산하고, 쓴 사람·시간은 서버가 채운다.
//
// 열 정의 [종류, 길이/목록, 'col' => DB 열 이름(다르면)]
//   s 글자(비어 있으면 NULL) · S 필수 글자 · z 글자(NULL 이어도 화면에는 빈 글자로) · t 긴 글 · T 필수 긴 글
//   d 날짜 · D 필수 날짜 · dt 날짜+시각 · DT 필수 · i 정수 · I 필수 정수 · b 참/거짓
//   j JSON · J 필수 JSON · u UUID · U 필수 UUID · x 짧은 코드 id · X 필수 코드 id · e 목록 중 하나 · E 필수
//   nref 이름으로 가리키는 다른 표의 행(화면은 이름, DB 는 id) · ro 읽기 전용 계산값 · virt 별도 처리(hook)

class SyncError extends Exception
{
    public function __construct(string $message, public string $table = '', public string $rowId = '')
    {
        parent::__construct($message);
    }
}

const DATA_UUID_RX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
const DATA_MAX_OPS = 3000;

/** @return array<string, array<string, mixed>> */
function data_specs(): array
{
    static $s = null;
    if ($s !== null) return $s;
    $s = [
        // ---------- 설정 성격 (level 1: 다른 표가 가리키는 것) ----------
        'categories' => [
            'table' => 'categories', 'idcols' => ['id'], 'idkinds' => ['code'], 'level' => 1,
            'read' => 'any', 'write' => 'admin', 'order' => 'm.sort_order, m.id', 'deferred' => ['parent_id'],
            'protect' => ['m', 'o', 'dead', 'move', 'keep', 'm.a.a'],
            'cols' => ['parent_id' => ['x', 40], 'name' => ['S', 40], 'sort_order' => ['I']],
        ],
        'communityList' => [
            'table' => 'communities', 'idcols' => ['id'], 'idkinds' => ['code'], 'level' => 1, 'sample' => true,
            'read' => 'any', 'write' => 'admin', 'order' => 'm.sort_order, m.name',
            'cols' => ['name' => ['S', 40], 'from' => ['I', 'col' => 'born_from'], 'to' => ['I', 'col' => 'born_to'], 'sort_order' => ['I']],
        ],
        'educationCourses' => [
            'table' => 'education_courses', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 1, 'sample' => true,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq',
            'cols' => ['name' => ['S', 60]],
        ],
        'meetings' => [
            'table' => 'meeting_officers', 'idcols' => ['meeting'], 'idkinds' => ['enum:공동의회|당회|제직회'], 'level' => 1,
            'read' => 'any', 'write' => 'admin', 'order' => 'm.meeting',
            'cols' => ['chair' => ['z', 80], 'secretary' => ['z', 80]],
        ],
        'yearSettings' => [
            'table' => 'year_settings', 'idcols' => ['year'], 'idkinds' => ['year'], 'level' => 1,
            'read' => 'any', 'write' => 'admin', 'order' => 'm.year',
            'cols' => [
                'motto' => ['z', 200], 'verse' => ['z', 100], 'ageMode' => ['E', '만 나이|한국 나이|연 나이', 'col' => 'age_mode'],
                'communities' => ['J', 'col' => 'communities'], 'societies' => ['J', 'col' => 'societies'], 'edu' => ['J', 'col' => 'edu'],
                'promoteDate' => ['d', 'col' => 'promote_date'], 'absentWeeks' => ['I', 'col' => 'absent_weeks'], 'newcomerDays' => ['I', 'col' => 'newcomer_days'],
            ],
        ],
        'smsTemplates' => [
            'table' => 'sms_templates', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 1,
            'read' => 'visit', 'write' => 'visit', 'order' => 'm.sort_order, m.seq',
            'cols' => ['title' => ['S', 60], 'body' => ['T'], 'sort_order' => ['I']],
        ],
        'site' => ['custom' => true, 'level' => 1, 'read' => 'any', 'write' => 'admin'],

        // ---------- 교인 ----------
        'people' => [
            'table' => 'people', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 2, 'sample' => true,
            'read' => 'any', 'write' => 'edit', 'del' => 'super', 'order' => 'm.seq', 'deferred' => ['guide_person_id', 'big_family_head_id'], 'post' => 'data_post_people',
            'cols' => [
                'name' => ['S', 50], 'name_note' => ['s', 10], 'phone' => ['s', 30], 'gender' => ['e', 'M|F'], 'birth_date' => ['d'],
                'email' => ['s', 100], 'birth_lunar' => ['b'], 'reg_no' => ['s', 30], 'address_zip' => ['s', 10], 'address1' => ['s', 255],
                'address2' => ['s', 255], 'occupation' => ['s', 100], 'workplace' => ['s', 100], 'car_number' => ['s', 100],
                'marital_status' => ['e', '미혼|기혼|이혼|사별|기타'], 'wedding_date' => ['d'], 'previous_church' => ['s', 100],
                'guide_person_id' => ['u'], 'guide_name' => ['s', 50], 'sms_opt_out' => ['b'], 'faith_checked_on' => ['d'], 'note' => ['t'],
                'purged_on' => ['d'], 'photo' => ['photo'], 'category_id' => ['x', 40],
                'clergy_title' => ['e', '담임목사|부목사|교육목사|전임전도사|교육전도사'], 'edu_dept' => ['e', '영아부|유치부|유초등부|중고등부|청년부'],
                'mission_society' => ['s', 40], 'big_family_head_id' => ['u'], 'big_family_relation' => ['s', 30],
                'has_account' => ['ro', 'bool' => true, 'expr' => 'EXISTS(SELECT 1 FROM ' . t('users') . ' u WHERE u.person_id = m.id)'],
            ],
        ],

        // ---------- level 3: 교인을 가리키는 설정 ----------
        'smallGroups' => [
            'table' => 'small_groups', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 3, 'sample' => true,
            'read' => 'any', 'write' => 'admin', 'order' => 'm.sort_order, m.seq',
            'cols' => [
                'community' => ['nref', 'community_id', 'communities', 'name', true], 'name' => ['S', 60], 'place' => ['s', 100],
                'leader_id' => ['u'], 'care' => ['b'], 'sort_order' => ['I'],
            ],
        ],
        'orgUnits' => [
            'table' => 'org_units', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 3, 'sample' => true,
            'read' => 'any', 'write' => 'admin', 'order' => 'm.sort_order, m.seq', 'deferred' => ['parent_id'],
            'cols' => [
                'parent_id' => ['u'], 'name' => ['S', 60], 'sort_order' => ['I'], 'leader_id' => ['u'], 'clergy_id' => ['u'], 'staff_ids' => ['virt'],
            ],
            'post' => 'data_post_org_units', 'after' => 'data_after_org_unit',
        ],
        'households' => [
            'table' => 'households', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 3, 'sample' => true,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq',
            'cols' => ['no' => ['S', 30], 'zip' => ['s', 10], 'address' => ['s', 255], 'home_phone' => ['s', 30], 'note' => ['t']],
        ],

        // ---------- level 4: 교인에 딸린 기록 ----------
        'householdMembers' => [
            'table' => 'household_members', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq',
            'cols' => [
                'household_id' => ['U'], 'person_id' => ['u'], 'name' => ['z', 50], 'birth_year' => ['i'], 'relation' => ['S', 20],
                'sort_order' => ['I'], 'note' => ['s', 255],
            ],
        ],
        'statusEvents' => [
            'table' => 'status_events', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq',
            'cols' => ['person_id' => ['U'], 'kind' => ['E', '등록|전입|복적|보류|보관|전출|별세|제적'], 'event_date' => ['d'], 'reason' => ['s', 255], 'church' => ['s', 100]],
        ],
        'faithEvents' => [
            'table' => 'faith_events', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq',
            'cols' => ['person_id' => ['U'], 'kind' => ['E', '학습|세례|유아세례|입교'], 'event_date' => ['d'], 'church' => ['s', 100], 'officiant' => ['s', 50], 'note' => ['s', 255]],
        ],
        'officeTerms' => [
            'table' => 'office_terms', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq',
            'cols' => [
                'person_id' => ['U'], 'office' => ['E', '목사|전도사|사모|장로|시무집사|권사|서리집사'],
                'rank_status' => ['E', '시무|은퇴|원로|명예|협동|사임|전입|서리|휴직'], 'start_date' => ['d'], 'end_date' => ['d'],
                'church' => ['s', 100], 'note' => ['s', 255],
            ],
        ],
        'personGroups' => [
            'table' => 'person_groups', 'idcols' => ['person_id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.person_id',
            'cols' => [
                'small_group' => ['nref', 'small_group_id', 'small_groups', 'name', true],
                'community' => ['ro', 'expr' => '(SELECT c.name FROM ' . t('small_groups') . ' sg JOIN ' . t('communities') . ' c ON c.id = sg.community_id WHERE sg.id = m.small_group_id)'],
            ],
        ],
        'groupNotes' => [
            'table' => 'group_notes', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'visit', 'write' => 'visit', 'order' => 'm.seq',
            'cols' => [
                'person_id' => ['u'], 'small_group' => ['nref', 'small_group_id', 'small_groups', 'name', false], 'date' => ['D'],
                'body' => ['T'], 'author' => ['z', 50],
            ],
        ],
        'attendance' => [
            'table' => 'worship_attendance', 'idcols' => ['sunday', 'person_id'], 'idkinds' => ['date', 'uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.sunday, m.person_id',
            'cols' => ['present' => ['b'], 'reason' => ['s', 255]],
        ],
        'groupAttendance' => [
            'table' => 'group_attendance', 'idcols' => ['sunday', 'person_id'], 'idkinds' => ['date', 'uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.sunday, m.person_id',
            'cols' => ['present' => ['b'], 'reason' => ['s', 255]],
        ],
        'worshipTotals' => [
            'table' => 'worship_totals', 'idcols' => ['sunday'], 'idkinds' => ['date'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.sunday',
            'cols' => ['total' => ['I']],
        ],
        'notes' => [
            'table' => 'notes', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq', 'stamp' => 'data_stamp_note',
            'cols' => ['person_id' => ['U'], 'body' => ['T'], 'author' => ['z', 50], 'created_at' => ['DT']],
        ],
        'legacyMemos' => [
            'table' => 'legacy_memos', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'visit', 'write' => 'visit', 'order' => 'm.seq',
            'cols' => ['person_id' => ['U'], 'body' => ['T']],
        ],
        'consents' => [
            'table' => 'consents', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq',
            'cols' => [
                'person_id' => ['U'], 'kind' => ['E', '교적 등록|민감정보|요람 공개|보호자 동의'], 'policy_version' => ['S', 30],
                'consent_date' => ['d'], 'method' => ['E', '서면|앱|구두'], 'guardian_name' => ['s', 50], 'revoked_on' => ['d'],
            ],
        ],
        'visits' => [
            'table' => 'visits', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4, 'sample' => true,
            'read' => 'visit', 'write' => 'visit', 'order' => 'm.seq',
            'cols' => [
                'person_id' => ['U'], 'visit_date' => ['D'], 'kind' => ['S', 30], 'method' => ['E', '대면|전화'], 'pastor' => ['S', 50],
                'companions' => ['s', 255], 'scripture' => ['s', 100], 'hymn' => ['s', 100], 'content' => ['T'], 'follow_up' => ['t'],
            ],
        ],
        'affiliations' => [
            'table' => 'affiliations', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq',
            'cols' => [
                'person_id' => ['U'], 'org' => ['nref', 'org_unit_id', 'org_units', 'name', true], 'role' => ['s', 40],
                'start_date' => ['d'], 'end_date' => ['d'],
            ],
        ],
        'educationRecords' => [
            'table' => 'education_records', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq',
            'cols' => [
                'person_id' => ['U'], 'course_id' => ['U'], 'start_date' => ['d'], 'end_date' => ['d'], 'completed' => ['b'],
                'instructor' => ['s', 50], 'note' => ['s', 255],
            ],
        ],
        'events' => [
            'table' => 'events', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4, 'sample' => true,
            'read' => 'any', 'write' => 'edit', 'order' => 'm.seq',
            'cols' => [
                'kind' => ['E', '성도|교회 절기|사역 일정|성례 일정'], 'date' => ['D'], 'end_date' => ['d'], 'title' => ['S', 200],
                'person_id' => ['u'], 'note' => ['s', 255],
            ],
        ],
        'certRequests' => [
            'table' => 'certificate_requests', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4,
            'read' => 'admin', 'write' => 'admin', 'order' => 'm.seq', 'stamp' => 'data_stamp_cert_request',
            'cols' => [
                'person_id' => ['U'], 'kind' => ['S', 30], 'purpose' => ['z', 100], 'requested_at' => ['DT'],
                'status' => ['E', '대기|승인|반려'], 'decided_at' => ['dt'], 'decided_by' => ['s', 50],
            ],
        ],

        // ---------- 기록: 추가만 가능. 쓴 사람·시간은 서버가 채운다 ----------
        'changeLog' => [
            'table' => 'change_log', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4, 'append' => true,
            'read' => 'any', 'write' => 'any', 'order' => 'm.at DESC, m.seq DESC', 'limit' => 3000, 'stamp' => 'data_stamp_change_log',
            'cols' => [
                'person_id' => ['u'], 'at' => ['DT'], 'by' => ['z', 50, 'col' => 'by_name'], 'field' => ['S', 60],
                'before' => ['t', 'col' => 'before_value'], 'after' => ['t', 'col' => 'after_value'],
            ],
        ],
        'accessLog' => [
            'table' => 'access_log', 'idcols' => ['id'], 'idkinds' => ['uuid'], 'level' => 4, 'append' => true,
            'read' => 'admin', 'write' => 'any', 'order' => 'm.at DESC, m.seq DESC', 'limit' => 500, 'stamp' => 'data_stamp_access_log',
            'cols' => ['at' => ['DT'], 'who' => ['z', 50], 'action' => ['S', 40], 'target' => ['z', 100]],
        ],
    ];
    return $s;
}

// ===================================================== 값 바꾸기 =====================================================

function data_required(array $c): bool
{
    return in_array($c[0], ['S', 'T', 'D', 'DT', 'I', 'U', 'X', 'E', 'J'], true) || ($c[0] === 'nref' && !empty($c[4]));
}

/** 화면에서 온 값을 DB 에 넣을 값으로. 규칙에 맞지 않으면 SyncError. */
function data_to_db(array $c, mixed $v, string $label): mixed
{
    $t = $c[0];
    $req = data_required($c);
    if ($t === 'b') return ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
    if ($v === null || $v === '' || (is_array($v) && $t !== 'j' && $t !== 'J')) {
        if ($req) throw new SyncError("{$label}: 비워 둘 수 없습니다.");
        return null;
    }
    switch ($t) {
        case 's': case 'S': case 'z': case 't': case 'T':
            if (!is_string($v) && !is_int($v) && !is_float($v)) throw new SyncError("{$label}: 글자만 넣을 수 있습니다.");
            $v = str_replace("\0", '', (string)$v);
            if (isset($c[1]) && is_int($c[1]) && mb_strlen($v) > $c[1]) throw new SyncError("{$label}: {$c[1]}자 이하로 넣어 주세요.");
            if ($t === 'T' && trim($v) === '') throw new SyncError("{$label}: 비워 둘 수 없습니다.");
            return $v;
        case 'd': case 'D':
            if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new SyncError("{$label}: 날짜 모양이 올바르지 않습니다.");
            return $v;
        case 'dt': case 'DT':
            // 시각 없이 날짜만 와도 받는다 (00:00 으로)
            if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new SyncError("{$label}: 날짜·시각 모양이 올바르지 않습니다.");
            return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00', $m[6] ?? '00');
        case 'i': case 'I':
            if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,10}$/', $v)) && !(is_float($v) && floor($v) === $v)) throw new SyncError("{$label}: 정수가 아닙니다.");
            return (int)$v;
        case 'j': case 'J':
            $enc = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($enc === false || strlen($enc) > 1_000_000) throw new SyncError("{$label}: 값이 올바르지 않거나 너무 큽니다.");
            return $enc;
        case 'u': case 'U':
            if (!is_string($v) || !preg_match(DATA_UUID_RX, $v)) throw new SyncError("{$label}: 연결 값(id) 모양이 올바르지 않습니다.");
            return strtolower($v);
        case 'x': case 'X':
            if (!is_string($v) || $v === '' || mb_strlen($v) > (int)($c[1] ?? 40) || preg_match('/[\x00-\x1f]/', $v)) throw new SyncError("{$label}: 코드 값이 올바르지 않습니다.");
            return $v;
        case 'e': case 'E':
            if (!is_string($v) || !in_array($v, explode('|', (string)$c[1]), true)) throw new SyncError("{$label}: 허용되지 않는 값입니다.");
            return $v;
        case 'nref':
            if (!is_string($v)) throw new SyncError("{$label}: 이름이 올바르지 않습니다.");
            $id = val('SELECT id FROM ' . t($c[2]) . ' WHERE `' . $c[3] . '` = ? LIMIT 1', [$v]);
            if ($id === null) throw new SyncError("{$label}: '{$v}' 을(를) 찾을 수 없습니다.");
            return $id;
    }
    throw new SyncError("{$label}: 알 수 없는 칸 종류입니다.");
}

/** DB 에서 읽은 값을 화면이 쓰는 값으로. */
function data_from_db(array $c, mixed $v): mixed
{
    switch ($c[0]) {
        case 'b': return (int)$v === 1;
        case 'i': case 'I': return $v === null ? null : (int)$v;
        case 'j': case 'J': return $v === null ? null : json_decode((string)$v);
        case 'z': return $v === null ? '' : (string)$v;
        case 'S': case 'T': return $v === null ? '' : (string)$v;
        case 'dt': case 'DT': return $v === null ? null : substr((string)$v, 0, 16);
        case 'nref': return $v === null ? '' : (string)$v;
        case 'ro': return !empty($c['bool']) ? (bool)(int)$v : ($v === null ? null : (string)$v);
        default: return $v === null ? null : (string)$v;
    }
}

/** 한 칸의 DB 열 이름 */
function data_col(string $key, array $c): string
{
    if ($c[0] === 'nref') return $c[1]; // 이름으로 가리키는 칸: DB 에는 id 열(community_id 등)이 있다
    return $c['col'] ?? $key;
}

// ===================================================== 권한 =====================================================

function data_perm_ok(array $user, string $need): bool
{
    if ($need === 'any') return true;
    if ($need === 'super') return (int)$user['is_super'] === 1;
    return perms_of($user)[$need] ?? false;
}

function data_version(): int
{
    return (int)val('SELECT v FROM ' . t('meta') . " WHERE k = 'data_version'");
}

// ===================================================== 읽기 =====================================================

/** 이 사용자가 읽을 수 있는 모든 표를 한 번에. */
function data_bootstrap(array $user): array
{
    $tables = [];
    foreach (data_specs() as $name => $spec) {
        if (!data_perm_ok($user, $spec['read'])) continue;
        $tables[$name] = isset($spec['custom']) ? data_read_site($user) : data_read_table($name, $spec, $user);
    }
    return ['version' => data_version(), 'tables' => $tables];
}

function data_read_table(string $name, array $spec, array $user): array
{
    $sel = [];
    foreach ($spec['idcols'] as $ic) $sel[] = "m.`{$ic}` AS `_id_{$ic}`";
    foreach ($spec['cols'] as $key => $c) {
        $kind = $c[0];
        if ($kind === 'virt') continue;
        if ($kind === 'ro') { $sel[] = '(' . $c['expr'] . ') AS `' . $key . '`'; continue; }
        if ($kind === 'nref') { $sel[] = '(SELECT r.`' . $c[3] . '` FROM ' . t($c[2]) . ' r WHERE r.id = m.`' . $c[1] . '`) AS `' . $key . '`'; continue; }
        $sel[] = 'm.`' . data_col($key, $c) . '` AS `' . $key . '`';
    }
    $sql = 'SELECT ' . implode(', ', $sel) . ' FROM ' . t($spec['table']) . ' m ORDER BY ' . $spec['order'];
    if (!empty($spec['limit'])) $sql .= ' LIMIT ' . (int)$spec['limit'];
    $out = [];
    foreach (rows($sql) as $r) {
        $row = [];
        $ids = [];
        foreach ($spec['idcols'] as $i => $ic) {
            $raw = $r['_id_' . $ic];
            $ids[] = (string)$raw;
            $row[$ic] = ($spec['idkinds'][$i] ?? '') === 'year' ? (int)$raw : (string)$raw;
        }
        $row['id'] = implode('|', $ids);
        foreach ($spec['cols'] as $key => $c) {
            if ($c[0] === 'virt') continue;
            $row[$key] = data_from_db($c, $r[$key]);
        }
        $out[] = $row;
    }
    if (isset($spec['post'])) $out = ($spec['post'])($out, $user);
    return $out;
}

/** 사진 파일 경로를 화면이 쓸 주소로 바꾼다. */
function data_post_people(array $rows, array $user): array
{
    foreach ($rows as &$r) $r['photo'] = $r['photo'] ? data_file_url('photo', (string)$r['photo'], $r['id']) : null;
    return $rows;
}

/** 부서 담당자 목록(staff_ids)을 붙인다. */
function data_post_org_units(array $rows, array $user): array
{
    $staff = [];
    foreach (rows('SELECT org_unit_id, person_id FROM ' . t('org_unit_staff') . ' ORDER BY org_unit_id, sort_order') as $r) $staff[$r['org_unit_id']][] = $r['person_id'];
    foreach ($rows as &$r) $r['staff_ids'] = $staff[$r['id']] ?? [];
    return $rows;
}

// ===================================================== 파일(사진·로고·직인) =====================================================

function data_upload_dir(): string
{
    $d = CM_DATA . '/uploads';
    if (!is_dir($d)) @mkdir($d, 0705, true);
    return $d;
}

/** 'data:image/png;base64,...' 를 검사해서 저장한다. @return string DATA/uploads 아래의 경로 */
function data_save_image(string $dataUrl, string $relNoExt, int $maxBytes = 2_000_000): string
{
    if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) throw new SyncError('그림은 PNG·JPG·WEBP 만 올릴 수 있습니다.');
    $bin = base64_decode($m[2], true);
    if ($bin === false || $bin === '') throw new SyncError('그림 파일을 읽지 못했습니다.');
    if (strlen($bin) > $maxBytes) throw new SyncError('그림이 너무 큽니다. (2MB 이하)');
    $info = @getimagesizefromstring($bin); // 그림이 아닌 파일(HTML 등)을 걸러낸다
    $mimeOk = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
    if (!$info || ($info['mime'] ?? '') !== $mimeOk[$m[1]]) throw new SyncError('그림 파일이 올바르지 않습니다.');
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $dir = data_upload_dir();
    $rel = $relNoExt . '.' . $ext;
    @mkdir(dirname($dir . '/' . $rel), 0705, true);
    foreach (['png', 'jpg', 'webp'] as $e) if ($e !== $ext) @unlink($dir . '/' . $relNoExt . '.' . $e); // 이전 형식 파일 정리
    if (file_put_contents($dir . '/' . $rel, $bin, LOCK_EX) === false) throw new SyncError('그림을 저장하지 못했습니다. data 폴더 권한을 확인해 주세요.');
    return $rel;
}

function data_file_url(string $kind, ?string $rel, string $id = ''): ?string
{
    if (!$rel) return null;
    $f = data_upload_dir() . '/' . $rel;
    $v = is_file($f) ? filemtime($f) : 0;
    return $kind === 'photo' ? "/files/photo/{$id}?v={$v}" : "/files/{$kind}?v={$v}";
}

function data_delete_file(?string $rel): void
{
    if ($rel && !str_contains($rel, '..')) @unlink(data_upload_dir() . '/' . $rel);
}

// ===================================================== 교회 기본 설정(site) =====================================================

function data_read_site(array $user): array
{
    $r = row('SELECT * FROM ' . t('site_settings') . ' WHERE id = 1');
    if (!$r) return [];
    $ci = json_decode((string)$r['church_info'], true) ?: [];
    $ci['logo'] = data_file_url('logo', $r['logo_path']);
    $ci['favicon'] = data_file_url('favicon', $r['favicon_path'] ?? null);
    // 직인은 증명서를 만들 수 있는 사람(내려받기 권한)에게만 보인다.
    $ci['seal'] = perms_of($user)['export'] ? data_file_url('seal', $r['seal_path']) : null;
    return [[
        'id' => '1', 'churchInfo' => $ci, 'certForm' => json_decode((string)$r['cert_form']),
        'codes' => json_decode((string)$r['codes']), 'backup' => json_decode((string)$r['backup']),
    ]];
}

function data_write_site(string $id, array $set, array &$ctx): void
{
    if ($id !== '1') throw new SyncError('설정 id 가 올바르지 않습니다.', 'site', $id);
    $cur = row('SELECT * FROM ' . t('site_settings') . ' WHERE id = 1');
    if (!$cur) throw new SyncError('기본 설정이 없습니다.', 'site', $id);
    $upd = [];
    foreach ($set as $k => $v) {
        if ($k === 'id') continue;
        if (!in_array($k, ['churchInfo', 'certForm', 'codes', 'backup'], true)) throw new SyncError("알 수 없는 설정 칸: {$k}", 'site', $id);
        if (!is_array($v)) throw new SyncError("{$k}: 값이 올바르지 않습니다.", 'site', $id);
        if ($k === 'churchInfo') {
            foreach (['logo' => 'logo_path', 'seal' => 'seal_path', 'favicon' => 'favicon_path'] as $f => $col) {
                if (!array_key_exists($f, $v)) continue;
                $val = $v[$f];
                if (is_string($val) && str_starts_with($val, 'data:')) {
                    $new = data_save_image($val, $f, $f === 'favicon' ? 200_000 : 1_000_000);
                    if ($cur[$col] && $cur[$col] !== $new) data_delete_file($cur[$col]);
                    $upd[$col] = $new;
                    $ctx['files']["site|1|{$f}"] = data_file_url($f, $new);
                } elseif ($val === null || $val === '') {
                    data_delete_file($cur[$col]);
                    $upd[$col] = null;
                } // 이미 있는 그림의 주소(/files/…)가 그대로 오면 바꾸지 않는다.
                $v[$f] = null; // JSON 에는 그림을 넣지 않는다. (경로는 별도 열)
            }
        }
        $upd[['churchInfo' => 'church_info', 'certForm' => 'cert_form', 'codes' => 'codes', 'backup' => 'backup'][$k]] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!$upd) return;
    $sets = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($upd)));
    q('UPDATE ' . t('site_settings') . " SET {$sets}, updated_by = ? WHERE id = 1", [...array_values($upd), $ctx['user']['id']]);
}

// ===================================================== 쓴 사람·시간을 서버가 채움 =====================================================

function data_stamp_note(array &$vals, bool $isInsert, array $ctx): void
{
    if (!$isInsert) { unset($vals['author'], $vals['created_at']); return; }
    $vals['author'] = $ctx['user']['name'];
    $vals['created_at'] = date('Y-m-d H:i:s');
}
function data_stamp_change_log(array &$vals, bool $isInsert, array $ctx): void
{
    if (!$isInsert) { $vals = []; return; } // 기록은 고칠 수 없다
    $vals['by_name'] = $ctx['user']['name'];
    $vals['by_user_id'] = (int)$ctx['user']['id'];
    $vals['at'] = date('Y-m-d H:i:s');
}
function data_stamp_access_log(array &$vals, bool $isInsert, array $ctx): void
{
    if (!$isInsert) { $vals = []; return; }
    $vals['who'] = $ctx['user']['name'];
    $vals['user_id'] = (int)$ctx['user']['id'];
    $vals['at'] = date('Y-m-d H:i:s');
}
function data_stamp_cert_request(array &$vals, bool $isInsert, array $ctx): void
{
    unset($vals['decided_at'], $vals['decided_by']);
    if (isset($vals['status']) && in_array($vals['status'], ['승인', '반려'], true)) {
        $vals['decided_at'] = date('Y-m-d H:i:s');
        $vals['decided_by'] = $ctx['user']['name'];
    }
}

/** 부서 담당자(staff_ids)를 저장한다. */
function data_after_org_unit(string $id, array $set, array &$ctx): void
{
    if (!array_key_exists('staff_ids', $set)) return;
    $ids = $set['staff_ids'];
    if (!is_array($ids)) throw new SyncError('담당자 목록이 올바르지 않습니다.', 'orgUnits', $id);
    q('DELETE FROM ' . t('org_unit_staff') . ' WHERE org_unit_id = ?', [$id]);
    $seen = [];
    foreach (array_values($ids) as $i => $pid) {
        $pid = data_to_db(['U'], $pid, '담당자');
        if (isset($seen[$pid])) continue;
        $seen[$pid] = true;
        q('INSERT INTO ' . t('org_unit_staff') . ' (org_unit_id, person_id, sort_order) VALUES (?,?,?)', [$id, $pid, $i + 1]);
    }
}

// ===================================================== 저장 =====================================================

/** id 문자열을 기본키 열 값들로. */
function data_id_parts(array $spec, string $id, string $name): array
{
    $cols = $spec['idcols'];
    $parts = count($cols) === 1 ? [$id] : explode('|', $id);
    if (count($parts) !== count($cols)) throw new SyncError('id 모양이 올바르지 않습니다.', $name, $id);
    $out = [];
    foreach ($cols as $i => $col) {
        $kind = $spec['idkinds'][$i];
        $p = $parts[$i];
        $ok = match (true) {
            $kind === 'uuid' => (bool)preg_match(DATA_UUID_RX, $p),
            $kind === 'code' => $p !== '' && mb_strlen($p) <= 40 && !preg_match('/[\x00-\x1f]/', $p),
            $kind === 'date' => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $p) && checkdate((int)substr($p, 5, 2), (int)substr($p, 8, 2), (int)substr($p, 0, 4)),
            $kind === 'year' => (bool)preg_match('/^\d{4}$/', $p),
            str_starts_with($kind, 'enum:') => in_array($p, explode('|', substr($kind, 5)), true),
            default => false,
        };
        if (!$ok) throw new SyncError('id 모양이 올바르지 않습니다.', $name, $id);
        $out[$col] = $kind === 'uuid' ? strtolower($p) : $p;
    }
    return $out;
}

/** 쓰기 요청 한 묶음을 하나의 트랜잭션으로 저장한다. @return array{version:int, prev:int, files:array} */
function data_apply(array $user, array $ops): array
{
    $specs = data_specs();
    $isSuper = (int)$user['is_super'] === 1;

    // 1) 모양과 권한을 먼저 확인한다. (DB 를 건드리기 전에)
    $upserts = [];
    $deletes = [];
    foreach ($ops as $i => $op) {
        if (!is_array($op) || !isset($op['t'], $op['id']) || !is_string($op['t']) || !is_string($op['id'])) throw new SyncError("{$i}번째 요청 모양이 올바르지 않습니다.");
        $name = $op['t'];
        if (!isset($specs[$name])) throw new SyncError("알 수 없는 표: {$name}");
        $spec = $specs[$name];
        if (!data_perm_ok($user, $spec['write'])) throw new SyncError('이 작업을 할 권한이 없습니다.', $name, $op['id']);
        if (!empty($op['del'])) {
            if (!empty($spec['append'])) throw new SyncError('기록은 지울 수 없습니다.', $name, $op['id']);
            // 교인 삭제처럼 딸린 기록까지 사라지는 일은 더 높은 권한이 필요하다. (교인은 '보관'으로 처리하고 지우지 않는 것이 원칙)
            if (!data_perm_ok($user, $spec['del'] ?? $spec['write'])) throw new SyncError('이 작업을 할 권한이 없습니다. (최고관리자만 지울 수 있습니다)', $name, $op['id']);
            $deletes[] = $op;
        } else {
            if (!isset($op['set']) || !is_array($op['set'])) throw new SyncError('저장할 값이 없습니다.', $name, $op['id']);
            if (!empty($op['sample']) && !$isSuper) throw new SyncError('샘플 데이터는 최고관리자만 넣을 수 있습니다.', $name, $op['id']);
            $upserts[] = $op;
        }
    }
    $lv = fn(array $op) => $specs[$op['t']]['level'];
    usort($upserts, fn($a, $b) => $lv($a) <=> $lv($b));
    usort($deletes, fn($a, $b) => $lv($b) <=> $lv($a));

    $ctx = ['user' => $user, 'files' => []];
    $deferred = [];
    $pdo = db();
    $prev = data_version();
    $pdo->beginTransaction();
    try {
        // 2) 지우기: 딸린 표부터
        foreach ($deletes as $op) data_delete_row($specs[$op['t']], $op['t'], $op['id']);
        // 3) 저장: 먼저 만들어져야 하는 표부터. 같은 표 안에서 서로 가리키는 칸(상위 부서, 인도자 등)은 맨 뒤에 연결한다.
        foreach ($upserts as $op) data_upsert_row($specs[$op['t']], $op['t'], $op['id'], $op['set'], !empty($op['sample']), $ctx, $deferred);
        foreach ($deferred as [$spec, $name, $parts, $vals]) {
            $sets = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($vals)));
            q('UPDATE ' . t($spec['table']) . " SET {$sets} WHERE " . implode(' AND ', array_map(fn($c) => "`{$c}` = ?", array_keys($parts))), [...array_values($vals), ...array_values($parts)]);
        }
        if ($ops) q('UPDATE ' . t('meta') . " SET v = v + 1 WHERE k = 'data_version'");
        $pdo->commit();
    } catch (SyncError $e) {
        $pdo->rollBack();
        throw $e;
    } catch (PDOException $e) {
        $pdo->rollBack();
        cm_log('error', 'sync: ' . $e->getMessage());
        // 연결된 자료가 있어서 지울 수 없거나, 없는 자료를 가리키는 경우 등
        $why = str_contains($e->getMessage(), 'foreign key') ? '연결된 다른 자료 때문에 저장하지 못했습니다.' : (str_contains($e->getMessage(), 'Duplicate') ? '이미 같은 값이 있어 저장하지 못했습니다.' : '저장하지 못했습니다.');
        throw new SyncError($why);
    }
    return ['version' => data_version(), 'prev' => $prev, 'files' => $ctx['files']];
}

function data_delete_row(array $spec, string $name, string $id): void
{
    if (isset($spec['custom'])) throw new SyncError('이 설정은 지울 수 없습니다.', $name, $id);
    $parts = data_id_parts($spec, $id, $name);
    if (in_array($id, $spec['protect'] ?? [], true)) throw new SyncError('기본 항목은 지울 수 없습니다.', $name, $id);
    if ($name === 'people') {
        $old = val('SELECT photo FROM ' . t('people') . ' WHERE id = ?', [$id]);
        data_delete_file($old);
    }
    q('DELETE FROM ' . t($spec['table']) . ' WHERE ' . implode(' AND ', array_map(fn($c) => "`{$c}` = ?", array_keys($parts))), array_values($parts));
}

function data_upsert_row(array $spec, string $name, string $id, array $set, bool $sample, array &$ctx, array &$deferred): void
{
    if (isset($spec['custom'])) { data_write_site($id, $set, $ctx); return; }
    $parts = data_id_parts($spec, $id, $name);
    $cols = $spec['cols'];
    $vals = [];
    foreach ($set as $key => $v) {
        if ($key === 'id' || array_key_exists($key, $parts)) continue; // 기본키는 id 로만 정한다
        if (!isset($cols[$key])) throw new SyncError("알 수 없는 칸: {$key}", $name, $id);
        $c = $cols[$key];
        if ($c[0] === 'ro') continue; // 계산값: 화면이 보내도 무시
        if ($c[0] === 'virt') continue; // hook 이 처리
        if ($c[0] === 'photo') { $vals['photo'] = data_photo_value($id, $v, $ctx, $name); continue; }
        try {
            $vals[data_col($key, $c)] = data_to_db($c, $v, $key);
        } catch (SyncError $e) {
            throw new SyncError($e->getMessage(), $name, $id);
        }
    }
    $where = implode(' AND ', array_map(fn($c) => "`{$c}` = ?", array_keys($parts)));
    $exists = val('SELECT 1 FROM ' . t($spec['table']) . " WHERE {$where}", array_values($parts)) !== null;
    if (isset($spec['stamp'])) ($spec['stamp'])($vals, !$exists, $ctx); // 쓴 사람·시간 등은 서버가 채운다
    if ($exists && !empty($spec['append'])) return; // 기록은 고치지 않는다 (stamp 가 비움)
    if (!$exists) {
        // 새로 만들 때는 꼭 필요한 칸이 모두 있어야 한다.
        foreach ($cols as $key => $c) {
            if (in_array($c[0], ['ro', 'virt', 'photo'], true) || !data_required($c)) continue;
            if (!array_key_exists(data_col($key, $c), $vals)) throw new SyncError("{$key}: 비워 둘 수 없습니다.", $name, $id);
        }
        if ($sample && !empty($spec['sample'])) $vals['is_sample'] = 1;
    }
    // 서로 가리키는 칸은 뒤로 미룬다.
    $later = [];
    foreach ($spec['deferred'] ?? [] as $dc) if (array_key_exists($dc, $vals)) { $later[$dc] = $vals[$dc]; unset($vals[$dc]); }
    if ($exists) {
        if ($vals) q('UPDATE ' . t($spec['table']) . ' SET ' . implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($vals))) . " WHERE {$where}", [...array_values($vals), ...array_values($parts)]);
    } else {
        $all = $parts + $vals;
        q('INSERT INTO ' . t($spec['table']) . ' (' . implode(', ', array_map(fn($c) => "`{$c}`", array_keys($all))) . ') VALUES (' . marks($all) . ')', array_values($all));
    }
    if ($later) $deferred[] = [$spec, $name, $parts, $later];
    if (isset($spec['after'])) ($spec['after'])($id, $set, $ctx);
}

/** 교인 사진: 새 그림(data:)이면 파일로 저장하고, 이미 있는 그림의 주소(/files/…)면 그대로 둔다. */
function data_photo_value(string $personId, mixed $v, array &$ctx, string $name): mixed
{
    if ($v === null || $v === '') {
        data_delete_file(val('SELECT photo FROM ' . t('people') . ' WHERE id = ?', [$personId]));
        return null;
    }
    if (is_string($v) && str_starts_with($v, 'data:')) {
        try {
            $rel = data_save_image($v, 'photos/' . strtolower($personId), 600_000);
        } catch (SyncError $e) {
            throw new SyncError($e->getMessage(), $name, $personId);
        }
        $ctx['files']["people|{$personId}|photo"] = data_file_url('photo', $rel, strtolower($personId));
        return $rel;
    }
    // 새로 만드는 교인이 주소 형태의 값을 보내면 무시(DB 의 값을 그대로 둠)
    return val('SELECT photo FROM ' . t('people') . ' WHERE id = ?', [$personId]);
}
