<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\rendering;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Common\Version;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Encoder\QrCode;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;

/**
 * Thin wrapper over bacon/bacon-qr-code that exposes the three knobs the
 * payment formats care about: charset, whether to emit an ECI segment, and
 * a forced symbol version. Error correction is always M (EPC, UPN).
 */
final class QrRenderer
{
    public static function svg(string $payload, int $size, string $encoding = 'UTF-8', bool $eci = false, ?int $version = null): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 4), new SvgImageBackEnd());
        return $renderer->render(self::encode($payload, $encoding, $eci, $version));
    }

    public static function png(string $payload, int $size, string $encoding = 'UTF-8', bool $eci = false, ?int $version = null): string
    {
        return (new GDLibRenderer($size, 4))->render(self::encode($payload, $encoding, $eci, $version));
    }

    public static function encode(string $payload, string $encoding, bool $eci, ?int $version): QrCode
    {
        return Encoder::encode(
            $payload,
            ErrorCorrectionLevel::M(),
            $encoding,
            $version === null ? null : Version::getVersionForNumber($version),
            $eci,
        );
    }
}
