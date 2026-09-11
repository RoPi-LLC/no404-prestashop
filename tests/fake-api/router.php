<?php
/**
 * Fake no404 API for tests and manual checks. Run with:
 *   php -S 127.0.0.1:8404 tests/fake-api/router.php
 *
 * The scenario is chosen by the `path` parameter:
 *   /echo            200, echoes the request headers back as JSON
 *   /match           200, CATALOG match to /new-product, redirectStatus 301
 *   /match-302       200, CATALOG match, redirectStatus 302
 *   /none            200, no match
 *   /external        200, match pointing at another host
 *   /broken          200, not JSON
 *   /status/NNN      that status code
 *   /moved           302 to https://www.no404.tr/...
 *   /slow            200 after 3 seconds
 * Anything else: 200, no match.
 *
 * Every request increments a counter in the system temp dir; GET /__count
 * returns it and DELETE /__count resets it — the counter is how "the cache
 * protects the quota" is measured rather than assumed.
 */
$counterFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'no404-fake-api-' . ($_SERVER['SERVER_PORT'] ?? '0') . '.count';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

header('Content-Type: application/json');

if ('/__count' === $uriPath) {
    if ('DELETE' === ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
        @unlink($counterFile);
    }
    echo json_encode(['count' => is_file($counterFile) ? (int) file_get_contents($counterFile) : 0]);

    return true;
}

if ('/api/v1/resolve' !== $uriPath) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);

    return true;
}

file_put_contents($counterFile, (string) ((is_file($counterFile) ? (int) file_get_contents($counterFile) : 0) + 1), LOCK_EX);

$path = isset($_GET['path']) ? (string) $_GET['path'] : '';
$none = ['success' => true, 'found' => false, 'redirect' => null, 'score' => 0, 'source' => 'NONE'];

if (preg_match('#^/status/(\d{3})$#', $path, $m)) {
    http_response_code((int) $m[1]);
    echo json_encode(['success' => false, 'message' => 'Scenario status ' . $m[1]]);

    return true;
}

switch ($path) {
    case '/echo':
        echo json_encode([
            'success' => true,
            'found' => false,
            'redirect' => null,
            'score' => 0,
            'source' => 'NONE',
            'echo' => [
                'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
                'query' => $_SERVER['QUERY_STRING'] ?? '',
            ],
        ]);
        break;
    case '/match':
        echo json_encode(['success' => true, 'found' => true, 'redirect' => '/new-product', 'score' => 0.82, 'source' => 'CATALOG', 'redirectStatus' => 301]);
        break;
    case '/match-302':
        echo json_encode(['success' => true, 'found' => true, 'redirect' => '/new-product', 'score' => 0.41, 'source' => 'CATALOG', 'redirectStatus' => 302]);
        break;
    case '/external':
        echo json_encode(['success' => true, 'found' => true, 'redirect' => 'https://evil.example/x', 'score' => 0.9, 'source' => 'CATALOG', 'redirectStatus' => 301]);
        break;
    case '/broken':
        header('Content-Type: text/html');
        echo '<html>not json</html>';
        break;
    case '/moved':
        header('Location: https://www.no404.tr/api/v1/resolve?path=%2Fmoved', true, 302);
        break;
    case '/slow':
        sleep(3);
        echo json_encode($none);
        break;
    default:
        echo json_encode($none);
}

return true;
