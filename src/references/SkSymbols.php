<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\references;

/**
 * Slovak variable symbol: up to ten digits, no check digit. Constant and
 * specific symbols are left empty.
 */
final class SkSymbols implements ReferenceSchemeInterface
{
    public function handle(): string
    {
        return 'sk';
    }
    public function label(): string
    {
        return 'Slovak variable symbol';
    }
    public function countries(): array
    {
        return ['SK'];
    }
    public function model(): ?string
    {
        return null;
    }

    public function generate(ReferenceInput $input): string
    {
        return substr($input->digits(), 0, 10);
    }

    public function validate(string $reference): bool
    {
        // Reference should only contain digits and allowed separators, no letters
        if (!preg_match('/^[\d\s\-]*$/', $reference)) {
            return false;
        }
        return (bool)preg_match('/^\d{1,10}$/', $this->normalise($reference));
    }

    public function normalise(string $reference): string
    {
        return preg_replace('/\D+/', '', $reference) ?? '';
    }
}
