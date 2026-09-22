<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use wmd\banktransferpaymentcodes\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves a code's PNG over a signed, anonymous URL for `emailMode: 'hosted'`
 * and for any other context (PDF, a third-party mailer) that cannot embed
 * a CID part.
 */
class CodeController extends Controller
{
    protected array|bool|int $allowAnonymous = ['png'];

    public function actionPng(string $number, string $format, string $token): Response
    {
        if (Craft::$app->getSecurity()->validateData($token) !== $number . '|' . $format) {
            throw new NotFoundHttpException();
        }
        $order = Commerce::getInstance()->getOrders()->getOrderByNumber($number);
        if ($order === null) {
            throw new NotFoundHttpException();
        }
        foreach (Plugin::getInstance()->codes->forOrder($order) as $code) {
            if ($code->handle() === $format) {
                $response = Craft::$app->getResponse();
                $response->getHeaders()->set('Cache-Control', 'private, no-store');
                return $response->sendContentAsFile($code->png(), "$format.png", ['mimeType' => 'image/png', 'inline' => true]);
            }
        }
        throw new NotFoundHttpException();
    }

    /**
     * The one place the signed hosted-PNG URL is built, so the token this
     * controller validates always matches what {@see BtpcVariable::hostedUrl()}
     * and {@see \wmd\banktransferpaymentcodes\services\Codes::forOrder()}
     * (for `emailMode: 'hosted'`) hand out.
     */
    public static function url(Order $order, string $handle): string
    {
        $token = Craft::$app->getSecurity()->hashData($order->number . '|' . $handle);
        return UrlHelper::actionUrl('bank-transfer-payment-codes/code/png', ['number' => $order->number, 'format' => $handle, 'token' => $token]);
    }
}
