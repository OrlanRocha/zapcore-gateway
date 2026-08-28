import axios from 'axios';
import { config } from '../config';
import { logger } from '../utils/logger';

type MessageStatusPayload = {
    message_id?: number;
    instance_uuid?: string;
    whatsapp_message_id?: string;
    status: string;
    error_message?: string;
};

export class PhpApiClient {
    private static baseUrl = config.phpApiUrl;
    private static secret = config.phpInternalSecret;
    private static readonly requestTimeoutMs = 10000;
    private static readonly maxAttempts = 3;

    private static getHeaders() {
        return {
            'Content-Type': 'application/json',
            'Internal-Secret': this.secret
        };
    }

    private static async post(path: string, payload: unknown) {
        let lastError: unknown;

        for (let attempt = 1; attempt <= this.maxAttempts; attempt++) {
            try {
                return await axios.post(`${this.baseUrl}${path}`, payload, {
                    headers: this.getHeaders(),
                    timeout: this.requestTimeoutMs
                });
            } catch (error: any) {
                lastError = error;
                const status = Number(error?.response?.status || 0);
                const retryable = status === 0 || status === 408 || status === 429 || status >= 500;
                if (!retryable || attempt === this.maxAttempts) break;

                await new Promise(resolve => setTimeout(resolve, 500 * Math.pow(2, attempt - 1)));
            }
        }

        throw lastError;
    }

    public static async updateInstanceStatus(
        uuid: string,
        status: string,
        qr: string | null = null,
        extra: Record<string, unknown> = {}
    ) {
        try {
            await this.post(`/internal/instances/${uuid}/status`, {
                status,
                qr,
                ...extra
            });
        } catch (error) {
            logger.error({ error }, 'Error updating instance status');
        }
    }

    public static async messageReceived(uuid: string, message: unknown, media: Record<string, unknown> | null = null) {
        try {
            const response = await this.post('/internal/messages/received', {
                instance_uuid: uuid,
                message,
                media
            });
            return response.data;
        } catch (error) {
            logger.error({ error }, 'Error sending message received');
        }
    }

    public static async messageStatus(payload: MessageStatusPayload) {
        try {
            await this.post('/internal/messages/status', payload);
        } catch (error) {
            logger.error({ error, payload }, 'Error updating message status');
        }
    }

    public static async contactsSync(uuid: string, contacts: unknown[]) {
        try {
            await this.post('/internal/contacts/sync', {
                instance_uuid: uuid,
                contacts
            });
        } catch (error) {
            logger.error({ error, uuid, contactCount: contacts.length }, 'Error syncing contacts');
        }
    }

    public static async connectionLog(instanceUuid: string, event: string, description = '', rawJson: unknown = null) {
        try {
            await this.post('/internal/connection-log', {
                instance_uuid: instanceUuid,
                event,
                description,
                raw_json: rawJson
            });
        } catch (error) {
            logger.error({ error }, 'Error sending connection log');
        }
    }
}
