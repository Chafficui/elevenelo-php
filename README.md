# elevenelo (PHP)

[![Packagist](https://img.shields.io/packagist/v/chafficui/elevenelo)](https://packagist.org/packages/chafficui/elevenelo)
[![PHP](https://img.shields.io/packagist/php-v/chafficui/elevenelo)](https://packagist.org/packages/chafficui/elevenelo)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

PHP client for the **[11elo](https://11elo.com) Soccer ELO API** — live and historical Elo ratings for German football (Bundesliga, 2. Bundesliga, 3. Liga).

Requires PHP 8.1+ and the built-in `curl` and `json` extensions (enabled by default).

## Installation

```bash
composer require chafficui/elevenelo
```

## Quick start

```php
<?php

require 'vendor/autoload.php';

$client = new \ElevenElo\Client('11e_fre_your_key_here');

// List all teams
$teams = $client->getTeams();
foreach ($teams as $team) {
    echo $team['teamName'] . ' ' . $team['currentElo'] . PHP_EOL;
}

// Get detailed info for one team
$team = $client->getTeam('Bayern München');
echo $team['team']['currentElo'] . PHP_EOL;

// Upcoming matches
$upcoming = $client->getUpcomingMatches(league: 'BL1', limit: 10);
foreach ($upcoming as $match) {
    echo $match['homeTeam'] . ' vs ' . $match['awayTeam'] . PHP_EOL;
}
```

## Getting an API key

Register for free at <https://11elo.com/developer>.  
Keys follow the format `11e_<tier>_<hex>` and are sent via the `X-API-Key` request header (handled automatically by this client).

**Rate limits by tier:**

| Tier  | Requests / day |
|-------|---------------|
| Free  | 100           |
| Basic | 1,000         |
| Pro   | 10,000        |

## API reference

### `new Client(apiKey, baseUrl, timeout)`

| Parameter  | Default               | Description                                    |
|------------|-----------------------|------------------------------------------------|
| `$apiKey`  | —                     | Your 11elo API key (**required**)              |
| `$baseUrl` | `https://11elo.com`   | Override for self-hosted / local dev           |
| `$timeout` | `30`                  | HTTP request timeout in seconds                |

---

### Teams

#### `getTeams(): array`

Returns all teams with current ELO stats, league info and trend data.

```php
$teams = $client->getTeams();
// [['teamName' => 'Bayern München', 'currentElo' => 1847, 'league' => 'BL1', ...], ...]
```

#### `getTeam(string $teamName): array`

Full detail for one team — ELO history, recent form, upcoming matches, career stats.

```php
$team = $client->getTeam('Borussia Dortmund');
// ['team' => [...], 'eloHistory' => [...], 'recentForm' => [...], 'upcomingMatches' => [...]]
```

#### `getHeadToHead(string $team1, string $team2): array`

Historical head-to-head match results between two teams.

```php
$h2h = $client->getHeadToHead('Bayern München', 'Borussia Dortmund');
// [['date' => '2026-02-15', 'result' => '2:1', 'winner' => 'Bayern München', ...], ...]
```

---

### Matches

#### `getMatches(?string $season, ?string $fromDate, ?string $toDate, ?int $limit, ?int $offset): array`

Paginated match history.  All parameters are optional.

```php
$matches = $client->getMatches(season: '2024/2025', limit: 50);
// [['homeTeam' => 'Bayern München', 'awayTeam' => 'BVB', 'homeElo' => 1835, ...], ...]
```

#### `getUpcomingMatches(?string $league, ?string $sort, ?int $limit): array`

Upcoming fixtures with ELO-difference predictions.

```php
$upcoming = $client->getUpcomingMatches(league: 'BL1', limit: 20);
// [['homeTeam' => '...', 'awayTeam' => '...', 'eloDiff' => 40, ...], ...]
```

#### `getMatch(int|string $matchId): array`

Full detail for a single match.

```php
$match = $client->getMatch(12345);
// ['match' => [...], 'homeRecentForm' => [...], 'awayRecentForm' => [...], ...]
```

---

### Seasons

#### `getSeasons(): array`

List all available seasons and the most recent one.

```php
$data = $client->getSeasons();
// ['seasons' => ['2025/2026', '2024/2025', ...], 'latestSeason' => '2025/2026']
```

#### `getSeason(string $season, ?string $league): array`

Per-team ELO change statistics for a given season.

```php
$entries = $client->getSeason('2024/2025', league: 'BL1');
// [['teamName' => 'Bayern München', 'startElo' => 1820, 'endElo' => 1847, 'change' => 27, ...], ...]
```

---

### Comparison

#### `getComparisonHistory(array $teams): array`

Time-series ELO data for multiple teams in one call.  `$teams` must contain at least two names.

```php
$history = $client->getComparisonHistory(['Bayern München', 'Borussia Dortmund']);
// [
//   'Bayern München'    => [['Date' => 1709856000000, 'ELO' => 1847], ...],
//   'Borussia Dortmund' => [['Date' => 1709856000000, 'ELO' => 1720], ...],
// ]
```

---

## Error handling

All exceptions extend `\ElevenElo\Exception\ElevenEloException`.

| Exception                | When thrown                                      |
|--------------------------|--------------------------------------------------|
| `AuthenticationException`| API key is missing or invalid (HTTP 401)         |
| `RateLimitException`     | Daily quota exceeded (HTTP 429)                  |
| `NotFoundException`      | Resource does not exist (HTTP 404)               |
| `ApiException`           | Any other non-2xx response; `$statusCode` is set |

```php
use ElevenElo\Client;
use ElevenElo\Exception\AuthenticationException;
use ElevenElo\Exception\NotFoundException;
use ElevenElo\Exception\RateLimitException;

$client = new Client('11e_fre_your_key_here');

try {
    $team = $client->getTeam('Unknown FC');
} catch (NotFoundException $e) {
    echo 'Team not found' . PHP_EOL;
} catch (RateLimitException $e) {
    echo 'Rate limit hit, resets at ' . $e->resetAt . PHP_EOL;
} catch (AuthenticationException $e) {
    echo 'Bad API key' . PHP_EOL;
}
```

## License

MIT
