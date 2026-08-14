INSERT INTO users (name, email, password_hash, role, active, must_change_password)
VALUES ('Admin ZapCore', 'admin@zapcore.local', '$2y$10$t1OuDQcAyuqVSsCt.8upguOwru4/C48kJjt6meNcwo6EYtEeVBMaW', 'admin', 1, 1)
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO api_tokens (user_id, token_hash, name)
SELECT id, 'ca2854d7782719e6f29281b861728602633b328ff6feb7a744f558e540c9f083', 'Local Development Token'
FROM users
WHERE email = 'admin@zapcore.local'
ON DUPLICATE KEY UPDATE token_hash = token_hash;
