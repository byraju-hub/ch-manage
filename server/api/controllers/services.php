<?php
declare(strict_types=1);

// 외부 서비스 설정(최고관리자), 문자 발송, 등록카드 읽기·심방 분석(AI), 큰 파일 조각 올리기,
// 증명서 발급 번호, 개인정보 정리.

// ===================================================== 외부 서비스 설정 =====================================================

function integ_payload(): array
{
    $s = integ_get('sms');
    $a = integ_get('ai');
    return [
        'sms' => ['configured' => integ_features()['sms'], 'sender' => (string)($s['sender'] ?? ''), 'keyTail' => integ_mask($s['key'] ?? ''), 'secretSet' => !empty($s['secret'])],
        'ai' => ['configured' => integ_features()['ai'], 'model' => (string)($a['model'] ?? ''), 'defaultModel' => AI_DEFAULT_MODEL, 'keyTail' => integ_mask($a['key'] ?? '')],
    ];
}

route('GET', '/integrations', function () {
    require_super();
    json_out(integ_payload());
});

/** 문자(솔라피) 키·발신번호 저장. 비워 둔 칸은 지금 값을 그대로 둔다. 저장하기 전에 잔액 조회로 키가 맞는지 확인한다. */
route('POST', '/integrations/sms', function () {
    require_super();
    $b = body();
    $cur = integ_get('sms');
    $key = in_str($b, 'key', 100) ?: (string)($cur['key'] ?? '');
    $secret = in_str($b, 'secret', 100) ?: (string)($cur['secret'] ?? '');
    $sender = preg_replace('/\D/', '', in_str($b, 'sender', 20));
    if ($key === '' || $secret === '') json_fail('invalid', '솔라피 API Key 와 API Secret 을 모두 넣어 주세요.', 422);
    if (!preg_match('/^(0\d{8,10}|1\d{7})$/', $sender)) json_fail('invalid', '발신번호는 숫자만 9~11자리(또는 8자리 대표번호)로 넣어 주세요. 솔라피에 미리 등록한 번호여야 합니다.', 422);
    try {
        $bal = sms_balance(['key' => $key, 'secret' => $secret]);
    } catch (Throwable $e) {
        json_fail('integration_failed', $e->getMessage(), 422);
    }
    integ_set('sms', ['key' => $key, 'secret' => $secret, 'sender' => $sender]);
    audit('문자 서비스 설정');
    json_out(integ_payload() + ['balance' => $bal]);
});

route('POST', '/integrations/ai', function () {
    require_super();
    $b = body();
    $cur = integ_get('ai');
    $key = in_str($b, 'key', 200) ?: (string)($cur['key'] ?? '');
    $model = in_str($b, 'model', 60);
    if ($key === '') json_fail('invalid', 'API 키를 넣어 주세요.', 422);
    if ($model !== '' && !preg_match('/^[\w.\-]{3,60}$/', $model)) json_fail('invalid', '모델 이름이 올바르지 않습니다.', 422);
    integ_set('ai', ['key' => $key, 'model' => $model]);
    try {
        ai_json([['text' => '다음 JSON 만 답하세요: {"ok": true}']]);
    } catch (Throwable $e) {
        integ_set('ai', $cur); // 안 되는 키는 저장하지 않는다
        json_fail('integration_failed', $e->getMessage(), 422);
    }
    audit('AI 서비스 설정');
    json_out(integ_payload());
});

route('POST', '/integrations/delete', function () {
    require_super();
    $kind = in_enum(body(), 'kind', ['sms', 'ai'], '종류');
    q('DELETE FROM ' . t('meta') . ' WHERE k = ?', ['int_' . $kind]);
    audit($kind === 'sms' ? '문자 서비스 설정 삭제' : 'AI 서비스 설정 삭제');
    json_out(integ_payload());
});

/** 문자 서비스 잔액 */
route('GET', '/sms/balance', function () {
    require_super();
    $c = integ_get('sms');
    if (!integ_features()['sms']) json_fail('not_configured', '문자 서비스가 설정되지 않았습니다.', 409);
    try { json_out(sms_balance($c)); } catch (Throwable $e) { json_fail('integration_failed', $e->getMessage(), 502); }
});

// ===================================================== 문자 발송 =====================================================

/** 한 번에 최대 300건. 화면이 많은 사람에게 보낼 때는 나누어 여러 번 부른다. */
route('POST', '/sms/send', function () {
    $u = require_perm('visit'); // 화면과 같이 A 등급(전임 교역자)만
    if (!integ_features()['sms']) json_fail('not_configured', '문자 서비스가 아직 설정되지 않았습니다. (관리자 › 프로그램 설정 › 외부 서비스)', 409);
    $b = body();
    $list = $b['messages'] ?? null;
    if (!is_array($list) || !$list || count($list) > 300) json_fail('invalid', '보낼 문자는 한 번에 1~300건까지입니다.', 422);
    $subject = in_str($b, 'subject', 40);
    $msgs = []; $pids = []; $seen = [];
    foreach ($list as $m) {
        $to = preg_replace('/\D/', '', (string)($m['to'] ?? ''));
        $text = trim((string)($m['text'] ?? ''));
        if (!preg_match('/^01[016789]\d{7,8}$/', $to)) json_fail('invalid', '휴대폰 번호가 올바르지 않은 받는 사람이 있습니다.', 422);
        if ($text === '' || strlen($text) > 6000 || strlen(mb_convert_encoding($text, 'EUC-KR', 'UTF-8')) > 2000) json_fail('invalid', '문자 내용이 비었거나 너무 깁니다. (한글 1,000자 이내)', 422);
        if (isset($seen[$to])) continue; // 같은 번호는 한 번만
        $seen[$to] = true;
        $msgs[] = ['to' => $to, 'text' => $text];
        if (is_string($m['pid'] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $m['pid'])) $pids[] = strtolower($m['pid']);
    }
    // 수신 거부한 사람의 번호는 서버에서도 막는다. (화면이 빠뜨려도 보내지 않는다.)
    $nums = array_keys($seen);
    $optout = array_column(rows('SELECT REPLACE(REPLACE(phone, "-", ""), " ", "") AS n FROM ' . t('people') . ' WHERE sms_opt_out = 1 AND REPLACE(REPLACE(phone, "-", ""), " ", "") IN (' . marks($nums) . ')', $nums), 'n');
    if ($optout) {
        $msgs = array_values(array_filter($msgs, fn($m) => !in_array($m['to'], $optout, true)));
        if (!$msgs) json_fail('invalid', '받는 사람이 모두 문자 수신을 거부했습니다.', 422);
    }
    $today = (int)val('SELECT COALESCE(SUM(sent_count), 0) FROM ' . t('sms_log') . ' WHERE sent_at >= CURDATE()');
    if ($today + count($msgs) > SMS_DAILY_LIMIT) json_fail('limit', '하루에 보낼 수 있는 문자 수(' . number_format(SMS_DAILY_LIMIT) . '건)를 넘습니다. 내일 다시 보내 주세요.', 429);
    try {
        $res = sms_send(integ_get('sms'), $msgs, $subject);
    } catch (Throwable $e) {
        cm_log('error', 'sms: ' . $e->getMessage());
        json_fail('sms_failed', $e->getMessage(), 502);
    }
    $isLms = fn($t) => strlen(mb_convert_encoding($t, 'EUC-KR', 'UTF-8')) > 90;
    q(
        'INSERT INTO ' . t('sms_log') . ' (sent_by, kind, subject, body, recipient_ids, sent_count, failed_count, provider_group_id) VALUES (?,?,?,?,?,?,?,?)',
        [$u['id'], $isLms($msgs[0]['text']) ? 'LMS' : 'SMS', mb_substr($subject, 0, 100), mb_substr($msgs[0]['text'], 0, 2000), json_encode($pids), $res['sent'], $res['failed'], mb_substr($res['group'], 0, 80)]
    );
    audit('문자 발송', '', ['sent' => $res['sent'], 'failed' => $res['failed'], 'skipped' => count($list) - count($msgs)]);
    json_out(['sent' => $res['sent'], 'failed' => $res['failed'], 'skipped' => count($list) - count($msgs)]);
});

// ===================================================== 큰 파일 조각 올리기 =====================================================

/** 사진·녹음처럼 큰 파일을 60KB 조각으로 받아 둔다. (호스팅이 한 번에 받는 크기가 128KB 로 제한되어 있다.) */
route('POST', '/uploads/chunk', function () {
    $u = require_perm('edit');
    $b = body();
    $seq = (int)($b['seq'] ?? -1);
    $data = base64_decode((string)($b['data'] ?? ''), true);
    if ($data === false || $seq < 0 || strlen($data) > TMP_CHUNK) json_fail('invalid', '올리는 파일 조각이 올바르지 않습니다.', 422);
    $path = tmp_path($u, (string)($b['id'] ?? ''));
    tmp_clean();
    if ($seq === 0) @unlink($path);
    if ($seq > 0 && (!is_file($path) || (int)filesize($path) !== $seq * TMP_CHUNK)) json_fail('invalid', '올리는 순서가 맞지 않습니다. 처음부터 다시 올려 주세요.', 409);
    if (file_put_contents($path, $data, FILE_APPEND | LOCK_EX) === false) json_fail('server_error', '파일을 저장하지 못했습니다.', 500);
    if ((int)filesize($path) > AI_MAX_BYTES) { @unlink($path); json_fail('invalid', '파일이 너무 큽니다. (14MB 이하)', 413); }
    json_out(['size' => (int)filesize($path)]);
});

/** 조각으로 올린 파일을 꺼낸다. 쓰고 나면 바로 지운다(파일 자체는 서버에 남기지 않는다). */
function tmp_take(array $u, string $id): string
{
    $path = tmp_path($u, $id);
    if (!is_file($path)) json_fail('not_found', '올린 파일을 찾을 수 없습니다. 다시 올려 주세요.', 404);
    $bin = (string)file_get_contents($path);
    @unlink($path);
    return $bin;
}

/** 파일 앞부분으로 종류를 알아낸다. (호스팅에 fileinfo 확장이 없어도 되도록 직접 확인) 지원하지 않는 파일이면 null */
function sniff_media(string $bin): ?string
{
    $h = substr($bin, 0, 16);
    if (str_starts_with($h, '%PDF')) return 'application/pdf';
    if (str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WAVE') return 'audio/wav';
    if (str_starts_with($h, 'ID3') || (strlen($h) > 1 && ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0)) return 'audio/mp3';
    if (substr($h, 4, 4) === 'ftyp') return 'audio/mp4';
    if (str_starts_with($h, 'OggS')) return 'audio/ogg';
    if (str_starts_with($h, "Eß£")) return 'audio/webm';
    if (str_starts_with($h, 'fLaC')) return 'audio/flac';
    if ($bin !== '' && !str_contains(substr($bin, 0, 4096), " ") && mb_check_encoding($bin, 'UTF-8')) return 'text/plain';
    return null;
}

function require_ai(): void
{
    if (!integ_features()['ai']) json_fail('not_configured', 'AI 서비스가 아직 설정되지 않았습니다. (관리자 › 프로그램 설정 › 외부 서비스)', 409);
}

// ===================================================== 등록카드 읽기 =====================================================

route('POST', '/card-scan', function () {
    $u = require_perm('edit');
    require_ai();
    $bin = tmp_take($u, (string)(body()['id'] ?? ''));
    $info = @getimagesizefromstring($bin);
    $mime = ['image/jpeg' => 'image/jpeg', 'image/png' => 'image/png', 'image/webp' => 'image/webp'][$info['mime'] ?? ''] ?? null;
    if (!$mime) json_fail('invalid', '그림 파일(JPG·PNG)만 읽을 수 있습니다.', 422);
    $prompt = "이것은 한국 교회의 '교인 등록카드' 사진입니다. 적힌 내용을 읽어 다음 JSON 한 개로만 답하세요. 적혀 있지 않거나 읽을 수 없는 칸은 null 로 두고, 짐작해서 채우지 마세요.\n"
        . '{"name":"이름","gender":"M 또는 F","birth_date":"YYYY-MM-DD","birth_lunar":음력이면 true,"phone":"휴대폰","email":"","address_zip":"우편번호","address1":"주소","address2":"상세주소","occupation":"직업/직장","marital_status":"미혼|기혼|이혼|사별|기타 중 하나","wedding_date":"YYYY-MM-DD","previous_church":"이전 출석 교회","guide_name":"인도자 이름","faith_kind":"학습|세례|유아세례|입교 중 하나","faith_date":"YYYY-MM-DD","faith_church":"신급을 받은 교회","office":"목사|전도사|사모|장로|시무집사|권사|서리집사 중 하나","reg_date":"교회 등록일 YYYY-MM-DD","family":[{"name":"","relation":"관계","birth_year":출생연도}],"memo":"위 칸에 없는 중요한 내용","uncertain":["글씨가 불분명해 확신이 없는 칸의 이름들"]}';
    try {
        $r = ai_json([['text' => $prompt], ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bin)]]]);
    } catch (Throwable $e) {
        cm_log('error', 'card-scan: ' . $e->getMessage());
        json_fail('ai_failed', $e->getMessage(), 502);
    }
    audit('등록카드 읽기');
    json_out(['result' => ai_clean_card($r)]);
});

// ===================================================== 심방 녹음·문서 분석 =====================================================

route('POST', '/visit-analyze', function () {
    $u = require_perm('visit');
    if (!perms_of($u)['edit']) json_fail('forbidden', '이 작업을 할 권한이 없습니다.', 403);
    require_ai();
    $b = body();
    $bin = tmp_take($u, (string)($b['id'] ?? ''));
    if ($bin === '') json_fail('invalid', '빈 파일입니다.', 422);
    $ext = strtolower(pathinfo((string)($b['name'] ?? ''), PATHINFO_EXTENSION));
    $mime = sniff_media($bin);
    if ($mime === 'text/plain') {
        $media = ['text' => "[문서 내용]
" . mb_substr($bin, 0, 60000)];
    } elseif ($mime !== null) {
        $media = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bin)]];
    } else {
        json_fail('invalid', '녹음(m4a·mp3·wav)·PDF·텍스트 파일만 분석할 수 있습니다.', 422);
    }
    $kinds = (json_decode((string)val('SELECT codes FROM ' . t('site_settings') . ' WHERE id = 1'), true)['visitKinds'] ?? null) ?: ['일반'];
    $prompt = "이것은 한국 교회 목회자의 심방(가정 방문 예배·상담)을 담은 녹음 또는 문서입니다. 내용을 분석해 다음 JSON 한 개로만 답하세요. 없는 내용은 null 이나 빈 배열로 두고 지어내지 마세요.\n"
        . '{"transcript":"녹음이면 받아쓴 전체 내용, 문서면 요약 없이 핵심 문장","kind":"' . implode('|', $kinds) . ' 중 가장 알맞은 하나","content":"심방 내용 요약(날짜가 나오면 \'22일 입원\'처럼 날짜와 함께, 3~8문장)","scripture":"읽은 말씀 예: 시편 23편","hymn":"부른 찬송 예: 찬 305장","follow_up":"앞으로 할 일(후속 조치)","companions":["동행한 사람 이름들"]}';
    try {
        $r = ai_json([['text' => $prompt], $media]);
    } catch (Throwable $e) {
        cm_log('error', 'visit-analyze: ' . $e->getMessage());
        json_fail('ai_failed', $e->getMessage(), 502);
    }
    $kind = ai_str($r['kind'] ?? null, 30);
    audit('심방 분석');
    json_out([
        'transcript' => ai_str($r['transcript'] ?? null, 30000),
        'result' => [
            'kind' => $kind !== null && in_array($kind, $kinds, true) ? $kind : null,
            'content' => ai_str($r['content'] ?? null, 4000), 'scripture' => ai_str($r['scripture'] ?? null, 100),
            'hymn' => ai_str($r['hymn'] ?? null, 100), 'follow_up' => ai_str($r['follow_up'] ?? null, 500),
            'companions' => array_values(array_filter(array_map(fn($n) => ai_str($n, 50), is_array($r['companions'] ?? null) ? array_slice($r['companions'], 0, 10) : []))),
        ],
    ]);
});

// ===================================================== 증명서 발급 번호 =====================================================

route('GET', '/certificates/next', function () {
    require_perm('export');
    $y = (int)date('Y');
    json_out(['docNo' => cert_format(cert_form_cfg(), $y, cert_next_number($y))]);
});

/** 증명서를 발급(출력)할 때 번호를 서버가 정하고 발급 대장에 남긴다. 번호는 올해 안에서 겹치지 않는다. */
route('POST', '/certificates/issue', function () {
    $u = require_perm('export');
    $b = body();
    $kind = in_str($b, 'kind', 30, true, '증명서 종류');
    $purpose = in_str($b, 'purpose', 100);
    $pid = $b['person_id'] ?? null;
    if ($pid !== null && (!is_string($pid) || !preg_match('/^[0-9a-f-]{36}$/i', $pid))) json_fail('invalid', '교인 정보가 올바르지 않습니다.', 422);
    $pid = $pid === null ? null : strtolower($pid);
    $name = '';
    if ($pid !== null) {
        $name = (string)val('SELECT name FROM ' . t('people') . ' WHERE id = ?', [$pid]);
        if ($name === '') json_fail('not_found', '교인을 찾을 수 없습니다.', 404);
    }
    $year = (int)date('Y');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cur = val('SELECT v FROM ' . t('meta') . ' WHERE k = ? FOR UPDATE', ['cert_seq_' . $year]);
        $n = $cur === null ? 1 : (int)$cur + 1;
        meta_set('cert_seq_' . $year, (string)$n);
        $no = cert_format(cert_form_cfg(), $year, $n);
        q('INSERT INTO ' . t('certificates') . ' (doc_no, kind, person_id, purpose, issued_by) VALUES (?,?,?,?,?)', [$no, $kind, $pid, $purpose, $u['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit('출력', trim($kind . ($name !== '' ? ' · ' . $name : '') . ' · ' . $no . ($purpose !== '' ? ' · ' . $purpose : '')));
    json_out(['docNo' => $no]);
});

// ===================================================== 개인정보 정리 =====================================================

function retention_payload(): array
{
    $c = retention_cfg();
    return [
        'cfg' => $c, 'due' => count(retention_due($c['years'])),
        'last' => meta_get('retention_last'), 'result' => json_decode((string)meta_get('retention_result'), true),
        'oldLogs' => (int)val('SELECT COUNT(*) FROM ' . t('access_log') . ' WHERE at < ?', [date('Y-m-d H:i:s', strtotime("-{$c['logYears']} years"))]),
    ];
}

route('GET', '/retention/status', function () {
    require_super();
    json_out(retention_payload());
});

route('POST', '/retention/config', function () {
    require_super();
    $b = body();
    meta_set('retention', json_encode(['auto' => !empty($b['auto']), 'years' => max(1, min(30, (int)($b['years'] ?? 5))), 'logYears' => max(1, min(10, (int)($b['logYears'] ?? 3)))]));
    audit('개인정보 정리 설정');
    json_out(retention_payload());
});

/** 지금 바로 정리. 정리 직전 자료를 서버에 한 번 보관한다. */
route('POST', '/retention/run', function () {
    require_super();
    $c = retention_cfg();
    $ids = retention_due($c['years']);
    if ($ids && backup_zlib_ok()) {
        try { backup_create(backup_dir() . '/' . backup_name('pre'), '개인정보 정리 직전 보관'); backup_prune('pre', 3); } catch (Throwable $e) { json_fail('backup_failed', '정리 전에 백업을 만들지 못해 중단했습니다. ' . $e->getMessage(), 500); }
    }
    $n = retention_purge($ids);
    $logs = retention_purge_logs($c['logYears']);
    meta_set('retention_result', json_encode(['at' => date('Y-m-d H:i:s'), 'people' => $n, 'logs' => $logs], JSON_UNESCAPED_UNICODE));
    audit('개인정보 정리', '', ['people' => $n, 'logs' => $logs]);
    json_out(retention_payload());
});
