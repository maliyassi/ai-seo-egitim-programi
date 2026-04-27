# Keyword Analyzer (Search Console + Gemini)

## Kurulum

```bash
composer install
php -S 127.0.0.1:8080 -t public
```

Tarayici: `http://127.0.0.1:8080`

## Gerekenler

- PHP 8.1+
- Google Search Console erisimi olan Service Account JSON
- (Opsiyonel) Gemini API Key

## Form Alanlari

- `URL`: analiz edilecek sayfa
- `Icerik XPath`: metin alani (ornek: `//main//article`)
- `Gun Araligi`: varsayilan 28, isterseniz 365
- `Site Property URL`: bos birakilirsa URL'den otomatik `https://host/`
- `Google JSON Dosya Yolu`: varsayilan `credentials-2.json` (proje kokunde)
- `Gemini model`: varsayilan `gemini-3-flash-preview`

## Eslesme Durumlari

- Yesil: Sirali tam eslesme
- Turuncu: Sirasiz tam eslesme
- Sari: Kismi eslesme
- Mavi: Turkce karakter kaynakli yazim hatasi eslesmesi
- Kirmizi: Eslesme yok

## Akis

1. `1. Adim: Icerik + Sorgu Analizi` calistirilir.
2. Sayfadan `//h1` ve verilen XPath iceriginin tamami cekilir.
3. XPath icindeki `h1` temizlenir.
4. Baslik + icerik normalize edilip Search Console sorgulari ile karsilastirilir.
5. `2. Adim: Gemini ile Eksikleri Tamamla` secilirse sadece kirmizi sorgular icin icerik revize edilir ve tekrar analiz edilir.
6. Eksik kalirsa belirlenen tur sayisina kadar dongu devam eder.
