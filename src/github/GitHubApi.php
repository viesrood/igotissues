<?php

declare(strict_types=1);

namespace viesrood\igotissues\github;

use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * The handful of GitHub REST calls the plugin makes, for one repository.
 *
 * Knows nothing about Craft, so it can be tested against a mock handler. The
 * client it is given must have the API root as base_uri and must not throw on
 * HTTP errors; the status code is handled here.
 *
 * @phpstan-type Issue array{number: int, title: string, url: string, state: string}
 */
final class GitHubApi
{
    public const BASE_URI = 'https://api.github.com/';

    private const API_VERSION = '2022-11-28';

    private const FORMS_PATH = '.github/ISSUE_TEMPLATE';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $repo,
        private readonly string $token,
    ) {
    }

    /**
     * @return array{name: string, private: bool, canWriteIssues: bool}
     * @throws GitHubException
     */
    public function repository(): array
    {
        $data = $this->request('GET', '');

        return [
            'name' => is_string($data['full_name'] ?? null) ? $data['full_name'] : $this->repo,
            'private' => ($data['private'] ?? false) === true,
            'canWriteIssues' => ($data['has_issues'] ?? true) === true,
        ];
    }

    /**
     * File name => contents of every issue form in the repository.
     *
     * @return array<string, string>
     * @throws GitHubException
     */
    public function issueForms(): array
    {
        $listing = $this->request('GET', 'contents/' . self::FORMS_PATH, [], [404]);
        $forms = [];

        foreach ($listing as $entry) {
            if (!is_array($entry) || ($entry['type'] ?? null) !== 'file' || !is_string($entry['name'] ?? null)) {
                continue;
            }

            $name = $entry['name'];

            if (preg_match('/^[A-Za-z0-9._-]+\.ya?ml$/', $name) !== 1 || in_array($name, ['config.yml', 'config.yaml'], true)) {
                continue;
            }

            $file = $this->request('GET', 'contents/' . self::FORMS_PATH . '/' . rawurlencode($name), [], [404]);
            $content = is_string($file['content'] ?? null) ? base64_decode($file['content'], false) : false;

            if (is_string($content) && $content !== '') {
                $forms[$name] = $content;
            }
        }

        ksort($forms);

        return $forms;
    }

    /**
     * @return string[]
     * @throws GitHubException
     */
    public function labels(): array
    {
        $labels = [];

        foreach ($this->request('GET', 'labels', ['query' => ['per_page' => 100]]) as $label) {
            if (is_array($label) && is_string($label['name'] ?? null)) {
                $labels[] = $label['name'];
            }
        }

        return $labels;
    }

    /**
     * The most recently updated open issues, pull requests left out.
     *
     * @return list<Issue>
     * @throws GitHubException
     */
    public function openIssues(int $limit = 100): array
    {
        return $this->issues(['state' => 'open', 'sort' => 'updated', 'per_page' => max(1, min(100, $limit))]);
    }

    /**
     * Issues in any state that changed since the given ISO 8601 time.
     *
     * @return list<Issue>
     * @throws GitHubException
     */
    public function issuesSince(?string $since): array
    {
        $query = ['state' => 'all', 'sort' => 'updated', 'per_page' => 100];

        if ($since !== null && $since !== '') {
            $query['since'] = $since;
        }

        return $this->issues($query);
    }

    /**
     * @return Issue|null Null when the issue does not exist (any more).
     * @throws GitHubException
     */
    public function issue(int $number): ?array
    {
        return self::toIssue($this->request('GET', 'issues/' . $number, [], [404, 410]));
    }

    /**
     * @param string[] $labels
     * @return Issue
     * @throws GitHubException
     */
    public function createIssue(string $title, string $body, array $labels = []): array
    {
        $issue = self::toIssue($this->request('POST', 'issues', [
            'json' => ['title' => $title, 'body' => $body, 'labels' => array_values($labels)],
        ]));

        if ($issue === null) {
            throw new GitHubException('GitHub created no issue.');
        }

        return $issue;
    }

    /**
     * @throws GitHubException
     */
    public function comment(int $number, string $body): void
    {
        $this->request('POST', 'issues/' . $number . '/comments', ['json' => ['body' => $body]]);
    }

    /**
     * @throws GitHubException
     */
    public function reopen(int $number): void
    {
        $this->request('PATCH', 'issues/' . $number, ['json' => ['state' => 'open']]);
    }

    /**
     * @param array<string, mixed> $query
     * @return list<Issue>
     * @throws GitHubException
     */
    private function issues(array $query): array
    {
        $issues = [];

        foreach ($this->request('GET', 'issues', ['query' => $query]) as $item) {
            // The issues endpoint also returns pull requests.
            if (!is_array($item) || isset($item['pull_request'])) {
                continue;
            }

            $issue = self::toIssue($item);

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return Issue|null
     */
    private static function toIssue(array $data): ?array
    {
        if (!is_int($data['number'] ?? null)) {
            return null;
        }

        return [
            'number' => $data['number'],
            'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
            'url' => is_string($data['html_url'] ?? null) ? $data['html_url'] : '',
            'state' => ($data['state'] ?? 'open') === 'closed' ? 'closed' : 'open',
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param int[] $emptyOn Status codes that mean "nothing there", not failure.
     * @return array<array-key, mixed>
     * @throws GitHubException
     */
    private function request(string $method, string $path, array $options = [], array $emptyOn = []): array
    {
        $uri = 'repos/' . $this->repo . ($path !== '' ? '/' . $path : '');

        $options['headers'] = [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $this->token,
            'X-GitHub-Api-Version' => self::API_VERSION,
            'User-Agent' => 'igotissues-craft-plugin',
        ];
        $options['http_errors'] = false;

        try {
            $response = $this->client->request($method, $uri, $options);
        } catch (Throwable $e) {
            // Not the exception's own message: Guzzle puts the request in it.
            throw new GitHubException(sprintf('GitHub is unreachable (%s %s).', $method, $path), 0, $e);
        }

        $status = $response->getStatusCode();

        if (in_array($status, $emptyOn, true)) {
            return [];
        }

        $body = (string)$response->getBody();

        if ($status >= 400) {
            throw new GitHubException(
                sprintf('GitHub returned HTTP %d for %s %s: %s', $status, $method, $path, mb_substr($body, 0, 300)),
                $status,
            );
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new GitHubException(sprintf('GitHub returned a non-JSON body for %s %s.', $method, $path), $status);
        }

        return $decoded;
    }
}
