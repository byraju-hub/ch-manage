<?php
declare(strict_types=1);

// 백업과 되살리기. 모든 표(사용자·심방·접속 기록 포함)와 올린 그림(사진·로고·직인·탭 아이콘)을 파일 하나로 묶는다.
//
// 파일 모양: gzip 으로 줄인 줄 단위 JSON (한 줄에 한 항목). 줄마다 읽으므로 큰 자료도 메모리를 적게 쓴다.
//   {"kind":"header", app, format, version, schema, at, tables:[...]}
//   {"kind":"row", "t":"people", "r":{열이름: 값, ...}}
//   {"kind":"file", "path":"photos/xxx.jpg", "b64":"..."}
//   {"kind":"end", "rows":N, "files":M}      ← 이 줄이 없으면 중간에 끊긴 파일로 보고 되살리지 않는다.
// 비밀번호(해시)가 들어 있으므로 최고관리자만 내려받고 되살릴 수 있다.
// 서버에 보관하는 백업은 data/backups/ 에 둔다. (data 폴더는 웹에서 직접 열리지 않는다.)

const BACKUP_FORMAT = 1;
const BACKUP_SKIP_TABLES = ['login_attempts']; // 로그인 시도 제한 기록은 백업하지 않는다.
const BACKUP_CHUNK = 60_000;                    // 되살릴 파일을 올릴 때 한 번에 보내는 크기(바이트). 호스팅의 128KB 한도 아래로.

function backup_dir(): string
{
    $d = CM_DATA . '/backups';
    if (!is_dir($d)) @mkdir($d, 0705, true);
    if (is_dir($d) && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n");
    return $d;
}

function backup_zlib_ok(): bool
{
    return function_exists('gzopen');
}

/** @return string[] 이 프로그램의 표 이름(접두어 뺀 것) */
function backup_tables(): array
{
    $p = cm_config()['prefix'];
    $out = [];
    foreach (rows('SHOW TABLES LIKE ' . db()->quote(str_replace(['_', '%'], ['\\_', '\\%'], $p) . '%')) as $r) {
        $name = substr((string)array_values($r)[0], strlen($p));
        if (!in_array($name, BACKUP_SKIP_TABLES, true)) $out[] = $name;
    }
    sort($out);
    return $out;
}

/** 올린 그림 파일들. @return string[] data/uploads 기준의 경로 */
function backup_upload_files(): array
{
    $base = data_upload_dir();
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        if (!str_contains($rel, '..')) $out[] = $rel;
    }
    sort($out);
    return $out;
}

function backup_line(mixed $h, array $o): void
{
    $j = json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    gzwrite($h, $j . "\n");
}

/** 백업 파일을 만든다. @return string 만든 파일 경로 */
function backup_create(string $path, string $note = ''): string
{
    if (!backup_zlib_ok()) throw new RuntimeException('이 호스팅에는 압축 기능(zlib)이 없어 백업 파일을 만들 수 없습니다.');
    @set_time_limit(300);
    $tmp = $path . '.part';
    $h = gzopen($tmp, 'wb6');
    if (!$h) throw new RuntimeException('백업 파일을 만들 수 없습니다. data 폴더 권한을 확인해 주세요.');
    try {
        $tables = backup_tables();
        $pdo = db();
        // 한 시점의 자료를 읽도록 읽기 전용 거래로 묶는다.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        backup_line($h, [
            'kind' => 'header', 'app' => 'ch-manage', 'format' => BACKUP_FORMAT, 'version' => CM_VERSION,
            'schema' => current_schema_version($pdo, cm_config()['prefix']),
            'at' => date('Y-m-d H:i:s'), 'tables' => $tables, 'note' => $note,
        ]);
        $nRows = 0;
        foreach ($tables as $name) {
            $st = $pdo->query('SELECT * FROM ' . t($name));
            while (($r = $st->fetch()) !== false) {
                if ($name === 'meta' && str_starts_with((string)$r['k'], 'int_')) continue; // 외부 서비스 키는 백업에 넣지 않는다
                backup_line($h, ['kind' => 'row', 't' => $name, 'r' => $r]);
                $nRows++;
            }
        }
        $pdo->commit();
        $nFiles = 0;
        $base = data_upload_dir();
        foreach (backup_upload_files() as $rel) {
            $bin = @file_get_contents($base . '/' . $rel);
            if ($bin === false) continue;
            backup_line($h, ['kind' => 'file', 'path' => $rel, 'b64' => base64_encode($bin)]);
            $nFiles++;
        }
        backup_line($h, ['kind' => 'end', 'rows' => $nRows, 'files' => $nFiles]);
        gzclose($h);
    } catch (Throwable $e) {
        @gzclose($h);
        if (db()->inTransaction()) db()->rollBack();
        @unlink($tmp);
        throw $e;
    }
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('백업 파일을 저장하지 못했습니다.'); }
    return $path;
}

/** 서버에 보관한 백업 파일 이름 규칙. 이름에 종류가 들어간다: auto(자동), manual(직접), pre(되살리기 직전) */
function backup_name(string $kind): string
{
    return 'cm-' . $kind . '-' . date('Ymd-His') . '.cmbak';
}

function backup_valid_name(string $n): bool
{
    return (bool)preg_match('/^cm-(auto|manual|pre)-\d{8}-\d{6}\.cmbak$/', $n);
}

/** @return array<int, array{name:string,kind:string,size:int,at:string}> 최신순 */
function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/cm-*.cmbak') ?: [] as $f) {
        $n = basename($f);
        if (!backup_valid_name($n)) continue;
        preg_match('/^cm-(auto|manual|pre)-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/', $n, $m);
        $out[] = ['name' => $n, 'kind' => $m[1], 'size' => (int)filesize($f), 'at' => "{$m[2]}-{$m[3]}-{$m[4]} {$m[5]}:{$m[6]}"];
    }
    usort($out, fn($a, $b) => strcmp($b['at'] . $b['name'], $a['at'] . $a['name']));
    return $out;
}

/** 종류별로 최근 $keep 개만 남기고 지운다. */
function backup_prune(string $kind, int $keep): void
{
    $same = array_values(array_filter(backup_list(), fn($b) => $b['kind'] === $kind));
    foreach (array_slice($same, max(1, $keep)) as $b) @unlink(backup_dir() . '/' . $b['name']);
}

function meta_get(string $k): ?string
{
    $v = val('SELECT v FROM ' . t('meta') . ' WHERE k = ?', [$k]);
    return $v === null ? null : (string)$v;
}

function meta_set(string $k, string $v): void
{
    q('INSERT INTO ' . t('meta') . ' (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}

/** 자동 백업 설정(화면에서 저장한 값). */
function backup_cfg(): array
{
    $d = json_decode((string)val('SELECT backup FROM ' . t('site_settings') . ' WHERE id = 1'), true) ?: [];
    return $d + ['auto' => false, 'cycle' => '매일', 'time' => '03:00', 'keep' => 30, 'notify' => ''];
}

/** 이번 주기의 백업 시각. 마지막 자동 백업이 이 시각보다 이전이면 백업할 때가 된 것이다. */
function backup_due_since(array $cfg, ?DateTimeImmutable $now = null): DateTimeImmutable
{
    $now ??= new DateTimeImmutable('now');
    [$hh, $mm] = array_map('intval', explode(':', preg_match('/^\d{1,2}:\d{2}$/', (string)$cfg['time']) ? $cfg['time'] : '03:00'));
    $t = $now->setTime($hh, $mm, 0);
    if ($cfg['cycle'] === '매주') { // 지난 일요일 그 시각
        $t = $t->modify('-' . (int)$now->format('w') . ' days');
    } elseif ($cfg['cycle'] === '매월') {
        $t = $t->setDate((int)$now->format('Y'), (int)$now->format('n'), 1);
    }
    return $t > $now ? ($cfg['cycle'] === '매일' ? $t->modify('-1 day') : ($cfg['cycle'] === '매주' ? $t->modify('-7 days') : $t->modify('-1 month'))) : $t;
}

/**
 * 자동 백업. 호스팅에 예약 작업(cron)이 없어도 되도록, 누군가 프로그램을 쓰는 중에 때가 된 것을 알아채 백업한다.
 * 동시에 여러 번 만들지 않도록 잠금 파일을 쓴다. 실패하면 app.log 에 남기고, 알림 이메일이 있으면 메일을 보낸다.
 */
function backup_maybe_run(): void
{
    try {
        $cfg = backup_cfg();
        if (empty($cfg['auto']) || !backup_zlib_ok()) return;
        $last = meta_get('backup_last_auto');
        $since = backup_due_since($cfg);
        if ($last !== null && strtotime($last) >= $since->getTimestamp()) return;
        // 실패하면 1시간 뒤에 다시 시도
        $failed = meta_get('backup_failed_at');
        if ($failed !== null && time() - strtotime($failed) < 3600) return;
        $lock = fopen(backup_dir() . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
        try {
            $last = meta_get('backup_last_auto'); // 잠금을 기다리는 사이 다른 요청이 했을 수 있다
            if ($last !== null && strtotime($last) >= $since->getTimestamp()) return;
            try {
                backup_create(backup_dir() . '/' . backup_name('auto'), '자동 백업');
                meta_set('backup_last_auto', date('Y-m-d H:i:s'));
                q('DELETE FROM ' . t('meta') . " WHERE k = 'backup_failed_at'");
                backup_prune('auto', (int)$cfg['keep']);
            } catch (Throwable $e) {
                meta_set('backup_failed_at', date('Y-m-d H:i:s'));
                cm_log('error', '자동 백업 실패: ' . $e->getMessage());
                $to = trim((string)($cfg['notify'] ?? ''));
                if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && function_exists('mail')) {
                    @mail($to, '[교적 프로그램] 자동 백업에 실패했습니다', "자동 백업을 만들지 못했습니다.\n이유: " . $e->getMessage() . "\n\n관리자 > 프로그램 설정 > 백업에서 확인해 주세요.", "Content-Type: text/plain; charset=UTF-8\r\n");
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } catch (Throwable $e) {
        cm_log('error', 'backup_maybe_run: ' . $e->getMessage());
    }
}

/** 파일을 처음부터 끝까지 읽어 보고 올바른 백업인지 확인한다. @return array 헤더 정보 */
function backup_inspect(string $path): array
{
    if (!backup_zlib_ok()) throw new RuntimeException('이 호스팅에는 압축 기능(zlib)이 없습니다.');
    $h = @gzopen($path, 'rb');
    if (!$h) throw new RuntimeException('백업 파일을 열 수 없습니다.');
    $header = null; $rows = 0; $files = 0; $end = null;
    while (($line = gzgets($h)) !== false) {
        $o = json_decode($line, true);
        if (!is_array($o)) { gzclose($h); throw new RuntimeException('백업 파일이 손상되었습니다.'); }
        if ($o['kind'] === 'header') $header = $o;
        elseif ($header === null) { gzclose($h); throw new RuntimeException('교적 백업 파일이 아닙니다.'); }
        elseif ($o['kind'] === 'row') $rows++;
        elseif ($o['kind'] === 'file') $files++;
        elseif ($o['kind'] === 'end') $end = $o;
    }
    gzclose($h);
    if (!$header || ($header['app'] ?? '') !== 'ch-manage') throw new RuntimeException('교적 백업 파일이 아닙니다.');
    if ((int)($header['format'] ?? 0) !== BACKUP_FORMAT) throw new RuntimeException('이 프로그램이 읽을 수 없는 백업 파일 형식입니다.');
    if (!$end || (int)$end['rows'] !== $rows || (int)$end['files'] !== $files) throw new RuntimeException('백업 파일이 중간에 끊겼거나 손상되었습니다.');
    $latest = latest_schema_version(CM_ROOT . '/migrations');
    if ((int)$header['schema'] > $latest) throw new RuntimeException('더 새로운 버전의 프로그램에서 만든 백업입니다. 프로그램을 먼저 새 버전으로 바꿔 주세요.');
    $header['rows'] = $rows; $header['files'] = $files;
    return $header;
}

/** @return array<string, true> 이 표의 열 이름 */
function backup_columns(string $name): array
{
    $cols = [];
    foreach (rows('SHOW COLUMNS FROM ' . t($name)) as $c) $cols[$c['Field']] = true;
    return $cols;
}

/**
 * 백업 파일로 지금 자료를 모두 바꾼다. 먼저 '되살리기 직전' 백업을 서버에 남긴다.
 * 표는 한 번의 거래로 바꿔서 중간에 실패하면 원래대로 돌아가고, 그림 파일은 새 폴더에 풀어 둔 뒤 마지막에 바꾼다.
 * @return array{rows:int, files:int, at:string}
 */
function backup_restore(string $path): array
{
    $info = backup_inspect($path);
    @set_time_limit(600);
    $lock = fopen(backup_dir() . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('다른 백업 작업이 끝나기를 기다리다 실패했습니다. 잠시 뒤 다시 시도해 주세요.');
    $pdo = db();
    $uploads = data_upload_dir();
    $new = $uploads . '.restore-' . bin2hex(random_bytes(3));
    $old = $uploads . '.old-' . bin2hex(random_bytes(3));
    try {
        backup_create(backup_dir() . '/' . backup_name('pre'), '되살리기 직전 자동 보관');
        backup_prune('pre', 3);

        @mkdir($new, 0705, true);
        $h = gzopen($path, 'rb');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->beginTransaction();
        $mine = backup_tables();
        $inBackup = array_flip($info['tables']);
        // 지금 있는 표는 모두 비운다 (백업에 없는 표는 비어 있는 상태가 된다).
        foreach ($mine as $name) if ($name !== 'meta') $pdo->exec('DELETE FROM ' . t($name));
        $cols = []; $ins = [];
        $nRows = 0; $nFiles = 0; $backupDataVersion = 0;
        while (($line = gzgets($h)) !== false) {
            $o = json_decode($line, true);
            if (!is_array($o)) continue;
            if ($o['kind'] === 'row') {
                $name = (string)$o['t'];
                if (!in_array($name, $mine, true) || !isset($inBackup[$name])) continue; // 지금 프로그램에 없는 표는 건너뜀
                if ($name === 'meta') {
                    if (in_array($o['r']['k'] ?? '', ['schema_version', 'data_version'], true)) {
                        if (($o['r']['k'] ?? '') === 'data_version') $backupDataVersion = (int)$o['r']['v'];
                        continue; // 구조 버전은 지금 것을 유지
                    }
                }
                $cols[$name] ??= backup_columns($name);
                $r = array_intersect_key($o['r'], $cols[$name]); // 지금 표에 없는 열(옛 백업의 지워진 열)은 버린다
                if (!$r) continue;
                $key = $name . '|' . implode(',', array_keys($r));
                if (!isset($ins[$key])) {
                    $ks = array_keys($r);
                    $ins[$key] = $pdo->prepare('REPLACE INTO ' . t($name) . ' (' . implode(',', array_map(fn($k) => '`' . $k . '`', $ks)) . ') VALUES (' . implode(',', array_fill(0, count($ks), '?')) . ')');
                }
                $ins[$key]->execute(array_values($r));
                $nRows++;
            } elseif ($o['kind'] === 'file') {
                $rel = (string)$o['path'];
                if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || !preg_match('#^[\w./-]+$#', $rel)) continue;
                $bin = base64_decode((string)$o['b64'], true);
                if ($bin === false) throw new RuntimeException('백업 파일 안의 그림이 손상되었습니다.');
                @mkdir(dirname($new . '/' . $rel), 0705, true);
                if (file_put_contents($new . '/' . $rel, $bin) === false) throw new RuntimeException('그림을 저장하지 못했습니다. data 폴더 권한과 남은 용량을 확인해 주세요.');
                $nFiles++;
            }
        }
        gzclose($h);
        // 화면이 다시 받도록 자료 버전을 올린다.
        $cur = (int)meta_get('data_version');
        meta_set('data_version', (string)(max($cur, $backupDataVersion) + 1));
        $pdo->commit();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        // 그림 폴더 바꾸기
        if (is_dir($uploads) && !@rename($uploads, $old)) throw new RuntimeException('그림 폴더를 바꾸지 못했습니다. (자료는 되살려졌습니다.)');
        if (!@rename($new, $uploads)) {
            @rename($old, $uploads);
            throw new RuntimeException('그림 폴더를 바꾸지 못했습니다. (자료는 되살려졌습니다.)');
        }
        backup_rmdir($old);
        return ['rows' => $nRows, 'files' => $nFiles, 'at' => (string)$info['at']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable) {}
        backup_rmdir($new);
        throw $e;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function backup_rmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}

/** 되살릴 파일을 조각으로 받아 두는 곳 */
function backup_upload_path(string $id): string
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) json_fail('invalid', '올리기 번호가 올바르지 않습니다.', 422);
    $d = backup_dir() . '/uploading';
    if (!is_dir($d)) @mkdir($d, 0705, true);
    return $d . '/' . $id . '.part';
}

/** 오래된 올리기 조각 정리 (1시간 지난 것) */
function backup_clean_uploads(): void
{
    foreach (glob(backup_dir() . '/uploading/*.part') ?: [] as $f) if (time() - (int)filemtime($f) > 3600) @unlink($f);
}
