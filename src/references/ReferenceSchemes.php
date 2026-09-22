<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\references;

use wmd\banktransferpaymentcodes\events\RegisterComponentsEvent;
use wmd\banktransferpaymentcodes\models\BankAccount;
use yii\base\Component;

/**
 * Registry of reference schemes. Third parties add their own through
 * EVENT_REGISTER_REFERENCE_SCHEMES with `$event->components[handle] = new Scheme()`.
 */
final class ReferenceSchemes extends Component
{
    public const EVENT_REGISTER_REFERENCE_SCHEMES = 'registerReferenceSchemes';

    /** @var array<string, ReferenceSchemeInterface>|null */
    private ?array $schemes = null;

    /** @return array<string, ReferenceSchemeInterface> */
    public function all(): array
    {
        if ($this->schemes !== null) {
            return $this->schemes;
        }
        // Built with a bare constructor + property assignment, not a config array: a
        // non-empty Yii config array routes through Yii::configure(), which needs the
        // global Yii class alias that plain (Craft-free) PHPUnit runs never load.
        $event = new RegisterComponentsEvent();
        $event->components = [
            'rf' => new Rf(),
            'hr00' => new HrModel(false),
            'hr01' => new HrModel(true),
            'si12' => new SiModel(),
            'be' => new BeStructured(),
            'fi' => new FiEeReference(),
            'sk' => new SkSymbols(),
        ];
        if ($this->hasEventHandlers(self::EVENT_REGISTER_REFERENCE_SCHEMES)) {
            $this->trigger(self::EVENT_REGISTER_REFERENCE_SCHEMES, $event);
        }
        return $this->schemes = $event->components;
    }

    public function get(string $handle): ReferenceSchemeInterface
    {
        return $this->all()[$handle] ?? throw new \InvalidArgumentException("Unknown reference scheme: $handle");
    }

    public function forAccount(BankAccount $account): ReferenceSchemeInterface
    {
        if ($account->referenceScheme !== 'auto') {
            return $this->get($account->referenceScheme);
        }
        return match ($account->country()) {
            'HR' => $this->get('hr00'),
            'SI' => $this->get('si12'),
            'BE' => $this->get('be'),
            'FI', 'EE' => $this->get('fi'),
            'SK' => $this->get('sk'),
            default => $this->get('rf'),
        };
    }
}
