<?php
declare(strict_types=1);

// 개인정보의 안전성 확보조치 기준(제8조)은 접속기록에 계정·접속일시·접속지 정보·수행 업무를 남기라고 한다.
// 그동안 접속 기록에는 접속지(IP 주소)가 없어서 칸을 하나 더한다. 이미 쌓인 기록은 비어 있다.

return [
    "ALTER TABLE `{p}access_log` ADD COLUMN ip VARCHAR(45) NULL AFTER who",
];
