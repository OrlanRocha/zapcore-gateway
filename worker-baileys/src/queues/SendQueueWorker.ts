import pool from '../db';
import { InstanceManager } from '../instances/InstanceManager';
import { logger } from '../utils/logger';
import { PhpApiClient } from '../services/PhpApiClient';

export class SendQueueWorker {
    private static isRunning = false;
    private static readonly idlePollMs = 5000;
    private static readonly activePollMs = 1000;
    private static readonly batchSize = 25;
    private static readonly processingTimeoutMinutes = 2;
    private static readonly minimumSendIntervalMs = Math.max(
        1000,
        Number.isFinite(Number(process.env.MESSAGE_MIN_INTERVAL_MS)) ? Number(process.env.MESSAGE_MIN_INTERVAL_MS) : 5000
    );
    private static readonly lastSendAt = new Map<number, number>();
    private static timer: NodeJS.Timeout | null = null;

    public static start() {
        if (this.isRunning) return;
        this.isRunning = true;
        this.poll();
    }

    public static stop() {
        this.isRunning = false;
        if (this.timer) clearTimeout(this.timer);
        this.timer = null;
    }

    private static async poll() {
        if (!this.isRunning) return;
        let nextPollMs = this.idlePollMs;

        try {
            await this.recoverStaleProcessingItems();

            const [rows]: any = await pool.query(`
                SELECT sq.id, sq.instance_id, sq.message_id, sq.to_jid, sq.payload_json, sq.attempts, sq.max_attempts, i.uuid as instance_uuid 
                FROM send_queue sq
                JOIN instances i ON sq.instance_id = i.id
                WHERE sq.status = 'pending'
                  AND sq.attempts < sq.max_attempts
                  AND COALESCE(sq.scheduled_at, NOW()) <= NOW()
                  AND i.status = 'connected'
                ORDER BY sq.created_at ASC
                LIMIT ?
            `, [this.batchSize]);

            if (rows.length > 0) {
                nextPollMs = this.activePollMs;
            }

            for (const item of rows) {
                await this.processItem(item);
            }
        } catch (error) {
            logger.error({ error }, 'Error polling queue');
        } finally {
            if (this.isRunning) this.timer = setTimeout(() => this.poll(), nextPollMs);
        }
    }

    private static async processItem(item: any) {
        try {
            const [claim]: any = await pool.query(`
                UPDATE send_queue
                SET status = 'processing', attempts = attempts + 1, error_message = NULL
                WHERE id = ?
                  AND status = 'pending'
                  AND attempts < max_attempts
            `, [item.id]);

            if (claim.affectedRows !== 1) {
                return;
            }

            const sock = InstanceManager.getInstance(item.instance_uuid);
            if (!sock) {
                throw new Error('Instance not connected or socket not found');
            }

            const payload = typeof item.payload_json === 'string' ? JSON.parse(item.payload_json) : item.payload_json;

            await this.assertRecipientConsent(item.instance_id, item.to_jid);
            await this.enforceInstancePacing(item.instance_id);
            const sentMsg = await sock.sendMessage(item.to_jid, payload);
            this.lastSendAt.set(item.instance_id, Date.now());

            const waMsgId = sentMsg?.key?.id || `unknown_${Date.now()}`;
            await pool.query("UPDATE send_queue SET status = 'sent', processed_at = NOW() WHERE id = ?", [item.id]);
            await PhpApiClient.messageStatus({
                message_id: item.message_id,
                whatsapp_message_id: waMsgId,
                status: 'sent'
            });

        } catch (error: any) {
            logger.error({ error: this.safeErrorLog(error), queueId: item.id }, 'Failed to process queue item');
            const newAttempts = item.attempts + 1;
            const permanentFailure = this.isPermanentFailure(error);
            const newStatus = permanentFailure || newAttempts >= item.max_attempts ? 'failed' : 'pending';
            const retryAt = this.nextRetryAt(newAttempts);
            const errorMessage = this.humanErrorMessage(error);
            
            await pool.query(`
                UPDATE send_queue
                SET status = ?,
                    scheduled_at = ?,
                    processed_at = CASE WHEN ? = 'failed' THEN NOW() ELSE processed_at END,
                    error_message = ?
                WHERE id = ?
            `, [
                newStatus,
                newStatus === 'pending' ? retryAt : null,
                newStatus,
                errorMessage,
                item.id
            ]);
            
            if (newStatus === 'failed') {
                await PhpApiClient.messageStatus({
                    message_id: item.message_id,
                    status: 'failed',
                    error_message: errorMessage
                });
            }
        }
    }

    private static async recoverStaleProcessingItems() {
        await pool.query(`
            UPDATE send_queue
            SET status = 'pending',
                scheduled_at = NOW(),
                error_message = 'Recovered stale processing item'
            WHERE status = 'processing'
              AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
              AND attempts < max_attempts
        `, [this.processingTimeoutMinutes]);

        await pool.query(`
            UPDATE send_queue
            SET status = 'failed',
                processed_at = NOW(),
                error_message = COALESCE(error_message, 'Max attempts reached while processing')
            WHERE status = 'processing'
              AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
              AND attempts >= max_attempts
        `, [this.processingTimeoutMinutes]);
    }

    private static nextRetryAt(attempts: number): Date {
        const delayMs = Math.min(60000, Math.max(1000, Math.pow(2, attempts) * 1000));
        return new Date(Date.now() + delayMs);
    }

    private static async enforceInstancePacing(instanceId: number) {
        const lastSentAt = this.lastSendAt.get(instanceId) || 0;
        const waitMs = this.minimumSendIntervalMs - (Date.now() - lastSentAt);
        if (waitMs > 0) {
            await new Promise(resolve => setTimeout(resolve, waitMs));
        }
    }

    private static async assertRecipientConsent(instanceId: number, toJid: string) {
        const requireOptIn = String(process.env.MESSAGE_REQUIRE_OPT_IN || 'true').toLowerCase() !== 'false';
        if (!requireOptIn || (!toJid.endsWith('@s.whatsapp.net') && !toJid.endsWith('@c.us'))) {
            return;
        }

        const [rows]: any = await pool.query(`
            SELECT status
            FROM recipient_consents
            WHERE instance_id = ? AND jid = ?
            LIMIT 1
        `, [instanceId, toJid]);

        if (!rows[0] || rows[0].status !== 'opted_in') {
            const error: any = new Error('Recipient has no active opt-in');
            error.code = 'CONSENT_REQUIRED';
            throw error;
        }
    }

    private static isPermanentFailure(error: any): boolean {
        if (error?.code === 'CONSENT_REQUIRED') return true;
        const status = Number(error?.response?.status || error?.output?.statusCode || 0);
        return status >= 400 && status < 500 && status !== 408 && status !== 429;
    }

    private static humanErrorMessage(error: any): string {
        const status = Number(error?.response?.status || error?.output?.statusCode || 0);
        const url = error?.config?.url;
        if (status > 0) {
            return `Remote media request failed with HTTP ${status}${url ? ` (${url})` : ''}`;
        }

        return error?.message || 'Unknown error';
    }

    private static safeErrorLog(error: any) {
        return {
            message: error?.message || String(error),
            status: error?.response?.status || null,
            url: error?.config?.url || null,
            code: error?.code || null
        };
    }
}
