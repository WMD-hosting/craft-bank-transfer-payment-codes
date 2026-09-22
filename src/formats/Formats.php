<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\formats;

use wmd\banktransferpaymentcodes\events\RegisterComponentsEvent;
use yii\base\Component;

/**
 * Registry of code formats. Third parties add their own through
 * EVENT_REGISTER_FORMATS with `$event->components[handle] = new Format()`.
 */
final class Formats extends Component
{
    public const EVENT_REGISTER_FORMATS = 'registerFormats';

    /** @var array<string, FormatInterface>|null */
    private ?array $formats = null;

    public function __construct(private readonly bool $epcForceBic = false, array $config = [])
    {
        parent::__construct($config);
    }

    /** @return array<string, FormatInterface> */
    public function all(): array
    {
        if ($this->formats !== null) {
            return $this->formats;
        }
        // Built with a bare constructor + property assignment, not a config array: a
        // non-empty Yii config array routes through Yii::configure(), which needs the
        // global Yii class alias that plain (Craft-free) PHPUnit runs never load.
        $event = new RegisterComponentsEvent();
        $event->components = [
            'epc' => new EpcQr($this->epcForceBic),
            'hub3' => new Hub3Pdf417(),
            'upn' => new UpnQr(),
            'paybysquare' => new PayBySquare(),
        ];
        if ($this->hasEventHandlers(self::EVENT_REGISTER_FORMATS)) {
            $this->trigger(self::EVENT_REGISTER_FORMATS, $event);
        }
        return $this->formats = $event->components;
    }

    public function get(string $handle): FormatInterface
    {
        return $this->all()[$handle] ?? throw new \InvalidArgumentException("Unknown format: $handle");
    }
}
