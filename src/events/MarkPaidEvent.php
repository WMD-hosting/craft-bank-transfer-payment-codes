<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\events;

use wmd\banktransferpaymentcodes\models\MarkPaidResult;
use yii\base\Event;

final class MarkPaidEvent extends Event
{
    public MarkPaidResult $result;
    public string $source;
}
