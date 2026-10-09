<?php

declare(strict_types=1);

namespace viesrood\igotissues\migrations;

use craft\db\Migration;
use craft\db\Table;
use viesrood\igotissues\records\ReportRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(ReportRecord::TABLE)) {
            return true;
        }

        $this->createTable(ReportRecord::TABLE, [
            'id' => $this->primaryKey(),
            'source' => $this->string(10)->notNull(),
            'status' => $this->string(10)->notNull(),
            'fingerprint' => $this->char(40),
            'occurrences' => $this->integer()->notNull()->defaultValue(1),
            // The count at the moment GitHub last heard about this report.
            'notifiedOccurrences' => $this->integer()->notNull()->defaultValue(0),
            'firstSeen' => $this->dateTime()->notNull(),
            'lastSeen' => $this->dateTime()->notNull(),
            'title' => $this->string(255)->notNull(),
            'body' => $this->mediumText(),
            'context' => $this->text(),
            'userId' => $this->integer(),
            'siteId' => $this->integer(),
            'issueNumber' => $this->integer(),
            'issueUrl' => $this->string(255),
            'issueState' => $this->string(10),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, ReportRecord::TABLE, ['fingerprint']);
        $this->createIndex(null, ReportRecord::TABLE, ['status', 'lastSeen']);
        $this->createIndex(null, ReportRecord::TABLE, ['userId', 'lastSeen']);
        $this->createIndex(null, ReportRecord::TABLE, ['issueNumber']);

        // A report outlives the user who sent it and the site it was sent from.
        $this->addForeignKey(null, ReportRecord::TABLE, ['userId'], Table::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, ReportRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'SET NULL');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(ReportRecord::TABLE);

        return true;
    }
}
