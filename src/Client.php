<?php

declare(strict_types=1);

namespace ElevenElo;

use ElevenElo\Exception\ApiException;
use ElevenElo\Exception\AuthenticationException;
use ElevenElo\Exception\ElevenEloException;
use ElevenElo\Exception\NotFoundException;
use ElevenElo\Exception\RateLimitException;

/**
 * PHP client for the 11elo Soccer ELO API.
 *
 * Basic usage:
 *
 * ```php
 * $client = new \ElevenElo\Client('11e_fre_your_key_here');
 *
 * $teams = $client->getTeams();
 * foreach ($teams as $team) {
 *     echo $team['teamName'] . ' ' . $team['currentElo'] . PHP_EOL;
 * }
 * ```
 */
class Client
{
    private const DEFAULT_BASE_URL = 'https://11elo.com';
    private const DEFAULT_TIMEOUT  = 30;

    private string $baseUrl;
    private int    $timeout;

    /**
     * Optional transport override for testing.
     * Signature: callable(string $url, string $apiKey): array{statusCode: int, headers: string, body: string}
     *
     * @var callable|null
     */
    private $transport;

    /**
     * @param string        $apiKey    Your 11elo API key (format ``11e_<tier>_<hex>``).
     * @param string        $baseUrl   Override the default API base URL.
     * @param int           $timeout   Request timeout in seconds (default: 30).
     * @param callable|null $transport Optional HTTP transport callable (for testing).
     *
     * @throws \InvalidArgumentException if $apiKey is empty.
     */
    public function __construct(
        private readonly string $apiKey,
        string   $baseUrl   = self::DEFAULT_BASE_URL,
        int      $timeout   = self::DEFAULT_TIMEOUT,
        callable $transport = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey must not be empty');
        }
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->timeout   = $timeout;
        $this->transport = $transport;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Perform a GET request and return the decoded JSON body.
     *
     * @param  array<string, scalar|null> $params Optional query parameters (null values are omitted).
     * @return mixed
     *
     * @throws AuthenticationException
     * @throws RateLimitException
     * @throws NotFoundException
     * @throws ApiException
     * @throws ElevenEloException
     */
    private function get(string $path, array $params = []): mixed
    {
        $filteredParams = array_filter($params, static fn ($v) => $v !== null);
        $url = $this->baseUrl . $path;
        if ($filteredParams !== []) {
            $url .= '?' . http_build_query($filteredParams);
        }

        if ($this->transport !== null) {
            $response   = ($this->transport)($url, $this->apiKey);
            $statusCode = $response['statusCode'];
            $headers    = $response['headers'];
            $body       = $response['body'];
        } else {
            [$statusCode, $headers, $body] = $this->curlGet($url);
        }

        return $this->handleResponse($statusCode, $headers, $body, $url);
    }

    /**
     * Execute the real cURL request.
     *
     * @return array{int, string, string}  [statusCode, headers, body]
     */
    private function curlGet(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ElevenEloException('Failed to initialise cURL');
        }

        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT       => $this->timeout,
            \CURLOPT_HTTPHEADER    => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json',
            ],
            \CURLOPT_HEADER        => true,
            \CURLOPT_FOLLOWLOCATION => true,
        ]);

        $raw        = curl_exec($ch);
        $curlErrno  = curl_errno($ch);
        $curlError  = curl_error($ch);
        $headerSize = (int) curl_getinfo($ch, \CURLINFO_HEADER_SIZE);
        $statusCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0 || $raw === false) {
            throw new ElevenEloException('cURL request failed: ' . $curlError);
        }

        $headers = substr((string) $raw, 0, $headerSize);
        $body    = substr((string) $raw, $headerSize);

        return [$statusCode, $headers, $body];
    }

    /**
     * @return mixed
     */
    private function handleResponse(int $statusCode, string $headers, string $body, string $url): mixed
    {
        if ($statusCode === 401) {
            throw new AuthenticationException(
                'Invalid or missing API key. Obtain one at https://11elo.com/developer',
            );
        }

        if ($statusCode === 429) {
            $resetAt = $this->extractHeader($headers, 'X-RateLimit-Reset');
            throw new RateLimitException(
                'Daily rate limit exceeded. Upgrade your plan at https://11elo.com/developer',
                $resetAt,
            );
        }

        if ($statusCode === 404) {
            throw new NotFoundException('Resource not found: ' . $url);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ApiException(
                sprintf('API request failed with status %d: %s', $statusCode, $body),
                $statusCode,
            );
        }

        $decoded = json_decode($body, true);
        if ($decoded === null && json_last_error() !== \JSON_ERROR_NONE) {
            throw new ElevenEloException('Failed to parse JSON response: ' . $body);
        }

        return $decoded;
    }

    /** Extract the value of a single response header (case-insensitive). */
    private function extractHeader(string $headers, string $name): ?string
    {
        $pattern = '/^' . preg_quote($name, '/') . ':\s*(.+)/im';
        if (preg_match($pattern, $headers, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Teams
    // -------------------------------------------------------------------------

    /**
     * Return all teams with their current ELO stats and league info.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTeams(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/teams');
    }

    /**
     * Return detailed information for a single team.
     *
     * @param  string $teamName The canonical team name, e.g. "Bayern München".
     * @return array<string, mixed> Contains "team", "eloHistory", "recentForm",
     *                              "significantMatches", "stats", "upcomingMatches".
     */
    public function getTeam(string $teamName): array
    {
        /** @var array<string, mixed> */
        return $this->get('/api/teams/' . rawurlencode($teamName));
    }

    /**
     * Return head-to-head match history between two teams.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHeadToHead(string $team1, string $team2): array
    {
        $path = '/api/teams/' . rawurlencode($team1) . '/head-to-head/' . rawurlencode($team2);
        /** @var array<int, array<string, mixed>> */
        return $this->get($path);
    }

    // -------------------------------------------------------------------------
    // Matches
    // -------------------------------------------------------------------------

    /**
     * Return a paginated list of historical matches.
     *
     * @param  string|null $season   Filter by season, e.g. "2024/2025".
     * @param  string|null $fromDate ISO-8601 start date "YYYY-MM-DD".
     * @param  string|null $toDate   ISO-8601 end date "YYYY-MM-DD".
     * @param  int|null    $limit    Max results (default 100, max 500).
     * @param  int|null    $offset   Pagination offset (default 0).
     * @return array<int, array<string, mixed>>
     */
    public function getMatches(
        ?string $season   = null,
        ?string $fromDate = null,
        ?string $toDate   = null,
        ?int    $limit    = null,
        ?int    $offset   = null,
    ): array {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/matches', [
            'season' => $season,
            'from'   => $fromDate,
            'to'     => $toDate,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Return upcoming fixtures with ELO-difference predictions.
     *
     * @param  string|null $league League code, e.g. "BL1".
     * @param  string|null $sort   Sort order (default "date").
     * @param  int|null    $limit  Max results (default 50, max 200).
     * @return array<int, array<string, mixed>>
     */
    public function getUpcomingMatches(
        ?string $league = null,
        ?string $sort   = null,
        ?int    $limit  = null,
    ): array {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/matches/upcoming', [
            'league' => $league,
            'sort'   => $sort,
            'limit'  => $limit,
        ]);
    }

    /**
     * Return full details for a single match.
     *
     * @param  int|string $matchId The numeric match identifier.
     * @return array<string, mixed> Contains "match", "homeRecentForm", "awayRecentForm",
     *                              "homeStats", "awayStats", "headToHead".
     */
    public function getMatch(int|string $matchId): array
    {
        /** @var array<string, mixed> */
        return $this->get('/api/matches/' . $matchId);
    }

    // -------------------------------------------------------------------------
    // Seasons
    // -------------------------------------------------------------------------

    /**
     * Return all available seasons and the most recent one.
     *
     * @return array{seasons: list<string>, latestSeason: string}
     */
    public function getSeasons(): array
    {
        /** @var array{seasons: list<string>, latestSeason: string} */
        return $this->get('/api/seasons');
    }

    /**
     * Return per-team ELO change statistics for a given season.
     *
     * @param  string      $season Season string, e.g. "2024/2025".
     * @param  string|null $league Optional league filter, e.g. "BL1".
     * @return array<int, array<string, mixed>>
     */
    public function getSeason(string $season, ?string $league = null): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/seasons/' . rawurlencode($season), ['league' => $league]);
    }

    // -------------------------------------------------------------------------
    // Comparison
    // -------------------------------------------------------------------------

    /**
     * Return historical ELO time-series for multiple teams side-by-side.
     *
     * @param  string[] $teams At least two team names.
     * @return array<string, list<array{Date: int, ELO: int}>>
     *
     * @throws \InvalidArgumentException if fewer than two team names are provided.
     */
    public function getComparisonHistory(array $teams): array
    {
        if (count($teams) < 2) {
            throw new \InvalidArgumentException(
                'At least two team names are required for comparison',
            );
        }
        /** @var array<string, list<array{Date: int, ELO: int}>> */
        return $this->get('/api/comparison/history', ['teams' => implode(',', $teams)]);
    }
}
