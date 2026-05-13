<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Only POST requests are allowed.', 405);
}

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'cms-config.php';
if (!is_file($configPath)) {
    respond(false, 'CMS config file is missing.', 500);
}

require $configPath;

$password = $_POST['password'] ?? '';
if (!isset($CMS_PASSWORD_HASH) || !password_verify($password, $CMS_PASSWORD_HASH)) {
    respond(false, 'Invalid CMS password.', 401);
}

$slug = trim((string) ($_POST['id'] ?? ''));
if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
    respond(false, 'Invalid blog slug.', 422);
}

$manifestPath = __DIR__ . DIRECTORY_SEPARATOR . 'blogs.js';
$blogs = readBlogManifest($manifestPath);
$found = false;
$remaining = [];

foreach ($blogs as $blog) {
    if (!is_array($blog)) {
        continue;
    }

    if (($blog['id'] ?? '') === $slug) {
        $found = true;
        continue;
    }

    $remaining[] = $blog;
}

if (!$found) {
    respond(false, 'Blog was not found in blogs.js.', 404);
}

$blogsDir = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'blogs');
if ($blogsDir === false || !is_dir($blogsDir)) {
    respond(false, 'Blogs folder was not found.', 500);
}

$blogPath = $blogsDir . DIRECTORY_SEPARATOR . $slug . '.html';
if (is_file($blogPath) && !unlink($blogPath)) {
    respond(false, 'Could not delete the blog page. Check folder permissions.', 500);
}

$manifestCode = 'window.blogData = ' . json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n";
if (file_put_contents($manifestPath, $manifestCode, LOCK_EX) === false) {
    respond(false, 'Blog page was deleted, but blogs.js could not be updated. Check file permissions.', 500);
}

respond(true, 'Blog deleted successfully.', 200, [
    'id' => $slug,
    'count' => count($remaining),
]);

function readBlogManifest(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return [];
    }

    if (!preg_match('/window\.blogData\s*=\s*(\[.*\])\s*;/s', $contents, $matches)) {
        return [];
    }

    $blogs = json_decode($matches[1], true);
    return is_array($blogs) ? $blogs : [];
}

function respond(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

