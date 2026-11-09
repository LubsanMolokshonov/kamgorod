<?php
declare(strict_types=1);

function seoCatalogArchive(): array
{
    static $registry;
    return $registry ??= json_decode(file_get_contents(__DIR__ . '/seo-catalog-archive.json'), true, 512, JSON_THROW_ON_ERROR);
}

function seoCatalogArchiveLanding(string $path): array
{
    return seoCatalogArchive()['landings'][$path] ?? [];
}

function olympiadVisibleAudience(array $olympiad): string
{
    $path = '/olimpiady/' . rawurlencode($olympiad['slug']) . '/';
    return seoCatalogArchive()['olympiad_labels'][$path] ?? Olympiad::getAudienceLabel($olympiad['target_audience'] ?? '');
}

function renderArchiveCatalogInfo(array $copy): void
{
    if (!$copy) return;
    ?>
    <section class="rd-section"><div class="rd-wrap">
      <h2 class="rd-section-title"><?= htmlspecialchars($copy['info_h2'], ENT_QUOTES, 'UTF-8') ?></h2>
      <?php foreach ($copy['info_paragraphs'] as $paragraph): ?>
        <p><?= htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') ?></p>
      <?php endforeach; ?>
    </div></section>
    <?php
}

function renderArchiveCatalogLinks(array $copy): void
{
    if (!$copy) return;
    ?>
    <nav class="rd-wrap" aria-label="Ссылки по теме"><ul>
      <?php foreach ($copy['links'] as $link): ?>
        <li><a href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($link['text'], ENT_QUOTES, 'UTF-8') ?></a></li>
      <?php endforeach; ?>
    </ul></nav>
    <?php
}
