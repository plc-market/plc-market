# PLC Market

plc-market.com.tr sitesinin kaynak deposu. Siemens odaklı otomasyon ve elektrik malzemeleri mağazası.

## Klasörler

| Yol | İçerik |
|---|---|
| `src/plc-market.html` | Sitenin kaynağı. claude.ai'deki sürümle aynı sayfa; katalog içine gömülüdür. |
| `src/site-client.js` | Sayfayı sitenin kendi sunucusuna bağlayan istemci katmanı (yalnızca canlı yayında eklenir). |
| `src/katalog.xlsx.b64.txt` | Varsayılan katalog Excel'i; yönetim panelinden yöneticilere indirilir. |
| `api/` | PHP + MySQL sunucu katmanı: üyelik, belge deposu, sipariş doğrulama, dosya yükleme, kurulum. |
| `tools/build_site.py` | Kaynaktan İHS'ye yüklenecek siteyi üretir. |
| `tools/rows.py`, `tools/build_xl.py` | Siemens fiyat listesi Excel'inden katalog üretme araçları. |
| `.github/workflows/deploy.yml` | `main` dalına her gönderimde siteyi üretir ve FTP ile İHS'ye yükler. |

## Yayın nasıl işler

1. Kaynak güncellenir ve `main` dalına gönderilir.
2. GitHub Actions `tools/build_site.py` ile siteyi `site/` klasörüne üretir.
   - Veritabanı bilgileri (`DB_NAME` vb.) tanımlıysa **canlı** site üretilir: `api/` klasörü ve `api/config.php` eklenir.
   - Tanımlı değilse **demo** site üretilir: statik vitrin, talepler kaydedilmez.
3. Site FTP ile İHS'deki `httpdocs/` klasörüne yüklenir. Yalnızca değişen dosyalar gönderilir.

Sunucudaki dosyaları elle değiştirmeyin: bir sonraki yayın üzerine yazar. Yüklenen görseller ve veritabanı yayından etkilenmez.

## Kurulum (bir kez)

GitHub'da depo sayfası › **Settings** › **Secrets and variables** › **Actions** › **Secrets**:

| Ad | Değer |
|---|---|
| `FTP_SERVER` | `plc-market.com.tr` |
| `FTP_USERNAME` | İHS panelindeki FTP kullanıcı adı |
| `FTP_PASSWORD` | İHS panelinde **FTP Şifre Değişikliği** ile belirlenen şifre |
| `DB_HOST` | İHS **Mysql Yönetimi**'nde görünen sunucu adı (çoğu zaman `localhost`) |
| `DB_NAME` | Veritabanı adı |
| `DB_USER` | Veritabanı kullanıcı adı |
| `DB_PASS` | Veritabanı şifresi |
| `ADMIN_SETUP_TOKEN` | Kendi belirlediğiniz, en az 20 karakterlik kurulum anahtarı |

Yayından sonra `https://plc-market.com.tr/api/kurulum.php` sayfası açılır. Kurulum anahtarıyla yönetici hesapları (e-posta + şifre) oluşturulur. İş bitince `ADMIN_SETUP_TOKEN` silinip yayın yeniden çalıştırılırsa sayfa kapanır.

**Variables** sekmesi (isteğe bağlı):
- `FTP_DIR`: Web kök klasörü, sonu `/` ile biter. Varsayılan `httpdocs/` (İHS'de sitenin klasörü).
- `FTP_PROTOCOL`: Varsayılan `ftps` (şifreli). Sunucu desteklemiyorsa `ftp`.
- `FTP_SECURITY`: Varsayılan `loose`. Paylaşımlı sunucunun FTP sertifikası alan adıyla eşleşmeyebileceği için sertifika adı denetlenmez; bağlantı yine şifrelidir.
- `HTTPS_REDIRECT`: Varsayılan `1`; http adresleri https'e yönlenir. SSL'de sorun olursa `0` yapılarak kapatılır.

Yayını elle başlatmak için: **Actions** › **Siteyi İHS'ye yükle** › **Run workflow**.

## Sunucu katmanı (api/)

- **Üyelik:** e-posta + şifre (bcrypt), PHP oturumu (HttpOnly, Secure, SameSite=Lax). Hatalı girişte 15 dakikada 5 deneme sınırı.
- **Belge deposu:** sayfanın kullandığı belge/koleksiyon yapısı MySQL'de `docs` tablosunda tutulur. Erişim kuralları sunucuda uygulanır:
  - `katalog/*`, `resimler/*`: herkes okur, yalnızca yönetici yazar.
  - `uyeler/<uid>/…`: yalnızca o üye ve yöneticiler okur/yazar. Üye siparişini yalnızca oluşturabilir; durumunu yönetim değiştirir.
  - `talepler/*`: üye olmayan ziyaretçilerin sipariş ve teklif talepleri. Herkes yeni talep oluşturabilir (saatte 10), yalnızca yönetici okur.
- **Sipariş doğrulama:** sipariş ve talepler kaydedilirken fiyat, adet iskontosu, KDV, kargo ve kur sunucuda yeniden hesaplanır; tarayıcıdan gelen tutarlara güvenilmez.
- **Dosyalar:** yalnızca yöneticiler yükler; `api/storage/blobs/` altında tutulur ve `/_blob/<kimlik>` adresinden sunulur. Excel kopyası gibi metin dosyalarını yalnızca yöneticiler indirebilir.
- **İstek güvenliği:** tüm API istekleri `X-PLC` başlığı ve aynı köken denetiminden geçer.
- **Gereksinim:** PHP 8.0 ya da üzeri, PDO MySQL, mbstring.

## Sonraki adımlar

- Online kart ödemesi (iyzico ya da PayTR). Şu an siparişler ödeme bekliyor olarak kaydedilir.
- E-posta bildirimleri ve şifre sıfırlama (Zoho SMTP). Şu an yönetici, üye ayrıntısından geçici şifre oluşturabilir.
