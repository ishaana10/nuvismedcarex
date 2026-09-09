<?php
declare(strict_types=1);

namespace ClinicFlow\Domain\ValueObjects;

use InvalidArgumentException;

final class TIN {
    private string $value;

    public function __construct(string $value) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException("Taxpayer Identification Number (TIN) cannot be empty.");
        }
        $this->value = $trimmed;
    }

    public function getValue(): string {
        return $this->value;
    }

    public function __toString(): string {
        return $this->value;
    }
}
