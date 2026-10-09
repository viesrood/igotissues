<?php

declare(strict_types=1);

namespace viesrood\igotissues\helpers;

use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads GitHub issue forms (.github/ISSUE_TEMPLATE/*.yml) into the shape the
 * report window and the issue body are built from.
 *
 * The repository's own forms decide what an issue looks like, so an issue
 * made here reads like one filed through GitHub's form.
 *
 * @phpstan-type Field array{id: string, label: string, description: string, type: string, required: bool, options: string[]}
 * @phpstan-type Form array{id: string, name: string, description: string, titlePrefix: string, labels: string[], fields: list<Field>}
 */
final class IssueForms
{
    private const TYPES = ['textarea', 'input', 'dropdown'];

    /**
     * @return Form|null Null when the file is not a usable issue form.
     */
    public static function parse(string $filename, string $yaml): ?array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data) || !is_array($data['body'] ?? null)) {
            return null;
        }

        $fields = [];

        foreach ($data['body'] as $index => $item) {
            if (!is_array($item) || !in_array($item['type'] ?? null, self::TYPES, true)) {
                // markdown is explanation, checkboxes are consent: neither is content.
                continue;
            }

            $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
            $label = trim(self::string($attributes['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $options = [];

            foreach (is_array($attributes['options'] ?? null) ? $attributes['options'] : [] as $option) {
                if (is_scalar($option)) {
                    $options[] = (string)$option;
                }
            }

            $fields[] = [
                'id' => self::slug(self::string($item['id'] ?? ''), 'field' . $index),
                'label' => $label,
                'description' => trim(self::string($attributes['description'] ?? '')),
                'type' => (string)$item['type'],
                'required' => ($item['validations']['required'] ?? false) === true,
                'options' => $options,
            ];
        }

        if ($fields === []) {
            return null;
        }

        $id = self::slug(pathinfo($filename, PATHINFO_FILENAME), 'form');

        return [
            'id' => $id,
            'name' => trim(self::string($data['name'] ?? '')) ?: $id,
            'description' => trim(self::string($data['description'] ?? '')),
            'titlePrefix' => self::string($data['title'] ?? ''),
            'labels' => self::labels($data['labels'] ?? []),
            'fields' => $fields,
        ];
    }

    /**
     * Used when the repository has no issue forms.
     *
     * @return list<Form>
     */
    public static function builtin(): array
    {
        $field = static fn(string $id, string $label, bool $required = true): array => [
            'id' => $id,
            'label' => $label,
            'description' => '',
            'type' => 'textarea',
            'required' => $required,
            'options' => [],
        ];

        return [
            [
                'id' => 'bug',
                'name' => 'Bug',
                'description' => 'Something does not work as it should.',
                'titlePrefix' => '',
                'labels' => ['bug'],
                'fields' => [
                    $field('what', 'What happened?'),
                    $field('expected', 'What did you expect?'),
                    $field('steps', 'Steps to reproduce', false),
                ],
            ],
            [
                'id' => 'feature',
                'name' => 'Feature request',
                'description' => 'Something you would like to be able to do.',
                'titlePrefix' => '',
                'labels' => ['enhancement'],
                'fields' => [
                    $field('what', 'What would you like?'),
                    $field('why', 'Why?'),
                ],
            ],
        ];
    }

    /**
     * @param list<Form> $forms
     * @return Form|null
     */
    public static function find(array $forms, string $id): ?array
    {
        foreach ($forms as $form) {
            if ($form['id'] === $id) {
                return $form;
            }
        }

        return null;
    }

    /**
     * The form without the given fields.
     *
     * @param Form $form
     * @param array<array-key, mixed> $fieldIds
     * @return Form
     */
    public static function without(array $form, array $fieldIds): array
    {
        $form['fields'] = array_values(array_filter(
            $form['fields'],
            static fn(array $field): bool => !in_array($field['id'], $fieldIds, true),
        ));

        return $form;
    }

    /**
     * What the site itself answers for a field: the context line with the
     * given label. A dropdown only takes one of its own options, so the issue
     * never claims a choice the form does not offer.
     *
     * @param Field $field
     * @param array<string, string> $context
     */
    public static function autoValue(array $field, array $context, mixed $label): string
    {
        $value = is_string($label) ? trim($context[$label] ?? '') : '';

        if ($field['type'] === 'dropdown' && !in_array($value, $field['options'], true)) {
            return '';
        }

        return $value;
    }

    /**
     * GitHub accepts labels as a list or as one comma-separated string.
     *
     * @return string[]
     */
    private static function labels(mixed $labels): array
    {
        if (is_string($labels)) {
            $labels = explode(',', $labels);
        }

        if (!is_array($labels)) {
            return [];
        }

        $clean = [];

        foreach ($labels as $label) {
            if (is_scalar($label) && trim((string)$label) !== '') {
                $clean[] = trim((string)$label);
            }
        }

        return array_values(array_unique($clean));
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    private static function slug(string $value, string $fallback): string
    {
        $slug = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', $value), '-');

        return $slug !== '' ? $slug : $fallback;
    }
}
