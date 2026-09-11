<?php
/**
 * ESPN Fantasy Football Dashboard
 */

// 1. CONFIGURATION
$leagues = [
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
if (!isset($leagues[$selectedIdx])) {
    $selectedIdx = 0;
}

$activeLeagueId   = $leagues[$selectedIdx]['id'];
$activeLeagueName = $leagues[$selectedIdx]['name'];
$season = "2026";

$cacheFile = __DIR__ . "/espn_fantasy_cache_{$activeLeagueId}.json"; 
$cacheTime = 300;        // 👈 Cache duration in seconds (300 seconds = 5 minutes)

// Build the multi-view API URL
$url = "https://lm-api-reads.fantasy.espn.com/apis/v3/games/ffl/seasons/{$season}/segments/0/leagues/{$activeLeagueId}?view=mTeam&view=mStandings&view=mMatchup";

// 3. CACHING LOGIC
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

// 4. DATA PROCESSING
// Map Team IDs to their actual names so we can display them easily later

$members = [];
foreach ($data['members'] ?? [] as $member) {
    $members[$member['id']] = trim(($member['firstName'] ?? '') . ' ' . ($member['lastName'] ?? '')) ?: 'Unknown';
}

$positions = [
    1 => 'QB',
    2 => 'RB',
    3 => 'WR',
    4 => 'TE',
    5 => 'K',
    16 => 'D/ST',
    17 => 'DB',
    18 => 'LB',
    19 => 'DL',
    20 => 'IDP'
];

$teams = [];
foreach ($data['teams'] as $t) {
    $roster = [];
    foreach ($t['roster']['entries'] ?? [] as $entry) {
        $lineupSlotId = (int)($entry['lineupSlotId'] ?? PHP_INT_MAX);
        if ($lineupSlotId >= 20) {
            continue;
        }

        $player = $entry['playerPoolEntry']['player'] ?? [];
        $roster[] = [
            'lineupSlotId' => $lineupSlotId,
            'position' => $positions[$player['defaultPositionId'] ?? 0] ?? 'Unknown',
            'name' => $player['fullName'] ?? 'Unknown',
            'score' => $entry['playerPoolEntry']['appliedStatTotal'] ?? 0
        ];
    }
    usort($roster, function($a, $b) {
        return $a['lineupSlotId'] <=> $b['lineupSlotId'];
    });

    $teams[$t['id']] = [
        'name'  => $t['name'],
        'owner' => $members[$t['primaryOwner']] ?? 'Unknown',
        'wins'  => $t['record']['overall']['wins'],
        'losses' => $t['record']['overall']['losses'],
        'ties'  => $t['record']['overall']['ties'],
        'points' => $t['record']['overall']['pointsFor'],
        'rank'  => $t['playoffSeed'],
        'playoffPct' => $t['currentSimulationResults']['playoffPct'] ?? 0,
        'roster' => $roster
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
        .standings-table th, .standings-table td { padding: 12px 12px; text-align: left; }
        .standings-table th { background-color: #1a202c; color: #fff; text-transform: uppercase; font-size: 12px; }
        .standings-table .align-right { text-align: right; }
        .standings-table tr:nth-child(even) { background-color: #f8fafc; }
        .standings-table { table-layout: fixed; }
        .standings-table .team-cell { min-width: 0; }
        .team-cell { display: flex; align-items: center; gap: 10px; }
        .team-cell a { color: inherit; text-decoration: none; }
        
        .matchups-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .matchup-card { display: block; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-left: 4px solid #3182ce; color: inherit; text-decoration: none; }
        .matchup-team { display: flex; justify-content: space-between; align-items: center; margin: 10px 0; }
        .matchup-team-info { display: flex; flex: 1; flex-direction: column; min-width: 0; }
        .matchup-team-info > span,
        .matchup-team-info > small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .team-owner { color: #718096; font-size: 12px; font-weight: normal;}
        .matchup-team.winner { font-weight: bold; color: #2f855a; }
        .score { display: flex; flex-shrink: 0; flex-direction: column; align-items: flex-end; font-size: 16px; font-weight: 600; white-space: nowrap; }
        .projected-score { color: #718096; font-size: 12px; font-weight: normal; }
        .roster-toggle { display: block; margin: 0 auto; border: 0; background: transparent; color: #4a5568; cursor: pointer; font-size: 16px; padding: 4px 8px; }
        .roster-toggle[aria-expanded="true"] span[aria-hidden="true"] { display: inline-block; transform: rotate(180deg); }
        .roster-row td { padding: 0 15px 12px; }
        .roster-table { width: min(100%, 520px); margin: 0 auto; box-shadow: none; }
        .roster-table th,
        .roster-table td { padding-top: 8px; padding-bottom: 8px; }
        .roster-table tr:nth-child(even) { background-color: transparent; }
        .roster-table th { background-color: #4a5568; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

        @media (min-width: 601px) and (max-width: 900px), (max-width: 600px) {
            .standings-table th,
            .standings-table td { padding-left: 8px; padding-right: 8px; }
            .standings-table th:nth-child(1),
            .standings-table td:nth-child(1) { width: 6%; }
            .standings-table th:nth-child(2),
            .standings-table td:nth-child(2) { width: 28%; }
            .standings-table th:nth-child(3),
            .standings-table td:nth-child(3) { width: 23%; overflow-wrap: anywhere; }
            .standings-table th:nth-child(4),
            .standings-table td:nth-child(4) { width: 16%; padding-left: 4px; padding-right: 4px; }
            .standings-table th:nth-child(5),
            .standings-table td:nth-child(5) { width: 11%; padding-left: 4px; padding-right: 4px; }
            .standings-table th:nth-child(6),
            .standings-table td:nth-child(6) { width: 10%; padding-left: 4px; padding-right: 4px; }
            .standings-table th:nth-child(7),
            .standings-table td:nth-child(7) { width: 6%; padding-left: 4px; padding-right: 4px; }
            .standings-table td:nth-child(2) a { overflow-wrap: anywhere; }
        }

        @media (max-width: 600px) {
            body { padding: 12px; }
            .standings-table th:nth-child(6),
            .standings-table td:nth-child(6) { display: none; }
        }
    </style>
</head>
<body>

<div class="container">
    <h1>🏈 Fantasy Football Dashboard</h1>

    <!-- LEAGUE SELECTION TOGGLE -->
    <div class="league-toggle">
        <?php foreach ($leagues as $index => $league): ?>
            <a href="?league=<?php echo $index; ?>" 
               class="toggle-btn <?php echo ($selectedIdx === $index) ? 'active' : ''; ?>">
                <?php echo htmlspecialchars($league['name']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- SECTION 1: CURRENT WEEK SCORES -->
    <h2>📊 Week <?php echo $currentWeek; ?> Scoreboard</h2>
    <div class="matchups-grid">
        <?php foreach ($currentMatchups as $match): 
            $homeId = $match['home']['teamId'];
            $awayId = $match['away']['teamId'];
            
            $homeScore = $match['home']['pointsByScoringPeriod'][$currentWeek];
            $awayScore = $match['away']['pointsByScoringPeriod'][$currentWeek];
            $homeProjected = $match['home']['totalProjectedPointsLive'] ?? null;
            $awayProjected = $match['away']['totalProjectedPointsLive'] ?? null;
            $homeComparisonScore = $homeProjected ?? $homeScore;
            $awayComparisonScore = $awayProjected ?? $awayScore;
            
            // Use live projected points to determine the current leader.
            $homeWinning = $homeComparisonScore > $awayComparisonScore;
            $awayWinning = $awayComparisonScore > $homeComparisonScore;
        ?>
        <a class="matchup-card" href="https://fantasy.espn.com/football/fantasycast?leagueId=<?php echo urlencode($activeLeagueId); ?>&matchupPeriodId=<?php echo urlencode($currentWeek); ?>&seasonId=<?php echo urlencode($season); ?>&teamId=<?php echo urlencode($homeId); ?>" target="_blank" rel="noopener noreferrer">
            <!-- Away Team Row -->
            <div class="matchup-team <?php echo $awayWinning ? 'winner' : ''; ?>">
                <span class="matchup-team-info">
                    <span><?php echo htmlspecialchars($teams[$awayId]['name'] ?? 'Away Team'); ?></span>
                    <small class="team-owner"><?php echo htmlspecialchars($teams[$awayId]['owner'] ?? 'Unknown'); ?></small>
                </span>
                <span class="score">
                    <?php echo number_format($awayScore, 2); ?>
                    <?php if ($awayProjected !== null): ?>
                        <small class="projected-score">Proj <?php echo number_format($awayProjected, 2); ?></small>
                    <?php endif; ?>
                </span>
            </div>
                        
            <!-- Home Team Row -->
            <div class="matchup-team <?php echo $homeWinning ? 'winner' : ''; ?>">
                <span class="matchup-team-info">
                    <span><?php echo htmlspecialchars($teams[$homeId]['name'] ?? 'Home Team'); ?></span>
                    <small class="team-owner"><?php echo htmlspecialchars($teams[$homeId]['owner'] ?? 'Unknown'); ?></small>
                </span>
                <span class="score">
                    <?php echo number_format($homeScore, 2); ?>
                    <?php if ($homeProjected !== null): ?>
                        <small class="projected-score">Proj <?php echo number_format($homeProjected, 2); ?></small>
                    <?php endif; ?>
                </span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- SECTION 2: LEAGUE STANDINGS -->
    <h2>🏆 <?php echo htmlspecialchars($activeLeagueName); ?> Current Standings</h2>
    <table class="standings-table">
        <thead>
            <tr>
                <th style="width: 30px;">Seed</th>
                <th>Team</th>
                <th>Owner</th>
                <th class="align-right" style="width: 80px;">Record</th>
                <th class="align-right" style="width: 70px;">Points</th>
                <th class="align-right" style="width: 80px;">Playoff %</th>
                <th style="width: 50px;">Roster</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($teams as $id => $team): ?>
            <tr>
                <td><strong><?= $team['rank']; ?></strong></td>
                <td>
                    <div class="team-cell">
                        <a href="https://fantasy.espn.com/football/team?leagueId=<?php echo urlencode($activeLeagueId); ?>&teamId=<?php echo urlencode($id); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo htmlspecialchars($team['name']); ?>
                        </a>
                    </div>
                </td>
                <td><?php echo htmlspecialchars($team['owner']); ?></td>
                <td class="align-right"><?php echo "{$team['wins']}-{$team['losses']}-{$team['ties']}"; ?></td>
                <td class="align-right"><?php echo number_format($team['points'], 2); ?></td>
                <td class="align-right"><?php echo number_format($team['playoffPct'] * 100, 0); ?>%</td>
                <td>
                    <button class="roster-toggle" type="button" aria-expanded="false" aria-controls="roster-<?php echo (int)$id; ?>">
                        <span aria-hidden="true">&#9660;</span>
                        <span class="sr-only">Show roster</span>
                    </button>
                </td>
            </tr>
            <tr class="roster-row" id="roster-<?php echo (int)$id; ?>" hidden>
                <td colspan="7">
                    <table class="roster-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">Pos </th>
                                <th>Player</th>
                                <th style="width: 80px;">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team['roster'] as $player): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($player['position']); ?></td>
                                <td><?php echo htmlspecialchars($player['name']); ?></td>
                                <td><?php echo number_format($player['score'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
    document.querySelectorAll('.roster-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            const roster = document.getElementById(button.getAttribute('aria-controls'));
            const expanded = button.getAttribute('aria-expanded') === 'true';

            button.setAttribute('aria-expanded', String(!expanded));
            button.querySelector('.sr-only').textContent = expanded ? 'Show roster' : 'Hide roster';
            roster.hidden = expanded;
        });
    });
</script>

</body>
</html>