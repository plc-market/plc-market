# PLC Market: src/plc-market.html dosyasından İHS'ye yüklenecek statik demo sitesini üretir.
# Kullanım: python3 tools/build_site.py src/plc-market.html site
import re, sys, os
src, outdir = sys.argv[1], sys.argv[2]
h = open(src, encoding='utf-8').read()
def rep(a, b):
    global h
    assert h.count(a) == 1, (a[:70], h.count(a))
    h = h.replace(a, b)
# başlık, font ve stil bloklarını <head>'e taşı
m = re.match(r'(<title>.*?</title>\s*<link[^>]*>\s*<style>.*?</style>)', h, re.S)
assert m
head, body = m.group(1), h[m.end():]
h = body
# demo metinleri
rep('<div class="notice" lang="tr"><div class="wrap">Önizleme sürümü.', '<div class="notice" lang="tr"><div class="wrap">Demo sürüm: siparişler henüz kaydedilmez.')
rep('<p class="warnbox">Bu önizlemede üyelik, sayfaya oturum açarak ve sayfayı kullanma yetkisiyle erişen kişilere açıktır. Siteye bu şekilde eriştiğinizde bu bölümden üye olabilirsiniz.</p>',
    '<p class="warnbox">Üyelik, canlı sürümde açılacak. Bu demo sürümde üye kaydı alınmıyor.</p>')
rep('note.textContent="Bu önizlemede üye olmadan verilen talepler kaydedilmez. Özeti kopyalayıp e-posta ya da mesajla gönderebilirsiniz. Üye olursanız siparişleriniz yönetim paneline iletilir ve Hesabım\'da görünür.";return}',
    'note.textContent="Bu demo sürümde talepler kaydedilmez. Özeti kopyalayıp e-posta ya da mesajla gönderebilirsiniz.";return}')
rep('Bu önizleme sürümünde talep henüz bir sipariş sistemine iletilmiyor.', 'Bu demo sürümde talep henüz bir sipariş sistemine iletilmiyor.')
rep('toast("Önizlemede ödeme alınmaz.', 'toast("Demo sürümde ödeme alınmaz.')
rep('<br><a class="link" href="#yonetim" style="font-size:12.5px;color:var(--muted)">Yönetici girişi</a>', '')
# claude.ai dışında yönetim paneli yok
rep('<div id="yon-deny" class="empty" hidden>Bu bölüm yalnızca site yöneticilerine açıktır. Yönetici olarak eklendiyseniz sayfayı kendi hesabınızla oturum açarak açın; erişim kendiliğinden tanınır.</div>',
    '<div id="yon-deny" class="empty" hidden>Yönetim paneli demo sürümde kapalıdır.</div>')
doc = ('<!doctype html>\n<html lang="tr">\n<head>\n<meta charset="utf-8">\n'
       '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">\n'
       '<meta name="robots" content="noindex, nofollow">\n'
       '<meta name="description" content="PLC Market: Siemens otomasyon ve elektrik malzemeleri (demo)">\n'
       + head + '\n</head>\n<body>\n' + h + '\n</body>\n</html>\n')
os.makedirs(outdir, exist_ok=True)
open(os.path.join(outdir, 'index.html'), 'w', encoding='utf-8').write(doc)
open(os.path.join(outdir, 'robots.txt'), 'w').write('User-agent: *\nDisallow: /\n')
HTTPS_ON = '''# http -> https yönlendirmesi (HTTPS_REDIRECT=1)
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
'''
HTTPS_OFF = '''# http -> https yönlendirmesi kapalı. SSL kurulunca GitHub'da HTTPS_REDIRECT değişkenini 1 yapın.
'''
open(os.path.join(outdir, '.htaccess'), 'w').write('''# PLC Market demo
DirectoryIndex index.html
# Otomatik yayın aracının durum dosyası dışarıdan okunmasın
<Files ".ftp-deploy-sync-state.json">
  Require all denied
</Files>
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/css application/javascript application/json image/svg+xml
</IfModule>
<IfModule mod_headers.c>
  Header set X-Robots-Tag "noindex, nofollow"
  <FilesMatch "\\.html$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
</IfModule>
''' + (HTTPS_ON if os.environ.get('HTTPS_REDIRECT') == '1' else HTTPS_OFF))
print('site hazır:', outdir, os.path.getsize(os.path.join(outdir, 'index.html')), 'bayt')
