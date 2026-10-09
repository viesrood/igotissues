<?php

declare(strict_types=1);

namespace viesrood\igotissues\records;

use craft\db\ActiveRecord;

/**
 * One report: something a person sent, or one distinct error the site recorded.
 *
 * @property int $id
 * @property string $source
 * @property string $status
 * @property string|null $fingerprint
 * @property int $occurrences
 * @property int $notifiedOccurrences
 * @property string $firstSeen
 * @property string $lastSeen
 * @property string $title
 * @property string|null $body
 * @property string|null $context
 * @property int|null $userId
 * @property int|null $siteId
 * @property int|null $issueNumber
 * @property string|null $issueUrl
 * @property string|null $issueState
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ReportRecord extends ActiveRecord
{
    public const TABLE = '{{%igotissues_reports}}';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_ERROR = 'error';

    /** Recorded, no issue yet. */
    public const STATUS_CAPTURED = 'captured';
    /** Has an issue on GitHub. */
    public const STATUS_SENT = 'sent';
    /** Someone decided this needs no issue. */
    public const STATUS_IGNORED = 'ignored';

    public static function tableName(): string
    {
        return self::TABLE;
    }

    /**
     * @return array<string, string>
     */
    public function contextLines(): array
    {
        $decoded = is_string($this->context) ? json_decode($this->context, true) : null;
        $lines = [];

        foreach (is_array($decoded) ? $decoded : [] as $label => $value) {
            if (is_scalar($value)) {
                $lines[(string)$label] = (string)$value;
            }
        }

        return $lines;
    }
}
