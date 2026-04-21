<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

/**
 * 邀请注册服务
 * 类似DNS解锁功能，用户需要输入邀请码才能解锁注册功能
 */
class CfInviteRegistrationService
{
    private const CODE_LENGTH = 12;
    private const TABLE_UNLOCK = 'mod_cloudflare_invite_registration_unlock';
    private const TABLE_LOGS = 'mod_cloudflare_invite_registration_logs';

    public static function resolveGateMode(array $moduleSettings): string
    {
        $mode = strtolower(trim((string) ($moduleSettings['invite_registration_gate_mode'] ?? '')));
        if (in_array($mode, ['invite_only', 'github_only', 'invite_or_github'], true)) {
            return $mode;
        }

        $legacyEnabled = function_exists('cfmod_setting_enabled')
            ? cfmod_setting_enabled($moduleSettings['enable_invite_registration_gate'] ?? '0')
            : in_array(strtolower(trim((string) ($moduleSettings['enable_invite_registration_gate'] ?? '0'))), ['1', 'on', 'yes', 'true', 'enabled'], true);

        return $legacyEnabled ? 'invite_only' : 'disabled';
    }

    public static function isGateEnabled(array $moduleSettings): bool
    {
        return self::resolveGateMode($moduleSettings) !== 'disabled';
    }

    public static function isInviteOptionEnabled(array $moduleSettings): bool
    {
        $mode = self::resolveGateMode($moduleSettings);
        if (in_array($mode, ['invite_only', 'invite_or_github'], true)) {
            return true;
        }

        if ($mode === 'github_only' && !self::isGithubOauthReady($moduleSettings)) {
            return true;
        }

        return false;
    }

    public static function isGithubOptionEnabled(array $moduleSettings): bool
    {
        $mode = self::resolveGateMode($moduleSettings);
        if (!in_array($mode, ['github_only', 'invite_or_github'], true)) {
            return false;
        }

        return self::isGithubOauthReady($moduleSettings);
    }

    private static function isGithubOauthReady(array $moduleSettings): bool
    {
        if (!class_exists('CfInviteRegistrationGithubService')) {
            return false;
        }

        try {
            return CfInviteRegistrationGithubService::isOauthConfigured($moduleSettings);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 检查表是否存在，如果不存在则创建
     */
    public static function ensureTables(): void
    {
        try {
            if (!Capsule::schema()->hasTable(self::TABLE_UNLOCK)) {
                Capsule::schema()->create(self::TABLE_UNLOCK, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->unique();
                    $table->string('invite_code', 20)->unique();
                    $table->integer('code_generate_count')->unsigned()->default(1);
                    $table->dateTime('unlocked_at')->nullable();
                    $table->timestamps();
                    $table->index('invite_code');
                });
            }

            if (!Capsule::schema()->hasTable(self::TABLE_LOGS)) {
                Capsule::schema()->create(self::TABLE_LOGS, function ($table) {
                    $table->increments('id');
                    $table->integer('invite_code_id')->unsigned();
                    $table->integer('inviter_userid')->unsigned();
                    $table->integer('invitee_userid')->unsigned()->nullable();
                    $table->string('invitee_email', 191)->nullable();
                    $table->string('invitee_ip', 64)->nullable();
                    $table->string('invite_code', 20);
                    $table->timestamps();
                    $table->index('invite_code_id');
                    $table->index('inviter_userid');
                    $table->index('invitee_userid');
                    $table->index('invitee_email');
                    $table->index('invite_code');
                    $table->index('created_at');
                });
            }
        } catch (\Throwable $e) {
            // ignore schema creation errors
        }
    }

    /**
     * 确保用户有邀请注册配置文件
     */
    public static function ensureProfile(int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user');
        }

        self::ensureTables();

        $row = Capsule::table(self::TABLE_UNLOCK)->where('userid', $userId)->first();
        if (!$row) {
            $row = self::createProfile($userId);
        }
        return self::normalizeRow($row);
    }

    /**
     * 检查用户是否已解锁注册功能
     */
    public static function userHasUnlocked(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        self::ensureTables();

        try {
            $row = Capsule::table(self::TABLE_UNLOCK)
                ->select('id', 'unlocked_at')
                ->where('userid', $userId)
                ->first();
            if (!$row || empty($row->unlocked_at)) {
                return false;
            }

            if (self::isExistingUser($userId) || self::hasUnlockAuditTrail($userId)) {
                return true;
            }

            Capsule::table(self::TABLE_UNLOCK)
                ->where('id', (int) ($row->id ?? 0))
                ->update([
                    'unlocked_at' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 使用邀请码解锁用户的注册功能
     */
    public static function unlockForUser(int $userId, string $inputCode, string $usedEmail, string $ipAddress = ''): void
    {
        self::ensureTables();

        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }

        $cleanCode = strtoupper(trim($inputCode));
        if ($cleanCode === '') {
            throw new \InvalidArgumentException('invalid_code');
        }

        self::ensureProfile($userId);

        $now = date('Y-m-d H:i:s');
        $normalizedEmail = strtolower(trim((string) $usedEmail));

        Capsule::connection()->transaction(function () use ($userId, $cleanCode, $normalizedEmail, $ipAddress, $now) {
            $inviterRow = Capsule::table(self::TABLE_UNLOCK)
                ->where('invite_code', $cleanCode)
                ->lockForUpdate()
                ->first();

            if (!$inviterRow) {
                throw new \InvalidArgumentException('invalid_code');
            }

            $inviterId = (int) ($inviterRow->userid ?? 0);
            if ($inviterId === $userId) {
                throw new \InvalidArgumentException('self_code');
            }

            if (!self::inviterCanShare($inviterId)) {
                throw new \InvalidArgumentException('inviter_banned');
            }

            if (!self::inviterMeetsMinimumMonths($inviterId)) {
                throw new \InvalidArgumentException('inviter_age_insufficient');
            }

            if (!self::checkInviterLimit($inviterId)) {
                throw new \InvalidArgumentException('inviter_limit_reached');
            }

            $inviteeRow = Capsule::table(self::TABLE_UNLOCK)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            if (!$inviteeRow) {
                throw new \InvalidArgumentException('invalid_user');
            }

            if (!empty($inviteeRow->unlocked_at)) {
                throw new \InvalidArgumentException('already_unlocked');
            }

            Capsule::table(self::TABLE_UNLOCK)
                ->where('id', (int) ($inviteeRow->id ?? 0))
                ->update([
                    'unlocked_at' => $now,
                    'updated_at' => $now,
                ]);

            Capsule::table(self::TABLE_LOGS)->insert([
                'invite_code_id' => (int) ($inviterRow->id ?? 0),
                'inviter_userid' => $inviterId,
                'invitee_userid' => $userId,
                'invitee_email' => $normalizedEmail,
                'invitee_ip' => $ipAddress,
                'invite_code' => $cleanCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            self::rotateInviteCode((int) ($inviterRow->id ?? 0));
        });
    }

    /**
     * 管理员直接解锁用户
     */
    public static function adminUnlock(int $userId, int $adminId = 0): void
    {
        self::ensureTables();

        $profile = self::ensureProfile($userId);
        if (!empty($profile['unlocked_at'])) {
            return; // 已解锁，无需操作
        }

        Capsule::table(self::TABLE_UNLOCK)
            ->where('id', $profile['id'])
            ->update([
                'unlocked_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        // 记录管理员操作日志
        Capsule::table(self::TABLE_LOGS)->insert([
            'invite_code_id' => $profile['id'],
            'inviter_userid' => 0,
            'invitee_userid' => $userId,
            'invitee_email' => 'admin_unlock',
            'invitee_ip' => 'admin:' . $adminId,
            'invite_code' => 'ADMIN_BYPASS',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function unlockByGithub(int $userId, int $githubId, string $githubLogin = '', string $githubCreatedAt = ''): void
    {
        self::ensureTables();

        if ($userId <= 0 || $githubId <= 0) {
            throw new \InvalidArgumentException('invalid_github_payload');
        }

        $profile = self::ensureProfile($userId);
        $now = date('Y-m-d H:i:s');

        if (empty($profile['unlocked_at'])) {
            Capsule::table(self::TABLE_UNLOCK)
                ->where('id', $profile['id'])
                ->update([
                    'unlocked_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $emailTag = 'github:' . $githubId;
        if ($githubLogin !== '') {
            $emailTag .= ':' . strtolower(trim($githubLogin));
        }

        $ipTag = 'github_oauth';
        if ($githubCreatedAt !== '') {
            $ipTag .= ':' . $githubCreatedAt;
        }

        Capsule::table(self::TABLE_LOGS)->insert([
            'invite_code_id' => $profile['id'],
            'inviter_userid' => 0,
            'invitee_userid' => $userId,
            'invitee_email' => $emailTag,
            'invitee_ip' => $ipTag,
            'invite_code' => 'GITHUB_OAUTH',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 批量为老用户自动解锁（向后兼容迁移）
     * 返回已自动解锁的用户数量
     */
    public static function migrateExistingUsers(): int
    {
        self::ensureTables();
        $unlocked = 0;
        $now = date('Y-m-d H:i:s');

        try {
            // 获取所有未解锁的记录
            $unlockedRecords = Capsule::table(self::TABLE_UNLOCK)
                ->whereNull('unlocked_at')
                ->get();

            foreach ($unlockedRecords as $record) {
                $userId = (int) $record->userid;
                if (self::isExistingUser($userId)) {
                    Capsule::table(self::TABLE_UNLOCK)
                        ->where('id', $record->id)
                        ->update([
                            'unlocked_at' => $now,
                            'updated_at' => $now,
                        ]);
                    $unlocked++;
                }
            }

            // 获取系统中所有有真实业务活动的用户，为他们创建解锁记录
            $existingUserIds = [];

            // 从子域名表获取用户
            if (Capsule::schema()->hasTable('mod_cloudflare_subdomain')) {
                $subdomainUsers = Capsule::table('mod_cloudflare_subdomain')
                    ->distinct()
                    ->pluck('userid')
                    ->toArray();
                $existingUserIds = array_merge($existingUserIds, $subdomainUsers);
            }

            // 从邀请使用记录表获取用户（邀请人/被邀请人）
            if (Capsule::schema()->hasTable('mod_cloudflare_invitation_claims')) {
                $inviterUsers = Capsule::table('mod_cloudflare_invitation_claims')
                    ->distinct()
                    ->pluck('inviter_userid')
                    ->toArray();
                $inviteeUsers = Capsule::table('mod_cloudflare_invitation_claims')
                    ->distinct()
                    ->pluck('invitee_userid')
                    ->toArray();
                $existingUserIds = array_merge($existingUserIds, $inviterUsers, $inviteeUsers);
            }

            // 从 DNS 解锁码表获取用户
            if (Capsule::schema()->hasTable('mod_cloudflare_dns_unlock_codes')) {
                $unlockUsers = Capsule::table('mod_cloudflare_dns_unlock_codes')
                    ->distinct()
                    ->pluck('userid')
                    ->toArray();
                $existingUserIds = array_merge($existingUserIds, $unlockUsers);
            }

            // 从用户统计表获取用户
            if (Capsule::schema()->hasTable('mod_cloudflare_user_stats')) {
                $statsUsers = Capsule::table('mod_cloudflare_user_stats')
                    ->distinct()
                    ->pluck('userid')
                    ->toArray();
                $existingUserIds = array_merge($existingUserIds, $statsUsers);
            }

            // 从操作日志表获取用户
            if (Capsule::schema()->hasTable('mod_cloudflare_logs')) {
                $logUsers = Capsule::table('mod_cloudflare_logs')
                    ->whereNotNull('userid')
                    ->distinct()
                    ->pluck('userid')
                    ->toArray();
                $existingUserIds = array_merge($existingUserIds, $logUsers);
            }

            $existingUserIds = array_unique(array_filter($existingUserIds));

            // 为这些用户创建解锁记录
            foreach ($existingUserIds as $userId) {
                $userId = (int) $userId;
                if ($userId <= 0) continue;

                $exists = Capsule::table(self::TABLE_UNLOCK)
                    ->where('userid', $userId)
                    ->exists();
                if (!$exists) {
                    // 创建配置（会自动检测并解锁老用户）
                    self::createProfile($userId);
                    $unlocked++;
                }
            }
        } catch (\Throwable $e) {
            // 忽略错误
        }

        return $unlocked;
    }

    /**
     * 获取后台管理日志
     */
    public static function fetchAdminLogs(string $search, int $page, int $perPage = 20): array
    {
        self::ensureTables();

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $search = trim($search);

        try {
            $query = Capsule::table(self::TABLE_LOGS . ' as l')
                ->leftJoin(self::TABLE_UNLOCK . ' as u', 'l.invite_code_id', '=', 'u.id')
                ->leftJoin('tblclients as inviter', 'l.inviter_userid', '=', 'inviter.id')
                ->leftJoin('tblclients as invitee', 'l.invitee_userid', '=', 'invitee.id')
                ->select(
                    'l.*',
                    'u.invite_code as current_code',
                    'inviter.email as inviter_email',
                    'inviter.id as inviter_id',
                    'invitee.email as invitee_account_email'
                );

            if ($search !== '') {
                if (strpos($search, '@') !== false) {
                    $like = '%' . $search . '%';
                    $query->where(function ($q) use ($like) {
                        $q->where('l.invitee_email', 'like', $like)
                            ->orWhere('inviter.email', 'like', $like)
                            ->orWhere('invitee.email', 'like', $like);
                    });
                } else {
                    $query->whereRaw('UPPER(l.invite_code) LIKE ?', [strtoupper($search) . '%']);
                }
            }

            $total = $query->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $rows = $query
                ->orderBy('l.id', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'id' => (int) ($row->id ?? 0),
                    'invite_code' => strtoupper((string) ($row->invite_code ?? '')),
                    'inviter_userid' => (int) ($row->inviter_userid ?? 0),
                    'inviter_email' => (string) ($row->inviter_email ?? ''),
                    'invitee_userid' => (int) ($row->invitee_userid ?? 0),
                    'invitee_email' => (string) ($row->invitee_email ?? ($row->invitee_account_email ?? '')),
                    'invitee_ip' => (string) ($row->invitee_ip ?? ''),
                    'created_at' => $row->created_at ?? '',
                ];
            }

            return [
                'items' => $items,
                'search' => $search,
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => $total,
                    'totalPages' => $totalPages,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'items' => [],
                'search' => $search,
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => 0,
                    'totalPages' => 1,
                ],
            ];
        }
    }

    /**
     * 获取用户的邀请历史记录
     */
    public static function fetchUserLogs(int $userId, int $page, int $perPage = 10): array
    {
        self::ensureTables();

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        try {
            $query = Capsule::table(self::TABLE_LOGS . ' as l')
                ->leftJoin('tblclients as c', 'l.invitee_userid', '=', 'c.id')
                ->select('l.*', 'c.email as joined_email')
                ->where('l.inviter_userid', $userId);

            $total = $query->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $logs = $query
                ->orderBy('l.id', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            $items = [];
            foreach ($logs as $log) {
                $email = $log->invitee_email ?: ($log->joined_email ?? '');
                $items[] = [
                    'id' => (int) $log->id,
                    'email' => $email,
                    'email_masked' => self::maskEmail($email),
                    'invite_code' => strtoupper((string) ($log->invite_code ?? '')),
                    'created_at' => $log->created_at,
                ];
            }

            return [
                'items' => $items,
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => $total,
                    'totalPages' => $totalPages,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'items' => [],
                'pagination' => [
                    'page' => 1,
                    'perPage' => $perPage,
                    'total' => 0,
                    'totalPages' => 1,
                ],
            ];
        }
    }

    /**
     * 获取用户邀请成功的总数
     */
    public static function getUserInviteCount(int $userId): int
    {
        self::ensureTables();

        try {
            return Capsule::table(self::TABLE_LOGS)
                ->where('inviter_userid', $userId)
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 检查邀请人是否达到邀请上限
     */
    public static function checkInviterLimit(int $inviterId): bool
    {
        $maxLimit = self::getMaxInviteCodesPerUser();
        if (function_exists('cf_is_user_privileged') && cf_is_user_privileged($inviterId)) {
            $maxLimit = defined('CF_PRIVILEGED_MAX_SUBDOMAIN') ? CF_PRIVILEGED_MAX_SUBDOMAIN : 99999999999;
        }
        if ($maxLimit <= 0) {
            return true; // 0 表示不限制
        }

        $currentCount = self::getUserInviteCount($inviterId);
        return $currentCount < $maxLimit;
    }

    /**
     * 获取每个用户最多可生成多少次邀请码
     */
    public static function getMaxInviteCodesPerUser(): int
    {
        try {
            $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
            $value = Capsule::table('tbladdonmodules')
                ->where('module', $moduleSlug)
                ->where('setting', 'invite_registration_max_per_user')
                ->value('value');
            return max(0, intval($value ?? 0));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function getInviterMinMonthsRequirement(): int
    {
        return self::resolveInviterMinMonths();
    }

    public static function getInviterCodeEligibility(int $inviterId): array
    {
        $requiredMonths = self::resolveInviterMinMonths();
        if ($requiredMonths <= 0) {
            return [
                'eligible' => true,
                'reason' => 'none',
                'required_months' => 0,
                'current_months' => 0,
                'reference_time' => null,
            ];
        }

        $referenceTime = self::resolveInviterOldestSubdomainAt($inviterId);
        if ($referenceTime === null) {
            return [
                'eligible' => false,
                'reason' => 'min_months',
                'required_months' => $requiredMonths,
                'current_months' => 0,
                'reference_time' => null,
            ];
        }

        $currentMonths = self::calculateAgeMonths($referenceTime);

        return [
            'eligible' => $currentMonths >= $requiredMonths,
            'reason' => $currentMonths >= $requiredMonths ? 'none' : 'min_months',
            'required_months' => $requiredMonths,
            'current_months' => $currentMonths,
            'reference_time' => $referenceTime,
        ];
    }

    private static function inviterMeetsMinimumMonths(int $inviterId): bool
    {
        $eligibility = self::getInviterCodeEligibility($inviterId);
        return !empty($eligibility['eligible']);
    }

    private static function resolveInviterMinMonths(): int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $months = 0;
        try {
            if (function_exists('cf_get_module_settings_cached')) {
                $settings = cf_get_module_settings_cached();
                if (is_array($settings)) {
                    $months = intval($settings['invite_registration_inviter_min_months'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
        }

        if ($months <= 0) {
            try {
                $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
                $legacySlug = defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : 'cloudflare_subdomain';
                $value = Capsule::table('tbladdonmodules')
                    ->where('module', $moduleSlug)
                    ->where('setting', 'invite_registration_inviter_min_months')
                    ->value('value');

                if ($value === null && $legacySlug !== $moduleSlug) {
                    $value = Capsule::table('tbladdonmodules')
                        ->where('module', $legacySlug)
                        ->where('setting', 'invite_registration_inviter_min_months')
                        ->value('value');
                }

                $months = intval($value ?? 0);
            } catch (\Throwable $e) {
                $months = 0;
            }
        }

        if ($months < 0) {
            $months = 0;
        }
        if ($months > 240) {
            $months = 240;
        }

        $cached = $months;
        return $months;
    }

    private static function resolveInviterOldestSubdomainAt(int $inviterId): ?string
    {
        if ($inviterId <= 0) {
            return null;
        }

        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_subdomain')) {
                return null;
            }

            $oldest = Capsule::table('mod_cloudflare_subdomain')
                ->where('userid', $inviterId)
                ->whereNotNull('created_at')
                ->orderBy('created_at', 'asc')
                ->value('created_at');

            if ($oldest !== null && trim((string) $oldest) !== '') {
                return (string) $oldest;
            }

            $hasSubdomain = Capsule::table('mod_cloudflare_subdomain')
                ->where('userid', $inviterId)
                ->exists();
            if (!$hasSubdomain) {
                return null;
            }

            $fallback = Capsule::table('tblclients')
                ->where('id', $inviterId)
                ->value('datecreated');
            if ($fallback !== null && trim((string) $fallback) !== '') {
                return (string) $fallback;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private static function calculateAgeMonths(string $dateTime): int
    {
        try {
            $createdAt = new \DateTimeImmutable($dateTime);
            $now = new \DateTimeImmutable('now', $createdAt->getTimezone());
            if ($createdAt > $now) {
                return 0;
            }
            $diff = $createdAt->diff($now);
            return max(0, ((int) $diff->y * 12) + (int) $diff->m);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 邮箱脱敏
     */
    public static function maskEmail(?string $email): string
    {
        $email = trim((string) $email);
        if ($email === '' || strpos($email, '@') === false) {
            return $email !== '' ? $email : '-';
        }
        [$user, $domain] = explode('@', $email, 2);
        $userLen = strlen($user);
        if ($userLen <= 2) {
            $maskedUser = substr($user, 0, 1) . '*';
        } else {
            $maskedUser = substr($user, 0, 2) . str_repeat('*', max(1, $userLen - 3)) . substr($user, -1);
        }
        $domainParts = explode('.', $domain);
        $maskedDomainParts = array_map(function ($part) {
            $len = strlen($part);
            if ($len <= 2) {
                return substr($part, 0, 1) . '*';
            }
            return substr($part, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($part, -1);
        }, $domainParts);
        return $maskedUser . '@' . implode('.', $maskedDomainParts);
    }

    /**
     * 邀请码脱敏
     */
    public static function maskInviteCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '***';
        }
        $len = strlen($code);
        $maskLen = 5;
        if ($len <= $maskLen) {
            return str_repeat('*', min($maskLen, $len));
        }
        $maxPrefix = min(3, max(0, $len - $maskLen - 1));
        $prefixLen = $maxPrefix;
        $suffixLen = $len - $prefixLen - $maskLen;
        if ($suffixLen < 1) {
            $suffixLen = 1;
            $prefixLen = max(0, $len - $suffixLen - $maskLen);
        }
        $prefix = $prefixLen > 0 ? substr($code, 0, $prefixLen) : '';
        $suffix = $suffixLen > 0 ? substr($code, -$suffixLen) : '';
        return $prefix . str_repeat('*', $maskLen) . $suffix;
    }

    private static function hasUnlockAuditTrail(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            return Capsule::table(self::TABLE_LOGS)
                ->where('invitee_userid', $userId)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 创建用户配置
     * 向后兼容：已有真实业务活动的老用户自动解锁
     */
    private static function createProfile(int $userId)
    {
        $code = self::generateUniqueCode();
        $now = date('Y-m-d H:i:s');
        
        // 向后兼容检查：判断是否为老用户
        $isExistingUser = self::isExistingUser($userId);
        
        $data = [
            'userid' => $userId,
            'invite_code' => $code,
            'code_generate_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        
        // 老用户自动解锁
        if ($isExistingUser) {
            $data['unlocked_at'] = $now;
        }
        
        $id = Capsule::table(self::TABLE_UNLOCK)->insertGetId($data);
        return Capsule::table(self::TABLE_UNLOCK)->where('id', $id)->first();
    }

    /**
     * 检查是否为老用户（向后兼容）
     * 满足以下任一条件即视为老用户，自动解锁：
     * 1. 已注册过子域名
     * 2. 有邀请使用记录（作为邀请人或被邀请人）
     * 3. 有DNS解锁记录
     * 4. 有用户统计记录
     * 5. 有插件操作日志记录
     */
    private static function isExistingUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            // 检查是否注册过子域名
            if (Capsule::schema()->hasTable('mod_cloudflare_subdomain')) {
                $hasSubdomain = Capsule::table('mod_cloudflare_subdomain')
                    ->where('userid', $userId)
                    ->exists();
                if ($hasSubdomain) {
                    return true;
                }
            }

            // 注意：不再使用配额表/邀请码表作为“老用户”判定依据。
            // 这两类记录会在用户首次访问时自动创建，无法用于区分新老用户。

            // 检查是否有邀请使用记录（作为邀请人或被邀请人）
            if (Capsule::schema()->hasTable('mod_cloudflare_invitation_claims')) {
                $hasInviteClaim = Capsule::table('mod_cloudflare_invitation_claims')
                    ->where('inviter_userid', $userId)
                    ->orWhere('invitee_userid', $userId)
                    ->exists();
                if ($hasInviteClaim) {
                    return true;
                }
            }

            // 检查是否有DNS解锁记录
            if (Capsule::schema()->hasTable('mod_cloudflare_dns_unlock_codes')) {
                $hasDnsUnlock = Capsule::table('mod_cloudflare_dns_unlock_codes')
                    ->where('userid', $userId)
                    ->exists();
                if ($hasDnsUnlock) {
                    return true;
                }
            }

            // 检查用户统计表
            if (Capsule::schema()->hasTable('mod_cloudflare_user_stats')) {
                $hasStats = Capsule::table('mod_cloudflare_user_stats')
                    ->where('userid', $userId)
                    ->exists();
                if ($hasStats) {
                    return true;
                }
            }

            // 检查操作日志表
            if (Capsule::schema()->hasTable('mod_cloudflare_logs')) {
                $hasLogs = Capsule::table('mod_cloudflare_logs')
                    ->where('userid', $userId)
                    ->exists();
                if ($hasLogs) {
                    return true;
                }
            }

        } catch (\Throwable $e) {
            // 查询出错时默认为新用户，需要邀请码
            return false;
        }

        return false;
    }

    /**
     * 通过邀请码查找配置
     */
    private static function findProfileByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        try {
            $row = Capsule::table(self::TABLE_UNLOCK)->where('invite_code', $code)->first();
            return $row ? self::normalizeRow($row) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 轮换邀请码
     */
    private static function rotateInviteCode(int $profileId): ?string
    {
        if ($profileId <= 0) {
            return null;
        }
        try {
            $newCode = self::generateUniqueCode();
            Capsule::table(self::TABLE_UNLOCK)
                ->where('id', $profileId)
                ->update([
                    'invite_code' => $newCode,
                    'code_generate_count' => Capsule::raw('code_generate_count + 1'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            return $newCode;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 生成唯一邀请码
     */
    private static function generateUniqueCode(): string
    {
        do {
            $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $maxIndex = strlen($characters) - 1;
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= $characters[random_int(0, $maxIndex)];
            }
            $exists = Capsule::table(self::TABLE_UNLOCK)->where('invite_code', $code)->exists();
        } while ($exists);
        return $code;
    }

    /**
     * 标准化数据库行
     */
    private static function normalizeRow($row): array
    {
        if (!$row) {
            return [
                'id' => 0,
                'userid' => 0,
                'invite_code' => '',
                'code_generate_count' => 0,
                'unlocked_at' => null,
            ];
        }
        return [
            'id' => (int) ($row->id ?? 0),
            'userid' => (int) ($row->userid ?? 0),
            'invite_code' => strtoupper((string) ($row->invite_code ?? '')),
            'code_generate_count' => (int) ($row->code_generate_count ?? 0),
            'unlocked_at' => $row->unlocked_at ?? null,
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    /**
     * 检查邀请人是否可以分享邀请码
     */
    private static function inviterCanShare(int $inviterId): bool
    {
        if ($inviterId <= 0) {
            return false;
        }
        try {
            $status = Capsule::table('tblclients')->where('id', $inviterId)->value('status');
            if ($status !== null && strtolower((string) $status) !== 'active') {
                return false;
            }
        } catch (\Throwable $e) {
            // ignore status lookup errors, default to allowing
        }
        try {
            if (function_exists('cfmod_resolve_user_ban_state')) {
                $banState = cfmod_resolve_user_ban_state($inviterId);
                if (!empty($banState['is_banned'])) {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            // ignore ban lookup errors
        }
        return true;
    }
}
