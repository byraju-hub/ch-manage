<?php
declare(strict_types=1);

// 부서 관리(dep-manage)와 연결하는 표. 교인(교적) 한 명 ↔ 부서 관리의 학생 또는 교사 한 명.
// 이 표는 교적(ch-manage) 쪽에만 둔다. 부서 관리의 표는 바꾸지 않으므로, 한쪽만 쓰거나 나중에 하나를 추가해도 된다.
// dm_id 는 다른 프로그램의 표를 가리키므로 외래키를 걸지 않고, 가리키는 학생·교사가 지워지면 '끊어진 연결'로 화면에 알려 준다.

$ENGINE = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    "CREATE TABLE `{p}links` (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        person_id CHAR(36) NOT NULL,
        dm_kind VARCHAR(10) NOT NULL,               -- student · teacher
        dm_id INT UNSIGNED NOT NULL,                -- 부서 관리의 학생/교사 번호
        linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        linked_by INT UNSIGNED NULL,
        UNIQUE KEY uniq_dm (dm_kind, dm_id),
        UNIQUE KEY uniq_person_kind (person_id, dm_kind),
        CONSTRAINT fk_{p}lk_ppl FOREIGN KEY (person_id) REFERENCES `{p}people`(id) ON DELETE CASCADE
    ) $ENGINE",
];
