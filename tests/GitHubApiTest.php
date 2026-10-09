<?php

declare(strict_types=1);

namespace viesrood\igotissues\tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use viesrood\igotissues\github\GitHubApi;
use viesrood\igotissues\github\GitHubException;

final class GitHubApiTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /**
     * @param array<int, Response|\Throwable> $responses
     */
    private function api(array $responses): GitHubApi
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GitHubApi(new Client(['handler' => $stack, 'base_uri' => GitHubApi::BASE_URI]), 'o/r', 'secret-token');
    }

    private function request(int $index = 0): Request
    {
        /** @var Request $request */
        $request = $this->history[$index]['request'];

        return $request;
    }

    private static function json(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($data));
    }

    public function testCreatingAnIssueSendsTitleBodyAndLabelsToTheRepository(): void
    {
        $api = $this->api([self::json(['number' => 41, 'title' => 'T', 'html_url' => 'https://github.com/o/r/issues/41', 'state' => 'open'], 201)]);

        $issue = $api->createIssue('T', 'B', ['bug']);

        self::assertSame(['number' => 41, 'title' => 'T', 'url' => 'https://github.com/o/r/issues/41', 'state' => 'open'], $issue);
        self::assertSame('POST', $this->request()->getMethod());
        self::assertSame('https://api.github.com/repos/o/r/issues', (string)$this->request()->getUri());
        self::assertSame('Bearer secret-token', $this->request()->getHeaderLine('Authorization'));
        self::assertSame(['title' => 'T', 'body' => 'B', 'labels' => ['bug']], json_decode((string)$this->request()->getBody(), true));
    }

    public function testPullRequestsAreNotIssues(): void
    {
        $api = $this->api([self::json([
            ['number' => 1, 'title' => 'Issue', 'html_url' => 'u1', 'state' => 'open'],
            ['number' => 2, 'title' => 'PR', 'html_url' => 'u2', 'state' => 'open', 'pull_request' => ['url' => 'x']],
        ])]);

        self::assertSame([1], array_column($api->issuesSince('2026-10-01T00:00:00Z'), 'number'));
        self::assertStringContainsString('state=all', $this->request()->getUri()->getQuery());
        self::assertStringContainsString('since=2026-10-01', $this->request()->getUri()->getQuery());
    }

    public function testIssueFormsAreReadAndTheChooserConfigIsSkipped(): void
    {
        $api = $this->api([
            self::json([
                ['type' => 'file', 'name' => 'wens.yml'],
                ['type' => 'file', 'name' => 'config.yml'],
                ['type' => 'file', 'name' => 'old.md'],
                ['type' => 'dir', 'name' => 'more'],
                ['type' => 'file', 'name' => 'fout.yml'],
            ]),
            self::json(['content' => base64_encode('name: Wens')]),
            self::json(['content' => base64_encode('name: Fout')]),
        ]);

        self::assertSame(['fout.yml' => 'name: Fout', 'wens.yml' => 'name: Wens'], $api->issueForms());
        self::assertCount(3, $this->history);
    }

    public function testARepositoryWithoutFormsHasNone(): void
    {
        self::assertSame([], $this->api([self::json(['message' => 'Not Found'], 404)])->issueForms());
    }

    public function testReopeningPatchesTheState(): void
    {
        $api = $this->api([self::json(['number' => 7, 'state' => 'open'])]);
        $api->reopen(7);

        self::assertSame('PATCH', $this->request()->getMethod());
        self::assertSame(['state' => 'open'], json_decode((string)$this->request()->getBody(), true));
    }

    public function testARejectedCallThrowsWithTheStatusAndWithoutTheToken(): void
    {
        $api = $this->api([self::json(['message' => 'Bad credentials'], 401)]);

        try {
            $api->labels();
            self::fail('Expected a GitHubException');
        } catch (GitHubException $e) {
            self::assertSame(401, $e->status);
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function testAnUnreachableGitHubThrowsWithoutLeakingTheRequest(): void
    {
        $api = $this->api([new ConnectException('cURL error 28 with Bearer secret-token', new Request('GET', 'x'))]);

        try {
            $api->repository();
            self::fail('Expected a GitHubException');
        } catch (GitHubException $e) {
            self::assertSame(0, $e->status);
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }
}
