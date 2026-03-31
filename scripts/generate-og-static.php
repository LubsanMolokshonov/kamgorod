<?php
/**
 * Генерация статических OG-картинок для каталожных страниц
 * Запуск: php scripts/generate-og-static.php
 * Результат: assets/images/og-*.jpg (1200x630)
 */

require_once __DIR__ . '/../classes/OgImageGenerator.php';

$generator = new OgImageGenerator();
$outputDir = __DIR__ . '/../assets/images';

$pages = [
    'og-home.jpg' => [
        'type'     => '',
        'title'    => 'Педагогический портал',
        'subtitle' => 'Конкурсы, олимпиады, вебинары, курсы повышения квалификации для педагогов',
    ],
    'og-competitions.jpg' => [
        'type'     => 'competition',
        'title'    => 'Всероссийские конкурсы для педагогов',
        'subtitle' => 'Участвуйте в конкурсах и получите диплом за 1 день',
    ],
    'og-olympiads.jpg' => [
        'type'     => 'olympiad',
        'title'    => 'Всероссийские олимпиады',
        'subtitle' => 'Проверьте свои знания и получите диплом победителя',
    ],
    'og-webinars.jpg' => [
        'type'     => 'webinar',
        'title'    => 'Вебинары для педагогов',
        'subtitle' => 'Бесплатные онлайн-мероприятия с сертификатом участника',
    ],
    'og-courses.jpg' => [
        'type'     => 'course',
        'title'    => 'Курсы повышения квалификации',
        'subtitle' => 'Дистанционное обучение с удостоверением установленного образца',
    ],
    'og-journal.jpg' => [
        'type'     => 'publication',
        'title'    => 'Научный педагогический журнал',
        'subtitle' => 'Публикация статей с присвоением УДК и свидетельством',
    ],
];

echo "Генерация статических OG-картинок...\n\n";

foreach ($pages as $filename => $config) {
    $outputPath = $outputDir . '/' . $filename;
    $generator->generate($outputPath, $config['type'], $config['title'], $config['subtitle']);

    $size = filesize($outputPath);
    echo "  ✓ $filename — " . number_format($size / 1024, 1) . " KB\n";
}

echo "\nГотово! Сгенерировано " . count($pages) . " картинок.\n";
