<?php

declare(strict_types=1);

/**
 * Telegram Bot — Vercel Serverless (PHP)
 *
 * External storage: Upstash Redis (REST API — no SDK required)
 * Sessions are stored per user_id with a TTL of 1 hour.
 * Leads are pushed directly to the Telegram group (no DB needed).
 *
 * Required environment variables:
 *   API_TOKEN            — Telegram bot token
 *   CHANNEL_USERNAME     — e.g. @registan_abituriyent
 *   GROUP_ID             — Telegram group/channel id (negative number)
 *   ADMIN_IDS            — Comma-separated list of admin user ids
 *   UPSTASH_REDIS_URL    — https://your-db.upstash.io
 *   UPSTASH_REDIS_TOKEN  — Upstash REST token
 */

// ─────────────────────────────────────────────
// CONFIG
// ─────────────────────────────────────────────

final class Config
{
    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return ($value !== false && $value !== '') ? $value : $default;
    }

    public static function require(string $key): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            http_response_code(500);
            exit(json_encode(['error' => "Missing required env var: $key"]));
        }
        return $value;
    }

    public static function adminIds(): array
    {
        $raw = self::get('ADMIN_IDS', '');
        if ($raw === '') return [];
        return array_map('intval', array_filter(explode(',', $raw)));
    }
}

// ─────────────────────────────────────────────
// TELEGRAM API CLIENT
// ─────────────────────────────────────────────

final class Telegram
{
    private string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function send(string $method, array $params = []): array
    {
        $url = "https://api.telegram.org/bot{$this->token}/{$method}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno || $response === false) {
            error_log("Telegram API cURL error [$errno] for $method");
            return [];
        }

        return json_decode($response, true) ?? [];
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        return $this->send('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public function answerCallback(string $callbackId, string $text = '', bool $alert = false): array
    {
        return $this->send('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $alert ? 'true' : 'false',
        ]);
    }

    public function isSubscribed(int $userId, string $channelUsername): bool
    {
        $res = $this->send('getChatMember', [
            'chat_id' => $channelUsername,
            'user_id' => $userId,
        ]);

        $status = $res['result']['status'] ?? '';
        return in_array($status, ['member', 'administrator', 'creator'], true);
    }
}

// ─────────────────────────────────────────────
// SESSION STORE — Upstash Redis REST API
// ─────────────────────────────────────────────

final class SessionStore
{
    private const TTL = 3600; // 1 hour

    private string $baseUrl;
    private string $token;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
    }

    public function get(int $userId): array
    {
        $data = $this->redis('GET', "session:{$userId}");
        if ($data === null || $data === '') return [];

        return json_decode($data, true) ?? [];
    }

    public function save(int $userId, string $state, array $data = []): void
    {
        $payload = json_encode(['state' => $state, 'data' => $data]);
        $this->redis('SET', "session:{$userId}", $payload, 'EX', (string) self::TTL);
    }

    public function clear(int $userId): void
    {
        $this->redis('DEL', "session:{$userId}");
    }

    /**
     * Prevent duplicate update processing (idempotency key, TTL 60s).
     */
    public function markProcessed(int $updateId): bool
    {
        $result = $this->redis('SET', "upd:{$updateId}", '1', 'NX', 'EX', '60');
        return $result === 'OK';
    }

    private function redis(string ...$args): mixed
    {
        $encoded = array_map(fn($a) => rawurlencode((string) $a), $args);
        $url      = $this->baseUrl . '/' . implode('/', $encoded);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$this->token}",
            ],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno || $response === false) {
            error_log("Upstash Redis error [$errno]");
            return null;
        }

        $decoded = json_decode($response, true);
        return $decoded['result'] ?? null;
    }
}

// ─────────────────────────────────────────────
// BOT HANDLER
// ─────────────────────────────────────────────

final class BotHandler
{
    private Telegram     $tg;
    private SessionStore $store;
    private string       $channel;
    private int          $groupId;
    private array        $adminIds;

    public function __construct(
        Telegram     $tg,
        SessionStore $store,
        string       $channel,
        int          $groupId,
        array        $adminIds
    ) {
        $this->tg       = $tg;
        $this->store    = $store;
        $this->channel  = $channel;
        $this->groupId  = $groupId;
        $this->adminIds = $adminIds;
    }

    public function handle(array $update): void
    {
        // ── Idempotency guard ──────────────────────────────────────────
        $updateId = (int) ($update['update_id'] ?? 0);
        if ($updateId > 0 && !$this->store->markProcessed($updateId)) {
            return; // Already handled
        }

        // ── Callback query ─────────────────────────────────────────────
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        // ── Regular message ────────────────────────────────────────────
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    // ── Callback ──────────────────────────────────────────────────────

    private function handleCallback(array $cb): void
    {
        $userId     = (int) $cb['from']['id'];
        $callbackId = (string) $cb['id'];
        $data       = (string) ($cb['data'] ?? '');

        if ($data === 'check_sub') {
            if ($this->tg->isSubscribed($userId, $this->channel)) {
                $this->tg->answerCallback($callbackId);
                $this->tg->sendMessage($userId, '📝 Ism va familiyangizni kiriting:');
                $this->store->save($userId, 'name');
            } else {
                $this->tg->answerCallback($callbackId, '❌ Hali obuna bo\'lmadingiz', true);
            }
        }
    }

    // ── Message ───────────────────────────────────────────────────────

    private function handleMessage(array $msg): void
    {
        $chatId  = (int) $msg['chat']['id'];
        $userId  = (int) $msg['from']['id'];
        $text    = trim((string) ($msg['text'] ?? ''));
        $contact = $msg['contact']['phone_number'] ?? null;

        $session = $this->store->get($userId);
        $state   = (string) ($session['state'] ?? '');
        $data    = (array)  ($session['data']  ?? []);

        // ── /start ──────────────────────────────────────────────────

        if ($text === '/start') {
            if (!$this->tg->isSubscribed($userId, $this->channel)) {
                $this->tg->sendMessage($chatId, '❗️Avval kanalga obuna bo\'ling:', [
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '📢 Kanalga o\'tish', 'url' => "https://t.me/{$this->channelHandle()}"]],
                            [['text' => '✅ Tekshirish', 'callback_data' => 'check_sub']],
                        ],
                    ]),
                ]);
                return;
            }

            $this->tg->sendMessage($chatId, '📝 Ism va familiyangizni kiriting:');
            $this->store->save($userId, 'name');
            return;
        }

        // ── /leads (admin only) ──────────────────────────────────────

        if ($text === '/leads' && in_array($userId, $this->adminIds, true)) {
            $this->tg->sendMessage($chatId, 'ℹ️ Lidlar guruhga yetkazilmoqda. Barcha lidlar guruhda saqlanadi.');
            return;
        }

        // ── State machine ────────────────────────────────────────────

        match ($state) {
            'name'  => $this->stepName($chatId, $userId, $text, $data),
            'phone' => $this->stepPhone($chatId, $userId, $contact, $data),
            'extra' => $this->stepExtra($chatId, $userId, $text, $data),
            default => null,
        };
    }

    // ── Step: name ────────────────────────────────────────────────────

    private function stepName(int $chatId, int $userId, string $text, array $data): void
    {
        if (empty($text) || mb_strlen($text) < 2) {
            $this->tg->sendMessage($chatId, '❌ Iltimos, to\'liq ism va familiyangizni kiriting:');
            return;
        }

        $data['name'] = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $this->tg->sendMessage($chatId, '📞 Telefon raqamingizni yuboring:', [
            'reply_markup' => json_encode([
                'keyboard'          => [[['text' => '📱 Raqam yuborish', 'request_contact' => true]]],
                'resize_keyboard'   => true,
                'one_time_keyboard' => true,
            ]),
        ]);

        $this->store->save($userId, 'phone', $data);
    }

    // ── Step: phone (contact) ─────────────────────────────────────────

    private function stepPhone(int $chatId, int $userId, ?string $contact, array $data): void
    {
        if ($contact === null) {
            $this->tg->sendMessage($chatId, '❌ Iltimos, telefon raqamingizni tugma orqali yuboring:');
            return;
        }

        $data['phone'] = $contact;

        $this->tg->sendMessage($chatId, '📱 Qo\'shimcha raqam kiriting:', [
            'reply_markup' => json_encode(['remove_keyboard' => true]),
        ]);

        $this->store->save($userId, 'extra', $data);
    }

    // ── Step: extra phone ─────────────────────────────────────────────

    private function stepExtra(int $chatId, int $userId, string $text, array $data): void
    {
        $cleaned = preg_replace('/\D/', '', $text);

        if (strlen($cleaned) < 9) {
            $this->tg->sendMessage($chatId, '❌ Noto\'g\'ri raqam. Faqat raqamlarni kiriting (min. 9 ta):');
            return;
        }

        $data['extra'] = $cleaned;

        // Send lead to Telegram group
        $name  = $data['name']  ?? '—';
        $phone = $data['phone'] ?? '—';
        $extra = $data['extra'];
        $time  = date('Y-m-d H:i:s');

        $this->tg->sendMessage(
            $this->groupId,
            "🧾 <b>Yangi LID</b>\n\n" .
            "👤 <b>Ism:</b> {$name}\n" .
            "📞 <b>Asosiy raqam:</b> {$phone}\n" .
            "📱 <b>Qo'shimcha raqam:</b> {$extra}\n" .
            "🕐 <b>Vaqt:</b> {$time}"
        );

        // Confirm to user
        $this->tg->sendMessage($chatId, '✅ Tabriklaymiz! Siz muvaffaqiyatli ro\'yxatdan o\'tdingiz.');

        $this->store->clear($userId);
    }

    // ─────────────────────────────────────────────────────────────────

    private function channelHandle(): string
    {
        return ltrim($this->channel, '@');
    }
}

// ─────────────────────────────────────────────
// ENTRY POINT
// ─────────────────────────────────────────────

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method Not Allowed']));
}

// Parse update
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Empty body']));
}

$update = json_decode($rawBody, true);
if (!is_array($update)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid JSON']));
}

// Boot services
$tg = new Telegram(Config::require('API_TOKEN'));

$store = new SessionStore(
    Config::require('UPSTASH_REDIS_URL'),
    Config::require('UPSTASH_REDIS_TOKEN')
);

$handler = new BotHandler(
    $tg,
    $store,
    Config::require('CHANNEL_USERNAME'),
    (int) Config::require('GROUP_ID'),
    Config::adminIds()
);

// Respond immediately (Telegram requires < 5s)
http_response_code(200);
echo json_encode(['ok' => true]);

// Flush output before processing
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Process update
$handler->handle($update);