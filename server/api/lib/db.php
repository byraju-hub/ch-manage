<?php
declare(strict_types=1);

// 데이터베이스 연결과 질의 도우미. 같은 DB 에 다른 프로그램(예: dep-manage 의 dm_)의 표가 있어도 섞이지 않도록
// 이 프로그램의 모든 표 이름에는 접두어(기본 cm_)가 붙는다.

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = cm_config();
    if (!$c) json_fail('not_installed', '아직 설치되지 않았습니다.', 503);
    $d = $c['db'];
    require_once __DIR__ . '/migrate.php';
    $pdo = new PDO(cm_dsn($d), $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+09:00'");
    return $pdo;
}

/** 접두어가 붙은 표 이름. t('people') → `cm_people` */
function t(string $name): string
{
    return '`' . (cm_config()['prefix'] ?? 'cm_') . $name . '`';
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** @return array<int, array<string, mixed>> */
function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val(string $sql, array $params = []): mixed
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

function insert_id(string $sql, array $params = []): int
{
    q($sql, $params);
    return (int)db()->lastInsertId();
}

/** IN (?, ?, ?) 자리표시자 */
function marks(array $values): string
{
    return implode(',', array_fill(0, max(1, count($values)), '?'));
}
