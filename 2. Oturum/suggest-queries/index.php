<?php
// Handle AJAX Request for Google Suggest
if (isset($_GET['action']) && $_GET['action'] == 'fetch' && isset($_GET['q'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $query = $_GET['q'];
    // Construct the Google Suggest URL, output=toolbar gives XML
    $url = 'https://suggestqueries.google.com/complete/search?output=toolbar&hl=tr&q=' . urlencode($query);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Masquerade as a real browser
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36');
    $response = curl_exec($ch);
    curl_close($ch);
    
    $suggestions = [];
    if ($response) {
        $xml = @simplexml_load_string($response);
        if ($xml && isset($xml->CompleteSuggestion)) {
            foreach ($xml->CompleteSuggestion as $sugg) {
                if (isset($sugg->suggestion)) {
                    $suggestions[] = (string)$sugg->suggestion['data'];
                }
            }
        }
    }
    
    echo json_encode(['query' => $query, 'suggestions' => $suggestions]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Anahtar Kelime Keşfedici</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #020617;
            --card-bg: rgba(15, 23, 42, 0.6);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --accent: #8b5cf6;
            --border: rgba(255, 255, 255, 0.08);
            --success: #10b981;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg-base);
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(59, 130, 246, 0.15), transparent 25%),
                radial-gradient(circle at 85% 30%, rgba(139, 92, 246, 0.15), transparent 25%);
            background-attachment: fixed;
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 4rem 1.5rem;
            line-height: 1.5;
        }

        .container {
            width: 100%;
            max-width: 850px;
        }

        .header {
            text-align: center;
            margin-bottom: 3.5rem;
            animation: fadeDown 0.8s ease-out;
        }

        .header h1 {
            font-size: 3.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #60a5fa, #d8b4fe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
        }

        .header p {
            color: var(--text-muted);
            font-size: 1.25rem;
            max-width: 600px;
            margin: 0 auto;
        }

        .search-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            margin-bottom: 2rem;
            animation: fadeIn 1s ease-out;
        }

        .input-group {
            display: flex;
            gap: 1rem;
            position: relative;
        }

        input[type="text"] {
            flex: 1;
            background: rgba(2, 6, 23, 0.5);
            border: 1px solid var(--border);
            color: var(--text-main);
            padding: 1.25rem 1.5rem;
            font-size: 1.15rem;
            border-radius: 16px;
            outline: none;
            transition: all 0.3s ease;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
        }

        input[type="text"]::placeholder {
            color: #475569;
        }

        input[type="text"]:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15), inset 0 2px 4px rgba(0,0,0,0.2);
        }

        button.btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            padding: 0 2.5rem;
            font-size: 1.15rem;
            font-weight: 600;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 14px 0 rgba(99, 102, 241, 0.39);
        }

        button.btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
        }

        button.btn-primary:active:not(:disabled) {
            transform: translateY(0);
        }

        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            background: #334155;
            box-shadow: none;
        }

        .progress-container {
            display: none;
            margin-top: 2rem;
            animation: fadeIn 0.5s ease-out;
        }

        .progress-bar-bg {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border);
            height: 12px;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 0.75rem;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.3);
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #a855f7);
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 6px;
            position: relative;
            overflow: hidden;
        }

        .progress-bar::after {
            content: "";
            position: absolute;
            top: 0; left: 0; bottom: 0; right: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transform: translateX(-100%);
            animation: shimmer 1.5s infinite;
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            color: var(--text-muted);
            font-size: 0.95rem;
            font-weight: 500;
        }

        .results {
            display: none;
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.6s ease-out;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
        }

        .results-header h2 {
            font-size: 1.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .badge {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 1rem;
            font-weight: 700;
        }

        .actions {
            display: flex;
            gap: 1rem;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text-main);
            padding: 0.6rem 1.25rem;
            font-size: 0.95rem;
            font-weight: 500;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .tag-cloud {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            max-height: 500px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }

        /* Custom Scrollbar */
        .tag-cloud::-webkit-scrollbar {
            width: 6px;
        }
        .tag-cloud::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 10px;
        }
        .tag-cloud::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        .category-group {
            background: rgba(2, 6, 23, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            width: 100%;
            animation: fadeIn 0.4s ease forwards;
        }
        .category-title {
            font-size: 1.15rem;
            font-weight: 600;
            color: #60a5fa;
            margin-bottom: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-bottom: 0.5rem;
        }
        .category-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
        }
        .tab-active {
            background: rgba(59, 130, 246, 0.2) !important;
            border-color: rgba(59, 130, 246, 0.5) !important;
            color: #fff !important;
        }

        .tag {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.05);
            padding: 0.6rem 1.25rem;
            border-radius: 12px;
            font-size: 1rem;
            color: #e2e8f0;
            animation: scaleIn 0.3s ease forwards;
            transition: all 0.2s;
            cursor: default;
        }

        .tag:hover {
            background: rgba(59, 130, 246, 0.15);
            border-color: rgba(59, 130, 246, 0.3);
            color: #fff;
            transform: translateY(-2px);
        }

        /* Spinner */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s linear infinite;
        }

        @keyframes spin { 100% { transform: rotate(360deg); } }
        @keyframes fadeDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        @keyframes shimmer { 100% { transform: translateX(100%); } }

        /* Responsive */
        @media (max-width: 640px) {
            .input-group { flex-direction: column; }
            button.btn-primary { padding: 1.25rem; }
            .header h1 { font-size: 2.5rem; }
            .results-header { flex-direction: column; gap: 1rem; align-items: flex-start; }
            .search-card, .results { padding: 1.5rem; }
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <h1>Keyword Explorer Pro</h1>
            <p>Hızlı ve otomatik olarak Google önerilerini (A-Z) çekin ve SEO kelime havuzunuzu genişletin.</p>
        </div>

        <div class="search-card">
            <div class="input-group">
                <input type="text" id="keyword" placeholder="Anahtar kelimenizi girin (örn: seo eğitimi)" autocomplete="off" onkeypress="handleEnter(event)">
                <button id="start-btn" class="btn-primary" onclick="startScraping()">
                    <span id="btn-text">İterasyonu Başlat</span>
                    <div id="btn-spinner" class="spinner"></div>
                </button>
            </div>

            <div class="progress-container" id="progress-container">
                <div class="progress-bar-bg">
                    <div class="progress-bar" id="progress-bar"></div>
                </div>
                <div class="progress-text">
                    <span id="progress-status">Hazırlanıyor...</span>
                    <span id="progress-percent">0%</span>
                </div>
            </div>
        </div>

        <div class="results" id="results-container">
            <div class="results-header">
                <h2>Bulunan Öneriler <span class="badge" id="count">0</span></h2>
                <div class="actions">
                    <button class="btn-secondary tab-active" id="tab-raw" onclick="switchTab('raw')">
                        Ham Liste
                    </button>
                    <button class="btn-secondary" id="tab-cat" onclick="switchTab('categorized')">
                        Kategorili
                    </button>
                    <button class="btn-secondary" onclick="copyResults()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        Kopyala
                    </button>
                    <button class="btn-secondary" onclick="downloadCSV()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        CSV İndir
                    </button>
                </div>
            </div>
            <div class="tag-cloud" id="tags">
                <!-- Tags will be injected here -->
            </div>
            <div id="categorized-container" style="display: none; width: 100%;">
                <!-- Categories will be injected here -->
            </div>
        </div>
    </div>

    <script>
        let allResults = new Set();
        let isRunning = false;
        
        // Türkçe ve İngilizce tüm harfler (Boşluk ile başlıyor ki önce sadece kelime aransın)
        const alphabet = ['', 'a', 'b', 'c', 'ç', 'd', 'e', 'f', 'g', 'ğ', 'h', 'ı', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'ö', 'p', 'q', 'r', 's', 'ş', 't', 'u', 'ü', 'v', 'w', 'x', 'y', 'z'];

        function handleEnter(e) {
            if (e.key === 'Enter') {
                startScraping();
            }
        }

        async function startScraping() {
            const baseQuery = document.getElementById('keyword').value.trim();
            if (!baseQuery) return;
            
            // Reset state
            isRunning = true;
            allResults.clear();
            document.getElementById('tags').innerHTML = '';
            document.getElementById('count').innerText = '0';
            
            // UI Updates
            document.getElementById('results-container').style.display = 'block';
            document.getElementById('start-btn').disabled = true;
            document.getElementById('btn-text').style.display = 'none';
            document.getElementById('btn-spinner').style.display = 'inline-block';
            
            const progressContainer = document.getElementById('progress-container');
            progressContainer.style.display = 'block';
            
            for (let i = 0; i < alphabet.length; i++) {
                if (!isRunning) break;
                
                const char = alphabet[i];
                const query = char ? `${baseQuery} ${char}` : baseQuery;
                
                // Update Progress UI
                const percent = Math.round(((i) / (alphabet.length - 1)) * 100);
                document.getElementById('progress-bar').style.width = percent + '%';
                document.getElementById('progress-status').innerText = `Sorgulanıyor: "${query}"`;
                document.getElementById('progress-percent').innerText = percent + '%';
                
                try {
                    const res = await fetch(`?action=fetch&q=${encodeURIComponent(query)}`);
                    const data = await res.json();
                    
                    if (data && data.suggestions) {
                        data.suggestions.forEach(sugg => {
                            if (!allResults.has(sugg)) {
                                allResults.add(sugg);
                                appendTag(sugg);
                                document.getElementById('count').innerText = allResults.size;
                            }
                        });
                    }
                } catch (e) {
                    console.error("Fetch error for " + query, e);
                }
                
                // Be gentle with the API (250ms delay between requests)
                await new Promise(r => setTimeout(r, 250));
            }
            
            // Complete process
            document.getElementById('progress-status').innerText = 'Tarama Tamamlandı!';
            document.getElementById('progress-status').style.color = 'var(--success)';
            resetButton();
        }

        function appendTag(text) {
            const tagsContainer = document.getElementById('tags');
            const tag = document.createElement('div');
            tag.className = 'tag';
            tag.innerText = text;
            tagsContainer.appendChild(tag);
        }

        function resetButton() {
            isRunning = false;
            document.getElementById('start-btn').disabled = false;
            document.getElementById('btn-text').style.display = 'inline-block';
            document.getElementById('btn-spinner').style.display = 'none';
        }

        function copyResults() {
            if (allResults.size === 0) return;
            const text = Array.from(allResults).join('\n');
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.querySelector('.actions .btn-secondary:nth-child(1)');
                const originalHtml = btn.innerHTML;
                btn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Kopyalandı!`;
                btn.style.borderColor = 'var(--success)';
                btn.style.color = 'var(--success)';
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.style.borderColor = '';
                    btn.style.color = '';
                }, 2000);
            });
        }

        function downloadCSV() {
            if (allResults.size === 0) return;
            const rows = Array.from(allResults).map(res => `"${res.replace(/"/g, '""')}"`);
            const csvContent = "data:text/csv;charset=utf-8,\uFEFFKeyword\n" + rows.join("\n");
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `keywords-${document.getElementById('keyword').value.trim().replace(/\s+/g, '-')}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function switchTab(tab) {
            if (tab === 'raw') {
                document.getElementById('tags').style.display = 'flex';
                document.getElementById('categorized-container').style.display = 'none';
                document.getElementById('tab-raw').classList.add('tab-active');
                document.getElementById('tab-cat').classList.remove('tab-active');
            } else {
                document.getElementById('tags').style.display = 'none';
                document.getElementById('categorized-container').style.display = 'block';
                document.getElementById('tab-cat').classList.add('tab-active');
                document.getElementById('tab-raw').classList.remove('tab-active');
                renderCategorized();
            }
        }

        function renderCategorized() {
            const container = document.getElementById('categorized-container');
            container.innerHTML = '';
            
            // Informational, Location, Marketplace vs Junk Filters
            const junkRegex = /(nasıl|yapımı|dikimi|nedir|kalıbı|ölçü|ölçüleri|üretici|rüyada|tasarla|dikme|çıkarma|nelerdir|nerede|fiyat|satın al|ankara|antalya|izmir|bursa|istanbul|mersin|adana|muğla|trendyol|amazon|hepsiburada|çiçeksepeti|temu|n11|eğitmeni|ısırma)/i;
            
            const categories = {
                "🧥 Kışlık & Mont": /(kışlık|mont|polar|peluş|kazak)/i,
                "👕 Yazlık & Günlük": /(yazlık|tişört|t-shirt|gömlek|ev kıyafeti)/i,
                "🌧️ Tulum & Yağmurluk": /(tulum|yağmurluk)/i,
                "🧶 Örgü & Triko": /(örgü|örme|tığ işi)/i,
                "🐘 Büyük Irklar": /(büyük ırk|büyük|xl|xxl)/i,
                "🐾 Küçük Irk & Yavru": /(yavru|küçük|xs|xxs|fino|şivava|oyuncak)/i,
                "🐩 Popüler Irklar": /(poodle|pomeranian|golden|husky|labrador|cocker|pug|terrier)/i,
                "🎉 Özel Gün & Kostümler": /(yılbaşı|noel|christmas|halloween|gelinlik|damatlık|smokin|balığı|dinazor|süpermen|arı|asker|polis|prenses|chucky|çaki|stitch|çakıl)/i,
                "🏥 Medikal & Güvenlik": /(ameliyat|operasyon|kısırlaştırma|regl|taşıma|güvenlik)/i,
                "⚽ Taraftar Giyimi": /(fenerbahçe|galatasaray|beşiktaş|trabzonspor)/i,
                "✨ Tasarım Detayları": /(çizgili|leoparlı|ekoseli|şapkalı|şemsiyeli|fermuarlı|isimli)/i,
            };

            const grouped = {};
            for (let cat in categories) grouped[cat] = [];
            grouped["🛍️ Diğer Modeller"] = [];

            allResults.forEach(keyword => {
                if (junkRegex.test(keyword)) return; // Skip junk
                
                let matched = false;
                for (let cat in categories) {
                    if (categories[cat].test(keyword)) {
                        grouped[cat].push(keyword);
                        matched = true;
                        break; 
                    }
                }
                if (!matched) grouped["🛍️ Diğer Modeller"].push(keyword);
            });

            Object.keys(grouped).forEach(cat => {
                if (grouped[cat].length > 0) {
                    const groupDiv = document.createElement('div');
                    groupDiv.className = 'category-group';
                    
                    const title = document.createElement('div');
                    title.className = 'category-title';
                    title.innerText = `${cat} (${grouped[cat].length} Kelime)`;
                    
                    const tagsDiv = document.createElement('div');
                    tagsDiv.className = 'category-tags';
                    
                    grouped[cat].forEach(kw => {
                        const tag = document.createElement('div');
                        tag.className = 'tag';
                        tag.innerText = kw;
                        tagsDiv.appendChild(tag);
                    });
                    
                    groupDiv.appendChild(title);
                    groupDiv.appendChild(tagsDiv);
                    container.appendChild(groupDiv);
                }
            });
        }
    </script>
</body>
</html>
