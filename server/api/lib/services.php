<?php
declare(strict_types=1);

// 외부 서비스 연결(선택 기능): 문자 발송(솔라피)과 AI 분석(구글 Gemini). 교회가 직접 만든 키를 넣어야 켜진다.
// 키와 비밀은 DB(meta 표)에만 두고 화면으로는 절대 내려보내지 않는다. (백업 파일에도 넣지 않는다.)
// 시험용으로 data/config.php 에 'sms_base', 'ai_base' 를 적으면 그 주소로 보낸다.

const SMS_BASE = 'https://api.solapi.com';
const AI_BASE = 'https://generativelanguage.googleapis.com';
const AI_DEFAULT_MODEL = 'gemini-2.5-flash';
const AI_MAX_BYTES = 14_000_000;   // 한 번에 AI 로 보내는 파일 크기(바이트). 인코딩하면 커지므로 안전하게 이 정도까지만
const TMP_CHUNK = 60_000;          // 큰 파일을 올릴 때 한 번에 보내는 조각 크기 (호스팅의 128KB 한도 아래)
const SMS_DAILY_LIMIT = 3000;      // 하루에 보낼 수 있는 문자 수 (실수로 많이 보내는 것을 막는 안전장치)

function integ_get(string $name): array
{
    $v = meta_get('int_' . $name);
    return $v ? (json_decode($v, true) ?: []) : [];
}

function integ_set(string $name, array $v): void
{
    meta_set('int_' . $name, json_encode($v, JSON_UNESCAPED_UNICODE));
}

function integ_features(): array
{
    $s = integ_get('sms');
    $a = integ_get('ai');
    return ['sms' => !empty($s['key']) && !empty($s['secret']) && !empty($s['sender']), 'ai' => !empty($a['key'])];
}

function integ_mask(?string $s): string
{
    $s = (string)$s;
    return $s === '' ? '' : '••••' . substr($s, -4);
}

/** @return array{status:int, body:string} */
function http_call(string $method, string $url, array $headers, ?string $body = null, int $timeout = 60): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) throw new RuntimeException('외부 서비스에 연결하지 못했습니다. ' . $err);
        return ['status' => $status, 'body' => (string)$out];
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true]]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) throw new RuntimeException('외부 서비스에 연결하지 못했습니다. (호스팅에서 외부 접속이 막혀 있을 수 있습니다.)');
    $status = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    return ['status' => $status, 'body' => (string)$out];
}

// ===================================================== 문자(솔라피) =====================================================

function sms_headers(array $c): array
{
    $date = gmdate('Y-m-d\TH:i:s\Z');
    $salt = bin2hex(random_bytes(16));
    $sig = hash_hmac('sha256', $date . $salt, (string)$c['secret']);
    return ['Authorization: HMAC-SHA256 apiKey=' . $c['key'] . ', date=' . $date . ', salt=' . $salt . ', signature=' . $sig, 'Content-Type: application/json'];
}

function sms_base(): string
{
    return rtrim((string)(cm_config()['sms_base'] ?? SMS_BASE), '/');
}

/** 솔라피 잔액 조회. 키가 맞는지 확인하는 데도 쓴다. */
function sms_balance(array $c): array
{
    $r = http_call('GET', sms_base() . '/cash/v1/balance', sms_headers($c), null, 20);
    $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !is_array($j)) throw new RuntimeException(sms_error_text($r));
    return ['balance' => (int)($j['balance'] ?? 0), 'point' => (int)($j['point'] ?? 0)];
}

function sms_error_text(array $r): string
{
    $j = json_decode($r['body'], true);
    $m = is_array($j) ? (string)($j['errorMessage'] ?? $j['message'] ?? '') : '';
    if ($r['status'] === 401 || $r['status'] === 403) return '솔라피 키가 맞지 않거나 권한이 없습니다.' . ($m !== '' ? " ($m)" : '');
    return '문자 서비스 오류(' . $r['status'] . ')' . ($m !== '' ? ': ' . $m : '');
}

/**
 * 문자를 보낸다. @param array<int, array{to:string, text:string}> $msgs
 * @return array{sent:int, failed:int, group:string}
 */
function sms_send(array $c, array $msgs, string $subject): array
{
    $list = [];
    foreach ($msgs as $m) {
        $isLms = strlen(mb_convert_encoding($m['text'], 'EUC-KR', 'UTF-8')) > 90;
        $one = ['to' => $m['to'], 'from' => $c['sender'], 'text' => $m['text'], 'type' => $isLms ? 'LMS' : 'SMS'];
        if ($isLms && $subject !== '') $one['subject'] = $subject;
        $list[] = $one;
    }
    $r = http_call('POST', sms_base() . '/messages/v4/send-many/detail', sms_headers($c), json_encode(['messages' => $list, 'allowDuplicates' => true], JSON_UNESCAPED_UNICODE), 60);
    $j = json_decode($r['body'], true);
    if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($j)) throw new RuntimeException(sms_error_text($r));
    $count = $j['groupInfo']['count'] ?? [];
    $failed = isset($j['failedMessageList']) && is_array($j['failedMessageList']) ? count($j['failedMessageList']) : (int)($count['registeredFailed'] ?? 0);
    $total = (int)($count['total'] ?? count($list));
    return ['sent' => max(0, $total - $failed), 'failed' => $failed, 'group' => (string)($j['groupInfo']['groupId'] ?? $j['groupId'] ?? '')];
}

// ===================================================== AI(Gemini) =====================================================

function ai_base(): string
{
    return rtrim((string)(cm_config()['ai_base'] ?? AI_BASE), '/');
}

/** 글·그림·소리·PDF 를 AI 에 보내 JSON 으로 답을 받는다. @param array<int, array<string, mixed>> $parts @return array<string, mixed> */
function ai_json(array $parts): array
{
    $c = integ_get('ai');
    if (empty($c['key'])) throw new RuntimeException('AI 서비스 키가 설정되지 않았습니다.');
    $model = preg_match('/^[\w.\-]{3,60}$/', (string)($c['model'] ?? '')) ? $c['model'] : AI_DEFAULT_MODEL;
    $body = json_encode(['contents' => [['role' => 'user', 'parts' => $parts]], 'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.1]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $r = http_call('POST', ai_base() . '/v1beta/models/' . $model . ':generateContent', ['x-goog-api-key: ' . $c['key'], 'Content-Type: application/json'], $body, 180);
    $j = json_decode($r['body'], true);
    if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($j)) {
        $m = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
        if (in_array($r['status'], [400, 401, 403], true) && stripos($m, 'key') !== false) throw new RuntimeException('AI 서비스 키가 맞지 않습니다.');
        if ($r['status'] === 429) throw new RuntimeException('AI 서비스의 사용 한도를 넘었습니다. 잠시 뒤 다시 시도해 주세요.');
        throw new RuntimeException('AI 서비스 오류(' . $r['status'] . ')' . ($m !== '' ? ': ' . mb_substr($m, 0, 120) : ''));
    }
    $text = '';
    foreach ($j['candidates'][0]['content']['parts'] ?? [] as $p) $text .= (string)($p['text'] ?? '');
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)));
    $out = json_decode($text, true);
    if (!is_array($out)) throw new RuntimeException('AI 가 읽을 수 있는 결과를 주지 않았습니다. 다시 시도해 주세요.');
    return $out;
}

// ---- 값 정리 (AI 의 답은 믿지 않고, 화면이 받는 모양으로 걸러 낸다) ----
function ai_str(mixed $v, int $max = 200): ?string
{
    if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
    $s = trim((string)$v);
    return $s === '' ? null : mb_substr($s, 0, $max);
}

function ai_date(mixed $v): ?string
{
    $s = ai_str($v, 10);
    if ($s === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : null;
}

function ai_pick(mixed $v, array $allowed): ?string
{
    $s = ai_str($v, 30);
    return $s !== null && in_array($s, $allowed, true) ? $s : null;
}

/** 등록카드 사진에서 읽은 내용을 교인 등록 칸 모양으로 */
function ai_clean_card(array $r): array
{
    $keys = ['name', 'gender', 'birth_date', 'birth_lunar', 'phone', 'email', 'address_zip', 'address1', 'address2', 'occupation', 'marital_status', 'wedding_date', 'previous_church', 'guide_name', 'faith_kind', 'faith_date', 'faith_church', 'office', 'reg_date', 'family', 'memo'];
    $out = array_fill_keys($keys, null);
    foreach (['name' => 50, 'phone' => 30, 'email' => 100, 'address_zip' => 10, 'address1' => 255, 'address2' => 255, 'occupation' => 100, 'previous_church' => 100, 'guide_name' => 50, 'faith_church' => 100, 'memo' => 1000] as $k => $max) $out[$k] = ai_str($r[$k] ?? null, $max);
    $g = strtoupper((string)ai_str($r['gender'] ?? null, 10));
    $out['gender'] = in_array($g, ['M', 'F'], true) ? $g : (in_array($r['gender'] ?? '', ['남', '남자'], true) ? 'M' : (in_array($r['gender'] ?? '', ['여', '여자'], true) ? 'F' : null));
    foreach (['birth_date', 'wedding_date', 'faith_date', 'reg_date'] as $k) $out[$k] = ai_date($r[$k] ?? null);
    $out['birth_lunar'] = !empty($r['birth_lunar']);
    $out['marital_status'] = ai_pick($r['marital_status'] ?? null, ['미혼', '기혼', '이혼', '사별', '기타']);
    $out['faith_kind'] = ai_pick($r['faith_kind'] ?? null, ['학습', '세례', '유아세례', '입교']);
    $out['office'] = ai_pick($r['office'] ?? null, ['목사', '전도사', '사모', '장로', '시무집사', '권사', '서리집사']);
    $fam = [];
    foreach (is_array($r['family'] ?? null) ? array_slice($r['family'], 0, 12) : [] as $m) {
        if (!is_array($m) || ai_str($m['name'] ?? null, 50) === null) continue;
        $by = (int)($m['birth_year'] ?? 0);
        $fam[] = ['name' => ai_str($m['name'], 50), 'relation' => ai_str($m['relation'] ?? null, 20), 'birth_year' => $by >= 1900 && $by <= 2100 ? $by : null];
    }
    $out['family'] = $fam;
    $out['uncertain'] = array_values(array_intersect(array_map('strval', is_array($r['uncertain'] ?? null) ? $r['uncertain'] : []), $keys));
    return $out;
}

// ===================================================== 큰 파일 조각 올리기 =====================================================

function tmp_dir(): string
{
    $d = CM_DATA . '/tmp';
    if (!is_dir($d)) @mkdir($d, 0705, true);
    if (is_dir($d) && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n");
    return $d;
}

function tmp_path(array $u, string $id): string
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) json_fail('invalid', '올리기 번호가 올바르지 않습니다.', 422);
    return tmp_dir() . '/' . (int)$u['id'] . '-' . $id . '.part';
}

function tmp_clean(): void
{
    foreach (glob(tmp_dir() . '/*.part') ?: [] as $f) if (time() - (int)filemtime($f) > 3600) @unlink($f);
}

// ===================================================== 증명서 번호 =====================================================

function cert_format(array $f, int $year, int $n): string
{
    $body = str_replace(['{연도}', '{번호}'], [(string)$year, str_pad((string)$n, max(1, (int)($f['digits'] ?? 3)), '0', STR_PAD_LEFT)], (string)($f['format'] ?? '{연도}-{번호}'));
    return implode(' ', array_filter([(string)($f['prefix'] ?? ''), $body, (string)($f['suffix'] ?? '')], fn($s) => $s !== ''));
}

function cert_form_cfg(): array
{
    return json_decode((string)val('SELECT cert_form FROM ' . t('site_settings') . ' WHERE id = 1'), true) ?: [];
}

/** 올해 다음에 발급할 번호(지금까지 발급한 수 + 1) */
function cert_next_number(int $year): int
{
    return (int)meta_get('cert_seq_' . $year) + 1;
}

// ===================================================== 개인정보 정리 =====================================================

function retention_cfg(): array
{
    $c = json_decode((string)meta_get('retention'), true) ?: [];
    return ['auto' => $c['auto'] ?? true, 'years' => max(1, min(30, (int)($c['years'] ?? 5))), 'logYears' => max(1, min(10, (int)($c['logYears'] ?? 3)))];
}

/** 전출·별세·제적(마지막 재적 상태)이고 그 날짜로부터 $years 년이 지났는데 아직 정리하지 않은 교인(샘플 제외). @return string[] 교인 id */
function retention_due(int $years): array
{
    $cut = date('Y-m-d', strtotime("-{$years} years"));
    $sql = 'SELECT p.id FROM ' . t('people') . ' p JOIN ' . t('status_events') . ' e ON e.id = ('
        . 'SELECT e2.id FROM ' . t('status_events') . ' e2 WHERE e2.person_id = p.id ORDER BY e2.event_date DESC, e2.seq DESC LIMIT 1)'
        . " WHERE p.purged_on IS NULL AND p.is_sample = 0 AND e.kind IN ('전출','별세','제적') AND e.event_date IS NOT NULL AND e.event_date <= ?";
    return array_column(rows($sql, [$cut]), 'id');
}

/** 인적사항(연락처·주소·생일·직업·메모·사진·수정 이력)을 지운다. 이름·교번·재적 이력·세례·직분 같은 교회 기록은 보관한다. @return int 정리한 교인 수 */
function retention_purge(array $ids): int
{
    if (!$ids) return 0;
    $pdo = db();
    $photos = [];
    $pdo->beginTransaction();
    try {
        foreach ($ids as $id) {
            $photo = val('SELECT photo FROM ' . t('people') . ' WHERE id = ?', [$id]);
            if ($photo) $photos[] = (string)$photo;
            q('UPDATE ' . t('people') . ' SET phone=NULL, email=NULL, birth_date=NULL, birth_lunar=0, address_zip=NULL, address1=NULL, address2=NULL, occupation=NULL, workplace=NULL, car_number=NULL,'
                . ' marital_status=NULL, wedding_date=NULL, previous_church=NULL, guide_person_id=NULL, guide_name=NULL, note=NULL, photo=NULL, purged_on=CURDATE() WHERE id = ?', [$id]);
            foreach (['change_log', 'notes', 'legacy_memos'] as $tbl) q('DELETE FROM ' . t($tbl) . ' WHERE person_id = ?', [$id]);
        }
        q('UPDATE ' . t('meta') . " SET v = v + 1 WHERE k = 'data_version'");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    foreach ($photos as $p) data_delete_file($p);
    return count($ids);
}

/** 오래된 접속·수정 기록 정리 @return int 지운 줄 수 */
function retention_purge_logs(int $years): int
{
    $cut = date('Y-m-d H:i:s', strtotime("-{$years} years"));
    return q('DELETE FROM ' . t('access_log') . ' WHERE at < ?', [$cut])->rowCount() + q('DELETE FROM ' . t('change_log') . ' WHERE at < ?', [$cut])->rowCount();
}

/** 하루에 한 번, 누군가 프로그램을 쓰는 중에 정리한다. (자동 백업과 같은 방식) */
function retention_maybe_run(): void
{
    try {
        $cfg = retention_cfg();
        if (!$cfg['auto'] || meta_get('retention_last') === date('Y-m-d')) return;
        meta_set('retention_last', date('Y-m-d'));
        $ids = retention_due($cfg['years']);
        if ($ids && backup_zlib_ok()) {
            try { backup_create(backup_dir() . '/' . backup_name('pre'), '개인정보 정리 직전 자동 보관'); backup_prune('pre', 3); } catch (Throwable $e) { cm_log('error', '정리 전 백업 실패: ' . $e->getMessage()); }
        }
        $n = retention_purge($ids);
        $logs = retention_purge_logs($cfg['logYears']);
        meta_set('retention_result', json_encode(['at' => date('Y-m-d H:i:s'), 'people' => $n, 'logs' => $logs], JSON_UNESCAPED_UNICODE));
        if ($n || $logs) audit('개인정보 자동 정리', '', ['people' => $n, 'logs' => $logs]);
    } catch (Throwable $e) {
        cm_log('error', 'retention: ' . $e->getMessage());
    }
}
