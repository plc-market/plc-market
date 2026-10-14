<?php
// Kurulum: veritabanı tablolarını oluşturur ve yönetici hesaplarını açar.
// ADMIN_SETUP_TOKEN (GitHub Secrets) ile korunur; token boşsa sayfa kapalıdır.
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");

$token = (string)cfg('setup_token', '');
$msg = ''; $ok = false;
if ($token === '' || strlen($token) < 20) {
    $msg = 'Kurulum kapalı. GitHub Secrets\'ta en az 20 karakterlik ADMIN_SETUP_TOKEN tanımlı olmalı.';
} elseif (!cfg('db_name')) {
    $msg = 'Veritabanı bilgileri eksik. GitHub Secrets\'ta DB_HOST, DB_NAME, DB_USER ve DB_PASS tanımlı olmalı.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        migrate();
        $key = 'setup:' . client_ip();
        if (rate_count($key, 3600) >= 5) {
            $msg = 'Çok fazla hatalı deneme. Bir saat sonra tekrar deneyin.';
        } elseif (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
            rate_hit($key);
            $msg = 'Kurulum anahtarı hatalı.';
        } else {
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $pass = (string)($_POST['pass'] ?? '');
            $pass2 = (string)($_POST['pass2'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $msg = 'E-posta adresi geçerli değil.';
            elseif (mb_strlen($pass) < 10) $msg = 'Yönetici şifresi en az 10 karakter olmalı.';
            elseif ($pass !== $pass2) $msg = 'Şifreler aynı değil.';
            else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $st = db()->prepare('SELECT uid FROM users WHERE email = ?'); $st->execute([$email]);
                if ($r = $st->fetch()) {
                    db()->prepare('UPDATE users SET role = "admin", status = "aktif", pass_hash = ? WHERE uid = ?')->execute([$hash, $r['uid']]);
                } else {
                    db()->prepare('INSERT INTO users (uid, email, pass_hash, role, status, created_at) VALUES (?, ?, ?, "admin", "aktif", UTC_TIMESTAMP())')
                        ->execute([new_uid(), $email, $hash]);
                }
                $ok = true;
                $msg = $email . ' yönetici olarak hazır. Sitede Hesabım › Giriş yap bölümünden bu e-posta ve şifreyle giriş yapabilirsiniz.';
            }
        }
    } catch (PDOException $e) {
        error_log('PLC kurulum: ' . $e->getMessage());
        $msg = 'Veritabanına bağlanılamadı. DB_HOST, DB_NAME, DB_USER ve DB_PASS değerlerini kontrol edin.';
    }
}
$admins = [];
if (cfg('db_name') && $token !== '') {
    try { migrate(); $admins = db()->query('SELECT email FROM users WHERE role = "admin" ORDER BY created_at')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>PLC Market kurulum</title>
<style>
body{margin:0;background:#E6EAE9;color:#16202A;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Arial,sans-serif}
main{max-width:520px;margin:40px auto;padding:0 16px}
.card{background:#fff;border:1px solid #C6CED2;border-radius:6px;padding:22px;display:flex;flex-direction:column;gap:14px}
h1{font-size:22px;margin:0}p{margin:0;color:#54616D}
label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:#54616D}
input{min-height:40px;padding:8px 10px;border:1px solid #C6CED2;border-radius:4px;font:inherit;color:#16202A}
button{min-height:42px;border:0;border-radius:4px;background:#0F4C81;color:#fff;font:600 15px system-ui,Arial,sans-serif;cursor:pointer}
.msg{padding:10px 12px;border-radius:4px;background:#FCEBD2;color:#7A4A00}.msg.ok{background:#DDF2E3;color:#1E6B34}
ul{margin:0;padding-left:18px}
</style></head><body><main><div class="card">
<h1>PLC Market kurulum</h1>
<p>Yönetici hesabı oluşturur ya da var olan bir hesabı yönetici yapar. Kurulum anahtarı, GitHub Secrets'taki ADMIN_SETUP_TOKEN değeridir.</p>
<?php if ($msg !== ''): ?><div class="msg<?= $ok ? ' ok' : '' ?>"><?= $h($msg) ?></div><?php endif; ?>
<?php if ($admins): ?><p>Mevcut yöneticiler:</p><ul><?php foreach ($admins as $a): ?><li><?= $h($a) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($token !== '' && strlen($token) >= 20 && cfg('db_name')): ?>
<form method="post" autocomplete="off">
<div style="display:flex;flex-direction:column;gap:12px">
<label>Kurulum anahtarı<input type="password" name="token" required></label>
<label>Yönetici e-postası<input type="email" name="email" required placeholder="ad.soyad@plc-market.com.tr"></label>
<label>Şifre (en az 10 karakter)<input type="password" name="pass" required minlength="10" autocomplete="new-password"></label>
<label>Şifre tekrar<input type="password" name="pass2" required minlength="10" autocomplete="new-password"></label>
<button type="submit">Yöneticiyi kaydet</button>
</div></form>
<p>Yöneticiler oluşturulduktan sonra GitHub'daki ADMIN_SETUP_TOKEN değerini silerseniz bu sayfa kapanır.</p>
<?php endif; ?>
</div></main></body></html>
