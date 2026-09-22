<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\migrations;

use craft\db\Migration;
use craft\db\Query;
use yii\base\Exception;

/**
 * Makes the stored reference globally unique. 1.0.0 indexed `normalised`
 * non-uniquely, so two orders whose numbers reduced to the same digits could
 * store the same reference and an incoming bank statement line could not be
 * matched back to a single order. Uniqueness is global rather than per
 * account because References::findOrder() looks a reference up without
 * knowing which account it was paid into, so two accounts sharing one
 * reference would be just as ambiguous as two orders on one account.
 * References::ensure() now walks a candidate list until it finds a free one,
 * so the index is always satisfiable for a new order.
 */
class m260922_150000_unique_reference extends Migration
{
    public function safeUp(): bool
    {
        $table = Install::TABLE;
        $this->requireNoDuplicates($table);
        $this->dropIndexesOn($table, ['normalised']);
        $this->dropIndexesOn($table, ['accountKey', 'normalised']);
        $this->createIndex(null, $table, ['normalised'], true);
        return true;
    }

    public function safeDown(): bool
    {
        $table = Install::TABLE;
        $this->dropIndexesOn($table, ['normalised']);
        $this->createIndex(null, $table, ['normalised'], false);
        return true;
    }

    /** @param string[] $columns */
    private function dropIndexesOn(string $table, array $columns): void
    {
        foreach ($this->db->getSchema()->findIndexes($table) as $name => $index) {
            if (($index['columns'] ?? []) === $columns) {
                $this->dropIndex($name, $table);
            }
        }
    }

    /**
     * A pre-1.0.1 install could already hold colliding rows; fail with the
     * offending references rather than a raw duplicate-key error.
     *
     * @throws Exception
     */
    private function requireNoDuplicates(string $table): void
    {
        $duplicates = (new Query())
            ->select(['normalised'])
            ->from([$table])
            ->groupBy(['normalised'])
            ->having('COUNT(*) > 1')
            ->all($this->db);
        if ($duplicates !== []) {
            $list = implode(', ', array_column($duplicates, 'normalised'));
            throw new Exception("Duplicate payment references block the unique index: $list. Fix or delete the colliding rows in btpc_references, then run the migration again.");
        }
    }
}
