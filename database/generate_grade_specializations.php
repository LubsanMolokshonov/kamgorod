<?php
// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Подготовка схемы для SEO-дробления олимпиад «Педагогам» по отдельным классам 1-11.
 *
 * Создаёт:
 *  - 11 audience_types (category_id=1, slug pedagogam-1-klass..pedagogam-11-klass)
 *  - под классы 1-4: специализации-предметы, скопированные из nachalnaya-shkola (id=2) + роли
 *  - под классы 5-11: специализации-предметы, скопированные из srednyaya-starshaya-shkola (id=3) + роли
 *  - единый список из 14 школьных ролей (12 перенесены из ДОУ, 2 новые) на каждый класс
 *
 * Идемпотентен: проверка по slug перед INSERT, безопасно перезапускать.
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/database/generate_grade_specializations.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

const CATEGORY_PEDAGOGI = 1;
const TYPE_NACHALKA_SOURCE = 2;   // nachalnaya-shkola — эталон предметов для 1-4 класса
const TYPE_SREDNYAYA_SOURCE = 3;  // srednyaya-starshaya-shkola — эталон предметов для 5-11 класса
const TYPE_DOU_SOURCE = 1;        // dou — эталон 12 ролей

// 12 ролей, релевантных школе, переносимых из набора ДОУ (slug совпадает с ДОУ-эталоном)
$ROLES_FROM_DOU = [
    'logopediya', 'defektologiya', 'pedagog-psiholog', 'tyutorstvo',
    'socialnaya-pedagogika', 'metodist', 'administratsiya-upravlenie',
    'bibliotekar', 'pedagog-organizator', 'klassnoe-rukovodstvo',
    'vospitatel-gpd', 'rabota-s-ovz',
];

// 2 новые роли, отсутствующие в ДОУ-наборе, формализующие текстовые subject старых олимпиад
$ROLES_NEW = [
    ['slug' => 'vospitatelnaya-rabota', 'name' => 'Воспитательная работа', 'name_dative' => 'воспитательной работе', 'seo_phrase' => 'по воспитательной работе'],
    ['slug' => 'tsifrovye-tehnologii-obrazovanie', 'name' => 'Цифровые технологии в образовании', 'name_dative' => 'цифровым технологиям в образовании', 'seo_phrase' => 'по цифровым технологиям в образовании'],
];

function getOrNull($db, $sql, $params = []) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ensureAudienceType($db, $categoryId, $slug, $name, $displayOrder) {
    $existing = getOrNull($db, "SELECT id FROM audience_types WHERE slug = ?", [$slug]);
    if ($existing) {
        echo "  SKIP audience_type (exists): {$slug} (id={$existing['id']})\n";
        return (int)$existing['id'];
    }
    $stmt = $db->prepare(
        "INSERT INTO audience_types (category_id, slug, name, display_order, is_active) VALUES (?, ?, ?, ?, 1)"
    );
    $stmt->execute([$categoryId, $slug, $name, $displayOrder]);
    $id = (int)$db->lastInsertId();
    echo "  OK audience_type #{$id}: {$slug} ({$name})\n";
    return $id;
}

function copySubjectSpecializations($db, $sourceTypeId, $targetTypeId) {
    $stmt = $db->prepare("SELECT slug, specialization_type, icon, name, name_dative, seo_phrase, description, display_order FROM audience_specializations WHERE audience_type_id = ? ORDER BY display_order");
    $stmt->execute([$sourceTypeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach ($rows as $r) {
        $exists = getOrNull($db, "SELECT id FROM audience_specializations WHERE audience_type_id = ? AND slug = ?", [$targetTypeId, $r['slug']]);
        if ($exists) {
            continue;
        }
        $ins = $db->prepare(
            "INSERT INTO audience_specializations
                (audience_type_id, slug, specialization_type, icon, name, name_dative, seo_phrase, description, display_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );
        $ins->execute([
            $targetTypeId, $r['slug'], $r['specialization_type'], $r['icon'],
            $r['name'], $r['name_dative'], $r['seo_phrase'], $r['description'], $r['display_order'],
        ]);
        $count++;
    }
    return $count;
}

function copyRoleSpecializations($db, $sourceTypeId, $targetTypeId, array $roleSlugs, int $displayOrderStart) {
    $stmt = $db->prepare("SELECT slug, icon, name, name_dative, seo_phrase, description FROM audience_specializations WHERE audience_type_id = ? AND slug = ?");
    $count = 0;
    $order = $displayOrderStart;
    foreach ($roleSlugs as $slug) {
        $exists = getOrNull($db, "SELECT id FROM audience_specializations WHERE audience_type_id = ? AND slug = ?", [$targetTypeId, $slug]);
        if ($exists) {
            $order++;
            continue;
        }
        $stmt->execute([$sourceTypeId, $slug]);
        $src = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$src) {
            echo "    WARN: role source not found for slug={$slug}\n";
            $order++;
            continue;
        }
        $ins = $db->prepare(
            "INSERT INTO audience_specializations
                (audience_type_id, slug, specialization_type, icon, name, name_dative, seo_phrase, description, display_order, is_active)
             VALUES (?, ?, 'role', ?, ?, ?, ?, ?, ?, 1)"
        );
        $ins->execute([$targetTypeId, $slug, $src['icon'], $src['name'], $src['name_dative'], $src['seo_phrase'], $src['description'], $order]);
        $count++;
        $order++;
    }
    return [$count, $order];
}

function ensureNewRoleSpecializations($db, $targetTypeId, array $newRoles, int $displayOrderStart) {
    $count = 0;
    $order = $displayOrderStart;
    foreach ($newRoles as $role) {
        $exists = getOrNull($db, "SELECT id FROM audience_specializations WHERE audience_type_id = ? AND slug = ?", [$targetTypeId, $role['slug']]);
        if ($exists) {
            $order++;
            continue;
        }
        $ins = $db->prepare(
            "INSERT INTO audience_specializations
                (audience_type_id, slug, specialization_type, icon, name, name_dative, seo_phrase, description, display_order, is_active)
             VALUES (?, ?, 'role', NULL, ?, ?, ?, NULL, ?, 1)"
        );
        $ins->execute([$targetTypeId, $role['slug'], $role['name'], $role['name_dative'], $role['seo_phrase'], $order]);
        $count++;
        $order++;
    }
    return [$count, $order];
}

echo "\n=== Создание audience_types для «Педагогам» (классы 1-11) ===\n";

$typeIds = []; // grade(int) => audience_type_id

for ($grade = 1; $grade <= 11; $grade++) {
    $slug = "pedagogam-{$grade}-klass";
    $name = "{$grade} класс";
    $displayOrder = 9 + $grade; // 10..20
    $typeIds[$grade] = ensureAudienceType($db, CATEGORY_PEDAGOGI, $slug, $name, $displayOrder);
}

echo "\n=== Копирование специализаций ===\n";

foreach ($typeIds as $grade => $typeId) {
    $isNachalka = $grade >= 1 && $grade <= 4;
    $sourceSubjectType = $isNachalka ? TYPE_NACHALKA_SOURCE : TYPE_SREDNYAYA_SOURCE;

    echo "-- Класс {$grade} (type_id={$typeId}), ступень: " . ($isNachalka ? 'началка' : 'средняя-старшая') . "\n";

    $subjCount = copySubjectSpecializations($db, $sourceSubjectType, $typeId);
    echo "   предметы: +{$subjCount}\n";

    [$roleCount1, $nextOrder] = copyRoleSpecializations($db, TYPE_DOU_SOURCE, $typeId, $ROLES_FROM_DOU, 100);
    echo "   роли (из ДОУ): +{$roleCount1}\n";

    [$roleCount2, ] = ensureNewRoleSpecializations($db, $typeId, $ROLES_NEW, $nextOrder);
    echo "   роли (новые): +{$roleCount2}\n";
}

echo "\n=== Итоговая проверка ===\n";
foreach ($typeIds as $grade => $typeId) {
    $cnt = getOrNull($db, "SELECT COUNT(*) c FROM audience_specializations WHERE audience_type_id = ?", [$typeId]);
    echo "Класс {$grade}: " . $cnt['c'] . " специализаций\n";
}

echo "\nГотово.\n";
