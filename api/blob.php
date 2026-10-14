<?php
// Yüklenen dosyaları sunar: /_blob/<id> adresi .htaccess ile buraya yönlenir.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $id)) { http_response_code(404); exit; }
try {
    $st = db()->prepare('SELECT type, size FROM assets WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
} catch (PDOException $e) { http_response_code(503); exit; }
$f = __DIR__ . '/storage/blobs/' . $id;
if (!$r || !is_file($f)) { http_response_code(404); exit; }
// Excel kopyası gibi metin dosyaları yalnızca yöneticilere açık; görseller ve katalog JSON herkese
if (in_array($r['type'], ['text/plain', 'text/csv', 'text/markdown'], true)) {
    try { $u = current_user(); } catch (PDOException $e) { $u = null; }
    if (!is_admin($u)) { http_response_code(404); exit; }
    $private = true;
}

$etag = '"' . $id . '"';
header('Content-Type: ' . $r['type']);
header('X-Content-Type-Options: nosniff');
header(!empty($private) ? 'Cache-Control: private, no-store' : 'Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
if ($r['type'] === 'image/svg+xml') header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . filesize($f));
readfile($f);
