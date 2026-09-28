<?php

require_once __DIR__ . '/OlympiadPageContent.php';

/**
 * Валидатор плана массовой генерации контента олимпиад.
 */
class OlympiadPageContentPlan
{
    /** @return array<int,string> */
    public static function validate(array $plan): array
    {
        $errors = [];
        if ((int)($plan['content_version'] ?? 0) !== OlympiadPageContent::CONTENT_VERSION) {
            $errors[] = 'В плане указана неподдерживаемая content_version.';
        }
        $entries = $plan['items'] ?? null;
        if (!is_array($entries)) {
            return ['В плане отсутствует массив items.'];
        }

        $ids = [];
        $seen = ['hero' => [], 'about' => [], 'answer' => [], 'review' => []];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $errors[] = "items[{$index}]: ожидается объект.";
                continue;
            }
            $id = (int)($entry['olympiad_id'] ?? 0);
            $prefix = $id > 0 ? "Олимпиада {$id}" : "items[{$index}]";
            if ($id <= 0 || isset($ids[$id])) {
                $errors[] = "{$prefix}: неверный или повторный olympiad_id.";
            }
            $ids[$id] = true;

            $sourceHash = (string)($entry['source_hash'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/', $sourceHash)) {
                $errors[] = "{$prefix}: неверный source_hash.";
            }

            $hero = trim((string)($entry['hero_text'] ?? ''));
            if (!OlympiadPageContent::isValidHero($hero) || self::hasPlaceholder($hero)) {
                $errors[] = "{$prefix}: hero должен содержать 2–3 предложения и 180–320 знаков.";
            }
            $title = OlympiadPageContent::normalizeTitle((string)($entry['title'] ?? ''));
            $subject = trim((string)($entry['subject'] ?? ''));
            if (!self::mentions($hero, $title) || !self::mentions($hero, $subject)) {
                $errors[] = "{$prefix}: hero не упоминает название/тему и предмет.";
            }

            $about = trim((string)($entry['about_html'] ?? ''));
            if (!OlympiadPageContent::isValidAbout($about) || self::hasPlaceholder($about)) {
                $errors[] = "{$prefix}: about_html не прошёл проверку длины, HTML или плейсхолдеров.";
            }
            foreach (['hero' => $hero, 'about' => strip_tags($about)] as $kind => $text) {
                if (self::hasUnsupportedClaim($text)) {
                    $errors[] = "{$prefix}: {$kind} содержит изменяемую цену, учебный год или неподтверждённое заявление.";
                }
                self::registerUnique($seen[$kind], $text, $prefix, $kind, $errors);
            }

            $faq = $entry['faq'] ?? null;
            if (!OlympiadPageContent::isValidFaq($faq)) {
                $errors[] = "{$prefix}: FAQ должен содержать ровно 10 уникальных вопросов с ответами по 2–3 предложения.";
            } else {
                foreach ($faq as $faqIndex => $item) {
                    $answer = (string)($item['answer'] ?? '');
                    if (self::hasUnsupportedClaim($answer)) {
                        $errors[] = "{$prefix}: FAQ #" . ($faqIndex + 1) . ' содержит неподтверждённый факт.';
                    }
                    self::registerUnique($seen['answer'], $answer, $prefix, 'FAQ-answer', $errors);
                }
            }

            $reviews = $entry['review_examples'] ?? [];
            if (!is_array($reviews) || count($reviews) !== 5) {
                $errors[] = "{$prefix}: нужно ровно 5 запасных ИИ-примеров отзывов.";
            } else {
                foreach ($reviews as $reviewIndex => $review) {
                    $role = trim((string)($review['author_role'] ?? ''));
                    $body = trim((string)($review['review_text'] ?? ''));
                    $rating = (int)($review['rating'] ?? 0);
                    if ($role === '' || $body === '' || $rating < 1 || $rating > 5 || self::hasPlaceholder($role . $body)) {
                        $errors[] = "{$prefix}: неверный ИИ-отзыв #" . ($reviewIndex + 1) . '.';
                    }
                    if (self::hasUnsupportedClaim($body)) {
                        $errors[] = "{$prefix}: ИИ-отзыв #" . ($reviewIndex + 1) . ' содержит неподтверждённый факт.';
                    }
                    self::registerUnique($seen['review'], $body, $prefix, 'review', $errors);
                }
            }
        }
        return $errors;
    }

    private static function mentions(string $haystack, string $needle): bool
    {
        $needle = trim(mb_strtolower($needle));
        if ($needle === '') {
            return false;
        }
        $haystack = mb_strtolower($haystack);
        if (mb_strpos($haystack, $needle) !== false) {
            return true;
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $meaningful = array_values(array_filter($words, static fn(string $word): bool => mb_strlen($word) >= 5));
        return $meaningful !== [] && count(array_filter(
            $meaningful,
            static function (string $word) use ($haystack): bool {
                $stem = mb_substr($word, 0, max(5, mb_strlen($word) - 2));
                return mb_strpos($haystack, $word) !== false || mb_strpos($haystack, $stem) !== false;
            }
        )) >= min(2, count($meaningful));
    }

    private static function hasUnsupportedClaim(string $text): bool
    {
        return (bool)preg_match(
            '/(?:\b\d+[\s\x{00A0}]*(?:₽|руб(?:\.|\b))|\bстоимост\w*\s+(?:составляет\s+)?\d+|\b20\d{2}\b|\bлиценз\w*|\bаккредит\w*|\bгарантир\w*|\bобязательно\s+засчитывается|\bсоответствует\s+ФГОС|\bофициально\s+(?:признан\w*|подтвержд\w*))/iu',
            $text
        );
    }

    private static function hasPlaceholder(string $text): bool
    {
        return (bool)preg_match('/\{[a-z_]+\}|\[[A-ZА-Я_]+\]|<[^>]*CHANGE_ME[^>]*>/iu', $text);
    }

    /** @param array<string,string> $bucket @param array<int,string> $errors */
    private static function registerUnique(array &$bucket, string $text, string $prefix, string $kind, array &$errors): void
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? ''));
        if ($normalized === '') {
            return;
        }
        $hash = hash('sha256', $normalized);
        if (isset($bucket[$hash])) {
            $errors[] = "{$prefix}: {$kind} дословно повторяет контент {$bucket[$hash]}.";
        } else {
            $bucket[$hash] = $prefix;
        }
    }
}
