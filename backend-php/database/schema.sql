CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    active BOOLEAN DEFAULT TRUE,
    must_change_password BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_users_role_active (role, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_api_tokens_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    provider VARCHAR(50) DEFAULT 'baileys',
    status ENUM('created', 'waiting_qr', 'connecting', 'connected', 'disconnected', 'logged_out', 'error') DEFAULT 'created',
    phone_number VARCHAR(50) NULL,
    profile_name VARCHAR(255) NULL,
    qr_code TEXT NULL,
    qr_updated_at TIMESTAMP NULL,
    last_connected_at TIMESTAMP NULL,
    last_disconnected_at TIMESTAMP NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_instances_user_status (user_id, status),
    KEY idx_instances_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS baileys_auth (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    instance_id INT NOT NULL,
    auth_type VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    value_json LONGTEXT NULL,
    encrypted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_auth (instance_id, auth_type, auth_key),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    instance_id INT NOT NULL,
    jid VARCHAR(100) NOT NULL,
    name VARCHAR(255) NULL,
    short_name VARCHAR(255) NULL,
    push_name VARCHAR(255) NULL,
    profile_pic_url TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_contact (instance_id, jid),
    KEY idx_contacts_instance_updated (instance_id, updated_at),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chats (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    instance_id INT NOT NULL,
    jid VARCHAR(100) NOT NULL,
    name VARCHAR(255) NULL,
    unread_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_chat (instance_id, jid),
    KEY idx_chats_instance_updated (instance_id, updated_at),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    instance_id INT NOT NULL,
    chat_id BIGINT NULL,
    whatsapp_message_id VARCHAR(255) NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    from_jid VARCHAR(100) NOT NULL,
    to_jid VARCHAR(100) NOT NULL,
    chat_type ENUM('user', 'group', 'newsletter', 'unknown') DEFAULT 'user',
    message_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
    body TEXT NULL,
    raw_json JSON NULL,
    status ENUM('pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'received') NOT NULL,
    error_message TEXT NULL,
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    received_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_message (instance_id, whatsapp_message_id),
    KEY idx_messages_instance_id (instance_id, id),
    KEY idx_messages_instance_direction_id (instance_id, direction, id),
    KEY idx_messages_instance_chat_type_id (instance_id, chat_type, id),
    KEY idx_messages_chat_type_created (chat_type, created_at),
    KEY idx_messages_instance_status_created (instance_id, status, created_at),
    KEY idx_messages_status_created (status, created_at),
    KEY idx_messages_direction_created (direction, created_at),
    KEY idx_messages_instance_from_jid (instance_id, from_jid),
    KEY idx_messages_instance_to_jid (instance_id, to_jid),
    FULLTEXT KEY ft_messages_body (body),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_media (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NULL,
    mime_type VARCHAR(100) NULL,
    file_size INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS send_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    instance_id INT NOT NULL,
    message_id BIGINT NOT NULL,
    to_jid VARCHAR(100) NOT NULL,
    payload_json JSON NOT NULL,
    status ENUM('pending', 'processing', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    scheduled_at TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_send_queue_status_schedule_created (status, scheduled_at, created_at),
    KEY idx_send_queue_instance_status (instance_id, status),
    KEY idx_send_queue_message (message_id),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instance_id INT NULL,
    name VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    secret VARCHAR(255) NOT NULL,
    events JSON NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_webhooks_instance_active (instance_id, active),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT NOT NULL,
    instance_id INT NULL,
    event_name VARCHAR(100) NOT NULL,
    payload_json JSON NOT NULL,
    response_status INT NULL,
    response_body TEXT NULL,
    success BOOLEAN DEFAULT FALSE,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_webhook_logs_instance_created (instance_id, created_at),
    KEY idx_webhook_logs_success_created (success, created_at),
    KEY idx_webhook_logs_webhook_created (webhook_id, created_at),
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE,
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connection_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    instance_id INT NOT NULL,
    event_type ENUM('qr_generated', 'connecting', 'connected', 'disconnected', 'logged_out', 'reconnecting', 'error') NOT NULL,
    description TEXT NULL,
    raw_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_connection_logs_instance_id (instance_id, id),
    KEY idx_connection_logs_instance_created (instance_id, created_at),
    KEY idx_connection_logs_event_created (event_type, created_at),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(255) NOT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_logs_user_created (user_id, created_at),
    KEY idx_audit_logs_action_created (action, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
