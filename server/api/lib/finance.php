<?php
declare(strict_types=1);

// 재정 관리(fin-manage)와의 연결. 재정은 같은 데이터베이스의 다른 폴더(기본 /fm)에 설치되고, 교적을 읽기만 한다.
// 교적은 (1) 재정이 설치되어 있는지만 확인하고 (2) 로그인한 사람에게 30초짜리 1회용 입장권을 발급한다.
// 재정의 표(fm_)는 읽거나 쓰지 않는다. 설치 여부를 알아보는 fm_meta 표 존재 확인만 한다.

const SSO_TTL_SECONDS = 30;
const SSO_PER_MINUTE = 10;

/** 재정 폴더 이름과 표 접두어. 설정이 없으면 fm, fm_ 이다. 이상한 값이면 기본값으로 돌린다. */
function finance_config(): array
{
    $c = json_decode((string)meta_get('finance'), true);
    $c = is_array($c) ? $c : [];
    $path = (string)($c['path'] ?? 'fm');
    $prefix = (string)($c['prefix'] ?? 'fm_');
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,50}$/', $path) || $path === '.' || $path === '..') $path = 'fm';
    if (!preg_match('/^[a-z0-9_]{1,20}$/', $prefix)) $prefix = 'fm_';
    return ['path' => $path, 'prefix' => $prefix];
}

/** 같은 데이터베이스에 재정 관리가 설치되어 있는가 (접두어+meta 표가 있는가) */
function finance_installed(): bool
{
    static $r = null;
    if ($r !== null) return $r;
    try {
        $tbl = finance_config()['prefix'] . 'meta';
        return $r = (bool)val('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1', [$tbl]);
    } catch (Throwable) {
        return $r = false;
    }
}

/** 화면이 버튼을 보일지 정하는 값 */
function finance_features(): array
{
    return ['available' => finance_installed(), 'path' => finance_config()['path']];
}
