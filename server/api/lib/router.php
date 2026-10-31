<?php
declare(strict_types=1);

// 아주 작은 라우터. route('GET', '/users/{id}', fn(int $id) => ...) 처럼 등록하고 dispatch() 가 맞는 것을 실행한다.
//   {id}    → 숫자 (정수로 넘겨준다)
//   {grade} → A, B, C 중 하나 (문자열로 넘겨준다)
//   {uuid}  → UUID 모양 글자 · {name} → logo 또는 seal

/** @var array<int, array{string, string, callable}> */
$GLOBALS['cm_routes'] = [];

function route(string $method, string $pattern, callable $handler): void
{
    $rx = preg_replace(['/\{id\}/', '/\{grade\}/', '/\{uuid\}/', '/\{name\}/'], ['(\d+)', '([ABC])', '([0-9a-fA-F-]{36})', '(logo|seal)'], $pattern);
    $GLOBALS['cm_routes'][] = [$method, '#^' . $rx . '$#', $handler];
}

function req_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function dispatch(string $path): never
{
    $method = req_method();
    $pathMatched = false;
    guard_demo($method, $path);
    foreach ($GLOBALS['cm_routes'] as [$m, $rx, $handler]) {
        if (!preg_match($rx, $path, $match)) continue;
        $pathMatched = true;
        if ($m !== $method) continue;
        array_shift($match);
        $args = array_map(fn($v) => ctype_digit($v) ? (int)$v : $v, $match);
        $handler(...$args);
        json_out(null); // 핸들러가 응답하지 않았다면 빈 성공
    }
    if ($pathMatched) json_fail('method_not_allowed', '허용되지 않는 요청 방식입니다.', 405);
    json_fail('not_found', '찾을 수 없는 주소입니다.', 404);
}

/** 체험 계정(is_demo)은 보기만 할 수 있다. 로그아웃만 예외이고, 회원 목록은 읽는 것도 막는다. */
function guard_demo(string $method, string $path): void
{
    if ($path === '/auth/login' || $path === '/auth/signup') return;
    $u = current_user();
    if (!$u || (int)($u['is_demo'] ?? 0) !== 1) return;
    if ($method !== 'GET' && $method !== 'HEAD' && $path !== '/auth/logout') {
        json_fail('demo_readonly', '체험 계정은 보기만 할 수 있어요. 저장하거나 바꿀 수는 없어요.', 403);
    }
    if (preg_match('#^/users(/|$)#', $path)) {
        json_fail('demo_readonly', '체험 계정에서는 볼 수 없는 화면이에요.', 403);
    }
}
