<?php
declare(strict_types=1);

// 모든 API 요청이 들어오는 곳. api/.htaccess 가 존재하지 않는 주소를 이 파일로 넘긴다.
// 주소 모양: /api/v1/...  (사이트를 서브폴더에 설치하면 앞에 그 폴더가 붙는다. 예: /cm/api/v1/...)

require __DIR__ . '/lib/bootstrap.php';

// 이 파일이 놓인 폴더(예: /cm/api)를 뺀 나머지가 API 경로다.
$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = cm_script_base(1);
$path = substr($uri, strlen($base));
if (!str_starts_with($path, '/v1/')) {
    json_fail('not_found', '찾을 수 없는 주소입니다.', 404);
}
$path = rtrim(substr($path, 3), '/') ?: '/';

if (!cm_config()) {
    if ($path === '/system/status') json_out(['installed' => false, 'version' => CM_VERSION]);
    json_fail('not_installed', '아직 설치되지 않았습니다.', 503);
}

require __DIR__ . '/lib/migrate.php';
foreach (['auth', 'users', 'data', 'dm', 'leader', 'backup', 'services', 'finance'] as $c) {
    require __DIR__ . '/controllers/' . $c . '.php';
}

$schemaNow = current_schema_version(db(), cm_config()['prefix']);
$schemaLatest = latest_schema_version(CM_ROOT . '/migrations');

route('GET', '/system/status', function () use ($schemaNow, $schemaLatest) {
    json_out([
        'installed' => true,
        'version' => CM_VERSION,
        'schema' => $schemaNow,
        'latest_schema' => $schemaLatest,
        'update_pending' => $schemaNow < $schemaLatest,
        'finalize_needed' => cm_htaccess_outdated(), // /install/ 을 한 번 열어야 하는 .htaccess 규칙이 있는가
    ]);
});

// 새 버전 파일을 올렸는데 /install/ 을 아직 열지 않아 데이터베이스가 옛 구조이면, 오류가 나기 전에 안내한다.
if ($schemaNow < $schemaLatest && !in_array($path, ['/system/status', '/auth/me', '/auth/logout'], true)) {
    $base = cm_script_base(2);
    json_fail('update_pending', '새 버전 파일이 올라왔지만 아직 마무리하지 않았습니다. 관리자가 주소창에 ' . $base . '/install/ 을 한 번 열어야 쓸 수 있어요.', 503);
}

dispatch($path);
