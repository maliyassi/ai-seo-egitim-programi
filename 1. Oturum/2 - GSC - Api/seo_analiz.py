#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
SEO Analiz Betikleri — Google Search Console Verileri ile
=========================================================
5 farklı SEO analizi yaparak her biri için ayrı sonuç dosyası üretir.

Analizler:
  1. Cannibalization Tespiti
  2. Intent Mismatch (Sorgu-Sayfa Uyumsuzluğu)
  3. Brand Contamination (Marka Kirliliği)
  4. Ranking Volatility (Pozisyon Tutarsızlığı)
  5. CTR Anomalisi

Kullanım:
  python3 seo_analiz.py --site "sc-domain:mutfak10.com"
  python3 seo_analiz.py --site "sc-domain:mutfak10.com" --days 180
"""

import argparse
import json
import os
import sys
import time
from datetime import datetime, timedelta

import pandas as pd
import requests
from google.oauth2.credentials import Credentials
from google_auth_oauthlib.flow import InstalledAppFlow
from google.auth.transport.requests import Request
from googleapiclient.discovery import build

# ─── Sabitler ─────────────────────────────────────────────────────────────────

SCOPES = ['https://www.googleapis.com/auth/webmasters.readonly']
CREDENTIALS_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'credentials.json')
TOKEN_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'token.json')
SONUCLAR_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'sonuclar')

GEMINI_API_KEY = "BURAYA_API_GELECEK"
GEMINI_MODEL = "gemini-3-flash-preview"
GEMINI_URL = f"https://generativelanguage.googleapis.com/v1beta/models/{GEMINI_MODEL}:generateContent?key={GEMINI_API_KEY}"

# Marka anahtar kelimeleri
MARKA_PATTERN = r'mutfak10|mutfakon'

# CTR benchmark tablosu (pozisyona göre beklenen CTR %)
BEKLENEN_CTR = {
    1: 28.5, 2: 15.7, 3: 11.0,
    4: 8.0,  5: 7.2,  6: 5.1,
    7: 4.0,  8: 3.2,  9: 2.8, 10: 2.5
}

# ─── GSC API Bağlantısı ──────────────────────────────────────────────────────

def gsc_baglantisi():
    """Google Search Console API'ye bağlan ve service objesini döndür."""
    creds = None

    if os.path.exists(TOKEN_FILE):
        creds = Credentials.from_authorized_user_file(TOKEN_FILE, SCOPES)

    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(Request())
        else:
            flow = InstalledAppFlow.from_client_secrets_file(CREDENTIALS_FILE, SCOPES)
            creds = flow.run_local_server(port=0)

        with open(TOKEN_FILE, 'w') as token:
            token.write(creds.to_json())

    service = build('searchconsole', 'v1', credentials=creds)
    return service


def gsc_siteleri_listele(service):
    """Erişilebilir GSC sitelerini listele."""
    site_list = service.sites().list().execute()
    sites = site_list.get('siteEntry', [])
    if not sites:
        print("  ⚠️  Bu hesapla erişilebilir site bulunamadı!")
        print("  💡 Farklı bir Google hesabı denemek için: python3 seo_analiz.py --reauth --list-sites")
    else:
        print(f"\n  📋 Erişilebilir siteler ({len(sites)} adet):\n")
        for s in sites:
            perm = s.get('permissionLevel', '?')
            print(f"    • {s['siteUrl']}  (yetki: {perm})")
    return sites


def gsc_veri_cek(service, site_url, dimensions, start_date, end_date, row_limit=25000):
    """GSC API'den veri çek. Sayfalama ile tüm veriyi al."""
    tum_satirlar = []
    start_row = 0

    while True:
        body = {
            'startDate': start_date,
            'endDate': end_date,
            'dimensions': dimensions,
            'rowLimit': row_limit,
            'startRow': start_row
        }

        try:
            response = service.searchanalytics().query(siteUrl=site_url, body=body).execute()
        except Exception as e:
            if '403' in str(e) or 'forbidden' in str(e).lower():
                print(f"\n  ❌ YETKI HATASI: '{site_url}' sitesine erişim izniniz yok!")
                print(f"  💡 Erişilebilir siteleri görmek için: python3 seo_analiz.py --list-sites")
                print(f"  💡 Farklı hesapla giriş yapmak için: python3 seo_analiz.py --reauth --site ...")
                sys.exit(1)
            raise
        rows = response.get('rows', [])

        if not rows:
            break

        tum_satirlar.extend(rows)
        start_row += len(rows)

        if len(rows) < row_limit:
            break

        print(f"  ... {start_row} satır çekildi, devam ediliyor...")

    return tum_satirlar


def satirlari_df_yap(rows, dimensions):
    """GSC API satırlarını pandas DataFrame'e dönüştür."""
    if not rows:
        return pd.DataFrame()

    data = []
    for row in rows:
        entry = {}
        for i, dim in enumerate(dimensions):
            entry[dim] = row['keys'][i]
        entry['tiklamalar'] = row.get('clicks', 0)
        entry['gosterimler'] = row.get('impressions', 0)
        entry['ctr'] = row.get('ctr', 0) * 100  # yüzde olarak
        entry['pozisyon'] = row.get('position', 0)
        data.append(entry)

    return pd.DataFrame(data)


# ─── Gemini API ──────────────────────────────────────────────────────────────

def gemini_yorum(analiz_adi, veri_ozeti, max_retries=3):
    """Gemini API'ye analiz verisini gönderip Türkçe yorum al."""
    prompt = f"""Sen bir SEO uzmanısın. Aşağıdaki "{analiz_adi}" analiz sonuçlarını inceleyip Türkçe olarak değerlendir.

Analiz Verisi:
{veri_ozeti}

Lütfen şu başlıklar altında yanıt ver:
1. **Genel Değerlendirme**: Verinin genel görünümü
2. **Kritik Bulgular**: En önemli sorunlar (varsa)
3. **Aksiyon Önerileri**: Yapılması gereken somut adımlar (en az 3 öneri)
4. **Öncelik Sıralaması**: Hangi aksiyonlar önce alınmalı

Yanıtını markdown formatında ver."""

    headers = {"Content-Type": "application/json"}
    payload = {
        "contents": [{"parts": [{"text": prompt}]}],
        "generationConfig": {
            "temperature": 0.7,
            "maxOutputTokens": 2048
        }
    }

    for attempt in range(max_retries):
        try:
            response = requests.post(GEMINI_URL, headers=headers, json=payload, timeout=60)
            response.raise_for_status()
            result = response.json()

            text = result['candidates'][0]['content']['parts'][0]['text']
            return text
        except Exception as e:
            print(f"  ⚠️  Gemini API hatası (deneme {attempt+1}/{max_retries}): {e}")
            if attempt < max_retries - 1:
                time.sleep(2 ** attempt)

    return "❌ Gemini API'den yanıt alınamadı."


# ─── Analiz Fonksiyonları ─────────────────────────────────────────────────────

def analiz_cannibalization(df_query_page):
    """
    1. Cannibalization Tespiti
    Aynı sorgu birden fazla sayfada görünüyorsa → cannibalization var.
    """
    print("\n📊 1. Cannibalization Tespiti...")

    if df_query_page.empty:
        print("  ⚠️  Veri bulunamadı.")
        return pd.DataFrame(), ""

    # Her sorgu için kaç farklı sayfada göründüğünü hesapla
    sorgu_sayfa_sayisi = df_query_page.groupby('query').agg(
        sayfa_sayisi=('page', 'nunique'),
        sayfalar=('page', lambda x: ' | '.join(sorted(x.unique()))),
        toplam_tiklama=('tiklamalar', 'sum'),
        toplam_gosterim=('gosterimler', 'sum'),
        ort_pozisyon=('pozisyon', 'mean')
    ).reset_index()

    # Sadece birden fazla sayfada görünenleri al
    cannibalization = sorgu_sayfa_sayisi[
        sorgu_sayfa_sayisi['sayfa_sayisi'] > 1
    ].sort_values('toplam_gosterim', ascending=False)

    cannibalization.columns = ['Sorgu', 'Sayfa Sayısı', 'Sayfalar',
                               'Toplam Tıklama', 'Toplam Gösterim', 'Ort. Pozisyon']
    cannibalization['Ort. Pozisyon'] = cannibalization['Ort. Pozisyon'].round(1)

    # CSV kaydet
    csv_path = os.path.join(SONUCLAR_DIR, '1_cannibalization.csv')
    cannibalization.to_csv(csv_path, index=False, encoding='utf-8-sig')
    print(f"  ✅ {len(cannibalization)} cannibalization sorgusu bulundu → {csv_path}")

    # Gemini yorumu
    ozet = f"""Toplam cannibalization sorgusu: {len(cannibalization)}
Toplam etkilenen tıklama: {cannibalization['Toplam Tıklama'].sum():,}
Toplam etkilenen gösterim: {cannibalization['Toplam Gösterim'].sum():,}

En çok gösterim alan ilk 20 cannibalization sorgusu:
{cannibalization.head(20).to_string(index=False)}"""

    yorum = gemini_yorum("Cannibalization Tespiti", ozet)

    # MD rapor kaydet
    md_path = os.path.join(SONUCLAR_DIR, '1_cannibalization.md')
    with open(md_path, 'w', encoding='utf-8') as f:
        f.write("# 🔴 Cannibalization Tespiti\n\n")
        f.write(f"**Tarih:** {datetime.now().strftime('%Y-%m-%d %H:%M')}\n\n")
        f.write(f"## Özet İstatistikler\n\n")
        f.write(f"- **Toplam cannibalization sorgusu:** {len(cannibalization)}\n")
        f.write(f"- **Etkilenen toplam tıklama:** {cannibalization['Toplam Tıklama'].sum():,}\n")
        f.write(f"- **Etkilenen toplam gösterim:** {cannibalization['Toplam Gösterim'].sum():,}\n\n")
        f.write(f"## En Kritik 20 Sorgu\n\n")
        f.write(cannibalization.head(20).to_markdown(index=False))
        f.write(f"\n\n## 🤖 Gemini AI Yorumu\n\n{yorum}\n")

    print(f"  ✅ Rapor → {md_path}")
    return cannibalization, yorum


def analiz_cannibalization_without_backslash(df_query_page):
    """
    1b. Cannibalization Tespiti (Trailing Slash Hariç)
    URL'lerdeki sondaki / karakteri normalize edilerek,
    sadece gerçek cannibalization durumları tespit edilir.
    """
    print("\n📊 1b. Cannibalization Tespiti (Trailing Slash Hariç)...")

    if df_query_page.empty:
        print("  ⚠️  Veri bulunamadı.")
        return pd.DataFrame(), ""

    df = df_query_page.copy()

    # URL'leri normalize et: sondaki / karakterini kaldır
    df['page_normalized'] = df['page'].str.rstrip('/')

    # Normalize edilmiş URL'ler üzerinden grupla
    sorgu_sayfa_sayisi = df.groupby('query').agg(
        sayfa_sayisi=('page_normalized', 'nunique'),
        sayfalar=('page_normalized', lambda x: ' | '.join(sorted(x.unique()))),
        toplam_tiklama=('tiklamalar', 'sum'),
        toplam_gosterim=('gosterimler', 'sum'),
        ort_pozisyon=('pozisyon', 'mean')
    ).reset_index()

    # Sadece birden fazla farklı sayfada görünenleri al
    cannibalization = sorgu_sayfa_sayisi[
        sorgu_sayfa_sayisi['sayfa_sayisi'] > 1
    ].sort_values('toplam_gosterim', ascending=False)

    cannibalization.columns = ['Sorgu', 'Sayfa Sayısı', 'Sayfalar',
                               'Toplam Tıklama', 'Toplam Gösterim', 'Ort. Pozisyon']
    cannibalization['Ort. Pozisyon'] = cannibalization['Ort. Pozisyon'].round(1)

    # CSV kaydet
    csv_path = os.path.join(SONUCLAR_DIR, '1_cannibalization_without_backslash.csv')
    cannibalization.to_csv(csv_path, index=False, encoding='utf-8-sig')
    print(f"  ✅ {len(cannibalization)} gerçek cannibalization sorgusu bulundu → {csv_path}")

    # Gemini yorumu
    ozet = f"""Cannibalization Analizi (Trailing Slash Normalize Edilmiş)

Toplam gerçek cannibalization sorgusu: {len(cannibalization)}
Toplam etkilenen tıklama: {cannibalization['Toplam Tıklama'].sum():,}
Toplam etkilenen gösterim: {cannibalization['Toplam Gösterim'].sum():,}

NOT: Bu analizde /sayfa ve /sayfa/ gibi sadece sondaki slash farkı olan URL'ler
aynı sayfa olarak değerlendirilmiştir. Dolayısıyla buradaki sonuçlar
gerçek cannibalization durumlarını göstermektedir.

En çok gösterim alan ilk 20 cannibalization sorgusu:
{cannibalization.head(20).to_string(index=False)}"""

    yorum = gemini_yorum("Cannibalization Tespiti (Trailing Slash Hariç)", ozet)

    # MD rapor kaydet
    md_path = os.path.join(SONUCLAR_DIR, '1_cannibalization_without_backslash.md')
    with open(md_path, 'w', encoding='utf-8') as f:
        f.write("# 🔴 Cannibalization Tespiti (Trailing Slash Hariç)\n\n")
        f.write(f"**Tarih:** {datetime.now().strftime('%Y-%m-%d %H:%M')}\n\n")
        f.write("> ℹ️ Bu raporda `/sayfa` ve `/sayfa/` gibi sadece trailing slash farkı olan\n")
        f.write("> URL'ler aynı sayfa olarak kabul edilmiştir.\n\n")
        f.write(f"## Özet İstatistikler\n\n")
        f.write(f"- **Gerçek cannibalization sorgusu:** {len(cannibalization)}\n")
        f.write(f"- **Etkilenen toplam tıklama:** {cannibalization['Toplam Tıklama'].sum():,}\n")
        f.write(f"- **Etkilenen toplam gösterim:** {cannibalization['Toplam Gösterim'].sum():,}\n\n")
        f.write(f"## En Kritik 20 Sorgu\n\n")
        f.write(cannibalization.head(20).to_markdown(index=False))
        f.write(f"\n\n## 🤖 Gemini AI Yorumu\n\n{yorum}\n")

    print(f"  ✅ Rapor → {md_path}")
    return cannibalization, yorum


def analiz_intent_mismatch(df_query_page):
    """
    2. Intent Mismatch (Sorgu-Sayfa Uyumsuzluğu)
    Ürün araması → kategori sayfasına gidiyor veya bilgi araması → ürün sayfasına gidiyor.
    """
    print("\n📊 2. Intent Mismatch Tespiti...")

    if df_query_page.empty:
        print("  ⚠️  Veri bulunamadı.")
        return pd.DataFrame(), ""

    # Ürün araması ama kategori sayfasına gidiyor
    urun_aramalari = df_query_page[
        df_query_page['query'].str.contains(
            'fiyat|satın al|sipariş|model|marka', case=False, na=False
        ) &
        df_query_page['page'].str.contains('/kategori/', case=False, na=False)
    ].copy()
    urun_aramalari['uyumsuzluk_tipi'] = 'Ürün Araması → Kategori Sayfası'

    # Bilgi araması ama ürün sayfasına gidiyor
    bilgi_aramalari = df_query_page[
        df_query_page['query'].str.contains(
            'nedir|nasıl|ne zaman|kaç', case=False, na=False
        ) &
        df_query_page['page'].str.contains('/urun/', case=False, na=False)
    ].copy()
    bilgi_aramalari['uyumsuzluk_tipi'] = 'Bilgi Araması → Ürün Sayfası'

    # Birleştir
    intent_mismatch = pd.concat([urun_aramalari, bilgi_aramalari], ignore_index=True)
    intent_mismatch = intent_mismatch.sort_values('gosterimler', ascending=False)

    sonuc = intent_mismatch[['query', 'page', 'uyumsuzluk_tipi',
                              'tiklamalar', 'gosterimler', 'ctr', 'pozisyon']].copy()
    sonuc.columns = ['Sorgu', 'Sayfa', 'Uyumsuzluk Tipi',
                     'Tıklama', 'Gösterim', 'CTR (%)', 'Pozisyon']
    sonuc['CTR (%)'] = sonuc['CTR (%)'].round(2)
    sonuc['Pozisyon'] = sonuc['Pozisyon'].round(1)

    # CSV kaydet
    csv_path = os.path.join(SONUCLAR_DIR, '2_intent_mismatch.csv')
    sonuc.to_csv(csv_path, index=False, encoding='utf-8-sig')
    print(f"  ✅ {len(sonuc)} uyumsuzluk bulundu → {csv_path}")

    # Gemini yorumu
    urun_kategori_sayi = len(urun_aramalari)
    bilgi_urun_sayi = len(bilgi_aramalari)

    ozet = f"""Toplam intent mismatch: {len(sonuc)}
- Ürün araması → Kategori sayfası: {urun_kategori_sayi}
- Bilgi araması → Ürün sayfası: {bilgi_urun_sayi}

En çok gösterim alan ilk 20 uyumsuzluk:
{sonuc.head(20).to_string(index=False)}"""

    yorum = gemini_yorum("Intent Mismatch (Sorgu-Sayfa Uyumsuzluğu)", ozet)

    # MD rapor kaydet
    md_path = os.path.join(SONUCLAR_DIR, '2_intent_mismatch.md')
    with open(md_path, 'w', encoding='utf-8') as f:
        f.write("# 🟠 Intent Mismatch — Sorgu-Sayfa Uyumsuzluğu\n\n")
        f.write(f"**Tarih:** {datetime.now().strftime('%Y-%m-%d %H:%M')}\n\n")
        f.write(f"## Özet İstatistikler\n\n")
        f.write(f"- **Toplam uyumsuzluk:** {len(sonuc)}\n")
        f.write(f"- **Ürün araması → Kategori sayfası:** {urun_kategori_sayi}\n")
        f.write(f"- **Bilgi araması → Ürün sayfası:** {bilgi_urun_sayi}\n\n")
        f.write(f"## Tüm Uyumsuzluklar\n\n")
        if not sonuc.empty:
            f.write(sonuc.to_markdown(index=False))
        else:
            f.write("Uyumsuzluk bulunamadı.\n")
        f.write(f"\n\n## 🤖 Gemini AI Yorumu\n\n{yorum}\n")

    print(f"  ✅ Rapor → {md_path}")
    return sonuc, yorum


def analiz_brand_contamination(df_query_page):
    """
    3. Brand Contamination (Marka Kirliliği)
    Marka aramaları tüm sorgular içinde ne kadar yer kaplıyor?
    """
    print("\n📊 3. Brand Contamination Tespiti...")

    if df_query_page.empty:
        print("  ⚠️  Veri bulunamadı.")
        return pd.DataFrame(), ""

    # Sorgu bazında unique değerler al (query+page'den sadece query bazlı topla)
    sorgu_bazli = df_query_page.groupby('query').agg(
        toplam_tiklama=('tiklamalar', 'sum'),
        toplam_gosterim=('gosterimler', 'sum'),
        ort_ctr=('ctr', 'mean'),
        ort_pozisyon=('pozisyon', 'mean')
    ).reset_index()

    # Marka sorguları
    marka_mask = sorgu_bazli['query'].str.contains(MARKA_PATTERN, case=False, na=False)
    marka_sorgulari = sorgu_bazli[marka_mask].copy()
    markasiz_sorgular = sorgu_bazli[~marka_mask].copy()

    # Oranlar
    toplam_tiklama = sorgu_bazli['toplam_tiklama'].sum()
    toplam_gosterim = sorgu_bazli['toplam_gosterim'].sum()
    marka_tiklama = marka_sorgulari['toplam_tiklama'].sum()
    marka_gosterim = marka_sorgulari['toplam_gosterim'].sum()

    marka_tiklama_orani = (marka_tiklama / toplam_tiklama * 100) if toplam_tiklama > 0 else 0
    marka_gosterim_orani = (marka_gosterim / toplam_gosterim * 100) if toplam_gosterim > 0 else 0

    markasiz_tiklama = markasiz_sorgular['toplam_tiklama'].sum()
    markasiz_gosterim = markasiz_sorgular['toplam_gosterim'].sum()

    # Sonuç tablosu
    karsilastirma = pd.DataFrame({
        'Metrik': ['Toplam Sorgu Sayısı', 'Toplam Tıklama', 'Toplam Gösterim',
                   'Ort. CTR (%)', 'Ort. Pozisyon'],
        'Markalı': [
            len(marka_sorgulari), marka_tiklama, marka_gosterim,
            round(marka_sorgulari['ort_ctr'].mean(), 2) if not marka_sorgulari.empty else 0,
            round(marka_sorgulari['ort_pozisyon'].mean(), 1) if not marka_sorgulari.empty else 0
        ],
        'Markasız': [
            len(markasiz_sorgular), markasiz_tiklama, markasiz_gosterim,
            round(markasiz_sorgular['ort_ctr'].mean(), 2) if not markasiz_sorgular.empty else 0,
            round(markasiz_sorgular['ort_pozisyon'].mean(), 1) if not markasiz_sorgular.empty else 0
        ]
    })

    # CSV kaydet — marka sorguları listesi
    marka_sonuc = marka_sorgulari.sort_values('toplam_tiklama', ascending=False)
    marka_sonuc.columns = ['Sorgu', 'Tıklama', 'Gösterim', 'CTR (%)', 'Pozisyon']
    marka_sonuc['CTR (%)'] = marka_sonuc['CTR (%)'].round(2)
    marka_sonuc['Pozisyon'] = marka_sonuc['Pozisyon'].round(1)

    csv_path = os.path.join(SONUCLAR_DIR, '3_brand_contamination.csv')
    marka_sonuc.to_csv(csv_path, index=False, encoding='utf-8-sig')
    print(f"  ✅ {len(marka_sonuc)} marka sorgusu bulundu → {csv_path}")
    print(f"  📈 Marka trafiği oranı: %{marka_tiklama_orani:.1f}")

    # Gemini yorumu
    ozet = f"""Marka Pattern: {MARKA_PATTERN}

Marka trafiği oranı (tıklama): %{marka_tiklama_orani:.1f}
Marka gösterim oranı: %{marka_gosterim_orani:.1f}

Karşılaştırma:
{karsilastirma.to_string(index=False)}

En çok tıklama alan marka sorguları (ilk 20):
{marka_sonuc.head(20).to_string(index=False)}

Markasız organik trafik:
- Tıklama: {markasiz_tiklama:,}
- Gösterim: {markasiz_gosterim:,}
- Sorgu sayısı: {len(markasiz_sorgular):,}"""

    yorum = gemini_yorum("Brand Contamination (Marka Kirliliği)", ozet)

    # MD rapor kaydet
    md_path = os.path.join(SONUCLAR_DIR, '3_brand_contamination.md')
    with open(md_path, 'w', encoding='utf-8') as f:
        f.write("# 🟡 Brand Contamination — Marka Kirliliği\n\n")
        f.write(f"**Tarih:** {datetime.now().strftime('%Y-%m-%d %H:%M')}\n\n")
        f.write(f"## Özet İstatistikler\n\n")
        f.write(f"- **Marka tıklama oranı:** %{marka_tiklama_orani:.1f}\n")
        f.write(f"- **Marka gösterim oranı:** %{marka_gosterim_orani:.1f}\n")
        f.write(f"- **Marka sorgu sayısı:** {len(marka_sorgulari)}\n")
        f.write(f"- **Markasız sorgu sayısı:** {len(markasiz_sorgular)}\n\n")
        f.write(f"## Markalı vs Markasız Karşılaştırma\n\n")
        f.write(karsilastirma.to_markdown(index=False))
        f.write(f"\n\n## Marka Sorguları (İlk 20)\n\n")
        f.write(marka_sonuc.head(20).to_markdown(index=False))
        f.write(f"\n\n## 🤖 Gemini AI Yorumu\n\n{yorum}\n")

    print(f"  ✅ Rapor → {md_path}")
    return marka_sonuc, yorum


def analiz_ranking_volatility(df_page_date):
    """
    4. Ranking Volatility (Pozisyon Tutarsızlığı)
    Her sayfa için pozisyon standart sapması hesapla.
    """
    print("\n📊 4. Ranking Volatility Tespiti...")

    if df_page_date.empty:
        print("  ⚠️  Veri bulunamadı.")
        return pd.DataFrame(), ""

    # Her sayfa için pozisyon istatistikleri
    volatilite = df_page_date.groupby('page')['pozisyon'].agg([
        'mean',   # ortalama pozisyon
        'std',    # standart sapma
        'min',    # en iyi pozisyon
        'max'     # en kötü pozisyon
    ]).reset_index()

    # Toplam tıklama ve gösterimi de ekle
    trafik = df_page_date.groupby('page').agg(
        toplam_tiklama=('tiklamalar', 'sum'),
        toplam_gosterim=('gosterimler', 'sum'),
        gun_sayisi=('date', 'nunique')
    ).reset_index()

    volatilite = volatilite.merge(trafik, on='page', how='left')

    # NaN olan std değerlerini 0 yap (tek gün verisi olan sayfalar)
    volatilite['std'] = volatilite['std'].fillna(0)

    # Yüksek volatilite: std > 3
    yuksek_volatilite = volatilite[
        volatilite['std'] > 3
    ].sort_values('std', ascending=False)

    yuksek_volatilite.columns = ['Sayfa', 'Ort. Pozisyon', 'Std. Sapma',
                                  'Min Pozisyon', 'Max Pozisyon',
                                  'Toplam Tıklama', 'Toplam Gösterim', 'Gün Sayısı']
    yuksek_volatilite['Ort. Pozisyon'] = yuksek_volatilite['Ort. Pozisyon'].round(1)
    yuksek_volatilite['Std. Sapma'] = yuksek_volatilite['Std. Sapma'].round(2)
    yuksek_volatilite['Min Pozisyon'] = yuksek_volatilite['Min Pozisyon'].round(1)
    yuksek_volatilite['Max Pozisyon'] = yuksek_volatilite['Max Pozisyon'].round(1)

    # CSV kaydet
    csv_path = os.path.join(SONUCLAR_DIR, '4_ranking_volatility.csv')
    yuksek_volatilite.to_csv(csv_path, index=False, encoding='utf-8-sig')
    print(f"  ✅ {len(yuksek_volatilite)} yüksek volatiliteli sayfa → {csv_path}")

    # Gemini yorumu
    ozet = f"""Toplam analiz edilen sayfa: {len(volatilite)}
Yüksek volatiliteli sayfa (std > 3): {len(yuksek_volatilite)}

En yüksek volatiliteli ilk 20 sayfa:
{yuksek_volatilite.head(20).to_string(index=False)}"""

    yorum = gemini_yorum("Ranking Volatility (Pozisyon Tutarsızlığı)", ozet)

    # MD rapor kaydet
    md_path = os.path.join(SONUCLAR_DIR, '4_ranking_volatility.md')
    with open(md_path, 'w', encoding='utf-8') as f:
        f.write("# 🟣 Ranking Volatility — Pozisyon Tutarsızlığı\n\n")
        f.write(f"**Tarih:** {datetime.now().strftime('%Y-%m-%d %H:%M')}\n\n")
        f.write(f"## Özet İstatistikler\n\n")
        f.write(f"- **Toplam analiz edilen sayfa:** {len(volatilite)}\n")
        f.write(f"- **Yüksek volatiliteli sayfa (std > 3):** {len(yuksek_volatilite)}\n\n")
        f.write(f"## En Yüksek Volatiliteli 20 Sayfa\n\n")
        f.write(yuksek_volatilite.head(20).to_markdown(index=False))
        f.write(f"\n\n## 🤖 Gemini AI Yorumu\n\n{yorum}\n")

    print(f"  ✅ Rapor → {md_path}")
    return yuksek_volatilite, yorum


def analiz_ctr_anomaly(df_query_page):
    """
    5. CTR Anomalisi
    Pozisyona göre beklenenin yarısından az CTR → anomali.
    """
    print("\n📊 5. CTR Anomalisi Tespiti...")

    if df_query_page.empty:
        print("  ⚠️  Veri bulunamadı.")
        return pd.DataFrame(), ""

    df = df_query_page.copy()

    # Pozisyonu tam sayıya yuvarla
    df['poz_tam'] = df['pozisyon'].round().astype(int)

    # Beklenen CTR'yi ekle
    df['beklenen_ctr'] = df['poz_tam'].map(BEKLENEN_CTR)

    # Sadece ilk 10 pozisyondakileri al (benchmark tanımlı olanlar)
    df = df[df['beklenen_ctr'].notna()].copy()

    # Anomali: beklenenin yarısından az CTR
    df['ctr_anomali'] = df['ctr'] < (df['beklenen_ctr'] * 0.5)

    # Gösterim > 1000 olanları filtrele
    anomaliler = df[
        df['ctr_anomali'] &
        (df['gosterimler'] > 1000)
    ].sort_values('gosterimler', ascending=False)

    sonuc = anomaliler[['query', 'page', 'pozisyon', 'ctr',
                         'beklenen_ctr', 'gosterimler', 'tiklamalar']].copy()
    sonuc.columns = ['Sorgu', 'Sayfa', 'Pozisyon', 'Gerçek CTR (%)',
                     'Beklenen CTR (%)', 'Gösterim', 'Tıklama']
    sonuc['Pozisyon'] = sonuc['Pozisyon'].round(1)
    sonuc['Gerçek CTR (%)'] = sonuc['Gerçek CTR (%)'].round(2)

    # CSV kaydet
    csv_path = os.path.join(SONUCLAR_DIR, '5_ctr_anomaly.csv')
    sonuc.to_csv(csv_path, index=False, encoding='utf-8-sig')
    print(f"  ✅ {len(sonuc)} CTR anomalisi bulundu → {csv_path}")

    # Gemini yorumu
    ozet = f"""Toplam CTR anomalisi (gösterim > 1000, CTR < beklenenin yarısı): {len(sonuc)}
Kaybedilen potansiyel tıklama tahmini: {sonuc['Gösterim'].sum():,} gösterime rağmen sadece {sonuc['Tıklama'].sum():,} tıklama

CTR Benchmark Tablosu:
Pozisyon 1: %28.5 | Pozisyon 2: %15.7 | Pozisyon 3: %11.0
Pozisyon 4: %8.0  | Pozisyon 5: %7.2  | Pozisyon 6: %5.1

En kritik ilk 20 anomali:
{sonuc.head(20).to_string(index=False)}"""

    yorum = gemini_yorum("CTR Anomalisi", ozet)

    # MD rapor kaydet
    md_path = os.path.join(SONUCLAR_DIR, '5_ctr_anomaly.md')
    with open(md_path, 'w', encoding='utf-8') as f:
        f.write("# 🔵 CTR Anomalisi — Beklenenden Düşük CTR\n\n")
        f.write(f"**Tarih:** {datetime.now().strftime('%Y-%m-%d %H:%M')}\n\n")
        f.write(f"## Özet İstatistikler\n\n")
        f.write(f"- **Toplam CTR anomalisi:** {len(sonuc)}\n")
        f.write(f"- **Etkilenen toplam gösterim:** {sonuc['Gösterim'].sum():,}\n")
        f.write(f"- **Mevcut tıklama:** {sonuc['Tıklama'].sum():,}\n\n")
        f.write("## CTR Benchmark Tablosu\n\n")
        f.write("| Pozisyon | Beklenen CTR (%) |\n|----------|------------------|\n")
        for poz, ctr in BEKLENEN_CTR.items():
            f.write(f"| {poz} | {ctr} |\n")
        f.write(f"\n## En Kritik 20 Anomali\n\n")
        f.write(sonuc.head(20).to_markdown(index=False))
        f.write(f"\n\n### Olası Nedenler\n\n")
        f.write("- Title tag zayıf veya spam görünümlü\n")
        f.write("- Meta description yok veya kötü\n")
        f.write("- Rich snippet kaybedilmiş\n")
        f.write("- SGE/AI snippet kapıyor\n")
        f.write("- Rakip daha iyi rich result alıyor\n")
        f.write(f"\n## 🤖 Gemini AI Yorumu\n\n{yorum}\n")

    print(f"  ✅ Rapor → {md_path}")
    return sonuc, yorum


# ─── Özet Rapor ───────────────────────────────────────────────────────────────

def ozet_rapor_olustur(sonuclar, site_url, days):
    """Tüm analizlerin özetini tek bir MD dosyasında topla."""
    print("\n📋 Özet rapor oluşturuluyor...")

    md_path = os.path.join(SONUCLAR_DIR, '0_ozet_rapor.md')

    # Özet veriyi Gemini'ye gönder
    ozet_metin = f"""Site: {site_url}
Analiz Dönemi: Son {days} gün
Analiz Tarihi: {datetime.now().strftime('%Y-%m-%d %H:%M')}

Analiz Sonuçları Özeti:
1. Cannibalization: {len(sonuclar['cannibalization'])} aynı sorgunun birden fazla sayfada göründüğü tespit edildi
2. Intent Mismatch: {len(sonuclar['intent_mismatch'])} sorgu-sayfa uyumsuzluğu bulundu
3. Brand Contamination: {len(sonuclar['brand'])} marka sorgusu analiz edildi
4. Ranking Volatility: {len(sonuclar['volatility'])} yüksek volatiliteli sayfa tespit edildi
5. CTR Anomaly: {len(sonuclar['ctr_anomaly'])} beklenenin altında CTR'ye sahip sorgu bulundu"""

    genel_yorum = gemini_yorum("Genel SEO Sağlık Raporu", ozet_metin)

    with open(md_path, 'w', encoding='utf-8') as f:
        f.write(f"# 📊 SEO Analiz Raporu — Genel Özet\n\n")
        f.write(f"**Site:** `{site_url}`\n\n")
        f.write(f"**Analiz Dönemi:** Son {days} gün\n\n")
        f.write(f"**Tarih:** {datetime.now().strftime('%Y-%m-%d %H:%M')}\n\n")
        f.write("---\n\n")
        f.write("## 📈 Analiz Sonuçları\n\n")
        f.write("| # | Analiz | Bulgu Sayısı | Dosya |\n")
        f.write("|---|--------|-------------|-------|\n")
        f.write(f"| 1 | 🔴 Cannibalization | {len(sonuclar['cannibalization'])} sorgu | `1_cannibalization.csv` + `.md` |\n")
        f.write(f"| 2 | 🟠 Intent Mismatch | {len(sonuclar['intent_mismatch'])} uyumsuzluk | `2_intent_mismatch.csv` + `.md` |\n")
        f.write(f"| 3 | 🟡 Brand Contamination | {len(sonuclar['brand'])} marka sorgusu | `3_brand_contamination.csv` + `.md` |\n")
        f.write(f"| 4 | 🟣 Ranking Volatility | {len(sonuclar['volatility'])} yüksek volatilite | `4_ranking_volatility.csv` + `.md` |\n")
        f.write(f"| 5 | 🔵 CTR Anomaly | {len(sonuclar['ctr_anomaly'])} anomali | `5_ctr_anomaly.csv` + `.md` |\n\n")
        f.write("---\n\n")
        f.write(f"## 🤖 Gemini AI — Genel Değerlendirme\n\n{genel_yorum}\n")

    print(f"  ✅ Özet rapor → {md_path}")


# ─── Ana Program ──────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description='Google Search Console SEO Analiz Betikleri',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Örnek kullanım:
  python3 seo_analiz.py --list-sites
  python3 seo_analiz.py --site "sc-domain:mutfak10.com"
  python3 seo_analiz.py --site "sc-domain:mutfak10.com" --days 180
  python3 seo_analiz.py --reauth --site "sc-domain:mutfak10.com"
        """
    )
    parser.add_argument('--site', help='GSC site URL\'si (örn: sc-domain:mutfak10.com)')
    parser.add_argument('--days', type=int, default=90, help='Kaç günlük veri çekilsin (varsayılan: 90)')
    parser.add_argument('--list-sites', action='store_true', help='Erişilebilir GSC sitelerini listele')
    parser.add_argument('--reauth', action='store_true', help='Token\'ı silip yeniden giriş yap (farklı hesap için)')

    args = parser.parse_args()

    # Yeniden kimlik doğrulama
    if args.reauth and os.path.exists(TOKEN_FILE):
        os.remove(TOKEN_FILE)
        print("🔄 Eski token silindi, yeniden giriş yapılacak...\n")

    # Siteleri listele modu
    if args.list_sites:
        print("🔗 Google Search Console'a bağlanılıyor...")
        service = gsc_baglantisi()
        print("  ✅ Bağlantı başarılı!")
        gsc_siteleri_listele(service)
        print("\n💡 Analiz başlatmak için: python3 seo_analiz.py --site \"<SITE_URL>\"")
        return

    if not args.site:
        parser.error("--site parametresi gerekli (veya --list-sites kullanın)")

    print("=" * 60)
    print("🔍 SEO ANALİZ BETİKLERİ")
    print("=" * 60)
    print(f"📌 Site: {args.site}")
    print(f"📅 Dönem: Son {args.days} gün")
    print(f"📂 Sonuçlar: {SONUCLAR_DIR}/")
    print("=" * 60)

    # Sonuçlar klasörünü oluştur
    os.makedirs(SONUCLAR_DIR, exist_ok=True)

    # Tarih aralığı
    end_date = datetime.now().strftime('%Y-%m-%d')
    start_date = (datetime.now() - timedelta(days=args.days)).strftime('%Y-%m-%d')
    print(f"\n📅 Tarih aralığı: {start_date} → {end_date}")

    # GSC bağlantısı
    print("\n🔗 Google Search Console'a bağlanılıyor...")
    service = gsc_baglantisi()
    print("  ✅ Bağlantı başarılı!")

    # ─── Veri Çekme ───────────────────────────────────────────────────────

    # 1. query + page boyutlu veri (cannibalization, intent mismatch, CTR anomaly, brand)
    print("\n📥 Veri çekiliyor: query + page...")
    rows_query_page = gsc_veri_cek(service, args.site,
                                    ['query', 'page'],
                                    start_date, end_date)
    df_query_page = satirlari_df_yap(rows_query_page, ['query', 'page'])
    print(f"  ✅ {len(df_query_page)} satır çekildi")

    # 2. page + date boyutlu veri (volatility)
    print("\n📥 Veri çekiliyor: page + date...")
    rows_page_date = gsc_veri_cek(service, args.site,
                                   ['page', 'date'],
                                   start_date, end_date)
    df_page_date = satirlari_df_yap(rows_page_date, ['page', 'date'])
    print(f"  ✅ {len(df_page_date)} satır çekildi")

    # ─── Analizler ────────────────────────────────────────────────────────

    sonuclar = {}

    # 1. Cannibalization
    sonuclar['cannibalization'], _ = analiz_cannibalization(df_query_page)

    # 1b. Cannibalization (Trailing Slash Hariç)
    sonuclar['cannibalization_clean'], _ = analiz_cannibalization_without_backslash(df_query_page)

    # 2. Intent Mismatch
    sonuclar['intent_mismatch'], _ = analiz_intent_mismatch(df_query_page)

    # 3. Brand Contamination
    sonuclar['brand'], _ = analiz_brand_contamination(df_query_page)

    # 4. Ranking Volatility
    sonuclar['volatility'], _ = analiz_ranking_volatility(df_page_date)

    # 5. CTR Anomaly
    sonuclar['ctr_anomaly'], _ = analiz_ctr_anomaly(df_query_page)

    # ─── Özet Rapor ───────────────────────────────────────────────────────

    ozet_rapor_olustur(sonuclar, args.site, args.days)

    # ─── Bitiş ────────────────────────────────────────────────────────────

    print("\n" + "=" * 60)
    print("✅ TÜM ANALİZLER TAMAMLANDI!")
    print("=" * 60)
    print(f"\n📂 Sonuçlar '{SONUCLAR_DIR}/' klasöründe:")
    print("   0_ozet_rapor.md           — Genel özet ve AI değerlendirmesi")
    print("   1_cannibalization.csv/.md  — Cannibalization tespiti")
    print("   2_intent_mismatch.csv/.md  — Sorgu-sayfa uyumsuzluğu")
    print("   3_brand_contamination.csv/.md — Marka kirliliği")
    print("   4_ranking_volatility.csv/.md — Pozisyon tutarsızlığı")
    print("   5_ctr_anomaly.csv/.md      — CTR anomalisi")
    print()


if __name__ == '__main__':
    main()
