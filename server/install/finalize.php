<?php
declare(strict_types=1);

// 배포용 zip 은 주소의 앞부분(설치 폴더)을 모르는 채로 만들어져 있어서, 설치할 때 실제 폴더 이름으로 바꿔 넣는다.
// 새 버전 zip 을 덮어쓴 뒤에도 이 화면을 한 번 열면 같은 작업이 다시 된다. 몇 번을 해도 결과가 같다.

const CM_PLACEHOLDER = '/__CM_BASE__';

/** 이 설치 화면이 있는 폴더의 주소. 사이트 맨 위에 설치했다면 빈 문자열. 예: /cm */
function cm_base_path(): string
{
    return rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')), '/');
}

/** 아직 바꾸지 않은 파일이 있는가 (zip 을 새로 풀었거나 처음 설치한 직후) */
function cm_needs_finalize(): bool
{
    $index = CM_ROOT . '/index.html';
    if (!is_file($index)) return false;
    return str_contains((string)file_get_contents($index), CM_PLACEHOLDER) || !is_file(CM_ROOT . '/.htaccess');
}

/** 화면 파일 안의 자리표시자를 실제 폴더 주소로 바꾸고, 주소 처리용 .htaccess 를 만든다. 바꾼 파일 수를 돌려준다. */
function cm_finalize_web(): int
{
    $base = cm_base_path();
    $changed = 0;
    $skip = ['api', 'data', 'migrations', 'install'];
    $ext = ['html', 'js', 'css', 'json', 'svg', 'txt', 'webmanifest', 'map'];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(CM_ROOT, FilesystemIterator::SKIP_DOTS),
        function (SplFileInfo $f) use ($skip) {
            // 맨 위 폴더 바로 아래의 서버용 폴더는 건드리지 않는다.
            if ($f->isDir() && $f->getPath() === CM_ROOT && in_array($f->getFilename(), $skip, true)) return false;
            return true;
        }
    ));
    foreach ($it as $f) {
        if (!$f->isFile() || !in_array(strtolower($f->getExtension()), $ext, true)) continue;
        $s = file_get_contents($f->getPathname());
        if ($s === false || !str_contains($s, CM_PLACEHOLDER)) continue;
        if (file_put_contents($f->getPathname(), str_replace(CM_PLACEHOLDER, $base, $s), LOCK_EX) !== false) $changed++;
    }

    // 주소 처리 규칙. 이미 있으면(직접 고친 경우 포함) 건드리지 않는다.
    $ht = CM_ROOT . '/.htaccess';
    if (!is_file($ht)) {
        $rules = "# ch-manage: 화면(SPA) 주소 처리\n"
            . "RewriteEngine On\n"
            . 'RewriteBase ' . $base . "/\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule ^ index.html [L]\n\n"
            . "<IfModule mod_headers.c>\n"
            . "  <FilesMatch \"\\.html$\">\n    Header set Cache-Control \"no-cache\"\n  </FilesMatch>\n"
            . "</IfModule>\n";
        @file_put_contents($ht, $rules, LOCK_EX);
    }
    return $changed;
}
