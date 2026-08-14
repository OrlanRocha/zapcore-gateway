import pino from 'pino';
import { config } from '../config';

export const logger = pino({
    level: config.nodeEnv === 'production' ? 'info' : 'debug',
    redact: [
        'req.headers.authorization',
        '*.WORKER_SECRET',
        '*.PHP_INTERNAL_SECRET',
        '*.DB_PASSWORD',
        '*.APP_KEY',
        'token',
        'secret',
        'password'
    ]
});
