<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }

function blogVoteToken(): string {
    $token = $_COOKIE['fgos_vote_token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) {
        $token = bin2hex(random_bytes(16));
        setcookie('fgos_vote_token', $token, ['expires' => time() + 31536000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['fgos_vote_token'] = $token;
    }
    return $token;
}

/** Проверка и списание лимита под одним замком; сбой хранилища закрывает отправку. */
function blogConsumeLimit(string $key, int $limit, int $window = 600): bool {
    $fp = fopen(sys_get_temp_dir() . '/blog_rl_' . hash('sha256', $key), 'c+');
    if (!$fp) throw new RuntimeException('Не удалось проверить лимит отправки');
    try {
        if (!flock($fp, LOCK_EX)) throw new RuntimeException('Не удалось проверить лимит отправки');
        $times = json_decode(stream_get_contents($fp), true) ?: [];
        $times = array_values(array_filter($times, static fn($t) => $t > time() - $window));
        if (count($times) >= $limit) return false;
        $times[] = time();
        rewind($fp); ftruncate($fp, 0);
        if (fwrite($fp, json_encode($times)) === false || !fflush($fp)) throw new RuntimeException('Не удалось сохранить лимит');
        return true;
    } finally { flock($fp, LOCK_UN); fclose($fp); }
}

function blogDiscussionHtml(array $rows): string {
    $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $html = '';
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $html .= '<article class="bd-comment' . ($row['parent_id'] ? ' bd-reply' : '') . '" id="comment-' . $id . '">';
        $html .= '<div class="bd-meta"><strong>' . $e($row['author_name']) . '</strong>';
        if ($row['author_role']) $html .= '<span>' . $e($row['author_role']) . '</span>';
        $html .= '<time datetime="' . $e(date('c', strtotime($row['created_at']))) . '">' . $e(date('d.m.Y H:i', strtotime($row['created_at']))) . '</time></div>';
        if ($row['rating'] !== null) $html .= '<div class="bd-stars" aria-label="Оценка ' . (int)$row['rating'] . ' из 5">' . str_repeat('★', (int)$row['rating']) . str_repeat('☆', 5 - (int)$row['rating']) . '</div>';
        if ($row['parent_id']) $html .= '<p class="bd-address">Ответ для ' . $e($row['parent_name']) . '</p>';
        if ($row['body'] !== '') $html .= '<p class="bd-body">' . nl2br($e($row['body'])) . '</p>';
        $html .= '<button type="button" class="bd-answer" data-parent="' . $id . '" data-name="' . $e($row['author_name']) . '">Ответить</button></article>';
    }
    return $html;
}

function blogDiscussionSchema(array $article, array $stats, array $rows): array {
    $article['commentCount'] = $stats['comments'];
    $article['discussionUrl'] = $article['url'] . '#discussion';
    if ($stats['count'] > 0) $article['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $stats['avg'], 'ratingCount' => $stats['count'], 'bestRating' => 5, 'worstRating' => 1];
    $comments = []; $reviews = [];
    foreach ($rows as $r) {
        if ($r['body'] === '') continue;
        $node = ['@type' => 'Comment', '@id' => $article['url'] . '#comment-' . (int)$r['id'], 'url' => $article['url'] . '#comment-' . (int)$r['id'],
            'author' => ['@type' => 'Person', 'name' => $r['author_name']], 'datePublished' => date('c', strtotime($r['created_at'])), 'text' => $r['body']];
        if ($r['parent_id']) $node['parentItem'] = ['@id' => $article['url'] . '#comment-' . (int)$r['parent_id']];
        if ($r['rating'] !== null) {
            $node['@type'] = ['Comment', 'Review'];
            $node['reviewBody'] = $r['body'];
            $node['reviewRating'] = ['@type' => 'Rating', 'ratingValue' => (int)$r['rating'], 'bestRating' => 5, 'worstRating' => 1];
            $node['itemReviewed'] = ['@id' => $article['@id']];
            $reviews[] = ['@id' => $node['@id']];
        }
        $comments[] = $node;
    }
    if ($comments) $article['comment'] = $comments;
    if ($reviews) $article['review'] = $reviews;
    return $article;
}
