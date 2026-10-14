<?php
// Dosya yükleme (yalnızca yönetici). Gövde: dosyanın kendisi; ?type= içerik türü.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('method', 'Yalnızca POST.', 405);
require_same_origin();
try {
    ensure_schema();
    $u = current_user();
    if (!is_admin($u)) fail('not_granted', 'Dosya yüklemek için yönetici olarak giriş yapın.', 403);

    $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf',
        'text/csv', 'text/plain', 'application/json', 'text/markdown'];
    $type = strtolower(trim((string)($_GET['type'] ?? ($_SERVER['CONTENT_TYPE'] ?? ''))));
    if (!in_array($type, $allowed, true)) fail('unsupported_type', 'Bu dosya türü yüklenemez.', 415);
    $max = $type === 'image/svg+xml' ? 2097152 : ASSET_MAX_BYTES;
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $max) fail('too_large', 'Dosya çok büyük.', 413);
    $body = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($body === false || $body === '') fail('invalid_request', 'Dosya boş.');
    if (strlen($body) > $max) fail('too_large', 'Dosya çok büyük.', 413);

    // İçerik türü denetimi: görseller gerçekten görsel olmalı; SVG'de betik olmamalı
    if (str_starts_with($type, 'image/') && $type !== 'image/svg+xml') {
        $info = @getimagesizefromstring($body);
        $map = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];
        if (!$info || ($map[$info[2]] ?? '') !== $type) fail('unsupported_type', 'Görsel dosyası okunamadı.', 415);
    }
    if ($type === 'image/svg+xml') {
        if (!preg_match('/<svg[\s>]/i', $body) || preg_match('/<script|on[a-z]+\s*=|javascript:|<foreignObject|<iframe|<embed|<object/i', $body))
            fail('unsupported_type', 'SVG dosyası güvenli değil.', 415);
    }
    if ($type === 'application/pdf' && !str_starts_with($body, '%PDF')) fail('unsupported_type', 'PDF dosyası okunamadı.', 415);
    if (in_array($type, ['text/csv', 'text/plain', 'application/json', 'text/markdown'], true) && !mb_check_encoding($body, 'UTF-8'))
        fail('invalid_request', 'Metin dosyası UTF-8 olmalı.');

    $id = bin2hex(random_bytes(16));
    $dir = storage_dir('blobs');
    if (file_put_contents($dir . '/' . $id, $body, LOCK_EX) === false) fail('store_unavailable', 'Dosya kaydedilemedi.', 503);
    db()->prepare('INSERT INTO assets (id, type, size, created_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$id, $type, strlen($body), $u['uid']]);
    out(['id' => $id, 'url' => '/_blob/' . $id, 'sizeBytes' => strlen($body), 'contentType' => $type]);
} catch (PDOException $e) {
    error_log('PLC asset DB: ' . $e->getMessage());
    fail('store_unavailable', 'Veritabanına ulaşılamadı.', 503);
}
