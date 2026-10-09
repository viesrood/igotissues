<?php

declare(strict_types=1);

namespace viesrood\igotissues\tests;

use PHPUnit\Framework\TestCase;
use viesrood\igotissues\models\Settings;

/**
 * Craft namespaces plugin settings forms itself, so the fields must use plain
 * names. A `settings[foo]` name double-namespaces and silently stops saving.
 */
final class SettingsTemplateTest extends TestCase
{
    private static function template(): string
    {
        return (string)file_get_contents(dirname(__DIR__) . '/src/templates/settings.twig');
    }

    public function testFieldNamesAreNotDoubleNamespaced(): void
    {
        self::assertStringNotContainsString("name: 'settings[", self::template());
    }

    public function testEverySettingHasAField(): void
    {
        $template = self::template();
        $settings = new Settings();

        foreach (array_keys($settings->getAttributes()) as $attribute) {
            self::assertStringContainsString(
                sprintf("name: '%s'", $attribute),
                $template,
                sprintf('Setting "%s" has no control panel field', $attribute),
            );
        }
    }
}
