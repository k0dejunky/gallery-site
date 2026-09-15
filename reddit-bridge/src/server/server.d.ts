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
    status?: string;
    postId?: string;
    url?: string;
    error?: string;
};
type AnyRsp = PublishResponse;
export declare function onReq(reqMsg: IncomingMessage, rspMsg: ServerResponse): Promise<void>;
/**
 * Shared submission path for both the push (external endpoint) and pull
 * (scheduler poll) transports: uploads the first image when present, then
 * submits a link- or image-kind post and returns the Reddit post id. Never
 * throws; failures come back as {ok:false,error}.
 */
export declare function submitToReddit(req: PublishRequest): Promise<AnyRsp>;
export {};
