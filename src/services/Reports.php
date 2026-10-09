<?php

declare(strict_types=1);

namespace viesrood\igotissues\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use viesrood\igotissues\events\DefineContextEvent;
use viesrood\igotissues\github\GitHubException;
use viesrood\igotissues\helpers\IssueBody;
use viesrood\igotissues\helpers\IssueForms;
use viesrood\igotissues\helpers\Scrubber;
use viesrood\igotissues\Plugin;
use viesrood\igotissues\records\ReportRecord;

/**
 * Reports: who may send one, what goes with it, and getting it to GitHub.
 *
 * @phpstan-import-type Form from IssueForms
 */
class Reports extends Component
{
    /**
     * @event DefineContextEvent Raised while the context of a report is built.
     */
    public const EVENT_DEFINE_CONTEXT = 'defineContext';

    /** How long after an error its user is asked what they were doing. */
    private const RECENT_ERROR_SECONDS = 600;

    private const SYNC_KEY = 'igotissues:last-sync';
    private const SYNC_EVERY = 300;

    private const BROWSER_EVENTS = 30;
    private const BROWSER_EVENT_TYPES = ['error', 'console', 'request', 'click', 'page'];

    /** @var string[]|null */
    private ?array $secrets = null;

    public function canReport(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $permission = trim(Plugin::getInstance()?->getSettings()->reportPermission ?? 'accessCp');

        return $user->admin || ($permission !== '' && $user->can($permission));
    }

    public function basePath(): string
    {
        return rtrim((string)Craft::getAlias('@root', false), '/');
    }

    /**
     * Text with this installation's secrets and paths removed.
     */
    public function scrub(string $text): string
    {
        $this->secrets ??= Scrubber::secretsFrom($_ENV + $_SERVER);

        return Scrubber::text($text, $this->secrets, $this->basePath());
    }

    /**
     * What the site knows about the moment of a report.
     *
     * @param array<array-key, mixed> $page What the browser said about the page.
     * @return array<string, string>
     */
    public function context(array $page, ?User $user): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin?->getSettings();
        $string = static fn(mixed $value, int $length): string => is_scalar($value)
            ? Scrubber::limit(trim((string)$value), $length)
            : '';

        $context = [
            'URL' => $this->scrub($string($page['url'] ?? '', 1000)),
            'Page title' => $string($page['title'] ?? '', 200),
        ];

        /** @var \craft\web\Application|\craft\console\Application $app */
        $app = Craft::$app;

        try {
            $context['Site'] = $app->getSites()->getCurrentSite()->handle;
        } catch (Throwable) {
            // No current site on this request; the URL says enough.
        }

        $context['Environment'] = (string)($app->env ?? '');
        $context['Release'] = $this->release();
        $context['Craft'] = $app->getVersion();
        $context['I got issues'] = (string)$plugin?->getVersion();
        $context['PHP'] = PHP_VERSION;
        $context['Database'] = $this->database();
        $context['Plugins'] = $this->plugins();
        $context['Dev mode'] = $app->getConfig()->getGeneral()->devMode ? 'on' : '';
        $context['Language'] = $string($page['language'] ?? '', 20);
        $context['Reported at'] = gmdate('Y-m-d H:i:s') . ' UTC';
        $context['Browser'] = $string($app->getRequest()->getUserAgent(), 300);
        $context['Viewport'] = $string($page['viewport'] ?? '', 30);

        if ($user !== null && ($settings === null || $settings->includeReporter)) {
            $context['Reported by'] = $user->getName();
        }

        if ($this->hasEventHandlers(self::EVENT_DEFINE_CONTEXT)) {
            $event = new DefineContextEvent(['context' => $context]);
            $this->trigger(self::EVENT_DEFINE_CONTEXT, $event);
            $context = [];

            foreach ($event->context as $label => $value) {
                $context[(string)$label] = $this->scrub((string)$value);
            }
        }

        return array_filter($context, static fn(string $value): bool => $value !== '');
    }

    /**
     * What the browser saw before the report: script errors, failed requests,
     * console errors and the last clicks, one per line, oldest first.
     *
     * Everything here was typed by a browser, so it is untrusted: only known
     * kinds, bounded lengths, scrubbed like any other text.
     *
     * @param array<array-key, mixed> $page
     */
    public function browserLog(array $page): string
    {
        $lines = [];

        foreach (array_slice(is_array($page['events'] ?? null) ? $page['events'] : [], -self::BROWSER_EVENTS) as $event) {
            if (!is_array($event) || !in_array($event['type'] ?? null, self::BROWSER_EVENT_TYPES, true)) {
                continue;
            }

            $text = is_scalar($event['text'] ?? null) ? trim((string)preg_replace('/\s+/', ' ', (string)$event['text'])) : '';
            $time = is_string($event['time'] ?? null) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $event['time']) === 1 ? $event['time'] : '--:--:--';

            if ($text !== '') {
                $lines[] = sprintf('%s  %-7s  %s', $time, $event['type'], $this->scrub(Scrubber::limit($text, 300)));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * What will go along with a report from this page, as the reporter may
     * read it before sending.
     *
     * @param array<array-key, mixed> $page
     */
    public function preview(array $page, User $user, ?ReportRecord $error): string
    {
        return IssueBody::plainReport($this->context($page, $user), $this->browserLog($page), $this->errorText($error));
    }

    /**
     * The error an issue is about, as text.
     */
    public function errorText(?ReportRecord $error): string
    {
        if ($error === null) {
            return '';
        }

        return trim($error->title . "\n\n" . (string)$error->body);
    }

    /**
     * The forms as the reporter sees them: without the fields the site fills
     * in itself and without the ones it leaves out.
     *
     * @param list<Form> $forms
     * @return list<Form>
     */
    public function visibleForms(array $forms): array
    {
        $settings = Plugin::getInstance()?->getSettings();

        if ($settings === null) {
            return $forms;
        }

        $hidden = array_merge(array_keys($settings->autoFields), $settings->skipFields);

        return array_map(static fn(array $form): array => IssueForms::without($form, $hidden), $forms);
    }

    /**
     * A captured error this user may attach a report to.
     */
    public function errorFor(?int $id): ?ReportRecord
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        /** @var ReportRecord|null $record */
        $record = ReportRecord::find()
            ->where(['id' => $id, 'source' => ReportRecord::SOURCE_ERROR])
            ->andWhere(['issueNumber' => null])
            ->one();

        return $record;
    }

    /**
     * An error this user ran into moments ago and that nobody reported yet.
     */
    public function recentErrorFor(User $user): ?ReportRecord
    {
        $since = Db::prepareDateForDb((new DateTime())->modify('-' . self::RECENT_ERROR_SECONDS . ' seconds'));

        /** @var ReportRecord|null $record */
        $record = ReportRecord::find()
            ->where([
                'source' => ReportRecord::SOURCE_ERROR,
                'status' => ReportRecord::STATUS_CAPTURED,
                'userId' => $user->id,
            ])
            ->andWhere(['>=', 'lastSeen', $since])
            ->orderBy(['lastSeen' => SORT_DESC])
            ->one();

        return $record;
    }

    /**
     * Errors that were recorded and are waiting for someone to look.
     */
    public function pendingCount(): int
    {
        return (int)ReportRecord::find()
            ->where(['source' => ReportRecord::SOURCE_ERROR, 'status' => ReportRecord::STATUS_CAPTURED])
            ->count();
    }

    /**
     * @return ReportRecord[]
     */
    public function mine(User $user, int $limit = 10): array
    {
        /** @var ReportRecord[] $records */
        $records = ReportRecord::find()
            ->where(['userId' => $user->id, 'source' => ReportRecord::SOURCE_MANUAL])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();

        return $records;
    }

    /**
     * Send a report to GitHub as a new issue, with its technical report.
     *
     * @param array<array-key, mixed> $input form, title, fields, reportId
     * @param array<array-key, mixed> $page
     * @throws InvalidArgumentException When the report is incomplete; the message is for the reporter.
     * @throws GitHubException When GitHub did not take it.
     */
    public function send(User $user, array $input, array $page): ReportRecord
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new GitHubException('The plugin is not available.');
        }

        $github = $plugin->getGitHub();
        $forms = $github->forms();
        $form = IssueForms::find($forms, is_string($input['form'] ?? null) ? $input['form'] : '');

        if ($form === null) {
            throw new InvalidArgumentException(Craft::t('igotissues', 'Choose what kind of report this is.'));
        }

        // Fields the site answers itself are filled from the context; fields it
        // has no use for are left out of the issue altogether.
        $settings = $plugin->getSettings();
        $form = IssueForms::without($form, $settings->skipFields);
        $context = $this->context($page, $user);
        $posted = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        $values = [];

        foreach ($form['fields'] as $field) {
            if (isset($settings->autoFields[$field['id']])) {
                $values[$field['id']] = IssueForms::autoValue($field, $context, $settings->autoFields[$field['id']]);

                continue;
            }

            $value = is_string($posted[$field['id']] ?? null) ? trim($posted[$field['id']]) : '';

            if ($field['type'] === 'dropdown' && $value !== '' && !in_array($value, $field['options'], true)) {
                $value = '';
            }

            if ($field['required'] && $value === '') {
                throw new InvalidArgumentException(Craft::t('igotissues', '“{field}” is required.', ['field' => $field['label']]));
            }

            $values[$field['id']] = $this->scrub(Scrubber::limit($value, 10000));
        }

        $title = $this->scrub(is_string($input['title'] ?? null) ? trim($input['title']) : '');

        if ($title === '') {
            throw new InvalidArgumentException(Craft::t('igotissues', 'Give the report a title.'));
        }

        $error = $this->errorFor(is_numeric($input['reportId'] ?? null) ? (int)$input['reportId'] : null);
        $record = $error ?? new ReportRecord([
            'source' => ReportRecord::SOURCE_MANUAL,
            'uid' => StringHelper::UUID(),
            'occurrences' => 1,
        ]);

        if ($error !== null) {
            $context['Error seen'] = sprintf('%d times, most recently %s UTC', $error->occurrences, $error->lastSeen);
            $context += $this->prefixed('Error', $error->contextLines());
        }

        // GitHub rejects the whole issue over one label it does not know.
        $labels = array_values(array_intersect($form['labels'], $github->labels()));
        $fullTitle = IssueBody::title($form, $title);
        $report = IssueBody::technicalReport($context, $this->browserLog($page), $this->errorText($error));

        $issue = $github->createIssue(
            $fullTitle,
            IssueBody::render($form, $values, $report, IssueBody::marker($record->uid, $record->fingerprint)),
            $labels,
        );

        $now = Db::prepareDateForDb(new DateTime());

        if ($error === null) {
            $record->title = Scrubber::limit($fullTitle, 255);
            $record->body = IssueBody::render($form, $values, '', '');
            $record->context = (string)json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE);
            $record->userId = $user->id;
            $record->firstSeen = $now;
            $record->lastSeen = $now;

            try {
                /** @var \craft\web\Application|\craft\console\Application $app */
                $app = Craft::$app;
                $record->siteId = $app->getSites()->getCurrentSite()->id;
            } catch (Throwable) {
                $record->siteId = null;
            }
        }

        $record->status = ReportRecord::STATUS_SENT;
        $record->issueNumber = $issue['number'];
        $record->issueUrl = Scrubber::limit($issue['url'], 255);
        $record->issueState = $issue['state'];
        $record->notifiedOccurrences = $record->occurrences;

        if (!$record->save(false)) {
            // The issue exists; losing the local row only loses the link to it.
            Craft::error(sprintf('I got issues: issue #%d was created but the report could not be saved.', $issue['number']), __METHOD__);
        }

        return $record;
    }

    public function ignore(ReportRecord $record, bool $ignored = true): void
    {
        if ($record->issueNumber !== null) {
            return;
        }

        $record->status = $ignored ? ReportRecord::STATUS_IGNORED : ReportRecord::STATUS_CAPTURED;
        $record->save(false);
    }

    /**
     * Bring the issue states up to date, and tell GitHub about errors that
     * came back after their issue was closed.
     *
     * @param bool $force Sync now, even if the last sync was moments ago.
     * @return array{updated: int, reopened: int}
     */
    public function sync(bool $force = false): array
    {
        $result = ['updated' => 0, 'reopened' => 0];
        $plugin = Plugin::getInstance();

        if ($plugin === null || !$plugin->getGitHub()->isConfigured()) {
            return $result;
        }

        $cache = Craft::$app->getCache();
        $last = $cache->get(self::SYNC_KEY);
        $last = is_int($last) ? $last : 0;

        if (!$force && time() - $last < self::SYNC_EVERY) {
            return $result;
        }

        $startedAt = time();

        try {
            $api = $plugin->getGitHub()->api();
            // A minute of overlap, for clocks that disagree.
            $since = $last > 0 ? gmdate('Y-m-d\TH:i:s\Z', $last - 60) : null;
            $states = [];

            foreach ($api->issuesSince($since) as $issue) {
                $states[$issue['number']] = $issue['state'];
            }

            if ($states !== []) {
                /** @var ReportRecord[] $records */
                $records = ReportRecord::find()->where(['issueNumber' => array_keys($states)])->all();

                foreach ($records as $record) {
                    $state = $states[(int)$record->issueNumber];

                    if ($record->issueState !== $state) {
                        $record->issueState = $state;
                        $record->save(false);
                        $result['updated']++;
                    }
                }
            }

            if ($plugin->getSettings()->reopenOnRecurrence) {
                $result['reopened'] = $this->reopenRecurrences();
            }
        } catch (Throwable $e) {
            Craft::warning('I got issues: sync failed: ' . $e->getMessage(), __METHOD__);

            return $result;
        }

        $cache->set(self::SYNC_KEY, $startedAt, 0);

        return $result;
    }

    /**
     * @throws GitHubException
     */
    private function reopenRecurrences(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return 0;
        }

        /** @var ReportRecord[] $records */
        $records = ReportRecord::find()
            ->where(['source' => ReportRecord::SOURCE_ERROR, 'issueState' => 'closed'])
            ->andWhere(['not', ['issueNumber' => null]])
            ->andWhere('[[occurrences]] > [[notifiedOccurrences]]')
            ->all();

        $api = $records !== [] ? $plugin->getGitHub()->api() : null;
        $reopened = 0;

        foreach ($records as $record) {
            $number = (int)$record->issueNumber;

            $api?->comment($number, IssueBody::recurrence(
                $record->occurrences,
                (new DateTime($record->lastSeen, new DateTimeZone('UTC')))->format('Y-m-d H:i') . ' UTC',
                IssueBody::marker($record->uid, $record->fingerprint),
            ));
            $api?->reopen($number);

            $record->issueState = 'open';
            $record->notifiedOccurrences = $record->occurrences;
            $record->save(false);
            $reopened++;
        }

        return $reopened;
    }

    /**
     * The deployed release: the first line of the configured file, or else the
     * short hash of the checked-out commit. '' when neither is there.
     */
    private function release(): string
    {
        $base = $this->basePath();
        $file = trim(Plugin::getInstance()?->getSettings()->releaseFile ?? '');

        if ($file !== '' && !str_contains($file, '..') && is_file($base . '/' . $file)) {
            $handle = @fopen($base . '/' . $file, 'r');
            $line = $handle !== false ? fgets($handle, 200) : false;

            if ($handle !== false) {
                fclose($handle);
            }

            return is_string($line) ? Scrubber::limit(trim($line), 80) : '';
        }

        $head = @file_get_contents($base . '/.git/HEAD', false, null, 0, 200);

        if (!is_string($head)) {
            return '';
        }

        $head = trim($head);

        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);

            if (preg_match('#^refs/[A-Za-z0-9._/-]+$#', $ref) !== 1 || str_contains($ref, '..')) {
                return '';
            }

            $head = trim((string)@file_get_contents($base . '/.git/' . $ref, false, null, 0, 64));
        }

        return preg_match('/^[a-f0-9]{7,64}$/', $head) === 1 ? substr($head, 0, 7) : '';
    }

    private function database(): string
    {
        try {
            $db = Craft::$app->getDb();

            return $db->getDriverName() . ' ' . $db->getServerVersion();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Every enabled plugin with its version: the first thing a developer asks.
     */
    private function plugins(): string
    {
        try {
            /** @var \craft\web\Application|\craft\console\Application $app */
            $app = Craft::$app;
            $plugins = [];

            foreach ($app->getPlugins()->getAllPlugins() as $plugin) {
                $plugins[] = $plugin->id . ' ' . $plugin->getVersion();
            }

            sort($plugins);

            return implode(', ', $plugins);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param array<string, string> $lines
     * @return array<string, string>
     */
    private function prefixed(string $prefix, array $lines): array
    {
        $prefixed = [];

        foreach ($lines as $label => $value) {
            $prefixed[$prefix . ' ' . $label] = $value;
        }

        return $prefixed;
    }
}
