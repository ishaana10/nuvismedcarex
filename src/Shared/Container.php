<?php
declare(strict_types=1);

namespace ClinicFlow\Shared;

use Closure;
use RuntimeException;

class Container {
    private static ?self $instance = null;
    private array $bindings = [];
    private array $instances = [];

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function bind(string $abstract, Closure $factory): void {
        $this->bindings[$abstract] = $factory;
    }

    public function singleton(string $abstract, Closure $factory): void {
        $this->bindings[$abstract] = $factory;
        $this->instances[$abstract] = null;
    }

    public function get(string $abstract): mixed {
        if (array_key_exists($abstract, $this->instances) && $this->instances[$abstract] !== null) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            $object = $this->bindings[$abstract]($this);
            if (array_key_exists($abstract, $this->instances)) {
                $this->instances[$abstract] = $object;
            }
            return $object;
        }

        if (class_exists($abstract)) {
            return new $abstract();
        }

        throw new RuntimeException("No binding found for {$abstract}");
    }
}
