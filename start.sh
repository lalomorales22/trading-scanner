#!/bin/bash

# Penguin-Burry Trading Scanner - Quick Start Script

echo "🐧 Penguin-Burry Trading Scanner Setup"
echo "========================================"
echo ""

# Check PHP
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed. Please install PHP 7.4+ with SQLite3 extension."
    exit 1
fi

echo "✓ PHP found: $(php -v | head -n 1)"

# Check SQLite3 extension
if ! php -m | grep -q sqlite3; then
    echo "❌ SQLite3 extension not found. Please install php-sqlite3."
    exit 1
fi

echo "✓ SQLite3 extension found"

# Check cURL extension
if ! php -m | grep -q curl; then
    echo "❌ cURL extension not found. Please install php-curl."
    exit 1
fi

echo "✓ cURL extension found"

# Check if .env exists
if [ ! -f .env ]; then
    echo ""
    echo "⚠️  .env file not found. Creating from template..."
    cat > .env << 'EOF'
# API Configuration
FINNHUB_API_KEY=your_finnhub_api_key_here
ANTHROPIC_API_KEY=your_anthropic_api_key_here

# Database Configuration
DB_PATH=trading_scanner.db
EOF
    echo "✓ .env file created"
    echo ""
    echo "📝 IMPORTANT: Edit .env and add your API keys:"
    echo "   - Finnhub: https://finnhub.io/register"
    echo "   - Anthropic: https://console.anthropic.com"
    echo ""
    read -p "Press Enter once you've added your API keys..."
fi

# Verify API keys are set
source .env
if [ "$FINNHUB_API_KEY" = "your_finnhub_api_key_here" ] || [ -z "$FINNHUB_API_KEY" ]; then
    echo "⚠️  Warning: Finnhub API key not set in .env"
fi

if [ "$ANTHROPIC_API_KEY" = "your_anthropic_api_key_here" ] || [ -z "$ANTHROPIC_API_KEY" ]; then
    echo "⚠️  Warning: Anthropic API key not set in .env"
fi

# Check if database exists
if [ -f trading_scanner.db ]; then
    echo "✓ Database found"
else
    echo "✓ Database will be created on first run"
fi

# Start server
echo ""
echo "🚀 Starting PHP development server..."
echo ""
echo "Access the scanner at: http://localhost:8000"
echo ""
echo "Press Ctrl+C to stop the server"
echo ""

php -S localhost:8000
