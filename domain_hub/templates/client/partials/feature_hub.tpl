<?php
$featureIsChinese = strtolower((string) ($currentClientLanguage ?? 'english')) === 'chinese';
$featureText = static function (string $key, string $zh, string $en, array $params = [], bool $escape = true) use ($featureIsChinese): string {
    return cfclient_lang($key, $featureIsChinese ? $zh : $en, $params, $escape);
};

$hasRootdomainInvite = false;
if (!empty($rootInviteRequiredMap) && is_array($rootInviteRequiredMap)) {
    foreach ($rootInviteRequiredMap as $requiredFlag) {
        if (!empty($requiredFlag)) {
            $hasRootdomainInvite = true;
            break;
        }
    }
}

$githubStarRewardEnabled = !empty($githubStarRewardEnabled);
$githubStarRewardRepoUrl = trim((string) ($githubStarRewardRepoUrl ?? ''));
$githubStarRewardAmount = max(1, (int) ($githubStarRewardAmount ?? 1));
$githubStarRewardAlreadyClaimed = !empty($githubStarRewardAlreadyClaimed);
$githubStarRewardGithubUsername = trim((string) ($githubStarRewardGithubUsername ?? ''));
$githubStarRewardHistory = is_array($githubStarRewardHistory ?? null) ? $githubStarRewardHistory : ['items' => [], 'page' => 1, 'totalPages' => 1];
$githubStarHistoryItems = is_array($githubStarRewardHistory['items'] ?? null) ? $githubStarRewardHistory['items'] : [];
$githubStarHistoryPage = max(1, (int) ($githubStarRewardHistory['page'] ?? 1));
$githubStarHistoryTotalPages = max(1, (int) ($githubStarRewardHistory['totalPages'] ?? 1));

$telegramGroupRewardEnabled = !empty($telegramGroupRewardEnabled);
$telegramGroupRewardGroupLink = trim((string) ($telegramGroupRewardGroupLink ?? ''));
$telegramGroupRewardAmount = max(1, (int) ($telegramGroupRewardAmount ?? 1));
$telegramGroupRewardAlreadyClaimed = !empty($telegramGroupRewardAlreadyClaimed);
$telegramGroupRewardTelegramBound = !empty($telegramGroupRewardTelegramBound);
$telegramGroupRewardTelegramUserId = (int) ($telegramGroupRewardTelegramUserId ?? 0);
$telegramGroupRewardTelegramUsername = trim((string) ($telegramGroupRewardTelegramUsername ?? ''));
$telegramGroupRewardBotUsername = trim((string) ($telegramGroupRewardBotUsername ?? ''));
$telegramGroupRewardHistory = is_array($telegramGroupRewardHistory ?? null) ? $telegramGroupRewardHistory : ['items' => [], 'page' => 1, 'totalPages' => 1];
$telegramGroupHistoryItems = is_array($telegramGroupRewardHistory['items'] ?? null) ? $telegramGroupRewardHistory['items'] : [];
$telegramGroupHistoryPage = max(1, (int) ($telegramGroupRewardHistory['page'] ?? 1));
$telegramGroupHistoryTotalPages = max(1, (int) ($telegramGroupRewardHistory['totalPages'] ?? 1));

$expiryTelegramReminderFeatureEnabled = !empty($expiryTelegramReminderFeatureEnabled);
$expiryTelegramReminderConfigured = !empty($expiryTelegramReminderConfigured);
$expiryTelegramReminderSubscribed = !empty($expiryTelegramReminderSubscribed);
$expiryTelegramReminderTelegramBound = !empty($expiryTelegramReminderTelegramBound);
$expiryTelegramReminderTelegramUserId = (int) ($expiryTelegramReminderTelegramUserId ?? 0);
$expiryTelegramReminderTelegramUsername = trim((string) ($expiryTelegramReminderTelegramUsername ?? ''));
$expiryTelegramReminderBotUsername = trim((string) ($expiryTelegramReminderBotUsername ?? ''));
$expiryTelegramReminderDaysCsv = trim((string) ($expiryTelegramReminderDaysCsv ?? ''));
if ($expiryTelegramReminderDaysCsv === '' && is_array($expiryTelegramReminderDays ?? null)) {
    $expiryTelegramReminderDaysCsv = implode(',', array_map('intval', $expiryTelegramReminderDays));
}
$expiryTelegramReminderDaysList = [];
if ($expiryTelegramReminderDaysCsv !== '') {
    foreach (preg_split('/\s*,\s*/', $expiryTelegramReminderDaysCsv) as $dayToken) {
        if ($dayToken === '') {
            continue;
        }
        $dayValue = max(0, (int) $dayToken);
        if ($dayValue > 0) {
            $expiryTelegramReminderDaysList[] = $dayValue;
        }
    }
}
$expiryTelegramReminderDaysList = array_values(array_unique($expiryTelegramReminderDaysList));
$expiryTelegramReminderDaysZh = '-';
$expiryTelegramReminderDaysEn = '-';
if (!empty($expiryTelegramReminderDaysList)) {
    $zhParts = array_map(static function (int $day): string {
        return $day . '天';
    }, $expiryTelegramReminderDaysList);
    $expiryTelegramReminderDaysZh = count($zhParts) === 2 ? ($zhParts[0] . '及' . $zhParts[1]) : implode('、', $zhParts);

    $enParts = array_map(static function (int $day): string {
        return $day . ' day' . ($day === 1 ? '' : 's');
    }, $expiryTelegramReminderDaysList);
    $expiryTelegramReminderDaysEn = count($enParts) === 2 ? ($enParts[0] . ' and ' . $enParts[1]) : implode(', ', $enParts);
}
$expiryTelegramReminderDisplayName = $expiryTelegramReminderTelegramUsername !== ''
    ? '@' . ltrim($expiryTelegramReminderTelegramUsername, '@')
    : ($expiryTelegramReminderTelegramUserId > 0 ? ('ID: ' . $expiryTelegramReminderTelegramUserId) : '');

$sslRequestEnabled = !empty($sslRequestEnabled);
$sslRequestDomains = is_array($sslRequestDomains ?? null) ? $sslRequestDomains : [];
$sslCertificates = is_array($sslCertificates ?? null) ? $sslCertificates : ['items' => [], 'page' => 1, 'totalPages' => 1];
$sslCertificateItems = is_array($sslCertificates['items'] ?? null) ? $sslCertificates['items'] : [];
$sslCertificatesPage = max(1, (int) ($sslCertificates['page'] ?? 1));
$sslCertificatesTotalPages = max(1, (int) ($sslCertificates['totalPages'] ?? 1));

$inviteRegistrationInviteEnabled = !empty($inviteRegistrationInviteEnabled);
$domainPermanentUpgradeEnabled = !empty($domainPermanentUpgradeEnabled);
$domainPermanentUpgradeAssistRequired = max(1, intval($domainPermanentUpgradeAssistRequired ?? ($domainPermanentUpgradeState['assist_required'] ?? 3)));
$digFeatureEnabled = !empty($digFeatureEnabled);
$digSupportedTypes = is_array($digSupportedTypes ?? null) ? $digSupportedTypes : ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SRV'];
$hasAnyFeature = !empty($quotaRedeemEnabled)
    || !empty($dnsUnlockFeatureEnabled)
    || $inviteRegistrationInviteEnabled
    || $domainPermanentUpgradeEnabled
    || $hasRootdomainInvite
    || $sslRequestEnabled
    || $githubStarRewardEnabled
    || $telegramGroupRewardEnabled
    || $expiryTelegramReminderFeatureEnabled
    || $digFeatureEnabled;
?>

<?php if ($hasAnyFeature): ?>
    <div class="row g-3">
        <?php if (!empty($quotaRedeemEnabled)): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <h6 class="card-title"><i class="fas fa-ticket-alt text-success me-2"></i><?php echo $featureText('cfclient.feature.redeem.title', '额度兑换', 'Quota Redeem'); ?></h6>
                        <p class="text-muted small flex-grow-1 mb-3"><?php echo $featureText('cfclient.feature.redeem.desc', '输入兑换码获取更多注册额度。', 'Use redeem codes to unlock more registration quota.'); ?></p>
                        <button type="button" class="btn btn-outline-success" onclick="openQuotaRedeemModal()">
                            <i class="fas fa-gift me-1"></i><?php echo $featureText('cfclient.feature.redeem.button', '打开额度兑换', 'Open Quota Redeem'); ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($dnsUnlockFeatureEnabled)): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <h6 class="card-title"><i class="fas fa-unlock-alt text-warning me-2"></i><?php echo $featureText('cfclient.feature.unlock.title', 'DNS 解锁', 'DNS Unlock'); ?></h6>
                        <p class="text-muted small flex-grow-1 mb-3"><?php echo $featureText('cfclient.feature.unlock.desc', '查看解锁状态、复制解锁码并记录使用情况。', 'Check unlock status, copy unlock code, and review usage logs.'); ?></p>
                        <button type="button" class="btn btn-outline-warning" onclick="showDnsUnlockModal()">
                            <i class="fas fa-key me-1"></i><?php echo $featureText('cfclient.feature.unlock.button', '管理 DNS 解锁', 'Manage DNS Unlock'); ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($inviteRegistrationInviteEnabled): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <h6 class="card-title"><i class="fas fa-user-plus text-info me-2"></i><?php echo $featureText('cfclient.feature.invite_registration.title', '邀请注册', 'Invite Registration'); ?></h6>
                        <p class="text-muted small flex-grow-1 mb-3"><?php echo $featureText('cfclient.feature.invite_registration.desc', '管理邀请注册邀请码并查看邀请记录。', 'Manage invite registration codes and invitation logs.'); ?></p>
                        <button type="button" class="btn btn-outline-info" onclick="showInviteRegistrationModal()">
                            <i class="fas fa-id-card me-1"></i><?php echo $featureText('cfclient.feature.invite_registration.button', '打开邀请注册', 'Open Invite Registration'); ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($domainPermanentUpgradeEnabled): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <h6 class="card-title"><i class="fas fa-infinity text-danger me-2"></i><?php echo $featureText('cfclient.feature.permanent_upgrade.title', '域名永久升级', 'Domain Permanent Upgrade'); ?></h6>
                        <p class="text-muted small flex-grow-1 mb-3"><?php echo $featureText('cfclient.feature.permanent_upgrade.desc', '选择待升级域名，生成助力码邀请好友协助，达到目标后自动升级为永久有效。', 'Choose an eligible domain, share your assist code, and upgrade to permanent once the target is reached.'); ?></p>
                        <button type="button" class="btn btn-outline-danger" onclick="showDomainPermanentUpgradeModal()">
                            <i class="fas fa-rocket me-1"></i><?php echo $featureText('cfclient.feature.permanent_upgrade.button', '打开永久升级中心', 'Open Permanent Upgrade Center'); ?>
                        </button>
                        <small class="text-muted mt-2"><?php echo $featureText('cfclient.feature.permanent_upgrade.required', '当前目标：%s 位好友助力', 'Current target: %s assists', [$domainPermanentUpgradeAssistRequired]); ?></small>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($hasRootdomainInvite): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <h6 class="card-title"><i class="fas fa-user-friends text-secondary me-2"></i><?php echo $featureText('cfclient.feature.root_invite.title', '根域名邀请码', 'Root Domain Invite Codes'); ?></h6>
                        <p class="text-muted small flex-grow-1 mb-3"><?php echo $featureText('cfclient.feature.root_invite.desc', '查看需要邀请码的根域名并复制专属邀请码。', 'View invite-required root domains and copy your code.'); ?></p>
                        <button type="button" class="btn btn-outline-secondary" onclick="showRootdomainInviteCodesModal()">
                            <i class="fas fa-copy me-1"></i><?php echo $featureText('cfclient.feature.root_invite.button', '查看邀请码', 'View Invite Codes'); ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($digFeatureEnabled): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <h6 class="card-title"><i class="fas fa-network-wired text-primary me-2"></i><?php echo $featureText('cfclient.feature.dig.title', 'Dig DNS 探测', 'Dig DNS Probe'); ?></h6>
                        <p class="text-muted small mb-3"><?php echo $featureText('cfclient.feature.dig.desc', '查询多个公共解析器返回结果，快速判断 DNS 全球生效情况。', 'Query multiple public resolvers and quickly check global DNS propagation.'); ?></p>
                        <form id="digLookupForm" class="d-flex flex-column gap-2 mt-auto">
                            <label class="small text-muted mb-0" for="digLookupDomainInput"><?php echo $featureText('cfclient.feature.dig.domain', '域名', 'Domain'); ?></label>
                            <input
                                type="text"
                                class="form-control form-control-sm"
                                id="digLookupDomainInput"
                                placeholder="<?php echo htmlspecialchars($featureText('cfclient.feature.dig.domain_placeholder', '例如：www.example.com', 'e.g. www.example.com')); ?>"
                                autocomplete="off"
                                required
                            >
                            <label class="small text-muted mb-0" for="digLookupTypeSelect"><?php echo $featureText('cfclient.feature.dig.type', '记录类型', 'Record Type'); ?></label>
                            <select class="form-select form-select-sm" id="digLookupTypeSelect">
                                <?php foreach ($digSupportedTypes as $digType): ?>
                                    <?php $digTypeValue = strtoupper(trim((string) $digType)); ?>
                                    <?php if ($digTypeValue !== ''): ?>
                                        <option value="<?php echo htmlspecialchars($digTypeValue, ENT_QUOTES); ?>"><?php echo htmlspecialchars($digTypeValue, ENT_QUOTES); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-outline-primary" id="digLookupButton">
                                <i class="fas fa-search-location me-1"></i><?php echo $featureText('cfclient.feature.dig.submit', '开始 Dig 探测', 'Run Dig Probe'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($sslRequestEnabled): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <h6 class="card-title"><i class="fas fa-shield-alt text-primary me-2"></i><?php echo $featureText('cfclient.feature.ssl.title', 'SSL 证书申请', 'SSL Certificate Request'); ?></h6>
                        <p class="text-muted small mb-3"><?php echo $featureText('cfclient.feature.ssl.desc', '选择域名后系统将通过 Let\'s Encrypt 自动添加 DNS 验证记录并尝试签发证书。', 'Select a domain and the system will use Let\'s Encrypt with automatic DNS validation to issue a certificate.'); ?></p>
                        <?php if (!empty($sslRequestDomains)): ?>
                            <form method="post" class="d-flex flex-column gap-2 mt-auto">
                                <input type="hidden" name="action" value="request_ssl_certificate">
                                <label class="small text-muted mb-0" for="sslSubdomainSelect"><?php echo $featureText('cfclient.feature.ssl.domain_label', '选择申请域名', 'Choose domain'); ?></label>
                                <select class="form-select form-select-sm" id="sslSubdomainSelect" name="ssl_subdomain_id" required>
                                    <?php foreach ($sslRequestDomains as $sslDomain): ?>
                                        <?php $sslDomainId = intval($sslDomain['id'] ?? 0); ?>
                                        <?php $sslDomainName = (string) ($sslDomain['domain'] ?? ''); ?>
                                        <?php if ($sslDomainId > 0 && $sslDomainName !== ''): ?>
                                            <option value="<?php echo $sslDomainId; ?>"><?php echo htmlspecialchars($sslDomainName, ENT_QUOTES); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-outline-primary">
                                    <i class="fas fa-certificate me-1"></i><?php echo $featureText('cfclient.feature.ssl.button', '立即申请 SSL', 'Request SSL Now'); ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-warning small mb-0 mt-auto"><?php echo $featureText('cfclient.feature.ssl.no_domain', '当前没有可申请 SSL 的域名，请先完成域名注册。', 'No eligible domains found. Please register a domain first.'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($githubStarRewardEnabled): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h6 class="card-title mb-0"><i class="fab fa-github text-dark me-2"></i><?php echo $featureText('cfclient.feature.github_star.title', 'GitHub 点赞奖励', 'GitHub Star Reward'); ?></h6>
                            <?php if ($githubStarRewardAlreadyClaimed): ?>
                                <span class="badge bg-success"><?php echo $featureText('cfclient.feature.github_star.claimed', '已领取', 'Claimed'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><?php echo $featureText('cfclient.feature.github_star.pending', '待领取', 'Pending'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted small mb-2"><?php echo $featureText('cfclient.feature.github_star.desc', '点赞指定仓库后可领取注册额度奖励。', 'Star the configured repository to claim extra registration quota.'); ?></p>
                        <p class="small mb-3"><?php echo $featureText('cfclient.feature.github_star.reward_amount', '当前奖励：+%s 注册额度', 'Current reward: +%s quota', [intval($githubStarRewardAmount)]); ?></p>
                        <?php if ($githubStarRewardRepoUrl !== ''): ?>
                            <div class="d-flex flex-column gap-2 mt-auto">
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="<?php echo htmlspecialchars($githubStarRewardRepoUrl, ENT_QUOTES); ?>" class="btn btn-outline-dark" target="_blank" rel="noopener noreferrer">
                                        <i class="fab fa-github me-1"></i><?php echo $featureText('cfclient.feature.github_star.goto', '前往仓库点赞', 'Open Repository'); ?>
                                    </a>
                                </div>
                                <form method="post" class="m-0 d-flex flex-column gap-2">
                                    <input type="hidden" name="action" value="claim_github_star_reward">
                                    <label class="small text-muted mb-0" for="githubStarRewardUsernameInput">
                                        <?php echo $featureText('cfclient.feature.github_star.username', 'GitHub 用户名（用于核验）', 'GitHub Username (for verification)'); ?>
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control form-control-sm"
                                        id="githubStarRewardUsernameInput"
                                        name="github_username"
                                        maxlength="39"
                                        pattern="^(?!-)(?!.*--)[A-Za-z0-9-]{1,39}(?<!-)$"
                                        value="<?php echo htmlspecialchars($githubStarRewardGithubUsername, ENT_QUOTES); ?>"
                                        placeholder="<?php echo htmlspecialchars($featureText('cfclient.feature.github_star.username_placeholder', '请输入 GitHub 用户名', 'Enter your GitHub username')); ?>"
                                        <?php echo $githubStarRewardAlreadyClaimed ? 'readonly' : 'required'; ?>
                                    >
                                    <button type="submit" class="btn btn-primary" <?php echo $githubStarRewardAlreadyClaimed ? 'disabled' : ''; ?>>
                                        <i class="fas fa-check-circle me-1"></i><?php echo $featureText('cfclient.feature.github_star.claim_button', '我已点赞，领取额度', 'I starred, claim reward'); ?>
                                    </button>
                                </form>
                                <div class="small text-muted">
                                    <?php echo $featureText('cfclient.feature.github_star.verify_tip', '领取时会核验该用户名是否已对仓库点亮 Star。', 'The system verifies whether this username has starred the repository.'); ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning small mb-0 mt-auto"><?php echo $featureText('cfclient.feature.github_star.repo_missing', '管理员尚未配置可用的 GitHub 仓库地址。', 'The admin has not configured a valid GitHub repository URL yet.'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($telegramGroupRewardEnabled): ?>
            <?php
            $telegramDisplayName = $telegramGroupRewardTelegramUsername !== ''
                ? '@' . ltrim($telegramGroupRewardTelegramUsername, '@')
                : ($telegramGroupRewardTelegramUserId > 0 ? ('ID: ' . $telegramGroupRewardTelegramUserId) : '');
            $telegramClaimDisabled = $telegramGroupRewardAlreadyClaimed || !$telegramGroupRewardTelegramBound;
            ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h6 class="card-title mb-0"><i class="fab fa-telegram-plane text-primary me-2"></i><?php echo $featureText('cfclient.feature.telegram_group.title', 'Telegram 社群奖励', 'Telegram Group Reward'); ?></h6>
                            <?php if ($telegramGroupRewardAlreadyClaimed): ?>
                                <span class="badge bg-success"><?php echo $featureText('cfclient.feature.telegram_group.claimed', '已领取', 'Claimed'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><?php echo $featureText('cfclient.feature.telegram_group.pending', '待领取', 'Pending'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted small mb-2"><?php echo $featureText('cfclient.feature.telegram_group.desc', '加入官方群组并完成 Telegram 身份验证后可领取额度奖励。', 'Join the official group and complete Telegram verification to claim extra quota.'); ?></p>
                        <p class="small mb-3"><?php echo $featureText('cfclient.feature.telegram_group.reward_amount', '当前奖励：+%s 注册额度', 'Current reward: +%s quota', [intval($telegramGroupRewardAmount)]); ?></p>
                        <div class="d-flex flex-column gap-2 mt-auto">
                            <?php if ($telegramGroupRewardGroupLink !== ''): ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="<?php echo htmlspecialchars($telegramGroupRewardGroupLink, ENT_QUOTES); ?>" class="btn btn-outline-primary" target="_blank" rel="noopener noreferrer">
                                        <i class="fab fa-telegram-plane me-1"></i><?php echo $featureText('cfclient.feature.telegram_group.goto', '前往群组', 'Open Group'); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <?php if (!$telegramGroupRewardAlreadyClaimed && $telegramGroupRewardBotUsername !== ''): ?>
                                <div class="small text-muted" id="telegramRewardAuthStatus">
                                    <?php echo $telegramGroupRewardTelegramBound
                                        ? $featureText('cfclient.feature.telegram_group.bound_hint', '当前已绑定：%s，可直接领取或重新授权。', 'Bound account: %s. You can claim now or re-authorize.', [$telegramDisplayName !== '' ? $telegramDisplayName : '-'])
                                        : $featureText('cfclient.feature.telegram_group.auth_hint', '请先点击 Telegram 授权按钮完成身份绑定。', 'Please complete Telegram authorization first.'); ?>
                                </div>
                                <div class="telegram-login-widget-wrap">
                                    <script async src="https://telegram.org/js/telegram-widget.js?22"
                                        data-telegram-login="<?php echo htmlspecialchars($telegramGroupRewardBotUsername, ENT_QUOTES); ?>"
                                        data-size="large"
                                        data-userpic="false"
                                        data-request-access="write"
                                        data-onauth="cfTelegramRewardOnAuth(user)">
                                    </script>
                                </div>
                            <?php endif; ?>
                            <form method="post" class="m-0 d-flex flex-column gap-2" id="telegramGroupRewardForm">
                                <input type="hidden" name="action" value="claim_telegram_group_reward">
                                <input type="hidden" name="telegram_auth_id" id="telegramRewardAuthId" value="">
                                <input type="hidden" name="telegram_auth_username" id="telegramRewardAuthUsername" value="">
                                <input type="hidden" name="telegram_auth_first_name" id="telegramRewardAuthFirstName" value="">
                                <input type="hidden" name="telegram_auth_last_name" id="telegramRewardAuthLastName" value="">
                                <input type="hidden" name="telegram_auth_photo_url" id="telegramRewardAuthPhotoUrl" value="">
                                <input type="hidden" name="telegram_auth_date" id="telegramRewardAuthDate" value="">
                                <input type="hidden" name="telegram_auth_hash" id="telegramRewardAuthHash" value="">
                                <label class="small text-muted mb-0" for="telegramRewardUsernameInput">
                                    <?php echo $featureText('cfclient.feature.telegram_group.account', 'Telegram 账号', 'Telegram Account'); ?>
                                </label>
                                <input
                                    type="text"
                                    class="form-control form-control-sm"
                                    id="telegramRewardUsernameInput"
                                    name="telegram_username"
                                    value="<?php echo htmlspecialchars($telegramDisplayName, ENT_QUOTES); ?>"
                                    placeholder="<?php echo htmlspecialchars($featureText('cfclient.feature.telegram_group.account_placeholder', '请先授权 Telegram 账号', 'Please authorize your Telegram account first')); ?>"
                                    readonly
                                >
                                <button type="submit" class="btn btn-primary" id="telegramRewardClaimButton" <?php echo $telegramClaimDisabled ? 'disabled' : ''; ?>>
                                    <i class="fas fa-check-circle me-1"></i><?php echo $featureText('cfclient.feature.telegram_group.claim_button', '验证加入并领取额度', 'Verify membership and claim reward'); ?>
                                </button>
                            </form>
                            <?php if ($telegramGroupRewardBotUsername === '' && !$telegramGroupRewardAlreadyClaimed): ?>
                                <div class="alert alert-warning small mb-0">
                                    <?php echo $featureText('cfclient.feature.telegram_group.bot_missing', '管理员尚未配置 Telegram 机器人用户名，暂无法完成前台授权。', 'The Telegram bot username is not configured yet, so authorization is unavailable.'); ?>
                                </div>
                            <?php else: ?>
                                <div class="small text-muted">
                                    <?php echo $featureText('cfclient.feature.telegram_group.verify_tip', '领取时会校验该 Telegram 账号是否已加入指定群组。', 'The system verifies whether this Telegram account has joined the configured group.'); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($expiryTelegramReminderFeatureEnabled): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h6 class="card-title mb-0"><i class="fab fa-telegram-plane text-info me-2"></i><?php echo $featureText('cfclient.feature.expiry_telegram.title', 'Telegram 到期提醒', 'Telegram Expiry Reminder'); ?></h6>
                            <?php if ($expiryTelegramReminderSubscribed): ?>
                                <span class="badge bg-success"><?php echo $featureText('cfclient.feature.expiry_telegram.enabled', '已开启', 'Enabled'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?php echo $featureText('cfclient.feature.expiry_telegram.disabled', '未开启', 'Disabled'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted small mb-2"><?php echo $featureText('cfclient.feature.expiry_telegram.desc', '绑定telegram账号后可设置域名即将到期消息提醒服务。', 'After binding your Telegram account, you can enable reminders for domains that are about to expire.'); ?></p>
                        <p class="small mb-3"><?php echo $featureText('cfclient.feature.expiry_telegram.days', '开启通知后系统将在到期前 %s 为您推送消息提醒。', 'After enabling notifications, the system will send reminder messages at %s before expiration.', [$featureIsChinese ? $expiryTelegramReminderDaysZh : $expiryTelegramReminderDaysEn]); ?></p>
                        <div class="d-flex flex-column gap-2 mt-auto">
                            <?php if ($expiryTelegramReminderDisplayName !== ''): ?>
                                <div class="small text-muted"><?php echo $featureText('cfclient.feature.expiry_telegram.bound', '当前绑定账号：%s', 'Bound account: %s', [$expiryTelegramReminderDisplayName]); ?></div>
                            <?php endif; ?>
                            <?php if (!$expiryTelegramReminderConfigured): ?>
                                <div class="alert alert-warning small mb-0"><?php echo $featureText('cfclient.feature.expiry_telegram.misconfigured', '管理员尚未完成 Telegram 提醒配置，当前仅可关闭已有提醒。', 'Telegram reminder settings are incomplete. You can still disable existing reminders.'); ?></div>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-info" onclick="showExpiryTelegramReminderModal()">
                                <i class="fas fa-bell me-1"></i><?php echo $featureText('cfclient.feature.expiry_telegram.button', '管理 Telegram 提醒', 'Manage Telegram Reminder'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($digFeatureEnabled): ?>
        <div class="card border-0 shadow-sm mt-3" id="digResultCard" style="display:none;">
            <div class="card-body">
                <h6 class="card-title mb-3"><i class="fas fa-server me-2 text-secondary"></i><?php echo $featureText('cfclient.feature.dig.result_title', 'Dig 查询结果', 'Dig Result'); ?></h6>
                <div id="digResultContainer"></div>
            </div>
        </div>
        <div id="digAlertContainer" class="mt-3"></div>
    <?php endif; ?>

    <?php if ($sslRequestEnabled): ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body">
                <h6 class="card-title mb-3"><i class="fas fa-lock me-2 text-primary"></i><?php echo $featureText('cfclient.feature.ssl.list_title', 'SSL 证书信息', 'SSL Certificate Information'); ?></h6>
                <?php if (!empty($sslCertificateItems)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?php echo $featureText('cfclient.feature.ssl.table.domain', '域名', 'Domain'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.ssl.table.status', '状态', 'Status'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.ssl.table.issuer', '签发机构', 'Issuer'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.ssl.table.expires', '到期时间', 'Expires At'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.ssl.table.updated', '申请/签发时间', 'Request/Issued At'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sslCertificateItems as $sslItem): ?>
                                    <?php
                                    $sslStatus = strtolower((string) ($sslItem['status'] ?? ''));
                                    $statusClass = 'secondary';
                                    $statusText = $sslStatus;
                                    if ($sslStatus === 'issued') {
                                        $statusClass = 'success';
                                        $statusText = $featureText('cfclient.feature.ssl.status.issued', '已签发', 'Issued');
                                    } elseif ($sslStatus === 'processing') {
                                        $statusClass = 'warning';
                                        $statusText = $featureText('cfclient.feature.ssl.status.processing', '签发中', 'Processing');
                                    } elseif ($sslStatus === 'pending') {
                                        $statusClass = 'info';
                                        $statusText = $featureText('cfclient.feature.ssl.status.pending', '待处理', 'Pending');
                                    } elseif ($sslStatus === 'failed') {
                                        $statusClass = 'danger';
                                        $statusText = $featureText('cfclient.feature.ssl.status.failed', '失败', 'Failed');
                                    } elseif ($sslStatus === 'expired') {
                                        $statusClass = 'dark';
                                        $statusText = $featureText('cfclient.feature.ssl.status.expired', '已过期', 'Expired');
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars((string) ($sslItem['domain'] ?? ''), ENT_QUOTES); ?></div>
                                            <?php if (!empty($sslItem['last_error']) && $sslStatus === 'failed'): ?>
                                                <div class="small text-danger mt-1"><?php echo htmlspecialchars((string) $sslItem['last_error'], ENT_QUOTES); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars((string) $statusText, ENT_QUOTES); ?></span></td>
                                        <td><?php echo htmlspecialchars((string) ($sslItem['issuer'] ?? '-'), ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string) (($sslItem['expires_at'] ?? '') !== '' ? $sslItem['expires_at'] : '-'), ENT_QUOTES); ?></td>
                                        <td>
                                            <div class="small"><?php echo htmlspecialchars((string) (($sslItem['requested_at'] ?? '') !== '' ? $sslItem['requested_at'] : '-'), ENT_QUOTES); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars((string) (($sslItem['issued_at'] ?? '') !== '' ? $sslItem['issued_at'] : '-'), ENT_QUOTES); ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($sslCertificatesTotalPages > 1): ?>
                        <nav class="mt-3">
                            <ul class="pagination pagination-sm mb-0">
                                <?php for ($sslPage = 1; $sslPage <= $sslCertificatesTotalPages; $sslPage++): ?>
                                    <?php
                                    $sslParams = $cfClientBaseEntryQuery ?? ['m' => $moduleSlug];
                                    $sslParams['view'] = 'tools';
                                    $sslParams['ssl_page'] = $sslPage;
                                    $sslPageUrl = ($cfClientEntryScript ?? 'index.php') . '?' . http_build_query($sslParams);
                                    ?>
                                    <li class="page-item <?php echo $sslPage === $sslCertificatesPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars($sslPageUrl, ENT_QUOTES); ?>"><?php echo $sslPage; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-light border mb-0">
                        <i class="fas fa-info-circle me-1"></i><?php echo $featureText('cfclient.feature.ssl.empty', '暂无 SSL 申请记录。', 'No SSL certificate records yet.'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($githubStarRewardEnabled): ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body">
                <h6 class="card-title mb-3"><i class="fas fa-history me-2 text-secondary"></i><?php echo $featureText('cfclient.feature.github_star.history_title', 'GitHub 点赞奖励记录', 'GitHub Reward History'); ?></h6>
                <?php if (!empty($githubStarHistoryItems)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?php echo $featureText('cfclient.feature.github_star.history.time', '时间', 'Time'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.github_star.history.repo', '仓库', 'Repository'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.github_star.history.reward', '奖励', 'Reward'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.github_star.history.status', '状态', 'Status'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($githubStarHistoryItems as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($item['created_at'] ?? ''), ENT_QUOTES); ?></td>
                                        <td>
                                            <a href="<?php echo htmlspecialchars((string) ($item['repo_url'] ?? ''), ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer">
                                                <?php echo htmlspecialchars((string) ($item['repo_url'] ?? ''), ENT_QUOTES); ?>
                                            </a>
                                        </td>
                                        <td>+<?php echo intval($item['reward_amount'] ?? 0); ?></td>
                                        <td>
                                            <?php if (($item['status'] ?? '') === 'granted'): ?>
                                                <span class="badge bg-success"><?php echo $featureText('cfclient.feature.github_star.history.granted', '已发放', 'Granted'); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars((string) ($item['status'] ?? ''), ENT_QUOTES); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($githubStarHistoryTotalPages > 1): ?>
                        <nav class="mt-3">
                            <ul class="pagination pagination-sm mb-0">
                                <?php for ($page = 1; $page <= $githubStarHistoryTotalPages; $page++): ?>
                                    <?php
                                    $pageParams = $cfClientBaseEntryQuery ?? ['m' => $moduleSlug];
                                    $pageParams['view'] = 'tools';
                                    $pageParams['github_reward_page'] = $page;
                                    $pageUrl = ($cfClientEntryScript ?? 'index.php') . '?' . http_build_query($pageParams);
                                    ?>
                                    <li class="page-item <?php echo $page === $githubStarHistoryPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars($pageUrl, ENT_QUOTES); ?>"><?php echo $page; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-light border mb-0">
                        <i class="fas fa-info-circle me-1"></i><?php echo $featureText('cfclient.feature.github_star.history.empty', '暂无点赞奖励记录。', 'No GitHub reward records yet.'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($telegramGroupRewardEnabled): ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body">
                <h6 class="card-title mb-3"><i class="fas fa-history me-2 text-primary"></i><?php echo $featureText('cfclient.feature.telegram_group.history_title', 'Telegram 社群奖励记录', 'Telegram Reward History'); ?></h6>
                <?php if (!empty($telegramGroupHistoryItems)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?php echo $featureText('cfclient.feature.telegram_group.history.time', '时间', 'Time'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.telegram_group.history.group', '群组', 'Group'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.telegram_group.history.account', '账号', 'Account'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.telegram_group.history.reward', '奖励', 'Reward'); ?></th>
                                    <th><?php echo $featureText('cfclient.feature.telegram_group.history.status', '状态', 'Status'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($telegramGroupHistoryItems as $item): ?>
                                    <?php $itemUsername = trim((string) ($item['telegram_username'] ?? '')); ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($item['created_at'] ?? ''), ENT_QUOTES); ?></td>
                                        <td>
                                            <?php $groupLinkItem = trim((string) ($item['group_link'] ?? '')); ?>
                                            <?php if ($groupLinkItem !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($groupLinkItem, ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php echo htmlspecialchars($groupLinkItem, ENT_QUOTES); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($itemUsername !== ''): ?>
                                                @<?php echo htmlspecialchars(ltrim($itemUsername, '@'), ENT_QUOTES); ?>
                                            <?php else: ?>
                                                <span class="text-muted">ID: <?php echo intval($item['telegram_user_id'] ?? 0); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>+<?php echo intval($item['reward_amount'] ?? 0); ?></td>
                                        <td>
                                            <?php if (($item['status'] ?? '') === 'granted'): ?>
                                                <span class="badge bg-success"><?php echo $featureText('cfclient.feature.telegram_group.history.granted', '已发放', 'Granted'); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars((string) ($item['status'] ?? ''), ENT_QUOTES); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($telegramGroupHistoryTotalPages > 1): ?>
                        <nav class="mt-3">
                            <ul class="pagination pagination-sm mb-0">
                                <?php for ($page = 1; $page <= $telegramGroupHistoryTotalPages; $page++): ?>
                                    <?php
                                    $pageParams = $cfClientBaseEntryQuery ?? ['m' => $moduleSlug];
                                    $pageParams['view'] = 'tools';
                                    $pageParams['telegram_reward_page'] = $page;
                                    $pageUrl = ($cfClientEntryScript ?? 'index.php') . '?' . http_build_query($pageParams);
                                    ?>
                                    <li class="page-item <?php echo $page === $telegramGroupHistoryPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars($pageUrl, ENT_QUOTES); ?>"><?php echo $page; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-light border mb-0">
                        <i class="fas fa-info-circle me-1"></i><?php echo $featureText('cfclient.feature.telegram_group.history.empty', '暂无 Telegram 社群奖励记录。', 'No Telegram reward records yet.'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-1"></i><?php echo $featureText('cfclient.feature.none', '当前没有可用的扩展功能模块。', 'No additional feature modules are currently available.'); ?>
    </div>
<?php endif; ?>


<?php if ($telegramGroupRewardEnabled): ?>
    <script>
    (function () {
        const usernameInput = document.getElementById('telegramRewardUsernameInput');
        const claimButton = document.getElementById('telegramRewardClaimButton');
        const statusNode = document.getElementById('telegramRewardAuthStatus');
        const authMap = {
            id: document.getElementById('telegramRewardAuthId'),
            username: document.getElementById('telegramRewardAuthUsername'),
            first_name: document.getElementById('telegramRewardAuthFirstName'),
            last_name: document.getElementById('telegramRewardAuthLastName'),
            photo_url: document.getElementById('telegramRewardAuthPhotoUrl'),
            auth_date: document.getElementById('telegramRewardAuthDate'),
            hash: document.getElementById('telegramRewardAuthHash')
        };

        window.cfTelegramRewardOnAuth = function (user) {
            if (!user || typeof user !== 'object') {
                return;
            }
            Object.keys(authMap).forEach(function (key) {
                if (!authMap[key]) {
                    return;
                }
                const value = Object.prototype.hasOwnProperty.call(user, key) && user[key] !== null && user[key] !== undefined
                    ? String(user[key])
                    : '';
                authMap[key].value = value;
            });

            if (usernameInput) {
                const displayName = user.username ? ('@' + String(user.username).replace(/^@+/, '')) : ('ID: ' + String(user.id || ''));
                usernameInput.value = displayName;
            }

            if (statusNode) {
                const label = user.username ? ('@' + String(user.username).replace(/^@+/, '')) : ('ID: ' + String(user.id || ''));
                statusNode.textContent = <?php echo json_encode($featureText('cfclient.feature.telegram_group.auth_ready', '已完成 Telegram 授权：%s，现在可以提交领取。', 'Telegram authorized: %s. You can claim now.', ['%s'], false)); ?>.replace('%s', label);
                statusNode.classList.remove('text-danger');
            }

            if (claimButton && !claimButton.hasAttribute('data-force-disabled')) {
                claimButton.removeAttribute('disabled');
            }
        };

        <?php if ($telegramGroupRewardAlreadyClaimed): ?>
        if (claimButton) {
            claimButton.setAttribute('data-force-disabled', '1');
        }
        <?php endif; ?>
    })();
    </script>
<?php endif; ?>
