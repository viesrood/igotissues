<?php

declare(strict_types=1);

namespace viesrood\igotissues\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use Throwable;
use viesrood\igotissues\events\CaptureEvent;
use viesrood\igotissues\helpers\Fingerprint;
use viesrood\igotissues\helpers\Scrubber;
use viesrood\igotissues\Plugin;
use viesrood\igotissues\records\ReportRecord;
use yii\db\Expression;
use yii\web\HttpException;

/**
 * Records unexpected errors, one row per distinct error.
 *
 * This runs while the site is already failing, so it does as little as it
 * can: one read and one write, no HTTP, and it never throws. An error in the
 * recorder must not replace the error it was recording.
 */
class Capture extends Component
{
    /**
     * @event CaptureEvent Raised before an error is recorded.
     */
    public const EVENT_BEFORE_CAPTURE = 'beforeCapture';

    /**
     * Whether this exception is a fault worth recording, as opposed to the
     * site doing its job (a 404, a 403, a failed form validation).
     */
    public static function isFault(Throwable $exception): bool
    {
        return !($exception instanceof HttpException) || $exception->statusCode >= 500;
    }

    public function capture(Throwable $exception): void
    {
        try {
            $this->record($exception);
        } catch (Throwable $e) {
            try {
                Craft::warning('I got issues: could not record an error: ' . $e->getMessage(), __METHOD__);
            } catch (Throwable) {
                // Logging is broken too. There is nothing left to tell.
            }
        }
    }

    private function record(Throwable $exception): void
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null || !self::isFault($exception)) {
            return;
        }

        /** @var \craft\web\Application|\craft\console\Application $app */
        $app = Craft::$app;
        $settings = $plugin->getSettings();
        $request = $app->getRequest();
        $isConsole = $request->getIsConsoleRequest();

        if (
            !$settings->captureErrors
            || ($isConsole && !$settings->captureConsole)
            || ($app->getConfig()->getGeneral()->devMode && !$settings->captureInDevMode)
        ) {
            return;
        }

        if ($this->hasEventHandlers(self::EVENT_BEFORE_CAPTURE)) {
            $event = new CaptureEvent(['exception' => $exception]);
            $this->trigger(self::EVENT_BEFORE_CAPTURE, $event);

            if (!$event->isValid) {
                return;
            }
        }

        $reports = $plugin->getReports();
        $basePath = $reports->basePath();
        $fingerprint = Fingerprint::of($exception, $basePath);
        $now = Db::prepareDateForDb(new DateTime());
        $db = $app->getDb();

        $userId = null;
        $siteId = null;

        if (!$isConsole) {
            try {
                $userId = $app->getUser()->getId();
                $siteId = $app->getSites()->getCurrentSite()->id;
            } catch (Throwable) {
                // The error came before Craft knew who or where. Record it anyway.
            }
        }

        $again = [
            'occurrences' => new Expression('[[occurrences]] + 1'),
            'lastSeen' => $now,
            'dateUpdated' => $now,
        ];

        // The latest person to run into it is the one who can still say what
        // they were doing.
        if ($userId !== null) {
            $again['userId'] = $userId;
        }

        // One statement, so two requests failing at once cannot both miss.
        $updated = $db->createCommand()->update(
            ReportRecord::TABLE,
            $again,
            ['source' => ReportRecord::SOURCE_ERROR, 'fingerprint' => $fingerprint],
            [],
            false,
        )->execute();

        if ($updated > 0) {
            return;
        }

        $root = Fingerprint::root($exception);
        $title = $reports->scrub(sprintf('%s: %s', $root::class, $root->getMessage()));
        $context = ['Where' => $isConsole ? 'console' : 'web'];

        if (!$isConsole) {
            /** @var \craft\web\Request $request */
            $context['URL'] = $reports->scrub($request->getAbsoluteUrl());
            $context['Method'] = $request->getMethod();
        }

        $db->createCommand()->insert(ReportRecord::TABLE, [
            'source' => ReportRecord::SOURCE_ERROR,
            'status' => ReportRecord::STATUS_CAPTURED,
            'fingerprint' => $fingerprint,
            'occurrences' => 1,
            'notifiedOccurrences' => 0,
            'firstSeen' => $now,
            'lastSeen' => $now,
            'title' => Scrubber::limit($title, 255),
            'body' => $reports->scrub(implode("\n", Fingerprint::chain($exception)) . "\n\n" . Fingerprint::trace($exception, $basePath)),
            'context' => json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE),
            'userId' => $userId,
            'siteId' => $siteId,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }
}
