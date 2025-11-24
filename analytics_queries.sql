-- ANALYTICS QUERIES FOR PENGUIN-BURRY SCANNER
-- Run these in your SQLite client or PHP to build analytics dashboard

-- 1. Top performing signals over time
SELECT 
    signals,
    COUNT(*) as count,
    AVG(price_change) as avg_price_change,
    AVG(rsi) as avg_rsi
FROM stocks
GROUP BY signals
ORDER BY signals DESC;

-- 2. Most frequently appearing stocks
SELECT 
    symbol,
    name,
    COUNT(*) as appearances,
    AVG(signals) as avg_signals,
    AVG(price_change) as avg_change
FROM stocks
GROUP BY symbol, name
HAVING appearances > 2
ORDER BY appearances DESC, avg_signals DESC
LIMIT 20;

-- 3. Stocks by strategy preset
SELECT 
    s.preset,
    COUNT(st.id) as total_stocks,
    AVG(st.signals) as avg_signals,
    AVG(st.price_change) as avg_price_change
FROM scans s
LEFT JOIN stocks st ON s.id = st.scan_id
GROUP BY s.preset
ORDER BY s.preset;

-- 4. AI verification success rate
SELECT 
    CASE 
        WHEN ai_verdict LIKE '%clear%' OR ai_verdict LIKE '%good%' THEN 'GO'
        WHEN ai_verdict LIKE '%avoid%' OR ai_verdict LIKE '%no%' THEN 'NO-GO'
        ELSE 'UNKNOWN'
    END as verdict_type,
    COUNT(*) as count,
    AVG(price_change) as avg_price_change
FROM stocks
WHERE ai_verified = 1
GROUP BY verdict_type;

-- 5. Recent scans summary
SELECT 
    s.id,
    s.scan_date,
    s.preset,
    s.total_results,
    COUNT(CASE WHEN st.signals = 5 THEN 1 END) as textbook_setups,
    COUNT(CASE WHEN st.signals = 4 THEN 1 END) as strong_setups,
    COUNT(CASE WHEN st.ai_verified = 1 THEN 1 END) as ai_verified_count
FROM scans s
LEFT JOIN stocks st ON s.id = st.scan_id
GROUP BY s.id
ORDER BY s.scan_date DESC
LIMIT 10;

-- 6. RSI distribution analysis
SELECT 
    CASE 
        WHEN rsi < 30 THEN 'Oversold (<30)'
        WHEN rsi >= 30 AND rsi < 50 THEN 'Bearish (30-50)'
        WHEN rsi >= 50 AND rsi < 70 THEN 'Bullish (50-70)'
        WHEN rsi >= 70 AND rsi < 80 THEN 'Strong (70-80)'
        WHEN rsi >= 80 THEN 'Overbought (>80)'
    END as rsi_zone,
    COUNT(*) as count,
    AVG(price_change) as avg_price_change,
    AVG(signals) as avg_signals
FROM stocks
GROUP BY rsi_zone
ORDER BY 
    CASE rsi_zone
        WHEN 'Oversold (<30)' THEN 1
        WHEN 'Bearish (30-50)' THEN 2
        WHEN 'Bullish (50-70)' THEN 3
        WHEN 'Strong (70-80)' THEN 4
        WHEN 'Overbought (>80)' THEN 5
    END;

-- 7. Volume vs Signal correlation
SELECT 
    CASE 
        WHEN volume_ratio < 2 THEN 'Low (<2x)'
        WHEN volume_ratio >= 2 AND volume_ratio < 3 THEN 'Medium (2-3x)'
        WHEN volume_ratio >= 3 AND volume_ratio < 5 THEN 'High (3-5x)'
        WHEN volume_ratio >= 5 THEN 'Extreme (>5x)'
    END as volume_category,
    AVG(signals) as avg_signals,
    AVG(price_change) as avg_price_change,
    COUNT(*) as count
FROM stocks
GROUP BY volume_category
ORDER BY 
    CASE volume_category
        WHEN 'Low (<2x)' THEN 1
        WHEN 'Medium (2-3x)' THEN 2
        WHEN 'High (3-5x)' THEN 3
        WHEN 'Extreme (>5x)' THEN 4
    END;

-- 8. Time-based patterns
SELECT 
    strftime('%H', created_at) as hour_of_day,
    COUNT(*) as scans_count,
    AVG(signals) as avg_signals
FROM stocks
GROUP BY hour_of_day
ORDER BY hour_of_day;

-- 9. Market cap sweet spot analysis
SELECT 
    CASE 
        WHEN market_cap < 100000000 THEN 'Micro (<100M)'
        WHEN market_cap >= 100000000 AND market_cap < 1000000000 THEN 'Small (100M-1B)'
        WHEN market_cap >= 1000000000 AND market_cap < 10000000000 THEN 'Mid (1B-10B)'
        WHEN market_cap >= 10000000000 THEN 'Large (>10B)'
    END as cap_category,
    COUNT(*) as count,
    AVG(signals) as avg_signals,
    AVG(price_change) as avg_price_change
FROM stocks
GROUP BY cap_category
ORDER BY 
    CASE cap_category
        WHEN 'Micro (<100M)' THEN 1
        WHEN 'Small (100M-1B)' THEN 2
        WHEN 'Mid (1B-10B)' THEN 3
        WHEN 'Large (>10B)' THEN 4
    END;

-- 10. Signal detail breakdown
SELECT 
    signal_details,
    COUNT(*) as count,
    AVG(price_change) as avg_price_change,
    signals as signal_count
FROM stocks
GROUP BY signal_details, signals
ORDER BY signals DESC, count DESC;

-- 11. Clean database (remove old scans older than 30 days)
-- DELETE FROM stocks WHERE scan_id IN (
--     SELECT id FROM scans WHERE scan_date < datetime('now', '-30 days')
-- );
-- DELETE FROM scans WHERE scan_date < datetime('now', '-30 days');

-- 12. Export for backtesting (CSV format ready)
SELECT 
    symbol,
    name,
    price,
    price_change,
    rsi,
    volume_ratio,
    signals,
    signal_details,
    ai_verified,
    ai_verdict,
    created_at
FROM stocks
WHERE signals >= 4
ORDER BY created_at DESC, signals DESC;
