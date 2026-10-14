import openpyxl, json, sys
wb=openpyxl.load_workbook(sys.argv[1],read_only=True,data_only=True)
rows=[];tot=0
for ws in wb.worksheets:
    if ws.title in('İçindekiler','Tüm Ürünler','Site Kategorileri'): continue
    a=list(ws.iter_rows(values_only=True))
    h=next((i for i in range(min(10,len(a))) if a[i] and 'Sipariş No' in a[i]),None)
    if h is None or 'Sitede Aktif' not in a[h]: continue
    H=list(a[h]); ix=H.index
    for R in a[h+1:]:
        if not R or not R[ix('Sipariş No')]: continue
        tot+=1
        if str(R[ix('Sitede Aktif')] or '').strip().lower() not in('evet','e','1','true'): continue
        g=lambda k: R[ix(k)] if k in H else None
        rows.append({'s':str(g('Sipariş No')).strip(),'n':g('Açıklama'),'t':g('Teknik Özellikler'),'l':g('Liste Fiyatı (EUR)'),'m':g('Piyasa Ortalaması (EUR)'),'f':g('Nihai Satış Fiyatı (EUR)'),'b':g('Fiyat Dayanağı'),'c':g('Site Kategorisi'),'g':g('Ara Grup')})
        if 'Stok Adedi' in H: rows[-1]['k']=g('Stok Adedi') or 0
json.dump(rows,open(sys.argv[2],'w',encoding='utf-8'),ensure_ascii=False,separators=(',',':'))
print(tot,len(rows))
