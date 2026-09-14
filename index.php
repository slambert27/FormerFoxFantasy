<?php
/**
 * ESPN Fantasy Football Dashboard
 */

require_once __DIR__ . '/espn_data.php';

// Support /stats when the local server routes unknown paths through index.php.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (is_string($requestPath) && preg_match('~/stats/?$~', $requestPath)) {
    require __DIR__ . '/stats.php';
    exit;
}

// 1. CONFIGURATION
$leagues = getLeagueConfigs();

// 2. DETERMINE SELECTED LEAGUE
// Get the league index from the URL (defaults to index 0 if not set or invalid)
$requestedLeague = $_GET['league'] ?? null;
$selectedIdx = array_search((string)$requestedLeague, array_column($leagues, 'id'), true);
if ($selectedIdx === false) {
    $selectedIdx = filter_var($requestedLeague, FILTER_VALIDATE_INT);
    if ($selectedIdx === false || !isset($leagues[$selectedIdx])) {
        $selectedIdx = 0;
    }
}

$activeLeagueId   = $leagues[$selectedIdx]['id'];
$activeLeagueName = $leagues[$selectedIdx]['name'];
$season = "2026";

$data = loadLeagueData($activeLeagueId, $season);

// Extract global status details
$currentWeek = $data['status']['currentMatchupPeriod'];
$currentScoringPeriod = (int)($data['scoringPeriodId'] ?? $currentWeek);
$selectedScoringPeriod = filter_var($_GET['scoring_period'] ?? $currentScoringPeriod, FILTER_VALIDATE_INT);
if ($selectedScoringPeriod === false || $selectedScoringPeriod < 1 || $selectedScoringPeriod > $currentScoringPeriod) {
    $selectedScoringPeriod = $currentScoringPeriod;
}

// 4. DATA PROCESSING
// Map Team IDs to their actual names so we can display them easily later

$members = [];
foreach ($data['members'] ?? [] as $member) {
    $members[$member['id']] = trim(($member['firstName'] ?? '') . ' ' . ($member['lastName'] ?? '')) ?: 'Unknown';
}

$lineupPositions = [
    0 => 'QB',
    2 => 'RB',
    4 => 'WR',
    6 => 'TE',
    16 => 'D/ST',
    17 => 'K',
    23 => 'FLEX'
];

$lineupOrder = [
    0 => 0,
    2 => 1,
    4 => 2,
    6 => 3,
    23 => 4,
    16 => 5,
    17 => 6
];

$teams = [];
foreach ($data['teams'] as $teamIndex => $t) {
    $roster = [];
    foreach ($t['roster']['entries'] ?? [] as $entry) {
        $lineupSlotId = (int)($entry['lineupSlotId'] ?? PHP_INT_MAX);
        if (isset($lineupOrder[$lineupSlotId])) {
            $player = $entry['playerPoolEntry']['player'] ?? [];
            $roster[] = [
                'lineupSlotId' => $lineupSlotId,
                'position' => $lineupPositions[$lineupSlotId],
                'name' => $player['fullName'] ?? 'Unknown',
                'score' => $entry['playerPoolEntry']['appliedStatTotal'] ?? 0
            ];
        }
    }
    usort($roster, function($a, $b) use ($lineupOrder) {
        return $lineupOrder[$a['lineupSlotId']] <=> $lineupOrder[$b['lineupSlotId']];
    });

    $teams[$t['id']] = [
        'name'  => $t['name'],
        'owner' => $members[$t['primaryOwner']] ?? 'Unknown',
        'wins'  => $t['record']['overall']['wins'],
        'losses' => $t['record']['overall']['losses'],
        'ties'  => $t['record']['overall']['ties'],
        'points' => $t['record']['overall']['pointsFor'],
        'rank'  => $teamIndex + 1,
        'playoffPct' => $t['currentSimulationResults']['playoffPct'] ?? 0,
        'roster' => $roster
    ];
}

// Sort the teams array by rank (Playoff Seed) for the standings table
// API provides teams in order matching ESPN site, playoffSeed is reversed from that order
// TODO: check this after week 1 results
// uasort($teams, function($a, $b) {
//     return $a['rank'] <=> $b['rank'];
// });

// Filter out only the matchups for the current active week
$currentMatchups = [];
if (!empty($data['schedule'])) {
    foreach ($data['schedule'] as $matchup) {
        if ($matchup['matchupPeriodId'] == $selectedScoringPeriod) {
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
    <title>Fantasy Superleague</title>
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
        .scoreboard-heading { align-items: baseline; border-bottom: 2px solid #ddd; display: flex; gap: 12px; justify-content: space-between; margin: 30px 0 20px; }
        .scoreboard-heading h2 { border-bottom: 0; flex: 1; margin: 0; }
        .scoring-period-selector { flex-shrink: 0; margin: 0 0 8px; }
        .scoring-period-selector select { border: 1px solid #cbd5e1; border-radius: 6px; color: #1a202c; font: inherit; padding: 6px 8px; }
        
        /* Layout Tables & Scoreboard Cards */
        table { width: 100%; border-collapse: collapse; background: #fff; margin-bottom: 40px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-radius: 6px; overflow: hidden; }
        .standings-table th, .standings-table td { padding: 12px 12px; text-align: left; }
        .standings-table th { background-color: #1a202c; color: #fff; text-transform: uppercase; font-size: 12px; }
        .standings-table .align-right { text-align: right; }
        .standings-table tr:nth-child(even) { background-color: #f8fafc; }
        .standings-table { width: 100%; table-layout: auto; }
        .standings-table .team-column,
        .standings-table .owner-column { width: 35%; white-space: wrap; }
        .standings-table th,
        .standings-table td { white-space: nowrap; }
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
        .matchup-team.winner.in-progress { color: #111; }
        .score { display: flex; flex-shrink: 0; flex-direction: column; align-items: flex-end; font-size: 16px; font-weight: 600; white-space: nowrap; }
        .projected-score { color: #718096; font-size: 12px; font-weight: normal; }
        .roster-toggle { display: block; margin: 0 auto; border: 0; background: transparent; color: #4a5568; cursor: pointer; font-size: 16px; padding: 4px 8px; }
        .roster-toggle span[aria-hidden="true"] { display: inline-block; transform: rotate(90deg); }
        .roster-toggle[aria-expanded="true"] span[aria-hidden="true"] { transform: rotate(270deg); }
        .roster-row td { padding: 12px 15px; }
        .roster-table { width: min(100%, 520px); margin: 0 auto; box-shadow: none; }
        .roster-table th,
        .roster-table td { padding-top: 8px; padding-bottom: 8px; }
        .roster-position { color: #718096; font-size: 12px; }
        .roster-table tr:nth-child(even) { background-color: transparent; }
        .roster-table th { background-color: #4a5568; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

        @media (min-width: 601px) and (max-width: 900px), (max-width: 600px) {
            .standings-table th,
            .standings-table td { padding-left: 4px; padding-right: 4px; }
            .standings-table th:nth-child(3),
            .standings-table td:nth-child(3) { overflow-wrap: anywhere; }
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
    <h1>🏈 Fantasy Football Superleague</h1>

    <!-- LEAGUE SELECTION TOGGLE -->
    <div class="league-toggle">
        <?php foreach ($leagues as $index => $league): ?>
            <a href="?league=<?php echo urlencode($league['id']); ?>" 
               class="toggle-btn <?php echo ($selectedIdx === $index) ? 'active' : ''; ?>">
                <?php echo htmlspecialchars($league['name']); ?>
            </a>
        <?php endforeach; ?>
        <a href="stats" class="toggle-btn">Stats</a>
    </div>

    <!-- SECTION 1: SELECTED WEEK SCORES -->
    <div class="scoreboard-heading">
        <h2>🏟️ Week <?php echo $selectedScoringPeriod; ?> Scoreboard</h2>
        <form class="scoring-period-selector" method="get">
            <input type="hidden" name="league" value="<?php echo htmlspecialchars($activeLeagueId); ?>">
            <select id="scoring-period" name="scoring_period" aria-label="Select scoreboard week" onchange="this.form.submit()">
                <?php for ($scoringPeriod = 1; $scoringPeriod <= $currentScoringPeriod; $scoringPeriod++): ?>
                    <option value="<?php echo $scoringPeriod; ?>"<?php echo $scoringPeriod === $selectedScoringPeriod ? ' selected' : ''; ?>>
                        Week <?php echo $scoringPeriod; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </form>
    </div>
    <div class="matchups-grid">
        <?php foreach ($currentMatchups as $match): 
            $homeId = $match['home']['teamId'];
            $awayId = $match['away']['teamId'];
            
            $homeScore = (float)($match['home']['pointsByScoringPeriod'][$selectedScoringPeriod] ?? 0);
            $awayScore = (float)($match['away']['pointsByScoringPeriod'][$selectedScoringPeriod] ?? 0);
            $homeProjected = $selectedScoringPeriod === $currentScoringPeriod ? ($match['home']['totalProjectedPointsLive'] ?? null) : null;
            $awayProjected = $selectedScoringPeriod === $currentScoringPeriod ? ($match['away']['totalProjectedPointsLive'] ?? null) : null;
            $matchupFinal = ($match['winner'] ?? 'UNDECIDED') !== 'UNDECIDED';
            $homeComparisonScore = $matchupFinal ? $homeScore : ($homeProjected ?? $homeScore);
            $awayComparisonScore = $matchupFinal ? $awayScore : ($awayProjected ?? $awayScore);
            
            // Use actual points for final games and projected points for games in progress.
            $homeWinning = $homeComparisonScore > $awayComparisonScore;
            $awayWinning = $awayComparisonScore > $homeComparisonScore;
        ?>
        <a class="matchup-card" href="https://fantasy.espn.com/football/fantasycast?leagueId=<?php echo urlencode($activeLeagueId); ?>&matchupPeriodId=<?php echo urlencode($selectedScoringPeriod); ?>&seasonId=<?php echo urlencode($season); ?>&teamId=<?php echo urlencode($homeId); ?>" target="_blank" rel="noopener noreferrer">
            <!-- Away Team Row -->
            <div class="matchup-team <?php echo $awayWinning ? 'winner' . (!$matchupFinal ? ' in-progress' : '') : ''; ?>">
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
            <div class="matchup-team <?php echo $homeWinning ? 'winner' . (!$matchupFinal ? ' in-progress' : '') : ''; ?>">
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
                <th>Seed</th>
                <th class="team-column">Team</th>
                <th class="owner-column">Owner</th>
                <th class="align-right">Record</th>
                <th class="align-right">Points</th>
                <th class="align-right">Playoff%</th>
                <th>Roster</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($teams as $id => $team): ?>
            <tr>
                <td><strong><?= $team['rank']; ?></strong></td>
                <td class="team-column">
                    <div class="team-cell">
                        <a href="https://fantasy.espn.com/football/team?leagueId=<?php echo urlencode($activeLeagueId); ?>&teamId=<?php echo urlencode($id); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo htmlspecialchars($team['name']); ?>
                        </a>
                    </div>
                </td>
                <td class="owner-column"><?php echo htmlspecialchars($team['owner']); ?></td>
                <td class="align-right"><?php echo "{$team['wins']}-{$team['losses']}-{$team['ties']}"; ?></td>
                <td class="align-right"><?php echo number_format($team['points'], 2); ?></td>
                <td class="align-right"><?php echo number_format($team['playoffPct'] * 100, 0); ?>%</td>
                <td>
                    <button class="roster-toggle" type="button" aria-expanded="false" aria-controls="roster-<?php echo (int)$id; ?>">
                        <span aria-hidden="true">&#10095;</span>
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
                                <th class="align-right" style="width: 80px;">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team['roster'] as $player): ?>
                            <tr>
                                <td class="roster-position"><?php echo htmlspecialchars($player['position']); ?></td>
                                <td><?php echo htmlspecialchars($player['name']); ?></td>
                                <td class="align-right"><?php echo number_format($player['score'], 2); ?></td>
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