<?php
/**
 * ESPN Fantasy Football Dashboard for Namecheap Hosting (PHP)
 */

// 1. CONFIGURATION
$myLeagues = [
    [
        'id'   => '5867033',
        'name' => 'Varsity'
    ],
    [
        'id'   => '125199559',
        'name' => 'JV'
    ],
    [
        'id'   => '1860357183',
        'name' => 'Freshman'
    ]
];

// 2. DETERMINE SELECTED LEAGUE
// Get the league index from the URL (defaults to index 0 if not set or invalid)
$selectedIdx = isset($_GET['league']) ? (int)$_GET['league'] : 0;
if (!isset($myLeagues[$selectedIdx])) {
    $selectedIdx = 0;
}

$activeLeagueId   = $myLeagues[$selectedIdx]['id'];
$activeLeagueName = $myLeagues[$selectedIdx]['name'];
$season = "2026";

$cacheFile = __DIR__ . "/espn_fantasy_cache_{$activeLeagueId}.json"; 
$cacheTime = 300;        // 👈 Cache duration in seconds (300 seconds = 5 minutes)

// Build the multi-view API URL
$url = "https://lm-api-reads.fantasy.espn.com/apis/v3/games/ffl/seasons/{$season}/segments/0/leagues/{$activeLeagueId}?view=mTeam&view=mStandings&view=mMatchup";

// 2. CACHING LOGIC
$fetchNewData = true;

// Check if a previously saved cache file exists
if (file_exists($cacheFile)) {
    // Check if the file is fresher than 5 minutes
    if ((time() - filemtime($cacheFile)) < $cacheTime) {
        $response = file_get_contents($cacheFile);
        
        // Ensure the cached data isn't corrupt or empty
        if ($response !== FALSE && !empty($response)) {
            $fetchNewData = false;
        }
    }
}

// If the cache is old or doesn't exist, call ESPN and save a new copy
if ($fetchNewData) {
    $response = file_get_contents($url);
    
    if ($response === FALSE) {
        // Fallback: If ESPN fails to respond, try to load the old cache anyway so the site doesn't crash
        if (file_exists($cacheFile)) {
            $response = file_get_contents($cacheFile);
        } else {
            die("Error: Unable to fetch live data from ESPN and no local cache exists.");
        }
    } else {
        // Save the fresh live response to your Namecheap server for next time
        file_put_contents($cacheFile, $response);
    }
}

$data = json_decode($response, true);

// Extract global status details
$currentWeek = $data['status']['currentMatchupPeriod'];

// 3. DATA PROCESSING
// Map Team IDs to their actual names so we can display them easily later
$teams = [];
foreach ($data['teams'] as $t) {
    $teams[$t['id']] = [
        'name'   => $t['name'],
        'wins'   => $t['record']['overall']['wins'],
        'losses' => $t['record']['overall']['losses'],
        'ties'   => $t['record']['overall']['ties'],
        'points' => $t['record']['overall']['pointsFor'],
        'rank'   => $t['playoffSeed']
    ];
}

// Sort the teams array by rank (Playoff Seed) for the standings table
uasort($teams, function($a, $b) {
    return $a['rank'] <=> $b['rank'];
});

// Filter out only the matchups for the current active week
$currentMatchups = [];
if (!empty($data['schedule'])) {
    foreach ($data['schedule'] as $matchup) {
        if ($matchup['matchupPeriodId'] == $currentWeek) {
            $currentMatchups[] = $matchup;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($activeLeagueName); ?> - Dashboard</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #f7f9fa; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { color: #111; margin-bottom: 5px; }
        h2 { color: #111; border-bottom: 2px solid #ddd; padding-bottom: 8px; margin-top: 30px; }
        
        /* League Selection Toggle Layout */
        .league-toggle { display: flex; gap: 10px; margin: 20px 0 30px 0; background: #e2e8f0; padding: 6px; border-radius: 8px; width: fit-content; }
        .toggle-btn { text-decoration: none; padding: 8px 16px; border-radius: 6px; color: #4a5568; font-weight: 500; font-size: 14px; transition: all 0.2s; }
        .toggle-btn:hover { background: #cbd5e1; }
        .toggle-btn.active { background: #fff; color: #1a202c; box-shadow: 0 2px 4px rgba(0,0,0,0.06); font-weight: 600; }
        
        /* Layout Tables & Scoreboard Cards */
        table { width: 100%; border-collapse: collapse; background: #fff; margin-bottom: 40px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-radius: 6px; overflow: hidden; }
        th, td { padding: 12px 15px; text-align: left; }
        th { background-color: #1a202c; color: #fff; text-transform: uppercase; font-size: 12px; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .team-cell { display: flex; align-items: center; gap: 10px; }
        .team-logo { width: 24px; height: 24px; border-radius: 50%; object-fit: cover; }
        
        .matchups-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .matchup-card { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-left: 4px solid #3182ce; }
        .matchup-team { display: flex; justify-content: space-between; align-items: center; margin: 10px 0; }
        .matchup-team.winner { font-weight: bold; color: #2f855a; }
        .score { font-size: 16px; font-weight: 600; }
    </style>
</head>
<body>

<div class="container">
    <h1>🏈 Fantasy Football Dashboard</h1>
    <p style="color: #718096; margin: 0;">Season: <?php echo $season; ?></p>

    <!-- LEAGUE SELECTION TOGGLE -->
    <div class="league-toggle">
        <?php foreach ($myLeagues as $index => $league): ?>
            <a href="?league=<?php echo $index; ?>" 
               class="toggle-btn <?php echo ($selectedIdx === $index) ? 'active' : ''; ?>">
                <?php echo htmlspecialchars($league['name']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- SECTION 1: LEAGUE STANDINGS -->
    <h2>🏆 <?php echo htmlspecialchars($activeLeagueName); ?> Current Standings</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 60px;">Seed</th>
                <th>Team</th>
                <th style="width: 100px;">Record</th>
                <th style="width: 120px;">Points For</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($teams as $id => $team): ?>
            <tr>
                <td><strong><?= $team['rank']; ?></strong></td>
                <td>
                    <div class="team-cell">
                        <span><?php echo htmlspecialchars($team['name']); ?></span>
                    </div>
                </td>
                <td><?php echo "{$team['wins']}-{$team['losses']}-{$team['ties']}"; ?></td>
                <td><?php echo number_format($team['points'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- SECTION 2: CURRENT WEEK SCORES -->
    <h2>📊 Week <?php echo $currentWeek; ?> Scoreboard</h2>
    <div class="matchups-grid">
        <?php foreach ($currentMatchups as $match): 
            $homeId = $match['home']['teamId'];
            $awayId = $match['away']['teamId'];
            
            $homeScore = $match['home']['pointsByScoringPeriod'][$currentWeek];
            $awayScore = $match['away']['pointsByScoringPeriod'][$currentWeek];
            
            // Basic logic to bold who is currently winning
            $homeWinning = $homeScore > $awayScore;
            $awayWinning = $awayScore > $homeScore;
        ?>
        <div class="matchup-card">
            <!-- Away Team Row -->
            <div class="matchup-team <?php echo $awayWinning ? 'winner' : ''; ?>">
                <span><?php echo htmlspecialchars($teams[$awayId]['name'] ?? 'Away Team'); ?></span>
                <span class="score"><?php echo number_format($awayScore, 2); ?></span>
            </div>
            
            <div style="text-align: center; color: #a0aec0; font-size: 12px; margin: 4px 0;">VS</div>
            
            <!-- Home Team Row -->
            <div class="matchup-team <?php echo $homeWinning ? 'winner' : ''; ?>">
                <span><?php echo htmlspecialchars($teams[$homeId]['name'] ?? 'Home Team'); ?></span>
                <span class="score"><?php echo number_format($homeScore, 2); ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>