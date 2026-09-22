#!/usr/bin/env php
<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/../classes/Bitrix24Integration.php';

$cases = [
    'ЦДО: договор заключён, но оплата не подтверждена' => [
        ['CATEGORY_ID' => 4, 'STAGE_ID' => 'C4:WON', 'STAGE_SEMANTIC_ID' => 'S'],
        false,
        false,
    ],
    'ЦДО: сделка провалена' => [
        ['CATEGORY_ID' => 4, 'STAGE_ID' => 'C4:35', 'STAGE_SEMANTIC_ID' => 'F'],
        false,
        true,
    ],
    'Курсы: успешная сделка' => [
        ['CATEGORY_ID' => 108, 'STAGE_ID' => 'C108:WON', 'STAGE_SEMANTIC_ID' => 'S'],
        true,
        false,
    ],
    'Курсы: legacy-этап оплаты с ошибочной семантикой F' => [
        ['CATEGORY_ID' => 108, 'STAGE_ID' => 'C108:UC_8RO3WZ', 'STAGE_SEMANTIC_ID' => 'F'],
        true,
        false,
    ],
    'Курсы: обычный отказ' => [
        ['CATEGORY_ID' => 108, 'STAGE_ID' => 'C108:LOSE', 'STAGE_SEMANTIC_ID' => 'F'],
        false,
        true,
    ],
];

$failed = 0;
foreach ($cases as $name => [$deal, $expectedPaid, $expectedFailed]) {
    $actualPaid = Bitrix24Integration::isFgosPaidDeal($deal);
    $actualFailed = Bitrix24Integration::isFgosDealFailed($deal);
    if ($actualPaid !== $expectedPaid || $actualFailed !== $expectedFailed) {
        fwrite(
            STDERR,
            "FAIL {$name}: paid=" . var_export($actualPaid, true)
            . ', failed=' . var_export($actualFailed, true) . "\n"
        );
        $failed++;
        continue;
    }
    echo "OK {$name}\n";
}

exit($failed === 0 ? 0 : 1);
