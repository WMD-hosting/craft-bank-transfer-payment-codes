<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\events;

use yii\base\Event;

final class RegisterComponentsEvent extends Event
{
    /** @var array<string, object> handle => instance */
    public array $components = [];
}
