import { Router } from 'express';
import { ConnectionService } from '../instances/ConnectionService';
import { InstanceManager } from '../instances/InstanceManager';
import { MessageService } from '../services/MessageService';
import { config } from '../config';
import { normalizePhoneNumber } from '../utils/jid';

export const internalRoutes = Router();

internalRoutes.use((req, res, next) => {
    const secret = req.header('Worker-Secret');
    if (!secret || !config.workerSecret || secret !== config.workerSecret) {
        return res.status(401).json({ success: false, error: 'Unauthorized worker access' });
    }
    next();
});

internalRoutes.post('/worker/instances/:uuid/connect', async (req, res) => {
    const mode = req.body?.mode === 'pin' ? 'pin' : 'qr';
    let phoneNumber: string | undefined;

    if (mode === 'pin') {
        try {
            phoneNumber = normalizePhoneNumber(String(req.body?.phone_number || req.body?.phoneNumber || ''));
        } catch {
            return res.status(422).json({ success: false, error: 'Valid phone_number with country code is required' });
        }
    }

    const result = await ConnectionService.connect(req.params.uuid, {
        mode,
        phoneNumber
    });

    if (!result.success) {
        return res.status(500).json({ success: false, error: result.error || 'Unable to initialize instance connection' });
    }

    res.json({
        success: true,
        data: {
            mode: result.mode,
            already_connected: Boolean(result.alreadyConnected),
            pairing_code: result.pairingCode || null
        },
        message: result.message || 'Connection initiated'
    });
});

internalRoutes.post('/worker/instances/:uuid/disconnect', async (req, res) => {
    await ConnectionService.disconnect(req.params.uuid);
    res.json({ success: true, message: 'Disconnected' });
});

internalRoutes.get('/worker/instances/:uuid/status', (req, res) => {
    res.json({ success: true, data: ConnectionService.status(req.params.uuid) });
});

internalRoutes.post('/worker/messages/send', async (req, res) => {
    const { instance_uuid: instanceUuid } = req.body;
    if (!instanceUuid) {
        return res.status(422).json({ success: false, error: 'instance_uuid is required' });
    }

    const sock = InstanceManager.getInstance(instanceUuid);
    if (!sock) {
        return res.status(409).json({ success: false, error: 'Instance socket not connected' });
    }

    try {
        const { jid, payload } = MessageService.buildOutgoingPayload(req.body);
        const sent = await sock.sendMessage(jid, payload);
        res.json({
            success: true,
            data: {
                whatsapp_message_id: sent?.key?.id || null,
                to_jid: jid
            }
        });
    } catch (error: any) {
        res.status(422).json({ success: false, error: error.message || 'Unable to send message' });
    }
});
