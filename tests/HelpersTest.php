<?php

declare(strict_types=1);

namespace viesrood\igotissues\tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use viesrood\igotissues\helpers\Fingerprint;
use viesrood\igotissues\helpers\IssueBody;
use viesrood\igotissues\helpers\IssueForms;
use viesrood\igotissues\helpers\Scrubber;
use viesrood\igotissues\models\Settings;
use viesrood\igotissues\services\Capture;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\ServerErrorHttpException;

/**
 * The pure helpers: what counts as the same error, what is removed before a
 * report leaves the site, and what an issue looks like.
 */
final class HelpersTest extends TestCase
{
    private const FORM = <<<'YAML'
        name: Fout
        description: Iets werkt niet
        title: "Fout: "
        labels: ["fout"]
        body:
          - type: markdown
            attributes:
              value: Dank je.
          - type: textarea
            id: gebeurde
            attributes:
              label: Wat gebeurde er?
              description: Zo precies als je kunt.
            validations:
              required: true
          - type: dropdown
            id: omgeving
            attributes:
              label: Omgeving
              options:
                - Lokaal (DDEV)
                - Productie
            validations:
              required: true
          - type: input
            id: adres
            attributes:
              label: Adres van de pagina
          - type: checkboxes
            id: akkoord
            attributes:
              label: Akkoord
              options:
                - label: Ja
        YAML;

    // Fingerprint

    public function testTheSameErrorFromAnotherCallingLineIsTheSameError(): void
    {
        $trace = static fn(int $callerLine): array => [
            ['file' => '/site/vendor/yiisoft/yii2/base/Component.php', 'line' => 12, 'function' => 'trigger'],
            ['file' => '/site/modules/shop/Cart.php', 'line' => $callerLine, 'class' => 'shop\Cart', 'function' => 'total'],
        ];

        $first = Fingerprint::hash('RuntimeException', 'modules/shop/Price.php', 40, Fingerprint::firstOwnFrame($trace(10), '/site'));
        $second = Fingerprint::hash('RuntimeException', 'modules/shop/Price.php', 40, Fingerprint::firstOwnFrame($trace(99), '/site'));

        self::assertSame($first, $second);
    }

    public function testAnotherClassOrAnotherPlaceIsAnotherError(): void
    {
        $base = Fingerprint::hash('RuntimeException', 'modules/shop/Price.php', 40, 'modules/shop/Cart.php:shop\Cart::total');

        self::assertNotSame($base, Fingerprint::hash('LogicException', 'modules/shop/Price.php', 40, 'modules/shop/Cart.php:shop\Cart::total'));
        self::assertNotSame($base, Fingerprint::hash('RuntimeException', 'modules/shop/Price.php', 41, 'modules/shop/Cart.php:shop\Cart::total'));
        self::assertNotSame($base, Fingerprint::hash('RuntimeException', 'modules/shop/Price.php', 40, 'modules/shop/Order.php:shop\Order::total'));
    }

    public function testTheMessageIsNotPartOfTheFingerprint(): void
    {
        $throw = static fn(string $message): RuntimeException => new RuntimeException($message);

        // Same line, same call: only the message differs.
        $errors = array_map($throw, ['Entry 12 not found', 'Entry 9041 not found']);

        self::assertSame(Fingerprint::of($errors[0], dirname(__DIR__)), Fingerprint::of($errors[1], dirname(__DIR__)));
    }

    public function testTheFirstOwnFrameSkipsVendorAndCompiledTemplates(): void
    {
        $frame = Fingerprint::firstOwnFrame([
            ['file' => '/site/vendor/twig/twig/src/Template.php', 'line' => 1, 'function' => 'render'],
            ['file' => '/site/storage/runtime/compiled_templates/a1/b2.php', 'line' => 9, 'function' => 'doDisplay'],
            ['file' => '/elsewhere/lib.php', 'line' => 3, 'function' => 'outside'],
            ['file' => '/site/modules/shop/Cart.php', 'line' => 7, 'class' => 'shop\Cart', 'function' => 'total'],
        ], '/site');

        self::assertSame('modules/shop/Cart.php:shop\Cart::total', $frame);
    }

    public function testTheRootCauseIsFingerprintedNotItsWrapper(): void
    {
        $cause = new RuntimeException('cause');
        $wrapper = new \LogicException('wrapper', 0, $cause);

        self::assertSame($cause, Fingerprint::root($wrapper));
        self::assertSame(Fingerprint::of($cause, '/x'), Fingerprint::of($wrapper, '/x'));
    }

    public function testTheChainNamesTheWrapperAndTheCause(): void
    {
        $wrapper = new \LogicException('The SQL being executed was: SELECT 1', 0, new RuntimeException('Column not found'));

        self::assertSame(
            ['LogicException: The SQL being executed was: SELECT 1', 'RuntimeException: Column not found'],
            Fingerprint::chain($wrapper),
        );
    }

    public function testATraceCarriesNoArguments(): void
    {
        $exception = (static fn(string $password): RuntimeException => new RuntimeException('x'))('hunter2-secret');
        $trace = Fingerprint::trace($exception, dirname(__DIR__));

        self::assertStringNotContainsString('hunter2', $trace);
        self::assertStringStartsWith('#0 tests/HelpersTest.php:', $trace);
    }

    // What is an error worth recording

    public function testOnlyServerFaultsAreRecorded(): void
    {
        self::assertFalse(Capture::isFault(new NotFoundHttpException()));
        self::assertFalse(Capture::isFault(new ForbiddenHttpException()));
        self::assertTrue(Capture::isFault(new ServerErrorHttpException()));
        self::assertTrue(Capture::isFault(new RuntimeException()));
    }

    // Scrubber

    public function testSecretsAreRemovedFromTextAndUrls(): void
    {
        $secrets = Scrubber::secretsFrom([
            'CRAFT_SECURITY_KEY' => 'k3y-k3y-k3y-k3y',
            'CRAFT_DB_PASSWORD' => 'correct horse',
            'CRAFT_ENVIRONMENT' => 'production',
            'SHORT_TOKEN' => 'abc',
        ]);

        self::assertSame(['k3y-k3y-k3y-k3y', 'correct horse'], $secrets);

        $text = Scrubber::text(
            'SQLSTATE: access denied using correct horse at /var/www/site/modules/a.php, '
            . 'see https://example.com/x?id=5&token=abcdef123456&merkCode=demo and Authorization: Bearer ghp_abcdefghijklmnop '
            . 'mysql://craft:s3cretpass@db/craft key k3y-k3y-k3y-k3y',
            $secrets,
            '/var/www/site',
        );

        self::assertStringNotContainsString('correct horse', $text);
        self::assertStringNotContainsString('abcdef123456', $text);
        self::assertStringNotContainsString('ghp_abcdefghijklmnop', $text);
        self::assertStringNotContainsString('s3cretpass', $text);
        self::assertStringNotContainsString('k3y-k3y-k3y-k3y', $text);
        self::assertStringNotContainsString('/var/www/site', $text);
        self::assertStringContainsString('at modules/a.php', $text);
        self::assertStringContainsString('id=5', $text);
        self::assertStringContainsString('merkCode=demo', $text);
        self::assertStringContainsString('token=' . Scrubber::REMOVED, $text);
    }

    public function testAnOrdinaryWordIsNeverTreatedAsASecret(): void
    {
        self::assertSame('the plan is fine', Scrubber::text('the plan is fine', ['plan']));
    }

    // Issue forms

    public function testAnIssueFormBecomesItsContentFields(): void
    {
        $form = IssueForms::parse('fout.yml', self::FORM);

        self::assertNotNull($form);
        self::assertSame('fout', $form['id']);
        self::assertSame('Fout', $form['name']);
        self::assertSame('Fout: ', $form['titlePrefix']);
        self::assertSame(['fout'], $form['labels']);
        self::assertSame(['gebeurde', 'omgeving', 'adres'], array_column($form['fields'], 'id'));
        self::assertSame([true, true, false], array_column($form['fields'], 'required'));
        self::assertSame(['Lokaal (DDEV)', 'Productie'], $form['fields'][1]['options']);
    }

    public function testTheSiteCanAnswerAFieldItselfAndLeaveAnotherOut(): void
    {
        $form = IssueForms::parse('fout.yml', self::FORM);
        self::assertNotNull($form);

        self::assertSame(['gebeurde', 'omgeving'], array_column(IssueForms::without($form, ['adres'])['fields'], 'id'));

        $context = ['URL' => ' https://example.com/kleuren ', 'Omgeving' => 'Productie', 'Environment' => 'production'];

        self::assertSame('https://example.com/kleuren', IssueForms::autoValue($form['fields'][2], $context, 'URL'));
        self::assertSame('Productie', IssueForms::autoValue($form['fields'][1], $context, 'Omgeving'));
        self::assertSame('', IssueForms::autoValue($form['fields'][1], $context, 'Environment'), 'not one of the options');
        self::assertSame('', IssueForms::autoValue($form['fields'][2], $context, 'No such line'));
        self::assertSame('', IssueForms::autoValue($form['fields'][2], $context, ['not a label']));
    }

    public function testWhatIsNotAnIssueFormIsRefused(): void
    {
        self::assertNull(IssueForms::parse('config.yml', 'blank_issues_enabled: false'));
        self::assertNull(IssueForms::parse('broken.yml', "body: [unclosed"));
        self::assertNull(IssueForms::parse('empty.yml', "name: x\nbody:\n  - type: markdown\n    attributes:\n      value: hi\n"));
    }

    public function testLabelsMayBeOneCommaSeparatedString(): void
    {
        $form = IssueForms::parse('a.yml', "labels: bug, urgent\nbody:\n  - type: input\n    attributes:\n      label: What\n");

        self::assertNotNull($form);
        self::assertSame(['bug', 'urgent'], $form['labels']);
        self::assertSame('field0', $form['fields'][0]['id']);
    }

    // Issue body

    public function testTheBodyReadsLikeAnIssueFiledThroughTheForm(): void
    {
        $form = IssueForms::parse('fout.yml', self::FORM);
        self::assertNotNull($form);

        $report = IssueBody::technicalReport(
            ['URL' => 'https://example.com/a|b', 'Empty' => ''],
            "12:00:01  error    TypeError: x is undefined",
            "RuntimeException: boom\n\n#0 modules/a.php:3",
        );
        $body = IssueBody::render(
            $form,
            ['gebeurde' => 'De pagina bleef wit.', 'omgeving' => 'Productie'],
            $report,
            IssueBody::marker('1b2c-3d', 'abc123'),
        );

        self::assertStringContainsString("### Wat gebeurde er?\n\nDe pagina bleef wit.", $body);
        self::assertStringContainsString("### Adres van de pagina\n\n" . IssueBody::NO_RESPONSE, $body);
        self::assertStringContainsString('| URL | https://example.com/a\|b |', $body);
        self::assertStringNotContainsString('Empty', $body);
        self::assertStringEndsWith("<!-- igotissues:1b2c-3d fp:abc123 -->\n", $body);
    }

    public function testTheTechnicalReportIsFoldedAwayUnderWhatTheReporterWrote(): void
    {
        $report = IssueBody::technicalReport(['Craft' => '5.11'], '12:00:01  click    button "Save"', 'RuntimeException: boom');

        self::assertStringStartsWith("<details>\n<summary>Technical report</summary>", $report);
        self::assertStringEndsWith('</details>', $report);
        self::assertStringContainsString("**Server error**\n\n```\nRuntimeException: boom\n```", $report);
        self::assertStringContainsString("**In the browser, before the report**\n\n```\n12:00:01  click", $report);
        self::assertSame('', IssueBody::technicalReport(['Empty' => ''], ' ', ''));
    }

    public function testTheReporterCanReadTheSameReportAsPlainText(): void
    {
        $plain = IssueBody::plainReport(['Craft' => '5.11', 'Empty' => ''], 'click', 'RuntimeException: boom');

        self::assertSame("Craft: 5.11\n\nServer error\nRuntimeException: boom\n\nIn the browser, before the report\nclick", $plain);
    }

    public function testTheTitleGetsTheFormPrefixOnce(): void
    {
        $form = IssueForms::parse('fout.yml', self::FORM);
        self::assertNotNull($form);

        self::assertSame('Fout: witte pagina', IssueBody::title($form, "witte\n pagina"));
        self::assertSame('fout: witte pagina', IssueBody::title($form, 'fout: witte pagina'));
    }

    public function testTextCannotBreakOutOfACodeBlockOrTheMarker(): void
    {
        $block = IssueBody::codeBlock("before\n```\n@everyone\n````\nafter");

        self::assertStringStartsWith("`````\n", $block);
        self::assertStringEndsWith("\n`````", $block);
        self::assertSame('<!-- igotissues:abc-script -->', IssueBody::marker('abc --><script>', null));
    }

    // Settings

    public function testCredentialsDefaultToEnvReferences(): void
    {
        $settings = new Settings();

        self::assertSame('$IGOTISSUES_GITHUB_TOKEN', $settings->githubToken);
        self::assertSame('$IGOTISSUES_GITHUB_REPO', $settings->githubRepo);
        self::assertFalse($settings->captureInDevMode);
        self::assertFalse($settings->autoInject);
    }

    public function testOnlyAnOwnerAndNameIsARepository(): void
    {
        $settings = new Settings();

        foreach (['viesrood/brandroot' => 'viesrood/brandroot', 'viesrood' => '', 'a/b/c' => '', '../x/y' => '', 'a b/c' => ''] as $given => $expected) {
            $settings->githubRepo = $given;
            self::assertSame($expected, $settings->getParsedGithubRepo(), $given);
        }

        $settings->githubToken = '';
        self::assertFalse($settings->hasGitHub());
    }

    public function testTheTokenOnlyGoesToAnHttpsAddressOrThisMachine(): void
    {
        $settings = new Settings();
        self::assertSame('https://api.github.com/', $settings->getParsedGithubApiUrl());

        $cases = [
            'https://ghe.example.com/api/v3' => 'https://ghe.example.com/api/v3/',
            'http://127.0.0.1:8898' => 'http://127.0.0.1:8898/',
            'http://ghe.example.com/api/v3/' => Settings::GITHUB_API,
            'https://user:pass@evil.example/' => Settings::GITHUB_API,
            'ftp://example.com/' => Settings::GITHUB_API,
            'not a url' => Settings::GITHUB_API,
            '' => Settings::GITHUB_API,
        ];

        foreach ($cases as $given => $expected) {
            $settings->githubApiUrl = $given;
            self::assertSame($expected, $settings->getParsedGithubApiUrl(), $given);
        }
    }
}
