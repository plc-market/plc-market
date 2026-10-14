# PLC Market

plc-market.com.tr sitesinin kaynak deposu. Siemens odaklı otomasyon ve elektrik malzemeleri mağazası.

## Klasörler

| Yol | İçerik |
|---|---|
| `src/plc-market.html` | Sitenin kaynağı. claude.ai'deki sürümle aynı sayfa; katalog içine gömülüdür. |
| `tools/build_site.py` | Kaynaktan İHS'ye yüklenecek demo sitesini üretir: `index.html`, `.htaccess`, `robots.txt`. |
| `tools/rows.py`, `tools/build_xl.py` | Siemens fiyat listesi Excel'inden katalog üretme araçları. |
| `.github/workflows/deploy.yml` | `main` dalına her gönderimde siteyi üretir ve FTP ile İHS'ye yükler. |

## Yayın nasıl işler

1. `src/plc-market.html` güncellenir ve `main` dalına gönderilir.
2. GitHub Actions `tools/build_site.py` ile siteyi `site/` klasörüne üretir.
3. Site FTP ile İHS'deki web kök klasörüne yüklenir. Yalnızca değişen dosyalar gönderilir.

Sunucudaki dosyaları elle değiştirmeyin: bir sonraki yayın üzerine yazar. Değişiklikler bu depodan yapılır.

## Kurulum (bir kez)

GitHub'da depo sayfası › **Settings** › **Secrets and variables** › **Actions**:

**Secrets** sekmesi:
- `FTP_SERVER`: `plc-market.com.tr`
- `FTP_USERNAME`: İHS panelindeki FTP kullanıcı adı
- `FTP_PASSWORD`: İHS panelinde **FTP Şifre Değişikliği** ile belirlenen şifre

**Variables** sekmesi (gerekirse):
- `FTP_DIR`: Web kök klasörü, sonu `/` ile biter. Varsayılan `httpdocs/` (İHS'de sitenin klasörü).
- `FTP_PROTOCOL`: Varsayılan `ftps` (şifreli). Sunucu desteklemiyorsa `ftp`.
- `FTP_SECURITY`: Varsayılan `loose`. Paylaşımlı sunucunun FTP sertifikası alan adıyla eşleşmeyebileceği için sertifika adı denetlenmez; bağlantı yine şifrelidir.
- `HTTPS_REDIRECT`: SSL kurulduktan sonra `1` yapılır; http adresleri https'e yönlenir.

Yayını elle başlatmak için: **Actions** › **Siteyi İHS'ye yükle** › **Run workflow**.

## Demo sınırları

İHS'deki site statik bir demodur. Katalog, arama, motor seçici, EUR/TL fiyatlar, sepet ve sipariş/teklif formu çalışır; talepler kaydedilmez. Üyelik, proje sepetleri ve yönetim paneli claude.ai sürümünde çalışır. Canlı mağaza için PHP + MySQL altyapısı bu depoya eklenecek.
