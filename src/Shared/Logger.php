<?php
declare(strict_types=1);

namespace ClinicFlow\Shared;

class Logger {
    private string $logFile;

    public function __construct(?string $logFile = null) {
        $this->logFile = $logFile ?? (__DIR__ . '/../../server.log');
    }

    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void {
        $date = date('Y-m-d H:i:s');
        $tenantId = TenantContext::getTenantId();
        $ctxString = !empty($context) ? ' ' . json_encode($context) : '';
        $formatted = sprintf("[%s] [%s] [Tenant: %s] %s%s\n", $date, $level, $tenantId, $message, $ctxString);
        error_log($formatted, 3, $this->logFile);

        // Also persist to system_error_logs database table if available
        try {
            if (function_exists('getDB')) {
                $pdo = getDB();
                $logId = 'err-php-' . uniqid();
                $fileLine = $context['file'] ?? null ? ($context['file'] . ':' . ($context['line'] ?? '')) : null;
                $stack = isset($context['exception']) && is_object($context['exception']) ? $context['exception']->getTraceAsString() : json_encode($context);
                $userId = $_SESSION['user_id'] ?? null;

                $stmt = $pdo->prepare("INSERT INTO system_error_logs (id, tenant_id, type, level, message, file_line, stack_trace, user_id, created_at) VALUES (?, ?, 'PHP', ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $logId,
                    $tenantId,
                    $level,
                    $message,
                    $fileLine,
                    $stack,
                    $userId,
                    $date
                ]);
            }
        } catch (\Throwable $e) {
            // Ignore DB log failures
        }
    }
}
