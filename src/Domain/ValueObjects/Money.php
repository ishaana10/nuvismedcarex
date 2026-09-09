<?php
declare(strict_types=1);

namespace ClinicFlow\Domain\ValueObjects;

use InvalidArgumentException;

final class Money {
    private int $amountCents;
    private string $currency;

    public function __construct(int|float $amount, string $currency = 'FJD') {
        if (is_float($amount)) {
            $this->amountCents = (int) round($amount * 100);
        } else {
            $this->amountCents = $amount;
        }
        $this->currency = strtoupper(trim($currency));
    }

    public static function fromFloat(float $amount, string $currency = 'FJD'): self {
        return new self($amount, $currency);
    }

    public static function fromCents(int $cents, string $currency = 'FJD'): self {
        return new self($cents, $currency);
    }

    public static function zero(string $currency = 'FJD'): self {
        return new self(0, $currency);
    }

    public function getAmountCents(): int {
        return $this->amountCents;
    }

    public function getAmountFloat(): float {
        return round($this->amountCents / 100.0, 2);
    }

    public function getCurrency(): string {
        return $this->currency;
    }

    public function add(Money $other): self {
        $this->assertSameCurrency($other);
        return new self($this->amountCents + $other->amountCents, $this->currency);
    }

    public function subtract(Money $other): self {
        $this->assertSameCurrency($other);
        return new self($this->amountCents - $other->amountCents, $this->currency);
    }

    public function multiply(float $factor): self {
        return new self((int) round($this->amountCents * $factor), $this->currency);
    }

    public function equals(Money $other): bool {
        return $this->amountCents === $other->amountCents && $this->currency === $other->currency;
    }

    public function format(): string {
        return sprintf('%s %s', $this->currency, number_format($this->getAmountFloat(), 2));
    }

    private function assertSameCurrency(Money $other): void {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }
}
