<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\records;

use craft\db\ActiveRecord;
use wmd\banktransferpaymentcodes\migrations\Install;

/**
 * @property int $id
 * @property int $orderId
 * @property string $accountKey
 * @property string $scheme
 * @property string $model
 * @property string $reference
 * @property string $normalised
 * @property bool $isStructured
 */
class PaymentReferenceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::TABLE;
    }
}
