<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\services;

use craft\commerce\events\MailEvent;
use craft\commerce\services\Emails;
use wmd\banktransferpaymentcodes\gateways\BankTransferGateway;
use wmd\banktransferpaymentcodes\Plugin;
use yii\base\Component;
use yii\base\Event;

/**
 * Attaches the PNG of every code as an inline (CID) part of Commerce emails
 * for bank-transfer orders, so `<img src="cid:…">` renders in Gmail and
 * Outlook, which block data: URIs.
 *
 * Commerce renders the template before this event fires, so the template
 * cannot know the CID up front. The convention is fixed instead:
 * `cid:btpc-{orderNumber}-{format}.png`, and PaymentCode::emailSrc() returns
 * exactly that whenever the embedder is active.
 */
class EmailEmbedder extends Component
{
    public function attach(): void
    {
        Event::on(Emails::class, Emails::EVENT_BEFORE_SEND_MAIL, function(MailEvent $e) {
            // `$e->order` is a non-nullable typed property; isset()/`??` (not a
            // direct read) is the safe way to check it without a possible
            // "must not be accessed before initialization" error for an event
            // fired by something other than Commerce's own order-email flow.
            $order = $e->order ?? null;
            if ($order === null || !$order->getGateway() instanceof BankTransferGateway || $order->isPaid) {
                return;
            }
            $plugin = Plugin::getInstance();
            if ($plugin->getSettings()->emailMode !== 'embedded') {
                return;
            }
            foreach ($plugin->codes->forOrder($order) as $code) {
                $name = self::cidName($order->number, $code->handle());
                if (!str_contains((string)$e->craftEmail->getSymfonyEmail()->getHtmlBody(), 'cid:' . $name)) {
                    continue;
                }
                $e->craftEmail->embedContent($code->png(), ['fileName' => $name, 'contentType' => 'image/png']);
            }
        });
    }

    public static function cidName(string $orderNumber, string $handle): string
    {
        return 'btpc-' . substr($orderNumber, 0, 12) . '-' . $handle . '.png';
    }
}
