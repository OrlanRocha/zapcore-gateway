import makeWASocket, { DisconnectReason, fetchLatestBaileysVersion, Browsers, downloadMediaMessage, extractMessageContent } from '@whiskeysockets/baileys';
import QRCode from 'qrcode';
import { useMySQLAuthState } from './BaileysSessionStore';
import pool from '../db';
import { PhpApiClient } from '../services/PhpApiClient';
import { logger } from '../utils/logger';
import { jidToPhone, normalizePhoneNumber } from '../utils/jid';
import pino from 'pino';
import fs from 'fs/promises';
import path from 'path';
import crypto from 'crypto';

export type ConnectMode = 'qr' | 'pin';

export type ConnectOptions = {
    mode?: ConnectMode;
    phoneNumber?: string;
};

export type ConnectResult = {
    success: boolean;
    mode: ConnectMode;
    alreadyConnected?: boolean;
    pairingCode?: string | null;
    message?: string;
    error?: string;
};

export class InstanceManager {
    private static instances: Map<string, ReturnType<typeof makeWASocket>> = new Map();
    private static connected: Set<string> = new Set();
    private static connectionWatchdogs: Map<string, NodeJS.Timeout> = new Map();
    private static restarting: Set<string> = new Set();

    public static async connect(uuid: string, options: ConnectOptions = {}): Promise<ConnectResult> {
        const mode: ConnectMode = options.mode === 'pin' ? 'pin' : 'qr';
        let phoneNumber: string | null = null;

        try {
            phoneNumber = options.phoneNumber ? normalizePhoneNumber(options.phoneNumber) : null;

            if (mode === 'pin' && !phoneNumber) {
                return {
                    success: false,
                    mode,
                    error: 'phoneNumber is required for PIN Code connection'
                };
            }

            const existing = this.instances.get(uuid);
            if (existing) {
                if (existing.user && this.connected.has(uuid)) {
                    return {
                        success: true,
                        mode,
                        alreadyConnected: true,
                        pairingCode: null,
                        message: 'Instance already connected'
                    };
                }

                this.restarting.add(uuid);
                existing.end(new Error('Restarting QR session'));
                this.instances.delete(uuid);
                this.connected.delete(uuid);
            }

            // Get instance ID from DB
            const [rows]: any = await pool.query('SELECT id, status FROM instances WHERE uuid = ?', [uuid]);
            if (rows.length === 0) {
                return {
                    success: false,
                    mode,
                    error: 'Instance not found'
                };
            }
            const instanceId = rows[0].id;
            const currentStatus = rows[0].status;

            const { state, saveCreds } = await useMySQLAuthState(instanceId);
            const { version } = await fetchLatestBaileysVersion();
            const alreadyRegistered = Boolean(state.creds.registered);

            const sock = makeWASocket({
                version,
                logger: pino({ level: 'silent' }) as any,
                printQRInTerminal: false,
                auth: state,
                browser: Browsers.macOS('Desktop'),
                syncFullHistory: false
            });

            this.instances.set(uuid, sock);
            this.armConnectionWatchdog(uuid, sock, alreadyRegistered);
            
            if (currentStatus !== 'connected') {
                await PhpApiClient.updateInstanceStatus(uuid, 'connecting');
                await PhpApiClient.connectionLog(uuid, 'connecting', 'Baileys socket initializing');
            }

            sock.ev.on('creds.update', saveCreds);

            sock.ev.on('connection.update', async (update) => {
                const { connection, lastDisconnect, qr } = update;
                
                if (qr && mode !== 'pin') {
                    const qrBase64 = await QRCode.toDataURL(qr);
                    await PhpApiClient.updateInstanceStatus(uuid, 'waiting_qr', qrBase64);
                    await PhpApiClient.connectionLog(uuid, 'qr_generated', 'QR Code generated');
                }

                if (connection === 'close') {
                    this.connected.delete(uuid);
                    this.clearConnectionWatchdog(uuid);
                    if (this.restarting.has(uuid)) {
                        this.restarting.delete(uuid);
                        return;
                    }

                    const statusCode = (lastDisconnect?.error as any)?.output?.statusCode;
                    const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
                    
                    if (shouldReconnect) {
                        logger.info({ uuid }, 'Connection closed; reconnecting instance');
                        await PhpApiClient.updateInstanceStatus(uuid, 'disconnected');
                        await PhpApiClient.connectionLog(uuid, 'reconnecting', 'Connection closed; reconnect scheduled', update);
                        this.instances.delete(uuid);
                        setTimeout(() => this.connect(uuid), 5000);
                    } else {
                        logger.info({ uuid }, 'Instance logged out');
                        await pool.query('DELETE FROM baileys_auth WHERE instance_id = ?', [instanceId]);
                        await PhpApiClient.updateInstanceStatus(uuid, 'logged_out');
                        await PhpApiClient.connectionLog(uuid, 'logged_out', 'Device logged out');
                        this.instances.delete(uuid);
                    }
                } else if (connection === 'open') {
                    this.connected.add(uuid);
                    this.clearConnectionWatchdog(uuid);
                    logger.info({ uuid }, 'Instance connected');
                    await PhpApiClient.updateInstanceStatus(uuid, 'connected', null, {
                        phone_number: jidToPhone(sock.user?.id),
                        profile_name: sock.user?.name || null
                    });
                    await PhpApiClient.connectionLog(uuid, 'connected', 'Baileys socket connected');
                }
            });

            sock.ev.on('messages.upsert', async (m) => {
                logger.info({ uuid, upsertType: m.type, count: m.messages.length, fromMe: m.messages.map((msg) => Boolean(msg.key.fromMe)) }, 'Messages upsert received');
                if (m.type === 'notify') {
                    for (const msg of m.messages) {
                        if (msg.message) {
                            const media = await this.persistIncomingMedia(uuid, sock, msg);
                            await PhpApiClient.messageReceived(uuid, msg, media);
                        }
                    }
                }
            });

            sock.ev.on('messaging-history.set', async ({ messages }) => {
                const cutoff = Math.floor(Date.now() / 1000) - (36 * 60 * 60);
                const recentInbound = messages
                    .filter((msg) => !msg.key.fromMe && msg.message && Number(msg.messageTimestamp || 0) >= cutoff)
                    .slice(-1000);

                logger.info({ uuid, received: messages.length, selected: recentInbound.length }, 'Processing recent history sync');
                for (const msg of recentInbound) {
                    await PhpApiClient.messageReceived(uuid, msg, null);
                }
            });

            sock.ev.on('messages.update', async (updates) => {
                for (const item of updates) {
                    if (!item.key?.id || !item.update?.status) {
                        continue;
                    }

                    const status = this.mapMessageStatus(item.update.status);
                    if (status) {
                        await PhpApiClient.messageStatus({
                            instance_uuid: uuid,
                            whatsapp_message_id: item.key.id,
                            status
                        });
                    }
                }
            });

            sock.ev.on('contacts.upsert', async (contacts) => {
                await this.syncContacts(uuid, contacts);
            });

            sock.ev.on('contacts.update', async (contacts) => {
                await this.syncContacts(uuid, contacts);
            });

            let pairingCode: string | null = null;
            if (mode === 'pin') {
                if (alreadyRegistered) {
                    await PhpApiClient.connectionLog(uuid, 'connecting', 'PIN Code skipped because the Baileys auth state is already registered');
                } else {
                    await sock.waitForSocketOpen();
                    await this.sleep(1500);
                    pairingCode = await sock.requestPairingCode(phoneNumber as string);
                    await PhpApiClient.updateInstanceStatus(uuid, 'connecting', null, {
                        phone_number: phoneNumber
                    });
                    await PhpApiClient.connectionLog(uuid, 'connecting', 'PIN Code generated for WhatsApp pairing');
                }
            }

            return {
                success: true,
                mode,
                pairingCode,
                message: pairingCode ? 'PIN Code generated' : 'Connection initiated'
            };

        } catch (error) {
            logger.error({ error, uuid }, 'Error connecting instance');
            await PhpApiClient.updateInstanceStatus(uuid, 'error');
            await PhpApiClient.connectionLog(uuid, 'error', 'Error connecting instance', { error: String(error) });
            this.instances.delete(uuid);
            return {
                success: false,
                mode,
                error: error instanceof Error ? error.message : String(error)
            };
        }
    }

    public static async disconnect(uuid: string) {
        const sock = this.instances.get(uuid);
        this.connected.delete(uuid);
        this.clearConnectionWatchdog(uuid);
        if (sock) {
            await sock.logout();
            this.instances.delete(uuid);
        }
        await PhpApiClient.updateInstanceStatus(uuid, 'disconnected');
        await PhpApiClient.connectionLog(uuid, 'disconnected', 'Disconnect requested');
    }
    
    public static getInstance(uuid: string) {
        return this.connected.has(uuid) ? this.instances.get(uuid) : undefined;
    }

    public static status(uuid: string) {
        const sock = this.instances.get(uuid);
        return {
            uuid,
            connected: Boolean(sock) && this.connected.has(uuid),
            user: sock?.user || null
        };
    }

    private static armConnectionWatchdog(uuid: string, sock: ReturnType<typeof makeWASocket>, enabled: boolean): void {
        this.clearConnectionWatchdog(uuid);
        if (!enabled) return;

        const timer = setTimeout(() => {
            if (this.instances.get(uuid) !== sock || this.connected.has(uuid)) return;
            logger.warn({ uuid }, 'Connection attempt timed out; restarting socket');
            this.restarting.add(uuid);
            sock.end(new Error('Connection attempt timed out'));
            this.instances.delete(uuid);
            this.connected.delete(uuid);
            setTimeout(() => this.connect(uuid), 1000);
        }, 30000);
        timer.unref();
        this.connectionWatchdogs.set(uuid, timer);
    }

    private static clearConnectionWatchdog(uuid: string): void {
        const timer = this.connectionWatchdogs.get(uuid);
        if (timer) clearTimeout(timer);
        this.connectionWatchdogs.delete(uuid);
    }

    private static mapMessageStatus(status: number): 'sent' | 'delivered' | 'read' | null {
        if (status >= 4) {
            return 'read';
        }
        if (status === 3) {
            return 'delivered';
        }
        if (status === 2 || status === 1) {
            return 'sent';
        }
        return null;
    }

    private static sleep(ms: number): Promise<void> {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    private static async persistIncomingMedia(uuid: string, sock: any, msg: any): Promise<Record<string, unknown> | null> {
        const payload = (extractMessageContent(msg.message) || {}) as any;
        const entry = payload.imageMessage || payload.videoMessage || payload.audioMessage || payload.documentMessage || payload.stickerMessage;
        if (!entry) return null;
        const messageType = payload.imageMessage ? 'image' : payload.videoMessage ? 'video' : payload.audioMessage ? 'audio' : payload.stickerMessage ? 'sticker' : 'document';
        try {
            let buffer: Buffer | null = null;
            let lastError: unknown;
            for (let attempt = 1; attempt <= 3; attempt++) {
                try {
                    buffer = await downloadMediaMessage(msg, 'buffer', {}, { logger: pino({ level: 'silent' }) as any, reuploadRequest: (message: any) => sock.updateMediaMessage(message) }) as Buffer;
                    break;
                } catch (error) {
                    lastError = error;
                    if (attempt < 3) await this.sleep(attempt * 750);
                }
            }
            if (!buffer) throw lastError || new Error('Incoming media download returned no data');
            if (buffer.length > 32 * 1024 * 1024) return null;
            const extension = (entry.mimetype || '').split('/')[1]?.split(';')[0] || (messageType === 'sticker' ? 'webp' : 'bin');
            const relativePath = path.join(uuid, new Date().toISOString().slice(0, 7), `${crypto.randomUUID()}.${extension}`).replace(/\\/g, '/');
            const storageRoot = process.env.MEDIA_STORAGE_PATH || path.resolve(process.cwd(), '../backend-php/storage/media');
            const absolutePath = path.join(storageRoot, relativePath);
            await fs.mkdir(path.dirname(absolutePath), { recursive: true });
            await fs.writeFile(absolutePath, buffer);
            return { file_path: relativePath, file_name: entry.fileName || `${messageType}.${extension}`, mime_type: entry.mimetype || null, file_size: buffer.length };
        } catch (error) {
            logger.warn({ error, uuid, messageType }, 'Unable to download incoming media');
            return null;
        }
    }

    private static async syncContacts(uuid: string, contacts: any[]): Promise<void> {
        const normalized = contacts
            .filter((contact) => contact?.id || contact?.lid || contact?.jid)
            .map((contact) => ({
                id: contact.id || null,
                lid: contact.lid || null,
                jid: contact.jid || null,
                name: contact.name || null,
                notify: contact.notify || null,
                verifiedName: contact.verifiedName || null
            }));

        for (let offset = 0; offset < normalized.length; offset += 200) {
            await PhpApiClient.contactsSync(uuid, normalized.slice(offset, offset + 200));
        }
    }
}
