import assert from 'node:assert/strict';
import { tmpdir } from 'node:os';
import test from 'node:test';
import { MessageService } from './MessageService';

test('internal send endpoint service rejects remote media payloads', async () => {
    await assert.rejects(
        () => MessageService.resolveOutgoingPayload({
            to: '5511999999999',
            payload: { image: { url: 'https://cdn.example.org/photo.jpg' } }
        }, tmpdir()),
        (error: any) => error?.code === 'UNSAFE_REMOTE_MEDIA'
    );
});

test('internal send endpoint service preserves text messages', async () => {
    const result = await MessageService.resolveOutgoingPayload({
        to: '5511999999999',
        text: ' hello '
    }, tmpdir());

    assert.equal(result.jid, '5511999999999@s.whatsapp.net');
    assert.deepEqual(result.payload, { text: 'hello' });
});
