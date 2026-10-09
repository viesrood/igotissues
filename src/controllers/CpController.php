<?php

declare(strict_types=1);

namespace viesrood\igotissues\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use Throwable;
use viesrood\igotissues\Plugin;
use viesrood\igotissues\records\ReportRecord;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Reports section of the control panel: what people sent, what the site
 * recorded, and what became of it on GitHub.
 */
class CpController extends Controller
{
    private const PER_PAGE = 50;

    private const FILTERS = ['all', 'waiting', 'sent', 'ignored'];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        if (!$this->plugin()->getReports()->canReport(static::currentUser())) {
            throw new ForbiddenHttpException('User is not permitted to view reports.');
        }

        if (in_array($action->id, ['ignore', 'test'], true)) {
            $this->requireAdmin(false);
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = $this->plugin();
        $plugin->getReports()->sync();

        $filter = (string)$this->request->getQueryParam('show', 'all');
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
        $query = ReportRecord::find()->orderBy(['lastSeen' => SORT_DESC])->limit(self::PER_PAGE);

        match ($filter) {
            'waiting' => $query->where(['status' => ReportRecord::STATUS_CAPTURED]),
            'sent' => $query->where(['status' => ReportRecord::STATUS_SENT]),
            'ignored' => $query->where(['status' => ReportRecord::STATUS_IGNORED]),
            default => $query->where(['not', ['status' => ReportRecord::STATUS_IGNORED]]),
        };

        /** @var ReportRecord[] $records */
        $records = $query->all();

        return $this->renderTemplate('igotissues/reports/index', [
            'records' => $records,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'reporters' => $this->reporters($records),
            'configured' => $plugin->getGitHub()->isConfigured(),
            'repo' => $plugin->getSettings()->getParsedGithubRepo(),
        ]);
    }

    public function actionView(int $id): Response
    {
        $record = $this->record($id);

        return $this->renderTemplate('igotissues/reports/_detail', [
            'record' => $record,
            'context' => $record->contextLines(),
            'reporters' => $this->reporters([$record]),
            'configured' => $this->plugin()->getGitHub()->isConfigured(),
        ]);
    }

    /**
     * Decide that a recorded error needs no issue, or undo that.
     */
    public function actionIgnore(): Response
    {
        $this->requirePostRequest();

        $record = $this->record((int)$this->request->getRequiredBodyParam('id'));
        $this->plugin()->getReports()->ignore($record, (bool)$this->request->getBodyParam('ignored', true));

        return $this->redirectToPostedUrl();
    }

    /**
     * Fetch the issue states now instead of at the next page view.
     */
    public function actionSync(): Response
    {
        $this->requirePostRequest();

        $result = $this->plugin()->getReports()->sync(true);
        $this->setSuccessFlash(Craft::t('igotissues', '{updated} updated, {reopened} reopened.', $result));

        return $this->redirectToPostedUrl();
    }

    /**
     * Check the token, the repository and the labels the forms ask for.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $github = $this->plugin()->getGitHub();

        if (!$github->isConfigured()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('igotissues', 'Set the GitHub token and the repository (owner/name) first.'),
            ]);
        }

        try {
            $github->clearCache();
            $repository = $github->api()->repository();
            $known = $github->api()->labels();
        } catch (Throwable $e) {
            Craft::warning('I got issues: connection test failed: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('igotissues', 'GitHub did not accept the token for this repository. The token needs Issues (read and write), Contents (read) and Metadata.'),
            ]);
        }

        $forms = $github->forms();
        $wanted = array_unique(array_merge(...array_map(static fn(array $form): array => $form['labels'], $forms)));

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('igotissues', 'Connected to {repo}. Forms: {forms}.', [
                'repo' => $repository['name'],
                'forms' => implode(', ', array_map(static fn(array $form): string => $form['name'], $forms)),
            ]),
            'missingLabels' => array_values(array_diff($wanted, $known)),
        ]);
    }

    /**
     * @param ReportRecord[] $records
     * @return array<int, string> User id => name.
     */
    private function reporters(array $records): array
    {
        $names = [];

        foreach ($records as $record) {
            if ($record->userId === null || isset($names[$record->userId])) {
                continue;
            }

            $user = User::find()->id($record->userId)->status(null)->one();
            $names[$record->userId] = $user !== null ? $user->getName() : '';
        }

        return $names;
    }

    private function record(int $id): ReportRecord
    {
        /** @var ReportRecord|null $record */
        $record = ReportRecord::findOne($id);

        if ($record === null) {
            throw new NotFoundHttpException('Report not found.');
        }

        return $record;
    }

    private function plugin(): Plugin
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new ForbiddenHttpException('The plugin is not available.');
        }

        return $plugin;
    }
}
