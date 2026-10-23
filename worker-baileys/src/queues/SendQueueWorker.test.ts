import assert from 'node:assert/strict';
import test from 'node:test';
import { SendQueueWorker } from './SendQueueWorker';

test('classifies unsafe legacy remote media as a permanent failure', () => {
    const error: any = new Error('Remote media URLs are blocked');
    error.code = 'UNSAFE_REMOTE_MEDIA';

    assert.equal(SendQueueWorker.isPermanentFailure(error), true);
});
