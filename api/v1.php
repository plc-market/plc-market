<?php
// PLC Market API: oturum, belge deposu ve yönetim işlemleri. Tüm istekler POST + JSON.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('method', 'Yalnızca POST.', 405);
require_same_origin();
if (!cfg('db_name')) fail('not_configured', 'Sunucu henüz yapılandırılmadı.', 503);

try {
    ensure_schema();
    $raw = file_get_contents('php://input', false, null, 0, 2 * DOC_MAX_BYTES + 65536);
    $in = json_decode((string)$raw, true);
    if (!is_array($in) || !isset($in['op'])) fail('invalid_argument', 'Geçersiz istek.');
    $op = (string)$in['op'];
    $u = current_user();

    switch ($op) {
    // ---------- oturum ----------
    case 'auth.me':
        out(['user' => $u ? ['uid' => $u['uid'], 'email' => $u['email'], 'admin' => is_admin($u)] : null]);

    case 'auth.register': {
        $email = mb_strtolower(trim((string)($in['email'] ?? '')));
        $pass = (string)($in['pass'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) fail('invalid_email', 'E-posta adresi geçerli görünmüyor.');
        if (mb_strlen($pass) < 8) fail('weak_password', 'Şifre en az 8 karakter olmalı.');
        rate_limit('reg:' . client_ip(), 10, 3600);
        rate_hit('reg:' . client_ip());
        $st = db()->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) fail('email_taken', 'Bu e-posta adresiyle zaten bir hesap var. Giriş yapın.');
        $uid = new_uid();
        db()->prepare('INSERT INTO users (uid, email, pass_hash, role, status, created_at, last_login) VALUES (?, ?, ?, "uye", "aktif", UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$uid, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        start_session(); session_regenerate_id(true); $_SESSION['uid'] = $uid;
        out(['user' => ['uid' => $uid, 'email' => $email, 'admin' => false]]);
    }

    case 'auth.login': {
        $email = mb_strtolower(trim((string)($in['email'] ?? '')));
        $pass = (string)($in['pass'] ?? '');
        $key = 'login:' . sha1($email) . ':' . client_ip();
        rate_limit($key, 5, 900);
        rate_limit('loginip:' . client_ip(), 30, 900);
        $st = db()->prepare('SELECT uid, email, pass_hash, role, status FROM users WHERE email = ?');
        $st->execute([$email]);
        $r = $st->fetch();
        if (!$r || !password_verify($pass, $r['pass_hash'])) {
            rate_hit($key); rate_hit('loginip:' . client_ip());
            fail('bad_login', 'E-posta ya da şifre hatalı.', 401);
        }
        if ($r['status'] !== 'aktif') fail('inactive', 'Hesabınız pasif. Bizimle iletişime geçin.', 403);
        if (password_needs_rehash($r['pass_hash'], PASSWORD_DEFAULT))
            db()->prepare('UPDATE users SET pass_hash = ? WHERE uid = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $r['uid']]);
        db()->prepare('UPDATE users SET last_login = UTC_TIMESTAMP() WHERE uid = ?')->execute([$r['uid']]);
        start_session(); session_regenerate_id(true); $_SESSION['uid'] = $r['uid'];
        out(['user' => ['uid' => $r['uid'], 'email' => $r['email'], 'admin' => $r['role'] === 'admin']]);
    }

    case 'auth.logout':
        start_session(); $_SESSION = []; session_destroy();
        out(['ok' => true]);

    case 'auth.passwd': {
        if (!$u) fail('unauthenticated', 'Oturum açın.', 401);
        $old = (string)($in['old'] ?? ''); $new = (string)($in['new'] ?? '');
        if (mb_strlen($new) < 8) fail('weak_password', 'Yeni şifre en az 8 karakter olmalı.');
        rate_limit('pw:' . $u['uid'], 10, 3600); rate_hit('pw:' . $u['uid']);
        $st = db()->prepare('SELECT pass_hash FROM users WHERE uid = ?'); $st->execute([$u['uid']]);
        if (!password_verify($old, $st->fetch()['pass_hash'])) fail('bad_login', 'Mevcut şifre hatalı.', 401);
        db()->prepare('UPDATE users SET pass_hash = ? WHERE uid = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['uid']]);
        out(['ok' => true]);
    }

    case 'admin.resetpw': {
        if (!is_admin($u)) fail('permission_denied', 'Yetkiniz yok.', 403);
        $uid = (string)($in['uid'] ?? '');
        $alpha = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $tmp = ''; for ($i = 0; $i < 12; $i++) $tmp .= $alpha[random_int(0, strlen($alpha) - 1)];
        $st = db()->prepare('UPDATE users SET pass_hash = ? WHERE uid = ? AND role = "uye"');
        $st->execute([password_hash($tmp, PASSWORD_DEFAULT), $uid]);
        if ($st->rowCount() < 1) fail('not_found', 'Üye bulunamadı.', 404);
        out(['temp' => $tmp]);
    }

    case 'admin.defaultxlsx': {
        if (!is_admin($u)) fail('permission_denied', 'Yetkiniz yok.', 403);
        $f = __DIR__ . '/data/katalog.xlsx.b64.txt';
        if (!is_file($f)) fail('not_found', 'Varsayılan Excel bulunamadı.', 404);
        out(['b64' => trim((string)file_get_contents($f))]);
    }

    case 'admin.users': {
        if (!is_admin($u)) fail('permission_denied', 'Yetkiniz yok.', 403);
        $rows = db()->query('SELECT uid, email, role, status, created_at, last_login FROM users ORDER BY created_at DESC')->fetchAll();
        out(['users' => $rows]);
    }

    // ---------- belge deposu ----------
    case 'db.get': {
        $path = (string)($in['path'] ?? ''); $parts = split_path($path);
        if (count($parts) % 2 !== 0) fail('invalid_argument', 'Belge yolu bekleniyor.');
        if (!can_read($parts, $u)) out(['exists' => false]);
        $d = doc_get($path);
        out($d ? ['exists' => true, 'data' => $d['data'], 'version' => $d['version']] : ['exists' => false]);
    }

    case 'db.list': {
        $path = (string)($in['path'] ?? ''); $parts = split_path($path);
        if (count($parts) % 2 !== 1) fail('invalid_argument', 'Koleksiyon yolu bekleniyor.');
        if (!can_read($parts, $u)) out(['docs' => []]);
        out(['docs' => col_list($path)]);
    }

    case 'db.set':
    case 'db.update': {
        $path = (string)($in['path'] ?? ''); $parts = split_path($path);
        if (count($parts) % 2 !== 0) fail('invalid_argument', 'Belge yolu bekleniyor.');
        $data = $in['data'] ?? null;
        if (!is_array($data) || ($data !== [] && array_is_list($data))) fail('invalid_argument', 'Belge bir nesne olmalı.');
        $cur = doc_get($path);
        $kind = $op === 'db.set' ? 'set' : 'update';
        if (!can_write($kind, $parts, $u, $cur !== null)) fail('permission_denied', 'Bu işlem için yetkiniz yok.', 403);
        if ($kind === 'update') {
            if (!$cur) fail('invalid_argument', 'Belge yok.');
            $data = deep_merge(is_array($cur['data']) ? $cur['data'] : [], $data);
        }
        if ($kind === 'set' && !$cur && is_order_path($parts)) {
            if ($parts[0] === 'talepler') { rate_limit('talep:' . client_ip(), 10, 3600); rate_hit('talep:' . client_ip()); }
            $data = normalize_order($data, $parts);
        }
        $v = doc_put($path, $data, $u['uid'] ?? null);
        out(['ok' => true, 'version' => $v, 'data' => is_order_path($parts) ? $data : null]);
    }

    case 'db.delete': {
        $path = (string)($in['path'] ?? ''); $parts = split_path($path);
        if (count($parts) % 2 !== 0) fail('invalid_argument', 'Belge yolu bekleniyor.');
        $cur = doc_get($path);
        if (!can_write('delete', $parts, $u, $cur !== null)) fail('permission_denied', 'Bu işlem için yetkiniz yok.', 403);
        if ($parts[0] === 'uyeler' && count($parts) >= 3 && $parts[2] === 'siparisler' && !is_admin($u)) fail('permission_denied', 'Siparişler silinemez.', 403);
        doc_del($path);
        out(['ok' => true]);
    }

    // Birden çok belge ve koleksiyonu tek istekte izleme: yalnızca değişenler döner
    case 'db.watch': {
        $res = ['docs' => [], 'cols' => []];
        foreach (array_slice((array)($in['docs'] ?? []), 0, 64, true) as $path => $known) {
            $path = (string)$path; $parts = split_path($path);
            if (count($parts) % 2 !== 0) continue;
            if (!can_read($parts, $u)) { if ($known !== 0) $res['docs'][$path] = ['exists' => false, 'version' => 0]; continue; }
            $d = doc_get($path);
            $v = $d ? $d['version'] : 0;
            if ((int)$known !== $v) $res['docs'][$path] = $d ? ['exists' => true, 'data' => $d['data'], 'version' => $v] : ['exists' => false, 'version' => 0];
        }
        foreach (array_slice((array)($in['cols'] ?? []), 0, 64, true) as $path => $known) {
            $path = (string)$path; $parts = split_path($path);
            if (count($parts) % 2 !== 1) continue;
            if (!can_read($parts, $u)) { if ($known !== '') $res['cols'][$path] = ['sig' => '', 'docs' => []]; continue; }
            $sig = col_sig($path);
            if ((string)$known !== $sig) $res['cols'][$path] = ['sig' => $sig, 'docs' => col_list($path)];
        }
        out($res);
    }

    // ---------- dosyalar ----------
    case 'asset.list': {
        if (!is_admin($u)) fail('not_granted', 'Yetkiniz yok.', 403);
        $rows = db()->query('SELECT id, type, size, created_at FROM assets ORDER BY created_at')->fetchAll();
        $bytes = 0; $list = [];
        foreach ($rows as $r) { $bytes += (int)$r['size']; $list[] = ['id' => $r['id'], 'url' => '/_blob/' . $r['id'], 'contentType' => $r['type'], 'sizeBytes' => (int)$r['size'], 'createdAt' => $r['created_at']]; }
        out(['assets' => $list, 'usage' => ['files' => count($list), 'bytes' => $bytes, 'maxFiles' => 0, 'maxBytes' => 0]]);
    }

    case 'asset.delete': {
        if (!is_admin($u)) fail('not_granted', 'Yetkiniz yok.', 403);
        $id = (string)($in['id'] ?? '');
        if (str_starts_with($id, '/_blob/')) $id = substr($id, 7);
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) fail('invalid_request', 'Geçersiz dosya.');
        $st = db()->prepare('DELETE FROM assets WHERE id = ?'); $st->execute([$id]);
        @unlink(storage_dir('blobs') . '/' . $id);
        out(['deleted' => $st->rowCount() > 0]);
    }

    default:
        fail('invalid_argument', 'Bilinmeyen işlem.');
    }
} catch (PDOException $e) {
    error_log('PLC API DB: ' . $e->getMessage());
    fail('unavailable', 'Veritabanına ulaşılamadı.', 503);
}
