import express from 'express';
import pool from './db';
import { InstanceManager } from './instances/InstanceManager';
import { SendQueueWorker } from './queues/SendQueueWorker';
import { config } from './config';
import { logger } from './utils/logger';
import { internalRoutes } from './routes/internalRoutes';
import packageJson from '../package.json';

const app = express();
app.use(express.json({ limit: '2mb' }));
app.get('/health', async (_req, res) => {
    try {
        await pool.query('SELECT 1');
        res.json({
            success: true,
            status: 'ok',
            version: packageJson.version,
            uptime_seconds: Math.floor(process.uptime())
        });
    } catch {
        res.status(503).json({ success: false, status: 'degraded' });
    }
});
app.use(internalRoutes);

const server = app.listen(config.workerPort, config.workerHost, async () => {
    logger.info({ host: config.workerHost, port: config.workerPort }, 'Worker server running');

    try {
        const [rows]: any = await pool.query("SELECT uuid FROM instances WHERE status IN ('connected', 'waiting_qr', 'connecting')");
        for (const row of rows) {
            logger.info({ uuid: row.uuid }, 'Resuming instance connection');
            await InstanceManager.connect(row.uuid);
        }
    } catch (error) {
        logger.error({ error }, 'Error resuming instances');
    }

    SendQueueWorker.start();
});

const shutdown = (signal: string) => {
    logger.info({ signal }, 'Worker shutdown requested');
    SendQueueWorker.stop();
    server.close(async () => {
        await pool.end();
        process.exit(0);
    });
    setTimeout(() => process.exit(1), 10000).unref();
};

process.once('SIGINT', () => shutdown('SIGINT'));
process.once('SIGTERM', () => shutdown('SIGTERM'));
