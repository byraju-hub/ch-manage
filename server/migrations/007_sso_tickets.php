<?php
declare(strict_types=1);

// 재정 관리(fin-manage)로 비밀번호 없이 넘어가기 위한 1회용 입장권. 교적이 만들고 재정이 읽은 뒤 지운다.
// 원문은 저장하지 않고 sha256 해시만 둔다. 유효 시간은 30초. 표·열 이름은 재정 쪽 코드가 그대로 읽으므로 바꾸지 않는다.

return [
    "CREATE TABLE `{p}sso_tickets` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_token (token_hash),
        INDEX idx_expires (expires_at),
        CONSTRAINT fk_{p}sso_user FOREIGN KEY (user_id) REFERENCES `{p}users`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
