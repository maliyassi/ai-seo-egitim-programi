document.addEventListener('DOMContentLoaded', () => {

    // UI Elements
    const runAnalysisBtn = document.getElementById('runAnalysisBtn');
    const loadingState = document.getElementById('loadingState');
    const resultsArea = document.getElementById('resultsArea');
    const ctx = document.getElementById('prophetChart').getContext('2d');

    let prophetChartInstance = null;

    // Chart'ı Çizme Fonksiyonu
    function renderChart(data) {
        if (prophetChartInstance) {
            prophetChartInstance.destroy();
        }

        prophetChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels, // Birleştirilmiş tarihler
                datasets: [
                    {
                        label: 'Gerçekleşen Trafik (y)',
                        data: data.historicalData,
                        borderColor: 'rgba(248, 250, 252, 0.8)',
                        backgroundColor: 'transparent',
                        borderWidth: 1.5,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        tension: 0.4 // Daha yumuşak kıvrımlar
                    },
                    {
                        label: 'Tahmin (yhat)',
                        data: data.forecastData,
                        borderColor: '#8b5cf6', // Violet Accent (daha premium)
                        borderDash: [5, 4],
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        tension: 0.4
                    },
                    {
                        label: 'Güven Aralığı Üst (yhat_upper)',
                        data: data.upperBound,
                        borderColor: 'transparent',
                        backgroundColor: 'rgba(139, 92, 246, 0.15)', // Violet glow
                        fill: '+1',
                        pointRadius: 0,
                        tension: 0.4
                    },
                    {
                        label: 'Güven Aralığı Alt (yhat_lower)',
                        data: data.lowerBound,
                        borderColor: 'transparent',
                        backgroundColor: 'transparent',
                        fill: false,
                        pointRadius: 0,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        labels: {
                            color: '#e2e8f0',
                            usePointStyle: true,
                            boxWidth: 8
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.9)',
                        titleColor: '#fff',
                        bodyColor: '#cbd5e1',
                        borderColor: 'rgba(255,255,255,0.1)',
                        borderWidth: 1,
                        padding: 12
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.03)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#64748b',
                            maxTicksLimit: 8,
                            maxRotation: 0
                        }
                    },
                    y: {
                        beginAtZero: false, // Y ekseninin veriye göre ayarlanmasını sağlar! (Hayat kurtarıcı)
                        grace: '10%', // Üst ve alttan %10 nefes alma payı verir
                        grid: {
                            color: 'rgba(255, 255, 255, 0.03)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#64748b',
                            padding: 10
                        }
                    }
                }
            }
        });
    }

    // Buton Tıklama Olayı
    runAnalysisBtn?.addEventListener('click', async () => {
        const property = document.getElementById('propertySelect').value;
        const metric = document.getElementById('metricSelect').value;
        const period = document.getElementById('forecastPeriod').value;

        if (!property) {
            alert('Lütfen analiz edilecek bir GSC mülkü seçin.');
            return;
        }

        // Form alanlarını disable et
        const inputs = document.querySelectorAll('select, input, button');
        inputs.forEach(el => el.disabled = true);
        runAnalysisBtn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> GSC\'den Veri Çekiliyor...';

        // Tabloyu gizle, loading göster
        resultsArea.style.opacity = '0.3';
        loadingState.classList.remove('hidden');

        try {
            // 1. Adım: GSC'den geçmiş verileri çek
            const gscResponse = await fetch(`getData.php?siteUrl=${encodeURIComponent(property)}&metric=${metric}`);
            const gscData = await gscResponse.json();

            if (gscData.error) {
                throw new Error(gscData.error);
            }

            runAnalysisBtn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> Prophet Modeli Eğitiliyor...';

            // 2. Adım: Python Prophet'i çalıştır ve tahminleri al
            const filename = gscData.csv_file.split('/').pop();
            const prophetResponse = await fetch(`runProphet.php?csv=${filename}&period=${period}`);
            const textResponse = await prophetResponse.text();

            let prophetData;
            try {
                prophetData = JSON.parse(textResponse);
            } catch (e) {
                console.error("Ham Çıktı:", textResponse);
                throw new Error("Python script dönüşü JSON değil. (Konsolu kontrol et)");
            }

            if (prophetData.status === 'error') {
                throw new Error("Python Hatası: " + prophetData.message);
            }

            // Metrik Kartlarını Güncelle
            if (prophetData.metrics) {
                const metrics = prophetData.metrics;

                // Beklenen Trafik Artışı
                const expectedIncreaseVal = document.getElementById('expectedIncreaseVal');
                if (expectedIncreaseVal) {
                    const sign = metrics.expected_increase_percent > 0 ? '+' : '';
                    expectedIncreaseVal.textContent = sign + metrics.expected_increase_percent + '%';
                    if (metrics.expected_increase_percent < 0) {
                        expectedIncreaseVal.style.color = '#ef4444'; // Kırmızı (Düşüş)
                    } else {
                        expectedIncreaseVal.style.color = '#10b981'; // Yeşil (Artış)
                        // veya default renk için bırakılabilir
                    }
                }
                const expectedIncreaseDesc = document.getElementById('expectedIncreaseDesc');
                if (expectedIncreaseDesc) {
                    expectedIncreaseDesc.textContent = `Önümüzdeki ${metrics.period} gün için`;
                }

                // Anomali Tespiti
                const anomalyCountVal = document.getElementById('anomalyCountVal');
                if (anomalyCountVal) {
                    anomalyCountVal.textContent = metrics.anomaly_count + ' Uyarı';
                }

                // Sezonallık Zirvesi
                const seasonalityPeakVal = document.getElementById('seasonalityPeakVal');
                if (seasonalityPeakVal) {
                    seasonalityPeakVal.textContent = metrics.seasonality_peak;
                }
            }

            // 3. Adım: Verileri birleştirip Chart.js'e ver
            const combinedLabels = [];
            const historicalArr = [];
            const forecastArr = [];
            const lowerBoundArr = [];
            const upperBoundArr = [];

            // Geçmiş GSC verilerini listele
            const histLabels = gscData.historical.labels;
            const histValues = gscData.historical.values;

            for (let i = 0; i < histLabels.length; i++) {
                combinedLabels.push(histLabels[i]);
                historicalArr.push(histValues[i]);
                forecastArr.push(null);
                lowerBoundArr.push(null);
                upperBoundArr.push(null);
            }

            // Son geçmiş tarihin değerini, tahmin çizgisinin başlangıcına bağlamak için saklayalım
            const lastHistoricalValue = histValues[histValues.length - 1];

            // Gelecek Prophet verilerini işleyip listeye ekle
            const forecasts = prophetData.data;
            let isFirstForecast = true;

            forecasts.forEach(f => {
                // Eğer bu tarih geçmiş verilerde yoksa listeye ekle (future dataframe olduğu için eski verileri de predict eder, biz sadece yenilerini alalım)
                if (!histLabels.includes(f.ds)) {
                    combinedLabels.push(f.ds);
                    historicalArr.push(null);

                    if (isFirstForecast) {
                        // Çizginin kopmaması için ilk tahmin verisinde history ve forecast aynı yerden başlar
                        forecastArr[forecastArr.length - 1] = lastHistoricalValue;
                        lowerBoundArr[lowerBoundArr.length - 1] = lastHistoricalValue;
                        upperBoundArr[upperBoundArr.length - 1] = lastHistoricalValue;
                        isFirstForecast = false;
                    }

                    forecastArr.push(f.yhat);
                    lowerBoundArr.push(f.yhat_lower);
                    upperBoundArr.push(f.yhat_upper);
                }
            });

            // Grafiği çiz
            renderChart({
                labels: combinedLabels,
                historicalData: historicalArr,
                forecastData: forecastArr,
                lowerBound: lowerBoundArr,
                upperBound: upperBoundArr
            });

        } catch (error) {
            alert("Hata oluştu: " + error.message);
        } finally {
            // UI'ı eski haline getir
            inputs.forEach(el => el.disabled = false);
            runAnalysisBtn.innerHTML = '<i class="ph ph-magic-wand"></i> Analizi Başlat';
            loadingState.classList.add('hidden');
            resultsArea.style.opacity = '1';
        }
    });

    // Sayfa yüklendiğinde boş chart'ı tetiklemek istersen:
    // const initialData = generateMockData();
    // renderChart(initialData);
});
