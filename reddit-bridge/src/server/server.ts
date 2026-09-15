import {once} from 'node:events'
import type {IncomingMessage, ServerResponse} from 'node:http'
import {media, reddit, settings} from '@devvit/web/server'

export type PublishImage = {
  name: string
  type: string
  b64: string
}

export type PublishRequest = {
  subreddit: string
  title: string
  body: string
  image: PublishImage | null
  nsfw: boolean
  siteUrl?: string
}

export type PublishResponse = {
  ok: boolean
  status?: string
  postId?: string
  url?: string
  error?: string
}

type AnyRsp = PublishResponse

const PUSH_PATH = '/external/on/publish'
const POLL_PATH = '/internal/scheduler/poll-queue'

export async function onReq(
  reqMsg: IncomingMessage,
  rspMsg: ServerResponse,
): Promise<void> {
  try {
    await route(reqMsg, rspMsg)
  } catch (err) {
    const msg = `server error; ${err instanceof Error ? err.stack : err}`
    console.error(msg)
    writeJson(500, {ok: false, error: msg}, rspMsg)
  }
}

async function route(
  reqMsg: IncomingMessage,
  rspMsg: ServerResponse,
): Promise<void> {
  const path = reqMsg.url ?? '/'
  const method = reqMsg.method ?? 'GET'

  if (path === PUSH_PATH) {
    if (method !== 'POST') {
      return writeJson(405, {ok: false, error: 'method not allowed'}, rspMsg)
    }
    return routePublish(reqMsg, rspMsg)
  }

  if (path === POLL_PATH) {
    if (method !== 'POST') {
      return writeJson(405, {ok: false, error: 'method not allowed'}, rspMsg)
    }
    return routePollQueue(reqMsg, rspMsg)
  }

  return writeJson(404, {ok: false, error: 'not found'}, rspMsg)
}

async function routePublish(
  reqMsg: IncomingMessage,
  rspMsg: ServerResponse,
): Promise<void> {
  const req = await readJson<PublishRequest>(reqMsg)
  const result = await submitToReddit(req)
  return writeJson(result.ok ? 200 : 500, result, rspMsg)
}

/**
 * Shared submission path for both the push (external endpoint) and pull
 * (scheduler poll) transports: uploads the first image when present, then
 * submits a link- or image-kind post and returns the Reddit post id. Never
 * throws; failures come back as {ok:false,error}.
 */
export async function submitToReddit(req: PublishRequest): Promise<AnyRsp> {
  const subreddit = (req.subreddit ?? '').replace(/^r\//i, '').trim()
  if (!subreddit) {
    return {ok: false, error: 'Missing subreddit'}
  }

  const title = (req.title ?? '').trim() || 'New upload'

  try {
    if (req.image && req.image.b64) {
      const dataUrl = `data:${req.image.type || 'image/png'};base64,${req.image.b64}`
      const asset = await media.upload({url: dataUrl, type: 'image'})
      const post = await reddit.submitPost({
        subredditName: subreddit,
        title,
        nsfw: !!req.nsfw,
        kind: 'image',
        imageUrls: [asset.mediaUrl],
      })
      return {
        ok: true,
        postId: post.id,
        url: `https://www.reddit.com/r/${subreddit}/comments/${post.id}`,
      }
    }

    const post = await reddit.submitPost({
      subredditName: subreddit,
      title,
      nsfw: !!req.nsfw,
      text: req.body ?? '',
    })
    return {
      ok: true,
      postId: post.id,
      url: `https://www.reddit.com/r/${subreddit}/comments/${post.id}`,
    }
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err)
    return {ok: false, error: msg}
  }
}

type NextItem = {
  id: number
  claim: string
  sentAt?: string
  subreddit: string
  title: string
  body: string
  nsfw: boolean
  siteUrl?: string
  image: PublishImage | null
}

/**
 * Pull-mode poll: fired on a cron schedule by the platform. Asks the gallery
 * site for the next due reddit queue row, submits it exactly like the push
 * path, then reports the outcome back so the site records posted/failed.
 */
async function routePollQueue(
  reqMsg: IncomingMessage,
  rspMsg: ServerResponse,
): Promise<void> {
  const baseUrl = String(
    (await settings.get('galleryBaseUrl')) ?? 'https://amethyst2213.com',
  ).replace(/\/+$/, '')
  const secret = String((await settings.get('pullSecret')) ?? '').trim()

  if (!baseUrl || !secret) {
    console.error('poll-queue: not configured (galleryBaseUrl + pullSecret)')
    return writeJson(200, {ok: true, status: 'not-configured'}, rspMsg)
  }

  const installSub = String(
    reqMsg.headers['devvit-subreddit-name'] ?? '',
  ).trim()

  let response: Response
  try {
    response = await fetch(`${baseUrl}/webhooks/reddit/next`, {
      method: 'GET',
      headers: {
        Authorization: `Bearer ${secret}`,
        Accept: 'application/json',
        ...(installSub ? {'X-Devvit-Subreddit': installSub} : {}),
      },
      signal: AbortSignal.timeout(30_000),
    })
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err)
    console.error(`poll-queue: next fetch failed: ${msg}`)
    return writeJson(200, {ok: true, status: 'error', error: msg}, rspMsg)
  }

  if (!response.ok) {
    console.error(
      `poll-queue: next returned HTTP ${response.status} (${
        response.statusText ?? ''
      })`,
    )
    return writeJson(200, {ok: true, status: 'error'}, rspMsg)
  }

  let data: {status?: string; item?: NextItem} | null = null
  try {
    data = (await response.json()) as {status?: string; item?: NextItem}
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err)
    console.error(`poll-queue: bad next payload: ${msg}`)
    return writeJson(200, {ok: true, status: 'error', error: msg}, rspMsg)
  }

  if (data?.status !== 'pending' || !data.item) {
    return writeJson(200, {ok: true, status: data?.status ?? 'empty'}, rspMsg)
  }

  const item = data.item
  const result = await submitToReddit({
    subreddit: item.subreddit,
    title: item.title,
    body: item.body ?? '',
    image: item.image ?? null,
    nsfw: !!item.nsfw,
    siteUrl: item.siteUrl ?? baseUrl,
  })

  await reportOutcome(baseUrl, secret, item.id, item.claim, result)

  return writeJson(result.ok ? 200 : 500, result, rspMsg)
}

async function reportOutcome(
  baseUrl: string,
  secret: string,
  id: number,
  claim: string,
  result: AnyRsp,
): Promise<void> {
  try {
    await fetch(`${baseUrl}/webhooks/reddit/report`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${secret}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        id,
        claim,
        ok: !!result.ok,
        url: result.url,
        error: result.error,
      }),
      signal: AbortSignal.timeout(30_000),
    })
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err)
    console.error(`poll-queue: report failed for #${id}: ${msg}`)
  }
}

async function readJson<T>(reqMsg: IncomingMessage): Promise<T> {
  const chunks: Uint8Array[] = []
  reqMsg.on('data', chunk => chunks.push(chunk))
  await once(reqMsg, 'end')
  return JSON.parse(`${Buffer.concat(chunks)}`) as T
}

function writeJson(
  status: number,
  json: AnyRsp,
  rsp: ServerResponse,
): void {
  const body = JSON.stringify(json)
  const len = Buffer.byteLength(body)
  rsp.writeHead(status, {
    'Content-Length': len,
    'Content-Type': 'application/json',
  })
  rsp.end(body)
}