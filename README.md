# Telegram Bot — Vercel + Upstash Redis

## Project Structure

```
project/
├── api/
│   └── webhook.php   ← main bot file (copy webhook.php here)
├── vercel.json
└── README.md
```

## 1. Create Upstash Redis database

1. Go to https://console.upstash.com
2. Create a new **Redis** database (free tier is sufficient)
3. Copy **UPSTASH_REDIS_REST_URL** and **UPSTASH_REDIS_REST_TOKEN**

## 2. Set Vercel environment variables

In your Vercel project → Settings → Environment Variables, add:

| Variable              | Example Value                        |
|-----------------------|--------------------------------------|
| `BOT_TOKEN`           | `123456:ABC-your-bot-token`          |
| `CHANNEL_USERNAME`    | `@registan_abituriyent`              |
| `GROUP_ID`            | `-1003890628671`                     |
| `ADMIN_IDS`           | `6653845419,5240893523`              |
| `UPSTASH_REDIS_URL`   | `https://your-db.upstash.io`         |
| `UPSTASH_REDIS_TOKEN` | `AXXXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx` |

Or via CLI:
```bash
vercel env add BOT_TOKEN
vercel env add CHANNEL_USERNAME
vercel env add GROUP_ID
vercel env add ADMIN_IDS
vercel env add UPSTASH_REDIS_URL
vercel env add UPSTASH_REDIS_TOKEN
```

## 3. Deploy

```bash
npm i -g vercel
vercel --prod
```

## 4. Register webhook with Telegram

Replace `YOUR_BOT_TOKEN` and `YOUR_VERCEL_DOMAIN`:

```bash
curl -X POST "https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook" \
  -d "url=https://YOUR_VERCEL_DOMAIN/webhook" \
  -d "allowed_updates=[\"message\",\"callback_query\"]" \
  -d "drop_pending_updates=true"
```

## 5. Verify webhook

```bash
curl "https://api.telegram.org/botYOUR_BOT_TOKEN/getWebhookInfo"
```

## Architecture Notes

- **No file storage** — Vercel has an ephemeral filesystem.
- **Sessions** — stored in Upstash Redis with 1-hour TTL per user.
- **Leads** — sent directly to the Telegram group (no external DB needed).
- **Idempotency** — each `update_id` is locked in Redis for 60 seconds to prevent duplicate processing on Vercel retries.
- **Fast response** — HTTP 200 is flushed before bot logic runs, so Telegram never times out.
