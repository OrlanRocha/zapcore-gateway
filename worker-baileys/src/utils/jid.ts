export type ChatType = 'user' | 'group' | 'newsletter';

export function toWhatsappJid(value: string, chatType?: ChatType): string {
    const trimmed = value.trim();
    if (trimmed.includes('@')) {
        const detected = detectChatType(trimmed);
        if (!detected) {
            throw new Error('Unsupported WhatsApp JID');
        }
        if (chatType && chatType !== detected) {
            throw new Error(`JID does not match chat type ${chatType}`);
        }
        return trimmed.toLowerCase();
    }

    if (chatType && chatType !== 'user') {
        throw new Error('Groups and newsletters require a full JID');
    }

    const number = normalizePhoneNumber(trimmed);
    return `${number}@s.whatsapp.net`;
}

export function detectChatType(jid: string): ChatType | null {
    const normalized = jid.trim().toLowerCase();
    if (normalized.endsWith('@s.whatsapp.net') || normalized.endsWith('@c.us')) {
        return 'user';
    }
    if (normalized.endsWith('@g.us')) {
        return 'group';
    }
    if (normalized.endsWith('@newsletter')) {
        return 'newsletter';
    }
    return null;
}

export function normalizePhoneNumber(value: string): string {
    const number = value.trim().replace(/\D+/g, '');
    if (!number || number.length < 10 || number.length > 15) {
        throw new Error('Invalid WhatsApp number');
    }

    return number;
}

export function jidToPhone(jid?: string | null): string | null {
    if (!jid) {
        return null;
    }

    const [left] = jid.split('@');
    const [phone] = (left || '').split(':');
    return phone ? phone.replace(/\D+/g, '') : null;
}
