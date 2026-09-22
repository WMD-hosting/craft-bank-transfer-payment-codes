<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\TransactionEvent;
use craft\commerce\services\Gateways;
use craft\commerce\services\Payments as CommercePayments;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use wmd\banktransferpaymentcodes\formats\Formats;
use wmd\banktransferpaymentcodes\gateways\BankTransferGateway;
use wmd\banktransferpaymentcodes\models\Settings;
use wmd\banktransferpaymentcodes\references\ReferenceSchemes;
use wmd\banktransferpaymentcodes\services\Accounts;
use wmd\banktransferpaymentcodes\services\Codes;
use wmd\banktransferpaymentcodes\services\EmailEmbedder;
use wmd\banktransferpaymentcodes\services\Payments;
use wmd\banktransferpaymentcodes\services\References;
use wmd\banktransferpaymentcodes\variables\BtpcVariable;
use yii\base\Event;

/**
 * Bank Transfer Payment Codes: a Commerce gateway that prints scannable
 * bank-transfer codes and national payment references.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Accounts $accounts
 * @property-read ReferenceSchemes $referenceSchemes
 * @property-read Formats $formats
 * @property-read Codes $codes
 * @property-read References $references
 * @property-read Payments $payments
 * @property-read EmailEmbedder $emailEmbedder
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.1';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'accounts' => static fn() => new Accounts(self::getInstance()->getSettings()),
                'referenceSchemes' => ReferenceSchemes::class,
                'formats' => static fn() => new Formats(self::getInstance()->getSettings()->epcForceBic),
                'references' => static fn() => new References(self::getInstance()->accounts, self::getInstance()->referenceSchemes),
                'codes' => static fn() => new Codes(
                    self::getInstance()->formats,
                    self::getInstance()->getSettings()->codeSize,
                    self::getInstance()->accounts,
                    self::getInstance()->references,
                    static fn(string $message) => Craft::warning($message, 'bank-transfer-payment-codes'),
                ),
                'payments' => static fn() => new Payments(self::getInstance()->references, self::getInstance()->getSettings()),
                'emailEmbedder' => EmailEmbedder::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(Gateways::class, Gateways::EVENT_REGISTER_GATEWAY_TYPES, static function(RegisterComponentTypesEvent $e) {
            $e->types[] = BankTransferGateway::class;
        });

        // Generate the payment reference the moment a bank-transfer order completes.
        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, static function(Event $e) {
            /** @var Order $order */
            $order = $e->sender;
            if ($order->getGateway() instanceof BankTransferGateway) {
                self::getInstance()->references->ensure($order);
            }
        });

        // Commerce's own CP Capture button captures the authorisation directly,
        // without going through markPaid(); pick it up here so the paid order
        // status and EVENT_AFTER_MARK_PAID apply to that path too.
        Event::on(CommercePayments::class, CommercePayments::EVENT_AFTER_CAPTURE_TRANSACTION, static function(TransactionEvent $e) {
            self::getInstance()->payments->afterCpCapture($e->transaction);
        });

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $e) {
            /** @var CraftVariable $variable */
            $variable = $e->sender;
            $variable->set('bankTransferPaymentCodes', BtpcVariable::class);
            $variable->set('btpc', BtpcVariable::class);
        });

        // craft\base\Plugin only auto-registers the CP template root; the
        // `_code.twig` partial is included from site templates (checkout
        // complete page, order email, order PDF), so it needs a site root too.
        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $e) {
            if (is_dir($baseDir = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates')) {
                $e->roots[$this->id] = $baseDir;
            }
        });

        $this->emailEmbedder->attach();
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('bank-transfer-payment-codes/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }
}
