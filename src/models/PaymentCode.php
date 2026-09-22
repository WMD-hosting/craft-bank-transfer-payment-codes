<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\models;

use wmd\banktransferpaymentcodes\formats\FormatInterface;

/**
 * One renderable code. Rendering is lazy and memoised so a template can call
 * svg() and png() without paying twice.
 */
final class PaymentCode
{
    private ?string $svg = null;
    private ?string $png = null;
    private ?string $emailSrc = null;

    public function __construct(
        public readonly FormatInterface $format,
        public readonly string $payload,
        private readonly int $size,
    ) {
    }

    public function handle(): string
    {
        return $this->format::handle();
    }
    public function label(): string
    {
        return $this->format::label();
    }

    public function svg(): string
    {
        return $this->svg ??= $this->format->svg($this->payload, $this->size);
    }

    public function png(): string
    {
        return $this->png ??= $this->format->png($this->payload, $this->size);
    }

    public function pngDataUri(): string
    {
        return 'data:image/png;base64,' . base64_encode($this->png());
    }

    /** What an <img src> in an email should use; the mailer listener swaps in a cid: URL. */
    public function emailSrc(): string
    {
        return $this->emailSrc ?? $this->pngDataUri();
    }

    public function setEmailSrc(string $src): void
    {
        $this->emailSrc = $src;
    }
}
