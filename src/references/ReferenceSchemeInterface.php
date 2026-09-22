<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\references;

interface ReferenceSchemeInterface
{
    public function handle(): string;

    public function label(): string;

    /** @return string[] ISO 3166-1 alpha-2 codes the scheme is native to; empty means any country. */
    public function countries(): array;

    /** The full reference as printed, including any model prefix or +++ frame. */
    public function generate(ReferenceInput $input): string;

    public function validate(string $reference): bool;

    /** Letters and digits only, no model, no separators: what statement matching compares. */
    public function normalise(string $reference): string;

    /** Bank model code for formats that carry one (HUB-3, UPN), or null. */
    public function model(): ?string;
}
