<?php

declare(strict_types=1);

namespace viesrood\igotissues\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use InvalidArgumentException;
use Throwable;
use viesrood\igotissues\helpers\RateLimit;
use viesrood\igotissues\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Backend of the report window, for the control panel and the front end alike.
 *
 * Credentials never leave the server, and neither does anything GitHub said
 * in an error: the browser gets a generic sentence, the log gets the detail.
 */
class ReportsController extends Controller
{
    /** Reports per minute per user. A person needs one or two. */
    private const RATE_LIMIT = 10;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!$this->plugin()->getReports()->canReport($this->user())) {
            throw new ForbiddenHttpException('User is not permitted to report.');
        }

        return true;
    }

    /**
     * What the window needs to open: the forms of the repository, the error
     * this user just ran into, and the technical report that would go along.
     */
    public function actionForms(): Response
    {
        $user = $this->user();
        $plugin = $this->plugin();
        $reports = $plugin->getReports();
        $error = $reports->errorFor($this->reportId()) ?? $reports->recentErrorFor($user);

        return $this->asJson([
            'success' => true,
            'forms' => $reports->visibleForms($plugin->getGitHub()->forms()),
            'error' => $error !== null ? ['id' => $error->id, 'title' => $error->title] : null,
            'preview' => $reports->preview($this->page(), $user, $error),
        ]);
    }

    /**
     * Send the report to GitHub.
     */
    public function actionSend(): Response
    {
        $user = $this->user();

        if (!RateLimit::hit('send', (int)$user->id, self::RATE_LIMIT)) {
            return $this->failure(Craft::t('igotissues', 'Too many requests. Wait a moment and try again.'), 429);
        }

        try {
            $record = $this->plugin()->getReports()->send($user, [
                'form' => $this->request->getBodyParam('form'),
                'title' => $this->request->getBodyParam('title'),
                'fields' => $this->request->getBodyParam('fields'),
                'reportId' => $this->reportId(),
            ], $this->page());
        } catch (InvalidArgumentException $e) {
            return $this->failure($e->getMessage());
        } catch (Throwable $e) {
            Craft::error('I got issues: a report could not be sent: ' . $e->getMessage(), __METHOD__);

            return $this->failure(Craft::t('igotissues', 'The report could not be sent to GitHub. Try again in a moment.'), 502);
        }

        return $this->asJson([
            'success' => true,
            'number' => $record->issueNumber,
            'url' => $record->issueUrl,
        ]);
    }

    /**
     * What this user reported before, and what became of it.
     */
    public function actionMine(): Response
    {
        $reports = $this->plugin()->getReports();
        $reports->sync();

        $mine = [];

        foreach ($reports->mine($this->user()) as $record) {
            $mine[] = [
                'title' => $record->title,
                'number' => $record->issueNumber,
                'url' => $record->issueUrl,
                'state' => $record->issueState,
            ];
        }

        return $this->asJson(['success' => true, 'reports' => $mine]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function page(): array
    {
        $page = $this->request->getBodyParam('page');

        return is_array($page) ? $page : [];
    }

    private function reportId(): ?int
    {
        $id = $this->request->getBodyParam('reportId');

        return is_numeric($id) ? (int)$id : null;
    }

    private function user(): User
    {
        $user = static::currentUser();

        if ($user === null) {
            throw new ForbiddenHttpException('Login required.');
        }

        return $user;
    }

    private function plugin(): Plugin
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new ForbiddenHttpException('The plugin is not available.');
        }

        return $plugin;
    }

    private function failure(string $message, int $status = 400): Response
    {
        $this->response->setStatusCode($status);

        return $this->asJson(['success' => false, 'error' => $message]);
    }
}
