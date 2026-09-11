<?php

function getLeagueConfigs(): array
{
    return [
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
}

function loadLeagueData(string $leagueId, string $season = '2026', int $cacheTime = 300): array
{
    $cacheFile = __DIR__ . "/espn_fantasy_cache_{$leagueId}.json";
    $url = "https://lm-api-reads.fantasy.espn.com/apis/v3/games/ffl/seasons/{$season}/segments/0/leagues/{$leagueId}?view=mTeam&view=mStandings&view=mMatchup";
    $response = false;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $response = file_get_contents($cacheFile);
    }

    if ($response === false || $response === '') {
        $response = file_get_contents($url);

        if ($response !== false && $response !== '') {
            file_put_contents($cacheFile, $response);
        } elseif (file_exists($cacheFile)) {
            $response = file_get_contents($cacheFile);
        }
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        throw new RuntimeException("Unable to load valid ESPN data for league {$leagueId}.");
    }

    return $data;
}
