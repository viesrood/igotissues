<?php

declare(strict_types=1);

namespace viesrood\igotissues;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\console\ErrorHandler as ConsoleErrorHandler;
use craft\events\ExceptionEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\utilities\ClearCaches;
use craft\web\ErrorHandler as WebErrorHandler;
use craft\web\UrlManager;
use craft\web\View;
use viesrood\igotissues\models\Settings;
use viesrood\igotissues\services\Capture;
use viesrood\igotissues\services\GitHub;
use viesrood\igotissues\services\Reports;
use viesrood\igotissues\web\twig\IGotIssuesExtension;
use viesrood\igotissues\web\Widget;
use yii\base\Event;

/**
 * I got issues.
 *
 * Turns what people run into on a site into GitHub issues:
 * - a report button in the control panel and on the front end, where someone
 *   describes a problem or a wish in their own words;
 * - the report takes the shape of the repository's own issue forms, and a
 *   technical report goes along with it: what the server knows and what the
 *   browser saw just before, visible to the reporter before it is sent;
 * - unexpected errors are recorded once per distinct error, with a count, and
 *   wait in the control panel until someone makes an issue of them.
 *
 * @property-read Capture $capture
 * @property-read GitHub $github
 * @property-read Reports $reports
 */
class Plugin extends BasePlugin
{
    /** Cache tag for everything the plugin caches from GitHub. */
    public const CACHE_TAG = 'igotissues';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    /**
     * @return array{components: array<string, mixed>}
     */
    public static function config(): array
    {
        return [
            'components' => [
                'capture' => Capture::class,
                'github' => GitHub::class,
                'reports' => Reports::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'viesrood\\igotissues\\console\\controllers';
        }

        $this->registerCapture();
        $this->registerTwigExtension();
        $this->registerWidget();
        $this->registerCpUrlRules();
        $this->registerCacheOptions();
    }

    public function getCapture(): Capture
    {
        /** @var Capture $service */
        $service = $this->get('capture');

        return $service;
    }

    public function getGitHub(): GitHub
    {
        /** @var GitHub $service */
        $service = $this->get('github');

        return $service;
    }

    public function getReports(): Reports
    {
        /** @var Reports $service */
        $service = $this->get('reports');

        return $service;
    }

    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        return $settings;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        /** @var \craft\elements\User|null $user */
        $user = Craft::$app->getUser()->getIdentity();

        if (!$this->getReports()->canReport($user)) {
            return null;
        }

        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $item['label'] = Craft::t('igotissues', 'Reports');
        $item['badgeCount'] = $this->getReports()->pendingCount();

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        /** @var \craft\web\View $view */
        $view = Craft::$app->getView();

        return $view->renderTemplate('igotissues/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ], View::TEMPLATE_MODE_CP);
    }

    private function registerCapture(): void
    {
        $handler = static function (ExceptionEvent $event): void {
            self::getInstance()?->getCapture()->capture($event->exception);
        };

        Event::on(WebErrorHandler::class, WebErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION, $handler);
        Event::on(ConsoleErrorHandler::class, ConsoleErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION, $handler);
    }

    private function registerTwigExtension(): void
    {
        /** @var \craft\web\Application|\craft\console\Application $app */
        $app = Craft::$app;

        $app->onInit(static function (): void {
            /** @var \craft\web\View $view */
            $view = Craft::$app->getView();
            $view->registerTwigExtension(new IGotIssuesExtension());
        });
    }

    /**
     * The control panel always gets the button. The front end gets it through
     * this hook only when asked to; most templates call igotissues_widget().
     */
    private function registerWidget(): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_END_BODY,
            static function (): void {
                $plugin = self::getInstance();
                /** @var \craft\web\Request $request */
                $request = Craft::$app->getRequest();

                if ($plugin === null || $request->getIsActionRequest()) {
                    return;
                }

                if ($request->getIsCpRequest() || ($request->getIsSiteRequest() && $plugin->getSettings()->autoInject)) {
                    echo Widget::html();
                }
            }
        );
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['igotissues'] = 'igotissues/cp/index';
                $event->rules['igotissues/<id:\d+>'] = 'igotissues/cp/view';
            }
        );
    }

    private function registerCacheOptions(): void
    {
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_TAG_OPTIONS,
            static function (RegisterCacheOptionsEvent $event): void {
                $event->options[] = [
                    'tag' => self::CACHE_TAG,
                    'label' => Craft::t('igotissues', 'I got issues: forms, labels and issues from GitHub'),
                ];
            }
        );
    }
}
