# 🏈 Former Fox Fantasy Dashboard

Former Fox Fantasy Dashboard is a lightweight PHP dashboard for viewing and comparing ESPN Fantasy Football leagues.

## 🕹️ Features

- Switch between the Varsity, JV, and Freshman leagues.
- View league standings, records, points, playoff odds, and team owners.
- Expand each team to view its current roster and player scores.
- Browse matchup scores by scoring period.
- Display projected scores for games that are still in progress.
- Open matchup and team details in ESPN Fantasy Football.
- View cross-league weekly superlatives, including:
  - Highest and lowest team score
  - Largest margin of victory
  - Smallest margin of defeat
  - Fewest points in a victory
  - Most points in a defeat
  - Highest-scoring player
- Select a scoring period and preserve that selection while switching leagues or views.
- Load historical scoring-period data when ESPN's current response does not include previous-period rosters.

## 💾 Data Source

The dashboard pulls league, matchup, roster, standings, and scoring data from ESPN's public Fantasy Football API. It does not require ESPN account authentication for the configured public league data.

Responses are cached locally as JSON files for five minutes. Historical scoring periods use separate cache files so that each selected period can retain its own roster and scoring response.

## 🏃‍♂️ Running Locally

This project requires PHP with access to outbound HTTPS requests. From the project directory, start PHP's built-in web server:

```sh
php -S localhost:8000
```

Then open [http://localhost:8000](http://localhost:8000) in a browser.

There are also extensions available in VSCode for running a PHP server.

The main dashboard is available at `/` and the statistics view is available at `/stats`.

## 🗄️ Project Files

- `index.php` - Main league dashboard with standings, matchups, and expandable rosters.
- `stats.php` - Cross-league weekly statistics and superlatives.
- `espn_data.php` - League configuration, ESPN API requests, and local caching.
- `espn_fantasy_cache_*.json` - Locally generated ESPN response caches.

## 🤖 Development

This project is an experiment in using AI development tools and is built almost entirely with AI assistance, with very few lines of codes being written manually. The implementation, debugging, data-handling changes, and documentation were created collaboratively with AI tools (primarily Copilot) and reviewed in the local development environment.

## 📝 Contributing

The recommended environment for making contributions is VSCode. Use of Copilot or other AI assistance is highly encouraged.

PR descriptions should include a list of changes along with Before & After screenshots.
