<?php
if (!defined('CFMOD_SAFE_JSON_FLAGS')) {
    define('CFMOD_SAFE_JSON_FLAGS', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
} catch (Exception $e) {}

if (function_exists('cfmod_load_language')) {
    cfmod_load_language();
}

if (!function_exists('cfclient_lang')) {
    function cfclient_lang(string $key, string $default = '', array $params = [], bool $escape = false): string
    {
        $text = cfmod_trans($key, $default);
        if (!empty($params)) {
            try {
                $text = vsprintf($text, $params);
            } catch (\Throwable $e) {
                // ignore formatting errors and fall back to untranslated text
            }
        }
        if ($escape) {
            return htmlspecialchars($text, ENT_QUOTES);
        }
        return $text;
    }
}

$cfClientLanguageMeta = function_exists('cfmod_resolve_language_preference') ? cfmod_resolve_language_preference() : ['normalized' => 'english', 'html' => 'en'];
$currentClientLanguage = $cfClientLanguageMeta['normalized'] ?? 'english';
$currentClientHtmlLang = $cfClientLanguageMeta['html'] ?? 'en';

$userid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
if ($userid <= 0) {
    $loginTitle = htmlspecialchars(cfmod_trans('cfclient.login_required_title', '需要登录'), ENT_QUOTES);
    $loginMessage = htmlspecialchars(cfmod_trans('cfclient.login_required', '请先登录后再访问此页面。'), ENT_QUOTES);
    $loginButton = htmlspecialchars(cfmod_trans('cfclient.login_button', '立即登录'), ENT_QUOTES);
    echo '<div class="container mt-4">';
    echo '<div class="alert alert-warning" role="alert">';
    echo '<h4 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> ' . $loginTitle . '</h4>';
    echo '<p class="mb-0">' . $loginMessage . '</p>';
    echo '</div>';
    echo '<a href="login.php" class="btn btn-primary">' . $loginButton . '</a>';
    echo '</div>';
    exit;
}

$cfClientViewModel = $GLOBALS['cfClientViewModel'] ?? null;
if (!is_array($cfClientViewModel) || !isset($cfClientViewModel['globals'])) {
    if (class_exists('CfClientViewModelBuilder')) {
        $cfClientViewModel = CfClientViewModelBuilder::build($userid);
    } else {
        $cfClientViewModel = ['globals' => []];
    }
}

$cfClientGlobals = $cfClientViewModel['globals'] ?? [];
if (!empty($cfClientGlobals)) {
    extract($cfClientGlobals, EXTR_SKIP);
}

$moduleSlug = $moduleSlug ?? (defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub');
$legacyModuleSlug = $legacyModuleSlug ?? (defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : $moduleSlug);
$cfmodAssetsBase = '/' . ltrim('modules/addons/' . $moduleSlug . '/assets', '/');
$nsBySubId = isset($nsBySubId) && is_array($nsBySubId) ? $nsBySubId : [];
$domainGiftSubdomains = isset($domainGiftSubdomains) && is_array($domainGiftSubdomains) ? $domainGiftSubdomains : [];
$module_settings = isset($module_settings) && is_array($module_settings) ? $module_settings : [];
$forbidden = isset($forbidden) && is_array($forbidden) ? $forbidden : [];
$roots = isset($roots) && is_array($roots) ? $roots : [];
$quota = isset($quota) && is_object($quota) ? $quota : (object) ['used_count' => 0, 'max_count' => 0, 'invite_bonus_count' => 0, 'invite_bonus_limit' => 0];
$dnsUnlockShareAllowed = isset($dnsUnlockShareAllowed) ? (bool) $dnsUnlockShareAllowed : true;
$clientAnnounceCookieKey = $clientAnnounceCookieKey ?? ('cfmod_client_announce_' . substr(md5($moduleSlug), 0, 8));
$cfmodClientNoscriptNotice = $cfmodClientNoscriptNotice ?? '';

if ($cfmodClientNoscriptNotice === '') {
    $noscriptText = cfmod_trans('cfmod.client.enable_js_notice', '为确保账户安全，请启用浏览器的 JavaScript 后重试。');
    $cfmodClientNoscriptNotice = '<noscript><div class="alert alert-danger m-3">' . htmlspecialchars($noscriptText) . '</div></noscript>';
}

$tables_exist = isset($tables_exist) ? (bool) $tables_exist : true;
if (!$tables_exist) {
    $notActivatedHeading = htmlspecialchars(cfmod_trans('cfclient.plugin_not_activated_heading', '插件未激活'), ENT_QUOTES);
    $notActivatedIntro = cfmod_trans('cfclient.plugin_not_activated_intro', '数据库表尚未创建，请先激活插件：');
    $notActivatedSteps = [
        cfmod_trans('cfclient.plugin_not_activated_step1', '进入 <strong>设置 → 插件模块</strong>。'),
        cfmod_trans('cfclient.plugin_not_activated_step2', '找到 “阿里云DNS 域名分发” 插件。'),
        cfmod_trans('cfclient.plugin_not_activated_step3', '点击 <strong>激活</strong> 按钮。'),
        cfmod_trans('cfclient.plugin_not_activated_step4', '激活成功后再访问此页面。'),
    ];
    $notActivatedBack = htmlspecialchars(cfmod_trans('cfclient.plugin_not_activated_back', '返回首页'), ENT_QUOTES);

    echo '<div class="container mt-4">';
    echo '<div class="alert alert-warning" role="alert">';
    echo '<h4><i class="fas fa-exclamation-triangle"></i> ' . $notActivatedHeading . '</h4>';
    echo '<p>' . $notActivatedIntro . '</p>';
    echo '<ol>';
    foreach ($notActivatedSteps as $step) {
        echo '<li>' . $step . '</li>';
    }
    echo '</ol>';
    echo '</div>';
    echo '<a href="index.php" class="btn btn-secondary">' . $notActivatedBack . '</a>';
    echo '</div>';
    exit;
}

$msg = $msg ?? '';
$msg_type = $msg_type ?? '';
$registerError = $registerError ?? '';
$isUserBannedOrInactive = !empty($isUserBannedOrInactive);
$domainGiftEnabled = !empty($domainGiftEnabled);
$quotaRedeemEnabled = !empty($quotaRedeemEnabled);
$sslRequestEnabled = !empty($sslRequestEnabled);
$whoisFeatureEnabled = !empty($whoisFeatureEnabled);

$cfClientJsLang = [
    'registerEnterPrefix' => cfmod_trans('cfclient.js.register_enter_prefix', '请输入域名前缀'),
    'registerSelectRoot' => cfmod_trans('cfclient.js.register_select_root', '请选择根域名'),
    'registerEdgeError' => cfmod_trans('cfclient.js.register_edge_error', '子域名前缀不能以 “.” 或 “-” 开头或结尾'),
    'registerForbiddenPrefix' => cfmod_trans('cfclient.js.register_forbidden_prefix', '该前缀被禁止使用，请选择其他前缀'),
    'nsManagementDisabled' => cfmod_trans('cfclient.js.ns_management_disabled', '已禁止设置 DNS 服务器（NS）。'),
    'nsAtLeastOne' => cfmod_trans('cfclient.js.ns_at_least_one', '请至少输入一个 NS 服务器'),
    'nsInvalidFormat' => cfmod_trans('cfclient.js.ns_invalid_format', 'NS 格式不正确：%s'),
    'nsInputPlaceholder' => cfmod_trans('cfclient.js.ns_input_placeholder', '例如：ns1.example.com'),
    'nsRemoveServer' => cfmod_trans('cfclient.js.ns_remove_server', '删除 DNS 服务器'),
    'nsAddServer' => cfmod_trans('cfclient.js.ns_add_server', '添加 DNS 服务器'),
    'nsMaxReached' => cfmod_trans('cfclient.js.ns_max_reached', '最多可添加 %s 个 DNS 服务器'),
    'dnsUnlockRequired' => cfmod_trans('cfclient.js.dns_unlock_required', '请先完成 DNS 解锁后再操作。'),
    'dnsUnlockCopySuccess' => cfmod_trans('cfclient.js.dns_unlock_copy_success', '解锁码已复制'),
    'dnsUnlockCopyFailed' => cfmod_trans('cfclient.js.dns_unlock_copy_failed', '复制失败，请手动复制'),
    'dnsNameEdgeError' => cfmod_trans('cfclient.js.dns_name_edge_error', '解析名称不能以点或连字符开头或结尾'),
    'dnsNameDoubleDot' => cfmod_trans('cfclient.js.dns_name_double_dot', '解析名称不能包含连续的点'),
    'dnsNameEmptyLabel' => cfmod_trans('cfclient.js.dns_name_empty_label', '解析名称不能包含空的标签片段'),
    'dnsNameLabelEdge' => cfmod_trans('cfclient.js.dns_name_label_edge', '解析名称中的每个标签都不能以连字符开头或结尾'),
    'srvPriorityInvalid' => cfmod_trans('cfclient.js.srv_priority_invalid', 'SRV 记录的优先级必须在 0-65535 之间'),
    'srvWeightInvalid' => cfmod_trans('cfclient.js.srv_weight_invalid', 'SRV 记录的权重必须在 0-65535 之间'),
    'srvPortInvalid' => cfmod_trans('cfclient.js.srv_port_invalid', 'SRV 记录的端口必须在 1-65535 之间'),
    'srvTargetRequired' => cfmod_trans('cfclient.js.srv_target_required', 'SRV 记录的目标地址不能为空'),
    'srvTargetInvalid' => cfmod_trans('cfclient.js.srv_target_invalid', '请输入有效的 SRV 目标主机名'),
    'recordContentRequired' => cfmod_trans('cfclient.js.record_content_required', '请输入记录内容'),
    'ipv4Invalid' => cfmod_trans('cfclient.js.ipv4_invalid', '请输入有效的 IPv4 地址'),
    'ipv6Invalid' => cfmod_trans('cfclient.js.ipv6_invalid', '请输入有效的 IPv6 地址'),
    'domainInvalid' => cfmod_trans('cfclient.js.domain_invalid', '请输入有效的域名'),
    'caaValueRequired' => cfmod_trans('cfclient.js.caa_value_required', 'CAA 记录的 Value 不能为空'),
    'rootLimitHint' => cfmod_trans('cfclient.js.root_limit_hint', '该根域名每个账号最多注册 %s 个'),
    'rootSuffixPlaceholder' => cfmod_trans('cfclient.js.root_suffix_placeholder', '根域名'),
    'nsNotConfigured' => cfmod_trans('cfclient.js.ns_not_configured', '（未设置）'),
    'buttonSubmitting' => cfmod_trans('cfclient.js.button_submitting', '提交中...'),
    'buttonSaving' => cfmod_trans('cfclient.js.button_saving', '保存中...'),
    'giftSelectDomain' => cfmod_trans('cfclient.js.gift.select_domain', '请选择域名'),
    'giftNoDomains' => cfmod_trans('cfclient.js.gift.no_domains', '暂无可用域名'),
    'giftInTransfer' => cfmod_trans('cfclient.js.gift.in_transfer', '（转赠中）'),
    'giftSelectRequired' => cfmod_trans('cfclient.js.gift.select_required', '请选择要转赠的域名'),
    'giftGenerateSuccess' => cfmod_trans('cfclient.js.gift.generate_success', '接收码已生成，请尽快分享给受赠人。'),
    'giftGenerateFailed' => cfmod_trans('cfclient.js.gift.generate_failed', '生成接收码失败，请稍后再试'),
    'networkError' => cfmod_trans('cfclient.js.network_error', '网络异常，请稍后再试'),
    'giftCopyEmpty' => cfmod_trans('cfclient.js.gift.copy_empty', '暂无可复制的接收码'),
    'giftCopySuccess' => cfmod_trans('cfclient.js.gift.copy_success', '接收码已复制'),
    'giftCopyFailed' => cfmod_trans('cfclient.js.gift.copy_failed', '复制失败，请手动复制'),
    'giftEnterCode' => cfmod_trans('cfclient.js.gift.enter_code', '请输入接收码'),
    'giftAcceptSuccess' => cfmod_trans('cfclient.js.gift.accept_success', '领取成功，即将刷新页面'),
    'giftAcceptFailed' => cfmod_trans('cfclient.js.gift.accept_failed', '领取失败，请稍后再试'),
    'giftHistoryEmpty' => cfmod_trans('cfclient.js.gift.history_empty', '暂无转赠记录'),
    'giftStatusPending' => cfmod_trans('cfclient.js.gift.status.pending', '进行中'),
    'giftStatusAccepted' => cfmod_trans('cfclient.js.gift.status.accepted', '已完成'),
    'giftStatusCancelled' => cfmod_trans('cfclient.js.gift.status.cancelled', '已取消'),
    'giftStatusExpired' => cfmod_trans('cfclient.js.gift.status.expired', '已过期'),
    'giftTimelineStart' => cfmod_trans('cfclient.js.gift.timeline.start', '发起：'),
    'giftTimelineCompleted' => cfmod_trans('cfclient.js.gift.timeline.completed', '完成：'),
    'giftTimelineEnded' => cfmod_trans('cfclient.js.gift.timeline.ended', '结束：'),
    'giftRoleReceived' => cfmod_trans('cfclient.js.gift.role.received', '（接收）'),
    'giftRoleSent' => cfmod_trans('cfclient.js.gift.role.sent', '（转赠）'),
    'giftActionCancel' => cfmod_trans('cfclient.js.gift.action.cancel', '取消'),
    'giftCancelSuccess' => cfmod_trans('cfclient.js.gift.cancel_success', '已取消转赠，即将刷新页面'),
    'giftCancelFailed' => cfmod_trans('cfclient.js.gift.cancel_failed', '取消失败，请稍后再试'),
    'giftHistoryLoadFailed' => cfmod_trans('cfclient.js.gift.history_load_failed', '加载历史记录失败'),
    'redeemEnterCode' => cfmod_trans('cfclient.js.redeem.enter_code', '请输入兑换码'),
    'redeemSuccess' => cfmod_trans('cfclient.js.redeem.success', '兑换成功，正在刷新页面'),
    'redeemFailed' => cfmod_trans('cfclient.js.redeem.failed', '兑换失败：%s'),
    'redeemHistoryEmpty' => cfmod_trans('cfclient.js.redeem.history_empty', '暂无兑换记录'),
    'redeemHistoryLoadFailed' => cfmod_trans('cfclient.js.redeem.history_load_failed', '加载兑换记录失败'),
    'cfclient.redeem.status.success' => cfmod_trans('cfclient.redeem.status.success', '成功'),
    'cfclient.redeem.status.failed' => cfmod_trans('cfclient.redeem.status.failed', '失败'),
    'giftRealtimeLabel' => cfmod_trans('cfclient.js.gift.realtime_label', '实时Top10'),
    'api.copySuccess' => cfmod_trans('cfclient.js.api.copy_success', '已复制到剪贴板'),
    'api.copyFailed' => cfmod_trans('cfclient.js.api.copy_failed', '复制失败：'),
    'api.createFailedWithReason' => cfmod_trans('cfclient.js.api.create_failed_with_reason', '创建失败：'),
    'api.createFailedGeneric' => cfmod_trans('cfclient.js.api.create_failed_generic', '创建失败，请重试'),
    'api.detailsFailed' => cfmod_trans('cfclient.js.api.details_failed', '获取详情失败：'),
    'api.regenerateConfirm' => cfmod_trans('cfclient.js.api.regenerate_confirm', '重新生成后，旧的API Secret将立即失效，确定继续吗？'),
    'api.regenerateFailed' => cfmod_trans('cfclient.js.api.regenerate_failed', '重新生成失败：'),
    'api.deleteConfirm' => cfmod_trans('cfclient.js.api.delete_confirm', '确定要删除此API密钥吗？删除后无法恢复！'),
    'api.deleteSuccess' => cfmod_trans('cfclient.js.api.delete_success', '删除成功'),
    'api.deleteFailed' => cfmod_trans('cfclient.js.api.delete_failed', '删除失败：'),
    'api.statusActive' => cfmod_trans('cfclient.js.api.status_active', '启用'),
    'api.statusDisabled' => cfmod_trans('cfclient.js.api.status_disabled', '禁用'),
    'api.neverUsed' => cfmod_trans('cfclient.js.api.never_used', '从未使用'),
    'invite.copySuccess' => cfmod_trans('cfclient.js.invite.copy_success', '邀请码已复制'),
    'invite.copyFailed' => cfmod_trans('cfclient.js.invite.copy_failed', '复制失败，请手动复制'),
    'invite.snapshotPreview' => cfmod_trans('cfclient.js.invite.snapshot_preview', '查看期数：%1$s 至 %2$s 的快照详情\n\n功能开发中...'),
    'tableEmpty' => cfmod_trans('cfclient.js.table.empty', '暂无数据'),
    'tableLoading' => cfmod_trans('cfclient.js.table.loading', '加载中...'),
    'tableHidden' => cfmod_trans('cfclient.js.table.hidden', '已隐藏'),
    'inviteCodeCopied' => cfmod_trans('cfclient.js.invite.code_copied', '邀请码已复制'),
    'inviteCopyFailed' => cfmod_trans('cfclient.js.invite.copy_failed', '复制失败，请手动复制'),
    'inviteSnapshotMessage' => cfmod_trans('cfclient.js.invite.snapshot_message', '查看期数：%1$s 至 %2$s 的快照详情\n\n功能开发中...'),
    'dnsActionCreate' => cfmod_trans('cfclient.js.dns.action_create', '创建'),
    'dnsActionUpdate' => cfmod_trans('cfclient.js.dns.action_update', '更新'),
    'dnsConfirmSummary' => cfmod_trans('cfclient.js.dns.confirm_summary', '将要%1$s记录\n名称: %2$s\n类型: %3$s\n内容: %4$s\nTTL: %5$s\n线路: %6$s'),
    'dnsConfirmPrompt' => cfmod_trans('cfclient.js.dns.confirm_prompt', '确认提交吗？'),
];

$registerBlockMessage = $maintenanceMessage ?: cfmod_trans('cfclient.register_paused_notice', '当前已暂停注册或系统维护中，暂不可操作。');

if (empty($_SESSION['cfmod_csrf'])) {
    $_SESSION['cfmod_csrf'] = bin2hex(random_bytes(16));
}
echo '<script>window.CF_MOD_CSRF = ' . json_encode($_SESSION['cfmod_csrf'], CFMOD_SAFE_JSON_FLAGS) . ';</script>';
echo '<script>(function(){var token=window.CF_MOD_CSRF||"";if(!token){return;}function inject(scope){if(!scope){return;}var forms=scope.querySelectorAll("form");forms.forEach(function(form){if(form.dataset.cfmodSkipCsrf==="1"){return;}if(form.querySelector("input[name=\'cfmod_csrf_token\']")){return;}var input=document.createElement("input");input.type="hidden";input.name="cfmod_csrf_token";input.value=token;form.appendChild(input);});}if(document.readyState!=="loading"){inject(document.getElementById("cfmod-client-root")||document);}else{document.addEventListener("DOMContentLoaded",function(){inject(document.getElementById("cfmod-client-root")||document);});}})();</script>';

$cfmodClientCsrfValid = $GLOBALS['cfmod_client_csrf_valid'] ?? true;
if (!$cfmodClientCsrfValid && $msg === '') {
    $msg = cfmod_trans('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。');
    $msg_type = 'danger';
}

$dnsUnlockFeatureEnabled = !empty($dnsUnlockFeatureEnabled);
$inviteRegistrationEnabled = !empty($inviteRegistrationEnabled);
$cfClientApiTemplateExists = file_exists(__DIR__ . '/api_management.tpl');
$cfClientApiEnabled = (($module_settings['enable_user_api'] ?? 'on') === 'on');
$cfClientHasRootdomainInvite = false;
if (!empty($rootInviteRequiredMap) && is_array($rootInviteRequiredMap)) {
    foreach ($rootInviteRequiredMap as $requiredFlag) {
        if (!empty($requiredFlag)) {
            $cfClientHasRootdomainInvite = true;
            break;
        }
    }
}
$cfClientHasGiftFeature = !empty($domainGiftEnabled);
$cfClientHasToolFeatures = !empty($quotaRedeemEnabled)
    || !empty($githubStarRewardEnabled)
    || $sslRequestEnabled
    || $dnsUnlockFeatureEnabled
    || $inviteRegistrationEnabled
    || $cfClientHasRootdomainInvite;

$cfClientEntryScript = class_exists('CfClientController')
    ? CfClientController::resolveClientEntryScript()
    : 'index.php';
$cfClientBaseEntryQuery = class_exists('CfClientController')
    ? CfClientController::buildClientBaseQuery($moduleSlug)
    : ['m' => $moduleSlug];
if (!is_array($cfClientBaseEntryQuery) || empty($cfClientBaseEntryQuery)) {
    $cfClientBaseEntryQuery = ['m' => $moduleSlug];
}

$cfClientIsChinese = strtolower((string) $currentClientLanguage) === 'chinese';
$cfClientNavText = static function (string $key, string $zh, string $en) use ($cfClientIsChinese): string {
    return cfclient_lang($key, $cfClientIsChinese ? $zh : $en, [], true);
};
$cfClientNormalizeLink = static function (string $url, string $fallback = ''): string {
    $url = trim($url);
    if ($url === '') {
        return $fallback;
    }
    if (preg_match('/^\s*javascript:/i', $url)) {
        return $fallback;
    }
    return $url;
};
$cfClientSupportTicketUrl = $cfClientNormalizeLink((string) ($clientSupportTicketUrl ?? ($module_settings['client_support_ticket_url'] ?? '')), 'submitticket.php');
$cfClientSupportGroupUrl = $cfClientNormalizeLink((string) ($clientSupportGroupUrl ?? ($module_settings['client_support_group_url'] ?? '')), 'https://t.me/+l9I5TNRDLP5lZDBh');
$cfClientPortalUrl = $cfClientNormalizeLink((string) ($clientPortalUrl ?? ($module_settings['client_portal_url'] ?? '')), 'index.php');

$cfClientViewItems = [
    'domains' => [
        'icon' => 'fas fa-globe',
        'label' => $cfClientNavText('cfclient.nav.domains', '域名管理', 'Domains'),
        'description' => $cfClientNavText('cfclient.nav.domains_desc', '管理域名与 DNS 解析记录', 'Manage domains and DNS records'),
    ],
];
if ($cfClientHasGiftFeature) {
    $cfClientViewItems['gift'] = [
        'icon' => 'fas fa-exchange-alt',
        'label' => $cfClientNavText('cfclient.nav.gift', '域名转赠', 'Domain Transfer'),
        'description' => $cfClientNavText('cfclient.nav.gift_desc', '发起、领取与管理转赠记录', 'Send, receive and manage transfers'),
    ];
}
if ($cfClientHasToolFeatures) {
    $cfClientViewItems['tools'] = [
        'icon' => 'fas fa-toolbox',
        'label' => $cfClientNavText('cfclient.nav.tools', '功能中心', 'Feature Center'),
        'description' => $cfClientNavText('cfclient.nav.tools_desc', '兑换与辅助功能入口', 'Redeem and utility features'),
    ];
}
if ($cfClientApiEnabled && $cfClientApiTemplateExists) {
    $cfClientViewItems['api'] = [
        'icon' => 'fas fa-key',
        'label' => $cfClientNavText('cfclient.nav.api', 'API 管理', 'API Management'),
        'description' => $cfClientNavText('cfclient.nav.api_desc', '管理自动化调用密钥', 'Manage API keys for automation'),
    ];
}
$cfClientViewItems['partner'] = [
    'icon' => 'fas fa-handshake',
    'label' => $cfClientNavText('cfclient.nav.partner', '合作伙伴计划', 'Partner Program'),
    'description' => $cfClientNavText('cfclient.nav.partner_desc', '申请域名经销合作与赞助支持', 'Apply for reseller cooperation and sponsorship support'),
];
if ($whoisFeatureEnabled) {
    $cfClientViewItems['whois'] = [
        'icon' => 'fas fa-user-shield',
        'label' => $cfClientNavText('cfclient.nav.whois', 'WHOIS 查询', 'WHOIS Lookup'),
        'description' => $cfClientNavText('cfclient.nav.whois_desc', '查询域名 WHOIS 并管理隐私开关', 'Lookup WHOIS and manage privacy toggle'),
    ];
}
$cfClientViewItems['help'] = [
    'icon' => 'fas fa-life-ring',
    'label' => $cfClientNavText('cfclient.nav.help', '帮助中心', 'Help Center'),
    'description' => $cfClientNavText('cfclient.nav.help_desc', '查看使用提示与支持入口', 'Usage tips and support channels'),
];

$cfClientCurrentView = strtolower(trim((string) ($_GET['view'] ?? 'domains')));
if (!isset($cfClientViewItems[$cfClientCurrentView])) {
    $cfClientCurrentView = 'domains';
}

$cfClientViewUrls = [];
foreach (array_keys($cfClientViewItems) as $viewKey) {
    $viewParams = $cfClientBaseEntryQuery;
    $viewParams['view'] = $viewKey;
    $cfClientViewUrls[$viewKey] = $cfClientEntryScript . '?' . http_build_query($viewParams);
}
$cfClientCurrentViewMeta = $cfClientViewItems[$cfClientCurrentView] ?? $cfClientViewItems['domains'];
?>
<script>
window.__nsBySubId = <?php echo json_encode($nsBySubId ?? [], CFMOD_SAFE_JSON_FLAGS); ?>;
</script>
    <script>
    // 若账户被封禁/停用，则禁用所有交互控件，仅允许浏览
    (function(){
        var banned = <?php echo $isUserBannedOrInactive ? 'true' : 'false'; ?>;
        if(!banned) return;
        try{
            // 禁用所有表单提交
            document.querySelectorAll('form').forEach(function(f){
                f.addEventListener('submit', function(e){
                    e.preventDefault();
                    e.stopPropagation();
                }, true);
            });
            // 禁用所有按钮和输入
            document.querySelectorAll('button, input, select, textarea, a.btn').forEach(function(el){
                // 允许关闭提示、返回首页等非危险按钮
                if(el.closest('#messageAlert')) return;
                if(el.matches('.btn-close')) return;
                el.setAttribute('disabled','disabled');
                el.classList.add('disabled');
                el.style.pointerEvents = 'none';
            });
            // 关闭可能的模态触发
            document.querySelectorAll('[data-bs-toggle="modal"]').forEach(function(el){
                el.removeAttribute('data-bs-toggle');
            });
        }catch(e){}
    })();
    </script>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentClientHtmlLang, ENT_QUOTES); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo cfclient_lang('cfclient.page.title', '我的免费域名管理', [], true); ?> - <?php echo htmlspecialchars($_SESSION['fullname'] ?? 'DNSHE.COM'); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="<?php echo htmlspecialchars($cfmodAssetsBase . '/css/bootstrap.min.css', ENT_QUOTES); ?>" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="<?php echo htmlspecialchars($cfmodAssetsBase . '/css/fontawesome-all.min.css', ENT_QUOTES); ?>" rel="stylesheet">
    
    <style>
        body {
            background-color: #f8f9fa;
        }
        .main-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            margin: 20px auto;
            max-width: 1400px;
        }
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px 10px 0 0;
            position: relative;
        }
        .header-language-switcher {
            position: absolute;
            top: 15px;
            right: 20px;
        }
        .header-language-switcher .btn {
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: none;
        }
        .header-language-switcher .btn:hover,
        .header-language-switcher .btn:focus {
            background: rgba(255, 255, 255, 0.3);
            color: #fff;
        }
        @media (max-width: 576px) {
            .header-language-switcher {
                position: static;
                margin-bottom: 15px;
                display: inline-block;
            }
            .header-section {
                padding-top: 60px;
            }
        }
        .subdomain-card {
            transition: all 0.3s ease;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .subdomain-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        .quota-progress {
            height: 25px;
            border-radius: 15px;
        }
        .cf-quota-action-col {
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }
        .cf-quota-action-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: flex-start;
        }
        @media (min-width: 768px) {
            .cf-quota-action-col {
                border-left: 1px solid #e9ecef;
                padding-left: 1rem;
            }
        }
        .status-badge {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
        }
        .btn-custom {
            border-radius: 20px;
            padding: 8px 20px;
            font-weight: 500;
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e9ecef;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        /* DNS记录名称样式优化 */
        .dns-record-name {
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
        }
        
        .dns-record-name .badge {
            font-size: 0.9rem !important;
            padding: 0.4rem 0.6rem;
        }
        
        .dns-record-name .text-dark {
            font-size: 1rem;
            letter-spacing: 0.3px;
        }
        
        /* DNS名称字体优化 - 更清晰易读 */
        .dns-name-text {
            font-family: 'Segoe UI', 'Microsoft YaHei', 'PingFang SC', 'Helvetica Neue', Arial, sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: #000000;
            letter-spacing: 0.5px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
            padding: 2px 4px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        .dns-name-text:hover {
            transform: translateY(-1px);
            text-shadow: 0 2px 4px rgba(0,0,0,0.15);
            color: #2c3e50;
        }
        
        /* 表格内容样式优化 */
        .table-sm td {
            vertical-align: middle;
            padding: 0.75rem 0.5rem;
        }
        
        .table-sm th {
            font-weight: 600;
            color: #495057;
        }
        
        /* DNS记录表格样式 */
        .table-bordered {
            border: 1px solid #dee2e6;
        }
        
        .table-bordered th,
        .table-bordered td {
            border: 1px solid #dee2e6;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0,123,255,0.05);
        }
        
        /* 复制按钮样式 */
        .btn-link.btn-sm {
            padding: 0.25rem;
            margin-left: 0.5rem;
        }
        
        .btn-link.btn-sm:hover {
            background-color: rgba(0,123,255,0.1);
            border-radius: 4px;
        }
        
        /* 消息提示样式 - 防止自动消失 */
        #messageAlert {
            opacity: 1 !important;
            display: block !important;
            transition: none !important;
        }
        
        #messageAlert.fade {
            opacity: 1 !important;
        }
        
        #messageAlert.show {
            opacity: 1 !important;
        }
        
        /* 确保消息提示始终可见 */
        .alert[role="alert"] {
            opacity: 1 !important;
            display: block !important;
        }
        
        /* 注册模态框中的重要说明样式 */
        #registerImportantInfo {
            opacity: 1 !important;
            display: block !important;
            visibility: visible !important;
            transition: none !important;
        }
        
        /* 确保模态框中的提示信息始终可见 */
        .modal .alert {
            opacity: 1 !important;
            display: block !important;
            visibility: visible !important;
        }
        
        /* DNS设置模态框中的提示样式 */
        #dnsImportantInfo,
        #dnsUsageTips {
            opacity: 1 !important;
            display: block !important;
            visibility: visible !important;
            transition: none !important;
        }
        
        /* 域名知识小贴士中的重要提示样式 */
        #dnsTimeoutWarning {
            opacity: 1 !important;
            display: block !important;
            visibility: visible !important;
            transition: none !important;
        }

        .ns-inputs-container {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .ns-input-row .form-control {
            border-radius: 0.6rem 0 0 0.6rem;
        }

        .ns-input-row .btn {
            border-radius: 0 0.6rem 0.6rem 0;
            min-width: 44px;
        }

        #ns_add_input_btn {
            border-style: dashed;
        }

        .cf-client-layout {
            display: flex;
            gap: 1.35rem;
            align-items: flex-start;
        }

        .cf-client-sidebar {
            width: 268px;
            flex-shrink: 0;
            position: sticky;
            top: 20px;
        }

        .cf-client-sidebar .list-group {
            gap: 10px;
        }

        .cf-client-sidebar .cf-client-nav-link {
            border: 1px solid #e9edf3;
            border-radius: 12px;
            color: #495057;
            background: #f8fafc;
            margin-bottom: 0;
            padding: 12px 14px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .cf-client-sidebar .cf-client-nav-link:hover {
            border-color: #cdd7ff;
            transform: translateY(-1px);
        }

        .cf-client-sidebar .cf-client-nav-link i {
            width: 18px;
            text-align: center;
        }

        .cf-client-sidebar .cf-nav-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .cf-client-sidebar .cf-nav-label {
            font-weight: 600;
        }

        .cf-client-sidebar .cf-nav-desc {
            color: #6c757d;
            font-size: 12px;
            margin-top: 2px;
        }

        .cf-client-sidebar .cf-client-nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.25);
        }

        .cf-client-sidebar .cf-client-nav-link.active .cf-nav-desc {
            color: rgba(255, 255, 255, 0.85);
        }

        .cf-client-content {
            flex: 1;
            min-width: 0;
        }

        .cf-client-view-header {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            background: #fff;
            margin-bottom: 1rem;
        }

        @media (max-width: 992px) {
            .cf-client-layout {
                flex-direction: column;
            }

            .cf-client-sidebar {
                width: 100%;
                position: static;
            }

            .cf-client-sidebar .list-group {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .cf-client-sidebar .cf-client-nav-link {
                min-height: 62px;
            }

            .cf-client-sidebar .cf-nav-desc {
                display: none;
            }
        }

        @media (max-width: 576px) {
            .cf-client-sidebar .list-group {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div id="cfmod-client-root">
<?php include __DIR__ . '/client/partials/header.tpl'; ?>
<?php include __DIR__ . '/client/partials/alerts.tpl'; ?>

<div class="p-4">
    <?php include __DIR__ . '/client/partials/messages.tpl'; ?>

    <div class="cf-client-layout">
        <aside class="cf-client-sidebar">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-2">
                    <div class="small text-muted px-2 pb-2">
                        <?php echo $cfClientNavText('cfclient.sidebar.nav_title', '功能导航', 'Navigation'); ?>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($cfClientViewItems as $viewKey => $viewMeta): ?>
                            <a href="<?php echo htmlspecialchars($cfClientViewUrls[$viewKey] ?? '#', ENT_QUOTES); ?>"
                               class="list-group-item list-group-item-action cf-client-nav-link <?php echo $cfClientCurrentView === $viewKey ? 'active' : ''; ?>">
                                <i class="<?php echo htmlspecialchars($viewMeta['icon'], ENT_QUOTES); ?>"></i>
                                <span class="cf-nav-text">
                                    <span class="cf-nav-label"><?php echo $viewMeta['label']; ?></span>
                                    <span class="cf-nav-desc"><?php echo $viewMeta['description'] ?? ''; ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($cfClientSupportTicketUrl !== '' || $cfClientSupportGroupUrl !== '' || $cfClientPortalUrl !== ''): ?>
                            <div class="small text-muted px-2 pt-2 pb-1">
                                <?php echo $cfClientNavText('cfclient.sidebar.support_title', '支持入口', 'Support Links'); ?>
                            </div>
                            <?php if ($cfClientSupportTicketUrl !== ''): ?>
                                <a href="<?php echo htmlspecialchars($cfClientSupportTicketUrl, ENT_QUOTES); ?>" class="list-group-item list-group-item-action cf-client-nav-link" target="_blank" rel="noopener noreferrer">
                                    <i class="fas fa-ticket-alt"></i>
                                    <span class="cf-nav-text">
                                        <span class="cf-nav-label"><?php echo $cfClientNavText('cfclient.sidebar.support_ticket', '提交工单', 'Submit Ticket'); ?></span>
                                        <span class="cf-nav-desc"><?php echo $cfClientNavText('cfclient.sidebar.support_ticket_desc', '反馈问题或请求协助', 'Report issues and request assistance'); ?></span>
                                    </span>
                                </a>
                            <?php endif; ?>
                            <?php if ($cfClientSupportGroupUrl !== ''): ?>
                                <a href="<?php echo htmlspecialchars($cfClientSupportGroupUrl, ENT_QUOTES); ?>" class="list-group-item list-group-item-action cf-client-nav-link" target="_blank" rel="noopener noreferrer">
                                    <i class="fas fa-users"></i>
                                    <span class="cf-nav-text">
                                        <span class="cf-nav-label"><?php echo $cfClientNavText('cfclient.sidebar.support_group', '交流群组', 'Community Group'); ?></span>
                                        <span class="cf-nav-desc"><?php echo $cfClientNavText('cfclient.sidebar.support_group_desc', '加入社区获取最新通知', 'Join community for updates'); ?></span>
                                    </span>
                                </a>
                            <?php endif; ?>
                            <?php if ($cfClientPortalUrl !== ''): ?>
                                <a href="<?php echo htmlspecialchars($cfClientPortalUrl, ENT_QUOTES); ?>" class="list-group-item list-group-item-action cf-client-nav-link">
                                    <i class="fas fa-arrow-left"></i>
                                    <span class="cf-nav-text">
                                        <span class="cf-nav-label"><?php echo $cfClientNavText('cfclient.sidebar.back_portal', '返回客户中心', 'Back to Client Area'); ?></span>
                                        <span class="cf-nav-desc"><?php echo $cfClientNavText('cfclient.sidebar.back_portal_desc', '返回 WHMCS 客户中心首页', 'Return to the WHMCS client portal home'); ?></span>
                                    </span>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php
            $quotaMaxCount = max(0, intval($quota->max_count ?? 0));
            $quotaUsedCount = max(0, intval($quota->used_count ?? 0));
            $quotaPercent = $quotaMaxCount > 0 ? min(100, ($quotaUsedCount / $quotaMaxCount) * 100) : 0;
            ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h6 class="mb-0">
                            <i class="fas fa-gauge text-primary"></i> <?php echo $cfClientNavText('cfclient.sidebar.quota_title', '当前配额', 'Quota'); ?>
                        </h6>
                        <span class="badge bg-light text-dark"><?php echo $quotaUsedCount; ?>/<?php echo $quotaMaxCount; ?></span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar" role="progressbar" style="width: <?php echo $quotaPercent; ?>%"></div>
                    </div>
                </div>
            </div>
        </aside>

        <section class="cf-client-content">
            <?php if ($cfClientCurrentView !== 'domains'): ?>
            <div class="cf-client-view-header p-3">
                <h5 class="mb-1">
                    <i class="<?php echo htmlspecialchars($cfClientCurrentViewMeta['icon'] ?? 'fas fa-window-maximize', ENT_QUOTES); ?> text-primary me-2"></i>
                    <?php echo $cfClientCurrentViewMeta['label'] ?? ''; ?>
                </h5>
                <div class="text-muted small"><?php echo $cfClientCurrentViewMeta['description'] ?? ''; ?></div>
            </div>
            <?php endif; ?>

            <div class="container-fluid px-0 py-1">
                <?php
                switch ($cfClientCurrentView) {
                    case 'gift':
                        include __DIR__ . '/client/partials/gift_center.tpl';
                        break;
                    case 'tools':
                        include __DIR__ . '/client/partials/feature_hub.tpl';
                        break;
                    case 'api':
                        if ($cfClientApiTemplateExists) {
                            include __DIR__ . '/api_management.tpl';
                        } else {
                            echo '<div class="alert alert-info">' . cfclient_lang('cfclient.api.disabled', 'API 功能暂未开启。', [], true) . '</div>';
                        }
                        break;
                    case 'whois':
                        include __DIR__ . '/client/partials/whois_center.tpl';
                        break;
                    case 'partner':
                        include __DIR__ . '/client/partials/partner_plan.tpl';
                        break;
                    case 'help':
                        include __DIR__ . '/client/partials/extras.tpl';
                        break;
                    case 'domains':
                    default:
                        include __DIR__ . '/client/partials/quota_invite.tpl';
                        include __DIR__ . '/client/partials/subdomains.tpl';
                        break;
                }
                ?>
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/client/partials/modals.tpl'; ?>
<?php include __DIR__ . '/client/partials/scripts.tpl'; ?>
</div>


</body>
</html>
<?php /* safeguard: ensure PHP context closes even if earlier block was left open */ ?>
