import { AuthenticationState, initAuthCreds, SignalDataTypeMap, BufferJSON, proto } from '@whiskeysockets/baileys';
import pool from '../db';
import crypto from 'crypto';
import { config } from '../config';
import { logger } from '../utils/logger';

export const useMySQLAuthState = async (instanceId: number): Promise<{ state: AuthenticationState, saveCreds: () => Promise<void> }> => {
    const writeData = async (data: any, id: string) => {
        try {
            const value = encrypt(JSON.stringify(data, BufferJSON.replacer));
            const authType = id.includes('-') ? id.split('-')[0] : 'creds';
            
            await pool.query(`
                INSERT INTO baileys_auth (instance_id, auth_type, auth_key, value_json, encrypted)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE value_json = ?, encrypted = 1
            `, [instanceId, authType, id, value, value]);
        } catch (error) {
            logger.error({ error }, 'Error saving auth state');
        }
    };

    const readData = async (id: string) => {
        try {
            const [rows]: any = await pool.query(
                `SELECT value_json, encrypted FROM baileys_auth WHERE instance_id = ? AND auth_key = ?`, 
                [instanceId, id]
            );
            
            if (rows.length && rows[0].value_json) {
                const raw = rows[0].encrypted ? decrypt(rows[0].value_json) : rows[0].value_json;
                return JSON.parse(raw, BufferJSON.reviver);
            }
        } catch (error) {
            logger.error({ error }, 'Error reading auth state');
        }
        return null;
    };

    const removeData = async (id: string) => {
        try {
            await pool.query(`DELETE FROM baileys_auth WHERE instance_id = ? AND auth_key = ?`, [instanceId, id]);
        } catch (error) {
            logger.error({ error }, 'Error removing auth state');
        }
    };

    let creds: any = await readData('creds');
    if (!creds) {
        creds = initAuthCreds();
        await writeData(creds, 'creds');
    }

    return {
        state: {
            creds,
            keys: {
                get: async (type, ids) => {
                    const data: { [_: string]: SignalDataTypeMap[typeof type] } = {};
                    await Promise.all(
                        ids.map(async (id) => {
                            let value = await readData(`${type}-${id}`);
                            if (type === 'app-state-sync-key' && value) {
                                value = importSyncKey(value);
                            }
                            data[id] = value;
                        })
                    );
                    return data;
                },
                set: async (data) => {
                    const tasks: Promise<void>[] = [];
                    for (const category in data) {
                        for (const id in data[category as keyof SignalDataTypeMap]) {
                            const value = data[category as keyof SignalDataTypeMap]![id];
                            const key = `${category}-${id}`;
                            if (value) {
                                tasks.push(writeData(value, key));
                            } else {
                                tasks.push(removeData(key));
                            }
                        }
                    }
                    await Promise.all(tasks);
                }
            }
        },
        saveCreds: () => {
            return writeData(creds, 'creds');
        }
    };
};

function importSyncKey(data: any) {
    return proto.Message.AppStateSyncKeyData.fromObject(data);
}

function key(): Buffer {
    return crypto.createHash('sha256').update(config.appKey).digest();
}

function encrypt(plainText: string): string {
    const iv = crypto.randomBytes(12);
    const cipher = crypto.createCipheriv('aes-256-gcm', key(), iv);
    const encrypted = Buffer.concat([cipher.update(plainText, 'utf8'), cipher.final()]);
    const tag = cipher.getAuthTag();

    return JSON.stringify({
        cipher: 'aes-256-gcm',
        iv: iv.toString('base64'),
        tag: tag.toString('base64'),
        data: encrypted.toString('base64')
    });
}

function decrypt(payload: string): string {
    const data = JSON.parse(payload);
    const decipher = crypto.createDecipheriv(
        'aes-256-gcm',
        key(),
        Buffer.from(data.iv, 'base64')
    );
    decipher.setAuthTag(Buffer.from(data.tag, 'base64'));

    return Buffer.concat([
        decipher.update(Buffer.from(data.data, 'base64')),
        decipher.final()
    ]).toString('utf8');
}
