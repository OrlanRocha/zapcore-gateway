DELIMITER //

DROP PROCEDURE IF EXISTS zapcore_add_column_if_missing//

CREATE PROCEDURE zapcore_add_column_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_column_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND column_name = p_column_name
        LIMIT 1
    ) THEN
        SET @zapcore_sql = p_ddl;
        PREPARE zapcore_stmt FROM @zapcore_sql;
        EXECUTE zapcore_stmt;
        DEALLOCATE PREPARE zapcore_stmt;
    END IF;
END//

DELIMITER ;

CALL zapcore_add_column_if_missing(
    'users',
    'must_change_password',
    'ALTER TABLE users ADD COLUMN must_change_password BOOLEAN DEFAULT FALSE AFTER active'
);

UPDATE users
SET must_change_password = 1
WHERE email = 'admin@zapcore.local'
  AND password_hash = '$2y$10$t1OuDQcAyuqVSsCt.8upguOwru4/C48kJjt6meNcwo6EYtEeVBMaW';

DROP PROCEDURE IF EXISTS zapcore_add_column_if_missing;
