import sys
import pandas as pd
from prophet import Prophet
import json
import warnings
import os

# Uyarıları gizle
warnings.filterwarnings('ignore')
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'

try:
    if len(sys.argv) < 3:
        raise Exception("Eksik argüman. Kullanım: python prophet_tahmin.py <csv_yolu> <tahmin_gunu>")

    csv_path = sys.argv[1]
    periods = int(sys.argv[2])

    # GSC'den çekilen CSV dosyasını oku
    df = pd.read_csv(csv_path)

    # Veriyi tarih sırasına diz
    df['ds'] = pd.to_datetime(df['ds'])
    df = df.sort_values(by='ds')

    # Prophet modelini oluştur (Türk tatilleri için Türkiye ayarı da yapılabilir ileride)
    m = Prophet(
        yearly_seasonality=True,
        weekly_seasonality=True,
        daily_seasonality=False,
        changepoint_prior_scale=0.01 # Anti-Panik (Az esnek modelleme)
    )
    
    m.fit(df)

    # İstenilen gün kadar (+ periods) gelecek tarihler oluştur
    future = m.make_future_dataframe(periods=periods)
    
    # Tahmini yap (yhat, yhat_lower, yhat_upper)
    forecast = m.predict(future)

    # Sadece UI'a göndereceğimiz gerekli sütunları al, NaN'leri temizle ve JSON'a çevir
    result_df = forecast[['ds', 'yhat', 'yhat_lower', 'yhat_upper']]
    result_df['ds'] = result_df['ds'].dt.strftime('%Y-%m-%d')
    
    # Değerleri integer'a yuvarla (Trafik/Gösterim kesirli olmaz)
    result_df['yhat'] = result_df['yhat'].clip(lower=0).round().astype(int)
    result_df['yhat_lower'] = result_df['yhat_lower'].clip(lower=0).round().astype(int)
    result_df['yhat_upper'] = result_df['yhat_upper'].clip(lower=0).round().astype(int)

    # --- METRICS CALCULATIONS ---

    # 1. Expected Traffic Increase / Decrease
    # Compare sum of last 'periods' historical days vs sum of 'periods' forecast days
    last_historical_sum = df['y'].tail(periods).sum()
    forecast_sum = result_df['yhat'].tail(periods).sum()
    
    if last_historical_sum > 0:
        increase_percent = ((forecast_sum - last_historical_sum) / last_historical_sum) * 100
    else:
        increase_percent = 0.0

    # 2. Anomaly Detection
    # Join forecast with historical df to compare actual 'y' with 'yhat_lower'
    historical_forecast = forecast.set_index('ds').join(df.set_index('ds'))
    historical_forecast = historical_forecast.dropna(subset=['y'])
    
    # We count days where actual traffic was below the lower confidence interval
    anomalies_count = len(historical_forecast[historical_forecast['y'] < historical_forecast['yhat_lower']])

    # 3. Seasonality Peak
    # Find the month with the highest average yearly seasonality component
    if 'yearly' in forecast.columns:
        forecast_copy = forecast.copy()
        forecast_copy['month'] = forecast_copy['ds'].dt.month
        monthly_seasonality = forecast_copy.groupby('month')['yearly'].mean()
        peak_month_num = monthly_seasonality.idxmax()
        months_tr = {1: 'Ocak', 2: 'Şubat', 3: 'Mart', 4: 'Nisan', 5: 'Mayıs', 6: 'Haziran', 
                     7: 'Temmuz', 8: 'Ağustos', 9: 'Eylül', 10: 'Ekim', 11: 'Kasım', 12: 'Aralık'}
        seasonality_peak = months_tr.get(peak_month_num, "Bilinmiyor")
    else:
        seasonality_peak = "Yeterli Veri Yok"

    # Çıktıyı JSON array olarak stdout'a bas (PHP'nin okuması için)
    print(json.dumps({
        "status": "success",
        "data": result_df.to_dict(orient='records'),
        "metrics": {
            "expected_increase_percent": round(increase_percent, 1),
            "anomaly_count": int(anomalies_count),
            "seasonality_peak": seasonality_peak,
            "period": periods
        }
    }))

except Exception as e:
    print(json.dumps({
        "status": "error",
        "message": str(e)
    }))
