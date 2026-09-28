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
            self::$instance->registerDefaultBindings();
        }
        return self::$instance;
    }

    private function registerDefaultBindings(): void {
        if (!isset($this->bindings[\PDO::class]) && !isset($this->instances[\PDO::class]) && function_exists('getDB')) {
            $this->singleton(\PDO::class, function () {
                return getDB();
            });
        }
        if (!isset($this->bindings[Logger::class]) && !isset($this->instances[Logger::class])) {
            $this->singleton(Logger::class, function () {
                return new Logger();
            });
        }
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
            $reflector = new \ReflectionClass($abstract);
            $constructor = $reflector->getConstructor();
            if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
                return new $abstract();
            }

            $dependencies = [];
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $dependencies[] = $this->get($type->getName());
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new RuntimeException("Cannot resolve parameter {$parameter->getName()} in {$abstract}");
                }
            }

            return $reflector->newInstanceArgs($dependencies);
        }

        throw new RuntimeException("No binding found for {$abstract}");
    }
}
