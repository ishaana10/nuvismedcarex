<?php
declare(strict_types=1);

namespace ClinicFlow\Domain\ValueObjects;

use InvalidArgumentException;

final class MRN {
    private string $value;

    public function __construct(string $value) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException("MRN cannot be empty.");
        }
        $this->value = $trimmed;
    }

    public static function generate(?string $prefix = '#'): self {
        return new self(($prefix ?? '#') . random_int(10000, 99999));
    }

    public static function fromString(string $value): self {
        return new self($value);
    }

    public function getValue(): string {
        return $this->value;
    }

    public function toString(): string {
        return $this->value;
    }

    public function __toString(): string {
        return $this->value;
    }
}
