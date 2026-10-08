<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StorageSchemaTest extends TestCase
{
    private string $schema;
    private string $migration;

    protected function setUp(): void
    {
        $this->schema = file_get_contents(ZAPCORE_PROJECT_ROOT . '/backend-php/database/schema.sql') ?: '';
        $migrationPath = ZAPCORE_PROJECT_ROOT . '/backend-php/database/storage_retention_migration.sql';
        $this->migration = is_file($migrationPath) ? (file_get_contents($migrationPath) ?: '') : '';
    }

    public function test_storage_tables_and_media_retention_fields_exist(): void
    {
        foreach ([$this->schema, $this->migration] as $sql) {
            self::assertStringContainsString('storage_settings', $sql);
            self::assertStringContainsString('storage_cleanup_runs', $sql);
            self::assertStringContainsString('removed_at', $sql);
            self::assertStringContainsString('removal_reason', $sql);
            self::assertStringContainsString('storage_origin', $sql);
        }
    }

    public function test_storage_contract_uses_safe_modes_intervals_and_bigint_sizes(): void
    {
        self::assertMatchesRegularExpression('/mode\s+ENUM\([^)]*percent[^)]*absolute/i', $this->schema);
        self::assertStringContainsString('interval_minutes', $this->schema);
        self::assertMatchesRegularExpression('/file_size\s+BIGINT/i', $this->schema);
        self::assertMatchesRegularExpression('/absolute_bytes\s+BIGINT/i', $this->schema);
        self::assertStringContainsString('idx_message_media_created', $this->schema);
    }

    public function test_migration_avoids_database_specific_if_not_exists_index_syntax(): void
    {
        self::assertStringNotContainsString('CREATE INDEX IF NOT EXISTS', $this->migration);
        self::assertStringNotContainsString('ADD COLUMN IF NOT EXISTS', $this->migration);
        self::assertStringContainsString('information_schema.statistics', $this->migration);
        self::assertStringContainsString('information_schema.columns', $this->migration);
    }
}
