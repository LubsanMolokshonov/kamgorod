<?php
/**
 * Hero-карточка «Сколково / аттестация / диплом» для страниц курсов.
 * Ожидает переменную $variant: 'all' | 'kpk' | 'pp'.
 */
$variant = $variant ?? 'all';

switch ($variant) {
    case 'pp':
        $heroTitleHtml  = 'Получите диплом и <span>право работать в новой должности</span>';
        $heroDescHtml   = 'Мы получили разрешение на осуществление образовательной деятельности от Фонда «Сколково» по <strong>66 образовательным программам</strong>. Диплом о профессиональной переподготовке даёт законное основание занимать новую должность согласно 273-ФЗ «Об образовании».';
        $heroFeatures   = [
            'Диплом гособразца о профпереподготовке',
            'Право вести новый вид деятельности (273-ФЗ)',
            'Диплом виден на Госуслугах',
            'Данные вносятся в ФИС ФРДО',
        ];
        break;

    case 'all':
        $heroTitleHtml  = 'Обучение, которое <span>признают работодатели и госорганы</span>';
        $heroDescHtml   = 'Мы получили разрешение на осуществление образовательной деятельности от Фонда «Сколково» по <strong>66 образовательным программам</strong>. Таких организаций в России — единицы. Удостоверения о повышении квалификации и дипломы о профессиональной переподготовке подтверждены на федеральном уровне.';
        $heroFeatures   = [
            '66 аккредитованных программ',
            'Документы принимают при аттестации и трудоустройстве',
            'Видны на Госуслугах',
            'Данные вносятся в ФИС ФРДО',
        ];
        break;

    case 'kpk':
    default:
        $heroTitleHtml  = 'С нашими курсами вы <span>100% пройдёте аттестацию</span>';
        $heroDescHtml   = 'Мы получили разрешение на осуществление образовательной деятельности от Фонда «Сколково» по <strong>66 образовательным программам</strong>. Таких организаций в России — единицы. Ваше удостоверение будет подтверждено на федеральном уровне.';
        $heroFeatures   = [
            'Документ примут при любой аттестации',
            'Удостоверение видно на Госуслугах',
            'Данные вносятся в ФИС ФРДО',
            '66 аккредитованных программ',
        ];
        break;
}
?>
<div class="hero-attestation-card">
    <div class="hero-attestation-header">
        <img src="/assets/images/skolkovo.webp" alt="Сколково" class="hero-attestation-logo">
        <span class="hero-attestation-badge-text">Фонд «Сколково» — разрешение № 068</span>
    </div>
    <h2 class="hero-attestation-title"><?php echo $heroTitleHtml; ?></h2>
    <p class="hero-attestation-desc"><?php echo $heroDescHtml; ?></p>
    <ul class="hero-features-list">
        <?php foreach ($heroFeatures as $feature): ?>
            <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span><?php echo htmlspecialchars($feature, ENT_QUOTES, 'UTF-8'); ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
