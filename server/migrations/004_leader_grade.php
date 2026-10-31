<?php
declare(strict_types=1);

// D 등급 = 소그룹 인도자. 교적 화면은 열지 못하고, 자기 소그룹의 출석 체크만 할 수 있다.
// 처음 만들 때 등급 표에 걸려 있던 'A·B·C 만 가능' 검사를 풀고 D 를 추가한다.
// (검사의 이름이 DB 종류마다 달라서 찾아서 지운다. 등급 값은 PHP 가 확인한다.)

return [
    function (PDO $pdo, string $p): void {
        $st = $pdo->prepare("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_TYPE = 'CHECK' AND TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $st->execute([$p . 'grades']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $pdo->exec("ALTER TABLE `{$p}grades` DROP CONSTRAINT `{$name}`");
        }
    },
    "INSERT INTO `{p}grades` (grade, name, can_edit, can_export, can_visit, is_site_admin) VALUES ('D', 'D · 소그룹 인도자', 0, 0, 0, 0)",
];
