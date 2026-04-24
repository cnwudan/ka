<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfTelegramExpiryNoticeException extends \RuntimeException
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

class CfTelegramExpiryNoticeService
{
    public const TABLE_PREFERENCES = 'mod_cloudflare_renewal_notice_telegram_users';
    public const TABLE_LOGS = 'mod_cloudflare_expiry_telegram_notices';

    private const TELEGRAM_API_BASE = 'https://api.telegram.org';

    public static function ensureTables(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE_PREFERENCES)) {
                $schema->create(self::TABLE_PREFERENCES, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->unique();
                    $table->boolean('enabled')->default(0);
                    $table->boolean('bound')->default(0);
                    $table->bigInteger('telegram_user_id')->unsigned()->default(0);
                    $table->string('telegram_username', 64)->default('');
                    $table->integer('auth_date')->unsigned()->default(0);
                    $table->timestamps();
                    $table->index(['enabled', 'bound'], 'idx_cf_tg_expiry_pref_enabled_bound');
                    $table->index(['telegram_user_id'], 'idx_cf_tg_expiry_pref_telegram_user');
                });
            }

            if (!$schema->hasTable(self::TABLE_LOGS)) {
                $schema->create(self::TABLE_LOGS, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->default(0)->index();
                    $table->integer('subdomain_id')->unsigned()->index();
                    $table->string('reminder_key', 50)->index();
                    $table->dateTime('expires_at_snapshot')->nullable()->index();
                    $table->bigInteger('telegram_user_id')->unsigned()->default(0);
                    $table->dateTime('sent_at');
                    $table->timestamps();
                    $table->unique(['subdomain_id', 'reminder_key', 'expires_at_snapshot'], 'uniq_cf_tg_expiry_notice');
                });
            }
        } catch (\Throwable $e) {
        }
    }

    public static function isEnabled(array $settings): bool
    {
        $value = strtolower(trim((string) ($settings['renewal_notice_telegram_enabled'] ?? '')));
        return in_array($value, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function resolveBotUsername(array $settings): string
    {
        $username = trim((string) ($settings['renewal_notice_telegram_bot_username'] ?? ''));
        if ($username === '') {
            $username = trim((string) ($settings['telegram_group_bot_username'] ?? ''));
        }
        if ($username !== '' && strpos($username, '@') === 0) {
            $username = ltrim($username, '@');
        }
        return $username;
    }

    public static function resolveBotToken(array $settings): string
    {
        $token = trim((string) ($settings['renewal_notice_telegram_bot_token'] ?? ''));
        if ($token === '') {
            $token = trim((string) ($settings['telegram_group_bot_token'] ?? ''));
        }
        if ($token === '') {
            return '';
        }
        if (strpos($token, 'enc::') === 0) {
            $token = trim((string) cfmod_decrypt_sensitive(substr($token, strlen('enc::'))));
        }
        return $token;
    }

    public static function isBotConfigured(array $settings): bool
    {
        return self::validateBotToken(self::resolveBotToken($settings));
    }

    public static function parseDaysCsv(string $raw, int $maxItems = 3): array
    {
        $maxItems = max(1, min(10, $maxItems));
        $parts = preg_split('/[\s,，]+/', trim($raw)) ?: [];
        $result = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $day = intval($part);
            if ($day <= 0) {
                continue;
            }
            if (!in_array($day, $result, true)) {
                $result[] = $day;
                if (count($result) >= $maxItems) {
                    break;
                }
            }
        }
        return $result;
    }

    public static function normalizeDaysCsv(string $raw, int $maxItems = 3): string
    {
        $days = self::parseDaysCsv($raw, $maxItems);
        return implode(',', $days);
    }

    public static function parseConfiguredDays(array $settings, array $override = []): array
    {
        if (!empty($override)) {
            $result = [];
            foreach ($override as $value) {
                $day = intval($value);
                if ($day <= 0) {
                    continue;
                }
                if (!in_array($day, $result, true)) {
                    $result[] = $day;
                }
                if (count($result) >= 3) {
                    break;
                }
            }
            return $result;
        }

        return self::parseDaysCsv((string) ($settings['renewal_notice_telegram_days_csv'] ?? ''), 3);
    }

    public static function reminderKey(int $days): string
    {
        return 'day' . max(0, $days);
    }

    public static function getUserState(int $userId, array $settings): array
    {
        self::ensureTables();
        $daysList = self::parseConfiguredDays($settings);
        $binding = class_exists('CfTelegramGroupRewardService')
            ? CfTelegramGroupRewardService::getBindingForUser($userId)
            : ['bound' => false, 'telegram_user_id' => 0, 'telegram_username' => '', 'auth_date' => 0];

        $state = [
            'supported' => true,
            'feature_enabled' => self::isEnabled($settings),
            'bot_username' => self::resolveBotUsername($settings),
            'bot_configured' => self::isBotConfigured($settings),
            'days' => $daysList,
            'days_csv' => implode(',', $daysList),
            'enabled' => false,
            'bound' => !empty($binding['bound']),
            'telegram_user_id' => intval($binding['telegram_user_id'] ?? 0),
            'telegram_username' => (string) ($binding['telegram_username'] ?? ''),
            'updated_at' => '',
        ];

        if ($userId <= 0) {
            return $state;
        }

        $row = Capsule::table(self::TABLE_PREFERENCES)->where('userid', $userId)->first();
        if ($row) {
            $state['enabled'] = intval($row->enabled ?? 0) === 1;
            $state['bound'] = intval($row->bound ?? 0) === 1 || $state['bound'];
            if (intval($row->telegram_user_id ?? 0) > 0) {
                $state['telegram_user_id'] = intval($row->telegram_user_id ?? 0);
            }
            if (trim((string) ($row->telegram_username ?? '')) !== '') {
                $state['telegram_username'] = trim((string) ($row->telegram_username ?? ''));
            }
            $state['updated_at'] = (string) ($row->updated_at ?? '');
        }

        if ($state['telegram_user_id'] <= 0 && !empty($binding['telegram_user_id'])) {
            $state['telegram_user_id'] = intval($binding['telegram_user_id']);
        }
        if ($state['telegram_username'] === '' && !empty($binding['telegram_username'])) {
            $state['telegram_username'] = (string) $binding['telegram_username'];
        }

        self::upsertPreferenceSnapshot($userId, [
            'enabled' => $state['enabled'] ? 1 : 0,
            'bound' => $state['bound'] ? 1 : 0,
            'telegram_user_id' => max(0, intval($state['telegram_user_id'])),
            'telegram_username' => self::normalizeTelegramUsername((string) $state['telegram_username']),
            'auth_date' => max(0, intval($binding['auth_date'] ?? 0)),
        ]);

        return $state;
    }

    public static function updateUserPreference(int $userId, array $settings, bool $enabled, array $authPayload = []): array
    {
        self::ensureTables();

        if ($userId <= 0) {
            throw new CfTelegramExpiryNoticeException('invalid_user');
        }

        if (!self::isEnabled($settings)) {
            throw new CfTelegramExpiryNoticeException('disabled');
        }

        if (!self::isBotConfigured($settings)) {
            throw new CfTelegramExpiryNoticeException('invalid_bot_token');
        }

        $days = self::parseConfiguredDays($settings);
        if (empty($days)) {
            throw new CfTelegramExpiryNoticeException('no_days_configured');
        }

        $hasAuth = false;
        foreach (['id', 'auth_date', 'hash'] as $key) {
            if (trim((string) ($authPayload[$key] ?? '')) !== '') {
                $hasAuth = true;
                break;
            }
        }

        if ($hasAuth) {
            if (!class_exists('CfTelegramGroupRewardService')) {
                throw new CfTelegramExpiryNoticeException('service_missing');
            }

            $bindSettings = [
                'telegram_group_bot_username' => self::resolveBotUsername($settings),
                'telegram_group_bot_token' => self::resolveBotToken($settings),
                'telegram_reward_auth_max_age_seconds' => intval($settings['telegram_reward_auth_max_age_seconds'] ?? 86400),
            ];

            try {
                CfTelegramGroupRewardService::bindUserFromAuthPayload($userId, $bindSettings, $authPayload);
            } catch (CfTelegramGroupRewardException $e) {
                throw new CfTelegramExpiryNoticeException($e->getReason(), $e->getMessage(), $e);
            }
        }

        $binding = class_exists('CfTelegramGroupRewardService')
            ? CfTelegramGroupRewardService::getBindingForUser($userId)
            : ['bound' => false, 'telegram_user_id' => 0, 'telegram_username' => '', 'auth_date' => 0];

        $isBound = !empty($binding['bound']) && intval($binding['telegram_user_id'] ?? 0) > 0;
        if ($enabled && !$isBound) {
            throw new CfTelegramExpiryNoticeException('auth_required');
        }

        self::upsertPreferenceSnapshot($userId, [
            'enabled' => $enabled ? 1 : 0,
            'bound' => $isBound ? 1 : 0,
            'telegram_user_id' => $isBound ? intval($binding['telegram_user_id'] ?? 0) : 0,
            'telegram_username' => $isBound ? self::normalizeTelegramUsername((string) ($binding['telegram_username'] ?? '')) : '',
            'auth_date' => $isBound ? max(0, intval($binding['auth_date'] ?? 0)) : 0,
        ]);

        return self::getUserState($userId, $settings);
    }

    public static function sendReminderTelegram($record, array $settings, int $days, ?int $overrideTelegramUserId = null, bool $ignorePreference = false): array
    {
        if (!$record) {
            return ['success' => false, 'message' => 'record_missing'];
        }

        $subdomain = is_array($record) ? (object) $record : $record;
        $userId = intval($subdomain->userid ?? 0);
        if ($userId <= 0) {
            return ['success' => false, 'message' => 'userid_missing'];
        }

        $token = self::resolveBotToken($settings);
        if (!self::validateBotToken($token)) {
            return ['success' => false, 'message' => 'invalid_bot_token'];
        }

        $targetTelegramUserId = intval($overrideTelegramUserId ?? 0);
        $pref = null;
        if ($targetTelegramUserId <= 0) {
            self::ensureTables();
            $pref = Capsule::table(self::TABLE_PREFERENCES)->where('userid', $userId)->first();
            if (!$ignorePreference) {
                if (!$pref || intval($pref->enabled ?? 0) !== 1) {
                    return ['success' => false, 'message' => 'user_opt_out'];
                }
            }
            if ($pref && intval($pref->telegram_user_id ?? 0) > 0) {
                $targetTelegramUserId = intval($pref->telegram_user_id ?? 0);
            }
        }

        if ($targetTelegramUserId <= 0 && class_exists('CfTelegramGroupRewardService')) {
            $binding = CfTelegramGroupRewardService::getBindingForUser($userId);
            $targetTelegramUserId = intval($binding['telegram_user_id'] ?? 0);
            if ($targetTelegramUserId > 0 && $pref) {
                self::upsertPreferenceSnapshot($userId, [
                    'enabled' => intval($pref->enabled ?? 0) === 1 ? 1 : 0,
                    'bound' => 1,
                    'telegram_user_id' => $targetTelegramUserId,
                    'telegram_username' => self::normalizeTelegramUsername((string) ($binding['telegram_username'] ?? '')),
                    'auth_date' => max(0, intval($binding['auth_date'] ?? 0)),
                ]);
            }
        }

        if ($targetTelegramUserId <= 0) {
            return ['success' => false, 'message' => 'binding_missing'];
        }

        $message = self::buildReminderMessage($subdomain, $days);

        try {
            $result = self::telegramApiRequest($token, 'sendMessage', [
                'chat_id' => (string) $targetTelegramUserId,
                'text' => $message,
                'disable_web_page_preview' => 'true',
            ]);
        } catch (CfTelegramExpiryNoticeException $e) {
            return ['success' => false, 'message' => $e->getReason()];
        }

        if (empty($result['ok'])) {
            $code = intval($result['error_code'] ?? 0);
            if ($code === 429) {
                return ['success' => false, 'message' => 'verify_rate_limited'];
            }
            return ['success' => false, 'message' => 'send_failed'];
        }

        return [
            'success' => true,
            'message' => 'sent',
            'telegram_user_id' => $targetTelegramUserId,
        ];
    }

    public static function markReminderSent(int $subdomainId, string $reminderKey, ?string $expiresAtSnapshot, int $userId = 0, int $telegramUserId = 0): void
    {
        self::ensureTables();
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::TABLE_LOGS)->updateOrInsert(
            [
                'subdomain_id' => $subdomainId,
                'reminder_key' => $reminderKey,
                'expires_at_snapshot' => $expiresAtSnapshot,
            ],
            [
                'userid' => max(0, $userId),
                'telegram_user_id' => max(0, $telegramUserId),
                'sent_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    private static function upsertPreferenceSnapshot(int $userId, array $snapshot): void
    {
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::TABLE_PREFERENCES)->updateOrInsert(
            ['userid' => $userId],
            [
                'enabled' => intval($snapshot['enabled'] ?? 0) === 1 ? 1 : 0,
                'bound' => intval($snapshot['bound'] ?? 0) === 1 ? 1 : 0,
                'telegram_user_id' => max(0, intval($snapshot['telegram_user_id'] ?? 0)),
                'telegram_username' => self::normalizeTelegramUsername((string) ($snapshot['telegram_username'] ?? '')),
                'auth_date' => max(0, intval($snapshot['auth_date'] ?? 0)),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
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

    private static function validateBotToken(string $botToken): bool
    {
        $botToken = trim($botToken);
        if ($botToken === '') {
            return false;
        }
        return (bool) preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,120}$/', $botToken);
    }

    private static function telegramApiRequest(string $botToken, string $method, array $params): array
    {
        if (!function_exists('curl_init')) {
            throw new CfTelegramExpiryNoticeException('curl_missing');
        }

        $url = rtrim(self::TELEGRAM_API_BASE, '/') . '/bot' . rawurlencode($botToken) . '/' . $method;
        $curl = curl_init();
        if ($curl === false) {
            throw new CfTelegramExpiryNoticeException('curl_init_failed');
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
            throw new CfTelegramExpiryNoticeException('curl_exec_failed', $error !== '' ? $error : 'curl_exec_failed');
        }

        $payload = json_decode((string) $body, true);
        if (!is_array($payload)) {
            throw new CfTelegramExpiryNoticeException('invalid_json');
        }

        $payload['http_status'] = $status;
        return $payload;
    }

    private static function buildReminderMessage($subdomain, int $days): string
    {
        $expiry = $subdomain->expires_at ?? null;
        $expiryTs = $expiry ? strtotime((string) $expiry) : false;
        $domainLabel = trim((string) ($subdomain->subdomain ?? ''));
        $rootdomain = trim((string) ($subdomain->rootdomain ?? ''));

        $fqdn = $domainLabel;
        if ($fqdn !== '' && $rootdomain !== '' && stripos($fqdn, $rootdomain) === false) {
            $fqdn = rtrim($domainLabel, '.') . '.' . ltrim($rootdomain, '.');
        }
        if ($fqdn === '') {
            $fqdn = $domainLabel !== '' ? $domainLabel : ('ID#' . intval($subdomain->id ?? 0));
        }

        $lines = [
            '【域名到期提醒】',
            '域名：' . $fqdn,
            '剩余天数：' . max(0, intval($days)) . ' 天',
            '到期时间：' . ($expiryTs ? date('Y-m-d H:i:s', $expiryTs) : '-'),
            '请及时续期，避免域名被系统回收。',
        ];

        return implode("\n", $lines);
    }
}
