<?php

declare(strict_types=1);

namespace ElevenElo\Tests;

use ElevenElo\Client;
use ElevenElo\Exception\ApiException;
use ElevenElo\Exception\AuthenticationException;
use ElevenElo\Exception\NotFoundException;
use ElevenElo\Exception\RateLimitException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ElevenElo PHP client.
 *
 * All HTTP calls are intercepted via the injectable $transport callable so no
 * real network connection is required.
 */
class ClientTest extends TestCase
{
    private const API_KEY = '11e_fre_testkey';

    /**
     * Build a Client with a mock transport that returns the given status,
     * JSON body, and optional extra headers.
     *
     * @param int                    $statusCode
     * @param mixed                  $body
     * @param array<string, string>  $extraHeaders
     */
    private function makeClient(int $statusCode, mixed $body, array $extraHeaders = []): Client
    {
        $transport = function (string $url, string $apiKey) use ($statusCode, $body, $extraHeaders): array {
            // Verify API key is forwarded
            $this->assertSame(self::API_KEY, $apiKey);

            $headerLines = "HTTP/1.1 {$statusCode} Test\r\nContent-Type: application/json\r\n";
            foreach ($extraHeaders as $k => $v) {
                $headerLines .= "{$k}: {$v}\r\n";
            }
            $headerLines .= "\r\n";

            return [
                'statusCode' => $statusCode,
                'headers'    => $headerLines,
                'body'       => json_encode($body, \JSON_THROW_ON_ERROR),
            ];
        };

        return new Client(self::API_KEY, 'https://11elo.com', 30, $transport);
    }

    /**
     * Build a Client with a transport that records the requested URL.
     */
    private function makeCapturingClient(mixed $body, string &$capturedUrl): Client
    {
        $transport = function (string $url, string $apiKey) use ($body, &$capturedUrl): array {
            $capturedUrl = $url;
            return [
                'statusCode' => 200,
                'headers'    => "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n",
                'body'       => json_encode($body, \JSON_THROW_ON_ERROR),
            ];
        };

        return new Client(self::API_KEY, 'https://11elo.com', 30, $transport);
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testEmptyApiKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }

    // -------------------------------------------------------------------------
    // Teams
    // -------------------------------------------------------------------------

    public function testGetTeams(): void
    {
        $payload = [['teamName' => 'Bayern München', 'currentElo' => 1847]];
        $result  = $this->makeClient(200, $payload)->getTeams();
        $this->assertSame($payload, $result);
    }

    public function testGetTeamEncodesName(): void
    {
        $capturedUrl = '';
        $payload     = ['team' => ['name' => 'Bayern München']];
        $this->makeCapturingClient($payload, $capturedUrl)->getTeam('Bayern München');
        $this->assertStringContainsString('Bayern%20M%C3%BCnchen', $capturedUrl);
    }

    public function testGetHeadToHead(): void
    {
        $capturedUrl = '';
        $payload     = [['result' => '2:1']];
        $this->makeCapturingClient($payload, $capturedUrl)
             ->getHeadToHead('Bayern München', 'Borussia Dortmund');
        $this->assertStringContainsString('head-to-head', $capturedUrl);
        $this->assertStringContainsString('Bayern%20M%C3%BCnchen', $capturedUrl);
        $this->assertStringContainsString('Borussia%20Dortmund', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // Matches
    // -------------------------------------------------------------------------

    public function testGetMatchesWithParams(): void
    {
        $capturedUrl = '';
        $payload     = [['id' => 1]];
        $this->makeCapturingClient($payload, $capturedUrl)
             ->getMatches(season: '2024/2025', limit: 10);
        $this->assertStringContainsString('season=', $capturedUrl);
        $this->assertStringContainsString('limit=10', $capturedUrl);
    }

    public function testGetUpcomingMatches(): void
    {
        $capturedUrl = '';
        $payload     = [['homeTeam' => 'BVB']];
        $this->makeCapturingClient($payload, $capturedUrl)
             ->getUpcomingMatches(league: 'BL1', limit: 5);
        $this->assertStringContainsString('league=BL1', $capturedUrl);
        $this->assertStringContainsString('limit=5', $capturedUrl);
    }

    public function testGetMatch(): void
    {
        $capturedUrl = '';
        $payload     = ['match' => ['id' => 12345]];
        $this->makeCapturingClient($payload, $capturedUrl)->getMatch(12345);
        $this->assertStringEndsWith('/api/matches/12345', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // Seasons
    // -------------------------------------------------------------------------

    public function testGetSeasons(): void
    {
        $payload = ['seasons' => ['2025/2026', '2024/2025'], 'latestSeason' => '2025/2026'];
        $result  = $this->makeClient(200, $payload)->getSeasons();
        $this->assertSame('2025/2026', $result['latestSeason']);
    }

    public function testGetSeasonEncodesAndPassesLeague(): void
    {
        $capturedUrl = '';
        $payload     = [['teamName' => 'Bayern München', 'change' => 27]];
        $this->makeCapturingClient($payload, $capturedUrl)
             ->getSeason('2024/2025', 'BL1');
        $this->assertStringContainsString('2024', $capturedUrl);
        $this->assertStringContainsString('league=BL1', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // Comparison
    // -------------------------------------------------------------------------

    public function testGetComparisonHistory(): void
    {
        $capturedUrl = '';
        $payload     = ['Bayern München' => [], 'Borussia Dortmund' => []];
        $this->makeCapturingClient($payload, $capturedUrl)
             ->getComparisonHistory(['Bayern München', 'Borussia Dortmund']);
        $this->assertStringContainsString('teams=', $capturedUrl);
    }

    public function testGetComparisonHistoryRequiresTwoTeams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeClient(200, [])->getComparisonHistory(['Bayern München']);
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testAuthenticationException(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->makeClient(401, [])->getTeams();
    }

    public function testRateLimitExceptionCarriesResetAt(): void
    {
        $client = $this->makeClient(
            429,
            [],
            ['X-RateLimit-Reset' => '2026-03-13T00:00:00Z'],
        );
        try {
            $client->getTeams();
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame('2026-03-13T00:00:00Z', $e->resetAt);
        }
    }

    public function testNotFoundException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->makeClient(404, [])->getTeam('Unknown FC');
    }

    public function testApiExceptionCarriesStatusCode(): void
    {
        try {
            $this->makeClient(500, ['error' => 'internal'])->getTeams();
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->statusCode);
        }
    }
}
