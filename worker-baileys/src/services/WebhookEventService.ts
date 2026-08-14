import { PhpApiClient } from './PhpApiClient';

export class WebhookEventService {
    static messageStatus(messageId: number, status: string, whatsappMessageId?: string, errorMessage?: string) {
        return PhpApiClient.messageStatus({
            message_id: messageId,
            status,
            whatsapp_message_id: whatsappMessageId,
            error_message: errorMessage
        });
    }

    static connectionLog(instanceUuid: string, event: string, description?: string, rawJson?: unknown) {
        return PhpApiClient.connectionLog(instanceUuid, event, description, rawJson);
    }
}
