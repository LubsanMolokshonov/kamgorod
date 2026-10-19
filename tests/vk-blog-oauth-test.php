<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../scripts/lib/vk-blog/oauth.php';
require_once __DIR__ . '/../scripts/lib/vk-blog/client.php';
function ok(bool $value, string $label): void {
    if (!$value) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'OK: ' . $label . "\n";
}
function rejected(Closure $call, string $label): void {
    try { $call(); } catch (RuntimeException $e) {
        ok(!str_contains($e->getMessage(), 'secret-'), $label . ' (safe error)'); return;
    }
    throw new RuntimeException('FAIL: ' . $label);
}
$directory = sys_get_temp_dir() . '/fgos-vk-oauth-test-' . bin2hex(random_bytes(8));
$now = 1000000;
$requests = [];
$fail = false;
$sequence = 0;
$transport = function (array $params) use (&$requests, &$fail, &$sequence): array {
    $requests[] = $params;
    if ($fail) { throw new RuntimeException('secret-provider-raw-error'); }
    $sequence++;
    return ['access_token' => 'secret-access-' . $sequence, 'refresh_token' => 'secret-refresh-' . $sequence,
        'expires_in' => 3600, 'state' => $params['state'], 'scope' => 'photos wall'];
};
$oauth = new VkBlogOAuth($directory, 54834138, 'https://fgos.pro/vk-blog-oauth-return.html', $transport, fn() => $GLOBALS['now']);
try {
    parse_str((string)parse_url($oauth->begin(), PHP_URL_QUERY), $query);
    $pending = json_decode(file_get_contents($directory . '/authorization.json'), true);
    ok($query['code_challenge'] === rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='), 'PKCE S256 challenge');
    ok($query['scope'] === 'photos wall' && !isset($query['code_verifier']), 'Only required scopes; verifier stays server-side');
    rejected(fn() => $oauth->exchange('secret-code', 'device', 'wrong'), 'Wrong state blocks exchange');
    ok(count($requests) === 0, 'Invalid state makes no network request');
    $oauth->exchange('secret-code', 'device', $query['state']);
    rejected(fn() => $oauth->exchange('secret-code', 'device', $query['state']), 'Code cannot be exchanged twice');
    ok($oauth->token() === 'secret-access-1' && count($requests) === 1, 'Valid token is reused');
    $now += 3000;
    ok($oauth->token() === 'secret-access-2', 'Refresh at ten minute threshold');
    $saved = json_decode(file_get_contents($directory . '/tokens.json'), true);
    ok($saved['VK_REFRESH_TOKEN'] === 'secret-refresh-2' && $saved['VK_DEVICE_ID'] === 'device', 'Rotated pair and device saved together');
    ok($saved['VK_TOKEN_EXPIRES_AT'] === $now + 3600, 'Expiry is absolute timestamp');
    $count = count($requests);
    ok($oauth->token('secret-access-1') === 'secret-access-2' && count($requests) === $count, 'Stale concurrent rejection does not rotate again');
    $apiCalls = 0;
    $client = new VkBlogClient('', 200065283, $oauth, function ($method, $params) use (&$apiCalls): string {
        $apiCalls++;
        return json_encode($apiCalls === 1 ? ['error' => ['error_code' => 5]] : ['response' => ['post_id' => 77]]);
    });
    ok($client->post(['message' => 'test'], 'photo-200065283_1', 'stable-guid') === 77 && $apiCalls === 2, 'Explicit auth rejection refreshes and retries once');
    $apiCalls = 0;
    $client = new VkBlogClient('', 200065283, $oauth, function () use (&$apiCalls): string {
        $apiCalls++;
        return '{"error":{"error_code":5}}';
    });
    rejected(fn() => $client->call('wall.get'), 'Repeated auth rejection stops');
    ok($apiCalls === 2, 'No infinite authorization retries');
    $apiCalls = 0;
    $client = new VkBlogClient('', 200065283, $oauth, function () use (&$apiCalls): string {
        $apiCalls++; throw new RuntimeException('Lost wall.post response');
    });
    rejected(fn() => $client->post(['message' => 'test'], 'photo-200065283_1', 'stable-guid'), 'Lost post response propagates to journal reconciliation');
    ok($apiCalls === 1, 'Transport failure never retries post');
    $fail = true;
    rejected(fn() => $oauth->token($oauth->token()), 'Lost refresh response requires manual authorization');
    $count = count($requests);
    rejected(fn() => $oauth->token(), 'Uncertain rotating token is blocked');
    ok(count($requests) === $count, 'Uncertain refresh is not replayed');
    $oauth->begin();
    $pending = json_decode(file_get_contents($directory . '/authorization.json'), true);
    $now += 901;
    rejected(fn() => $oauth->exchange('secret-code', 'device', $pending['state']), 'Expired authorization state rejected');
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($directory)) { rmdir($directory); }
}
echo "VK OAuth tests passed\n";