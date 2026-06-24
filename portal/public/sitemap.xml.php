<?php

declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
$base = $host !== '' ? $scheme . '://' . $host : '';

$paths = [
    '/index.php',
    '/store.php',
    '/about.php',
    '/login.php',
    '/register.php',
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($paths as $path): ?>
  <url>
    <loc><?= htmlspecialchars($base . $path, ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></loc>
    <changefreq>daily</changefreq>
    <priority><?= $path === '/index.php' ? '1.0' : '0.8' ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
