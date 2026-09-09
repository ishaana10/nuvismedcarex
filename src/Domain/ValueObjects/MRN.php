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

    public function getValue(): string {
        return $this->value;
    }

    public function __toString(): string {
        return $this->value;
    }
}
