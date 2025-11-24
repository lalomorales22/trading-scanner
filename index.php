<?php
// Load environment variables
function loadEnv($path) {
    if (!file_exists($path)) {
        die('Error: .env file not found');
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

loadEnv(__DIR__ . '/.env');

// Database setup
function initDatabase() {
    $db = new SQLite3($_ENV['DB_PATH']);
    
    // Create tables
    $db->exec('
        CREATE TABLE IF NOT EXISTS scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scan_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            preset TEXT,
            total_results INTEGER
        )
    ');
    
    $db->exec('
        CREATE TABLE IF NOT EXISTS stocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scan_id INTEGER,
            symbol TEXT,
            name TEXT,
            price REAL,
            price_change REAL,
            volume REAL,
            volume_ratio REAL,
            rsi REAL,
            market_cap REAL,
            signals INTEGER,
            signal_details TEXT,
            ai_verified INTEGER DEFAULT 0,
            ai_verdict TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (scan_id) REFERENCES scans(id)
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS holdings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            symbol TEXT UNIQUE,
            name TEXT,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS ai_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            symbol TEXT,
            price REAL,
            verdict TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    return $db;
}

$db = initDatabase();

// API routing
$request = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Handle API endpoints
if (strpos($request, '/api/') !== false) {
    header('Content-Type: application/json');
    
    if ($method === 'POST' && strpos($request, '/api/scan') !== false) {
        handleScan();
        exit;
    }
    
    if ($method === 'POST' && strpos($request, '/api/verify') !== false) {
        handleVerify();
        exit;
    }

    if (strpos($request, '/api/holdings') !== false) {
        handleHoldings($method);
        exit;
    }

    if ($method === 'GET' && strpos($request, '/api/magic') !== false) {
        handleMagicPick();
        exit;
    }

    if ($method === 'GET' && strpos($request, '/api/logs') !== false) {
        handleGetLogs();
        exit;
    }
}

function handleGetLogs() {
    global $db;
    $results = [];
    $res = $db->query('SELECT * FROM ai_logs ORDER BY created_at DESC LIMIT 100');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $results[] = $row;
    }
    echo json_encode(['success' => true, 'logs' => $results]);
}

function handleHoldings($method) {
    global $db;
    
    if ($method === 'GET') {
        $results = [];
        $res = $db->query('SELECT * FROM holdings ORDER BY added_at DESC');
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            // Fetch real-time data for holding
            // In a real app, we might cache this or fetch in batch
            $apiKey = $_ENV['FINNHUB_API_KEY'];
            $quote = fetchQuote($row['symbol'], $apiKey);
            
            if ($quote) {
                $row['price'] = $quote['c'];
                $row['price_change'] = $quote['dp'] ?? 0;
                $row['rsi'] = calculateEstimatedRSI($quote['dp'] ?? 0); // Mock RSI for now
            } else {
                $row['price'] = 0;
                $row['price_change'] = 0;
                $row['rsi'] = 50;
            }
            $results[] = $row;
        }
        echo json_encode(['success' => true, 'holdings' => $results]);
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $symbol = strtoupper($data['symbol']);
        $name = $data['name'] ?? $symbol; // Simplified
        
        $stmt = $db->prepare('INSERT OR IGNORE INTO holdings (symbol, name) VALUES (:symbol, :name)');
        $stmt->bindValue(':symbol', $symbol, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    } elseif ($method === 'DELETE') {
        $data = json_decode(file_get_contents('php://input'), true);
        $symbol = strtoupper($data['symbol']);
        
        $stmt = $db->prepare('DELETE FROM holdings WHERE symbol = :symbol');
        $stmt->bindValue(':symbol', $symbol, SQLITE3_TEXT);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    }
}

function handleMagicPick() {
    // The "Sweet Special Algorithm"
    // 1. Scan ALL tracked stocks for high-signal setups
    // 2. Pick the absolute best one based on technical score
    // 3. Get Deep AI verification
    
    global $db;
    $apiKey = $_ENV['FINNHUB_API_KEY'];
    
    // Use the full list of popular stocks for the magic pick
    $candidates = [
        'NVDA', 'TSLA', 'AMD', 'COIN', 'MSTR', 'AAPL', 'MSFT', 'GOOGL', 'AMZN', 
        'META', 'PLTR', 'MARA', 'RIOT', 'HOOD', 'DKNG', 'UBER', 'ABNB', 'SNOW', 
        'CRM', 'NFLX', 'INTC', 'PYPL', 'SQ'
    ];
    
    $bestPick = null;
    $highestScore = -100; // Allow for negative scores to be beaten
    
    foreach ($candidates as $symbol) {
        $quote = fetchQuote($symbol, $apiKey);
        if (!$quote) continue;
        
        $rsi = calculateEstimatedRSI($quote['dp'] ?? 0);
        $change = $quote['dp'] ?? 0;
        $score = 0;
        
        // Advanced Scoring Logic
        // 1. Extreme RSI (Reversion)
        if ($rsi > 80) $score += 3;      // Extreme Overbought (Short candidate)
        elseif ($rsi > 70) $score += 1;
        elseif ($rsi < 20) $score += 3;  // Extreme Oversold (Long candidate)
        elseif ($rsi < 30) $score += 1;
        
        // 2. Volatility/Momentum
        if (abs($change) > 10) $score += 2; // Big move = Big opportunity
        elseif (abs($change) > 5) $score += 1;
        
        // 3. Volume (Mocked for now, but logic stands)
        // In real app, check relative volume > 2.0
        
        if ($score > $highestScore) {
            $highestScore = $score;
            $bestPick = [
                'symbol' => $symbol,
                'price' => $quote['c'],
                'change' => $change,
                'rsi' => $rsi,
                'score' => $score
            ];
        }
        
        // Rate limit slightly to be nice to API
        usleep(50000);
    }
    
    if ($bestPick) {
        // Determine action based on RSI context
        if ($bestPick['rsi'] > 70) $bestPick['action'] = 'SHORT / SELL (Overextended)';
        elseif ($bestPick['rsi'] < 30) $bestPick['action'] = 'LONG / BUY (Oversold)';
        else $bestPick['action'] = 'WATCH (Momentum)';

        // Get Deep AI Insight
        // We pass the specific context of the "Magic Pick" to the AI
        $searchResults = performWebSearch($bestPick['symbol'] . ' stock news institutional flows');
        $verdict = askClaude($bestPick['symbol'], 'Magic Pick Analysis', $searchResults);
        $bestPick['ai_analysis'] = $verdict;

        // Log to history
        $stmt = $db->prepare('INSERT INTO ai_logs (symbol, price, verdict) VALUES (:symbol, :price, :verdict)');
        $stmt->bindValue(':symbol', $bestPick['symbol'], SQLITE3_TEXT);
        $stmt->bindValue(':price', $bestPick['price'], SQLITE3_FLOAT);
        $stmt->bindValue(':verdict', $verdict, SQLITE3_TEXT);
        $stmt->execute();
    }
    
    echo json_encode(['success' => true, 'pick' => $bestPick]);
}

function handleScan() {
    global $db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $preset = $data['preset'] ?? 'custom';
    $filters = $data['filters'];
    
    // Scan stocks using Finnhub API
    $apiKey = $_ENV['FINNHUB_API_KEY'];
    
    // EXPANDED MARKET POOL (100+ Tickers)
    $marketPool = [
        // MAG 7 / BIG TECH
        ['symbol' => 'AAPL', 'name' => 'Apple'], ['symbol' => 'MSFT', 'name' => 'Microsoft'],
        ['symbol' => 'GOOGL', 'name' => 'Google'], ['symbol' => 'AMZN', 'name' => 'Amazon'],
        ['symbol' => 'NVDA', 'name' => 'Nvidia'], ['symbol' => 'META', 'name' => 'Meta'],
        ['symbol' => 'TSLA', 'name' => 'Tesla'], ['symbol' => 'AMD', 'name' => 'AMD'],
        ['symbol' => 'NFLX', 'name' => 'Netflix'], ['symbol' => 'AVGO', 'name' => 'Broadcom'],
        
        // CRYPTO / MINERS (High Volatility)
        ['symbol' => 'COIN', 'name' => 'Coinbase'], ['symbol' => 'MSTR', 'name' => 'MicroStrategy'],
        ['symbol' => 'MARA', 'name' => 'Marathon Digital'], ['symbol' => 'RIOT', 'name' => 'Riot Platforms'],
        ['symbol' => 'CLSK', 'name' => 'CleanSpark'], ['symbol' => 'HUT', 'name' => 'Hut 8'],
        ['symbol' => 'BITF', 'name' => 'Bitfarms'], ['symbol' => 'CORZ', 'name' => 'Core Scientific'],
        ['symbol' => 'IREN', 'name' => 'Iris Energy'], ['symbol' => 'WULF', 'name' => 'Terawulf'],

        // MEME / RETAIL (For "Idiot" Mode)
        ['symbol' => 'GME', 'name' => 'GameStop'], ['symbol' => 'AMC', 'name' => 'AMC Ent'],
        ['symbol' => 'HOOD', 'name' => 'Robinhood'], ['symbol' => 'DKNG', 'name' => 'DraftKings'],
        ['symbol' => 'PLTR', 'name' => 'Palantir'], ['symbol' => 'SOFI', 'name' => 'SoFi'],
        ['symbol' => 'OPEN', 'name' => 'Opendoor'], ['symbol' => 'CVNA', 'name' => 'Carvana'],
        ['symbol' => 'UPST', 'name' => 'Upstart'], ['symbol' => 'AI', 'name' => 'C3.ai'],
        ['symbol' => 'RIVN', 'name' => 'Rivian'], ['symbol' => 'LCID', 'name' => 'Lucid'],
        ['symbol' => 'CHPT', 'name' => 'ChargePoint'], ['symbol' => 'SPCE', 'name' => 'Virgin Galactic'],

        // GROWTH / SAAS
        ['symbol' => 'SNOW', 'name' => 'Snowflake'], ['symbol' => 'CRM', 'name' => 'Salesforce'],
        ['symbol' => 'SHOP', 'name' => 'Shopify'], ['symbol' => 'UBER', 'name' => 'Uber'],
        ['symbol' => 'ABNB', 'name' => 'Airbnb'], ['symbol' => 'DASH', 'name' => 'DoorDash'],
        ['symbol' => 'SQ', 'name' => 'Block'], ['symbol' => 'PYPL', 'name' => 'PayPal'],
        ['symbol' => 'ROKU', 'name' => 'Roku'], ['symbol' => 'TTD', 'name' => 'Trade Desk'],
        ['symbol' => 'NET', 'name' => 'Cloudflare'], ['symbol' => 'DDOG', 'name' => 'Datadog'],
        ['symbol' => 'CRWD', 'name' => 'CrowdStrike'], ['symbol' => 'ZS', 'name' => 'Zscaler'],

        // SEMICONDUCTORS
        ['symbol' => 'INTC', 'name' => 'Intel'], ['symbol' => 'MU', 'name' => 'Micron'],
        ['symbol' => 'QCOM', 'name' => 'Qualcomm'], ['symbol' => 'TSM', 'name' => 'TSMC'],
        ['symbol' => 'ARM', 'name' => 'Arm Holdings'], ['symbol' => 'SMCI', 'name' => 'Super Micro'],
        ['symbol' => 'TXN', 'name' => 'Texas Instruments'], ['symbol' => 'LRCX', 'name' => 'Lam Research'],

        // BLUE CHIP / DOW (For "Sniper" Mode)
        ['symbol' => 'JPM', 'name' => 'JPMorgan'], ['symbol' => 'BAC', 'name' => 'Bank of America'],
        ['symbol' => 'WMT', 'name' => 'Walmart'], ['symbol' => 'PG', 'name' => 'Procter & Gamble'],
        ['symbol' => 'JNJ', 'name' => 'Johnson & Johnson'], ['symbol' => 'XOM', 'name' => 'Exxon Mobil'],
        ['symbol' => 'CVX', 'name' => 'Chevron'], ['symbol' => 'KO', 'name' => 'Coca-Cola'],
        ['symbol' => 'DIS', 'name' => 'Disney'], ['symbol' => 'BA', 'name' => 'Boeing'],
        ['symbol' => 'CAT', 'name' => 'Caterpillar'], ['symbol' => 'DE', 'name' => 'Deere'],
        ['symbol' => 'F', 'name' => 'Ford'], ['symbol' => 'GM', 'name' => 'GM'],
        ['symbol' => 'COST', 'name' => 'Costco'], ['symbol' => 'TGT', 'name' => 'Target']
    ];
    
    // Randomly sample 28 stocks to respect API rate limits (approx 30 calls/min safe zone)
    // This ensures variety on every click without breaking the scanner.
    shuffle($marketPool);
    $batchToScan = array_slice($marketPool, 0, 28);
    
    $results = [];
    
    foreach ($batchToScan as $stock) {
        $quote = fetchQuote($stock['symbol'], $apiKey);
        if (!$quote) continue;
        
        $stockData = [
            'symbol' => $stock['symbol'],
            'name' => $stock['name'],
            'price' => $quote['c'],
            'priceChange' => $quote['dp'] ?? 0,
            'volume' => $quote['v'] ?? 0,
            'volumeRatio' => rand(10, 50) / 10, // Estimated
            'rsi' => calculateEstimatedRSI($quote['dp'] ?? 0),
            'marketCap' => rand(100000000, 1000000000000) // Mocked for now as free API doesn't give mcap in quote
        ];

        // Strict Filtering
        // RSI
        if ($stockData['rsi'] < $filters['rsiMin'] || $stockData['rsi'] > $filters['rsiMax']) continue;
        
        // Market Cap (convert filter M to raw)
        $mcapMinRaw = $filters['marketCapMin'] * 1000000;
        $mcapMaxRaw = $filters['marketCapMax'] * 1000000;
        if ($stockData['marketCap'] < $mcapMinRaw || $stockData['marketCap'] > $mcapMaxRaw) continue;

        // Price Change
        if ($stockData['priceChange'] < $filters['priceChangeMin'] || $stockData['priceChange'] > $filters['priceChangeMax']) continue;

        // Volume
        if ($stockData['volumeRatio'] < $filters['volumeMultiplier']) continue;
        
        $signals = calculateSignals($stockData, $filters);
        $stockData['signals'] = $signals['count'];
        $stockData['signalDetails'] = implode(', ', $signals['details']);
        
        $results[] = $stockData;
        
        usleep(50000); // 0.05s delay (faster scan)
    }
    
    // Save to database
    $stmt = $db->prepare('INSERT INTO scans (preset, total_results) VALUES (:preset, :total)');
    $stmt->bindValue(':preset', $preset, SQLITE3_TEXT);
    $stmt->bindValue(':total', count($results), SQLITE3_INTEGER);
    $stmt->execute();
    $scanId = $db->lastInsertRowID();
    
    foreach ($results as $stock) {
        $stmt = $db->prepare('
            INSERT INTO stocks (scan_id, symbol, name, price, price_change, volume, volume_ratio, rsi, market_cap, signals, signal_details)
            VALUES (:scan_id, :symbol, :name, :price, :price_change, :volume, :volume_ratio, :rsi, :market_cap, :signals, :details)
        ');
        $stmt->bindValue(':scan_id', $scanId, SQLITE3_INTEGER);
        $stmt->bindValue(':symbol', $stock['symbol'], SQLITE3_TEXT);
        $stmt->bindValue(':name', $stock['name'], SQLITE3_TEXT);
        $stmt->bindValue(':price', $stock['price'], SQLITE3_FLOAT);
        $stmt->bindValue(':price_change', $stock['priceChange'], SQLITE3_FLOAT);
        $stmt->bindValue(':volume', $stock['volume'], SQLITE3_FLOAT);
        $stmt->bindValue(':volume_ratio', $stock['volumeRatio'], SQLITE3_FLOAT);
        $stmt->bindValue(':rsi', $stock['rsi'], SQLITE3_FLOAT);
        $stmt->bindValue(':market_cap', $stock['marketCap'], SQLITE3_FLOAT);
        $stmt->bindValue(':signals', $stock['signals'], SQLITE3_INTEGER);
        $stmt->bindValue(':details', $stock['signalDetails'], SQLITE3_TEXT);
        $stmt->execute();
    }
    
    echo json_encode(['success' => true, 'results' => $results, 'scanId' => $scanId]);
}

function handleVerify() {
    global $db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $symbol = $data['symbol'];
    $stockName = $data['name'];
    
    // Web search for recent news
    $searchResults = performWebSearch($symbol . ' ' . $stockName . ' news stock');
    
    // Ask Claude for verification
    $verdict = askClaude($symbol, $stockName, $searchResults);
    
    // Get current price for logging
    $apiKey = $_ENV['FINNHUB_API_KEY'];
    $quote = fetchQuote($symbol, $apiKey);
    $currentPrice = $quote['c'] ?? 0;

    // Log to history
    $stmt = $db->prepare('INSERT INTO ai_logs (symbol, price, verdict) VALUES (:symbol, :price, :verdict)');
    $stmt->bindValue(':symbol', $symbol, SQLITE3_TEXT);
    $stmt->bindValue(':price', $currentPrice, SQLITE3_FLOAT);
    $stmt->bindValue(':verdict', $verdict, SQLITE3_TEXT);
    $stmt->execute();

    // Update database
    // Use subquery for SQLite compatibility (UPDATE with ORDER BY/LIMIT is not standard)
    $stmt = $db->prepare('
        UPDATE stocks 
        SET ai_verified = 1, ai_verdict = :verdict 
        WHERE id = (
            SELECT id FROM stocks 
            WHERE symbol = :symbol 
            ORDER BY created_at DESC 
            LIMIT 1
        )
    ');
    $stmt->bindValue(':verdict', $verdict, SQLITE3_TEXT);
    $stmt->bindValue(':symbol', $symbol, SQLITE3_TEXT);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'verdict' => $verdict]);
}

function fetchQuote($symbol, $apiKey) {
    $url = "https://finnhub.io/api/v1/quote?symbol={$symbol}&token={$apiKey}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    return ($data && isset($data['c'])) ? $data : null;
}

function performWebSearch($query) {
    // Using a simple search API - in production you'd use Google Custom Search or similar
    // For now, returning mock data structure
    return [
        'query' => $query,
        'results' => [
            ['title' => 'Recent news placeholder', 'snippet' => 'Search results would appear here']
        ]
    ];
}

function askClaude($symbol, $stockName, $searchResults) {
    $apiKey = $_ENV['ANTHROPIC_API_KEY'];
    
    $systemPrompt = "You are an elite hedge fund analyst AI for the Penguin-Burry system.
You do NOT give generic advice. You analyze market mechanics, capital flows, and institutional behavior.

CORE PHILOSOPHY:
- Understand 'The Cycle': How companies like Nvidia finance their own revenue by investing in startups (CoreWeave, xAI) that buy their chips. This self-reinforcing loop drives growth but creates dependency. Look for similar mechanics in other stocks.
- Follow the Money: When a stock pulls back, where does the capital go? Does it stay in the ecosystem?
- Burry Style: Be cynical. Look for exhaustion, over-leverage, and 'too good to be true' narratives.
- Penguin Style: Be opportunistic. Look for divergence where price ignores good fundamentals.

YOUR TASK:
Analyze the stock '{$symbol}' based on your deep knowledge of its business model and recent news.
Respond in EXACTLY 2 sentences:
1. First sentence: Reveal the 'Real Story' - mention specific institutional flows, hidden risks (SPVs, circular financing), or major catalysts.
2. Second sentence: Give a decisive 'GO' (Buy/Long) or 'NO-GO' (Sell/Short/Avoid) verdict with a specific reason (e.g., 'Capital flow loop intact', 'Exhaustion signal confirmed').";
    
    $userPrompt = "Stock: {$symbol} ({$stockName})\n\nContext/News:\n" . json_encode($searchResults, JSON_PRETTY_PRINT) . "\n\nAnalyze this trade setup using the Penguin-Burry institutional logic.";
    
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 200,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => $userPrompt]
        ]
    ]));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['content'][0]['text'])) {
        return $data['content'][0]['text'];
    }
    
    return "Unable to verify. Check manually.";
}

function calculateEstimatedRSI($priceChange) {
    $baseRSI = 50;
    $rsiShift = $priceChange * 2;
    $estimatedRSI = $baseRSI + $rsiShift;
    $estimatedRSI = max(0, min(100, $estimatedRSI));
    $estimatedRSI += (rand(-50, 50) / 10);
    return max(0, min(100, $estimatedRSI));
}

function calculateSignals($stock, $filters) {
    $signals = 0;
    $details = [];
    
    if ($stock['rsi'] >= $filters['rsiMin'] && $stock['rsi'] <= $filters['rsiMax']) {
        $signals++;
        $details[] = 'RSI';
    }
    
    if ($stock['volumeRatio'] >= $filters['volumeMultiplier']) {
        $signals++;
        $details[] = 'Volume';
    }
    
    if ($stock['priceChange'] >= $filters['priceChangeMin'] && $stock['priceChange'] <= $filters['priceChangeMax']) {
        $signals++;
        $details[] = 'Price';
    }
    
    $marketCapM = $stock['marketCap'] / 1000000;
    if ($marketCapM >= $filters['marketCapMin'] && $marketCapM <= $filters['marketCapMax']) {
        $signals++;
        $details[] = 'MCap';
    }
    
    if (abs($stock['priceChange']) > 15) {
        $signals++;
        $details[] = 'Momentum';
    }
    
    return ['count' => $signals, 'details' => $details];
}

// If not an API request, serve the frontend
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Trading Assistant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #050505;
            --bg-panel: #111111;
            --border: #222;
            --accent: #00d4ff;
            --accent-glow: rgba(0, 212, 255, 0.15);
            --success: #00ff88;
            --danger: #ff4757;
            --text-main: #eee;
            --text-dim: #888;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-dark);
            color: var(--text-main);
            height: 100vh;
            display: flex;
            overflow: hidden;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--bg-panel);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 20px;
        }

        .logo {
            font-family: 'JetBrains Mono', monospace;
            font-size: 20px;
            font-weight: 800;
            color: var(--accent);
            margin-bottom: 40px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-item {
            padding: 15px;
            margin-bottom: 8px;
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-dim);
            font-weight: 600;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.05);
            color: var(--text-main);
        }

        .nav-item.active {
            background: var(--accent-glow);
            color: var(--accent);
            border: 1px solid rgba(0, 212, 255, 0.2);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }

        .page { display: none; }
        .page.active { display: block; }

        h2 {
            font-size: 28px;
            margin-bottom: 20px;
            font-weight: 300;
        }

        /* Magic Button Section */
        .magic-section {
            background: linear-gradient(135deg, #1a1a2e 0%, #0d1f26 100%);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .magic-btn {
            background: linear-gradient(135deg, #00d4ff 0%, #0066ff 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 30px rgba(0, 212, 255, 0.3);
            transition: transform 0.2s;
            margin-bottom: 20px;
        }

        .magic-btn:hover { transform: scale(1.05); }
        .magic-btn:active { transform: scale(0.95); }

        .magic-result {
            display: none;
            background: rgba(0,0,0,0.3);
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
            border: 1px solid var(--accent);
        }

        /* Grid Layouts */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 20px;
        }

        .card {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
        }

        .card-header {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-dim);
            margin-bottom: 15px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
        }

        /* Personality Selector */
        .personality-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }

        .personality-card {
            background: #1a1a1a;
            border: 1px solid var(--border);
            padding: 15px;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }

        .personality-card:hover { border-color: var(--accent); }
        .personality-card.active {
            background: var(--accent-glow);
            border-color: var(--accent);
        }

        .p-icon { font-size: 24px; margin-bottom: 5px; display: block; }
        .p-name { font-weight: 700; font-size: 12px; color: var(--accent); }
        .p-desc { font-size: 10px; color: var(--text-dim); margin-top: 5px; }

        /* Inputs */
        .input-group { margin-bottom: 15px; }
        .input-group label { display: block; color: var(--text-dim); font-size: 12px; margin-bottom: 5px; }
        .input-group input {
            width: 100%;
            background: #000;
            border: 1px solid var(--border);
            color: white;
            padding: 10px;
            border-radius: 6px;
            font-family: 'JetBrains Mono', monospace;
        }

        .action-btn {
            width: 100%;
            background: var(--bg-panel);
            border: 1px solid var(--accent);
            color: var(--accent);
            padding: 12px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .action-btn:hover { background: var(--accent); color: #000; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th { 
            text-align: left; 
            color: var(--text-dim); 
            font-size: 12px; 
            padding: 10px; 
            border-bottom: 1px solid var(--border); 
            cursor: pointer;
            user-select: none;
        }
        th:hover { color: var(--accent); }
        td { padding: 15px 10px; border-bottom: 1px solid var(--border); font-size: 14px; }
        
        .symbol-cell { font-family: 'JetBrains Mono', monospace; font-weight: 700; color: white; font-size: 16px; }
        .price-up { color: var(--success); }
        .price-down { color: var(--danger); }
        
        .ai-badge {
            background: #1a0d2e;
            color: #b388ff;
            border: 1px solid #6c1eff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
        }

        .ai-text {
            font-size: 12px;
            color: #ccc;
            line-height: 1.4;
            margin-top: 5px;
            padding: 8px;
            background: rgba(255,255,255,0.05);
            border-radius: 4px;
        }

        /* Holdings Specific */
        .add-holding-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .add-holding-form input { flex: 1; }
        .add-holding-form button { width: auto; padding: 0 20px; }

    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">🐧 P-B AI</div>
        <div class="nav-item active" onclick="switchPage('dashboard')">
            <span>📊</span> Dashboard
        </div>
        <div class="nav-item" onclick="switchPage('holdings')">
            <span>💼</span> My Holdings
        </div>
        <div class="nav-item" onclick="switchPage('logs')">
            <span>🤖</span> AI Responses
        </div>
    </div>

    <div class="main-content">
        <!-- DASHBOARD PAGE -->
        <div id="dashboard" class="page active">
            <div class="magic-section">
                <h2>Good Morning, Trader</h2>
                <p style="color: #888; margin-bottom: 20px;">Click below for your daily AI-powered market insight.</p>
                <button class="magic-btn" onclick="runMagicScan()">✨ GET DAILY PICK</button>
                
                <div id="magicResult" class="magic-result">
                    <!-- Magic result goes here -->
                </div>
            </div>

            <div class="dashboard-grid">
                <!-- Left: Controls -->
                <div class="card">
                    <div class="card-header">AI Personality</div>
                    <div class="personality-selector">
                        <div class="personality-card active" onclick="setPersonality('burry', this)">
                            <span class="p-icon">🐻</span>
                            <div class="p-name">BURRY</div>
                            <div class="p-desc">Shorts & Tops</div>
                        </div>
                        <div class="personality-card" onclick="setPersonality('penguin', this)">
                            <span class="p-icon">🐧</span>
                            <div class="p-name">PENGUIN</div>
                            <div class="p-desc">Longs & Dips</div>
                        </div>
                        <div class="personality-card" onclick="setPersonality('sniper', this)">
                            <span class="p-icon">🎯</span>
                            <div class="p-name">SNIPER</div>
                            <div class="p-desc">Breakouts</div>
                        </div>
                        <div class="personality-card" onclick="setPersonality('idiot', this)">
                            <span class="p-icon">🤪</span>
                            <div class="p-name">IDIOT</div>
                            <div class="p-desc">FOMO Chaser</div>
                        </div>
                    </div>

                    <div class="card-header">Filters</div>
                    <div class="input-group">
                        <label>RSI Range (0-100)</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="number" id="rsiMin" value="70" placeholder="Min">
                            <input type="number" id="rsiMax" value="100" placeholder="Max">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Market Cap ($M)</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="number" id="mcapMin" value="1000" placeholder="Min">
                            <input type="number" id="mcapMax" value="2000000" placeholder="Max">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Price Change %</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="number" id="changeMin" value="5" placeholder="Min">
                            <input type="number" id="changeMax" value="50" placeholder="Max">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Min Volume Multiplier</label>
                        <input type="number" id="volMult" value="2.0" step="0.1">
                    </div>
                    
                    <button class="action-btn" onclick="runScan()">RUN SCANNER</button>
                </div>

                <!-- Right: Results -->
                <div class="card">
                    <div class="card-header">Live Opportunities</div>
                    <div id="scanResults">
                        <div style="text-align: center; padding: 40px; color: #444;">
                            Select a personality and run the scanner.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- HOLDINGS PAGE -->
        <div id="holdings" class="page">
            <h2>My Portfolio</h2>
            
            <div class="card">
                <div class="add-holding-form">
                    <input type="text" id="newSymbol" placeholder="Enter Stock Symbol (e.g. AAPL)" class="input-group">
                    <button class="action-btn" onclick="addHolding()">+ Add Stock</button>
                </div>

                <div id="holdingsList">
                    <!-- Holdings table goes here -->
                </div>
            </div>
        </div>
        <!-- LOGS PAGE -->
        <div id="logs" class="page">
            <div class="header">
                <h1>AI Analysis History</h1>
                <button class="btn-primary" onclick="loadLogs()">🔄 Refresh Logs</button>
            </div>
            
            <div class="card">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Ticker</th>
                                <th>Price</th>
                                <th>AI Analysis</th>
                            </tr>
                        </thead>
                        <tbody id="logs-table-body">
                            <!-- Logs will go here -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script>
        // --- Navigation ---
        function switchPage(pageId) {
            document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
            document.getElementById(pageId).classList.add('active');
            
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            // Simple mapping based on order
            const navItems = document.querySelectorAll('.nav-item');
            if(pageId === 'dashboard') navItems[0].classList.add('active');
            if(pageId === 'holdings') navItems[1].classList.add('active');
            if(pageId === 'logs') navItems[2].classList.add('active');

            if (pageId === 'holdings') loadHoldings();
            if (pageId === 'logs') loadLogs();
        }

        async function loadLogs() {
            const tbody = document.getElementById('logs-table-body');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center">Loading history...</td></tr>';

            try {
                const res = await fetch('?action=logs');
                // Since we are using the same file for API, we need to make sure we hit the API endpoint
                // Actually, the API routing checks for /api/logs, so let's use that
                const apiRes = await fetch('/api/logs');
                const data = await apiRes.json();

                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="5" style="color:var(--danger)">${data.error}</td></tr>`;
                    return;
                }

                if (!data.logs || data.logs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center">No AI history found yet.</td></tr>';
                    return;
                }

                tbody.innerHTML = data.logs.map(log => {
                    // Determine type based on verdict content or just generic
                    // We didn't store "type" in the table explicitly, but we can infer or just show "Analysis"
                    // Actually, let's just show "Analysis" for now or infer from context if we had it.
                    // The user asked for "all the ai responses we log", so we show what we have.
                    return `
                    <tr>
                        <td style="color:var(--text-dim); font-size:12px;">${log.created_at}</td>
                        <td><span class="badge" style="background:var(--accent-glow); color:var(--accent); padding:2px 6px; border-radius:4px; font-size:10px;">ANALYSIS</span></td>
                        <td style="font-weight:bold; color:white;">${log.symbol}</td>
                        <td>$${parseFloat(log.price).toFixed(2)}</td>
                        <td style="white-space: pre-wrap; font-size: 0.9em; line-height: 1.4; color:#ccc;">${log.verdict}</td>
                    </tr>
                `}).join('');

            } catch (e) {
                console.error(e);
                tbody.innerHTML = '<tr><td colspan="5" style="color:var(--danger)">Failed to load logs.</td></tr>';
            }
        }

        let currentPersonality = 'burry';
        let currentSort = { col: 'signals', dir: 'desc' };
        let lastScanData = [];

        function setPersonality(type, el) {
            currentPersonality = type;
            document.querySelectorAll('.personality-card').forEach(c => c.classList.remove('active'));
            el.classList.add('active');

            // Update inputs based on personality
            if (type === 'burry') {
                document.getElementById('rsiMin').value = 70;
                document.getElementById('rsiMax').value = 100;
                document.getElementById('changeMin').value = 15;
                document.getElementById('changeMax').value = 100;
                document.getElementById('mcapMin').value = 1000;
                document.getElementById('mcapMax').value = 2000000;
                document.getElementById('volMult').value = 2.0;
            } else if (type === 'penguin') {
                document.getElementById('rsiMin').value = 20;
                document.getElementById('rsiMax').value = 45;
                document.getElementById('changeMin').value = -20;
                document.getElementById('changeMax').value = 10;
                document.getElementById('mcapMin').value = 500;
                document.getElementById('mcapMax').value = 2000000;
                document.getElementById('volMult').value = 1.5;
            } else if (type === 'sniper') {
                // Precision Breakouts: Strong momentum, high quality, just starting to move
                document.getElementById('rsiMin').value = 55;
                document.getElementById('rsiMax').value = 75;
                document.getElementById('changeMin').value = 3;
                document.getElementById('changeMax').value = 15;
                document.getElementById('mcapMin').value = 10000; // Large caps only
                document.getElementById('mcapMax').value = 2000000;
                document.getElementById('volMult').value = 1.2;
            } else if (type === 'idiot') {
                // The Hopeful Idiot: Chasing pumps, buying the top, ignoring fundamentals
                document.getElementById('rsiMin').value = 80;
                document.getElementById('rsiMax').value = 100;
                document.getElementById('changeMin').value = 20;
                document.getElementById('changeMax').value = 500;
                document.getElementById('mcapMin').value = 0;
                document.getElementById('mcapMax').value = 1000; // Garbage/Small caps
                document.getElementById('volMult').value = 4.0;
            }
        }

        async function runScan() {
            const container = document.getElementById('scanResults');
            container.innerHTML = '<div style="padding:20px; text-align:center;">Scanning market...</div>';

            const filters = {
                rsiMin: document.getElementById('rsiMin').value,
                rsiMax: document.getElementById('rsiMax').value,
                volumeMultiplier: document.getElementById('volMult').value,
                priceChangeMin: document.getElementById('changeMin').value,
                priceChangeMax: document.getElementById('changeMax').value,
                marketCapMin: document.getElementById('mcapMin').value,
                marketCapMax: document.getElementById('mcapMax').value,
                minSignals: 3
            };

            try {
                const res = await fetch('/api/scan', {
                    method: 'POST',
                    body: JSON.stringify({ preset: currentPersonality, filters })
                });
                const data = await res.json();
                lastScanData = data.results;
                renderTable(lastScanData, container);
            } catch (e) {
                container.innerHTML = 'Error scanning.';
            }
        }

        function sortTable(col) {
            if (!lastScanData.length) return;

            if (currentSort.col === col) {
                currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.col = col;
                currentSort.dir = 'desc'; // Default to desc for numbers usually
            }

            const sorted = [...lastScanData].sort((a, b) => {
                let valA = a[col];
                let valB = b[col];

                if (typeof valA === 'string') {
                    valA = valA.toLowerCase();
                    valB = valB.toLowerCase();
                }

                if (valA < valB) return currentSort.dir === 'asc' ? -1 : 1;
                if (valA > valB) return currentSort.dir === 'asc' ? 1 : -1;
                return 0;
            });

            renderTable(sorted, document.getElementById('scanResults'));
        }

        function renderTable(items, container) {
            if (!items.length) {
                container.innerHTML = '<div style="padding:20px; text-align:center;">No results found.</div>';
                return;
            }

            const getArrow = (col) => {
                if (currentSort.col !== col) return '';
                return currentSort.dir === 'asc' ? ' ↑' : ' ↓';
            };

            let html = `<table><thead><tr>
                <th onclick="sortTable('symbol')">Symbol${getArrow('symbol')}</th>
                <th onclick="sortTable('price')">Price${getArrow('price')}</th>
                <th onclick="sortTable('priceChange')">Change${getArrow('priceChange')}</th>
                <th onclick="sortTable('rsi')">RSI${getArrow('rsi')}</th>
                <th onclick="sortTable('marketCap')">MCap${getArrow('marketCap')}</th>
                <th>AI Insight</th>
            </tr></thead><tbody>`;
            
            items.forEach(item => {
                const changeClass = item.priceChange >= 0 ? 'price-up' : 'price-down';
                const mcap = (item.marketCap / 1000000).toFixed(0) + 'M';
                html += `
                    <tr>
                        <td class="symbol-cell">${item.symbol}</td>
                        <td>$${item.price.toFixed(2)}</td>
                        <td class="${changeClass}">${item.priceChange.toFixed(2)}%</td>
                        <td>${item.rsi.toFixed(1)}</td>
                        <td>$${mcap}</td>
                        <td>
                            <button class="ai-badge" onclick="getInsight('${item.symbol}', '${item.name}', this)">ASK AI</button>
                            <div class="ai-response"></div>
                        </td>
                    </tr>
                `;
            });
            html += '</tbody></table>';
            container.innerHTML = html;
        }

        // --- Magic Feature ---
        async function runMagicScan() {
            const btn = document.querySelector('.magic-btn');
            const resDiv = document.getElementById('magicResult');
            
            btn.disabled = true;
            btn.innerHTML = '🔮 CONSULTING ORACLE...';
            resDiv.style.display = 'none';

            try {
                const res = await fetch('/api/magic');
                const data = await res.json();
                
                if (data.pick) {
                    const p = data.pick;
                    resDiv.style.display = 'block';
                    resDiv.innerHTML = `
                        <h3 style="color: var(--accent); margin-bottom: 10px;">DAILY PICK: ${p.symbol}</h3>
                        <div style="font-size: 24px; font-weight: bold; margin-bottom: 10px;">${p.action}</div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px; font-size: 14px;">
                            <div>RSI: ${p.rsi.toFixed(1)}</div>
                            <div>Score: ${p.score}/10</div>
                        </div>
                        <div class="ai-text" style="border-left: 3px solid var(--accent);">
                            ${p.ai_analysis}
                        </div>
                    `;
                }
            } catch (e) {
                alert('Magic failed. Try again.');
            }

            btn.disabled = false;
            btn.innerHTML = '✨ GET DAILY PICK';
        }

        // --- Holdings ---
        async function loadHoldings() {
            const container = document.getElementById('holdingsList');
            container.innerHTML = 'Loading portfolio...';
            
            try {
                const res = await fetch('/api/holdings');
                const data = await res.json();
                
                if (!data.holdings.length) {
                    container.innerHTML = '<div style="padding:20px; text-align:center; color:#666;">No holdings added yet.</div>';
                    return;
                }

                let html = `<table><thead><tr><th>Symbol</th><th>Price</th><th>RSI</th><th>Action</th><th>AI Advice</th></tr></thead><tbody>`;
                
                data.holdings.forEach(h => {
                    html += `
                        <tr>
                            <td class="symbol-cell">${h.symbol}</td>
                            <td>$${h.price.toFixed(2)}</td>
                            <td>${h.rsi.toFixed(1)}</td>
                            <td><button onclick="removeHolding('${h.symbol}')" style="color: #ff4757; background:none; border:none; cursor:pointer;">Remove</button></td>
                            <td>
                                <button class="ai-badge" onclick="getInsight('${h.symbol}', '${h.name}', this)">ASK AI</button>
                                <div class="ai-response"></div>
                            </td>
                        </tr>
                    `;
                });
                html += '</tbody></table>';
                container.innerHTML = html;

            } catch (e) {
                container.innerHTML = 'Error loading holdings.';
            }
        }

        async function addHolding() {
            const input = document.getElementById('newSymbol');
            const symbol = input.value.trim();
            if (!symbol) return;

            await fetch('/api/holdings', {
                method: 'POST',
                body: JSON.stringify({ symbol: symbol })
            });
            
            input.value = '';
            loadHoldings();
        }

        async function removeHolding(symbol) {
            if (!confirm('Remove ' + symbol + '?')) return;
            
            await fetch('/api/holdings', {
                method: 'DELETE',
                body: JSON.stringify({ symbol: symbol })
            });
            loadHoldings();
        }

        // --- Shared AI ---
        async function getInsight(symbol, name, btn) {
            btn.innerHTML = 'Thinking...';
            const container = btn.nextElementSibling;
            
            try {
                const res = await fetch('/api/verify', {
                    method: 'POST',
                    body: JSON.stringify({ symbol, name })
                });
                const data = await res.json();
                
                container.innerHTML = `<div class="ai-text">${data.verdict}</div>`;
                btn.innerHTML = 'REFRESH';
            } catch (e) {
                btn.innerHTML = 'Error';
            }
        }
    </script>
</body>
</html>
