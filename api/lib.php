<?php
// PLC Market sunucu katmanı: ortak işlevler.
// Bu dosya doğrudan çağrılmaz; v1.php, asset.php, blob.php ve kurulum.php tarafından yüklenir.
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'lib.php') { http_response_code(404); exit; }

const SCHEMA_VERSION = 1;
const DOC_MAX_BYTES = 262144;      // belge başına 256 KB
const ASSET_MAX_BYTES = 20971520;  // dosya başına 20 MB
const KDV = 0.20;
const FREE_SHIP = 100;             // KDV dahil bu tutarın üstünde kargo ücretsiz (EUR)
const SHIP = 3;                    // kargo (EUR)
const RATE0 = 55.2121;             // kayıtlı kur yoksa kullanılan EUR/TL

function cfg(string $k, $def = null) {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        $c = is_file($f) ? (require $f) : [];
        if (!is_array($c)) $c = [];
    }
    return $c[$k] ?? $def;
}

function storage_dir(string $sub = ''): string {
    $d = __DIR__ . '/storage' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d)) @mkdir($d, 0750, true);
    $ht = __DIR__ . '/storage/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
    return $d;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = (string)cfg('db_host', 'localhost');
    $port = '';
    if (preg_match('/^(.+):(\d+)$/', $host, $m)) { $host = $m[1]; $port = ';port=' . $m[2]; }
    $dsn = 'mysql:host=' . $host . $port . ';dbname=' . cfg('db_name', '') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)cfg('db_user', ''), (string)cfg('db_pass', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function migrate(): void {
    $p = db();
    $p->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uid CHAR(16) NOT NULL UNIQUE,
        email VARCHAR(190) NOT NULL UNIQUE,
        pass_hash VARCHAR(255) NOT NULL,
        role VARCHAR(10) NOT NULL DEFAULT 'uye',
        status VARCHAR(10) NOT NULL DEFAULT 'aktif',
        created_at DATETIME NOT NULL,
        last_login DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $p->exec("CREATE TABLE IF NOT EXISTS docs (
        h CHAR(40) NOT NULL PRIMARY KEY,
        parent_h CHAR(40) NOT NULL,
        path VARCHAR(1000) NOT NULL,
        data LONGTEXT NOT NULL,
        version INT NOT NULL DEFAULT 1,
        owner CHAR(16) NULL,
        updated_at DATETIME(3) NOT NULL,
        INDEX (parent_h)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $p->exec("CREATE TABLE IF NOT EXISTS assets (
        id CHAR(32) NOT NULL PRIMARY KEY,
        type VARCHAR(100) NOT NULL,
        size INT NOT NULL,
        created_by CHAR(16) NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $p->exec("CREATE TABLE IF NOT EXISTS hits (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        k VARCHAR(200) NOT NULL,
        at DATETIME NOT NULL,
        INDEX (k, at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    @file_put_contents(storage_dir() . '/schema-v' . SCHEMA_VERSION . '.ok', gmdate('c'));
}

function ensure_schema(): void {
    if (!is_file(storage_dir() . '/schema-v' . SCHEMA_VERSION . '.ok')) migrate();
}

// ---------- istek / yanıt ----------
function out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $code, string $msg = '', int $http = 400): void {
    out(['error' => ['code' => $code, 'message' => $msg]], $http);
}
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}
// Yalnızca kendi sayfamızdan gelen istekler: özel başlık + aynı köken
function require_same_origin(): void {
    if (($_SERVER['HTTP_X_PLC'] ?? '') !== '1') fail('forbidden', 'Geçersiz istek.', 403);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $oh = parse_url($origin, PHP_URL_HOST) . ((parse_url($origin, PHP_URL_PORT)) ? ':' . parse_url($origin, PHP_URL_PORT) : '');
        if (strcasecmp($oh, $host) !== 0) fail('forbidden', 'Geçersiz köken.', 403);
    }
}
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 45); }

// ---------- oturum ----------
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('PLCSESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '43200');
    session_start();
}
function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    start_session();
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT uid, email, role, status FROM users WHERE uid = ?');
        $st->execute([$_SESSION['uid']]);
        $r = $st->fetch();
        if ($r && $r['status'] === 'aktif') $u = $r; else unset($_SESSION['uid']);
    }
    return $u;
}
function is_admin(?array $u): bool { return $u !== null && $u['role'] === 'admin'; }

// Basit hız sınırı: $k anahtarıyla son $sec saniyede $max denemeden fazlası reddedilir
function rate_hit(string $k): void { db()->prepare('INSERT INTO hits (k, at) VALUES (?, UTC_TIMESTAMP())')->execute([$k]); }
function rate_count(string $k, int $sec): int {
    $st = db()->prepare('SELECT COUNT(*) c FROM hits WHERE k = ? AND at > (UTC_TIMESTAMP() - INTERVAL ? SECOND)');
    $st->execute([$k, $sec]);
    return (int)$st->fetch()['c'];
}
function rate_limit(string $k, int $max, int $sec): void {
    if (rate_count($k, $sec) >= $max) fail('rate_limited', 'Çok fazla deneme. Biraz bekleyip tekrar deneyin.', 429);
    if (random_int(1, 200) === 1) db()->exec('DELETE FROM hits WHERE at < (UTC_TIMESTAMP() - INTERVAL 2 DAY)');
}
function new_uid(): string { return bin2hex(random_bytes(8)); }

// ---------- belge yolları ve erişim kuralları ----------
function seg_ok(string $s): bool { return $s !== '.' && $s !== '..' && strlen($s) <= 200 && preg_match('/^[A-Za-z0-9_\-.~:@+]+$/', $s) === 1; }
function split_path(string $path): array {
    if ($path === '' || strlen($path) > 1000) fail('invalid_argument', 'Geçersiz yol.');
    $parts = explode('/', $path);
    if (count($parts) > 16) fail('invalid_argument', 'Geçersiz yol.');
    foreach ($parts as $s) if (!seg_ok($s)) fail('invalid_argument', 'Geçersiz yol.');
    return $parts;
}
function hpath(string $p): string { return sha1($p); }
function parent_of(string $path): string { $i = strrpos($path, '/'); return $i === false ? '' : substr($path, 0, $i); }

// Okuma kuralı. $parts: belge ya da koleksiyon yolu parçaları
function can_read(array $parts, ?array $u): bool {
    $top = $parts[0];
    if ($top === 'katalog' || $top === 'resimler') return true;
    if ($top === 'uyeler') {
        if (count($parts) === 1) return is_admin($u);
        return is_admin($u) || ($u !== null && $parts[1] === $u['uid']);
    }
    if ($top === 'talepler' || $top === 'takip') return is_admin($u);
    return false;
}
// Yazma kuralı. $op: set | update | delete; $exists: belge şu an var mı
function can_write(string $op, array $parts, ?array $u, bool $exists): bool {
    $n = count($parts);
    if ($n % 2 !== 0) return false;
    $top = $parts[0];
    if ($top === 'katalog' || $top === 'resimler' || $top === 'takip') return is_admin($u);
    if ($top === 'uyeler') {
        if (is_admin($u)) return true;
        if ($u === null || $parts[1] !== $u['uid']) return false;
        // Üye kendi siparişini yalnızca oluşturabilir; durumunu yönetim değiştirir
        if ($n >= 3 && $parts[2] === 'siparisler') return $op === 'set' && !$exists && $n === 4;
        return true;
    }
    if ($top === 'talepler') {
        if (is_admin($u)) return true;
        return $op === 'set' && !$exists && $n === 2;
    }
    return false;
}

function doc_get(string $path): ?array {
    $st = db()->prepare('SELECT data, version, updated_at FROM docs WHERE h = ?');
    $st->execute([hpath($path)]);
    $r = $st->fetch();
    if (!$r) return null;
    return ['data' => json_decode($r['data'], true), 'version' => (int)$r['version'], 'updated' => $r['updated_at']];
}
function doc_put(string $path, array $data, ?string $owner): int {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > DOC_MAX_BYTES) fail('invalid_argument', 'Belge çok büyük.');
    $p = db();
    $st = $p->prepare('INSERT INTO docs (h, parent_h, path, data, version, owner, updated_at) VALUES (?, ?, ?, ?, 1, ?, UTC_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE data = VALUES(data), version = version + 1, updated_at = UTC_TIMESTAMP(3)');
    $st->execute([hpath($path), hpath(parent_of($path)), $path, $json, $owner]);
    $v = $p->prepare('SELECT version FROM docs WHERE h = ?');
    $v->execute([hpath($path)]);
    return (int)$v->fetch()['version'];
}
function doc_del(string $path): void { db()->prepare('DELETE FROM docs WHERE h = ?')->execute([hpath($path)]); }
function col_list(string $path): array {
    $st = db()->prepare('SELECT path, data, version FROM docs WHERE parent_h = ? ORDER BY path');
    $st->execute([hpath($path)]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = ['id' => substr($r['path'], strrpos($r['path'], '/') + 1), 'data' => json_decode($r['data'], true), 'version' => (int)$r['version']];
    }
    return $out;
}
function col_sig(string $path): string {
    $st = db()->prepare('SELECT COUNT(*) c, COALESCE(SUM(version),0) s, COALESCE(MAX(updated_at),"") m FROM docs WHERE parent_h = ?');
    $st->execute([hpath($path)]);
    $r = $st->fetch();
    return $r['c'] . '.' . $r['s'] . '.' . $r['m'];
}
// İç içe nesneleri birleştir; diziler ve diğer değerler olduğu gibi değişir
function deep_merge(array $a, array $b): array {
    foreach ($b as $k => $v) {
        if (is_array($v) && !array_is_list($v) && isset($a[$k]) && is_array($a[$k]) && !array_is_list($a[$k])) $a[$k] = deep_merge($a[$k], $v);
        else $a[$k] = $v;
    }
    return $a;
}
if (!function_exists('array_is_list')) {
    function array_is_list(array $a): bool { $i = 0; foreach ($a as $k => $_) { if ($k !== $i++) return false; } return true; }
}

// ---------- katalog ve sipariş doğrulama ----------
function numv($v): float {
    if (is_int($v) || is_float($v)) return (float)$v;
    $t = preg_replace('/[€\s]/u', '', (string)$v);
    if ($t === '') return NAN;
    if (preg_match('/,\d{1,2}$/', $t)) $t = str_replace(',', '.', str_replace('.', '', $t)); else $t = str_replace(',', '', $t);
    return is_numeric($t) ? (float)$t : NAN;
}
// Yayındaki katalog: yönetimden yüklenen son Excel, yoksa sitedeki varsayılan katalog
function catalog(): array {
    static $by = null;
    if ($by !== null) return $by;
    $rows = null;
    $akt = doc_get('katalog/aktif');
    if ($akt && !empty($akt['data']['json']) && preg_match('/^[a-f0-9]{32}$/', (string)$akt['data']['json'])) {
        $f = storage_dir('blobs') . '/' . $akt['data']['json'];
        if (is_file($f)) $rows = json_decode((string)file_get_contents($f), true);
    }
    if (!is_array($rows)) {
        $f = __DIR__ . '/data/katalog.json';
        $rows = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    }
    $by = [];
    foreach ((array)$rows as $r) {
        $id = trim((string)($r['s'] ?? ''));
        $f = numv($r['f'] ?? '');
        if ($id === '' || isset($by[$id]) || !($f > 0)) continue;
        $k = array_key_exists('k', $r) ? numv($r['k']) : NAN;
        $by[$id] = ['n' => preg_replace('/\s+/', ' ', trim((string)($r['n'] ?? $id))), 'p' => round($f, 2), 'k' => is_nan($k) ? null : max(0, (int)floor($k))];
    }
    return $by;
}
function tier(int $q): float { return $q >= 25 ? 0.08 : ($q >= 10 ? 0.05 : ($q >= 5 ? 0.03 : 0.0)); }
function current_rate(): float {
    $d = doc_get('katalog/ayarlar');
    $k = $d ? (float)($d['data']['kur'] ?? 0) : 0;
    return $k >= 1 ? $k : RATE0;
}
// Sipariş ya da teklif belgesini sunucuda yeniden hesaplar: fiyat, iskonto, KDV, kargo, kur, durum
function normalize_order(array $o, array $parts): array {
    $cat = catalog();
    $lines = [];
    $sub = 0.0; $n = 0;
    foreach ((array)($o['kalemler'] ?? []) as $r) {
        $id = (string)($r['s'] ?? '');
        $q = (int)floor((float)($r['q'] ?? 0));
        if ($id === '' || $q < 1 || $q > 9999 || !isset($cat[$id]) || count($lines) >= 500) continue;
        $p = $cat[$id];
        $u = round($p['p'] * (1 - tier($q)), 2);
        $t = round($p['p'] * (1 - tier($q)) * $q, 2);
        $lines[] = ['s' => $id, 'n' => $p['n'], 'q' => $q, 'u' => $u, 't' => round($u * $q, 2), 'stok' => $p['k']];
        $sub += $t; $n += $q;
    }
    if (!$lines) fail('invalid_argument', 'Siparişte geçerli ürün yok.');
    $sub = round($sub, 2);
    $kdv = round($sub * KDV, 2);
    $gross = $sub + $kdv;
    $ship = ($n === 0 || $gross >= FREE_SHIP) ? 0 : SHIP;
    $total = round($gross + $ship, 2);
    $rate = current_rate();
    $quote = (($o['tur'] ?? '') === 'teklif');
    $clean = fn($v, $l = 500) => mb_substr(trim((string)$v), 0, $l);
    $tes = (array)($o['teslimat'] ?? []);
    $res = [
        'no' => end($parts),
        'tur' => $quote ? 'teklif' : 'siparis',
        'tarih' => gmdate('Y-m-d\TH:i:s.v\Z'),
        'durum' => $quote ? 'Teklif bekliyor' : 'Alındı',
        'proje' => isset($o['proje']) && $o['proje'] !== null ? $clean($o['proje'], 120) : null,
        'ad' => $clean($o['ad'] ?? '', 120),
        'odeme' => $quote ? '' : $clean($o['odeme'] ?? '', 40),
        'kalemler' => $lines,
        'ara' => $sub, 'kdv' => $kdv, 'kargo' => $ship, 'toplam' => $total,
        'kur' => $rate, 'tl' => round($total * $rate, 2),
        'teslimat' => [
            'ad' => $clean($tes['ad'] ?? '', 120), 'firma' => $clean($tes['firma'] ?? '', 160), 'tel' => $clean($tes['tel'] ?? '', 40),
            'eposta' => $clean($tes['eposta'] ?? '', 160), 'adres' => $clean($tes['adres'] ?? '', 500), 'il' => $clean($tes['il'] ?? '', 120),
            'vergi' => $clean($tes['vergi'] ?? '', 80),
        ],
        'not' => $clean($o['not'] ?? '', 2000),
        'fiyatKontrol' => 'sunucu',
    ];
    if (isset($o['uye']) && is_array($o['uye'])) $res['uye'] = ['firma' => $clean($o['uye']['firma'] ?? '', 160), 'yetkili' => $clean($o['uye']['yetkili'] ?? '', 120)];
    return $res;
}
function is_order_path(array $parts): bool {
    return (count($parts) === 4 && $parts[0] === 'uyeler' && $parts[2] === 'siparisler') || (count($parts) === 2 && $parts[0] === 'talepler');
}
