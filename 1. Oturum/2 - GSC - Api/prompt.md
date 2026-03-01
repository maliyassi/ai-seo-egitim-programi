Aşağıdaki verileri kullanarak, bana şu özellikteki SEO sonuçlarını dönecek bir py betiği yaz. Ancak, bu betik her bir analiz için farklı sonuç dosyaları üretsin.

1. Cannibalization Tespiti: Aynı sorgu → birden fazla sayfada görünüyorsa
→ Cannibalization var
2. Sorgu-Sayfa Uyumsuzluğu (Intent Mismatch): # Sorgu + sayfa verisini çek
# Sonra filtrele:

# Ürün araması (spesifik marka/model)
# ama giden sayfa kategori

urun_aramalari = df[
    df['sorgu'].str.contains(
        'fiyat|satın al|sipariş|model|marka', 
        case=False
    ) & 
    df['sayfa'].str.contains('/kategori/')
]

Veya tam tersi:
python# Bilgi araması ama ürün sayfasına gidiyor
bilgi_aramalari = df[
    df['sorgu'].str.contains(
        'nedir|nasıl|ne zaman|kaç', 
        case=False
    ) & 
    df['sayfa'].str.contains('/urun/')
]

3. Alakasız Sorgu Tespiti (Brand Contamination)
python# Marka aramaları tüm sorgular içinde
# ne kadar yer kaplıyor?

marka_sorgulari = df[
    df['sorgu'].str.contains(
        'mutfak10|mutfakon', 
        case=False
    )
]

marka_tiklama_orani = (
    marka_sorgulari['tiklamalar'].sum() / 
    df['tiklamalar'].sum() * 100
)

print(f"Marka trafiği oranı: %{marka_tiklama_orani:.1f}")
Marka aramaları yüksekse organik büyüme olduğundan az görünüyor. Bunu ayırınca gerçek SEO performansını ölçersin.

4. Pozisyon Tutarsızlığı (Ranking Volatility)
Bunu görmek için tarih boyutunu eklemen gerekiyor:
pythonbody={
    'dimensions': ['page', 'date'],
    'rowLimit': 25000
}

# Sonra her sayfa için pozisyon standart sapması hesapla
volatilite = df.groupby('sayfa')['pozisyon'].agg([
    'mean',   # ortalama pozisyon
    'std',    # standart sapma
    'min',    # en iyi pozisyon
    'max'     # en kötü pozisyon
]).reset_index()

# Yüksek sapma = stabil değil
yuksek_volatilite = volatilite[
    volatilite['std'] > 3
].sort_values('std', ascending=False)
Bir sayfa bugün 3. yarın 15. sıraya düşüyorsa bunu ancak böyle yakalarsın.

5. CTR Anomalisi (Pozisyona Göre Beklenenden Düşük)
Pozisyon 1'de ortalama CTR %28-30 civarında. Eğer pozisyon 1'de %5 CTR varsa ciddi bir sorun var.
python# Beklenen CTR benchmark
beklenen_ctr = {
    1: 28.5, 2: 15.7, 3: 11.0,
    4: 8.0,  5: 7.2,  6: 5.1,
    7: 4.0,  8: 3.2,  9: 2.8, 10: 2.5
}

# Pozisyonu tam sayıya yuvarla
df['poz_tam'] = df['pozisyon'].round()

# Beklenen CTR'yi ekle
df['beklenen_ctr'] = df['poz_tam'].map(beklenen_ctr)

# Anomali: beklenenin yarısından az CTR
df['ctr_anomali'] = (
    df['ctr'] < df['beklenen_ctr'] * 0.5
)

anomaliler = df[
    df['ctr_anomali'] & 
    df['gosterimler'] > 1000
].sort_values('gosterimler', ascending=False)
```

**Neden CTR beklenenin altında?**
```
→ Title tag zayıf veya spam görünümlü
→ Meta description yok veya kötü
→ Rich snippet kaybedilmiş
→ SGE snippet kapıyor
→ Rakip daha iyi rich result alıyor

GEMINI_API_KEY = "AIzaSyDTrSc5nCH8E4h3XDwn3-1zo1ltT0_6hWQ"
GEMINI_MODEL = "gemini-3-flash-preview"
GEMINI_URL = f"https://generativelanguage.googleapis.com/v1beta/models/{GEMINI_MODEL}:generateContent?key={GEMINI_API_KEY}"
