import dotenv from 'dotenv';

dotenv.config();

export const config = {
    nodeEnv: process.env.NODE_ENV || 'development',
    workerPort: Number(process.env.WORKER_PORT || 3333),
    workerHost: process.env.WORKER_HOST || '0.0.0.0',
    workerSecret: process.env.WORKER_SECRET || '',
    phpApiUrl: process.env.PHP_API_URL || 'http://localhost:8080',
    phpInternalSecret: process.env.PHP_INTERNAL_SECRET || '',
    appKey: process.env.APP_KEY || process.env.PHP_APP_KEY || 'change_me_32_chars_minimum_key_123',
    db: {
        host: process.env.DB_HOST || '127.0.0.1',
        port: Number(process.env.DB_PORT || 3306),
        user: process.env.DB_USERNAME || 'root',
        password: process.env.DB_PASSWORD || '',
        database: process.env.DB_DATABASE || 'zapcore_gateway'
    }
};
