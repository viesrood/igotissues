<?php

declare(strict_types=1);

namespace viesrood\igotissues\services;

use Craft;
use craft\base\Component;
use Throwable;
use viesrood\igotissues\github\GitHubApi;
use viesrood\igotissues\github\GitHubException;
use viesrood\igotissues\helpers\IssueForms;
use viesrood\igotissues\models\Settings;
use viesrood\igotissues\Plugin;
use yii\caching\TagDependency;

/**
 * The repository as the rest of the plugin sees it: its forms and labels,
 * cached, and the call that creates an issue.
 *
 * Reads never throw: a window that cannot reach GitHub still opens, with the
 * built-in forms. Writes do throw, because the reporter must
 * hear that nothing was sent.
 *
 * @phpstan-import-type Form from IssueForms
 * @phpstan-import-type Issue from GitHubApi
 */
class GitHub extends Component
{
    private const FORMS_TTL = 3600;
    private const LABELS_TTL = 3600;

    public function isConfigured(): bool
    {
        return $this->settings()->hasGitHub();
    }

    /**
     * @throws GitHubException
     */
    public function api(): GitHubApi
    {
        $settings = $this->settings();

        if (!$settings->hasGitHub()) {
            throw new GitHubException('No GitHub token or repository is configured.');
        }

        $client = Craft::createGuzzleClient([
            'base_uri' => $settings->getParsedGithubApiUrl(),
            'timeout' => max(1, $settings->timeout),
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);

        return new GitHubApi($client, $settings->getParsedGithubRepo(), $settings->getParsedGithubToken());
    }

    /**
     * The repository's issue forms, or the built-in pair when it has none.
     *
     * @return list<Form>
     */
    public function forms(): array
    {
        /** @var list<Form> $forms */
        $forms = $this->cached('forms', self::FORMS_TTL, function (): array {
            $forms = [];

            foreach ($this->api()->issueForms() as $name => $yaml) {
                $form = IssueForms::parse($name, $yaml);

                if ($form !== null) {
                    $forms[] = $form;
                }
            }

            return $forms;
        });

        return $forms !== [] ? $forms : IssueForms::builtin();
    }

    /**
     * @return string[]
     */
    public function labels(): array
    {
        /** @var string[] $labels */
        $labels = $this->cached('labels', self::LABELS_TTL, fn(): array => $this->api()->labels());

        return $labels;
    }

    /**
     * @param string[] $labels
     * @return Issue
     * @throws GitHubException
     */
    public function createIssue(string $title, string $body, array $labels): array
    {
        return $this->api()->createIssue($title, $body, $labels);
    }

    public function clearCache(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), Plugin::CACHE_TAG);
    }

    /**
     * @param callable(): array<array-key, mixed> $load
     * @return array<array-key, mixed>
     */
    private function cached(string $what, int $ttl, callable $load): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $cache = Craft::$app->getCache();
        $key = $this->key($what);
        $cached = $cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $value = $load();
        } catch (Throwable $e) {
            Craft::warning(sprintf('I got issues: could not load %s: %s', $what, $e->getMessage()), __METHOD__);

            // Briefly, so a GitHub outage does not add a timeout to every window.
            $cache->set($key, [], 60, new TagDependency(['tags' => [Plugin::CACHE_TAG]]));

            return [];
        }

        $cache->set($key, $value, $ttl, new TagDependency(['tags' => [Plugin::CACHE_TAG]]));

        return $value;
    }

    /**
     * The repository is in the key, never the token.
     */
    private function key(string $what): string
    {
        $settings = $this->settings();

        return Plugin::CACHE_TAG . ':' . $what . ':' . md5($settings->getParsedGithubApiUrl() . $settings->getParsedGithubRepo());
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()?->getSettings() ?? new Settings();
    }
}
