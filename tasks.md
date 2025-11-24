# Future Development Tasks

## Phase 1: Smarter Logic & Enhanced Data
- [ ] **Integrate Real Volume Data**: Replace the estimated volume ratio with real relative volume (RVOL) from the API to catch true breakouts.
- [ ] **Add Moving Averages**: Implement 50-day and 200-day SMA checks to determine the long-term trend (only buy above 200 SMA).
- [ ] **Sector Analysis**: Group stocks by sector (Tech, Energy, etc.) and only trade the strongest sectors.
- [ ] **News Sentiment Analysis**: Instead of just a "check", have the AI score the news from -10 to +10 and auto-filter out stocks with negative sentiment.
- [ ] **Earnings Calendar**: Automatically flag stocks with earnings coming up in the next 3 days to avoid volatility.

## Phase 2: Automation & Trader Skill
- [ ] **Auto-Trade Execution**: Connect to a broker API (like Alpaca or Interactive Brokers) to place paper trades automatically when a "Magic Pick" is found.
- [ ] **Portfolio Balancing**: Have the AI suggest position sizing based on your total account value (e.g., "Buy 5% of account in NVDA").
- [ ] **Stop Loss & Take Profit**: When a stock is added to holdings, have the AI suggest a technical stop loss level and a take profit target.
- [ ] **Morning Briefing Email**: Send an email at 9:00 AM with the top 3 picks and a market summary.
- [ ] **Win/Loss Tracker**: A new "Journal" tab to log your trades and have the AI analyze your mistakes at the end of the week.
