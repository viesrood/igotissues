<?php

declare(strict_types=1);

namespace viesrood\igotissues\web;

use Craft;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use Throwable;
use viesrood\igotissues\Plugin;
use viesrood\igotissues\web\assets\widget\WidgetAsset;

/**
 * The report button and its window, for the control panel and the front end.
 *
 * The markup is one empty element carrying its configuration, a stylesheet
 * and a script. The script builds everything else, so the same widget works
 * in any template, with no dependency on the site's own CSS or JavaScript.
 */
final class Widget
{
    private static bool $rendered = false;

    /**
     * The widget for the current user, or '' when they may not report, when
     * the plugin cannot reach GitHub, or when it is already on the page.
     */
    public static function html(): string
    {
        if (self::$rendered) {
            return '';
        }

        try {
            /** @var \craft\web\Application $app */
            $app = Craft::$app;
            $plugin = Plugin::getInstance();
            /** @var \craft\elements\User|null $user */
            $user = $app->getUser()->getIdentity();

            if ($plugin === null || $user === null || !$plugin->getReports()->canReport($user)) {
                return '';
            }

            if (!$plugin->getGitHub()->isConfigured()) {
                return '';
            }

            $settings = $plugin->getSettings();
            $request = $app->getRequest();
            $general = $app->getConfig()->getGeneral();
            $recent = $plugin->getReports()->recentErrorFor($user);

            $config = [
                'urls' => [
                    'forms' => UrlHelper::actionUrl('igotissues/reports/forms'),
                    'send' => UrlHelper::actionUrl('igotissues/reports/send'),
                    'mine' => UrlHelper::actionUrl('igotissues/reports/mine'),
                ],
                'csrf' => $general->enableCsrfProtection
                    ? ['name' => $general->csrfTokenName, 'value' => $request->getCsrfToken()]
                    : null,
                'position' => $settings->position,
                'recentError' => $recent !== null ? ['id' => $recent->id, 'title' => $recent->title] : null,
                't' => self::strings(),
            ];

            $base = $app->getAssetManager()->getPublishedUrl((new WidgetAsset())->sourcePath, true);

            if (!is_string($base)) {
                return '';
            }

            self::$rendered = true;

            return Html::tag('div', '', ['id' => 'igotissues', 'data' => ['config' => Json::encode($config)]])
                . Html::cssFile($base . '/igotissues.css')
                . Html::jsFile($base . '/igotissues.js', ['defer' => true]);
        } catch (Throwable $e) {
            // A report button is never worth a broken page.
            Craft::warning('I got issues: the widget could not render: ' . $e->getMessage(), __METHOD__);

            return '';
        }
    }

    /**
     * Every string the script shows, translated here so the front end does
     * not need Craft's own JavaScript.
     *
     * @return array<string, string>
     */
    private static function strings(): array
    {
        return [
            'button' => Craft::t('igotissues', 'Report something'),
            'title' => Craft::t('igotissues', 'Report a problem or a wish'),
            'close' => Craft::t('igotissues', 'Close'),
            'loading' => Craft::t('igotissues', 'Loading…'),
            'intro' => Craft::t('igotissues', 'Describe it the way you would tell a colleague. Technical details are added for you.'),
            'kind' => Craft::t('igotissues', 'Kind of report'),
            'issueTitle' => Craft::t('igotissues', 'Title'),
            'required' => Craft::t('igotissues', 'required'),
            'choose' => Craft::t('igotissues', 'Choose…'),
            'send' => Craft::t('igotissues', 'Send report'),
            'sending' => Craft::t('igotissues', 'Sending…'),
            'sent' => Craft::t('igotissues', 'Thank you. Your report is #{number}.'),
            'view' => Craft::t('igotissues', 'View on GitHub'),
            'another' => Craft::t('igotissues', 'Report something else'),
            'mine' => Craft::t('igotissues', 'My reports'),
            'newReport' => Craft::t('igotissues', 'New report'),
            'noReports' => Craft::t('igotissues', 'You have not reported anything yet.'),
            'open' => Craft::t('igotissues', 'Open'),
            'closed' => Craft::t('igotissues', 'Closed'),
            'failed' => Craft::t('igotissues', 'That did not work. Try again in a moment.'),
            'recentError' => Craft::t('igotissues', 'Something went wrong just now. Tell us what you were doing?'),
            'includeError' => Craft::t('igotissues', 'Include the error that just happened'),
            'whatIsSent' => Craft::t('igotissues', 'What is sent along'),
            'whatIsSentHelp' => Craft::t('igotissues', 'This technical report goes with your report, so the developers can see what happened. It contains no passwords and nothing you typed into the site.'),
        ];
    }
}
