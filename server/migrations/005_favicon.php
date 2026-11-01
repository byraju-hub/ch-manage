<?php
declare(strict_types=1);

// 교회가 올리는 탭 아이콘(파비콘) 파일 경로. 서버 data 폴더 안에 저장한다.

return [
    "ALTER TABLE `{p}site_settings` ADD COLUMN favicon_path VARCHAR(255) NULL AFTER seal_path",
];
