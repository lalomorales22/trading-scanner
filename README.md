# Penguin-Burry AI Trading Assistant

A comprehensive, AI-powered trading dashboard designed for clarity, automation, and insight.

## New Features (v2.0)

- **Expanded Dashboard UI**: A modern, full-screen interface with sidebar navigation.
- **Personality Profiles**: Switch between "Burry" (Short/Top Hunting) and "Penguin" (Long/Dip Buying) strategies with one click.
- **Daily Magic Pick**: A special "Sweet Algorithm" button that finds the single best trade of the day with AI verification.
- **Holdings Management**: A dedicated tab to track your portfolio. Add your symbols and get instant AI advice (Buy/Sell/Hold) for your specific positions.
- **Deep AI Integration**: Every stock card has an "Ask AI" button that provides a 2-sentence summary:
    1.  Red Flags/News Check
    2.  Clear GO/NO-GO Verdict

## Setup

### 1. Installation
Clone the repository and enter the directory:
```bash
git clone <your-repo-url>
cd penguin-stock-watcher
```

### 2. Configuration
Copy the example environment file:
```bash
cp .env.example .env
```
Edit `.env` and add your API keys:
- **Finnhub API Key**: Get free key at [finnhub.io](https://finnhub.io/)
- **Anthropic API Key**: Get key at [console.anthropic.com](https://console.anthropic.com/)

### 3. Run the App
Use the included start script:
```bash
./start.sh
```
Or run with PHP directly:
```bash
php -S localhost:8000
```

### 4. Access
Open your browser to: `http://localhost:8000`

## Usage Guide

### Dashboard
- **Magic Button**: Click "GET DAILY PICK" every morning for the top AI-verified opportunity.
- **Scanner**: Select a personality (Bear/Penguin), adjust filters if needed, and click "RUN SCANNER".
- **AI Insight**: Click "ASK AI" on any result to get a real-time analysis from Claude.

### Holdings Tab
- **Add Stock**: Enter a symbol (e.g., AAPL, TSLA) to add it to your watchlist.
- **Analyze**: Click "ANALYZE" next to any holding to see if you should Sell, Hold, or Buy more based on current news and technicals.

## Database Schema

**scans table:**
- Stores historical scan results.

**stocks table:**
- Stores individual stock data found during scans.

**holdings table:**
- Stores user's portfolio symbols for quick access.

## License

Built for personal trading use. Not financial advice.
