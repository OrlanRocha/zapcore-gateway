DELIMITER //

DROP PROCEDURE IF EXISTS zapcore_add_index_if_missing//

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

CALL zapcore_add_index_if_missing('users', 'idx_users_role_active', 'ALTER TABLE users ADD INDEX idx_users_role_active (role, active)');
CALL zapcore_add_index_if_missing('api_tokens', 'idx_api_tokens_user', 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_user (user_id)');
CALL zapcore_add_index_if_missing('instances', 'idx_instances_user_status', 'ALTER TABLE instances ADD INDEX idx_instances_user_status (user_id, status)');
CALL zapcore_add_index_if_missing('instances', 'idx_instances_status', 'ALTER TABLE instances ADD INDEX idx_instances_status (status)');

CALL zapcore_add_index_if_missing('contacts', 'idx_contacts_instance_updated', 'ALTER TABLE contacts ADD INDEX idx_contacts_instance_updated (instance_id, updated_at)');
CALL zapcore_add_index_if_missing('chats', 'idx_chats_instance_updated', 'ALTER TABLE chats ADD INDEX idx_chats_instance_updated (instance_id, updated_at)');

CALL zapcore_add_index_if_missing('messages', 'idx_messages_instance_id', 'ALTER TABLE messages ADD INDEX idx_messages_instance_id (instance_id, id)');
CALL zapcore_add_index_if_missing('messages', 'idx_messages_instance_direction_id', 'ALTER TABLE messages ADD INDEX idx_messages_instance_direction_id (instance_id, direction, id)');
CALL zapcore_add_index_if_missing('messages', 'idx_messages_instance_chat_type_id', 'ALTER TABLE messages ADD INDEX idx_messages_instance_chat_type_id (instance_id, chat_type, id)');
CALL zapcore_add_index_if_missing('messages', 'idx_messages_chat_type_created', 'ALTER TABLE messages ADD INDEX idx_messages_chat_type_created (chat_type, created_at)');
CALL zapcore_add_index_if_missing('messages', 'idx_messages_instance_status_created', 'ALTER TABLE messages ADD INDEX idx_messages_instance_status_created (instance_id, status, created_at)');
CALL zapcore_add_index_if_missing('messages', 'idx_messages_status_created', 'ALTER TABLE messages ADD INDEX idx_messages_status_created (status, created_at)');
CALL zapcore_add_index_if_missing('messages', 'idx_messages_direction_created', 'ALTER TABLE messages ADD INDEX idx_messages_direction_created (direction, created_at)');
CALL zapcore_add_index_if_missing('messages', 'idx_messages_instance_from_jid', 'ALTER TABLE messages ADD INDEX idx_messages_instance_from_jid (instance_id, from_jid)');
CALL zapcore_add_index_if_missing('messages', 'idx_messages_instance_to_jid', 'ALTER TABLE messages ADD INDEX idx_messages_instance_to_jid (instance_id, to_jid)');
CALL zapcore_add_index_if_missing('messages', 'ft_messages_body', 'ALTER TABLE messages ADD FULLTEXT INDEX ft_messages_body (body)');

CALL zapcore_add_index_if_missing('send_queue', 'idx_send_queue_status_schedule_created', 'ALTER TABLE send_queue ADD INDEX idx_send_queue_status_schedule_created (status, scheduled_at, created_at)');
CALL zapcore_add_index_if_missing('send_queue', 'idx_send_queue_instance_status', 'ALTER TABLE send_queue ADD INDEX idx_send_queue_instance_status (instance_id, status)');
CALL zapcore_add_index_if_missing('send_queue', 'idx_send_queue_message', 'ALTER TABLE send_queue ADD INDEX idx_send_queue_message (message_id)');

CALL zapcore_add_index_if_missing('webhooks', 'idx_webhooks_instance_active', 'ALTER TABLE webhooks ADD INDEX idx_webhooks_instance_active (instance_id, active)');
CALL zapcore_add_index_if_missing('webhook_logs', 'idx_webhook_logs_instance_created', 'ALTER TABLE webhook_logs ADD INDEX idx_webhook_logs_instance_created (instance_id, created_at)');
CALL zapcore_add_index_if_missing('webhook_logs', 'idx_webhook_logs_success_created', 'ALTER TABLE webhook_logs ADD INDEX idx_webhook_logs_success_created (success, created_at)');
CALL zapcore_add_index_if_missing('webhook_logs', 'idx_webhook_logs_webhook_created', 'ALTER TABLE webhook_logs ADD INDEX idx_webhook_logs_webhook_created (webhook_id, created_at)');

CALL zapcore_add_index_if_missing('connection_logs', 'idx_connection_logs_instance_id', 'ALTER TABLE connection_logs ADD INDEX idx_connection_logs_instance_id (instance_id, id)');
CALL zapcore_add_index_if_missing('connection_logs', 'idx_connection_logs_instance_created', 'ALTER TABLE connection_logs ADD INDEX idx_connection_logs_instance_created (instance_id, created_at)');
CALL zapcore_add_index_if_missing('connection_logs', 'idx_connection_logs_event_created', 'ALTER TABLE connection_logs ADD INDEX idx_connection_logs_event_created (event_type, created_at)');

CALL zapcore_add_index_if_missing('audit_logs', 'idx_audit_logs_user_created', 'ALTER TABLE audit_logs ADD INDEX idx_audit_logs_user_created (user_id, created_at)');
CALL zapcore_add_index_if_missing('audit_logs', 'idx_audit_logs_action_created', 'ALTER TABLE audit_logs ADD INDEX idx_audit_logs_action_created (action, created_at)');

DROP PROCEDURE IF EXISTS zapcore_add_index_if_missing;
