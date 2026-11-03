<?php
declare(strict_types=1);

// 배포용 zip 은 주소의 앞부분(설치 폴더)을 모르는 채로 만들어져 있어서, 설치할 때 실제 폴더 이름으로 바꿔 넣는다.
// 새 버전 zip 을 덮어쓴 뒤에도 이 화면을 한 번 열면 같은 작업이 다시 된다. 몇 번을 해도 결과가 같다.

const CM_PLACEHOLDER = '/__CM_BASE__';

/** 주소가 http:// 로 들어오면 https:// 로 보내는 규칙. 비밀번호와 교적이 암호화 없이 오가지 않게 한다.
 *  내 컴퓨터(localhost)에서 시험할 때는 건드리지 않고, 호스팅 앞단이 https 를 처리하는 경우(X-Forwarded-Proto)도 인정한다. */
const CM_HTTPS_RULES = "# ch-manage-https: http 로 들어오면 https 로\n"
    . "RewriteCond %{HTTPS} !=on\n"
    . "RewriteCond %{HTTP:X-Forwarded-Proto} !=https\n"
    . "RewriteCond %{HTTP_HOST} !^(localhost|127\\.0\\.0\\.1)(:\\d+)?$ [NC]\n"
    . "RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n";

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
    $ht = CM_ROOT . '/.htaccess';
    return str_contains((string)file_get_contents($index), CM_PLACEHOLDER) || !is_file($ht) || !str_contains((string)file_get_contents($ht), 'ch-manage-https');
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
            . CM_HTTPS_RULES
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule ^ index.html [L]\n\n"
            . "<IfModule mod_headers.c>\n"
            . "  <FilesMatch \"\\.html$\">\n    Header set Cache-Control \"no-cache\"\n  </FilesMatch>\n"
            . "</IfModule>\n";
        @file_put_contents($ht, $rules, LOCK_EX);
    } elseif (!str_contains((string)file_get_contents($ht), 'ch-manage-https')) {
        // 예전 버전이 만든 .htaccess 에는 https 규칙이 없으므로, 한 번만 끼워 넣는다. (직접 고친 내용은 그대로 둔다.)
        $cur = (string)file_get_contents($ht);
        $new = preg_replace('/^RewriteEngine On\R/mi', '$0' . addcslashes(CM_HTTPS_RULES, '\\$'), $cur, 1, $n);
        if ($n === 1) @file_put_contents($ht, $new, LOCK_EX);
    }
    return $changed;
}
