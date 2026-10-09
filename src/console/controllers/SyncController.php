<?php

declare(strict_types=1);

namespace viesrood\igotissues\console\controllers;

use craft\console\Controller;
use viesrood\igotissues\Plugin;
use yii\console\ExitCode;

/**
 * Fetches issue states from GitHub. Meant for cron on a site GitHub cannot
 * reach with a webhook.
 */
class SyncController extends Controller
{
    public $defaultAction = 'index';

    /**
     * Updates the state of every reported issue and reopens the issue of an
     * error that came back after it was closed.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null || !$plugin->getGitHub()->isConfigured()) {
            $this->stderr("No GitHub token or repository is configured.\n");

            return ExitCode::CONFIG;
        }

        $result = $plugin->getReports()->sync(true);

        $this->stdout(sprintf("%d updated, %d reopened.\n", $result['updated'], $result['reopened']));

        return ExitCode::OK;
    }
}
