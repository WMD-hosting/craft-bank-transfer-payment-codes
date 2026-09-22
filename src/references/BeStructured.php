<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\references;

/**
 * Belgian structured communication +++XXX/XXXX/XXXCC+++: ten digits and a
 * mod 97 check (0 becomes 97). Febelfin requires it in the EPC QR structured
 * reference field since February 2026.
 */
final class BeStructured implements ReferenceSchemeInterface
{
    public function handle(): string
    {
        return 'be';
    }
    public function label(): string
    {
        return 'Belgian structured communication';
    }
    public function countries(): array
    {
        return ['BE'];
    }
    public function model(): ?string
    {
        return null;
    }

    public function generate(ReferenceInput $input): string
    {
        $body = str_pad(substr($input->digits(), -10), 10, '0', STR_PAD_LEFT);
        return self::format($body . self::check($body));
    }

    public function validate(string $reference): bool
    {
        $digits = $this->normalise($reference);
        return strlen($digits) === 12 && self::check(substr($digits, 0, 10)) === substr($digits, 10);
    }

    public function normalise(string $reference): string
    {
        return preg_replace('/\D+/', '', $reference) ?? '';
    }

    public static function check(string $tenDigits): string
    {
        $remainder = (int)$tenDigits % 97;
        return str_pad((string)($remainder === 0 ? 97 : $remainder), 2, '0', STR_PAD_LEFT);
    }

    public static function format(string $twelveDigits): string
    {
        return '+++' . substr($twelveDigits, 0, 3) . '/' . substr($twelveDigits, 3, 4) . '/' . substr($twelveDigits, 7, 5) . '+++';
    }
}
