import type { AnyMessageContent } from '@whiskeysockets/baileys';
import { readFile, realpath, stat } from 'node:fs/promises';
import path from 'node:path';

export const MAX_LOCAL_MEDIA_BYTES = 268435456;

export type QueueMediaPayload = Record<string, unknown> & {
    local_media_path?: string;
    media_type?: string;
    mime_type?: string;
    file_name?: string;
    caption?: string;
};

export type ResolvedMediaPayload = AnyMessageContent;

export async function resolveLocalMedia(payload: QueueMediaPayload, storageRoot: string): Promise<ResolvedMediaPayload> {
    if (!payload.local_media_path) return payload as AnyMessageContent;

    const relativePath = payload.local_media_path;
    if (path.isAbsolute(relativePath) || relativePath.includes('\0') || relativePath.split(/[\\/]+/).includes('..')) {
        throw new Error('Invalid local media path');
    }

    let root: string;
    let filePath: string;
    try {
        root = await realpath(storageRoot);
        filePath = await realpath(path.resolve(root, relativePath));
    } catch {
        throw new Error('Local media file not found');
    }

    const pathFromRoot = path.relative(root, filePath);
    if (pathFromRoot === '' || pathFromRoot.startsWith(`..${path.sep}`) || path.isAbsolute(pathFromRoot)) {
        throw new Error('Local media path resolves outside storage root');
    }

    const fileStat = await stat(filePath);
    if (!fileStat.isFile()) throw new Error('Local media path must reference a regular file');
    if (fileStat.size > MAX_LOCAL_MEDIA_BYTES) throw new Error('Local media file is too large; maximum is 256 MB');

    const mediaType = String(payload.media_type || '').toLowerCase();
    if (!['image', 'audio', 'video', 'document'].includes(mediaType)) throw new Error('Invalid local media type');

    const media = await readFile(filePath);
    const caption = typeof payload.caption === 'string' && payload.caption !== '' ? payload.caption : undefined;
    const mimetype = typeof payload.mime_type === 'string' && payload.mime_type !== '' ? payload.mime_type : undefined;

    switch (mediaType) {
        case 'image': return caption ? { image: media, caption } : { image: media };
        case 'video': return caption ? { video: media, caption } : { video: media };
        case 'audio': return { audio: media, ...(mimetype ? { mimetype } : {}) };
        case 'document': return {
            document: media,
            mimetype: mimetype || 'application/octet-stream',
            fileName: typeof payload.file_name === 'string' && payload.file_name !== '' ? payload.file_name : path.basename(filePath),
            ...(caption ? { caption } : {}),
        };
        default: throw new Error('Invalid local media type');
    }
}
