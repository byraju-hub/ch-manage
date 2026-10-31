<?php
declare(strict_types=1);

// 입력 확인과 자잘한 도우미.

function client_ip(): string
{
    return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** 문자열 입력. 앞뒤 공백을 지우고 길이를 확인한다. */
function in_str(array $b, string $key, int $max, bool $required = false, string $label = ''): string
{
    $v = $b[$key] ?? '';
    if (!is_string($v) && !is_int($v)) $v = '';
    $v = trim((string)$v);
    $label = $label !== '' ? $label : $key;
    if ($required && $v === '') json_fail('invalid', $label . '을(를) 입력해 주세요.', 422);
    if (mb_strlen($v) > $max) json_fail('invalid', $label . '은(는) ' . $max . '자 이하로 입력해 주세요.', 422);
    return $v;
}

/** 정해진 값 중 하나만 허용 */
function in_enum(array $b, string $key, array $allowed, string $label = ''): string
{
    $v = $b[$key] ?? null;
    if (!is_string($v) || !in_array($v, $allowed, true)) json_fail('invalid', ($label !== '' ? $label : $key) . ' 값이 올바르지 않습니다.', 422);
    return $v;
}

function today_str(): string
{
    return date('Y-m-d');
}

/** 길이 n 의 읽기 쉬운 임시 비밀번호 (헷갈리는 글자 제외) */
function temp_password(int $n = 10): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzACDEFGHJKLMNPQRTUVWXY23456789';
    $s = '';
    for ($i = 0; $i < $n; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
    return $s;
}
