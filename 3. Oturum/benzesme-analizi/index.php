<?php
$result = [];
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['text_lines'])) {
    $text = $_POST['text_lines'];
    $lines = explode("\n", $text);
    $lines = array_map('trim', $lines);
    // Boş satırları çıkar
    $lines = array_filter($lines, function($value) { return $value !== ''; });
    $lines = array_values($lines); // İndeksleri sıfırla
    
    $pairs = [];
    $count = count($lines);
    
    if ($count >= 2) {
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $percent = 0;
                similar_text($lines[$i], $lines[$j], $percent);
                $pairs[] = [
                    'A' => $lines[$i],
                    'B' => $lines[$j],
                    'similarity' => round($percent, 1)
                ];
            }
        }
        
        // Benzerlik oranına göre azalan şekilde sırala
        usort($pairs, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        $result = $pairs;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Metin Benzerlik Analizi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --primary: #8b5cf6;
            --primary-glow: rgba(139, 92, 246, 0.5);
            --secondary: #3b82f6;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: rgba(255, 255, 255, 0.1);
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            color: var(--text-main);
            min-height: 100vh;
            padding: 3rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 0;
            box-sizing: border-box;
        }

        *, *::before, *::after {
            box-sizing: inherit;
        }

        .container {
            width: 100%;
            max-width: 900px;
        }

        h1 {
            text-align: center;
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 2rem;
            background: linear-gradient(to right, #a78bfa, #60a5fa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 40px rgba(167, 139, 250, 0.3);
            animation: fadeInDown 0.8s ease-out;
        }
        
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .glass-panel {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            margin-bottom: 2.5rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            animation: fadeInUp 0.8s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .glass-panel:hover {
            box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.6);
        }

        label {
            display: block;
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 1rem;
            color: #e2e8f0;
        }

        textarea {
            width: 100%;
            height: 250px;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            color: var(--text-main);
            font-family: inherit;
            font-size: 1.1rem;
            resize: vertical;
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-glow);
            background: rgba(15, 23, 42, 0.8);
        }

        textarea::placeholder {
            color: var(--text-muted);
            opacity: 0.7;
        }

        button {
            width: 100%;
            padding: 1.25rem;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        button::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(90deg, var(--secondary), var(--primary));
            z-index: -1;
            transition: opacity 0.3s ease;
            opacity: 0;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px var(--primary-glow);
        }

        button:hover::before {
            opacity: 1;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .results-title {
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
            color: #fff;
        }
        
        .results-info {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .result-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .result-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 4px; height: 100%;
            background: var(--card-color, var(--primary));
            opacity: 0.8;
        }

        .result-card:hover {
            background: rgba(255, 255, 255, 0.06);
            transform: translateX(5px);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .texts {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .text-row {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .badge {
            flex-shrink: 0;
            padding: 0.35rem 0.85rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 45px;
        }

        .badge-a { color: #f472b6; border: 1px solid rgba(244, 114, 182, 0.3); background: rgba(244, 114, 182, 0.1); }
        .badge-b { color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); background: rgba(56, 189, 248, 0.1); }

        .text-content {
            font-size: 1.1rem;
            flex: 1;
            word-break: break-word;
            line-height: 1.5;
            padding-top: 0.2rem;
        }

        .score-circle {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.4);
            border: 3px solid var(--card-color, var(--primary));
            flex-shrink: 0;
            box-shadow: 0 0 20px var(--card-glow, rgba(0,0,0,0));
        }

        .score-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: #fff;
        }

        .score-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 2px;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-muted);
            font-size: 1.1rem;
        }

        @media (max-width: 640px) {
            .result-card {
                flex-direction: column;
                align-items: stretch;
            }
            .score-circle {
                align-self: center;
                margin-top: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Metin Benzerlik Analizi</h1>
        
        <div class="glass-panel">
            <form method="POST" action="">
                <label for="text_lines">Karşılaştırılacak metinleri girin (Her satıra bir ifade):</label>
                <textarea id="text_lines" name="text_lines" required placeholder="Örnek:&#10;Yapay Zeka Uygulamaları&#10;Yapay Zeka Kullanım Alanları&#10;Makine Öğrenmesi Derin Öğrenme&#10;Derin Öğrenme Algoritmaları"><?php echo isset($_POST['text_lines']) ? htmlspecialchars($_POST['text_lines']) : ''; ?></textarea>
                <button type="submit">En Benzerleri Bul</button>
            </form>
        </div>

        <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
        <div class="glass-panel" style="animation-delay: 0.2s;">
            <div class="results-header">
                <h2 class="results-title">Sonuçlar</h2>
                <div class="results-info">En yüksek benzerlikten en düşüğe sıralı</div>
            </div>

            <?php if (count($result) > 0): ?>
                <?php 
                // En fazla 50 sonuç göster
                $limit = min(50, count($result));
                for ($k = 0; $k < $limit; $k++): 
                    $pair = $result[$k];
                    
                    // Renk paleti belirleme (benzerlik oranına göre)
                    $score = $pair['similarity'];
                    if ($score >= 80) {
                        $color = '#10b981'; // Green
                        $glow = 'rgba(16, 185, 129, 0.3)';
                    } elseif ($score >= 50) {
                        $color = '#f59e0b'; // Amber
                        $glow = 'rgba(245, 158, 11, 0.3)';
                    } else {
                        $color = '#ef4444'; // Red
                        $glow = 'rgba(239, 68, 68, 0.3)';
                    }
                ?>
                <div class="result-card" style="--card-color: <?php echo $color; ?>; --card-glow: <?php echo $glow; ?>; animation: fadeInUp <?php echo 0.3 + ($k * 0.05); ?>s ease-out both;">
                    <div class="texts">
                        <div class="text-row">
                            <span class="badge badge-a">A</span>
                            <div class="text-content"><?php echo htmlspecialchars($pair['A']); ?></div>
                        </div>
                        <div class="text-row">
                            <span class="badge badge-b">B</span>
                            <div class="text-content"><?php echo htmlspecialchars($pair['B']); ?></div>
                        </div>
                    </div>
                    <div class="score-circle">
                        <span class="score-value">%<?php echo $pair['similarity']; ?></span>
                        <span class="score-label">Benzer</span>
                    </div>
                </div>
                <?php endfor; ?>
                
                <?php if(count($result) > 50): ?>
                    <div class="empty-state">
                        Kalan <?php echo count($result) - 50; ?> sonuç gizlendi (Sadece en yüksek 50 sonuç gösteriliyor).
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    Lütfen karşılaştırma yapmak için birbirinden farklı en az 2 satır metin girin.
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
