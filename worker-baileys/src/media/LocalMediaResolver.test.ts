import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, symlink, truncate, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { resolveLocalMedia } from './LocalMediaResolver';

async function fixture() {
    const root = await mkdtemp(path.join(tmpdir(), 'zapcore-media-'));
    const mediaDir = path.join(root, 'outgoing', '1', '2026-10');
    await mkdir(mediaDir, { recursive: true });
    return { root, mediaDir, clean: () => rm(root, { recursive: true, force: true }) };
}

test('passes remote URL payloads through unchanged', async () => {
    const payload = { image: { url: 'https://cdn.example.org/photo.jpg' }, caption: 'hello' };
    assert.equal(await resolveLocalMedia(payload, 'unused'), payload);
});

test('resolves local image and document payloads', async () => {
    const fs = await fixture();
    try {
        await writeFile(path.join(fs.mediaDir, 'photo.jpg'), Buffer.from('image-data'));
        await writeFile(path.join(fs.mediaDir, 'report.pdf'), Buffer.from('pdf-data'));

        const image = await resolveLocalMedia({
            local_media_path: 'outgoing/1/2026-10/photo.jpg', media_type: 'image',
            mime_type: 'image/jpeg', file_name: 'photo.jpg', caption: 'caption'
        }, fs.root);
        assert.deepEqual(image, { image: Buffer.from('image-data'), caption: 'caption' });

        const document = await resolveLocalMedia({
            local_media_path: 'outgoing/1/2026-10/report.pdf', media_type: 'document',
            mime_type: 'application/pdf', file_name: 'report.pdf', caption: 'report'
        }, fs.root);
        assert.deepEqual(document, {
            document: Buffer.from('pdf-data'), mimetype: 'application/pdf',
            fileName: 'report.pdf', caption: 'report'
        });
    } finally {
        await fs.clean();
    }
});

for (const [name, relativePath] of [
    ['absolute path', path.resolve('outside.jpg')],
    ['path traversal', '../outside.jpg'],
    ['missing file', 'outgoing/1/2026-10/missing.jpg'],
] as const) {
    test(`rejects ${name}`, async () => {
        const fs = await fixture();
        try {
            await assert.rejects(
                resolveLocalMedia({ local_media_path: relativePath, media_type: 'image' }, fs.root),
                /invalid|not found/i
            );
        } finally {
            await fs.clean();
        }
    });
}

test('rejects a directory instead of a regular file', async () => {
    const fs = await fixture();
    try {
        await assert.rejects(resolveLocalMedia({
            local_media_path: 'outgoing/1/2026-10', media_type: 'image'
        }, fs.root), /regular file/i);
    } finally {
        await fs.clean();
    }
});

test('rejects a symlink that escapes the storage root', async (t) => {
    const fs = await fixture();
    const outside = await mkdtemp(path.join(tmpdir(), 'zapcore-outside-'));
    try {
        const outsideFile = path.join(outside, 'secret.jpg');
        await writeFile(outsideFile, 'secret');
        try {
            await symlink(outsideFile, path.join(fs.mediaDir, 'link.jpg'));
        } catch (error: any) {
            if (error?.code === 'EPERM') return t.skip('symlink creation is not permitted');
            throw error;
        }
        await assert.rejects(resolveLocalMedia({
            local_media_path: 'outgoing/1/2026-10/link.jpg', media_type: 'image'
        }, fs.root), /outside/i);
    } finally {
        await fs.clean();
        await rm(outside, { recursive: true, force: true });
    }
});

test('rejects files larger than 256 MiB before reading', async () => {
    const fs = await fixture();
    try {
        const largeFile = path.join(fs.mediaDir, 'large.mp4');
        await writeFile(largeFile, 'x');
        await truncate(largeFile, 268435457);
        await assert.rejects(resolveLocalMedia({
            local_media_path: 'outgoing/1/2026-10/large.mp4', media_type: 'video'
        }, fs.root), /256 MB/i);
    } finally {
        await fs.clean();
    }
});
