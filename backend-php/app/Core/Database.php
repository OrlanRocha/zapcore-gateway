<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    public PDO $pdo;

    public function __construct(array $config)
    {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
        try {
            $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            die("Database Connection Error: " . $e->getMessage());
        }

        try {
            $this->runCompatibilityMigrations();
        } catch (PDOException $e) {
            die("Database Migration Error: " . $e->getMessage());
        }
    }

    public function prepare($sql)
    {
        return $this->pdo->prepare($sql);
    }

    private function runCompatibilityMigrations(): void
    {
        if ($this->tableExists('users') && $this->tableExists('instances')) {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS instance_shares (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    instance_id INT NOT NULL,
                    user_id INT NOT NULL,
                    permission ENUM('editor') NOT NULL DEFAULT 'editor',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_instance_share (instance_id, user_id),
                    KEY idx_instance_shares_user (user_id, instance_id),
                    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!$this->tableExists('messages')) {
            return;
        }

        if (!$this->columnExists('messages', 'chat_type')) {
            $this->execIgnoringMysqlCodes(
                "ALTER TABLE messages ADD COLUMN chat_type ENUM('user','group','newsletter','unknown') DEFAULT 'user' AFTER to_jid",
                [1060]
            );

            $this->pdo->exec("
                UPDATE messages
                SET chat_type = CASE
                    WHEN direction = 'inbound' AND from_jid LIKE '%@g.us' THEN 'group'
                    WHEN direction = 'inbound' AND from_jid LIKE '%@newsletter' THEN 'newsletter'
                    WHEN direction = 'outbound' AND to_jid LIKE '%@g.us' THEN 'group'
                    WHEN direction = 'outbound' AND to_jid LIKE '%@newsletter' THEN 'newsletter'
                    WHEN from_jid LIKE '%@s.whatsapp.net'
                      OR to_jid LIKE '%@s.whatsapp.net'
                      OR from_jid LIKE '%@c.us'
                      OR to_jid LIKE '%@c.us' THEN 'user'
                    ELSE 'unknown'
                END
                WHERE chat_type IS NULL OR chat_type = 'user' OR chat_type = 'unknown'
            ");
        }

        if ($this->columnType('messages', 'message_type') === 'enum') {
            $this->pdo->exec("ALTER TABLE messages MODIFY message_type VARCHAR(32) NOT NULL DEFAULT 'unknown'");
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS contact_identities (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                instance_id INT NOT NULL,
                lid_jid VARCHAR(100) NULL,
                phone_jid VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_contact_lid (instance_id, lid_jid),
                UNIQUE KEY unique_contact_phone (instance_id, phone_jid),
                FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM contact_identities')->fetchColumn() === 0) {
            $this->pdo->exec("
                INSERT IGNORE INTO contact_identities (instance_id, lid_jid, phone_jid)
                SELECT DISTINCT
                    instance_id,
                    JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.key.remoteJid')),
                    JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.key.senderPn'))
                FROM messages
                WHERE JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.key.remoteJid')) LIKE '%@lid'
                  AND JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.key.senderPn')) LIKE '%@s.whatsapp.net'
            ");
        }

        $this->addIndexIfMissing(
            'messages',
            'idx_messages_instance_chat_type_id',
            'ALTER TABLE messages ADD INDEX idx_messages_instance_chat_type_id (instance_id, chat_type, id)'
        );
        $this->addIndexIfMissing(
            'messages',
            'idx_messages_chat_type_created',
            'ALTER TABLE messages ADD INDEX idx_messages_chat_type_created (chat_type, created_at)'
        );
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table
        ");
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND column_name = :column
        ");
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnType(string $table, string $column): ?string
    {
        $stmt = $this->pdo->prepare("
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        ");
        $stmt->execute(['table' => $table, 'column' => $column]);
        $type = $stmt->fetchColumn();
        return $type ? strtolower((string) $type) : null;
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND index_name = :index
        ");
        $stmt->execute(['table' => $table, 'index' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function addIndexIfMissing(string $table, string $index, string $ddl): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        $this->execIgnoringMysqlCodes($ddl, [1061]);
    }

    private function execIgnoringMysqlCodes(string $sql, array $ignoredCodes): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if (!in_array($driverCode, $ignoredCodes, true)) {
                throw $e;
            }
        }
    }
}
