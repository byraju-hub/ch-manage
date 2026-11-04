<?php
declare(strict_types=1);

// 비밀번호를 바꾸거나 최고관리자가 임시 비밀번호를 만들면, 그 사용자의 다른 로그인(다른 기기·브라우저)을 모두 끊기 위한 번호.
// 로그인할 때 이 번호를 세션에 적어 두고, 요청마다 같은지 확인한다. 번호가 올라가면 예전 세션은 로그인이 풀린다.

return [
    "ALTER TABLE `{p}users` ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER must_change_password",
];
