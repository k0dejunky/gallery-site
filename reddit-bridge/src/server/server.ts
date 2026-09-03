import {once} from 'node:events'
import type {IncomingMessage, ServerResponse} from 'node:http'
import {media, reddit} from '@devvit/web/server'

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
  postId?: string
  url?: string
  error?: string
}

type AnyRsp = PublishResponse

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

  if (path === '/external/on/publish') {
    if (reqMsg.method !== 'POST') {
      return writeJson(404, {ok: false, error: 'not found'}, rspMsg)
    }
    return routePublish(reqMsg, rspMsg)
  }

  return writeJson(404, {ok: false, error: 'not found'}, rspMsg)
}

async function routePublish(
  reqMsg: IncomingMessage,
  rspMsg: ServerResponse,
): Promise<void> {
  const req = await readJson<PublishRequest>(reqMsg)

  const subreddit = (req.subreddit ?? '').replace(/^r\//i, '').trim()
  if (!subreddit) {
    return writeJson(400, {ok: false, error: 'Missing subreddit'}, rspMsg)
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
      return writeJson(200, {
        ok: true,
        postId: post.id,
        url: `https://www.reddit.com/r/${subreddit}/comments/${post.id}`,
      }, rspMsg)
    }

    const post = await reddit.submitPost({
      subredditName: subreddit,
      title,
      nsfw: !!req.nsfw,
      text: req.body ?? '',
    })
    return writeJson(200, {
      ok: true,
      postId: post.id,
      url: `https://www.reddit.com/r/${subreddit}/comments/${post.id}`,
    }, rspMsg)
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err)
    return writeJson(500, {ok: false, error: msg}, rspMsg)
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