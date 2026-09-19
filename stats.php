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
$selectedScoringPeriod = filter_var($_GET['scoring_period'] ?? $currentWeek, FILTER_VALIDATE_INT);
if ($selectedScoringPeriod === false || $selectedScoringPeriod < 1 || $selectedScoringPeriod > $currentWeek) {
    $selectedScoringPeriod = $currentWeek;
}
if ($selectedScoringPeriod !== $currentWeek || array_key_exists('scoring_period', $_GET)) {
    foreach ($leagueData as $leagueId => $league) {
        $leagueData[$leagueId]['data'] = loadLeagueData($leagueId, $season, 300, $selectedScoringPeriod);
    }
}

$currentWeekScores = [];
$currentWeekMargins = [];
$currentWeekTopPlayer = null;
$currentWeekTopPlayerOwners = [];
$allCurrentWeekGamesFinal = true;
foreach ($leagueData as $league) {
    $data = $league['data'];
    $week = $selectedScoringPeriod;
    $members = [];
    $teams = [];

    foreach ($data['members'] ?? [] as $member) {
        $members[$member['id']] = trim(($member['firstName'] ?? '') . ' ' . ($member['lastName'] ?? '')) ?: 'Unknown';
    }

    foreach ($data['teams'] ?? [] as $team) {
        $teams[$team['id']] = [
            'name' => $team['name'] ?? 'Unknown Team',
            'owner' => $members[$team['primaryOwner'] ?? ''] ?? 'Unknown Owner'
        ];
    }

    foreach ($data['schedule'] ?? [] as $matchup) {
        if (($matchup['matchupPeriodId'] ?? null) != $week) {
            continue;
        }

        $matchupFinal = ($matchup['winner'] ?? 'UNDECIDED') != 'UNDECIDED';

        if (!$matchupFinal) {
            $allCurrentWeekGamesFinal = false;
        }

        $homeId = $matchup['home']['teamId'] ?? null;
        $awayId = $matchup['away']['teamId'] ?? null;

        $homeProjectedScore = (float)($matchup['home']['totalProjectedPointsLive'] ?? 0);
        $awayProjectedScore = (float)($matchup['away']['totalProjectedPointsLive'] ?? 0);

        $homeScore = $matchupFinal ? (float)($matchup['home']['pointsByScoringPeriod'][$week] ?? 0) : $homeProjectedScore;
        $awayScore = $matchupFinal ? (float)($matchup['away']['pointsByScoringPeriod'][$week] ?? 0) : $awayProjectedScore;


        foreach ([
            ['teamId' => $homeId, 'entries' => $matchup['home']['rosterForMatchupPeriod']['entries'] ?? []],
            ['teamId' => $awayId, 'entries' => $matchup['away']['rosterForMatchupPeriod']['entries'] ?? []]
        ] as $side) {
            $teamId = $side['teamId'];
            if ($teamId === null || !isset($teams[$teamId])) {
                continue;
            }

            foreach ($side['entries'] as $entry) {
                $playerPoolEntry = $entry['playerPoolEntry'] ?? [];
                $player = $playerPoolEntry['player'] ?? [];
                $playerScore = $playerPoolEntry['appliedStatTotal'] ?? null;

                if (($playerPoolEntry['onTeamId'] ?? null) != $teamId || $playerScore === null || empty($player['fullName'])) {
                    continue;
                }

                $playerScore = (float)$playerScore;
                $leagueId = $league['config']['id'];
                $gameResult = $teamId === $homeId
                    ? ($homeScore > $awayScore ? 'W' : ($homeScore < $awayScore ? 'L' : 'T'))
                    : ($awayScore > $homeScore ? 'W' : ($awayScore < $homeScore ? 'L' : 'T'));
                if ($currentWeekTopPlayer === null || $playerScore > $currentWeekTopPlayer['score']) {
                    $currentWeekTopPlayer = [
                        'player' => $player['fullName'],
                        'score' => $playerScore
                    ];
                    $currentWeekTopPlayerOwners = [$leagueId => [
                        'owner' => $teams[$teamId]['owner'],
                        'result' => $gameResult
                    ]];
                } elseif ($playerScore === $currentWeekTopPlayer['score'] && !isset($currentWeekTopPlayerOwners[$leagueId])) {
                    $currentWeekTopPlayerOwners[$leagueId] = [
                        'owner' => $teams[$teamId]['owner'],
                        'result' => $gameResult
                    ];
                }
            }
        }

        if ($homeId !== null && $awayId !== null) {
            if ($homeScore !== $awayScore) {
                $homeWon = $homeScore > $awayScore;
                $currentWeekMargins[] = [
                    'league' => $league['config']['name'],
                    'winner' => $homeWon ? ($teams[$homeId]['name'] ?? 'Unknown Team') : ($teams[$awayId]['name'] ?? 'Unknown Team'),
                    'winnerOwner' => $homeWon ? ($teams[$homeId]['owner'] ?? 'Unknown Owner') : ($teams[$awayId]['owner'] ?? 'Unknown Owner'),
                    'winnerScore' => $homeWon ? $homeScore : $awayScore,
                    'loser' => $homeWon ? ($teams[$awayId]['name'] ?? 'Unknown Team') : ($teams[$homeId]['name'] ?? 'Unknown Team'),
                    'loserOwner' => $homeWon ? ($teams[$awayId]['owner'] ?? 'Unknown Owner') : ($teams[$homeId]['owner'] ?? 'Unknown Owner'),
                    'loserScore' => $homeWon ? $awayScore : $homeScore,
                    'margin' => abs($homeScore - $awayScore)
                ];
            }

            $currentWeekScores[] = [
                'league' => $league['config']['name'],
                'team' => $teams[$homeId]['name'] ?? 'Unknown Team',
                'owner' => $teams[$homeId]['owner'] ?? 'Unknown Owner',
                'score' => $homeScore,
                'opponent' => $teams[$awayId]['name'] ?? 'Unknown Team',
                'opponentOwner' => $teams[$awayId]['owner'] ?? 'Unknown Owner',
                'opponentScore' => $awayScore
            ];
            $currentWeekScores[] = [
                'league' => $league['config']['name'],
                'team' => $teams[$awayId]['name'] ?? 'Unknown Team',
                'owner' => $teams[$awayId]['owner'] ?? 'Unknown Owner',
                'score' => $awayScore,
                'opponent' => $teams[$homeId]['name'] ?? 'Unknown Team',
                'opponentOwner' => $teams[$homeId]['owner'] ?? 'Unknown Owner',
                'opponentScore' => $homeScore
            ];
        }
    }
}

$highestWeekScore = $currentWeekScores ? max(array_column($currentWeekScores, 'score')) : null;
$lowestWeekScore = $currentWeekScores ? min(array_column($currentWeekScores, 'score')) : null;
$highestWeekTeam = $highestWeekScore !== null
    ? current(array_filter($currentWeekScores, fn($entry) => $entry['score'] === $highestWeekScore))
    : null;
$lowestWeekTeam = $lowestWeekScore !== null
    ? current(array_filter($currentWeekScores, fn($entry) => $entry['score'] === $lowestWeekScore))
    : null;
$largestVictory = $currentWeekMargins ? max(array_column($currentWeekMargins, 'margin')) : null;
$smallestDefeat = $currentWeekMargins ? min(array_column($currentWeekMargins, 'margin')) : null;
$largestVictoryMatchup = $largestVictory !== null
    ? current(array_filter($currentWeekMargins, fn($entry) => $entry['margin'] === $largestVictory))
    : null;
$smallestDefeatMatchup = $smallestDefeat !== null
    ? current(array_filter($currentWeekMargins, fn($entry) => $entry['margin'] === $smallestDefeat))
    : null;
$mostPointsInLoss = $currentWeekMargins ? max(array_column($currentWeekMargins, 'loserScore')) : null;
$mostPointsInLossMatchup = $mostPointsInLoss !== null
    ? current(array_filter($currentWeekMargins, fn($entry) => $entry['loserScore'] === $mostPointsInLoss))
    : null;
$fewestPointsInWin = $currentWeekMargins ? min(array_column($currentWeekMargins, 'winnerScore')) : null;
$fewestPointsInWinMatchup = $fewestPointsInWin !== null
    ? current(array_filter($currentWeekMargins, fn($entry) => $entry['winnerScore'] === $fewestPointsInWin))
    : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fantasy Superleague</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f7f9fa; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { color: #111; margin-bottom: 5px; }
        h2 { color: #111; border-bottom: 2px solid #ddd; padding-bottom: 8px; margin-top: 30px; }
        .league-toggle { display: flex; flex-wrap: wrap; gap: 10px; margin: 20px 0 30px 0; background: #e2e8f0; padding: 6px; border-radius: 8px; width: fit-content; }
        .toggle-btn { text-decoration: none; padding: 8px 16px; border-radius: 6px; color: #4a5568; font-weight: 500; font-size: 14px; transition: all 0.2s; }
        .toggle-btn:hover { background: #cbd5e1; }
        .toggle-btn.active { background: #fff; color: #1a202c; box-shadow: 0 2px 4px rgba(0,0,0,0.06); font-weight: 600; }
        .stats-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 28px; }
        .stats-column h2 { margin-top: 0; }
        .scoreboard-heading { align-items: baseline; border-bottom: 2px solid #ddd; display: flex; gap: 12px; justify-content: space-between; margin: 30px 0 20px; }
        .stats-column .scoreboard-heading { margin-top: 0; }
        .scoreboard-heading h2 { border-bottom: 0; flex: 1; margin: 0; }
        .scoreboard-heading form { flex-shrink: 0; margin: 0 0 8px; }
        .scoreboard-heading select { border: 1px solid #cbd5e1; border-radius: 6px; color: #1a202c; font-family: inherit; font-size: 15px; font-weight: 600; padding: 10px 14px; }
        .games-in-progress { color: #718096; font-size: 12px; margin: -12px 0 12px; }
        .stat-list { display: grid; gap: 12px; }
        .stat-card { background: #fff; border-left: 4px solid #3182ce; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); padding: 18px; }
        .current-week-in-progress .stat-card .stat-primary .stat-score { color: black; }
        .stat-heading { align-items: baseline; display: flex; gap: 12px; justify-content: space-between; }
        .stat-card h3 { color: #1a202c; font-size: 16px; margin: 0 0 8px; }
        .stat-league { color: #718096; flex-shrink: 0; font-size: 14px; margin-bottom: 8px; }
        .stat-card p { color: #718096; margin: 0; }
        .stat-primary { align-items: baseline; display: flex; gap: 12px; justify-content: space-between; }
        .stat-owner { color: #1a202c; font-size: 18px; font-weight: 600; }
        .stat-score { color: #2f855a; font-size: 18px; font-weight: 700; }
        .stat-team { color: #718096; font-size: 13px; margin-top: 3px; padding-bottom: 3px; }
        .stat-footnote { border-top: 1px solid #edf2f7; color: #718096; font-size: 12px; margin-top: 0px; padding-top: 10px; }
        .player-league-name { color: #718096; font-size: 12px; font-weight: 600; margin: 0 0 6px; }
        .player-team-row { color: #718096; font-size: 13px; margin-top: 10px; padding-top: 10px; }
        .player-result.w { color: #2f855a; font-weight: 700; }
        .player-result.l { color: #c53030; font-weight: 700; }
        .margin-score { color: #2f855a; font-weight: 700; }
        .margin-matchup-list { border-top: 1px solid #edf2f7; margin-top: 0px; padding-top: 10px; }
        .stat-score.negative,
        .margin-score.negative { color: #c53030; }
        .matchup-row { color: #718096; display: flex; font-size: 12px; justify-content: space-between; padding-top: 5px; }
        @media (max-width: 700px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            body { padding: 12px; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🏈 Fantasy Football Superleague</h1>

    <nav class="league-toggle" aria-label="Site navigation">
        <?php foreach ($leagues as $league): ?>
            <a href="index.php?league=<?php echo urlencode($league['id']); ?>&scoring_period=<?php echo urlencode((string)$selectedScoringPeriod); ?>" class="toggle-btn">
                <?php echo htmlspecialchars($league['name']); ?>
            </a>
        <?php endforeach; ?>
        <a href="stats" class="toggle-btn active" aria-current="page">Stats</a>
    </nav>

    <div class="stats-grid">
        <section class="stats-column<?php echo !$allCurrentWeekGamesFinal ? ' current-week-in-progress' : ''; ?>">
            <div class="scoreboard-heading">
                <h2>🥇 <?php echo $selectedScoringPeriod !== null ? 'Week ' . htmlspecialchars((string)$selectedScoringPeriod) : 'Current Week'; ?> Superlatives</h2>
                <form method="get">
                    <select name="scoring_period" aria-label="Select stats week" onchange="this.form.submit()">
                        <?php for ($scoringPeriod = 1; $scoringPeriod <= $currentWeek; $scoringPeriod++): ?>
                            <option value="<?php echo $scoringPeriod; ?>"<?php echo $scoringPeriod === $selectedScoringPeriod ? ' selected' : ''; ?>>
                                Week <?php echo $scoringPeriod; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>
            <?php if (!$allCurrentWeekGamesFinal && $highestWeekTeam && $highestWeekTeam['score'] > 0): ?>
                <p class="games-in-progress">Games in progress - Projected Scores Shown</p>
            <?php endif; ?>
            <div class="stat-list">
                <article class="stat-card">
                    <div class="stat-heading">
                        <h3>Highest Score</h3>
                        <?php if ($highestWeekTeam && $highestWeekTeam['score'] > 0): ?>
                            <span class="stat-league"><?php echo htmlspecialchars($highestWeekTeam['league']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($highestWeekTeam && $highestWeekTeam['score'] > 0): ?>
                        <div class="stat-primary">
                            <span class="stat-owner"><?php echo htmlspecialchars($highestWeekTeam['owner']); ?></span>
                            <span class="stat-score"><?php echo number_format($highestWeekTeam['score'], 2); ?></span>
                        </div>
                        <p class="stat-team"><?php echo htmlspecialchars($highestWeekTeam['team']); ?></p>
                        <p class="stat-footnote">vs. <?php echo htmlspecialchars($highestWeekTeam['opponentOwner']); ?>, <?php echo htmlspecialchars($highestWeekTeam['opponent']); ?> - <?php echo number_format($highestWeekTeam['opponentScore'], 2); ?></p>
                    <?php else: ?>
                        <p>Unavailable</p>
                    <?php endif; ?>
                </article>
                <article class="stat-card">
                    <div class="stat-heading">
                        <h3>Largest Margin of Victory</h3>
                        <?php if ($largestVictoryMatchup): ?>
                            <span class="stat-league"><?php echo htmlspecialchars($largestVictoryMatchup['league']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($largestVictoryMatchup): ?>
                        <div class="stat-primary">
                            <span class="stat-owner"><?php echo htmlspecialchars($largestVictoryMatchup['winnerOwner']); ?></span>
                            <span class="stat-score"><?php echo number_format($largestVictoryMatchup['margin'], 2); ?></span>
                        </div>
                        <p class="stat-team"><?php echo htmlspecialchars($largestVictoryMatchup['winner']); ?></p>
                        <div class="margin-matchup-list">
                            <div class="matchup-row"><span><?php echo htmlspecialchars($largestVictoryMatchup['winner']); ?></span><span><?php echo number_format($largestVictoryMatchup['winnerScore'], 2); ?></span></div>
                            <div class="matchup-row"><span><?php echo htmlspecialchars($largestVictoryMatchup['loser']); ?></span><span><?php echo number_format($largestVictoryMatchup['loserScore'], 2); ?></span></div>
                        </div>
                    <?php else: ?>
                        <p>Unavailable</p>
                    <?php endif; ?>
                </article>
                <article class="stat-card">
                    <div class="stat-heading">
                        <h3>Fewest Points in Victory</h3>
                        <?php if ($fewestPointsInWinMatchup): ?>
                            <span class="stat-league"><?php echo htmlspecialchars($fewestPointsInWinMatchup['league']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($fewestPointsInWinMatchup): ?>
                        <div class="stat-primary">
                            <span class="stat-owner"><?php echo htmlspecialchars($fewestPointsInWinMatchup['winnerOwner']); ?></span>
                            <span class="stat-score"><?php echo number_format($fewestPointsInWinMatchup['winnerScore'], 2); ?></span>
                        </div>
                        <p class="stat-team"><?php echo htmlspecialchars($fewestPointsInWinMatchup['winner']); ?></p>
                        <p class="stat-footnote">vs. <?php echo htmlspecialchars($fewestPointsInWinMatchup['loserOwner']); ?>, <?php echo htmlspecialchars($fewestPointsInWinMatchup['loser']); ?> - <?php echo number_format($fewestPointsInWinMatchup['loserScore'], 2); ?></p>
                    <?php else: ?>
                        <p>Unavailable</p>
                    <?php endif; ?>
                </article>
                <article class="stat-card">
                    <div class="stat-heading">
                        <h3>Most Points in Defeat</h3>
                        <?php if ($mostPointsInLossMatchup): ?>
                            <span class="stat-league"><?php echo htmlspecialchars($mostPointsInLossMatchup['league']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($mostPointsInLossMatchup): ?>
                        <div class="stat-primary">
                            <span class="stat-owner"><?php echo htmlspecialchars($mostPointsInLossMatchup['loserOwner']); ?></span>
                            <span class="stat-score negative"><?php echo number_format($mostPointsInLossMatchup['loserScore'], 2); ?></span>
                        </div>
                        <p class="stat-team"><?php echo htmlspecialchars($mostPointsInLossMatchup['loser']); ?></p>
                        <p class="stat-footnote">vs. <?php echo htmlspecialchars($mostPointsInLossMatchup['winnerOwner']); ?>, <?php echo htmlspecialchars($mostPointsInLossMatchup['winner']); ?> - <?php echo number_format($mostPointsInLossMatchup['winnerScore'], 2); ?></p>
                    <?php else: ?>
                        <p>Unavailable</p>
                    <?php endif; ?>
                </article>
                <article class="stat-card">
                    <div class="stat-heading">
                        <h3>Smallest Margin of Defeat</h3>
                        <?php if ($smallestDefeatMatchup): ?>
                            <span class="stat-league"><?php echo htmlspecialchars($smallestDefeatMatchup['league']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($smallestDefeatMatchup): ?>
                        <div class="stat-primary">
                            <span class="stat-owner"><?php echo htmlspecialchars($smallestDefeatMatchup['loserOwner']); ?></span>
                            <span class="stat-score negative"><?php echo number_format($smallestDefeatMatchup['margin'], 2); ?></span>
                        </div>
                        <p class="stat-team"><?php echo htmlspecialchars($smallestDefeatMatchup['loser']); ?></p>
                        <div class="margin-matchup-list">
                            <div class="matchup-row"><span><?php echo htmlspecialchars($smallestDefeatMatchup['winner']); ?></span><span><?php echo number_format($smallestDefeatMatchup['winnerScore'], 2); ?></span></div>
                            <div class="matchup-row"><span><?php echo htmlspecialchars($smallestDefeatMatchup['loser']); ?></span><span><?php echo number_format($smallestDefeatMatchup['loserScore'], 2); ?></span></div>
                        </div>
                    <?php else: ?>
                        <p>Unavailable</p>
                    <?php endif; ?>
                </article>
                <article class="stat-card">
                    <div class="stat-heading">
                        <h3>Lowest Score</h3>
                        <?php if ($lowestWeekTeam): ?>
                            <span class="stat-league"><?php echo htmlspecialchars($lowestWeekTeam['league']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($lowestWeekTeam): ?>
                        <div class="stat-primary">
                            <span class="stat-owner"><?php echo htmlspecialchars($lowestWeekTeam['owner']); ?></span>
                            <span class="stat-score negative"><?php echo number_format($lowestWeekTeam['score'], 2); ?></span>
                        </div>
                        <p class="stat-team"><?php echo htmlspecialchars($lowestWeekTeam['team']); ?></p>
                        <p class="stat-footnote">vs. <?php echo htmlspecialchars($lowestWeekTeam['opponentOwner']); ?>, <?php echo htmlspecialchars($lowestWeekTeam['opponent']); ?> - <?php echo number_format($lowestWeekTeam['opponentScore'], 2); ?></p>
                    <?php else: ?>
                        <p>Unavailable</p>
                    <?php endif; ?>
                </article>
                <article class="stat-card">
                    <h3>Highest Scoring Player</h3>
                    <?php if ($currentWeekTopPlayer): ?>
                        <div class="stat-primary">
                            <span class="stat-owner"><?php echo htmlspecialchars($currentWeekTopPlayer['player']); ?></span>
                            <span class="stat-score"><?php echo number_format($currentWeekTopPlayer['score'], 2); ?></span>
                        </div>
                        <?php foreach ($leagues as $league): ?>
                            <?php $playerTeam = $currentWeekTopPlayerOwners[$league['id']] ?? null; ?>
                            <p class="player-team-row"><?php echo htmlspecialchars($league['name']); ?>: <?php echo htmlspecialchars($playerTeam['owner'] ?? 'Free Agent'); ?><?php if ($playerTeam && $allCurrentWeekGamesFinal): ?> <span class="player-result <?php echo strtolower($playerTeam['result']); ?>"><?php echo htmlspecialchars($playerTeam['result']); ?></span><?php endif; ?></p>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Unavailable</p>
                    <?php endif; ?>
                </article>
            </div>
        </section>

        <section class="stats-column">
            <h2>📈 Season Leaders</h2>
            <div class="stat-list">
                <article class="stat-card">
                    <h3>Coming Soon</h3>
                    <p>Check back later in the season</p>
                </article>
            </div>
        </section>
    </div>
</div>
</body>
</html>