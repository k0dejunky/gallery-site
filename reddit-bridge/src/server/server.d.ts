import type { IncomingMessage, ServerResponse } from 'node:http';
export type PublishImage = {
    name: string;
    type: string;
    b64: string;
};
export type PublishRequest = {
    subreddit: string;
    title: string;
    body: string;
    image: PublishImage | null;
    nsfw: boolean;
    siteUrl?: string;
};
export type PublishResponse = {
    ok: boolean;
    postId?: string;
    url?: string;
    error?: string;
};
export declare function onReq(reqMsg: IncomingMessage, rspMsg: ServerResponse): Promise<void>;
