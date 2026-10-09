<?php

declare(strict_types=1);

namespace viesrood\igotissues\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings.
 *
 * The credential fields reference env vars by default and are resolved through
 * craft\helpers\App::parseEnv() by the getParsed*() getters. Every setting can
 * also be overridden per environment via config/igotissues.php.
 */
class Settings extends Model
{
    public const POSITIONS = ['bottom-right', 'bottom-left'];

    public const GITHUB_API = 'https://api.github.com/';

    /**
     * Fine-grained personal access token for one repository: Issues (read and
     * write), Contents (read) and Metadata. Server-side only.
     */
    public string $githubToken = '$IGOTISSUES_GITHUB_TOKEN';

    /** The repository issues go to, as owner/name. */
    public string $githubRepo = '$IGOTISSUES_GITHUB_REPO';

    /**
     * The REST API root. Only different for GitHub Enterprise Server, where it
     * is https://<host>/api/v3/.
     */
    public string $githubApiUrl = self::GITHUB_API;

    /** The Craft permission a user needs to report. Admins always may. */
    public string $reportPermission = 'accessCp';

    /** Corner of the screen the report button sits in. */
    public string $position = 'bottom-right';

    /**
     * Add the button to front-end pages through the end-body hook. Templates
     * without {% do view.endBody() %} call igotissues_widget() instead.
     */
    public bool $autoInject = false;

    /** Put the reporter's name in the issue. */
    public bool $includeReporter = true;

    /**
     * Form fields the site answers itself, as field id => the label of a line
     * in the technical report ('URL', 'Site', 'Environment', or one the site
     * adds through Reports::EVENT_DEFINE_CONTEXT). The reporter does not see
     * these fields. Config file only.
     *
     * @var array<string, string>
     */
    public array $autoFields = [];

    /**
     * Form field ids that are neither shown nor put in the issue, for a field
     * the technical report makes redundant. Config file only.
     *
     * @var string[]
     */
    public array $skipFields = [];

    /** Record unexpected errors in the control panel's Reports list. */
    public bool $captureErrors = true;

    /** Also record errors while devMode is on. */
    public bool $captureInDevMode = false;

    /** Also record errors from console commands. */
    public bool $captureConsole = false;

    /** Comment on and reopen a closed issue when its error comes back. */
    public bool $reopenOnRecurrence = true;

    /**
     * A file, relative to the project root, whose first line names the
     * deployed release. Empty falls back to the git commit, if there is one.
     */
    public string $releaseFile = '';

    /** HTTP timeout in seconds for GitHub calls. */
    public int $timeout = 15;

    /** The resolved GitHub token ('' when unset). */
    public function getParsedGithubToken(): string
    {
        return self::resolved($this->githubToken);
    }

    /** The resolved repository, or '' when it is not a valid owner/name. */
    public function getParsedGithubRepo(): string
    {
        $repo = self::resolved($this->githubRepo);

        return preg_match('#^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9._-]{1,100}$#', $repo) === 1 ? $repo : '';
    }

    /**
     * The API root with a trailing slash. Anything that is not an https URL
     * falls back to github.com: the token is sent to this address. Plain http
     * is accepted for this machine only, which is what a test double needs.
     */
    public function getParsedGithubApiUrl(): string
    {
        $url = self::resolved($this->githubApiUrl);
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['query'])) {
            return self::GITHUB_API;
        }

        $local = in_array($parts['host'], ['localhost', '127.0.0.1'], true);

        if ($parts['scheme'] !== 'https' && !($parts['scheme'] === 'http' && $local)) {
            return self::GITHUB_API;
        }

        return rtrim($url, '/') . '/';
    }

    public function hasGitHub(): bool
    {
        return $this->getParsedGithubToken() !== '' && $this->getParsedGithubRepo() !== '';
    }

    public function rules(): array
    {
        return [
            [['githubToken', 'githubRepo', 'githubApiUrl', 'reportPermission', 'releaseFile'], 'string', 'max' => 255],
            [['reportPermission'], 'required'],
            [['releaseFile'], 'match', 'pattern' => '#^(?!/)(?!.*\.\.)[A-Za-z0-9._/-]*$#'],
            [['position'], 'in', 'range' => self::POSITIONS],
            [['autoFields', 'skipFields'], 'each', 'rule' => ['string']],
            [['timeout'], 'integer', 'min' => 1, 'max' => 60],
            [
                ['autoInject', 'includeReporter', 'captureErrors', 'captureInDevMode', 'captureConsole', 'reopenOnRecurrence'],
                'boolean',
            ],
        ];
    }

    /**
     * An env reference that is not set parses to null or to the reference
     * itself, depending on the Craft version. Neither is a value.
     */
    private static function resolved(string $value): string
    {
        $parsed = trim((string)(App::parseEnv($value) ?? ''));

        return str_starts_with($parsed, '$') ? '' : $parsed;
    }
}
