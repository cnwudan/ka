<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfDomainPermanentUpgradeService
{
    private const TABLE_REQUESTS = 'mod_cloudflare_domain_permanent_upgrade_requests';
    private const TABLE_ASSISTS = 'mod_cloudflare_domain_permanent_upgrade_assists';
    private const CODE_LENGTH = 12;

    public static function isEnabled(array $settings): bool
    {
        $raw = $settings['enable_domain_permanent_upgrade'] ?? '1';
        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($raw);
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function getRequiredAssistCount(array $settings): int
    {
        $raw = intval($settings['domain_permanent_upgrade_assist_required'] ?? 3);
        return max(1, min(100, $raw));
    }

    public static function ensureTables(): void
    {
        try {
            $schema = Capsule::schema();

            if (!$schema->hasTable(self::TABLE_REQUESTS)) {
                $schema->create(self::TABLE_REQUESTS, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned();
                    $table->integer('subdomain_id')->unsigned()->unique();
                    $table->string('assist_code', 20)->unique();
                    $table->integer('target_assists')->unsigned()->default(3);
                    $table->integer('assist_count')->unsigned()->default(0);
                    $table->string('status', 20)->default('pending');
                    $table->dateTime('upgraded_at')->nullable();
                    $table->timestamps();
                    $table->index('userid');
                    $table->index('status');
                    $table->index('created_at');
                });
            }

            if (!$schema->hasTable(self::TABLE_ASSISTS)) {
                $schema->create(self::TABLE_ASSISTS, function ($table) {
                    $table->increments('id');
                    $table->integer('request_id')->unsigned();
                    $table->integer('helper_userid')->unsigned();
                    $table->string('helper_email', 191)->nullable();
                    $table->string('helper_ip', 64)->nullable();
                    $table->string('assist_code', 20);
                    $table->timestamps();
                    $table->unique(['request_id', 'helper_userid'], 'uniq_cf_perm_upgrade_helper_once');
                    $table->index('request_id');
                    $table->index('helper_userid');
                    $table->index('assist_code');
                    $table->index('created_at');
                });
            }
        } catch (\Throwable $e) {
        }
    }

    public static function getUserState(int $userId, array $settings, int $page = 1, int $perPage = 10): array
    {
        self::ensureTables();

        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $requiredAssists = self::getRequiredAssistCount($settings);

        $state = [
            'assist_required' => $requiredAssists,
            'eligible_domains' => [],
            'requests' => [],
            'pending_count' => 0,
            'upgraded_count' => 0,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => 0,
                'totalPages' => 1,
            ],
        ];

        if ($userId <= 0) {
            return $state;
        }

        try {
            $eligibleRows = Capsule::table('mod_cloudflare_subdomain')
                ->select('id', 'subdomain', 'status')
                ->where('userid', $userId)
                ->where('never_expires', 0)
                ->whereIn('status', ['active', 'pending'])
                ->orderBy('created_at', 'desc')
                ->get();

            $eligibleDomains = [];
            foreach ($eligibleRows as $eligibleRow) {
                $eligibleDomains[] = [
                    'id' => (int) ($eligibleRow->id ?? 0),
                    'domain' => (string) ($eligibleRow->subdomain ?? ''),
                    'status' => (string) ($eligibleRow->status ?? ''),
                ];
            }
            $state['eligible_domains'] = $eligibleDomains;

            $baseQuery = Capsule::table(self::TABLE_REQUESTS . ' as r')
                ->leftJoin('mod_cloudflare_subdomain as s', 'r.subdomain_id', '=', 's.id')
                ->where('r.userid', $userId);

            $total = (clone $baseQuery)->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $rows = $baseQuery
                ->select(
                    'r.id',
                    'r.userid',
                    'r.subdomain_id',
                    'r.assist_code',
                    'r.target_assists',
                    'r.assist_count',
                    'r.status',
                    'r.upgraded_at',
                    'r.created_at',
                    's.subdomain as domain_name',
                    's.never_expires as domain_never_expires'
                )
                ->orderByRaw("CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END")
                ->orderBy('r.id', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            $requestIds = [];
            $requestItems = [];
            $pendingCount = 0;
            $upgradedCount = 0;

            foreach ($rows as $row) {
                $requestId = (int) ($row->id ?? 0);
                if ($requestId <= 0) {
                    continue;
                }
                $requestIds[] = $requestId;

                $status = strtolower((string) ($row->status ?? 'pending'));
                $domainNeverExpires = intval($row->domain_never_expires ?? 0) === 1;
                if ($status !== 'upgraded' && $domainNeverExpires) {
                    $status = 'upgraded';
                    Capsule::table(self::TABLE_REQUESTS)
                        ->where('id', $requestId)
                        ->update([
                            'status' => 'upgraded',
                            'upgraded_at' => $row->upgraded_at ?: date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                }

                if ($status === 'pending') {
                    $pendingCount++;
                }
                if ($status === 'upgraded') {
                    $upgradedCount++;
                }

                $targetAssists = max(1, (int) ($row->target_assists ?? $requiredAssists));
                $assistCount = max(0, (int) ($row->assist_count ?? 0));
                if ($assistCount > $targetAssists) {
                    $assistCount = $targetAssists;
                }

                $requestItems[$requestId] = [
                    'id' => $requestId,
                    'subdomain_id' => (int) ($row->subdomain_id ?? 0),
                    'domain' => (string) ($row->domain_name ?? ''),
                    'assist_code' => strtoupper((string) ($row->assist_code ?? '')),
                    'target_assists' => $targetAssists,
                    'assist_count' => $assistCount,
                    'status' => $status,
                    'created_at' => (string) ($row->created_at ?? ''),
                    'upgraded_at' => (string) ($row->upgraded_at ?? ''),
                    'helpers_preview' => [],
                    'can_copy' => $status === 'pending',
                ];
            }

            if (!empty($requestIds)) {
                $assistRows = Capsule::table(self::TABLE_ASSISTS)
                    ->select('request_id', 'helper_email')
                    ->whereIn('request_id', $requestIds)
                    ->orderBy('id', 'desc')
                    ->get();

                foreach ($assistRows as $assistRow) {
                    $requestId = (int) ($assistRow->request_id ?? 0);
                    if ($requestId <= 0 || !isset($requestItems[$requestId])) {
                        continue;
                    }
                    if (count($requestItems[$requestId]['helpers_preview']) >= 3) {
                        continue;
                    }
                    $requestItems[$requestId]['helpers_preview'][] = self::maskEmail((string) ($assistRow->helper_email ?? ''));
                }
            }

            $state['requests'] = array_values($requestItems);
            $state['pending_count'] = $pendingCount;
            $state['upgraded_count'] = $upgradedCount;
            $state['pagination'] = [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ];
        } catch (\Throwable $e) {
        }

        return $state;
    }

    public static function createOrGetRequest(int $userId, int $subdomainId, array $settings): array
    {
        self::ensureTables();

        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if ($subdomainId <= 0) {
            throw new \InvalidArgumentException('invalid_subdomain');
        }
        if (!self::isEnabled($settings)) {
            throw new \InvalidArgumentException('feature_disabled');
        }

        $targetAssists = self::getRequiredAssistCount($settings);

        return Capsule::connection()->transaction(function () use ($userId, $subdomainId, $targetAssists) {
            $subdomain = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomainId)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            if (!$subdomain) {
                throw new \InvalidArgumentException('invalid_subdomain');
            }

            if (intval($subdomain->never_expires ?? 0) === 1) {
                throw new \InvalidArgumentException('already_permanent');
            }

            $status = strtolower((string) ($subdomain->status ?? ''));
            if (!in_array($status, ['active', 'pending'], true)) {
                throw new \InvalidArgumentException('invalid_status');
            }

            $existingRequest = Capsule::table(self::TABLE_REQUESTS)
                ->where('subdomain_id', $subdomainId)
                ->lockForUpdate()
                ->first();

            $now = date('Y-m-d H:i:s');

            if ($existingRequest) {
                $requestStatus = strtolower((string) ($existingRequest->status ?? 'pending'));
                if ($requestStatus === 'upgraded') {
                    throw new \InvalidArgumentException('already_permanent');
                }

                if ($requestStatus !== 'pending') {
                    $newCode = self::generateUniqueAssistCode();
                    Capsule::table(self::TABLE_REQUESTS)
                        ->where('id', (int) ($existingRequest->id ?? 0))
                        ->update([
                            'assist_code' => $newCode,
                            'target_assists' => $targetAssists,
                            'assist_count' => 0,
                            'status' => 'pending',
                            'upgraded_at' => null,
                            'updated_at' => $now,
                        ]);
                    Capsule::table(self::TABLE_ASSISTS)
                        ->where('request_id', (int) ($existingRequest->id ?? 0))
                        ->delete();
                } else {
                    Capsule::table(self::TABLE_REQUESTS)
                        ->where('id', (int) ($existingRequest->id ?? 0))
                        ->update([
                            'target_assists' => $targetAssists,
                            'updated_at' => $now,
                        ]);
                }

                $refreshed = Capsule::table(self::TABLE_REQUESTS)
                    ->where('id', (int) ($existingRequest->id ?? 0))
                    ->first();

                return [
                    'created' => false,
                    'request_id' => (int) ($refreshed->id ?? 0),
                    'subdomain_id' => $subdomainId,
                    'domain' => (string) ($subdomain->subdomain ?? ''),
                    'assist_code' => strtoupper((string) ($refreshed->assist_code ?? '')),
                    'assist_count' => max(0, (int) ($refreshed->assist_count ?? 0)),
                    'target_assists' => max(1, (int) ($refreshed->target_assists ?? $targetAssists)),
                ];
            }

            $assistCode = self::generateUniqueAssistCode();
            $requestId = Capsule::table(self::TABLE_REQUESTS)->insertGetId([
                'userid' => $userId,
                'subdomain_id' => $subdomainId,
                'assist_code' => $assistCode,
                'target_assists' => $targetAssists,
                'assist_count' => 0,
                'status' => 'pending',
                'upgraded_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'created' => true,
                'request_id' => (int) $requestId,
                'subdomain_id' => $subdomainId,
                'domain' => (string) ($subdomain->subdomain ?? ''),
                'assist_code' => strtoupper($assistCode),
                'assist_count' => 0,
                'target_assists' => $targetAssists,
            ];
        });
    }

    public static function assistByCode(int $helperUserId, string $assistCode, string $helperEmail, string $helperIp, array $settings): array
    {
        self::ensureTables();

        if ($helperUserId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if (!self::isEnabled($settings)) {
            throw new \InvalidArgumentException('feature_disabled');
        }

        $cleanCode = strtoupper(trim($assistCode));
        if ($cleanCode === '') {
            throw new \InvalidArgumentException('invalid_code');
        }

        $normalizedEmail = strtolower(trim($helperEmail));
        $now = date('Y-m-d H:i:s');

        return Capsule::connection()->transaction(function () use ($helperUserId, $cleanCode, $normalizedEmail, $helperIp, $now) {
            $request = Capsule::table(self::TABLE_REQUESTS)
                ->where('assist_code', $cleanCode)
                ->lockForUpdate()
                ->first();

            if (!$request) {
                throw new \InvalidArgumentException('invalid_code');
            }

            $requestStatus = strtolower((string) ($request->status ?? 'pending'));
            if ($requestStatus !== 'pending') {
                throw new \InvalidArgumentException($requestStatus === 'upgraded' ? 'already_upgraded' : 'request_closed');
            }

            $ownerUserId = (int) ($request->userid ?? 0);
            if ($ownerUserId <= 0) {
                throw new \InvalidArgumentException('request_invalid');
            }
            if ($ownerUserId === $helperUserId) {
                throw new \InvalidArgumentException('self_assist');
            }

            $subdomain = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', (int) ($request->subdomain_id ?? 0))
                ->lockForUpdate()
                ->first();

            if (!$subdomain || (int) ($subdomain->userid ?? 0) !== $ownerUserId) {
                throw new \InvalidArgumentException('request_invalid');
            }

            if (intval($subdomain->never_expires ?? 0) === 1) {
                Capsule::table(self::TABLE_REQUESTS)
                    ->where('id', (int) ($request->id ?? 0))
                    ->update([
                        'status' => 'upgraded',
                        'assist_count' => max((int) ($request->assist_count ?? 0), (int) ($request->target_assists ?? 1)),
                        'upgraded_at' => $request->upgraded_at ?: $now,
                        'updated_at' => $now,
                    ]);
                throw new \InvalidArgumentException('already_upgraded');
            }

            $existingAssist = Capsule::table(self::TABLE_ASSISTS)
                ->where('request_id', (int) ($request->id ?? 0))
                ->where('helper_userid', $helperUserId)
                ->first();

            if ($existingAssist) {
                throw new \InvalidArgumentException('already_assisted');
            }

            Capsule::table(self::TABLE_ASSISTS)->insert([
                'request_id' => (int) ($request->id ?? 0),
                'helper_userid' => $helperUserId,
                'helper_email' => $normalizedEmail,
                'helper_ip' => $helperIp,
                'assist_code' => $cleanCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $targetAssists = max(1, (int) ($request->target_assists ?? 1));
            $assistCount = max(0, (int) ($request->assist_count ?? 0)) + 1;
            $upgraded = $assistCount >= $targetAssists;

            if ($upgraded) {
                $assistCount = $targetAssists;
                Capsule::table(self::TABLE_REQUESTS)
                    ->where('id', (int) ($request->id ?? 0))
                    ->update([
                        'assist_count' => $assistCount,
                        'status' => 'upgraded',
                        'upgraded_at' => $now,
                        'updated_at' => $now,
                    ]);

                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', (int) ($subdomain->id ?? 0))
                    ->update([
                        'never_expires' => 1,
                        'expires_at' => null,
                        'auto_deleted_at' => null,
                        'renewed_at' => $now,
                        'updated_at' => $now,
                    ]);
            } else {
                Capsule::table(self::TABLE_REQUESTS)
                    ->where('id', (int) ($request->id ?? 0))
                    ->update([
                        'assist_count' => $assistCount,
                        'updated_at' => $now,
                    ]);
            }

            return [
                'request_id' => (int) ($request->id ?? 0),
                'owner_userid' => $ownerUserId,
                'domain' => (string) ($subdomain->subdomain ?? ''),
                'assist_count' => $assistCount,
                'target_assists' => $targetAssists,
                'upgraded' => $upgraded,
            ];
        });
    }

    private static function generateUniqueAssistCode(): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxIndex = strlen($characters) - 1;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= $characters[random_int(0, $maxIndex)];
            }
            $exists = Capsule::table(self::TABLE_REQUESTS)
                ->where('assist_code', $code)
                ->exists();
            if (!$exists) {
                return $code;
            }
        }

        throw new \RuntimeException('assist_code_generate_failed');
    }

    private static function maskEmail(string $email): string
    {
        $email = trim($email);
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
        $maskedDomainParts = array_map(static function ($part) {
            $len = strlen($part);
            if ($len <= 2) {
                return substr($part, 0, 1) . '*';
            }

            return substr($part, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($part, -1);
        }, $domainParts);

        return $maskedUser . '@' . implode('.', $maskedDomainParts);
    }
}
