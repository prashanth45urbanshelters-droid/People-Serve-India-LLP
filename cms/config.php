<?php
declare(strict_types=1);

// Basic site settings for the CMS
define('TL_CMS_SITE_NAME', 'People Serve Blog CMS');

// Where CMS stores its JSON data (posts list)
define('TL_CMS_DATA_FILE', dirname(__DIR__) . '/cms-data/posts.json');

// Where generated blog HTML pages will be placed (existing `blogs` folder)
define('TL_CMS_BLOG_DIR', dirname(__DIR__) . '/blogs');

// Upload directory for blog images (inside assets)
define('TL_CMS_UPLOAD_DIR', dirname(__DIR__) . '/assets/blog');
define('TL_CMS_UPLOAD_WEB_PATH', '../assets/blog/');

// Default image to use when a post has no image
define('TL_CMS_DEFAULT_IMAGE', '../assets/livingroom-1.png');

/*
 * Password handling:
 * Set TIMBERLANE_CMS_PASSWORD_HASH in the server environment to the sha256
 * hash of your admin password. If not set, a default hash is provided for
 * initial setup (change it immediately after deployment).
 */
define('TL_CMS_PASSWORD_HASH', getenv('TIMBERLANE_CMS_PASSWORD_HASH') ?: 'bea7e48ba22727f62109924c9034243ab64489612134a56aed58ca6b3564a9af');

// Session name and start
session_name('timberlane_cms');
session_start();

// Ensure data folders exist
if (!is_dir(dirname(TL_CMS_DATA_FILE))) {
    mkdir(dirname(TL_CMS_DATA_FILE), 0755, true);
}

if (!is_dir(TL_CMS_BLOG_DIR)) {
    mkdir(TL_CMS_BLOG_DIR, 0755, true);
}

if (!is_dir(TL_CMS_UPLOAD_DIR)) {
    mkdir(TL_CMS_UPLOAD_DIR, 0755, true);
}

?>
