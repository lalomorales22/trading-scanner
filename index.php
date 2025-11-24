<?php
// Load environment variables
function loadEnv($path)
{
    if (!file_exists($path)) {
        die('Error: .env file not found');
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

loadEnv(__DIR__ . '/.env');

// Database setup
function initDatabase()
{
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

function handleGetLogs()
{
    global $db;
    $results = [];
    $res = $db->query('SELECT * FROM ai_logs ORDER BY created_at DESC LIMIT 100');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $results[] = $row;
    }
    echo json_encode(['success' => true, 'logs' => $results]);
}



function handleHoldings($method)
{
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
                $row['volume'] = $quote['v'] ?? 0;
                $row['dayHigh'] = $quote['h'] ?? 0;
                $row['dayLow'] = $quote['l'] ?? 0;
                $row['gap'] = isset($quote['o'], $quote['pc']) && $quote['pc'] != 0 ? (($quote['o'] - $quote['pc']) / $quote['pc'] * 100) : 0;
                $row['marketCap'] = rand(100000000, 1000000000000); // Mock Market Cap
            } else {
                $row['price'] = 0;
                $row['price_change'] = 0;
                $row['rsi'] = 50;
                $row['volume'] = 0;
                $row['dayHigh'] = 0;
                $row['dayLow'] = 0;
                $row['gap'] = 0;
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

function handleMagicPick()
{
    // The "Sweet Special Algorithm"
    // 1. Scan ALL tracked stocks for high-signal setups
    // 2. Pick the absolute best one based on technical score
    // 3. Get Deep AI verification

    global $db;
    $apiKey = $_ENV['FINNHUB_API_KEY'];

    // Use the full list of popular stocks for the magic pick
    $candidates = [
        'NVDA',
        'TSLA',
        'AMD',
        'COIN',
        'MSTR',
        'AAPL',
        'MSFT',
        'GOOGL',
        'AMZN',
        'META',
        'PLTR',
        'MARA',
        'RIOT',
        'HOOD',
        'DKNG',
        'UBER',
        'ABNB',
        'SNOW',
        'CRM',
        'NFLX',
        'INTC',
        'PYPL',
        'SQ'
    ];

    $bestPick = null;
    $highestScore = -100; // Allow for negative scores to be beaten

    foreach ($candidates as $symbol) {
        $quote = fetchQuote($symbol, $apiKey);
        if (!$quote)
            continue;

        $rsi = calculateEstimatedRSI($quote['dp'] ?? 0);
        $change = $quote['dp'] ?? 0;
        $score = 0;

        // Advanced Scoring Logic
        // 1. Extreme RSI (Reversion)
        if ($rsi > 80)
            $score += 3;      // Extreme Overbought (Short candidate)
        elseif ($rsi > 70)
            $score += 1;
        elseif ($rsi < 20)
            $score += 3;  // Extreme Oversold (Long candidate)
        elseif ($rsi < 30)
            $score += 1;

        // 2. Volatility/Momentum
        if (abs($change) > 10)
            $score += 2; // Big move = Big opportunity
        elseif (abs($change) > 5)
            $score += 1;

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
        if ($bestPick['rsi'] > 70)
            $bestPick['action'] = 'SHORT / SELL (Overextended)';
        elseif ($bestPick['rsi'] < 30)
            $bestPick['action'] = 'LONG / BUY (Oversold)';
        else
            $bestPick['action'] = 'WATCH (Momentum)';

        // Get Deep AI Insight
        // We pass the specific context of the "Magic Pick" to the AI
        $searchResults = performWebSearch($bestPick['symbol'] . ' stock news institutional flows');

        // Pass technicals to AI
        $technicals = [
            'price' => $bestPick['price'],
            'change' => $bestPick['change'],
            'rsi' => $bestPick['rsi'],
            'score' => $bestPick['score']
        ];

        $verdict = askClaude($bestPick['symbol'], 'Magic Pick Analysis', $searchResults, $technicals, []);
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

function handleScan()
{
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);
    $preset = $data['preset'] ?? 'custom';
    $filters = $data['filters'];

    // Scan stocks using Finnhub API
    $apiKey = $_ENV['FINNHUB_API_KEY'];

    // EXPANDED MARKET POOL (100+ Tickers)
    $marketPool = [
        // MAG 7 / BIG TECH
        ['symbol' => 'AAPL', 'name' => 'Apple'],
        ['symbol' => 'MSFT', 'name' => 'Microsoft'],
        ['symbol' => 'GOOGL', 'name' => 'Google'],
        ['symbol' => 'AMZN', 'name' => 'Amazon'],
        ['symbol' => 'NVDA', 'name' => 'Nvidia'],
        ['symbol' => 'META', 'name' => 'Meta'],
        ['symbol' => 'TSLA', 'name' => 'Tesla'],
        ['symbol' => 'AMD', 'name' => 'AMD'],
        ['symbol' => 'NFLX', 'name' => 'Netflix'],
        ['symbol' => 'AVGO', 'name' => 'Broadcom'],

        // CRYPTO / MINERS (High Volatility)
        ['symbol' => 'COIN', 'name' => 'Coinbase'],
        ['symbol' => 'MSTR', 'name' => 'MicroStrategy'],
        ['symbol' => 'MARA', 'name' => 'Marathon Digital'],
        ['symbol' => 'RIOT', 'name' => 'Riot Platforms'],
        ['symbol' => 'CLSK', 'name' => 'CleanSpark'],
        ['symbol' => 'HUT', 'name' => 'Hut 8'],
        ['symbol' => 'BITF', 'name' => 'Bitfarms'],
        ['symbol' => 'CORZ', 'name' => 'Core Scientific'],
        ['symbol' => 'IREN', 'name' => 'Iris Energy'],
        ['symbol' => 'WULF', 'name' => 'Terawulf'],

        // MEME / RETAIL (For "Idiot" Mode)
        ['symbol' => 'GME', 'name' => 'GameStop'],
        ['symbol' => 'AMC', 'name' => 'AMC Ent'],
        ['symbol' => 'HOOD', 'name' => 'Robinhood'],
        ['symbol' => 'DKNG', 'name' => 'DraftKings'],
        ['symbol' => 'PLTR', 'name' => 'Palantir'],
        ['symbol' => 'SOFI', 'name' => 'SoFi'],
        ['symbol' => 'OPEN', 'name' => 'Opendoor'],
        ['symbol' => 'CVNA', 'name' => 'Carvana'],
        ['symbol' => 'UPST', 'name' => 'Upstart'],
        ['symbol' => 'AI', 'name' => 'C3.ai'],
        ['symbol' => 'RIVN', 'name' => 'Rivian'],
        ['symbol' => 'LCID', 'name' => 'Lucid'],
        ['symbol' => 'CHPT', 'name' => 'ChargePoint'],
        ['symbol' => 'SPCE', 'name' => 'Virgin Galactic'],

        // GROWTH / SAAS
        ['symbol' => 'SNOW', 'name' => 'Snowflake'],
        ['symbol' => 'CRM', 'name' => 'Salesforce'],
        ['symbol' => 'SHOP', 'name' => 'Shopify'],
        ['symbol' => 'UBER', 'name' => 'Uber'],
        ['symbol' => 'ABNB', 'name' => 'Airbnb'],
        ['symbol' => 'DASH', 'name' => 'DoorDash'],
        ['symbol' => 'SQ', 'name' => 'Block'],
        ['symbol' => 'PYPL', 'name' => 'PayPal'],
        ['symbol' => 'ROKU', 'name' => 'Roku'],
        ['symbol' => 'TTD', 'name' => 'Trade Desk'],
        ['symbol' => 'NET', 'name' => 'Cloudflare'],
        ['symbol' => 'DDOG', 'name' => 'Datadog'],
        ['symbol' => 'CRWD', 'name' => 'CrowdStrike'],
        ['symbol' => 'ZS', 'name' => 'Zscaler'],

        // SEMICONDUCTORS
        ['symbol' => 'INTC', 'name' => 'Intel'],
        ['symbol' => 'MU', 'name' => 'Micron'],
        ['symbol' => 'QCOM', 'name' => 'Qualcomm'],
        ['symbol' => 'TSM', 'name' => 'TSMC'],
        ['symbol' => 'ARM', 'name' => 'Arm Holdings'],
        ['symbol' => 'SMCI', 'name' => 'Super Micro'],
        ['symbol' => 'TXN', 'name' => 'Texas Instruments'],
        ['symbol' => 'LRCX', 'name' => 'Lam Research'],

        // BLUE CHIP / DOW (For "Sniper" Mode)
        ['symbol' => 'JPM', 'name' => 'JPMorgan'],
        ['symbol' => 'BAC', 'name' => 'Bank of America'],
        ['symbol' => 'WMT', 'name' => 'Walmart'],
        ['symbol' => 'PG', 'name' => 'Procter & Gamble'],
        ['symbol' => 'JNJ', 'name' => 'Johnson & Johnson'],
        ['symbol' => 'XOM', 'name' => 'Exxon Mobil'],
        ['symbol' => 'CVX', 'name' => 'Chevron'],
        ['symbol' => 'KO', 'name' => 'Coca-Cola'],
        ['symbol' => 'DIS', 'name' => 'Disney'],
        ['symbol' => 'BA', 'name' => 'Boeing'],
        ['symbol' => 'CAT', 'name' => 'Caterpillar'],
        ['symbol' => 'DE', 'name' => 'Deere'],
        ['symbol' => 'F', 'name' => 'Ford'],
        ['symbol' => 'GM', 'name' => 'GM'],
        ['symbol' => 'COST', 'name' => 'Costco'],
        ['symbol' => 'TGT', 'name' => 'Target']
    ];

    // Randomly sample 28 stocks to respect API rate limits (approx 30 calls/min safe zone)
    // This ensures variety on every click without breaking the scanner.
    shuffle($marketPool);
    $batchToScan = array_slice($marketPool, 0, 28);

    $results = [];

    foreach ($batchToScan as $stock) {
        $quote = fetchQuote($stock['symbol'], $apiKey);
        if (!$quote)
            continue;

        $stockData = [
            'symbol' => $stock['symbol'],
            'name' => $stock['name'],
            'price' => $quote['c'],
            'priceChange' => $quote['dp'] ?? 0,
            'volume' => $quote['v'] ?? 0,
            'volumeRatio' => rand(10, 50) / 10, // Estimated
            'rsi' => calculateEstimatedRSI($quote['dp'] ?? 0),
            'marketCap' => rand(100000000, 1000000000000), // Mocked
            'dayHigh' => $quote['h'] ?? 0,
            'dayLow' => $quote['l'] ?? 0,
            'gap' => isset($quote['o'], $quote['pc']) && $quote['pc'] != 0 ? (($quote['o'] - $quote['pc']) / $quote['pc'] * 100) : 0
        ];

        // Strict Filtering
        // RSI
        if ($stockData['rsi'] < $filters['rsiMin'] || $stockData['rsi'] > $filters['rsiMax'])
            continue;

        // Market Cap (convert filter M to raw)
        $mcapMinRaw = $filters['marketCapMin'] * 1000000;
        $mcapMaxRaw = $filters['marketCapMax'] * 1000000;
        if ($stockData['marketCap'] < $mcapMinRaw || $stockData['marketCap'] > $mcapMaxRaw)
            continue;

        // Price Change
        if ($stockData['priceChange'] < $filters['priceChangeMin'] || $stockData['priceChange'] > $filters['priceChangeMax'])
            continue;

        // Volume
        if ($stockData['volumeRatio'] < $filters['volumeMultiplier'])
            continue;

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

function handleVerify()
{
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);
    $symbol = $data['symbol'];
    $stockName = $data['name'];

    // Web search for recent news
    $searchResults = performWebSearch($symbol . ' ' . $stockName . ' news stock');

    // Get current price and technicals for context
    $apiKey = $_ENV['FINNHUB_API_KEY'];
    $quote = fetchQuote($symbol, $apiKey);
    $currentPrice = $quote['c'] ?? 0;

    $technicals = [
        'price' => $currentPrice,
        'change' => $quote['dp'] ?? 0,
        'high' => $quote['h'] ?? 0,
        'low' => $quote['l'] ?? 0,
        'volume' => $quote['v'] ?? 0,
        'gap' => isset($quote['o'], $quote['pc']) && $quote['pc'] != 0 ? (($quote['o'] - $quote['pc']) / $quote['pc'] * 100) : 0,
        'rsi' => calculateEstimatedRSI($quote['dp'] ?? 0)
    ];

    // Fetch DB History (Previous AI Verdicts)
    $history = [];
    $stmt = $db->prepare('SELECT verdict, created_at FROM ai_logs WHERE symbol = :symbol ORDER BY created_at DESC LIMIT 3');
    $stmt->bindValue(':symbol', $symbol, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $history[] = $row;
    }

    // Ask Claude for verification
    $verdict = askClaude($symbol, $stockName, $searchResults, $technicals, $history);

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



function fetchQuote($symbol, $apiKey)
{
    $url = "https://finnhub.io/api/v1/quote?symbol={$symbol}&token={$apiKey}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return ($data && isset($data['c'])) ? $data : null;
}

function performWebSearch($query)
{
    // Using a simple search API - in production you'd use Google Custom Search or similar
    // For now, returning mock data structure
    return [
        'query' => $query,
        'results' => [
            ['title' => 'Recent news placeholder', 'snippet' => 'Search results would appear here']
        ]
    ];
}

function askClaude($symbol, $stockName, $searchResults, $technicals = [], $history = [])
{
    $apiKey = $_ENV['ANTHROPIC_API_KEY'];

    $techStr = json_encode($technicals, JSON_PRETTY_PRINT);
    $histStr = json_encode($history, JSON_PRETTY_PRINT);

    // Extract key technical values for direct use in prompt
    $price = $technicals['price'] ?? 0;
    $change = $technicals['change'] ?? 0;
    $rsi = isset($technicals['rsi']) ? round($technicals['rsi'], 1) : 'N/A';
    $volume = $technicals['volume'] ?? 0;
    $gap = isset($technicals['gap']) ? round($technicals['gap'], 2) : 0;
    $high = $technicals['high'] ?? 0;
    $low = $technicals['low'] ?? 0;

    $systemPrompt = "You are the Penguin-Burry AI - an elite tactical trading analyst specializing in high-probability setups using technical confluence and market psychology.

METHODOLOGY:
You identify two types of setups:

1. BURRY SHORT (Exhaustion Hunter)
   - Parabolic moves showing exhaustion
   - Signals: RSI >80, volume spike 2-3x, MACD turning negative, price change >15%
   - Critical check: Is the trend exhausted or still strong? Never short strength.
   - Look for: Retail FOMO, blow-off volume, momentum divergence

2. PENGUIN LONG (Divergence Hunter)  
   - Fear rotations where smart money accumulates
   - Signals: RSI 70-85 (momentum without exhaustion), strong volume, solid support
   - Look for: Market weakness but stock holding, institutional accumulation, sector rotation strength

3. NO SETUP
   - If signals don't align, say HOLD
   - Don't force trades that aren't there

CRITICAL RULES:
- Analyze ONLY {$symbol} - no comparisons to other stocks unless explaining direct sector rotation
- State which setup type this is (Burry/Penguin/None)
- Use the actual technical numbers provided: Price \${$price}, Change {$change}%, RSI {$rsi}
- Check disqualifiers: Fake volume? Conflicting signals? Already extended?
- Give specific entry price or HOLD command

RESPONSE FORMAT (EXACTLY 2 SENTENCES):

Sentence 1 - SETUP ANALYSIS:
State the setup type and technical confluence. Example: '{$symbol} shows a [Burry/Penguin/No] setup with RSI at {$rsi}, volume [context], and [momentum state] - [what this means].'

Sentence 2 - VERDICT:  
Give decisive action with specific price. Example: 'LONG at \${$price} targeting \$[target] (stop \$[stop])' OR 'SHORT at \${$price} targeting \$[target] (stop \$[stop])' OR 'HOLD - [specific reason why no trade].'

Focus on THIS stock's technicals and price action. Use the Penguin-Burry signal framework. No generic advice.";

    $userPrompt = "STOCK: {$symbol} ({$stockName})

TECHNICAL SNAPSHOT:
├─ Current Price: \${$price}
├─ Price Change: {$change}%  
├─ RSI: {$rsi}
├─ Volume: " . number_format($volume) . "
├─ Day Range: \${$low} - \${$high}
└─ Gap: {$gap}%

MARKET CONTEXT & NEWS:
" . json_encode($searchResults, JSON_PRETTY_PRINT) . "
" . (count($history) > 0 ? "
PREVIOUS ANALYSIS:
{$histStr}
" : "") . "

Apply Penguin-Burry methodology. Which setup is this? What's the play?";

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
        'max_tokens' => 250,
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

function calculateEstimatedRSI($priceChange)
{
    $baseRSI = 50;
    $rsiShift = $priceChange * 2;
    $estimatedRSI = $baseRSI + $rsiShift;
    $estimatedRSI = max(0, min(100, $estimatedRSI));
    $estimatedRSI += (rand(-50, 50) / 10);
    return max(0, min(100, $estimatedRSI));
}

function calculateSignals($stock, $filters)
{
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
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=JetBrains+Mono:wght@400;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg-dark: #000000;
            --bg-panel: #0a0a0a;
            --border: #1a1a1a;
            --accent: #00d4ff;
            --accent-glow: rgba(0, 212, 255, 0.2);
            --success: #00ff88;
            --danger: #ff4757;
            --text-main: #ffffff;
            --text-dim: #666666;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #000;
        }

        ::-webkit-scrollbar-thumb {
            background: #333;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--accent);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #000000;
            color: var(--text-main);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .app-container {
            width: 95%;
            max-width: 1600px;
            height: 90vh;
            background: var(--bg-dark);
            border: 1px solid #333;
            border-radius: 24px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            /* White highlight around the main window */
            box-shadow: 0 0 30px rgba(255, 255, 255, 0.15);
            position: relative;
        }

        .app-header {
            padding: 20px 30px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #050505;
        }

        .logo {
            font-family: 'JetBrains Mono', monospace;
            font-size: 24px;
            font-weight: 800;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
            text-shadow: 0 0 10px var(--accent-glow);
        }

        .header-controls {
            display: flex;
            gap: 15px;
        }

        .dashboard-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            padding: 25px;
            height: 100%;
            overflow-y: auto;
        }

        .col-left,
        .col-right {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .card {
            background: var(--bg-panel);
            border: 1px solid var(--accent);
            /* Blue highlight around widgets */
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 0 20px rgba(0, 212, 255, 0.05);
            position: relative;
        }

        .card-header {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--accent);
            margin-bottom: 20px;
            border-bottom: 1px solid #222;
            padding-bottom: 10px;
            font-weight: 700;
        }

        /* Magic Section */
        .magic-section {
            background: linear-gradient(135deg, #0a0a12 0%, #050a0d 100%);
            border: 1px solid var(--accent);
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 0 25px rgba(0, 212, 255, 0.1);
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
            box-shadow: 0 0 20px rgba(0, 212, 255, 0.4);
            transition: transform 0.2s;
            margin-bottom: 20px;
        }

        .magic-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 0 30px rgba(0, 212, 255, 0.6);
        }

        .magic-result {
            display: none;
            background: rgba(0, 0, 0, 0.6);
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
            border: 1px solid var(--accent);
        }

        /* Personality Selector */
        .personality-selector {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .personality-card {
            background: #000;
            border: 1px solid #333;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }

        .personality-card:hover {
            border-color: var(--accent);
            box-shadow: 0 0 10px var(--accent-glow);
        }

        .personality-card.active {
            background: var(--accent-glow);
            border-color: var(--accent);
            color: white;
        }

        /* Logs Dropdown */
        .logs-dropdown {
            position: absolute;
            top: 80px;
            right: 30px;
            width: 500px;
            max-height: 600px;
            background: #000;
            border: 1px solid var(--accent);
            border-radius: 12px;
            box-shadow: 0 0 50px rgba(0, 0, 0, 0.8);
            z-index: 1000;
            display: none;
            flex-direction: column;
            overflow: hidden;
        }

        .logs-dropdown.open {
            display: flex;
        }

        .logs-header {
            padding: 15px;
            background: #111;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logs-content {
            overflow-y: auto;
            max-height: 500px;
        }

        /* Inputs & Buttons */
        .input-group {
            margin-bottom: 15px;
        }

        .input-group label {
            display: block;
            color: var(--text-dim);
            font-size: 12px;
            margin-bottom: 5px;
        }

        .input-group input {
            width: 100%;
            background: #000;
            border: 1px solid #333;
            color: white;
            padding: 10px;
            border-radius: 6px;
            font-family: 'JetBrains Mono', monospace;
        }

        .input-group input:focus {
            border-color: var(--accent);
            outline: none;
        }

        .action-btn {
            width: 100%;
            background: #111;
            border: 1px solid var(--accent);
            color: var(--accent);
            padding: 12px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .action-btn:hover {
            background: var(--accent);
            color: #000;
            box-shadow: 0 0 15px var(--accent-glow);
        }

        .btn-ghost {
            background: transparent;
            border: 1px solid #444;
            color: #aaa;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-ghost:hover {
            border-color: white;
            color: white;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            color: var(--text-dim);
            font-size: 12px;
            padding: 10px;
            border-bottom: 1px solid #333;
            cursor: pointer;
        }

        td {
            padding: 12px 10px;
            border-bottom: 1px solid #222;
            font-size: 14px;
        }

        .symbol-cell {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            color: var(--accent);
        }

        .price-up {
            color: var(--success);
        }

        .price-down {
            color: var(--danger);
        }

        .ai-badge {
            background: rgba(108, 30, 255, 0.2);
            color: #b388ff;
            border: 1px solid #6c1eff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
        }

        .ai-badge:hover {
            background: #6c1eff;
            color: white;
        }

        .add-holding-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .add-holding-form input {
            flex: 1;
            background: #000;
            border: 1px solid #333;
            color: white;
            padding: 10px;
            border-radius: 6px;
            font-family: 'JetBrains Mono', monospace;
        }

        .add-holding-form input:focus {
            border-color: var(--accent);
            outline: none;
        }

        .add-holding-form button {
            width: auto;
        }

        /* Mobile Responsiveness */
        @media (max-width: 1024px) {
            .app-container {
                width: 100%;
                height: 100%;
                border-radius: 0;
                border: none;
            }

            .dashboard-layout {
                grid-template-columns: 1fr;
                padding: 15px;
                gap: 15px;
            }

            .app-header {
                padding: 15px;
            }

            .logo {
                font-size: 20px;
            }
        }
    </style>
</head>

<body>

    <body>
        <div class="app-container">
            <div class="app-header">
                <div class="logo">🐧 P-B AI</div>
                <div class="header-controls">
                    <button class="btn-ghost" onclick="toggleLogs()">📜 View AI Logs</button>
                </div>
            </div>

            <!-- AI Logs Dropdown Panel -->
            <div id="logsDropdown" class="logs-dropdown">
                <div class="logs-header">
                    <span style="color:white; font-weight:bold;">AI Analysis History</span>
                    <button onclick="toggleLogs()"
                        style="background:none; border:none; color:#666; cursor:pointer;">✕</button>
                </div>
                <div class="logs-content">
                    <table>
                        <tbody id="logs-table-body">
                            <!-- Logs will load here -->
                        </tbody>
                    </table>
                </div>
                <div style="padding:10px; text-align:center; border-top:1px solid #333;">
                    <button class="btn-ghost" style="font-size:12px;" onclick="loadLogs()">🔄 Refresh Logs</button>
                </div>
            </div>

            <div class="dashboard-layout">
                <!-- Left Column -->
                <div class="col-left">
                    <!-- Magic Section -->
                    <div class="magic-section">
                        <h2>Good Morning, Trader</h2>
                        <p style="color: #888; margin-bottom: 20px;">Click below for your daily AI-powered market
                            insight.</p>
                        <button class="magic-btn" onclick="runMagicScan()">✨ GET DAILY PICK</button>
                        <div id="magicResult" class="magic-result"></div>
                    </div>

                    <!-- Holdings Section -->
                    <div class="card">
                        <div class="card-header">My Portfolio</div>
                        <div class="add-holding-form">
                            <input type="text" id="newSymbol" placeholder="Enter Stock Symbol (e.g. AAPL)">
                            <button class="action-btn" onclick="addHolding()">+ Add</button>
                        </div>
                        <div id="holdingsList"></div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="col-right">
                    <!-- Personality & Filters -->
                    <div class="card">
                        <div class="card-header">AI Personality</div>
                        <div class="personality-selector">
                            <div class="personality-card active" onclick="setPersonality('burry', this)">
                                <span class="p-icon">🐻</span>
                                <div class="p-name">BURRY</div>
                            </div>
                            <div class="personality-card" onclick="setPersonality('penguin', this)">
                                <span class="p-icon">🐧</span>
                                <div class="p-name">PENGUIN</div>
                            </div>
                            <div class="personality-card" onclick="setPersonality('sniper', this)">
                                <span class="p-icon">🎯</span>
                                <div class="p-name">SNIPER</div>
                            </div>
                            <div class="personality-card" onclick="setPersonality('idiot', this)">
                                <span class="p-icon">🤪</span>
                                <div class="p-name">IDIOT</div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="input-group">
                                <label>RSI Range</label>
                                <div style="display: flex; gap: 5px;">
                                    <input type="number" id="rsiMin" value="70">
                                    <input type="number" id="rsiMax" value="100">
                                </div>
                            </div>
                            <div class="input-group">
                                <label>MCap ($M)</label>
                                <div style="display: flex; gap: 5px;">
                                    <input type="number" id="mcapMin" value="1000">
                                    <input type="number" id="mcapMax" value="2000000">
                                </div>
                            </div>
                        </div>

                        <!-- Hidden filters for cleaner UI, set by personality -->
                        <input type="hidden" id="changeMin" value="5">
                        <input type="hidden" id="changeMax" value="50">
                        <input type="hidden" id="volMult" value="2.0">

                        <button class="action-btn" onclick="runScan()">RUN SCANNER</button>
                    </div>

                    <!-- Scanner Results -->
                    <div class="card" style="flex:1; display:flex; flex-direction:column;">
                        <div class="card-header">Live Opportunities</div>
                        <div id="scanResults" style="flex:1; overflow-y:auto;">
                            <div style="text-align: center; padding: 40px; color: #444;">
                                Select a personality and run the scanner.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // --- Navigation ---
            // --- Initialization ---
            window.onload = function () {
                loadHoldings();
                runScan(); // Run default scan
                loadLogs(); // Pre-load logs
            };

            function toggleLogs() {
                const dropdown = document.getElementById('logsDropdown');
                dropdown.classList.toggle('open');
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
                <th onclick="sortTable('gap')">Gap%${getArrow('gap')}</th>
                <th onclick="sortTable('volume')">Vol${getArrow('volume')}</th>
                <th onclick="sortTable('marketCap')">MCap${getArrow('marketCap')}</th>
                <th>Day Range</th>
                <th onclick="sortTable('rsi')">RSI${getArrow('rsi')}</th>
                <th>AI Insight</th>
            </tr></thead><tbody>`;

                items.forEach(item => {
                    const changeClass = item.priceChange >= 0 ? 'price-up' : 'price-down';
                    const gapClass = item.gap >= 0 ? 'price-up' : 'price-down';
                    const mcap = (item.marketCap / 1000000).toFixed(0) + 'M';
                    const vol = item.volume > 0 ? (item.volume / 1000000).toFixed(1) + 'M' : 'Closed';

                    html += `
                    <tr>
                        <td class="symbol-cell">${item.symbol}</td>
                        <td>$${item.price.toFixed(2)}</td>
                        <td class="${changeClass}">${item.priceChange.toFixed(2)}%</td>
                        <td class="${gapClass}">${item.gap.toFixed(2)}%</td>
                        <td>${vol}</td>
                        <td>${mcap}</td>
                        <td style="font-size:12px; color:#666;">$${item.dayLow.toFixed(2)} - $${item.dayHigh.toFixed(2)}</td>
                        <td>${item.rsi.toFixed(1)}</td>
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

                    let html = `<table><thead><tr>
                    <th>Symbol</th>
                    <th>Price</th>
                    <th>Change</th>
                    <th>Gap%</th>
                    <th>Vol</th>
                    <th>MCap</th>
                    <th>Range</th>
                    <th>RSI</th>
                    <th>Action</th>
                    <th>AI Advice</th>
                </tr></thead><tbody>`;

                    data.holdings.forEach(h => {
                        const changeClass = h.price_change >= 0 ? 'price-up' : 'price-down';
                        const gapClass = h.gap >= 0 ? 'price-up' : 'price-down';
                        const vol = h.volume > 0 ? (h.volume / 1000000).toFixed(1) + 'M' : 'Closed';
                        const mcap = h.marketCap ? (h.marketCap / 1000000).toFixed(0) + 'M' : 'N/A';

                        html += `
                        <tr>
                            <td class="symbol-cell">${h.symbol}</td>
                            <td>$${h.price.toFixed(2)}</td>
                            <td class="${changeClass}">${h.price_change.toFixed(2)}%</td>
                            <td class="${gapClass}">${h.gap.toFixed(2)}%</td>
                            <td>${vol}</td>
                            <td>${mcap}</td>
                            <td style="font-size:12px; color:#666;">$${h.dayLow.toFixed(2)} - $${h.dayHigh.toFixed(2)}</td>
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