<?php

require_once __DIR__ . '/espn_data.php';

$leagues = getLeagueConfigs();
$season = '2026';
$leagueData = [];

foreach ($leagues as $league) {
    $leagueData[$league['id']] = [
        'config' => $league,
        'data' => loadLeagueData($league['id'], $season)
    ];
}

$currentWeek = null;
foreach ($leagueData as $league) {
    $week = $league['data']['status']['currentMatchupPeriod'] ?? null;
    if ($week !== null) {
        $currentWeek = max($currentWeek ?? $week, $week);
    }
}

function countLeagueTeams(array $data): int
{
    return count($data['teams'] ?? []);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superleague Stats</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f7f9fa; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { color: #111; margin-bottom: 5px; }
        h2 { color: #111; border-bottom: 2px solid #ddd; padding-bottom: 8px; margin-top: 30px; }
        .league-toggle { display: flex; flex-wrap: wrap; gap: 10px; margin: 20px 0 30px 0; background: #e2e8f0; padding: 6px; border-radius: 8px; width: fit-content; }
        .toggle-btn { text-decoration: none; padding: 8px 16px; border-radius: 6px; color: #4a5568; font-weight: 500; font-size: 14px; transition: all 0.2s; }
        .toggle-btn:hover { background: #cbd5e1; }
        .toggle-btn.active { background: #fff; color: #1a202c; box-shadow: 0 2px 4px rgba(0,0,0,0.06); font-weight: 600; }
        .league-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; }
        .league-card { background: #fff; border-left: 4px solid #3182ce; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); padding: 18px; }
        .league-card h2 { border: 0; margin: 0 0 10px; padding: 0; }
        .league-card p { margin: 6px 0; }
        .label { color: #718096; }
    </style>
</head>
<body>
<div class="container">
    <h1>🏈 Fantasy Football Superleague</h1>

    <nav class="league-toggle" aria-label="Site navigation">
        <?php foreach ($leagues as $league): ?>
            <a href="index.php?league=<?php echo urlencode($league['id']); ?>" class="toggle-btn">
                <?php echo htmlspecialchars($league['name']); ?>
            </a>
        <?php endforeach; ?>
        <a href="stats" class="toggle-btn active" aria-current="page">Superleague Stats</a>
    </nav>

    <h2>League Data Loaded</h2>
    <div class="league-grid">
        <?php foreach ($leagueData as $league): ?>
            <section class="league-card">
                <h2><?php echo htmlspecialchars($league['config']['name']); ?></h2>
                <p><span class="label">Current week:</span> <?php echo htmlspecialchars((string)($league['data']['status']['currentMatchupPeriod'] ?? 'Unknown')); ?></p>
                <p><span class="label">Teams loaded:</span> <?php echo countLeagueTeams($league['data']); ?></p>
            </section>
        <?php endforeach; ?>
    </div>

    <h2>Stats Framework</h2>
    <p>All three league datasets are loaded and available in <code>$leagueData</code> for the upcoming superleague statistics.</p>
</div>
</body>
</html>