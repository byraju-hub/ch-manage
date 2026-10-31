<?php
declare(strict_types=1);

// 모든 API 요청이 들어오는 곳. api/.htaccess 가 존재하지 않는 주소를 이 파일로 넘긴다.
// 주소 모양: /api/v1/...  (사이트를 서브폴더에 설치하면 앞에 그 폴더가 붙는다. 예: /cm/api/v1/...)

require __DIR__ . '/lib/bootstrap.php';

// 이 파일이 놓인 폴더(예: /cm/api)를 뺀 나머지가 API 경로다.
$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
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
foreach (['auth', 'users', 'data'] as $c) {
    require __DIR__ . '/controllers/' . $c . '.php';
}

route('GET', '/system/status', function () {
    json_out([
        'installed' => true,
        'version' => CM_VERSION,
        'schema' => current_schema_version(db(), cm_config()['prefix']),
        'latest_schema' => latest_schema_version(CM_ROOT . '/migrations'),
    ]);
});

dispatch($path);
