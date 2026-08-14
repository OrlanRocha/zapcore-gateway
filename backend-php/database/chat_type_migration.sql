DELIMITER //

DROP PROCEDURE IF EXISTS zapcore_add_column_if_missing//
DROP PROCEDURE IF EXISTS zapcore_add_index_if_missing//

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

CREATE PROCEDURE zapcore_add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND index_name = p_index_name
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
    'messages',
    'chat_type',
    "ALTER TABLE messages ADD COLUMN chat_type ENUM('user','group','newsletter','unknown') DEFAULT 'user' AFTER to_jid"
);

UPDATE messages
SET chat_type = CASE
    WHEN direction = 'inbound' AND from_jid LIKE '%@g.us' THEN 'group'
    WHEN direction = 'inbound' AND from_jid LIKE '%@newsletter' THEN 'newsletter'
    WHEN direction = 'outbound' AND to_jid LIKE '%@g.us' THEN 'group'
    WHEN direction = 'outbound' AND to_jid LIKE '%@newsletter' THEN 'newsletter'
    WHEN from_jid LIKE '%@s.whatsapp.net' OR to_jid LIKE '%@s.whatsapp.net' OR from_jid LIKE '%@c.us' OR to_jid LIKE '%@c.us' THEN 'user'
    ELSE 'unknown'
END
WHERE chat_type IS NULL OR chat_type = 'user' OR chat_type = 'unknown';

CALL zapcore_add_index_if_missing('messages', 'idx_messages_instance_chat_type_id', 'ALTER TABLE messages ADD INDEX idx_messages_instance_chat_type_id (instance_id, chat_type, id)');
CALL zapcore_add_index_if_missing('messages', 'idx_messages_chat_type_created', 'ALTER TABLE messages ADD INDEX idx_messages_chat_type_created (chat_type, created_at)');

DROP PROCEDURE IF EXISTS zapcore_add_column_if_missing;
DROP PROCEDURE IF EXISTS zapcore_add_index_if_missing;
