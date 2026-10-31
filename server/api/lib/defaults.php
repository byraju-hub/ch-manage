<?php
declare(strict_types=1);

// 설치할 때 넣는 기본값. 화면의 src/lib/registry/config.ts · site-settings.ts · year-settings.ts 와 같은 내용이다.
// 교회마다 다른 값(공동체 이름, 전도회별 출생 연도 등)은 비워 두고, 설치한 뒤 관리자 › 교회 설정에서 채운다.

/** 교인구분 3단계. 교인 > (장년·새가족·청년부·…) > (예배출석·가끔출석·불출석·장기결석자). @return array<int, array{string,?string,string,int}> id, parent_id, name, sort */
function default_categories(): array
{
    $l2 = [['a', '장년'], ['n', '새가족'], ['y', '청년부'], ['h', '중고등부'], ['e', '유초등부'], ['k', '유치부'], ['b', '영아부']];
    $l3 = [['a', '예배출석'], ['s', '가끔출석'], ['n', '불출석'], ['l', '장기결석자']];
    $out = [['m', null, '교인', 1]];
    foreach ($l2 as $j => [$k2, $n2]) {
        $out[] = ["m.$k2", 'm', $n2, $j + 1];
        $k = 0;
        foreach ($l3 as [$k3, $n3]) {
            if ($k3 === 'n' && $k2 !== 'a') continue; // '불출석'은 장년에만 있다
            $out[] = ["m.$k2.$k3", "m.$k2", $n3, ++$k];
        }
    }
    $out[] = ['o', null, '미등록/타교인', 2];
    $out[] = ['dead', null, '별세', 3];
    $out[] = ['move', null, '이사/이민', 4];
    $out[] = ['keep', null, '보관', 5];
    return $out;
}

function default_church_info(string $name, string $unit1, string $unit2): array
{
    return [
        'name' => $name, 'denomination' => '', 'address' => '', 'phone' => '', 'fax' => '', 'email' => '', 'homepage' => '',
        'founded' => '', 'bizNo' => '', 'pastorLabel' => '담임목사', 'pastorName' => '', 'logo' => null, 'seal' => null,
        'unit1' => $unit1, 'unit2' => $unit2,
    ];
}

function default_cert_form(): array
{
    return [
        'prefix' => '제', 'format' => '{연도}-{번호}', 'digits' => 3, 'suffix' => '호',
        'purposes' => ['제출용', '이명용', '학교 제출용', '직장 제출용', '기타'],
        'memberBody' => '위 사람은 본 교회에 등록된 교인임을 증명합니다.',
        'baptismBody' => '위 사람은 위와 같이 {구분}를 받았음을 증명합니다.',
        'footNote' => '', 'sealSize' => 22,
    ];
}

function default_codes(): array
{
    $soc = [];
    for ($i = 1; $i <= 6; $i++) $soc[] = "{$i}남전도회";
    for ($i = 1; $i <= 10; $i++) $soc[] = "{$i}여전도회";
    return [
        'visitKinds' => ['대심방', '새신자', '일반', '결혼', '출산', '돌', '입학', '졸업', '회갑', '장례', '추도', '개업', '이사', '병문안', '전도'],
        'missionSocieties' => $soc,
    ];
}

function default_backup(): array
{
    return ['auto' => true, 'cycle' => '매일', 'time' => '03:00', 'keep' => 30, 'notify' => '', 'last' => null];
}

/** 연간 설정 기본값. 교육부서 기준은 학교 나이(올해 - 출생 연도, 7 = 초1)로 정한다. */
function default_year_settings(int $year): array
{
    return [
        'year' => $year, 'motto' => '', 'verse' => '', 'age_mode' => '만 나이',
        'communities' => new stdClass(), // 공동체별 출생 연도: 교회가 정함
        'societies' => new stdClass(),   // 전도회별 출생 연도: 교회가 정함
        'edu' => [
            ['dept' => '영아부', 'from' => 0, 'to' => 3],
            ['dept' => '유치부', 'from' => 4, 'to' => 6],
            ['dept' => '유초등부', 'from' => 7, 'to' => 12],
            ['dept' => '중고등부', 'from' => 13, 'to' => 18],
            ['dept' => '청년부', 'from' => 19, 'to' => 39],
            ['dept' => '장년', 'from' => 40, 'to' => 200],
        ],
        'promote_date' => sprintf('%04d-01-04', $year),
        'absent_weeks' => 9, 'newcomer_days' => 60,
    ];
}

/** 설치 때 기본값을 한꺼번에 넣는다. 표는 이미 만들어져 있어야 한다. */
function seed_defaults(PDO $pdo, string $p, string $churchName, string $unit1, string $unit2): void
{
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);

    $st = $pdo->prepare("INSERT INTO `{$p}categories` (id, parent_id, name, sort_order) VALUES (?,?,?,?)");
    foreach (default_categories() as $c) $st->execute($c); // 부모가 먼저 나오는 순서

    $pdo->prepare("INSERT INTO `{$p}site_settings` (id, church_info, cert_form, codes, backup) VALUES (1,?,?,?,?)")
        ->execute([$j(default_church_info($churchName, $unit1, $unit2)), $j(default_cert_form()), $j(default_codes()), $j(default_backup())]);

    $y = default_year_settings((int)date('Y'));
    $pdo->prepare("INSERT INTO `{$p}year_settings` (year, motto, verse, age_mode, communities, societies, edu, promote_date, absent_weeks, newcomer_days) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$y['year'], $y['motto'], $y['verse'], $y['age_mode'], $j($y['communities']), $j($y['societies']), $j($y['edu']), $y['promote_date'], $y['absent_weeks'], $y['newcomer_days']]);

    $st = $pdo->prepare("INSERT INTO `{$p}meeting_officers` (meeting) VALUES (?)");
    foreach (['공동의회', '당회', '제직회'] as $m) $st->execute([$m]);
}
