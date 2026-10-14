"""Katalog Excel'inden sitenin ürün satırlarını üretir.
Kullanım: python3 tools/rows.py <katalog.xlsx> <çıktı.json>
Stok: "Stok Adedi" sütunu varsa o; yoksa "Toplam Ürün Sayısı" (değeri hesaplanmamış formülse,
"Stoklu Bayi Sayısı" ile "Toplam Ürün Sayısı" arasındaki bayi stok sütunlarının toplamı)."""
import openpyxl, json, sys

SKIP = ('İçindekiler', 'Tüm Ürünler', 'Site Kategorileri')


def num(v):
    if isinstance(v, (int, float)):
        return v
    try:
        return float(str(v).replace(',', '.'))
    except (TypeError, ValueError):
        return None


def stock_of(R, H):
    if 'Stok Adedi' in H:
        v = num(R[H.index('Stok Adedi')])
        return int(v) if v is not None else 0
    if 'Toplam Ürün Sayısı' not in H:
        return None
    t = H.index('Toplam Ürün Sayısı')
    v = num(R[t]) if t < len(R) else None
    if v is None:
        a = H.index('Stoklu Bayi Sayısı') + 1 if 'Stoklu Bayi Sayısı' in H else t
        v = sum(x for x in (num(c) for c in R[a:t]) if x and x > 0)
    return max(0, int(v))


def rows_from(path):
    wb = openpyxl.load_workbook(path, read_only=True, data_only=True)
    rows, tot = [], 0
    for ws in wb.worksheets:
        if ws.title in SKIP:
            continue
        a = list(ws.iter_rows(values_only=True))
        h = next((i for i in range(min(10, len(a))) if a[i] and 'Sipariş No' in a[i]), None)
        if h is None or 'Sitede Aktif' not in a[h]:
            continue
        H = [str(x).strip() if x is not None else '' for x in a[h]]
        ix = H.index
        for R in a[h + 1:]:
            if not R or not R[ix('Sipariş No')]:
                continue
            tot += 1
            if str(R[ix('Sitede Aktif')] or '').strip().lower() not in ('evet', 'e', '1', 'true', 'aktif'):
                continue
            g = lambda k: R[ix(k)] if k in H else None
            r = {'s': str(g('Sipariş No')).strip(), 'n': g('Açıklama'), 't': g('Teknik Özellikler'), 'l': g('Liste Fiyatı (EUR)'),
                 'm': g('Piyasa Ortalaması (EUR)'), 'f': g('Nihai Satış Fiyatı (EUR)'), 'b': g('Fiyat Dayanağı'),
                 'c': g('Site Kategorisi'), 'g': g('Ara Grup')}
            k = stock_of(R, H)
            if k is not None:
                r['k'] = k
            rows.append(r)
    return rows, tot


if __name__ == '__main__':
    rows, tot = rows_from(sys.argv[1])
    json.dump(rows, open(sys.argv[2], 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
    print(tot, len(rows))
