<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfSettingsRepository
{
    private static ?self $instance = null;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 获取全部配置（含默认值与迁移）
     */
    public function getAll(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->loadSettings();
        }

        return $this->cache;
    }

    /**
     * 获取指定配置
     */
    public function get(string $key, $default = null)
    {
        $settings = $this->getAll();

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * 刷新缓存
     */
    public function refresh(): array
    {
        $this->cache = $this->loadSettings();

        return $this->cache;
    }

    private function loadSettings(): array
    {
        $settings = [];

        try {
            if (function_exists('cf_ensure_module_settings_migrated')) {
                cf_ensure_module_settings_migrated();
            }

            $module = $this->currentModuleSlug();
            $legacy = $this->legacyModuleSlug();

            $configs = Capsule::table('tbladdonmodules')
                ->where('module', $module)
                ->get();

            if (count($configs) === 0 && $legacy !== $module) {
                $configs = Capsule::table('tbladdonmodules')
                    ->where('module', $legacy)
                    ->get();
            }

            foreach ($configs as $config) {
                $settings[$config->setting] = $config->value;
            }
        } catch (\Throwable $e) {
            $settings = [];
        }

        $settings = $this->normalizeInviteRegistrationGithubSecret($settings);
        $settings = $this->normalizeTelegramGroupBotToken($settings);
        $settings = $this->normalizeRenewalNoticeTelegramBotToken($settings);
        $settings = $this->normalizeInviteRegistrationTelegramBotToken($settings);
        $settings = $this->normalizeHelpAiGeminiApiKey($settings);
        $settings = $this->normalizeHelpAiOpenrouterApiKey($settings);
        $settings = $this->normalizeRenewalNoticeTelegramTemplates($settings);
        $settings = $this->applyDefaults($settings);
        $settings = $this->synchronizeProviders($settings);
        $settings = $this->migrateLegacyFields($settings);

        return $settings;
    }

    private function normalizeInviteRegistrationGithubSecret(array $settings): array
    {
        return $this->normalizeSensitiveSetting($settings, 'invite_registration_github_client_secret');
    }

    private function normalizeTelegramGroupBotToken(array $settings): array
    {
        return $this->normalizeSensitiveSetting($settings, 'telegram_group_bot_token');
    }

    private function normalizeInviteRegistrationTelegramBotToken(array $settings): array
    {
        return $this->normalizeSensitiveSetting($settings, 'invite_registration_telegram_bot_token');
    }

    private function normalizeRenewalNoticeTelegramBotToken(array $settings): array
    {
        return $this->normalizeSensitiveSetting($settings, 'renewal_notice_telegram_bot_token');
    }

    private function normalizeHelpAiGeminiApiKey(array $settings): array
    {
        return $this->normalizeSensitiveSetting($settings, 'help_ai_gemini_api_key');
    }

    private function normalizeHelpAiOpenrouterApiKey(array $settings): array
    {
        return $this->normalizeSensitiveSetting($settings, 'help_ai_openrouter_api_key');
    }

    private function normalizeRenewalNoticeTelegramTemplates(array $settings): array
    {
        $legacyExists = array_key_exists('renewal_notice_telegram_template', $settings);
        $zhExists = array_key_exists('renewal_notice_telegram_template_zh', $settings);

        if ($legacyExists && !$zhExists) {
            $settings['renewal_notice_telegram_template_zh'] = (string) ($settings['renewal_notice_telegram_template'] ?? '');
        }

        if (!$legacyExists && $zhExists) {
            $settings['renewal_notice_telegram_template'] = (string) ($settings['renewal_notice_telegram_template_zh'] ?? '');
        }

        return $settings;
    }

    private function normalizeSensitiveSetting(array $settings, string $key): array
    {
        if (!array_key_exists($key, $settings)) {
            return $settings;
        }

        $rawValue = trim((string) ($settings[$key] ?? ''));
        if ($rawValue === '') {
            $settings[$key] = '';
            return $settings;
        }

        $plainValue = $this->decodeSensitiveRawValue($rawValue);
        if ($plainValue !== '' && !$this->isMaskedSensitivePlaceholder($plainValue)) {
            if (strpos($rawValue, 'enc::') !== 0) {
                $this->persistSensitiveRawValue($key, $rawValue);
            }
            $settings[$key] = $plainValue;
            return $settings;
        }

        $fallback = $this->resolveSensitiveFallback($key, [$rawValue]);
        if (($fallback['plain'] ?? '') !== '') {
            $settings[$key] = (string) $fallback['plain'];
            $this->persistSensitiveRawValue($key, (string) ($fallback['raw'] ?? ''));
            return $settings;
        }

        $settings[$key] = '';
        return $settings;
    }

    private function resolveSensitiveFallback(string $key, array $excludeRawValues = []): array
    {
        $excludeRawValues = array_values(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $excludeRawValues), static function (string $value): bool {
            return $value !== '';
        }));

        $modules = array_values(array_unique([$this->currentModuleSlug(), $this->legacyModuleSlug()]));
        foreach ($modules as $module) {
            try {
                $rows = Capsule::table('tbladdonmodules')
                    ->where('module', $module)
                    ->where('setting', $key)
                    ->get();
            } catch (\Throwable $e) {
                $rows = [];
            }

            foreach ($rows as $row) {
                $candidateRaw = trim((string) ($row->value ?? ''));
                if ($candidateRaw === '' || in_array($candidateRaw, $excludeRawValues, true)) {
                    continue;
                }

                $candidatePlain = $this->decodeSensitiveRawValue($candidateRaw);
                if ($candidatePlain === '' || $this->isMaskedSensitivePlaceholder($candidatePlain)) {
                    continue;
                }

                return [
                    'raw' => $candidateRaw,
                    'plain' => $candidatePlain,
                    'module' => $module,
                ];
            }
        }

        return [
            'raw' => '',
            'plain' => '',
            'module' => '',
        ];
    }

    private function decodeSensitiveRawValue(string $rawValue): string
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            return '';
        }

        $prefix = 'enc::';
        if (strpos($rawValue, $prefix) === 0) {
            $encrypted = substr($rawValue, strlen($prefix));
            return trim((string) cfmod_decrypt_sensitive($encrypted));
        }

        return $rawValue;
    }

    private function persistSensitiveRawValue(string $key, string $rawValue): void
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            return;
        }

        $stored = $rawValue;
        if (strpos($stored, 'enc::') !== 0) {
            $encrypted = cfmod_encrypt_sensitive($stored);
            if ($encrypted === null || $encrypted === '') {
                return;
            }
            $stored = 'enc::' . $encrypted;
        }

        try {
            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => $this->currentModuleSlug(), 'setting' => $key],
                ['value' => $stored]
            );
        } catch (\Throwable $e) {
        }
    }

    private function isMaskedSensitivePlaceholder(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $length = function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < 4 || $length > 512) {
            return false;
        }

        return preg_match('/^[\*\x{FF0A}\x{2022}\x{25CF}]+$/u', $value) === 1;
    }

    private function applyDefaults(array $settings): array
    {
        $defaults = [
            'domain_registration_term_years' => '1',
            'domain_free_renew_window_days' => '30',
            'enable_domain_permanent_upgrade' => '0',
            'domain_permanent_upgrade_price' => '0',
            'domain_grace_period_days' => '45',
            'domain_redemption_days' => '0',
            'domain_redemption_mode' => 'manual',
            'domain_redemption_fee_amount' => '0',
            'domain_redemption_cleanup_days' => '0',
            'domain_expiry_enable_legacy_never' => 'yes',
            'domain_cleanup_batch_size' => '50',
            'domain_cleanup_deep_delete' => 'yes',
            'pdns_register_local_check_only' => '1',
            'pdns_register_strategy' => 'local_only',
            'pdns_register_hybrid_local_threshold' => '800',
            'redeem_ticket_url' => '',
            'api_logs_retention_days' => '30',
            'general_logs_retention_days' => '90',
            'sync_logs_retention_days' => '30',
            'api_log_mode' => 'full',
            'api_log_success_sample_percent' => '100',
            'api_log_payload_max_bytes' => '0',
            'api_key_last_used_update_interval_seconds' => '60',
            'api_rate_limit_cleanup_probability_per_thousand' => '10',
            'api_rate_limit_cleanup_batch_size' => '1000',
            'whois_require_api_key' => 'no',
            'whois_email_mode' => 'anonymous',
            'whois_anonymous_email' => 'whois@example.com',
            'whois_default_nameservers' => '',
            'whois_rate_limit_per_minute' => '2',
            'enable_dig_center' => 'yes',
            'dig_timeout_seconds' => '6',
            'dig_log_mode' => 'meta',
            'dig_logs_retention_days' => '30',
            'enable_client_domain_delete' => '0',
            'domain_cleanup_interval_hours' => '24',
            'partner_plan_admin_email' => '',
            'invite_registration_gate_mode' => 'disabled',
            'invite_registration_github_client_id' => '',
            'invite_registration_github_client_secret' => '',
            'invite_registration_github_min_months' => '0',
            'invite_registration_github_min_repos' => '0',
            'invite_registration_telegram_bot_username' => '',
            'invite_registration_telegram_bot_token' => '',
            'invite_registration_telegram_auth_max_age_seconds' => '86400',
            'invite_registration_inviter_min_months' => '0',
            'enable_help_ai_search' => '0',
            'help_ai_provider' => 'gemini',
            'help_ai_assistant_name' => 'AI 助手',
            'help_ai_system_prompt' => '',
            'help_ai_max_input_chars' => '600',
            'help_ai_gemini_api_key' => '',
            'help_ai_gemini_model' => 'gemini-2.0-flash',
            'help_ai_openrouter_api_key' => '',
            'help_ai_openrouter_model' => 'meta-llama/llama-3.1-8b-instruct:free',
            'privileged_allow_register_suspended_root' => '0',
            'privileged_unlimited_invite_generation' => '1',
            'privileged_force_never_expire' => '1',
            'privileged_allow_delete_with_dns_history' => '0',
            'sponsor_title' => '赞助 DNSHE',
            'sponsor_description' => 'DNSHE 的成长离不开社区的支持。你的每一份赞助都将用于支付服务器与根域名的续费开支。',
            'sponsor_methods' => '',
            'sponsor_acknowledgements' => '',
            'enable_telegram_group_reward' => '0',
            'telegram_group_link' => '',
            'telegram_group_chat_id' => '',
            'telegram_group_bot_username' => '',
            'telegram_group_bot_token' => '',
            'telegram_group_reward_amount' => '1',
            'telegram_reward_auth_max_age_seconds' => '86400',
            'renewal_notice_telegram_enabled' => '0',
            'renewal_notice_telegram_bot_username' => '',
            'renewal_notice_telegram_bot_token' => '',
            'renewal_notice_telegram_template' => "【域名到期提醒】
域名：{\$fqdn}
到期时间：{\$expiry_datetime}
剩余天数：{\$days_left} 天
请及时续期，避免域名失效。",
            'renewal_notice_telegram_template_zh' => "【域名到期提醒】
域名：{\$fqdn}
到期时间：{\$expiry_datetime}
剩余天数：{\$days_left} 天
请及时续期，避免域名失效。",
            'renewal_notice_telegram_template_en' => "[Domain Expiry Reminder]
Domain: {\$fqdn}
Expiry Time: {\$expiry_datetime}
Days Left: {\$days_left}
Please renew in time to avoid domain suspension.",
            'renewal_notice_telegram_days' => '30,10',
            'renewal_notice_telegram_auth_max_age_seconds' => '86400',
            'job_running_timeout_minutes' => '120',
            'queue_heartbeat_interval_seconds' => '20',
            'transfer_clone_full_zone' => '1',
        ];

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $settings)) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    private function migrateLegacyFields(array $settings): array
    {
        if (!array_key_exists('domain_grace_period_days', $settings)
            && array_key_exists('domain_auto_delete_grace_days', $settings)
        ) {
            $legacyGrace = $settings['domain_auto_delete_grace_days'];
            $settings['domain_grace_period_days'] = $legacyGrace;

            try {
                Capsule::table('tbladdonmodules')->updateOrInsert(
                    ['module' => $this->currentModuleSlug(), 'setting' => 'domain_grace_period_days'],
                    ['value' => $legacyGrace]
                );
            } catch (\Throwable $e) {
                // best-effort
            }
        }

        if (!array_key_exists('domain_auto_delete_grace_days', $settings)
            && array_key_exists('domain_grace_period_days', $settings)
        ) {
            $settings['domain_auto_delete_grace_days'] = $settings['domain_grace_period_days'];
        }

        if (function_exists('cfmod_migrate_legacy_rootdomains')) {
            try {
                cfmod_migrate_legacy_rootdomains($settings);
            } catch (\Throwable $ignored) {
            }
        }

        return $settings;
    }

    private function synchronizeProviders(array $settings): array
    {
        try {
            if (function_exists('cfmod_sync_default_provider_account')) {
                $defaultProviderId = cfmod_sync_default_provider_account($settings);
                if ($defaultProviderId !== null && !array_key_exists('default_provider_account_id', $settings)) {
                    $settings['default_provider_account_id'] = (string) $defaultProviderId;
                }
            }
        } catch (\Throwable $ignored) {
        }

        try {
            if (function_exists('cfmod_get_provider_account') && function_exists('cfmod_get_default_provider_account')) {
                $providerForSettings = null;

                if (!empty($settings['default_provider_account_id'])) {
                    $providerForSettings = cfmod_get_provider_account((int) $settings['default_provider_account_id'], true);
                } else {
                    $providerForSettings = cfmod_get_default_provider_account(true);
                    if ($providerForSettings && !empty($providerForSettings['id'])) {
                        $settings['default_provider_account_id'] = (string) $providerForSettings['id'];
                        try {
                            Capsule::table('tbladdonmodules')->updateOrInsert(
                                ['module' => $this->currentModuleSlug(), 'setting' => 'default_provider_account_id'],
                                ['value' => $providerForSettings['id']]
                            );
                        } catch (\Throwable $ignored) {
                        }
                    }
                }

                if ($providerForSettings) {
                    $settings['cloudflare_email'] = $providerForSettings['access_key_id'] ?? '';
                    $settings['cloudflare_api_key'] = $providerForSettings['access_key_secret'] ?? '';
                }
            }
        } catch (\Throwable $ignored) {
        }

        return $settings;
    }

    private function currentModuleSlug(): string
    {
        return defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
    }

    private function legacyModuleSlug(): string
    {
        return defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : 'cloudflare_subdomain';
    }
}
