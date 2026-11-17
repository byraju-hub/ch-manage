<?php
// 시험용: /cm/api/... 를 api/index.php 로 보낸다 (운영의 .htaccess 역할)
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__ . '/www';
if (preg_match('#^/(cm|fm)/api/(?!index\.php)#', $u, $m)) {
    $_SERVER['SCRIPT_NAME'] = '/' . $m[1] . '/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . $_SERVER['SCRIPT_NAME'];
    chdir(dirname($_SERVER['SCRIPT_FILENAME']));
    require $_SERVER['SCRIPT_FILENAME'];
    return true;
}
return false;
