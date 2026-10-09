<?php

declare(strict_types=1);

namespace viesrood\igotissues\services;

use Craft;
use craft\base\Component;
use Throwable;
use viesrood\igotissues\github\GitHubApi;
use viesrood\igotissues\helpers\Drafting;
use viesrood\igotissues\helpers\IssueForms;
use viesrood\igotissues\models\Settings;
use viesrood\igotissues\Plugin;

/**
 * Asks Claude to turn what a reporter typed into a draft issue.
 *
 * A draft is a convenience. Every failure here (no key, a timeout, a refusal,
 * a reply that is not what was asked for) returns null and the reporter gets
 * the plain form instead.
 *
 * @phpstan-import-type Form from IssueForms
 * @phpstan-import-type Issue from GitHubApi
 * @phpstan-import-type Draft from Drafting
 */
class Drafter extends Component
{
    /** A draft is worth waiting for, but not for long: someone is watching a spinner. */
    private const TIMEOUT = 60;

    public function isConfigured(): bool
    {
        return $this->settings()->hasDrafts();
    }

    /**
     * @param list<Form> $forms
     * @param string[] $labels
     * @param list<Issue> $issues
     * @param array<string, string> $page
     * @return Draft|null
     */
    public function draft(string $report, array $forms, array $labels, array $issues, array $page, string $error = ''): ?array
    {
        $settings = $this->settings();

        if (!$settings->hasDrafts() || $forms === []) {
            return null;
        }

        $model = trim($settings->model);
        $headers = [
            'x-api-key' => $settings->getParsedAnthropicApiKey(),
            'anthropic-version' => Drafting::API_VERSION,
            'content-type' => 'application/json',
        ];

        if (Drafting::supportsFallback($model)) {
            $headers['anthropic-beta'] = Drafting::FALLBACK_BETA;
        }

        try {
            $response = Craft::createGuzzleClient([
                'timeout' => self::TIMEOUT,
                'connect_timeout' => 5,
                'http_errors' => false,
            ])->request('POST', Drafting::ENDPOINT, [
                'headers' => $headers,
                'json' => Drafting::requestBody(
                    $model,
                    Drafting::system($settings->draftInstructions),
                    Drafting::user($report, $forms, $labels, $issues, $page, $error),
                ),
            ]);
        } catch (Throwable $e) {
            Craft::warning('I got issues: the Claude API is unreachable: ' . $e::class, __METHOD__);

            return null;
        }

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status >= 400) {
            Craft::warning(sprintf('I got issues: the Claude API returned HTTP %d: %s', $status, mb_substr($body, 0, 300)), __METHOD__);

            return null;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return null;
        }

        $draft = Drafting::parse($decoded, $forms, $labels, $issues);

        if ($draft === null) {
            Craft::info(sprintf(
                'I got issues: no usable draft (stop_reason: %s).',
                is_string($decoded['stop_reason'] ?? null) ? $decoded['stop_reason'] : 'unknown',
            ), __METHOD__);
        }

        return $draft;
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()?->getSettings() ?? new Settings();
    }
}
