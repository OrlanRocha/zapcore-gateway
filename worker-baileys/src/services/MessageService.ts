import { AnyMessageContent } from '@whiskeysockets/baileys';
import { ChatType, toWhatsappJid } from '../utils/jid';

export type SendMessagePayload = {
    to: string;
    chat_type?: ChatType;
    recipient_type?: ChatType;
    text?: string;
    payload?: AnyMessageContent;
};

export class MessageService {
    static buildOutgoingPayload(input: SendMessagePayload): { jid: string; payload: AnyMessageContent } {
        const jid = toWhatsappJid(input.to, input.chat_type || input.recipient_type);
        if (input.payload) {
            return { jid, payload: input.payload };
        }
        if (input.text && input.text.trim() !== '') {
            return { jid, payload: { text: input.text.trim() } };
        }
        throw new Error('A text or payload field is required');
    }
}
