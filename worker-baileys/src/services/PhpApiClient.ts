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

    private static getHeaders() {
        return {
            'Content-Type': 'application/json',
            'Internal-Secret': this.secret
        };
    }

    public static async updateInstanceStatus(
        uuid: string,
        status: string,
        qr: string | null = null,
        extra: Record<string, unknown> = {}
    ) {
        try {
            await axios.post(`${this.baseUrl}/internal/instances/${uuid}/status`, {
                status,
                qr,
                ...extra
            }, { headers: this.getHeaders() });
        } catch (error) {
            logger.error({ error }, 'Error updating instance status');
        }
    }

    public static async messageReceived(uuid: string, message: unknown, media: Record<string, unknown> | null = null) {
        try {
            const response = await axios.post(`${this.baseUrl}/internal/messages/received`, {
                instance_uuid: uuid,
                message,
                media
            }, { headers: this.getHeaders() });
            return response.data;
        } catch (error) {
            logger.error({ error }, 'Error sending message received');
        }
    }

    public static async messageStatus(payload: MessageStatusPayload) {
        try {
            await axios.post(`${this.baseUrl}/internal/messages/status`, payload, {
                headers: this.getHeaders()
            });
        } catch (error) {
            logger.error({ error, payload }, 'Error updating message status');
        }
    }

    public static async contactsSync(uuid: string, contacts: unknown[]) {
        try {
            await axios.post(`${this.baseUrl}/internal/contacts/sync`, {
                instance_uuid: uuid,
                contacts
            }, { headers: this.getHeaders() });
        } catch (error) {
            logger.error({ error, uuid, contactCount: contacts.length }, 'Error syncing contacts');
        }
    }

    public static async connectionLog(instanceUuid: string, event: string, description = '', rawJson: unknown = null) {
        try {
            await axios.post(`${this.baseUrl}/internal/connection-log`, {
                instance_uuid: instanceUuid,
                event,
                description,
                raw_json: rawJson
            }, { headers: this.getHeaders() });
        } catch (error) {
            logger.error({ error }, 'Error sending connection log');
        }
    }
}
