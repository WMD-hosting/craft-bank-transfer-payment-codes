<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\formats;

use wmd\banktransferpaymentcodes\models\PaymentDetails;

interface FormatInterface
{
    public static function handle(): string;

    public static function label(): string;

    /** @return string[] countries whose banking apps prefer this format; [] = generic */
    public function countries(): array;

    /** Null when the details can be encoded; otherwise a short English reason. */
    public function supports(PaymentDetails $details): ?string;

    /** @throws FormatException when the details cannot be encoded */
    public function payload(PaymentDetails $details): string;

    public function svg(string $payload, int $size): string;

    public function png(string $payload, int $size): string;
}
