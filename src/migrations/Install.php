<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public const TABLE = '{{%btpc_references}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'accountKey' => $this->string(64)->notNull(),
            'scheme' => $this->string(16)->notNull(),
            'model' => $this->string(8)->notNull()->defaultValue(''),
            'reference' => $this->string(64)->notNull(),
            'normalised' => $this->string(64)->notNull(),
            'isStructured' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, self::TABLE, ['orderId'], true);
        // Globally unique: findOrder() resolves a reference without knowing the
        // account, so a bank statement line must match at most one order.
        $this->createIndex(null, self::TABLE, ['normalised'], true);
        $this->addForeignKey(null, self::TABLE, ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
