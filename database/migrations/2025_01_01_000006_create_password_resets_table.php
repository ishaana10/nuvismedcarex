<?php

return new class {
    public function up(PDO $pdo): void {
        $sql = "CREATE TABLE IF NOT EXISTS password_resets (
            id VARCHAR(50) PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            otp VARCHAR(10) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );";
        $pdo->exec($sql);

        try {
            $pdo->exec("CREATE INDEX idx_password_resets_email ON password_resets(email);");
        } catch (Throwable $e) {
            // Index might already exist
        }
    }
};
