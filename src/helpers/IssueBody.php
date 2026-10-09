<?php

declare(strict_types=1);

namespace viesrood\igotissues\helpers;

/**
 * Builds the title and markdown body of an issue.
 *
 * The body follows what GitHub itself renders for an issue form: one
 * `### Label` heading per field, `_No response_` for an empty one.
 *
 * @phpstan-import-type Form from IssueForms
 */
final class IssueBody
{
    public const NO_RESPONSE = '_No response_';

    private const TITLE_LENGTH = 240;

    /**
     * @param Form $form
     */
    public static function title(array $form, string $title): string
    {
        $title = trim((string)preg_replace('/\s+/', ' ', $title));
        $prefix = $form['titlePrefix'];

        if (trim($prefix) !== '' && !str_starts_with(mb_strtolower($title), mb_strtolower(trim($prefix)))) {
            $title = $prefix . $title;
        }

        return Scrubber::limit($title, self::TITLE_LENGTH);
    }

    /**
     * @param Form $form
     * @param array<string, string> $values Field id => what the reporter wrote.
     * @param string $report The technical report, see technicalReport().
     */
    public static function render(array $form, array $values, string $report, string $marker): string
    {
        $parts = [];

        foreach ($form['fields'] as $field) {
            $value = trim($values[$field['id']] ?? '');
            $parts[] = '### ' . $field['label'] . "\n\n" . ($value !== '' ? $value : self::NO_RESPONSE);
        }

        if ($report !== '') {
            $parts[] = $report;
        }

        if ($marker !== '') {
            $parts[] = $marker;
        }

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * What goes along with a report without the reporter typing it, folded
     * away under the issue: what the server knows, the server error the
     * reporter ran into, and what the browser saw just before.
     *
     * @param array<string, string> $context Label => value.
     */
    public static function technicalReport(array $context, string $browser = '', string $error = ''): string
    {
        $parts = [];
        $table = self::table($context);

        if ($table !== '') {
            $parts[] = $table;
        }

        if (trim($error) !== '') {
            $parts[] = "**Server error**\n\n" . self::codeBlock($error);
        }

        if (trim($browser) !== '') {
            $parts[] = "**In the browser, before the report**\n\n" . self::codeBlock($browser);
        }

        if ($parts === []) {
            return '';
        }

        return "<details>\n<summary>Technical report</summary>\n\n" . implode("\n\n", $parts) . "\n\n</details>";
    }

    /**
     * The same report as plain text, for showing the reporter what is sent.
     *
     * @param array<string, string> $context
     */
    public static function plainReport(array $context, string $browser = '', string $error = ''): string
    {
        $parts = [];
        $lines = [];

        foreach ($context as $label => $value) {
            if (trim($value) !== '') {
                $lines[] = $label . ': ' . trim($value);
            }
        }

        if ($lines !== []) {
            $parts[] = implode("\n", $lines);
        }

        if (trim($error) !== '') {
            $parts[] = "Server error\n" . trim($error);
        }

        if (trim($browser) !== '') {
            $parts[] = "In the browser, before the report\n" . trim($browser);
        }

        return implode("\n\n", $parts);
    }

    /**
     * The note left on a closed issue whose error came back.
     */
    public static function recurrence(int $occurrences, string $lastSeen, string $marker): string
    {
        return sprintf(
            "**This error happened again.**\n\nSeen %d times in total, most recently on %s.\n\n%s\n",
            $occurrences,
            $lastSeen,
            $marker,
        );
    }

    /**
     * An invisible line that ties an issue back to its report.
     */
    public static function marker(string $uid, ?string $fingerprint = null): string
    {
        // No run of dashes: "--" would end the comment early in some parsers.
        $uid = (string)preg_replace(['/[^A-Za-z0-9-]/', '/-{2,}/'], ['', '-'], $uid);
        $fingerprint = (string)preg_replace('/[^a-f0-9]/', '', (string)$fingerprint);

        return '<!-- igotissues:' . $uid . ($fingerprint !== '' ? ' fp:' . $fingerprint : '') . ' -->';
    }

    /**
     * @param array<string, string> $context
     */
    public static function table(array $context): string
    {
        $rows = [];

        foreach ($context as $label => $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $rows[] = '| ' . self::cell((string)$label) . ' | ' . self::cell($value) . ' |';
        }

        return $rows === [] ? '' : "| | |\n|---|---|\n" . implode("\n", $rows);
    }

    /**
     * A fence longer than any run of backticks inside, so the text cannot
     * close it early.
     */
    public static function codeBlock(string $text): string
    {
        $longest = 0;

        if (preg_match_all('/`+/', $text, $matches) > 0) {
            $longest = max(array_map('strlen', $matches[0]));
        }

        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence . "\n" . trim($text) . "\n" . $fence;
    }

    private static function cell(string $text): string
    {
        $text = (string)preg_replace('/\s*\R\s*/', '<br>', trim($text));

        return str_replace('|', '\|', $text);
    }
}
