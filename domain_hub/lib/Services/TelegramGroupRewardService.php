<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfTelegramGroupRewardException extends \RuntimeException
{
    private string $reason;

    public function __construct(string $reason, string $message = '', ?\Throwable $previous = null)
    {
        $this->reason = $reason;
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}

class CfTelegramGroupRewardService
{
    private const HISTORY_PER_PAGE = 10;
    private const TABLE_REWARDS = 'mod_cloudflare_telegram_group_rewards';
    private const TABLE_BINDINGS = 'mod_cloudflare_telegram_reward_bindings';
    private const TELEGRAM_API_BASE = 'https://api.telegram.org';

    public static function ensureTables(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE_REWARDS)) {
                $schema->create(self::TABLE_REWARDS, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned();
                    $table->string('group_link', 255)->default('');
                    $table->string('group_hash', 64)->default('');
                    $table->bigInteger('telegram_user_id')->unsigned();
                    $table->string('telegram_username', 64)->default('');
                    $table->integer('reward_amount')->unsigned()->default(0);
                    $table->integer('before_quota')->unsigned()->default(0);
                    $table->integer('after_quota')->unsigned()->default(0);
                    $table->string('status', 20)->default('granted');
                    $table->string('client_ip', 45)->nullable();
                    $table->string('user_agent', 255)->nullable();
                    $table->timestamps();
                    $table->index(['userid', 'id'], 'idx_cf_tg_reward_user_id');
                    $table->index(['group_hash'], 'idx_cf_tg_reward_group_hash');
                    $table->index(['group_hash', 'telegram_user_id'], 'idx_cf_tg_reward_group_telegram');
                    $table->unique(['userid', 'group_hash', 'status'], 'uniq_cf_tg_reward_user_group_status');
                });
            }

            if (!$schema->hasTable(self::TABLE_BINDINGS)) {
                $schema->create(self::TABLE_BINDINGS, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->unique();
                    $table->bigInteger('telegram_user_id')->unsigned()->unique();
                    $table->string('telegram_username', 64)->nullable();
                    $table->string('first_name', 255)->nullable();
                    $table->string('last_name', 255)->nullable();
                    $table->string('photo_url', 255)->nullable();
                    $table->integer('auth_date')->unsigned()->default(0);
                    $table->timestamps();
                    $table->index(['userid'], 'idx_cf_tg_binding_user');
                    $table->index(['telegram_user_id'], 'idx_cf_tg_binding_telegram_user');
                });
            }

            self::ensureColumns();
            self::ensureIndexes();
        } catch (\Throwable $e) {
        }
    }

    private static function ensureColumns(): void
    {
        try {
            $schema = Capsule::schema();
            if ($schema->hasTable(self::TABLE_REWARDS)) {
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'group_link')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('group_link', 255)->default('');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'group_hash')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('group_hash', 64)->default('');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'telegram_user_id')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->bigInteger('telegram_user_id')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'telegram_username')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('telegram_username', 64)->default('');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'reward_amount')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->integer('reward_amount')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'before_quota')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->integer('before_quota')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'after_quota')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->integer('after_quota')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'status')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('status', 20)->default('granted');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'client_ip')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('client_ip', 45)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'user_agent')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('user_agent', 255)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'created_at') || !$schema->hasColumn(self::TABLE_REWARDS, 'updated_at')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) use ($schema) {
                        if (!$schema->hasColumn(self::TABLE_REWARDS, 'created_at')) {
                            $table->timestamp('created_at')->nullable();
                        }
                        if (!$schema->hasColumn(self::TABLE_REWARDS, 'updated_at')) {
                            $table->timestamp('updated_at')->nullable();
                        }
                    });
                }
            }

            if ($schema->hasTable(self::TABLE_BINDINGS)) {
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'telegram_username')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->string('telegram_username', 64)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'first_name')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->string('first_name', 255)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'last_name')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->string('last_name', 255)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'photo_url')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->string('photo_url', 255)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'auth_date')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->integer('auth_date')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'created_at') || !$schema->hasColumn(self::TABLE_BINDINGS, 'updated_at')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) use ($schema) {
                        if (!$schema->hasColumn(self::TABLE_BINDINGS, 'created_at')) {
                            $table->timestamp('created_at')->nullable();
                        }
                        if (!$schema->hasColumn(self::TABLE_BINDINGS, 'updated_at')) {
                            $table->timestamp('updated_at')->nullable();
                        }
                    });
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private static function ensureIndexes(): void
    {
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_REWARDS . '` ADD INDEX `idx_cf_tg_reward_user_id` (`userid`, `id`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_REWARDS . '` ADD INDEX `idx_cf_tg_reward_group_hash` (`group_hash`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_REWARDS . '` ADD INDEX `idx_cf_tg_reward_group_telegram` (`group_hash`, `telegram_user_id`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_REWARDS . '` ADD UNIQUE `uniq_cf_tg_reward_user_group_status` (`userid`, `group_hash`, `status`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_BINDINGS . '` ADD UNIQUE `uniq_cf_tg_binding_userid` (`userid`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_BINDINGS . '` ADD UNIQUE `uniq_cf_tg_binding_telegram_user` (`telegram_user_id`)');
        } catch (\Throwable $e) {
        }
    }

    public static function isEnabled(array $moduleSettings): bool
    {
        return in_array(($moduleSettings['enable_telegram_group_reward'] ?? '0'), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function resolveRewardAmount(array $moduleSettings): int
    {
        return max(1, min(1000, (int) ($moduleSettings['telegram_group_reward_amount'] ?? 1)));
    }

    public static function resolveGroupLink(array $moduleSettings): string
    {
        return trim((string) ($moduleSettings['telegram_group_link'] ?? ''));
    }

    public static function resolveChatId(array $moduleSettings): string
    {
        return trim((string) ($moduleSettings['telegram_group_chat_id'] ?? ''));
    }

    public static function resolveBotUsername(array $moduleSettings): string
    {
        $username = trim((string) ($moduleSettings['telegram_group_bot_username'] ?? ''));
        if ($username !== '' && strpos($username, '@') === 0) {
            $username = ltrim($username, '@');
        }
        return $username;
    }

    public static function resolveBotToken(array $moduleSettings): string
    {
        $token = trim((string) ($moduleSettings['telegram_group_bot_token'] ?? ''));
        if ($token === '') {
            return '';
        }
        if (strpos($token, 'enc::') === 0) {
            $token = substr($token, strlen('enc::'));
            $token = trim((string) cfmod_decrypt_sensitive($token));
        }
        return $token;
    }

    private static function normalizeTelegramUsername(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            return '';
        }
        if (strpos($username, '@') === 0) {
            $username = ltrim($username, '@');
        }
        if (!preg_match('/^[A-Za-z0-9_]{5,64}$/', $username)) {
            return '';
        }
        return strtolower($username);
    }

    private static function resolveGroupHash(string $groupLink, string $chatId): string
    {
        $base = strtolower(trim($chatId));
        if ($base === '') {
            $base = strtolower(trim($groupLink));
        }
        return hash('sha256', $base);
    }

    private static function validateChatId(string $chatId): bool
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return false;
        }
        return (bool) preg_match('/^-?[0-9]{5,20}$/', $chatId);
    }

    private static function validateBotToken(string $botToken): bool
    {
        $botToken = trim($botToken);
        if ($botToken === '') {
            return false;
        }
        return (bool) preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,120}$/', $botToken);
    }

    private static function normalizeAuthPayload(array $authPayload): array
    {
        $result = [];
        foreach ($authPayload as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            $result[$key] = trim((string) $value);
        }
        return $result;
    }

    private static function buildDataCheckString(array $payload): string
    {
        $pairs = [];
        foreach ($payload as $key => $value) {
            if ($key === 'hash') {
                continue;
            }
            if ($value === '') {
                continue;
            }
            $pairs[$key] = $key . '=' . $value;
        }
        ksort($pairs, SORT_STRING);
        return implode("\n", array_values($pairs));
    }

    private static function verifyAuthPayload(array $authPayload, string $botToken, int $maxAgeSeconds): array
    {
        $payload = self::normalizeAuthPayload($authPayload);
        $telegramUserId = intval($payload['id'] ?? 0);
        $authDate = intval($payload['auth_date'] ?? 0);
        $hash = strtolower(trim((string) ($payload['hash'] ?? '')));

        if ($telegramUserId <= 0 || $authDate <= 0 || $hash === '') {
            throw new CfTelegramGroupRewardException('auth_invalid');
        }

        if ($maxAgeSeconds > 0 && (time() - $authDate) > $maxAgeSeconds) {
            throw new CfTelegramGroupRewardException('auth_expired');
        }

        $dataCheckString = self::buildDataCheckString($payload);
        if ($dataCheckString === '') {
            throw new CfTelegramGroupRewardException('auth_invalid');
        }

        $secretKey = hash('sha256', $botToken, true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
        if (!hash_equals($expectedHash, $hash)) {
            throw new CfTelegramGroupRewardException('auth_invalid');
        }

        return [
            'telegram_user_id' => $telegramUserId,
            'telegram_username' => self::normalizeTelegramUsername((string) ($payload['username'] ?? '')),
            'first_name' => trim((string) ($payload['first_name'] ?? '')),
            'last_name' => trim((string) ($payload['last_name'] ?? '')),
            'photo_url' => trim((string) ($payload['photo_url'] ?? '')),
            'auth_date' => $authDate,
        ];
    }

    private static function telegramApiRequest(string $botToken, string $method, array $params): array
    {
        if (!function_exists('curl_init')) {
            throw new CfTelegramGroupRewardException('verify_failed', 'curl_missing');
        }

        $url = rtrim(self::TELEGRAM_API_BASE, '/') . '/bot' . rawurlencode($botToken) . '/' . $method;
        $curl = curl_init();
        if ($curl === false) {
            throw new CfTelegramGroupRewardException('verify_failed', 'curl_init_failed');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new CfTelegramGroupRewardException('verify_failed', $error !== '' ? $error : 'curl_exec_failed');
        }

        $payload = json_decode((string) $body, true);
        if (!is_array($payload)) {
            throw new CfTelegramGroupRewardException('verify_failed', 'invalid_json');
        }

        $payload['http_status'] = $status;
        return $payload;
    }

    private static function verifyMembership(string $botToken, string $chatId, int $telegramUserId): array
    {
        $response = self::telegramApiRequest($botToken, 'getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $telegramUserId,
        ]);

        $httpStatus = (int) ($response['http_status'] ?? 0);
        $ok = !empty($response['ok']);
        if (!$ok) {
            $errorCode = (int) ($response['error_code'] ?? $httpStatus);
            if ($errorCode === 429) {
                throw new CfTelegramGroupRewardException('verify_rate_limited');
            }
            $description = strtolower(trim((string) ($response['description'] ?? '')));
            if ($description !== '' && (strpos($description, 'user not found') !== false || strpos($description, 'participant') !== false)) {
                throw new CfTelegramGroupRewardException('member_not_found');
            }
            if ($errorCode === 400 || $errorCode === 404) {
                throw new CfTelegramGroupRewardException('not_joined');
            }
            throw new CfTelegramGroupRewardException('verify_failed', $description !== '' ? $description : ('http_' . $httpStatus));
        }

        $member = is_array($response['result'] ?? null) ? $response['result'] : [];
        $status = strtolower(trim((string) ($member['status'] ?? '')));
        if (!in_array($status, ['creator', 'administrator', 'member', 'restricted'], true)) {
            throw new CfTelegramGroupRewardException('not_joined');
        }

        return [
            'status' => $status,
            'member' => $member,
        ];
    }

    private static function hasColumn(string $table, string $column): bool
    {
        try {
            return Capsule::schema()->hasTable($table) && Capsule::schema()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function getBindingForUser(int $userId): array
    {
        $result = [
            'bound' => false,
            'telegram_user_id' => 0,
            'telegram_username' => '',
            'first_name' => '',
            'last_name' => '',
            'auth_date' => 0,
            'updated_at' => '',
        ];
        if ($userId <= 0) {
            return $result;
        }

        self::ensureTables();

        try {
            $row = Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->first();
            if (!$row) {
                return $result;
            }
            return [
                'bound' => true,
                'telegram_user_id' => (int) ($row->telegram_user_id ?? 0),
                'telegram_username' => (string) ($row->telegram_username ?? ''),
                'first_name' => (string) ($row->first_name ?? ''),
                'last_name' => (string) ($row->last_name ?? ''),
                'auth_date' => (int) ($row->auth_date ?? 0),
                'updated_at' => (string) ($row->updated_at ?? ''),
            ];
        } catch (\Throwable $e) {
            return $result;
        }
    }

    public static function getUserClaimState(int $userId, array $moduleSettings): array
    {
        $groupLink = self::resolveGroupLink($moduleSettings);
        $chatId = self::resolveChatId($moduleSettings);
        $state = [
            'enabled' => self::isEnabled($moduleSettings),
            'group_link' => $groupLink,
            'chat_id' => $chatId,
            'reward_amount' => self::resolveRewardAmount($moduleSettings),
            'claimed' => false,
            'telegram_bound' => false,
            'telegram_user_id' => 0,
            'telegram_username' => '',
            'bot_username' => self::resolveBotUsername($moduleSettings),
        ];

        if ($userId <= 0 || !$state['enabled']) {
            return $state;
        }

        self::ensureTables();

        $binding = self::getBindingForUser($userId);
        $state['telegram_bound'] = !empty($binding['bound']);
        $state['telegram_user_id'] = (int) ($binding['telegram_user_id'] ?? 0);
        $state['telegram_username'] = (string) ($binding['telegram_username'] ?? '');

        $groupHash = self::resolveGroupHash($groupLink, $chatId);

        try {
            $query = Capsule::table(self::TABLE_REWARDS)
                ->where('userid', $userId)
                ->where('status', 'granted');
            if (self::hasColumn(self::TABLE_REWARDS, 'group_hash')) {
                $query->where('group_hash', $groupHash);
            } else {
                $query->where('group_link', $groupLink);
            }
            $row = $query->orderBy('id', 'desc')->first();
            $state['claimed'] = !empty($row);
            if ($row && $state['telegram_username'] === '' && self::hasColumn(self::TABLE_REWARDS, 'telegram_username')) {
                $state['telegram_username'] = (string) ($row->telegram_username ?? '');
            }
            if ($row && $state['telegram_user_id'] <= 0 && self::hasColumn(self::TABLE_REWARDS, 'telegram_user_id')) {
                $state['telegram_user_id'] = (int) ($row->telegram_user_id ?? 0);
            }
        } catch (\Throwable $e) {
            $state['claimed'] = false;
        }

        return $state;
    }

    public static function claim(int $userId, array $moduleSettings, string $clientIp = '', string $userAgent = '', array $authPayload = []): array
    {
        if ($userId <= 0) {
            throw new CfTelegramGroupRewardException('invalid_user');
        }
        if (!self::isEnabled($moduleSettings)) {
            throw new CfTelegramGroupRewardException('disabled');
        }

        $groupLink = self::resolveGroupLink($moduleSettings);
        $chatId = self::resolveChatId($moduleSettings);
        $botToken = self::resolveBotToken($moduleSettings);
        $rewardAmount = self::resolveRewardAmount($moduleSettings);
        $groupHash = self::resolveGroupHash($groupLink, $chatId);

        if ($groupLink !== '' && preg_match('/^\s*javascript:/i', $groupLink)) {
            throw new CfTelegramGroupRewardException('invalid_group_link');
        }
        if (!self::validateChatId($chatId)) {
            throw new CfTelegramGroupRewardException('invalid_chat_id');
        }
        if (!self::validateBotToken($botToken)) {
            throw new CfTelegramGroupRewardException('invalid_bot_token');
        }

        self::ensureTables();

        $safeIp = trim($clientIp);
        $safeUa = trim($userAgent);
        if ($safeUa !== '') {
            $safeUa = function_exists('mb_substr') ? mb_substr($safeUa, 0, 255, 'UTF-8') : substr($safeUa, 0, 255);
        }
        $now = date('Y-m-d H:i:s');

        $maxAgeSeconds = max(60, min(7 * 86400, (int) ($moduleSettings['telegram_reward_auth_max_age_seconds'] ?? 86400)));

        $authData = null;
        $normalizedAuthPayload = self::normalizeAuthPayload($authPayload);
        $hasAuthPayload = false;
        foreach (['id', 'auth_date', 'hash'] as $requiredKey) {
            if (trim((string) ($normalizedAuthPayload[$requiredKey] ?? '')) !== '') {
                $hasAuthPayload = true;
                break;
            }
        }
        if ($hasAuthPayload) {
            $authData = self::verifyAuthPayload($normalizedAuthPayload, $botToken, $maxAgeSeconds);
        }

        $binding = self::getBindingForUser($userId);
        if ($authData === null) {
            if (empty($binding['bound']) || intval($binding['telegram_user_id'] ?? 0) <= 0) {
                throw new CfTelegramGroupRewardException('auth_required');
            }
            $authData = [
                'telegram_user_id' => (int) ($binding['telegram_user_id'] ?? 0),
                'telegram_username' => self::normalizeTelegramUsername((string) ($binding['telegram_username'] ?? '')),
                'first_name' => (string) ($binding['first_name'] ?? ''),
                'last_name' => (string) ($binding['last_name'] ?? ''),
                'photo_url' => '',
                'auth_date' => (int) ($binding['auth_date'] ?? 0),
            ];
        }

        $telegramUserId = (int) ($authData['telegram_user_id'] ?? 0);
        $telegramUsername = self::normalizeTelegramUsername((string) ($authData['telegram_username'] ?? ''));
        if ($telegramUserId <= 0) {
            throw new CfTelegramGroupRewardException('auth_invalid');
        }

        self::verifyMembership($botToken, $chatId, $telegramUserId);

        return Capsule::connection()->transaction(function () use ($userId, $moduleSettings, $groupLink, $groupHash, $telegramUserId, $telegramUsername, $authData, $rewardAmount, $safeIp, $safeUa, $now) {
            $existingRewardQuery = Capsule::table(self::TABLE_REWARDS)
                ->where('userid', $userId)
                ->where('status', 'granted');
            if (self::hasColumn(self::TABLE_REWARDS, 'group_hash')) {
                $existingRewardQuery->where('group_hash', $groupHash);
            } else {
                $existingRewardQuery->where('group_link', $groupLink);
            }
            $existingReward = $existingRewardQuery->lockForUpdate()->first();
            if ($existingReward) {
                throw new CfTelegramGroupRewardException('already_claimed');
            }

            if (self::hasColumn(self::TABLE_REWARDS, 'telegram_user_id')) {
                $telegramUsedQuery = Capsule::table(self::TABLE_REWARDS)
                    ->where('status', 'granted')
                    ->where('userid', '<>', $userId)
                    ->where('telegram_user_id', $telegramUserId);
                if (self::hasColumn(self::TABLE_REWARDS, 'group_hash')) {
                    $telegramUsedQuery->where('group_hash', $groupHash);
                } else {
                    $telegramUsedQuery->where('group_link', $groupLink);
                }
                $telegramUsed = $telegramUsedQuery->lockForUpdate()->first();
                if ($telegramUsed) {
                    throw new CfTelegramGroupRewardException('telegram_used');
                }
            }

            $bindingExisting = Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            $bindingByTelegram = Capsule::table(self::TABLE_BINDINGS)
                ->where('telegram_user_id', $telegramUserId)
                ->lockForUpdate()
                ->first();
            if ($bindingByTelegram && intval($bindingByTelegram->userid ?? 0) !== $userId) {
                throw new CfTelegramGroupRewardException('telegram_used');
            }

            $bindingPayload = [
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $telegramUsername !== '' ? $telegramUsername : null,
                'first_name' => trim((string) ($authData['first_name'] ?? '')) !== '' ? trim((string) ($authData['first_name'] ?? '')) : null,
                'last_name' => trim((string) ($authData['last_name'] ?? '')) !== '' ? trim((string) ($authData['last_name'] ?? '')) : null,
                'photo_url' => trim((string) ($authData['photo_url'] ?? '')) !== '' ? trim((string) ($authData['photo_url'] ?? '')) : null,
                'auth_date' => max(0, intval($authData['auth_date'] ?? 0)),
                'updated_at' => $now,
            ];
            if ($bindingExisting) {
                Capsule::table(self::TABLE_BINDINGS)
                    ->where('id', intval($bindingExisting->id ?? 0))
                    ->update($bindingPayload);
            } else {
                $bindingPayload['userid'] = $userId;
                $bindingPayload['created_at'] = $now;
                Capsule::table(self::TABLE_BINDINGS)->insert($bindingPayload);
            }

            $quota = Capsule::table('mod_cloudflare_subdomain_quotas')
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            if (!$quota) {
                $baseMax = max(0, (int) ($moduleSettings['max_subdomain_per_user'] ?? 5));
                $inviteLimit = max(0, (int) ($moduleSettings['invite_bonus_limit_global'] ?? 5));
                $usedCount = 0;
                try {
                    $usedCount = (int) Capsule::table('mod_cloudflare_subdomain')->where('userid', $userId)->count();
                } catch (\Throwable $e) {
                    $usedCount = 0;
                }
                Capsule::table('mod_cloudflare_subdomain_quotas')->insert([
                    'userid' => $userId,
                    'used_count' => $usedCount,
                    'max_count' => $baseMax,
                    'invite_bonus_count' => 0,
                    'invite_bonus_limit' => $inviteLimit,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $quota = Capsule::table('mod_cloudflare_subdomain_quotas')
                    ->where('userid', $userId)
                    ->lockForUpdate()
                    ->first();
            }

            if (!$quota) {
                throw new CfTelegramGroupRewardException('quota_unavailable');
            }

            $beforeQuota = (int) ($quota->max_count ?? 0);
            $afterQuota = $beforeQuota + $rewardAmount;

            Capsule::table('mod_cloudflare_subdomain_quotas')
                ->where('userid', $userId)
                ->update([
                    'max_count' => $afterQuota,
                    'updated_at' => $now,
                ]);

            $insertData = [
                'userid' => $userId,
                'group_link' => $groupLink,
                'reward_amount' => $rewardAmount,
                'before_quota' => $beforeQuota,
                'after_quota' => $afterQuota,
                'status' => 'granted',
                'client_ip' => $safeIp !== '' ? $safeIp : null,
                'user_agent' => $safeUa !== '' ? $safeUa : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (self::hasColumn(self::TABLE_REWARDS, 'group_hash')) {
                $insertData['group_hash'] = $groupHash;
            }
            if (self::hasColumn(self::TABLE_REWARDS, 'telegram_user_id')) {
                $insertData['telegram_user_id'] = $telegramUserId;
            }
            if (self::hasColumn(self::TABLE_REWARDS, 'telegram_username')) {
                $insertData['telegram_username'] = $telegramUsername;
            }
            Capsule::table(self::TABLE_REWARDS)->insert($insertData);

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('client_telegram_group_reward', [
                    'group_link' => $groupLink,
                    'telegram_user_id' => $telegramUserId,
                    'telegram_username' => $telegramUsername,
                    'reward_amount' => $rewardAmount,
                    'before_quota' => $beforeQuota,
                    'after_quota' => $afterQuota,
                ], $userId, null);
            }

            return [
                'group_link' => $groupLink,
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $telegramUsername,
                'reward_amount' => $rewardAmount,
                'before_quota' => $beforeQuota,
                'after_quota' => $afterQuota,
            ];
        });
    }

    public static function getUserHistory(int $userId, int $page = 1, int $perPage = self::HISTORY_PER_PAGE): array
    {
        self::ensureTables();
        $page = max(1, $page);
        $perPage = max(1, min(30, $perPage));

        if ($userId <= 0) {
            return [
                'items' => [],
                'page' => 1,
                'perPage' => $perPage,
                'total' => 0,
                'totalPages' => 1,
            ];
        }

        $query = Capsule::table(self::TABLE_REWARDS)
            ->where('userid', $userId)
            ->orderBy('id', 'desc');

        $total = (int) $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) ($row->id ?? 0),
                'group_link' => (string) ($row->group_link ?? ''),
                'telegram_user_id' => (int) ($row->telegram_user_id ?? 0),
                'telegram_username' => (string) ($row->telegram_username ?? ''),
                'reward_amount' => (int) ($row->reward_amount ?? 0),
                'status' => (string) ($row->status ?? 'granted'),
                'before_quota' => (int) ($row->before_quota ?? 0),
                'after_quota' => (int) ($row->after_quota ?? 0),
                'created_at' => !empty($row->created_at) ? date('Y-m-d H:i', strtotime((string) $row->created_at)) : '',
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }
}
