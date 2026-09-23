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
    }
}
