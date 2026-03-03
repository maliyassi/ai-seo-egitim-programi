import pandas as pd
import numpy as np

# Dosya yollari
pr_path = 'internal_pagerank_report.csv'
gsc_path = 'Sayfa sayısı.csv'
output_path = 'final_seo_power_score.csv'

try:
    print(f"Reading PageRank Data: {pr_path}")
    df_pr = pd.read_csv(pr_path)
    
    print(f"Reading GSC Data: {gsc_path}")
    df_gsc = pd.read_csv(gsc_path)
    
    # GSC verisinde "En alakalı sayfalar" sutununu "URL" olarak degistiriyoruz (Merge islemi icin)
    if 'En alakalı sayfalar' in df_gsc.columns:
        df_gsc.rename(columns={'En alakalı sayfalar': 'URL'}, inplace=True)
    
    # URL'lerdeki bosluklari vs temizliyoruz
    df_pr['URL'] = df_pr['URL'].str.strip()
    df_gsc['URL'] = df_gsc['URL'].str.strip()
    
    # Iki veri setini URL uzerinden birlestiriyoruz (Outer Join ile her iki taraftaki verileri kaybetmemek icin)
    df_merged = pd.merge(df_gsc, df_pr, on='URL', how='left')
    
    # NaN olan GSC veya PR metriklerini 0 ile dolduruyoruz
    df_merged['Tıklamalar'] = df_merged['Tıklamalar'].fillna(0)
    df_merged['Gösterimler'] = df_merged['Gösterimler'].fillna(0)
    df_merged['Internal PageRank'] = df_merged['Internal PageRank'].fillna(0)
    
    # --- 100 Uzerinden Puanlama Icin Min-Max Normalizasyon ---
    # 1. PageRank'i 0-100 Arasina Cekme
    pr_max = df_merged['Internal PageRank'].max()
    pr_min = df_merged['Internal PageRank'].min()
    
    if pr_max > pr_min:
        df_merged['PR_Score_100'] = ((df_merged['Internal PageRank'] - pr_min) / (pr_max - pr_min)) * 100
    else:
        df_merged['PR_Score_100'] = 0
        
    # 2. Tiklama (Trafik) ve Gosterimi de kendi icinde 0-100'e Cekme
    click_max = df_merged['Tıklamalar'].max()
    imp_max = df_merged['Gösterimler'].max()
    
    df_merged['Click_Score_100'] = (df_merged['Tıklamalar'] / click_max * 100) if click_max > 0 else 0
    df_merged['Impression_Score_100'] = (df_merged['Gösterimler'] / imp_max * 100) if imp_max > 0 else 0
    
    # --- Agirlikli SEO Guc Skoru (Weighted SEO Power Score) ---
    # Agirliklar (Ihtiyaca gore degistirilebilir):
    # %50 Iceriden Gelen Guc (Internal PageRank)
    # %35 Gercek Trafik (Tiklamalar)
    # %15 Gorunurluk (Gosterimler)
    
    df_merged['Final_SEO_Power_Score'] = (
        (df_merged['PR_Score_100'] * 0.50) + 
        (df_merged['Click_Score_100'] * 0.35) + 
        (df_merged['Impression_Score_100'] * 0.15)
    )
    
    # Skorlari yuvarliyoruz
    df_merged['Final_SEO_Power_Score'] = df_merged['Final_SEO_Power_Score'].round(2)
    df_merged['PR_Score_100'] = df_merged['PR_Score_100'].round(2)
    
    # En gucluye gore siralama
    df_final = df_merged.sort_values(by='Final_SEO_Power_Score', ascending=False)
    
    # Sadece anlamli kolonlari secme ve disari aktarma
    export_columns = ['URL', 'Tıklamalar', 'Gösterimler', 'Internal PageRank', 'PR_Score_100', 'Final_SEO_Power_Score']
    df_export = df_final[export_columns]
    
    df_export.to_csv(output_path, index=False)
    
    print(f"\n=======================================================")
    print(f"Birlestirme ve Skorlama Tamamlandi!")
    print(f"Toplam Analiz Edilen URL Sayisi: {len(df_export)}")
    print(f"Rapor Kaydedildi: {output_path}")
    print(f"=======================================================\n")
    print("En Guclu Ilk 10 Sayfa (Agirlikli Skora Gore):\n")
    print(df_export.head(10).to_string(index=False))

except Exception as e:
    print(f"An error occurred: {e}")
