<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cms_is_logged_in(): bool
{
    return !empty($_SESSION['tl_cms_logged_in']);
}

function cms_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'blog-post';
}

function cms_read_posts(): array
{
    if (!is_file(TL_CMS_DATA_FILE)) {
        return [];
    }

    $posts = json_decode((string) file_get_contents(TL_CMS_DATA_FILE), true);
    if (!is_array($posts)) {
        return [];
    }

    usort($posts, static fn ($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
    return $posts;
}

function cms_write_posts(array $posts): void
{
    if (!is_dir(dirname(TL_CMS_DATA_FILE))) {
        mkdir(dirname(TL_CMS_DATA_FILE), 0755, true);
    }

    file_put_contents(
        TL_CMS_DATA_FILE,
        json_encode(array_values($posts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function cms_format_date(string $date): string
{
    $time = strtotime($date);
    return $time ? date('F j, Y', $time) : $date;
}

function cms_normalize_body(string $body): string
{
    $allowed = '<p><br><strong><b><em><i><a><h2><h3><ul><ol><li><blockquote>';
    $body = trim(strip_tags($body, $allowed));
    if ($body === '') {
        return '<p>Start writing your blog content here.</p>';
    }

    $paragraphs = array_filter(array_map('trim', preg_split('/\R{2,}/', $body) ?: []));
    $body = implode("\n", array_map(static fn ($p) => '<p>' . nl2br(h($p)) . '</p>', $paragraphs));

    $body = preg_replace('/<p>/', '<p class="text-muted">', $body) ?? $body;
    $body = preg_replace('/<h2>/', '<h2>', $body) ?? $body;
    $body = preg_replace('/<h3>/', '<h3>', $body) ?? $body;
    return $body;
}

function cms_upload_image(): ?string
{
    if (empty($_FILES['image_upload']['name'])) {
        return null;
    }

    if (!is_dir(TL_CMS_UPLOAD_DIR)) {
        mkdir(TL_CMS_UPLOAD_DIR, 0755, true);
    }

    $tmp = $_FILES['image_upload']['tmp_name'] ?? '';
    $name = (string) ($_FILES['image_upload']['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!is_uploaded_file($tmp) || !in_array($ext, $allowed, true)) {
        throw new RuntimeException('Please upload a JPG, PNG, or WEBP image.');
    }

    $file = cms_slugify(pathinfo($name, PATHINFO_FILENAME)) . '-' . date('YmdHis') . '.' . $ext;
    $target = TL_CMS_UPLOAD_DIR . '/' . $file;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Image upload failed. Check folder permissions for assets/blog.');
    }

    return TL_CMS_UPLOAD_WEB_PATH . $file;
}

function cms_render_index(array $posts): void
{
    $cards = '';
    foreach ($posts as $i => $post) {
        if (($post['status'] ?? 'published') !== 'published') {
            continue;
        }

        $delay = $i % 3 === 1 ? ' delay-100' : ($i % 3 === 2 ? ' delay-200' : '');
        $image = $post['image'] ?: TL_CMS_DEFAULT_IMAGE;
        $cards .= '<a href="' . h($post['slug']) . '.html" class="card">'
            . '<div class="thumb"><img src="' . h($image) . '" alt="' . h($post['image_alt'] ?? $post['title']) . '"></div>'
            . '<span class="meta">' . h(cms_format_date($post['date'])) . ' — ' . h($post['category']) . '</span>'
            . '<h3>' . h($post['title']) . '</h3>'
            . '<p>' . h($post['excerpt']) . '</p>'
            . '</a>';
    }

    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Blog</title></head><body><main>' . $cards . '</main></body></html>';
    file_put_contents(TL_CMS_BLOG_DIR . '/index.html', $html, LOCK_EX);
}

function cms_render_post(array $post, array $posts): void
{
    $recent = '';
    $count = 0;
    foreach ($posts as $item) {
        if (($item['status'] ?? 'published') !== 'published' || $item['slug'] === $post['slug']) {
            continue;
        }
        $recent .= '<li><a href="' . h($item['slug']) . '.html">' . h($item['title']) . '</a></li>';
        $count++;
        if ($count >= 4) break;
    }

    $image = $post['image'] ?: TL_CMS_DEFAULT_IMAGE;
    $body = cms_normalize_body((string) $post['body']);

    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($post['title']) . '</title></head><body>';
    $html .= '<article><header><h1>' . h($post['title']) . '</h1><p>' . h(cms_format_date($post['date'])) . ' — ' . h($post['category']) . '</p></header>';
    $html .= '<img src="' . h($image) . '" alt="' . h($post['image_alt'] ?? '') . '">';
    $html .= '<div>' . $body . '</div>';
    $html .= '<aside><h4>Recent posts</h4><ul>' . $recent . '</ul></aside>';
    $html .= '</article></body></html>';

    file_put_contents(TL_CMS_BLOG_DIR . '/' . $post['slug'] . '.html', $html, LOCK_EX);
}

function cms_publish_all(array $posts): void
{
    if (!is_dir(TL_CMS_BLOG_DIR)) {
        mkdir(TL_CMS_BLOG_DIR, 0755, true);
    }

    cms_render_index($posts);
    foreach ($posts as $post) {
        if (($post['status'] ?? 'published') === 'published') {
            cms_render_post($post, $posts);
        }
    }

    // Generate blogs.js manifest at project root
    $manifestPath = dirname(__DIR__) . '/blogs.js';
    $manifestCode = 'window.blogData = ' . json_encode(array_values($posts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n";
    file_put_contents($manifestPath, $manifestCode, LOCK_EX);
}

$message = '';
$error = '';

// Login handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (hash('sha256', (string) ($_POST['password'] ?? '')) === TL_CMS_PASSWORD_HASH) {
        $_SESSION['tl_cms_logged_in'] = true;
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid password.';
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Save post
if (cms_is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    try {
        $posts = cms_read_posts();
        $originalSlug = cms_slugify((string) ($_POST['original_slug'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') throw new RuntimeException('Title is required.');

        $slug = cms_slugify((string) ($_POST['slug'] ?: $title));
        $uploaded = cms_upload_image();

        $post = [
            'title' => $title,
            'slug' => $slug,
            'date' => (string) ($_POST['date'] ?: date('Y-m-d')),
            'category' => trim((string) ($_POST['category'] ?: 'Ideas')),
            'status' => (string) ($_POST['status'] ?: 'published'),
            'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
            'meta_description' => trim((string) ($_POST['meta_description'] ?? '')),
            'image' => $uploaded ?: trim((string) ($_POST['image'] ?? '')),
            'image_alt' => trim((string) ($_POST['image_alt'] ?? '')),
            'body' => trim((string) $_POST['body'] ?? ''),
            'updated_at' => date('c'),
        ];

        $found = false;
        foreach ($posts as $key => $existing) {
            if (($existing['slug'] ?? '') === $originalSlug) {
                $posts[$key] = array_merge($existing, $post);
                $found = true;
                break;
            }
        }
        if (!$found) $posts[] = $post;

        cms_write_posts($posts);
        cms_publish_all($posts);
        $message = 'Blog post saved and public pages regenerated.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Delete via query
if (cms_is_logged_in() && isset($_GET['delete'])) {
    $deleteSlug = cms_slugify((string) $_GET['delete']);
    $posts = array_values(array_filter(cms_read_posts(), static fn ($post) => ($post['slug'] ?? '') !== $deleteSlug));
    cms_write_posts($posts);
    cms_publish_all($posts);
    $message = 'Post removed from CMS and blog index regenerated.';
}

$posts = cms_read_posts();
$editSlug = cms_slugify((string) ($_GET['edit'] ?? ''));
$edit = [
    'title' => '', 'slug' => '', 'date' => date('Y-m-d'), 'category' => 'Ideas', 'status' => 'published',
    'excerpt' => '', 'meta_description' => '', 'image' => TL_CMS_DEFAULT_IMAGE, 'image_alt' => '', 'body' => '',
];
foreach ($posts as $post) {
    if (($post['slug'] ?? '') === $editSlug) { $edit = array_merge($edit, $post); break; }
}

// Render simple CMS UI
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(TL_CMS_SITE_NAME) ?></title>
    <style>body{font-family:Arial,Helvetica,sans-serif;background:#f7f7f7} .wrap{max-width:980px;margin:24px auto;padding:0 16px} .panel{background:#fff;padding:18px;border:1px solid #e6e6e6;margin-bottom:18px} .field{margin-bottom:10px} label{display:block;font-weight:600;margin-bottom:6px} input[type=text],input[type=date],textarea{width:100%;padding:8px;border:1px solid #ddd} .posts .post{padding:10px;border-bottom:1px solid #eee} .btn{display:inline-block;padding:8px 12px;background:#222;color:#fff;text-decoration:none;border-radius:4px} .notice{background:#e6ffed;padding:10px;border:1px solid #bfeac8} .error{background:#ffecec;padding:10px;border:1px solid #f1b7b7}</style>
</head>
<body>
<div class="wrap">
<?php if (!cms_is_logged_in()): ?>
    <main class="panel">
        <form method="post">
            <input type="hidden" name="action" value="login">
            <h2>Blog CMS Login</h2>
            <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
            <div class="field"><label>Password</label><input type="password" name="password" required autofocus></div>
            <button class="btn" type="submit">Login</button>
        </form>
    </main>
<?php else: ?>
    <header class="panel"><div style="display:flex;justify-content:space-between;align-items:center"><h1>Blog CMS</h1><div><a class="btn" href="?logout=1">Logout</a> <a class="btn" href="?">New</a></div></div></header>
    <main class="grid">
        <?php if ($message): ?><div class="notice"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <div class="panel">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="original_slug" value="<?= h((string) $edit['slug']) ?>">
                <div class="field"><label>Title</label><input name="title" value="<?= h((string) $edit['title']) ?>" required></div>
                <div class="field"><label>Slug</label><input name="slug" value="<?= h((string) $edit['slug']) ?>" placeholder="auto-from-title"></div>
                <div class="field"><label>Date</label><input type="date" name="date" value="<?= h((string) $edit['date']) ?>"></div>
                <div class="field"><label>Category</label><input name="category" value="<?= h((string) $edit['category']) ?>"></div>
                <div class="field"><label>Status</label><select name="status"><option value="published" <?= $edit['status'] === 'published' ? 'selected' : '' ?>>Published</option><option value="draft" <?= $edit['status'] === 'draft' ? 'selected' : '' ?>>Draft</option></select></div>
                <div class="field"><label>Excerpt</label><textarea name="excerpt" style="min-height:90px"><?= h((string) $edit['excerpt']) ?></textarea></div>
                <div class="field"><label>Meta Description</label><textarea name="meta_description" style="min-height:80px"><?= h((string) $edit['meta_description']) ?></textarea></div>
                <div class="field"><label>Existing Image Path</label><input name="image" value="<?= h((string) $edit['image']) ?>" placeholder="../assets/blog/example.webp"></div>
                <div class="field"><label>Upload New Image</label><input type="file" name="image_upload" accept=".jpg,.jpeg,.png,.webp"></div>
                <div class="field"><label>Image Alt Text</label><input name="image_alt" value="<?= h((string) $edit['image_alt']) ?>"></div>
                <div class="field"><label>Article Body</label><textarea name="body" style="min-height:200px" placeholder="<p>Your intro...</p>"><?= h((string) $edit['body']) ?></textarea></div>
                <button class="btn" type="submit">Save and Publish</button>
            </form>
        </div>
        <aside class="panel posts" style="margin-top:0">
            <h3>Posts</h3>
            <?php foreach ($posts as $post): ?>
                <div class="post">
                    <strong><?= h((string) $post['title']) ?></strong>
                    <div style="font-size:12px;color:#666"><?= h(cms_format_date((string) $post['date'])) ?> / <?= h((string) $post['category']) ?> / <?= h((string) $post['status']) ?></div>
                    <div class="actions" style="margin-top:6px"><a href="?edit=<?= h((string) $post['slug']) ?>">Edit</a> | <a href="<?= h((string) TL_CMS_BLOG_DIR . '/' . $post['slug'] . '.html') ?>" target="_blank">View</a> | <a href="?delete=<?= h((string) $post['slug']) ?>" onclick="return confirm('Delete this post?')">Delete</a></div>
                </div>
            <?php endforeach; ?>
        </aside>
    </main>
<?php endif; ?>
</div>
</body>
</html>
