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

$entryJson = $_POST['entry'] ?? '';
$html = $_POST['html'] ?? '';
$entry = json_decode($entryJson, true);

if (!is_array($entry) || json_last_error() !== JSON_ERROR_NONE) {
    respond(false, 'Invalid blog entry data.', 422);
}

$requiredFields = ['id', 'title', 'excerpt', 'category', 'categoryLabel', 'date', 'readTime', 'image', 'featured', 'url'];
foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $entry)) {
        respond(false, "Missing required field: {$field}.", 422);
    }
}

$slug = (string) $entry['id'];
if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
    respond(false, 'Invalid blog slug.', 422);
}

if (trim($html) === '') {
    respond(false, 'Blog HTML cannot be empty.', 422);
}

$blogsDir = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'blogs');
if ($blogsDir === false || !is_dir($blogsDir)) {
    respond(false, 'Blogs folder was not found.', 500);
}

$blogPath = $blogsDir . DIRECTORY_SEPARATOR . $slug . '.html';
$expectedUrl = 'blogs/' . $slug . '.html';
$entry['url'] = $expectedUrl;

if (file_put_contents($blogPath, $html, LOCK_EX) === false) {
    respond(false, 'Could not write the blog page. Check folder permissions.', 500);
}

$manifestPath = __DIR__ . DIRECTORY_SEPARATOR . 'blogs.js';
$existingBlogs = readBlogManifest($manifestPath);
$mergedBlogs = mergeBlogEntry($existingBlogs, $entry);
$manifestCode = 'window.blogData = ' . json_encode($mergedBlogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n";

if (file_put_contents($manifestPath, $manifestCode, LOCK_EX) === false) {
    respond(false, 'Blog page was written, but blogs.js could not be updated. Check file permissions.', 500);
}

respond(true, 'Blog published successfully.', 200, [
    'url' => $expectedUrl,
    'filename' => $slug . '.html',
    'count' => count($mergedBlogs),
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

function mergeBlogEntry(array $blogs, array $newEntry): array
{
    $merged = [];
    foreach ($blogs as $blog) {
        if (!is_array($blog) || ($blog['id'] ?? '') === $newEntry['id']) {
            continue;
        }

        if (!empty($newEntry['featured'])) {
            $blog['featured'] = false;
        }

        $merged[] = $blog;
    }

    array_unshift($merged, $newEntry);
    return $merged;
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
