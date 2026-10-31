<?php
declare(strict_types=1);

// 체험(데모) 계정을 표시하는 칸. 체험 계정은 모든 화면을 볼 수 있지만, 서버가 보기 외의 요청을 모두 막는다.

return [
    "ALTER TABLE `{p}users` ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0",
];
