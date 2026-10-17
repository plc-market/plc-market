# PLC Market: src/plc-market.html dosyasından İHS'ye yüklenecek siteyi üretir.
# Kullanım: python3 tools/build_site.py src/plc-market.html site
#
# İki çalışma biçimi var:
#   canlı  : DB_NAME ortam değişkeni doluysa. api/ klasörü (PHP + MySQL) ve istemci katmanı eklenir;
#            üyelik, siparişler, görseller ve yönetim paneli sitenin kendi sunucusunda çalışır.
#   demo   : DB_NAME yoksa. Statik vitrin; talepler kaydedilmez.
# Ortam değişkenleri: DB_HOST, DB_NAME, DB_USER, DB_PASS, ADMIN_SETUP_TOKEN, HTTPS_REDIRECT
import json, os, re, shutil, sys

src, outdir = sys.argv[1], sys.argv[2]
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
live = bool(os.environ.get('DB_NAME', '').strip())
h = open(src, encoding='utf-8').read()


def rep(a, b):
    global h
    assert h.count(a) == 1, (a[:70], h.count(a))
    h = h.replace(a, b)


# başlık, font ve stil bloklarını <head>'e taşı
m = re.match(r'(<title>.*?</title>\s*<link[^>]*>\s*<style>.*?</style>)', h, re.S)
assert m
head, h = m.group(1), h[m.end():]

# gömülü katalog: iç fiyatlama alanları (piyasa ortalaması, fiyat dayanağı) herkese açık sayfadan çıkarılır
mk = re.search(r'(<script type="application/json" id="katalog-data">)(.*?)(</script>)', h, re.S)
rows = json.loads(mk.group(2))
pub = [{k: v for k, v in r.items() if k not in ('m', 'b')} for r in rows]
h = h[:mk.start(2)] + json.dumps(pub, ensure_ascii=False, separators=(',', ':')) + h[mk.end(2):]

if live:
    client = open(os.path.join(root, 'src', 'site-client.js'), encoding='utf-8').read()
    rep('<script type="application/json" id="resim-data">', '<script>\n' + client + '\n</script>\n<script type="application/json" id="resim-data">')
    rep('<div class="notice" lang="tr"><div class="wrap">Önizleme sürümü. ', '<div class="notice" lang="tr"><div class="wrap">')
    rep('<div id="yon-deny" class="empty" hidden>Bu bölüm yalnızca site yöneticilerine açıktır. Yönetici olarak eklendiyseniz sayfayı kendi hesabınızla oturum açarak açın; erişim kendiliğinden tanınır.</div>',
        '<div id="yon-deny" class="empty" hidden>Bu bölüm yalnızca yöneticilere açıktır. <a class="link" href="#hesap">Hesabım › Giriş yap</a> bölümünden yönetici hesabınızla giriş yapın.</div>')
    rep('Bu önizleme sürümünde talep henüz bir sipariş sistemine iletilmiyor. Özeti kopyalayıp e-posta ya da mesajla gönderebilirsiniz.', 'Talebiniz kaydediliyor…')
    desc = 'PLC Market: Siemens otomasyon ve elektrik malzemeleri'
else:
    rep('<div class="notice" lang="tr"><div class="wrap">Önizleme sürümü.', '<div class="notice" lang="tr"><div class="wrap">Demo sürüm: siparişler henüz kaydedilmez.')
    rep('<p class="warnbox">Bu önizlemede üyelik, sayfaya oturum açarak ve sayfayı kullanma yetkisiyle erişen kişilere açıktır. Siteye bu şekilde eriştiğinizde bu bölümden üye olabilirsiniz.</p>',
        '<p class="warnbox">Üyelik, canlı sürümde açılacak. Bu demo sürümde üye kaydı alınmıyor.</p>')
    rep('note.textContent="Bu önizlemede üye olmadan verilen talepler kaydedilmez. Özeti kopyalayıp e-posta ya da mesajla gönderebilirsiniz. Üye olursanız siparişleriniz yönetim paneline iletilir ve Hesabım\'da görünür.";return}',
        'note.textContent="Bu demo sürümde talepler kaydedilmez. Özeti kopyalayıp e-posta ya da mesajla gönderebilirsiniz.";return}')
    rep('Bu önizleme sürümünde talep henüz bir sipariş sistemine iletilmiyor.', 'Bu demo sürümde talep henüz bir sipariş sistemine iletilmiyor.')
    rep('toast("Önizlemede ödeme alınmaz.', 'toast("Demo sürümde ödeme alınmaz.')
    rep('<br><a class="link" href="#yonetim" style="font-size:12.5px;color:var(--muted)">Yönetici girişi</a>', '')
    rep('<div id="yon-deny" class="empty" hidden>Bu bölüm yalnızca site yöneticilerine açıktır. Yönetici olarak eklendiyseniz sayfayı kendi hesabınızla oturum açarak açın; erişim kendiliğinden tanınır.</div>',
        '<div id="yon-deny" class="empty" hidden>Yönetim paneli demo sürümde kapalıdır.</div>')
    desc = 'PLC Market: Siemens otomasyon ve elektrik malzemeleri (demo)'

doc = ('<!doctype html>\n<html lang="tr">\n<head>\n<meta charset="utf-8">\n'
       '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">\n'
       '<meta name="robots" content="noindex, nofollow">\n'
       '<meta name="description" content="' + desc + '">\n'
       + head + '\n</head>\n<body>\n' + h + '\n</body>\n</html>\n')
os.makedirs(outdir, exist_ok=True)
open(os.path.join(outdir, 'index.html'), 'w', encoding='utf-8').write(doc)

# Yayın öncesi güvenlik: sayfadaki satır içi betiklerde sözdizimi hatası varsa yayını durdur
# (tek bir hata bütün siteyi çalışmaz hâle getirir).
import shutil as _sh, subprocess as _sp
if _sh.which('node'):
    _chk = r"""
const fs=require('fs');const h=fs.readFileSync(process.argv[1],'utf8');
const re=/<script(?![^>]*\bsrc=)(?![^>]*type="application\/json")[^>]*>([\s\S]*?)<\/script>/g;let m,i=0,bad=0;
while((m=re.exec(h))){try{new Function(m[1])}catch(e){bad++;console.error('betik '+i+': '+e.message)}i++}
if(bad)process.exit(1);console.log('betik denetimi: '+i+' betik, hata yok');"""
    r = _sp.run(['node', '-e', _chk, os.path.join(outdir, 'index.html')])
    if r.returncode != 0:
        sys.exit('HATA: index.html içindeki JavaScript sözdizimi bozuk; yayın durduruldu.')
open(os.path.join(outdir, 'robots.txt'), 'w').write('User-agent: *\nDisallow: /\n')

HTTPS_ON = '''# http -> https yönlendirmesi (HTTPS_REDIRECT=1)
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
'''
HTTPS_OFF = '''# http -> https yönlendirmesi kapalı (HTTPS_REDIRECT=0).
'''
BLOB = '''# Yüklenen dosyalar: /_blob/<kimlik> -> api/blob.php
RewriteRule ^_blob/([a-f0-9]{32})$ api/blob.php?id=$1 [L,QSA]
'''
open(os.path.join(outdir, '.htaccess'), 'w').write('''# PLC Market
DirectoryIndex index.html
Options -Indexes
# Otomatik yayın aracının durum dosyası dışarıdan okunmasın
<Files ".ftp-deploy-sync-state.json">
  Require all denied
</Files>
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/css application/javascript application/json image/svg+xml
</IfModule>
<IfModule mod_headers.c>
  Header set X-Robots-Tag "noindex, nofollow"
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  <FilesMatch "\\.html$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
</IfModule>
RewriteEngine On
''' + (HTTPS_ON if os.environ.get('HTTPS_REDIRECT') == '1' else HTTPS_OFF) + (BLOB if live else ''))

if live:
    api_src, api_out = os.path.join(root, 'api'), os.path.join(outdir, 'api')
    if os.path.isdir(api_out):
        shutil.rmtree(api_out)
    shutil.copytree(api_src, api_out, ignore=lambda d, names: [n for n in names if n == 'config.php' or (os.path.basename(d) == 'storage' and n != '.htaccess')])
    os.makedirs(os.path.join(api_out, 'data'), exist_ok=True)
    # sunucudaki sipariş doğrulaması için varsayılan katalog ve yöneticiye özel Excel kopyası
    json.dump([{k: r.get(k) for k in ('s', 'n', 'f', 'k') if k in r} for r in rows],
              open(os.path.join(api_out, 'data', 'katalog.json'), 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
    shutil.copy(os.path.join(root, 'src', 'katalog.xlsx.b64.txt'), os.path.join(api_out, 'data', 'katalog.xlsx.b64.txt'))

    def q(v):
        return "'" + str(v).replace('\\', '\\\\').replace("'", "\\'") + "'"
    cfg = {
        'db_host': os.environ.get('DB_HOST', 'localhost').strip() or 'localhost',
        'db_name': os.environ.get('DB_NAME', '').strip(),
        'db_user': os.environ.get('DB_USER', '').strip(),
        'db_pass': os.environ.get('DB_PASS', ''),
        'setup_token': os.environ.get('ADMIN_SETUP_TOKEN', '').strip(),
    }
    with open(os.path.join(api_out, 'config.php'), 'w', encoding='utf-8') as f:
        f.write("<?php\n// Yayın sırasında GitHub Secrets'tan üretilir. Depoya eklenmez.\nreturn [\n")
        for k, v in cfg.items():
            f.write('    ' + q(k) + ' => ' + q(v) + ',\n')
        f.write('];\n')

print('site hazır (' + ('canlı' if live else 'demo') + '):', outdir, os.path.getsize(os.path.join(outdir, 'index.html')), 'bayt')
