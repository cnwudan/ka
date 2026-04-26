<?php
$modalText = function (string $key, string $default, array $params = [], bool $escape = true) {
    return cfclient_lang($key, $default, $params, $escape);
};
$modalLanguage = strtolower((string) ($currentClientLanguage ?? 'english'));
$modalIsChinese = $modalLanguage === 'chinese';
$nsListLabelDefault = $modalIsChinese ? 'DNS服务器列表' : 'DNS Server List';
$nsAddButtonDefault = $modalIsChinese ? '[增加 DNS 服务器]' : '[Add DNS Server]';
$nsSaveButtonDefault = $modalIsChinese ? '保存设置' : 'Save Settings';
$nsForceShortDefault = $modalIsChinese ? '强制替换冲突记录' : 'Force replace conflicting records';
$nsForceTooltipDefault = $modalIsChinese
    ? '删除与 NS 冲突的同名记录，如 A/AAAA/CNAME/TXT/MX/SRV/CAA 等。'
    : 'Delete records with the same name that conflict with NS, such as A/AAAA/CNAME/TXT/MX/SRV/CAA.';

$dnsUnlockRequired = !empty($dnsUnlockRequired);
$dnsUnlockModalData = $dnsUnlock ?? [];
$dnsUnlockPurchaseEnabled = !empty($dnsUnlockPurchaseEnabled);
$dnsUnlockPurchasePrice = isset($dnsUnlockPurchasePrice) ? (float) $dnsUnlockPurchasePrice : 0.0;
$dnsUnlockPriceDisplay = number_format($dnsUnlockPurchasePrice, 2, '.', '');
$dnsUnlockShareAllowed = !empty($dnsUnlockShareAllowed);

$domainPermanentUpgradeEnabled = !empty($domainPermanentUpgradeEnabled);
$domainPermanentUpgradeState = is_array($domainPermanentUpgradeState ?? null) ? $domainPermanentUpgradeState : [];
$domainPermanentUpgradeAssistRequired = max(1, intval($domainPermanentUpgradeState['assist_required'] ?? ($domainPermanentUpgradeAssistRequired ?? 3)));
$domainPermanentUpgradeEligibleDomains = is_array($domainPermanentUpgradeState['eligible_domains'] ?? null) ? $domainPermanentUpgradeState['eligible_domains'] : [];
$domainPermanentUpgradeRequests = is_array($domainPermanentUpgradeState['requests'] ?? null) ? $domainPermanentUpgradeState['requests'] : [];
$domainPermanentUpgradePagination = $domainPermanentUpgradeState['pagination'] ?? ['page' => 1, 'totalPages' => 1, 'perPage' => 10, 'total' => 0];
$domainPermanentUpgradePage = max(1, intval($domainPermanentUpgradePagination['page'] ?? 1));
$domainPermanentUpgradeTotalPages = max(1, intval($domainPermanentUpgradePagination['totalPages'] ?? 1));
$domainPermanentUpgradeBaseParams = $_GET ?? [];
unset($domainPermanentUpgradeBaseParams['perm_upgrade_page']);
$domainPermanentUpgradeBaseQuery = http_build_query($domainPermanentUpgradeBaseParams);
$domainPermanentUpgradeLinkPrefix = $domainPermanentUpgradeBaseQuery !== '' ? ('?' . $domainPermanentUpgradeBaseQuery . '&perm_upgrade_page=') : '?perm_upgrade_page=';

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

$dnsRecordTypes = [
    'A' => $modalText('cfclient.modals.dns.type.a', 'A记录 (IPv4地址)'),
    'AAAA' => $modalText('cfclient.modals.dns.type.aaaa', 'AAAA记录 (IPv6地址)'),
    'CNAME' => $modalText('cfclient.modals.dns.type.cname', 'CNAME记录 (别名)'),
    'MX' => $modalText('cfclient.modals.dns.type.mx', 'MX记录 (邮件服务器)'),
    'TXT' => $modalText('cfclient.modals.dns.type.txt', 'TXT记录 (文本)'),
    'SRV' => $modalText('cfclient.modals.dns.type.srv', 'SRV记录 (服务)'),
];
if (!$disableNsManagement) {
    $dnsRecordTypes['NS'] = $modalText('cfclient.modals.dns.type.ns', 'NS记录 (DNS服务器/子域授权)');
}
$dnsRecordTypes['CAA'] = $modalText('cfclient.modals.dns.type.caa', 'CAA记录 (证书颁发机构授权)');

$ttlOptions = [
    '600' => $modalText('cfclient.modals.dns.ttl.600', '10分钟'),
    '1800' => $modalText('cfclient.modals.dns.ttl.1800', '30分钟'),
    '3600' => $modalText('cfclient.modals.dns.ttl.3600', '1小时'),
    '7200' => $modalText('cfclient.modals.dns.ttl.7200', '2小时'),
    '14400' => $modalText('cfclient.modals.dns.ttl.14400', '4小时'),
    '28800' => $modalText('cfclient.modals.dns.ttl.28800', '8小时'),
    '86400' => $modalText('cfclient.modals.dns.ttl.86400', '24小时'),
];

$dnsLineOptions = [
    'default' => $modalText('cfclient.modals.dns.line.default', '默认'),
    'telecom' => $modalText('cfclient.modals.dns.line.telecom', '电信'),
    'unicom' => $modalText('cfclient.modals.dns.line.unicom', '联通'),
    'mobile' => $modalText('cfclient.modals.dns.line.mobile', '移动'),
    'oversea' => $modalText('cfclient.modals.dns.line.oversea', '海外'),
    'edu' => $modalText('cfclient.modals.dns.line.edu', '教育网'),
];
?>
    <!-- DNS设置模态框 -->
    <div class="modal fade" id="dnsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus text-primary"></i> <?php echo $modalText('cfclient.modals.dns.title', '添加DNS解析记录'); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="dnsForm">
                    <div class="modal-body">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <input type="hidden" name="action" id="dns_action">
                        <input type="hidden" name="subdomain_id" id="dns_subdomain_id">
                        <input type="hidden" name="record_id" id="dns_record_id">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.domain', '域名'); ?></label>
                                    <input type="text" class="form-control" id="dns_subdomain_name" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.record_name', '记录名称'); ?></label>
                                    <div class="input-group">
                                        <input type="text" name="record_name" id="dns_record_name" class="form-control" placeholder="<?php echo $modalText('cfclient.modals.dns.placeholder.record_name', '@ 或 前缀:如www、mail'); ?>">
                                        <span class="input-group-text">.<span id="dns_record_suffix"></span></span>
                                    </div>
                                    <div class="form-text">
                                        <strong><?php echo $modalText('cfclient.modals.dns.hint.record_name_1', '@ 记录：表示域名本身（如 blog.example.com）'); ?></strong><br>
                                        <strong><?php echo $modalText('cfclient.modals.dns.hint.record_name_2', '域名前缀：填写前缀（如 www、mail、api）表示 www.blog.example.com'); ?></strong><br>
                                        <strong><?php echo $modalText('cfclient.modals.dns.hint.record_name_3', '可以同时存在 @ 记录和前缀域名记录，互不影响'); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.type', '记录类型'); ?></label>
                                    <select name="record_type" class="form-select" required>
                                        <?php foreach ($dnsRecordTypes as $value => $label): ?>
                                            <?php $nsLockedAttr = ($value === 'NS' && $dnsUnlockRequired) ? ' disabled data-requires-unlock="1"' : ''; ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"<?php echo $nsLockedAttr; ?>><?php echo $label; ?><?php echo ($value === 'NS' && $dnsUnlockRequired) ? ' (' . $modalText('cfclient.dns_unlock.title', 'DNS 解锁') . ')' : ''; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.type', 'MX记录需要设置优先级，SRV记录需要设置优先级、权重、端口和目标地址'); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3" id="record_content_field">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.content', '记录内容'); ?></label>
                                    <input type="text" name="record_content" class="form-control" required placeholder="<?php echo $modalText('cfclient.modals.dns.placeholder.content', '根据记录类型填写相应内容'); ?>">
                                    <div class="form-text">
                                        <?php echo $modalText('cfclient.modals.dns.hint.content_1', 'A记录: IP地址 (如: 192.168.1.1)'); ?><br>
                                        <?php echo $modalText('cfclient.modals.dns.hint.content_2', 'CNAME记录: 域名 (如: example.com)'); ?><br>
                                        <?php echo $modalText('cfclient.modals.dns.hint.content_3', 'MX记录: 邮件服务器域名 (如: mail.example.com)'); ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.line', '解析请求来源（Line）'); ?></label>
                                    <select name="line" class="form-select">
                                        <?php foreach ($dnsLineOptions as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"<?php echo $value === 'default' ? ' selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.line', '不同运营商/地域可选择对应的解析线路（若无特殊需求保持默认）。'); ?></div>
                                </div>
                                <div class="mb-3" id="caa_fields" style="display: none;">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.caa', 'CAA记录参数'); ?></label>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.flag', 'Flag'); ?></label>
                                            <select name="caa_flag" class="form-select">
                                                <option value="0">0</option>
                                                <option value="128">128</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.tag', 'Tag'); ?></label>
                                            <select name="caa_tag" class="form-select">
                                                <option value="issue">issue</option>
                                                <option value="issuewild">issuewild</option>
                                                <option value="iodef">iodef</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.value', 'Value'); ?></label>
                                            <input type="text" name="caa_value" class="form-control" placeholder="letsencrypt.org">
                                        </div>
                                    </div>
                                    <div class="form-text">
                                        <?php echo $modalText('cfclient.modals.dns.hint.caa.flag', 'Flag: 0=非关键, 128=关键'); ?><br>
                                        <?php echo $modalText('cfclient.modals.dns.hint.caa.tag', 'Tag: issue=允许颁发, issuewild=允许通配符, iodef=违规报告'); ?><br>
                                        <?php echo $modalText('cfclient.modals.dns.hint.caa.value', 'Value: CA域名或邮箱地址'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.ttl', 'TTL (分钟)'); ?></label>
                                    <select name="record_ttl" class="form-select">
                                        <?php foreach ($ttlOptions as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"<?php echo $value === '600' ? ' selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.ttl', 'TTL根据实际情况选择，一般无需修改。'); ?></div>
                                </div>
                                <div class="mb-3" id="priority_field" style="display: none;">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.priority', '优先级 (MX/SRV)'); ?></label>
                                    <input type="number" name="record_priority" class="form-control" placeholder="<?php echo $modalText('cfclient.modals.dns.label.priority', '优先级 (MX/SRV)', [], true); ?>" min="0" max="65535" value="10">
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.priority', 'MX记录优先级，数值越小优先级越高'); ?></div>
                                </div>
                                <div class="mb-3" id="srv_fields" style="display: none;">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.weight', '权重 (SRV)'); ?></label>
                                    <input type="number" name="record_weight" class="form-control" placeholder="<?php echo $modalText('cfclient.modals.dns.label.weight', '权重 (SRV)', [], true); ?>" min="0" max="65535" value="0">
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.weight', '范围 0-65535，数值越大权重越高'); ?></div>
                                    <label class="form-label mt-3"><?php echo $modalText('cfclient.modals.dns.label.port', '端口 (SRV)'); ?></label>
                                    <input type="number" name="record_port" class="form-control" placeholder="<?php echo $modalText('cfclient.modals.dns.label.port', '端口 (SRV)', [], true); ?>" min="1" max="65535" value="1">
                                    <label class="form-label mt-3"><?php echo $modalText('cfclient.modals.dns.label.target', '目标地址 (SRV)'); ?></label>
                                    <input type="text" name="record_target" class="form-control" placeholder="service.example.com">
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.target', '填写服务主机名，不带协议'); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong><?php echo $modalText('cfclient.modals.dns.alert.title', '提示：'); ?></strong>
                            <ul class="mb-0 mt-2">
                                <li><?php echo $modalText('cfclient.modals.dns.alert.1', '修改DNS记录可能需要几分钟时间生效'); ?></li>
                                <li><?php echo $modalText('cfclient.modals.dns.alert.2', '可以同时设置 @ 记录和三级域名记录，互不影响'); ?></li>
                                <li><strong><?php echo $modalText('cfclient.modals.dns.alert.3', '智能解析支持:域名us.ci与cn.mt 支持按线路（运营商/地域）精准解析'); ?></strong></li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $modalText('cfclient.modals.buttons.cancel', '取消'); ?></button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $modalText('cfclient.modals.buttons.save', '保存设置'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 注册模态框 -->
    <div class="modal fade" id="registerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle text-primary"></i> <?php echo $modalText('cfclient.modals.register.title', '注册新域名'); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="registerForm">
                    <div class="modal-body">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <div id="registerErrorAlert" class="alert alert-danger" style="display:none"></div>
                        <input type="hidden" name="action" value="register">
                        <div class="mb-3">
                            <label class="form-label"><?php echo $modalText('cfclient.modals.register.label.root', '选择根域名'); ?></label>
                            <select name="rootdomain" id="register_rootdomain" class="form-select" required>
                                <option value=""><?php echo $modalText('cfclient.modals.register.placeholder.root', '请选择根域名'); ?></option>
                                <?php if (is_array($roots) && !empty($roots)): ?>
                                    <?php foreach ($roots as $r): ?>
                                        <?php if (!empty($r)): ?>
                                            <?php $limitValue = intval($rootLimitMap[strtolower($r)] ?? 0); ?>
                                            <option value="<?php echo htmlspecialchars($r); ?>" data-limit="<?php echo $limitValue; ?>"><?php echo htmlspecialchars($r); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="form-text text-muted" id="register_limit_hint" style="display:none;"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo $modalText('cfclient.modals.register.label.prefix', '域名前缀'); ?></label>
                            <div class="input-group">
                                <input type="text" name="subdomain" class="form-control" required placeholder="<?php echo $modalText('cfclient.modals.register.placeholder.prefix', '输入前缀，如: myblog'); ?>" pattern="<?php echo $subPrefixPatternHtml; ?>" minlength="<?php echo $subPrefixMinLength; ?>" maxlength="<?php echo $subPrefixMaxLength; ?>">
                                <span class="input-group-text">.<span id="register_root_suffix"><?php echo $modalText('cfclient.modals.register.label.root', '选择根域名', [], true); ?></span></span>
                            </div>
                            <div class="form-text"><?php echo $modalText('cfclient.modals.register.hint.prefix', '只能包含字母、数字和连字符，长度%1$s-%2$s字符', [$subPrefixMinLength, $subPrefixMaxLength]); ?></div>
                        </div>
                        <div class="mb-3" id="rootdomain_invite_code_container" style="display:none;">
                            <label class="form-label">
                                <i class="fas fa-ticket-alt text-warning"></i> <?php echo $modalText('cfclient.modals.register.label.invite_code', '邀请码'); ?>
                                <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="rootdomain_invite_code" id="rootdomain_invite_code_input" class="form-control" placeholder="<?php echo $modalText('cfclient.modals.register.placeholder.invite_code', '请输入10位邀请码'); ?>" maxlength="10" pattern="[A-Za-z0-9]{10}">
                            <div class="form-text text-info">
                                <i class="fas fa-info-circle"></i> <?php echo $modalText('cfclient.modals.register.hint.invite_code', '该根域名需要邀请码才能注册，请向已注册该根域名的用户获取邀请码'); ?>
                            </div>
                        </div>
                        <div class="alert alert-info" id="registerImportantInfo">
                            <i class="fas fa-info-circle"></i>
                            <strong><?php echo $modalText('cfclient.modals.register.alert.title', '重要说明：'); ?></strong>
                            <ul class="mb-0 mt-2">
                                <li><strong><?php echo $modalText('cfclient.modals.register.alert.1', '注册成功后，您需要手动设置DNS解析'); ?></strong></li>
                                <li><?php echo $modalText('cfclient.modals.register.alert.2', '可以设置A记录、CNAME记录等多种类型'); ?></li>
                                <li><?php echo $modalText('cfclient.modals.register.alert.3', '注册的域名严禁用于违法违规行为'); ?></li>
                                <?php if (!empty($clientDeleteEnabled)): ?>
                                    <li><?php echo $modalText('cfclient.modals.register.alert.delete_enabled', '如需删除，可在“查看详情”中提交自助删除申请。'); ?></li>
                                <?php else: ?>
                                    <li><?php echo $modalText('cfclient.modals.register.alert.delete_disabled', '注册成功的域名不支持删除。如有问题，请联系客服获取帮助'); ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $modalText('cfclient.modals.buttons.cancel', '取消'); ?></button>
                        <?php if ($pauseFreeRegistration || $maintenanceMode): ?>
                            <button type="button" class="btn btn-secondary" disabled>
                                <i class="fas fa-pause"></i> <?php echo $modalText('cfclient.modals.buttons.pause', '暂停注册'); ?>
                            </button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check"></i> <?php echo $modalText('cfclient.modals.buttons.confirm', '确认注册'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- NS 委派管理模态框 -->
    <div class="modal fade" id="nsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-server text-primary"></i> <?php echo $modalText('cfclient.modals.ns.title', 'DNS服务器（域名委派）'); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="nsForm">
                    <div class="modal-body">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <input type="hidden" name="action" value="replace_ns_group">
                        <input type="hidden" name="subdomain_id" id="ns_subdomain_id">
                        <div class="mb-3">
                            <label class="form-label"><?php echo $modalText('cfclient.modals.ns.label.domain', '域名'); ?></label>
                            <input type="text" class="form-control" id="ns_subdomain_name" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo $modalText('cfclient.modals.ns.label.current', '当前 NS'); ?></label>
                            <div id="ns_current" class="small text-muted">(<?php echo $modalText('cfclient.modals.ns.label.current', '当前 NS', [], true); ?>)</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo $modalText('cfclient.modals.ns.label.lines', $nsListLabelDefault); ?></label>
                            <textarea name="ns_lines" id="ns_lines" class="form-control d-none" rows="6"></textarea>
                            <div id="ns_inputs_container" class="ns-inputs-container"></div>
                            <div class="d-flex justify-content-end mt-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="ns_add_input_btn">
                                    <i class="fas fa-plus"></i> <?php echo $modalText('cfclient.modals.ns.button.add_server', $nsAddButtonDefault); ?>
                                </button>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-0 mb-3">
                            <div class="form-check mb-0 ns-force-check">
                                <input class="form-check-input" type="checkbox" name="force_replace" id="force_replace" value="1">
                                <label class="form-check-label ns-force-label" for="force_replace"><?php echo $modalText('cfclient.modals.ns.label.force_short', $nsForceShortDefault); ?></label>
                            </div>
                            <button type="button"
                                    class="btn btn-link ns-force-help p-0"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="bottom"
                                    data-bs-container="body"
                                    data-bs-custom-class="ns-force-tooltip"
                                    data-bs-title="<?php echo htmlspecialchars($modalText('cfclient.modals.ns.label.force', $nsForceTooltipDefault), ENT_QUOTES); ?>"
                                    aria-label="<?php echo htmlspecialchars($modalText('cfclient.modals.ns.label.force', $nsForceTooltipDefault), ENT_QUOTES); ?>">
                                <i class="fas fa-exclamation-circle"></i>
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $modalText('cfclient.modals.buttons.cancel', '取消'); ?></button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $modalText('cfclient.modals.buttons.save_settings', $nsSaveButtonDefault); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($quotaRedeemEnabled): ?>
    <div class="modal fade" id="quotaRedeemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-gift text-success"></i> <?php echo $modalText('cfclient.modals.redeem.title', '兑换注册额度'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="redeemAlertPlaceholder"></div>
                    <div class="row g-4">
                        <div class="col-md-5">
                            <form id="quotaRedeemForm" data-cfmod-skip-csrf="1">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.redeem.label.code', '输入兑换码'); ?></label>
                                    <input type="text" class="form-control" id="redeemCodeInput" placeholder="<?php echo $modalText('cfclient.modals.redeem.placeholder', '请输入兑换码'); ?>" autocomplete="off">
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.redeem.help', '兑换成功后，将自动增加您的注册额度。'); ?></div>
                                </div>
                                <button type="submit" class="btn btn-success w-100" id="redeemSubmitButton">
                                    <i class="fas fa-check-circle"></i> <?php echo $modalText('cfclient.modals.redeem.button', '立即兑换'); ?>
                                </button>
                            </form>
                            <div class="mt-4">
                                <?php
                                    $redeemLang = isset($currentClientLanguage) ? strtolower((string) $currentClientLanguage) : 'english';
                                    $isRedeemChinese = $redeemLang === 'chinese';
                                    $faqTitle = $isRedeemChinese ? '常见问题' : 'FAQ';
                                ?>
                                <h6 class="text-muted"><i class="fas fa-question-circle me-1"></i> <?php echo htmlspecialchars($faqTitle); ?></h6>
                                <div class="small text-muted">
                                    <?php
                                        $faqQ1 = $isRedeemChinese ? '如何获取兑换码？' : 'How do I get a redeem code?';
                                        $faqA1 = $isRedeemChinese
                                            ? '兑换码通常来自官方活动、邀请排行榜奖励或管理员单独派发，请留意公告或联系支持。'
                                            : 'Redeem codes are issued via official campaigns, invite leaderboard rewards, or direct support grants—follow announcements or contact support.';
                                        $faqQ2 = $isRedeemChinese ? '兑换成功后额度会过期吗？' : 'Does the redeemed quota expire?';
                                        $faqA2 = $isRedeemChinese
                                            ? '不会过期，额度会一直保留在您的账户中，直到全部使用完毕。'
                                            : 'Redeemed quota never expires and stays in your account until it is fully consumed.';
                                        $faqQ3 = $isRedeemChinese ? '兑换失败怎么办？' : 'What if redemption fails?';
                                        $faqA3 = $isRedeemChinese
                                            ? '请检查兑换码是否输入正确或已使用，如仍无法兑换，请提交工单或联系在线客服协助处理。'
                                            : 'Verify whether the code is correct or already used. If the issue persists, please open a ticket or contact support for help.';
                                    ?>
                                    <p class="mb-2"><strong><?php echo htmlspecialchars($faqQ1); ?></strong><br><?php echo htmlspecialchars($faqA1); ?></p>
                                    <p class="mb-2"><strong><?php echo htmlspecialchars($faqQ2); ?></strong><br><?php echo htmlspecialchars($faqA2); ?></p>
                                    <p class="mb-0"><strong><?php echo htmlspecialchars($faqQ3); ?></strong><br><?php echo htmlspecialchars($faqA3); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <h6 class="mb-3"><i class="fas fa-history text-muted"></i> <?php echo $modalText('cfclient.modals.redeem.history.title', '兑换历史'); ?></h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0" id="redeemHistoryTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th><?php echo $modalText('cfclient.modals.redeem.history.code', '兑换码'); ?></th>
                                            <th><?php echo $modalText('cfclient.modals.redeem.history.amount', '增加额度'); ?></th>
                                            <th><?php echo $modalText('cfclient.modals.redeem.history.time', '兑换时间'); ?></th>
                                            <th><?php echo $modalText('cfclient.modals.redeem.history.status', '状态'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="redeemHistoryBody">
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3"><?php echo $modalText('cfclient.modals.redeem.history.placeholder', '暂无兑换记录'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <nav class="mt-3">
                                <ul class="pagination pagination-sm justify-content-end mb-0" id="redeemHistoryPagination"></ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>



<?php if (!empty($dnsUnlockFeatureEnabled)): ?>
<?php
$dnsUnlockCode = $dnsUnlockModalData['code'] ?? '';
$dnsUnlockUnlocked = !empty($dnsUnlockModalData['unlocked']);
$unlockInputDisabled = $dnsUnlockUnlocked;
$dnsUnlockLastUsedCode = strtoupper(trim((string) ($dnsUnlockModalData['last_used_code'] ?? '')));
$unlockCodeUpper = strtoupper(trim((string) $dnsUnlockCode));
$unlockUsedMessage = '';
if ($dnsUnlockUnlocked) {
    $displayCode = $dnsUnlockLastUsedCode !== '' ? $dnsUnlockLastUsedCode : $unlockCodeUpper;
    if ($displayCode !== '') {
        $unlockUsedMessage = $modalText('cfclient.dns_unlock.used_code', '已使用解锁码：%s', [$displayCode]);
    }
}
$unlockInputPrefillAttr = $unlockUsedMessage !== '' ? ' value="' . htmlspecialchars($unlockUsedMessage, ENT_QUOTES) . '"' : '';
$dnsUnlockLogs = $dnsUnlockModalData['logs'] ?? [];
$dnsUnlockPagination = $dnsUnlockModalData['pagination'] ?? ['page' => 1, 'totalPages' => 1, 'perPage' => 10, 'total' => 0];
$unlockPage = max(1, intval($dnsUnlockPagination['page'] ?? 1));
$unlockTotalPages = max(1, intval($dnsUnlockPagination['totalPages'] ?? 1));
$unlockBaseParams = $_GET ?? [];
unset($unlockBaseParams['unlock_page']);
$unlockBaseQuery = http_build_query($unlockBaseParams);
$unlockLinkPrefix = $unlockBaseQuery !== '' ? ('?' . $unlockBaseQuery . '&unlock_page=') : '?unlock_page=';
?>
<div class="modal fade" id="dnsUnlockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-unlock-alt me-2"></i> <?php echo $modalText('cfclient.dns_unlock.title', 'DNS 解锁'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert <?php echo $dnsUnlockUnlocked ? 'alert-success' : 'alert-warning'; ?>">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php if ($dnsUnlockUnlocked): ?>
                        <?php echo $modalText('cfclient.dns_unlock.unlocked', 'DNS 解锁已完成，可以随时设置 NS。'); ?>
                    <?php else: ?>
                        <?php echo $modalText('cfclient.dns_unlock.locked', '首次设置 NS 服务器前需要输入解锁码，分享给协作者时可查看记录。'); ?>
                        <br>
                        <small class="text-muted d-block mt-1"><i class="fas fa-exclamation-triangle me-1"></i><?php echo $modalText('cfclient.dns_unlock.warning', 'Reminder: Sharing unlock code binds you to their behavior.'); ?></small>
                    <?php endif; ?>
                </div>
                <?php if ($dnsUnlockShareAllowed): ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?php echo $modalText('cfclient.dns_unlock.code_label', '我的解锁码'); ?></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="dnsUnlockCodeText" value="<?php echo htmlspecialchars($dnsUnlockCode, ENT_QUOTES); ?>" readonly>
                        <button type="button" class="btn btn-outline-secondary" onclick="copyDnsUnlockCode()">
                            <i class="fas fa-copy"></i> <?php echo $modalText('cfclient.dns_unlock.copy', '复制'); ?>
                        </button>
                    </div>
                    <small class="text-muted d-block mt-2"><?php echo $modalText('cfclient.dns_unlock.single_use_note', '解锁码仅限一次使用，使用后会立即失效并自动生成新的解锁码。'); ?></small>
                </div>
                <?php endif; ?>
                <?php if (!$dnsUnlockUnlocked && $dnsUnlockPurchaseEnabled && $dnsUnlockPurchasePrice > 0): ?>
                <div class="alert alert-info d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div>
                        <h6 class="mb-1 text-info"><?php echo $modalText('cfclient.dns_unlock.purchase_title', '快速解锁 (余额支付)'); ?></h6>
                        <p class="mb-0 small text-muted"><?php echo $modalText('cfclient.dns_unlock.purchase_desc', '支付余额 %s 即可立即解锁，无需输入协作解锁码。', [$dnsUnlockPriceDisplay]); ?></p>
                    </div>
                    <form method="post" class="mb-0 d-flex gap-2 align-items-center">
                        <input type="hidden" name="action" value="purchase_dns_unlock">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-wallet me-1"></i> <?php echo $modalText('cfclient.dns_unlock.purchase_button', '使用余额解锁'); ?>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
                <?php if ($dnsUnlockShareAllowed): ?>
                <form method="post" class="mb-4">
                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                    <input type="hidden" name="action" value="dns_unlock">
                    <label class="form-label fw-semibold" for="dns_unlock_input"><?php echo $modalText('cfclient.dns_unlock.input_label', '输入解锁码'); ?></label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="unlock_code" id="dns_unlock_input" placeholder="<?php echo $modalText('cfclient.dns_unlock.input_placeholder', '例如：AB12CDEF34'); ?>" maxlength="16"<?php echo $unlockInputPrefillAttr; ?><?php echo $unlockInputDisabled ? ' disabled' : ' required'; ?>>
                        <button type="submit" class="btn btn-primary" <?php echo $unlockInputDisabled ? 'disabled' : ''; ?>>
                            <i class="fas fa-unlock"></i> <?php echo $modalText('cfclient.dns_unlock.submit', '立即解锁'); ?>
                        </button>
                    </div>
                </form>
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><?php echo $modalText('cfclient.dns_unlock.logs_title', '解锁码使用记录'); ?></h6>
                    <small class="text-muted"><?php echo $modalText('cfclient.dns_unlock.logs_hint', '最多展示最近 10 条记录，邮箱已脱敏'); ?></small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th><?php echo $modalText('cfclient.dns_unlock.logs_email', '使用者邮箱'); ?></th>
                                <th><?php echo $modalText('cfclient.dns_unlock.logs_time', '时间'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($dnsUnlockLogs)): ?>
                                <?php foreach ($dnsUnlockLogs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['email_masked'] ?? '-', ENT_QUOTES); ?></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($log['used_at'] ?? '-', ENT_QUOTES); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="2" class="text-center text-muted py-3"><?php echo $modalText('cfclient.dns_unlock.logs_empty', '暂无使用记录'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($unlockTotalPages > 1): ?>
                    <nav>
                        <ul class="pagination pagination-sm">
                            <?php $prevPage = max(1, $unlockPage - 1); ?>
                            <li class="page-item <?php echo $unlockPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($unlockLinkPrefix . $prevPage, ENT_QUOTES); ?>#dnsUnlockModal" aria-label="<?php echo $modalText('cfclient.dns_unlock.pagination.prev', '上一页'); ?>">&laquo;</a>
                            </li>
                            <?php for ($i = 1; $i <= $unlockTotalPages; $i++): ?>
                                <li class="page-item <?php echo $unlockPage == $i ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($unlockLinkPrefix . $i, ENT_QUOTES); ?>#dnsUnlockModal"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php $nextPage = min($unlockTotalPages, $unlockPage + 1); ?>
                            <li class="page-item <?php echo $unlockPage >= $unlockTotalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($unlockLinkPrefix . $nextPage, ENT_QUOTES); ?>#dnsUnlockModal" aria-label="<?php echo $modalText('cfclient.dns_unlock.pagination.next', '下一页'); ?>">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-wallet me-1"></i> <?php echo $modalText('cfclient.dns_unlock.share_disabled', '当前仅支持余额解锁，请使用付费解锁功能或联系管理员。'); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($inviteRegistrationEnabled)): ?>
<?php
$inviteRegistrationInviteEnabled = !empty($inviteRegistrationInviteEnabled);
$inviteRegData = $inviteRegistration ?? [];
$inviteRegCode = $inviteRegData['code'] ?? '';
$inviteRegUnlocked = !empty($inviteRegData['unlocked']);
$inviteRegQuotaExhausted = !empty($inviteRegistrationQuotaExhausted);
$inviteRegAgeInsufficient = !empty($inviteRegistrationAgeInsufficient) || !empty($inviteRegData['age_insufficient']);
$inviteRegRequiredMonths = max(0, intval($inviteRegData['required_months'] ?? ($inviteRegistrationInviterMinMonths ?? 0)));
$inviteRegCopyDisabled = $inviteRegQuotaExhausted || $inviteRegAgeInsufficient;
if ($inviteRegAgeInsufficient && $inviteRegRequiredMonths > 0) {
    $inviteRegCodeDisplay = $modalText('cfclient.invite_registration.age_insufficient', '账户注册时间不足 %s 个月，无法生成邀请码。', [$inviteRegRequiredMonths]);
} elseif ($inviteRegQuotaExhausted) {
    $inviteRegCodeDisplay = $modalText('cfclient.invite_registration.quota_exhausted', '您的邀请名额已用完，暂无法生成新的邀请码。');
} else {
    $inviteRegCodeDisplay = $inviteRegCode;
}
$inviteRegLogs = $inviteRegData['logs'] ?? [];
$inviteRegPagination = $inviteRegData['pagination'] ?? ['page' => 1, 'totalPages' => 1, 'perPage' => 10, 'total' => 0];
$inviteRegPage = max(1, intval($inviteRegPagination['page'] ?? 1));
$inviteRegTotalPages = max(1, intval($inviteRegPagination['totalPages'] ?? 1));
$inviteRegBaseParams = $_GET ?? [];
unset($inviteRegBaseParams['invite_reg_page']);
$inviteRegBaseQuery = http_build_query($inviteRegBaseParams);
$inviteRegLinkPrefix = $inviteRegBaseQuery !== '' ? ('?' . $inviteRegBaseQuery . '&invite_reg_page=') : '?invite_reg_page=';
$inviteRegMaxPerUser = intval($inviteRegistrationMaxPerUser ?? 0);
?>
<?php if ($inviteRegistrationInviteEnabled): ?>
<div class="modal fade" id="inviteRegistrationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i> <?php echo $modalText('cfclient.invite_registration.title', '邀请注册'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert <?php echo $inviteRegUnlocked ? 'alert-warning' : 'alert-info'; ?>">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php if ($inviteRegUnlocked): ?>
                        <?php echo $modalText('cfclient.invite_registration.warning', '重要提醒：您可以分享给好友注册码，但请提醒对方遵守域名使用规则。一旦对方违规使用，您的账户也会同步被封禁。'); ?>
                    <?php else: ?>
                        <?php echo $modalText('cfclient.invite_registration.locked', '新用户需要输入邀请码才能使用本系统，请向已有用户获取邀请码。'); ?>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?php echo $modalText('cfclient.invite_registration.my_code', '我的邀请码'); ?></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="inviteRegCodeText" value="<?php echo htmlspecialchars($inviteRegCodeDisplay, ENT_QUOTES); ?>" readonly<?php echo $inviteRegCopyDisabled ? ' disabled' : ''; ?>>
                        <?php if ($inviteRegCopyDisabled): ?>
                            <span class="input-group-text text-warning bg-light">
                                <i class="fas fa-ban"></i>
                            </span>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline-secondary" onclick="copyInviteRegCode()">
                                <i class="fas fa-copy"></i> <?php echo $modalText('cfclient.invite_registration.copy', '复制'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted d-block mt-2">
                        <?php if ($inviteRegAgeInsufficient && $inviteRegRequiredMonths > 0): ?>
                            <?php echo $modalText('cfclient.invite_registration.age_insufficient_hint', '当前账号未达到后台设置的最低月龄（%s 个月），暂时不能发放邀请码。', [$inviteRegRequiredMonths]); ?>
                        <?php else: ?>
                            <?php echo $modalText('cfclient.invite_registration.single_use_note', '邀请码仅限一次使用，被使用后会自动生成新的邀请码。'); ?>
                        <?php endif; ?>
                        <?php if ($inviteRegMaxPerUser > 0): ?>
                            <?php echo $modalText('cfclient.invite_registration.limit_note', '每个用户最多可邀请 %s 人。', [$inviteRegMaxPerUser]); ?>
                        <?php endif; ?>
                    </small>
                </div>
                <hr>
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><?php echo $modalText('cfclient.invite_registration.logs_title', '我的邀请记录'); ?></h6>
                    <small class="text-muted"><?php echo $modalText('cfclient.invite_registration.logs_hint', '展示最近 10 条邀请记录。'); ?></small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th><?php echo $modalText('cfclient.invite_registration.logs_email', '被邀请者邮箱'); ?></th>
                                <th><?php echo $modalText('cfclient.invite_registration.logs_code', '使用的邀请码'); ?></th>
                                <th><?php echo $modalText('cfclient.invite_registration.logs_time', '时间'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($inviteRegLogs)): ?>
                                <?php foreach ($inviteRegLogs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['email_masked'] ?? '-', ENT_QUOTES); ?></td>
                                        <td><code><?php echo htmlspecialchars($log['invite_code'] ?? '-', ENT_QUOTES); ?></code></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($log['created_at'] ?? '-', ENT_QUOTES); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-3"><?php echo $modalText('cfclient.invite_registration.logs_empty', '暂无邀请记录'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($inviteRegTotalPages > 1): ?>
                    <nav>
                        <ul class="pagination pagination-sm">
                            <?php $prevPage = max(1, $inviteRegPage - 1); ?>
                            <li class="page-item <?php echo $inviteRegPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($inviteRegLinkPrefix . $prevPage, ENT_QUOTES); ?>#inviteRegistrationModal" aria-label="<?php echo $modalText('cfclient.invite_registration.pagination.prev', '上一页'); ?>">&laquo;</a>
                            </li>
                            <?php for ($i = 1; $i <= $inviteRegTotalPages; $i++): ?>
                                <li class="page-item <?php echo $inviteRegPage == $i ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($inviteRegLinkPrefix . $i, ENT_QUOTES); ?>#inviteRegistrationModal"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php $nextPage = min($inviteRegTotalPages, $inviteRegPage + 1); ?>
                            <li class="page-item <?php echo $inviteRegPage >= $inviteRegTotalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($inviteRegLinkPrefix . $nextPage, ENT_QUOTES); ?>#inviteRegistrationModal" aria-label="<?php echo $modalText('cfclient.invite_registration.pagination.next', '下一页'); ?>">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<?php if ($domainPermanentUpgradeEnabled): ?>
<div class="modal fade" id="domainPermanentUpgradeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-infinity text-danger me-2"></i> <?php echo $modalText('cfclient.domain_permanent_upgrade.title', '域名永久升级中心'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php echo $modalText('cfclient.domain_permanent_upgrade.notice', '选择您的域名并创建助力任务，达到 %s 次好友助力后将自动升级为永久有效。', [$domainPermanentUpgradeAssistRequired]); ?>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-lg-6">
                        <div class="card border-0 bg-light h-100">
                            <div class="card-body">
                                <h6 class="card-title mb-3"><i class="fas fa-rocket me-2 text-danger"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.create.title', '发起永久升级任务'); ?></h6>
                                <?php if (!empty($domainPermanentUpgradeEligibleDomains)): ?>
                                    <form method="post" class="d-flex flex-column gap-2">
                                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                                        <input type="hidden" name="action" value="create_domain_permanent_upgrade_request">
                                        <label class="small text-muted mb-0" for="permUpgradeSubdomainSelect"><?php echo $modalText('cfclient.domain_permanent_upgrade.create.domain_label', '选择域名'); ?></label>
                                        <select class="form-select" id="permUpgradeSubdomainSelect" name="perm_upgrade_subdomain_id" required>
                                            <?php foreach ($domainPermanentUpgradeEligibleDomains as $domainOption): ?>
                                                <?php
                                                $optionId = intval($domainOption['id'] ?? 0);
                                                $optionDomain = trim((string) ($domainOption['domain'] ?? ''));
                                                ?>
                                                <?php if ($optionId > 0 && $optionDomain !== ''): ?>
                                                    <option value="<?php echo $optionId; ?>"><?php echo htmlspecialchars($optionDomain, ENT_QUOTES); ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-danger">
                                            <i class="fas fa-bolt me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.create.button', '创建助力任务'); ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0 small">
                                        <i class="fas fa-exclamation-triangle me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.create.empty', '暂无可升级域名（仅未永久有效且状态正常的域名可参与）。'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card border-0 bg-light h-100">
                            <div class="card-body">
                                <h6 class="card-title mb-3"><i class="fas fa-handshake me-2 text-success"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.assist.title', '输入好友助力码'); ?></h6>
                                <form method="post" class="d-flex flex-column gap-2">
                                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                                    <input type="hidden" name="action" value="assist_domain_permanent_upgrade">
                                    <label class="small text-muted mb-0" for="permUpgradeAssistCodeInput"><?php echo $modalText('cfclient.domain_permanent_upgrade.assist.label', '助力码'); ?></label>
                                    <input type="text" class="form-control" id="permUpgradeAssistCodeInput" name="perm_upgrade_assist_code" maxlength="20" placeholder="<?php echo $modalText('cfclient.domain_permanent_upgrade.assist.placeholder', '请输入好友分享的助力码'); ?>" required>
                                    <button type="submit" class="btn btn-outline-success">
                                        <i class="fas fa-heart me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.assist.button', '立即助力'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-history me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.history.title', '我的升级任务'); ?></h6>
                    <small class="text-muted"><?php echo $modalText('cfclient.domain_permanent_upgrade.history.hint', '最多展示最近 10 条任务，可复制助力码分享给好友。'); ?></small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.domain', '域名'); ?></th>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.code', '助力码'); ?></th>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.progress', '进度'); ?></th>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.helpers', '最近助力'); ?></th>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.status', '状态'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($domainPermanentUpgradeRequests)): ?>
                                <?php foreach ($domainPermanentUpgradeRequests as $requestItem): ?>
                                    <?php
                                    $requestStatus = strtolower((string) ($requestItem['status'] ?? 'pending'));
                                    $statusClass = 'secondary';
                                    $statusText = $modalText('cfclient.domain_permanent_upgrade.status.pending', '进行中');
                                    if ($requestStatus === 'upgraded') {
                                        $statusClass = 'success';
                                        $statusText = $modalText('cfclient.domain_permanent_upgrade.status.upgraded', '已永久');
                                    } elseif ($requestStatus !== 'pending') {
                                        $statusClass = 'dark';
                                        $statusText = strtoupper($requestStatus);
                                    }
                                    $assistCount = max(0, intval($requestItem['assist_count'] ?? 0));
                                    $targetAssists = max(1, intval($requestItem['target_assists'] ?? $domainPermanentUpgradeAssistRequired));
                                    $progressPercent = min(100, (int) round(($assistCount / $targetAssists) * 100));
                                    $helpersPreview = is_array($requestItem['helpers_preview'] ?? null) ? $requestItem['helpers_preview'] : [];
                                    $assistCode = strtoupper(trim((string) ($requestItem['assist_code'] ?? '')));
                                    $canCopyCode = !empty($requestItem['can_copy']) && $assistCode !== '';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars((string) ($requestItem['domain'] ?? '-'), ENT_QUOTES); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars((string) ($requestItem['created_at'] ?? '-'), ENT_QUOTES); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($assistCode !== ''): ?>
                                                <div class="d-flex align-items-center gap-1">
                                                    <code><?php echo htmlspecialchars($assistCode, ENT_QUOTES); ?></code>
                                                    <?php if ($canCopyCode): ?>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyDomainPermanentAssistCode('<?php echo htmlspecialchars($assistCode, ENT_QUOTES); ?>')">
                                                            <i class="fas fa-copy"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="min-width: 180px;">
                                            <div class="small mb-1"><?php echo $assistCount; ?>/<?php echo $targetAssists; ?></div>
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo $progressPercent; ?>%;"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($helpersPreview)): ?>
                                                <?php echo htmlspecialchars(implode(', ', $helpersPreview), ENT_QUOTES); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusText, ENT_QUOTES); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3"><?php echo $modalText('cfclient.domain_permanent_upgrade.history.empty', '暂无升级任务记录'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($domainPermanentUpgradeTotalPages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination pagination-sm mb-0">
                            <?php $permPrevPage = max(1, $domainPermanentUpgradePage - 1); ?>
                            <li class="page-item <?php echo $domainPermanentUpgradePage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeLinkPrefix . $permPrevPage, ENT_QUOTES); ?>#domainPermanentUpgradeModal">&laquo;</a>
                            </li>
                            <?php for ($permPage = 1; $permPage <= $domainPermanentUpgradeTotalPages; $permPage++): ?>
                                <li class="page-item <?php echo $permPage === $domainPermanentUpgradePage ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeLinkPrefix . $permPage, ENT_QUOTES); ?>#domainPermanentUpgradeModal"><?php echo $permPage; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php $permNextPage = min($domainPermanentUpgradeTotalPages, $domainPermanentUpgradePage + 1); ?>
                            <li class="page-item <?php echo $domainPermanentUpgradePage >= $domainPermanentUpgradeTotalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeLinkPrefix . $permNextPage, ENT_QUOTES); ?>#domainPermanentUpgradeModal">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$inviteRegUnlocked): ?>
<?php
$inviteGateMode = (string) ($inviteRegistrationGateMode ?? 'invite_only');
$inviteGateInviteEnabled = !empty($inviteRegistrationInviteEnabled);
$inviteGateGithubEnabled = !empty($inviteRegistrationGithubEnabled);
$inviteGateGithubConfigured = !empty($inviteRegistrationGithubConfigured);
$inviteGateGithubAuthUrl = trim((string) ($inviteRegistrationGithubAuthUrl ?? ''));
$inviteGateGithubMinMonths = max(0, intval($inviteRegistrationGithubMinMonths ?? 0));
$inviteGateGithubMinRepos = max(0, intval($inviteRegistrationGithubMinRepos ?? 0));
$inviteGateTelegramEnabled = !empty($inviteRegistrationTelegramEnabled);
$inviteGateTelegramConfigured = !empty($inviteRegistrationTelegramConfigured);
$inviteGateTelegramBotUsername = trim((string) ($inviteRegistrationTelegramBotUsername ?? ''));
$inviteGateTelegramAuthMaxAge = max(60, intval($inviteRegistrationTelegramAuthMaxAge ?? 86400));
$inviteGateFlash = is_array($inviteRegistrationGateFlash ?? null) ? $inviteRegistrationGateFlash : null;
?>
<style>
#inviteRegistrationRequiredModal .invite-reg-required-inner {
    max-width: 560px;
    margin: 0 auto;
}
#inviteRegistrationRequiredModal .invite-reg-required-body {
    padding: 14px 16px;
}
#inviteRegistrationRequiredModal .invite-reg-github-btn {
    background: #24292e;
    border: 1px solid #24292e;
    color: #FFFFFF;
    height: 50px;
    border-radius: 6px;
}
#inviteRegistrationRequiredModal .invite-reg-github-btn:hover,
#inviteRegistrationRequiredModal .invite-reg-github-btn:focus,
#inviteRegistrationRequiredModal .invite-reg-github-btn:active {
    background: #1f2328;
    color: #FFFFFF;
    border-color: #1f2328;
}
#inviteRegistrationRequiredModal .github-auth-button .github-logo {
    height: 2.025em;
    width: 2.025em;
    margin-right: 12px;
    flex-shrink: 0;
    filter: brightness(0) invert(1);
}
#inviteRegistrationRequiredModal .invite-reg-github-hints {
    margin-top: 4px;
    margin-bottom: 12px;
}
#inviteRegistrationRequiredModal .invite-reg-github-hint {
    font-size: 13px;
    line-height: 1.4;
    color: #555555;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}
#inviteRegistrationRequiredModal .invite-reg-github-hint i {
    font-size: 11px;
    margin-right: 4px;
    color: #F59E0B;
}
#inviteRegistrationRequiredModal .invite-reg-github-hint + .invite-reg-github-hint {
    margin-top: 2px;
}
#inviteRegistrationRequiredModal .invite-reg-or-divider {
    margin: 12px 0;
    display: flex;
    align-items: center;
    color: #9CA3AF;
    font-size: 0.875rem;
}
#inviteRegistrationRequiredModal .invite-reg-or-divider--primary {
    margin: 10px 0 8px;
}
#inviteRegistrationRequiredModal .invite-reg-or-divider::before,
#inviteRegistrationRequiredModal .invite-reg-or-divider::after {
    content: '';
    flex: 1;
    border-bottom: 1px solid #E5E7EB;
}
#inviteRegistrationRequiredModal .invite-reg-or-divider > span {
    padding: 0 12px;
}
</style>
<div class="modal fade" id="inviteRegistrationRequiredModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:560px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-lock me-2"></i> <?php echo $modalText('cfclient.invite_registration.required_title', '准入验证'); ?></h5>
            </div>
            <div class="modal-body invite-reg-required-body">
                <div class="invite-reg-required-inner">
                <?php if ($inviteGateFlash && !empty($inviteGateFlash['message'])): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($inviteGateFlash['type'] ?? 'danger', ENT_QUOTES); ?>">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        <?php echo htmlspecialchars((string) ($inviteGateFlash['message'] ?? ''), ENT_QUOTES); ?>
                    </div>
                <?php endif; ?>

                <div class="border rounded bg-light px-3 py-2 mb-3 small text-muted">
                    <i class="fas fa-shield-alt text-warning me-1"></i>
                    <?php echo $modalText('cfclient.invite_registration.required_notice', '首次使用前请先完成准入验证。'); ?>
                </div>

                <?php if ($inviteGateInviteEnabled): ?>
                <form method="post" id="inviteRegRequiredForm" class="mb-3">
                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                    <input type="hidden" name="action" value="invite_registration_unlock">
                    <div class="border rounded bg-white p-3">
                        <label class="form-label fw-semibold small mb-2" for="invite_reg_code_input"><?php echo $modalText('cfclient.invite_registration.input_label', '输入邀请码'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-ticket-alt text-muted"></i></span>
                            <input type="text" class="form-control text-uppercase" name="invite_reg_code" id="invite_reg_code_input" placeholder="<?php echo $modalText('cfclient.invite_registration.input_placeholder', '例如：ABCD1234EFGH'); ?>" maxlength="20" <?php echo $inviteGateInviteEnabled ? 'required' : ''; ?> autofocus>
                            <button type="submit" class="btn btn-primary px-3">
                                <i class="fas fa-check"></i> <?php echo $modalText('cfclient.invite_registration.submit', '验证邀请码'); ?>
                            </button>
                        </div>
                        <div class="form-text mb-0 mt-2"><?php echo $modalText('cfclient.invite_registration.input_hint', '邀请码不区分大小写，请仔细核对后提交。'); ?></div>
                    </div>
                </form>
                <?php endif; ?>

                <?php if ($inviteGateInviteEnabled && ($inviteGateGithubEnabled || $inviteGateTelegramEnabled)): ?>
                    <div class="invite-reg-or-divider invite-reg-or-divider--primary"><span><?php echo $modalText('cfclient.invite_registration.or_other_method', '或使用其他方式'); ?></span></div>
                <?php endif; ?>

                <?php if ($inviteGateGithubEnabled): ?>
                    <?php if ($inviteGateGithubConfigured && $inviteGateGithubAuthUrl !== ''): ?>
                        <a href="<?php echo htmlspecialchars($inviteGateGithubAuthUrl, ENT_QUOTES); ?>" class="btn invite-reg-github-btn github-auth-button w-100 d-flex align-items-center justify-content-center px-3 mb-1">
                            <svg class="github-logo" aria-hidden="true" height="20" viewBox="0 0 16 16" width="20" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8a8 8 0 0 0 5.47 7.59c.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.5-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8 8 0 0 0 16 8c0-4.42-3.58-8-8-8Z"></path></svg>
                            <span><?php echo $modalText('cfclient.invite_registration.github.button_no_invite', '点击使用 GitHub 快捷认证（无需邀请码）。'); ?></span>
                        </a>
                        <?php if ($inviteGateGithubMinMonths > 0 || $inviteGateGithubMinRepos > 0): ?>
                            <div class="invite-reg-github-hints">
                                <?php if ($inviteGateGithubMinMonths > 0): ?>
                                    <div class="invite-reg-github-hint"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><?php echo $modalText('cfclient.invite_registration.github.min_months', 'GitHub 账号需至少注册 %s 个月。', [$inviteGateGithubMinMonths]); ?></div>
                                <?php endif; ?>
                                <?php if ($inviteGateGithubMinRepos > 0): ?>
                                    <div class="invite-reg-github-hint"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><?php echo $modalText('cfclient.invite_registration.github.min_repos', 'GitHub 账号公开仓库数需至少 %s 个。', [$inviteGateGithubMinRepos]); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-secondary py-2 px-3 mb-2 small">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo $modalText('cfclient.invite_registration.github.not_configured', '管理员尚未配置 GitHub OAuth，请联系管理员。'); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($inviteGateGithubEnabled && $inviteGateTelegramEnabled): ?>
                    <div class="invite-reg-or-divider"><span><?php echo $modalText('cfclient.invite_registration.or', '或'); ?></span></div>
                <?php endif; ?>

                <?php if ($inviteGateTelegramEnabled): ?>
                    <?php if ($inviteGateTelegramConfigured && $inviteGateTelegramBotUsername !== ''): ?>
                        <div class="small text-muted text-center mb-2" id="inviteRegTelegramAuthStatus">
                            <?php echo $modalText('cfclient.invite_registration.telegram.auth_hint', '请点击 Telegram 授权按钮并确认授权，系统将自动完成准入验证。'); ?>
                        </div>
                        <div class="telegram-login-widget-wrap d-flex justify-content-center mb-2">
                            <script async src="https://telegram.org/js/telegram-widget.js?22"
                                data-telegram-login="<?php echo htmlspecialchars($inviteGateTelegramBotUsername, ENT_QUOTES); ?>"
                                data-size="large"
                                data-userpic="false"
                                data-request-access="write"
                                data-onauth="cfInviteRegistrationTelegramOnAuth(user)">
                            </script>
                        </div>
                        <form method="post" class="mb-3" id="inviteRegTelegramForm">
                            <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                            <input type="hidden" name="action" value="invite_registration_telegram_unlock">
                            <input type="hidden" name="telegram_auth_id" id="inviteRegTelegramAuthId" value="">
                            <input type="hidden" name="telegram_auth_username" id="inviteRegTelegramAuthUsername" value="">
                            <input type="hidden" name="telegram_auth_first_name" id="inviteRegTelegramAuthFirstName" value="">
                            <input type="hidden" name="telegram_auth_last_name" id="inviteRegTelegramAuthLastName" value="">
                            <input type="hidden" name="telegram_auth_photo_url" id="inviteRegTelegramAuthPhotoUrl" value="">
                            <input type="hidden" name="telegram_auth_date" id="inviteRegTelegramAuthDate" value="">
                            <input type="hidden" name="telegram_auth_hash" id="inviteRegTelegramAuthHash" value="">
                            <button type="submit" class="btn btn-primary w-100" id="inviteRegTelegramSubmitButton" disabled>
                                <i class="fab fa-telegram-plane me-1"></i><?php echo $modalText('cfclient.invite_registration.telegram.submit', '我已授权 Telegram，继续验证'); ?>
                            </button>
                            <div class="form-text text-muted mt-2"><?php echo $modalText('cfclient.invite_registration.telegram.ttl_hint', '授权数据最长有效 %s 秒，超时请重新授权。', [$inviteGateTelegramAuthMaxAge]); ?></div>

                        </form>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-3">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo $modalText('cfclient.invite_registration.telegram.not_configured', '管理员尚未完成 Telegram 准入配置，请联系管理员。'); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <a href="clientarea.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-home"></i> <?php echo $modalText('cfclient.invite_registration.back_to_portal', '返回客户中心'); ?>
                </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var inviteRegTelegramAuthSuccessText = <?php echo json_encode($modalText('cfclient.invite_registration.telegram.auth_success', 'Telegram 授权成功，请点击下方按钮完成准入验证。', [], false), CFMOD_SAFE_JSON_FLAGS); ?>;
var inviteRegTelegramAuthRequiredText = <?php echo json_encode($modalText('cfclient.invite_registration.telegram.auth_required', '请先完成 Telegram 授权后再提交验证。', [], false), CFMOD_SAFE_JSON_FLAGS); ?>;

window.cfInviteRegistrationTelegramOnAuth = function(user) {
    if (!user || typeof user !== 'object') {
        return;
    }
    var map = {
        inviteRegTelegramAuthId: user.id || '',
        inviteRegTelegramAuthUsername: user.username || '',
        inviteRegTelegramAuthFirstName: user.first_name || '',
        inviteRegTelegramAuthLastName: user.last_name || '',
        inviteRegTelegramAuthPhotoUrl: user.photo_url || '',
        inviteRegTelegramAuthDate: user.auth_date || '',
        inviteRegTelegramAuthHash: user.hash || ''
    };
    Object.keys(map).forEach(function(id) {
        var input = document.getElementById(id);
        if (input) {
            input.value = map[id];
        }
    });
    var submitBtn = document.getElementById('inviteRegTelegramSubmitButton');
    if (submitBtn) {
        submitBtn.disabled = !(map.inviteRegTelegramAuthId && map.inviteRegTelegramAuthHash && map.inviteRegTelegramAuthDate);
    }
    var status = document.getElementById('inviteRegTelegramAuthStatus');
    if (status) {
        status.textContent = inviteRegTelegramAuthSuccessText;
        status.classList.remove('text-muted');
        status.classList.add('text-success');
    }
};

document.addEventListener('DOMContentLoaded', function() {
    var inviteRegRequiredModal = document.getElementById('inviteRegistrationRequiredModal');
    if (inviteRegRequiredModal) {
        var bsModal = new bootstrap.Modal(inviteRegRequiredModal);
        bsModal.show();
    }

    var telegramForm = document.getElementById('inviteRegTelegramForm');
    if (telegramForm) {
        telegramForm.addEventListener('submit', function(event) {
            var authId = document.getElementById('inviteRegTelegramAuthId');
            var authHash = document.getElementById('inviteRegTelegramAuthHash');
            var authDate = document.getElementById('inviteRegTelegramAuthDate');
            if (!authId || !authHash || !authDate || !authId.value || !authHash.value || !authDate.value) {
                event.preventDefault();
                var status = document.getElementById('inviteRegTelegramAuthStatus');
                if (status) {
                    status.textContent = inviteRegTelegramAuthRequiredText;
                    status.classList.remove('text-muted');
                    status.classList.add('text-danger');
                }
            }
        });
    }
});
</script>
<?php endif; ?>

<?php endif; ?>

<?php if ($expiryTelegramReminderFeatureEnabled): ?>
<div class="modal fade" id="expiryTelegramReminderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fab fa-telegram-plane text-info me-1"></i> <?php echo $modalText('cfclient.expiry_telegram.modal.title', 'Telegram 到期提醒'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="expiryTelegramReminderForm">
                <div class="modal-body">
                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                    <input type="hidden" name="action" value="update_expiry_telegram_reminder">
                    <input type="hidden" name="telegram_auth_id" id="expiryTelegramAuthId" value="">
                    <input type="hidden" name="telegram_auth_username" id="expiryTelegramAuthUsername" value="">
                    <input type="hidden" name="telegram_auth_first_name" id="expiryTelegramAuthFirstName" value="">
                    <input type="hidden" name="telegram_auth_last_name" id="expiryTelegramAuthLastName" value="">
                    <input type="hidden" name="telegram_auth_photo_url" id="expiryTelegramAuthPhotoUrl" value="">
                    <input type="hidden" name="telegram_auth_date" id="expiryTelegramAuthDate" value="">
                    <input type="hidden" name="telegram_auth_hash" id="expiryTelegramAuthHash" value="">

                    <div class="alert alert-light border small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        <?php echo $modalText('cfclient.expiry_telegram.modal.days', '提醒频率：系统会在到期前 %s 各发送一次telegram消息提醒。', [$modalIsChinese ? $expiryTelegramReminderDaysZh : $expiryTelegramReminderDaysEn]); ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted" for="expiryTelegramReminderAccount"><?php echo $modalText('cfclient.expiry_telegram.modal.account', '当前绑定账号'); ?></label>
                        <input type="text" class="form-control" id="expiryTelegramReminderAccount" value="<?php echo htmlspecialchars($expiryTelegramReminderDisplayName !== '' ? $expiryTelegramReminderDisplayName : $modalText('cfclient.expiry_telegram.modal.no_account', '尚未绑定', [], false), ENT_QUOTES); ?>" readonly>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="expiry_telegram_reminder_enabled" name="expiry_telegram_reminder_enabled" value="1" <?php echo $expiryTelegramReminderSubscribed ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="expiry_telegram_reminder_enabled"><?php echo $modalText('cfclient.expiry_telegram.modal.enable', '开启 Telegram 到期提醒'); ?></label>
                    </div>

                    <div class="small text-muted mb-2" id="expiryTelegramReminderAuthStatus">
                        <?php if ($expiryTelegramReminderTelegramBound): ?>
                            <?php echo $modalText('cfclient.expiry_telegram.modal.bound_hint', '已绑定 Telegram：%s，可直接保存或重新授权。', [$expiryTelegramReminderDisplayName !== '' ? $expiryTelegramReminderDisplayName : '-']); ?>
                        <?php else: ?>
                            <?php echo $modalText('cfclient.expiry_telegram.modal.auth_hint', '请先完成 Telegram 授权后再开启提醒。'); ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($expiryTelegramReminderConfigured && $expiryTelegramReminderBotUsername !== ''): ?>
                        <div class="telegram-login-widget-wrap d-flex justify-content-center mb-2">
                            <script async src="https://telegram.org/js/telegram-widget.js?22"
                                data-telegram-login="<?php echo htmlspecialchars($expiryTelegramReminderBotUsername, ENT_QUOTES); ?>"
                                data-size="large"
                                data-userpic="false"
                                data-request-access="write"
                                data-onauth="cfExpiryTelegramReminderOnAuth(user)">
                            </script>
                        </div>

                    <?php else: ?>
                        <div class="alert alert-warning small mb-0">
                            <?php echo $modalText('cfclient.expiry_telegram.modal.not_configured', '管理员尚未完成 Telegram 提醒配置，暂无法授权。'); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $modalText('cfclient.modals.buttons.cancel', '取消'); ?></button>
                    <button type="submit" class="btn btn-info text-white" id="expiryTelegramReminderSubmitButton">
                        <i class="fas fa-save me-1"></i><?php echo $modalText('cfclient.expiry_telegram.modal.submit', '保存提醒设置'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var expiryTelegramAuthRequiredText = <?php echo json_encode($modalText('cfclient.expiry_telegram.modal.auth_required', '开启提醒前请先完成 Telegram 授权。', [], false), CFMOD_SAFE_JSON_FLAGS); ?>;
    var expiryTelegramBoundTextTemplate = <?php echo json_encode($modalText('cfclient.expiry_telegram.modal.bound_hint', '已绑定 Telegram：%s，可直接保存或重新授权。', ['%s'], false), CFMOD_SAFE_JSON_FLAGS); ?>;
    var expiryTelegramHasBinding = <?php echo $expiryTelegramReminderTelegramBound ? 'true' : 'false'; ?>;

    function setAuthStatus(text, className) {
        var status = document.getElementById('expiryTelegramReminderAuthStatus');
        if (!status) {
            return;
        }
        status.textContent = text;
        status.classList.remove('text-muted', 'text-success', 'text-danger');
        status.classList.add(className || 'text-muted');
    }

    window.cfExpiryTelegramReminderOnAuth = function (user) {
        if (!user || typeof user !== 'object') {
            return;
        }
        var map = {
            expiryTelegramAuthId: user.id || '',
            expiryTelegramAuthUsername: user.username || '',
            expiryTelegramAuthFirstName: user.first_name || '',
            expiryTelegramAuthLastName: user.last_name || '',
            expiryTelegramAuthPhotoUrl: user.photo_url || '',
            expiryTelegramAuthDate: user.auth_date || '',
            expiryTelegramAuthHash: user.hash || ''
        };
        Object.keys(map).forEach(function (id) {
            var input = document.getElementById(id);
            if (input) {
                input.value = map[id] ? String(map[id]) : '';
            }
        });

        var accountInput = document.getElementById('expiryTelegramReminderAccount');
        var displayName = user.username ? ('@' + String(user.username).replace(/^@+/, '')) : ('ID: ' + String(user.id || ''));
        if (accountInput) {
            accountInput.value = displayName;
        }

        var enableSwitch = document.getElementById('expiry_telegram_reminder_enabled');
        if (enableSwitch) {
            enableSwitch.checked = true;
        }

        expiryTelegramHasBinding = true;
        setAuthStatus(expiryTelegramBoundTextTemplate.replace('%s', displayName), 'text-success');
    };

    window.showExpiryTelegramReminderModal = function () {
        var modal = document.getElementById('expiryTelegramReminderModal');
        if (!modal || typeof bootstrap === 'undefined') {
            return;
        }
        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    };

    var form = document.getElementById('expiryTelegramReminderForm');
    if (form) {
        form.addEventListener('submit', function (event) {
            var enableSwitch = document.getElementById('expiry_telegram_reminder_enabled');
            var authId = document.getElementById('expiryTelegramAuthId');
            var authHash = document.getElementById('expiryTelegramAuthHash');
            var authDate = document.getElementById('expiryTelegramAuthDate');
            var hasNewAuth = !!(authId && authHash && authDate && authId.value && authHash.value && authDate.value);
            if (enableSwitch && enableSwitch.checked && !hasNewAuth && !expiryTelegramHasBinding) {
                event.preventDefault();
                setAuthStatus(expiryTelegramAuthRequiredText, 'text-danger');
            }
        });
    }
})();
</script>
<?php endif; ?>

<!-- 根域名邀请码模态框 -->
<div class="modal fade" id="rootdomainInviteCodesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-friends text-primary"></i> <?php echo $modalText('cfclient.rootdomain_invite.title', '根域名邀请码'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php
                $rootdomainInviteCodes = $rootdomainInviteCodes ?? [];
                $rootInviteRequiredMap = $rootInviteRequiredMap ?? [];
                $rootdomainInviteMaxPerUser = $rootdomainInviteMaxPerUser ?? 0;
                
                // 过滤出需要邀请码的根域名
                $inviteEnabledRoots = [];
                foreach ($rootInviteRequiredMap as $root => $required) {
                    if ($required) {
                        $inviteEnabledRoots[] = $root;
                    }
                }
                ?>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <?php if ($rootdomainInviteMaxPerUser > 0): ?>
                        <?php echo $modalText('cfclient.rootdomain_invite.description_with_limit', '以下根域名需要邀请码才能注册。您可以分享您的邀请码给好友，每个根域名最多可邀请 %s 个好友。邀请码使用后会自动刷新。', [$rootdomainInviteMaxPerUser]); ?>
                    <?php else: ?>
                        <?php echo $modalText('cfclient.rootdomain_invite.description', '以下根域名需要邀请码才能注册。您可以分享您的邀请码给好友，好友使用后邀请码会自动刷新。'); ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($inviteEnabledRoots)): ?>
                    <div class="row g-3">
                        <?php foreach ($inviteEnabledRoots as $rootdomain): ?>
                            <?php
                            $inviteCodeData = $rootdomainInviteCodes[$rootdomain] ?? null;
                            $inviteCode = $inviteCodeData ? ($inviteCodeData['invite_code'] ?? '') : '';
                            
                            // 先获取该根域名已邀请人数
                            $invitedCount = 0;
                            try {
                                if (class_exists('CfRootdomainInviteService') && ($userid ?? 0) > 0) {
                                    $invitedCount = CfRootdomainInviteService::getUserInviteCount($userid, $rootdomain);
                                }
                            } catch (\Throwable $e) {
                                $invitedCount = 0;
                            }
                            
                            // 检查是否达到上限
                            $maxLimit = $rootdomainInviteMaxPerUser;
                            $hasReachedLimit = false;
                            
                            if ($maxLimit > 0) {
                                // 检查是否为特权用户
                                $isPrivileged = false;
                                try {
                                    if (function_exists('cf_is_user_privileged') && cf_is_user_privileged($userid)) {
                                        $isPrivileged = true;
                                    }
                                } catch (\Throwable $e) {
                                    // 忽略错误
                                }
                                
                                // 非特权用户且已达上限
                                if (!$isPrivileged && $invitedCount >= $maxLimit) {
                                    $hasReachedLimit = true;
                                }
                            }
                            
                            // 只有未达上限才生成邀请码
                            if (!$hasReachedLimit && $inviteCode === '' && ($userid ?? 0) > 0) {
                                try {
                                    if (class_exists('CfRootdomainInviteService')) {
                                        $generated = CfRootdomainInviteService::getOrCreateInviteCode($userid, $rootdomain);
                                        $inviteCode = $generated['invite_code'] ?? '';
                                    }
                                } catch (\Throwable $e) {
                                    $inviteCode = '';
                                }
                            }
                            
                            $remainingInvites = $rootdomainInviteMaxPerUser > 0 ? max(0, $rootdomainInviteMaxPerUser - $invitedCount) : -1;
                            ?>
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <i class="fas fa-server text-success"></i>
                                            <code><?php echo htmlspecialchars($rootdomain); ?></code>
                                        </h6>
                                        
                                        <?php if ($hasReachedLimit): ?>
                                            <!-- 达到上限：显示提示 -->
                                            <div class="alert alert-warning mb-0">
                                                <div class="d-flex align-items-start">
                                                    <i class="fas fa-exclamation-triangle me-2 mt-1"></i>
                                                    <div class="flex-grow-1">
                                                        <strong><?php echo $modalText('cfclient.rootdomain_invite.limit_reached_title', '已达邀请上限'); ?></strong>
                                                        <p class="mb-2 mt-2 small"><?php echo $modalText('cfclient.rootdomain_invite.limit_reached_desc', '您已邀请 %s 人，已达到该根域名的邀请上限（最多 %s 人）。', [$invitedCount, $maxLimit]); ?></p>
                                                        <small class="text-muted">
                                                            <i class="fas fa-users"></i>
                                                            <?php echo $modalText('cfclient.rootdomain_invite.invited_count', '已邀请：%s 人', [$invitedCount]); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php elseif ($inviteCode !== ''): ?>
                                            <!-- 未达上限：显示邀请码 -->
                                            <div class="mb-3">
                                                <label class="form-label small text-muted">
                                                    <?php echo $modalText('cfclient.rootdomain_invite.your_code', '您的邀请码'); ?>
                                                </label>
                                                <div class="input-group input-group-sm">
                                                    <input type="text" class="form-control font-monospace" 
                                                           value="<?php echo htmlspecialchars($inviteCode); ?>" 
                                                           id="invite_code_modal_<?php echo htmlspecialchars($rootdomain); ?>" 
                                                           readonly>
                                                    <button class="btn btn-outline-primary" type="button" 
                                                            onclick="copyRootdomainInviteCode('<?php echo htmlspecialchars($rootdomain, ENT_QUOTES); ?>')">
                                                        <i class="fas fa-copy"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">
                                                    <i class="fas fa-users"></i>
                                                    <?php echo $modalText('cfclient.rootdomain_invite.invited_count', '已邀请：%s 人', [$invitedCount]); ?>
                                                </small>
                                                <?php if ($remainingInvites >= 0): ?>
                                                    <small class="text-success">
                                                        <i class="fas fa-check-circle"></i>
                                                        <?php echo $modalText('cfclient.rootdomain_invite.remaining', '剩余：%s', [$remainingInvites]); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <!-- 邀请码生成失败 -->
                                            <div class="alert alert-warning mb-0">
                                                <small>
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                    <?php echo $modalText('cfclient.rootdomain_invite.code_not_generated', '邀请码生成失败，请刷新页面重试'); ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary">
                        <i class="fas fa-info-circle"></i>
                        <?php echo $modalText('cfclient.rootdomain_invite.no_roots', '当前没有需要邀请码的根域名'); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <?php echo $modalText('cfclient.modals.buttons.close', '关闭'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function copyRootdomainInviteCode(rootdomain) {
    const inputId = 'invite_code_modal_' + rootdomain;
    const input = document.getElementById(inputId);
    if (!input) {
        return;
    }
    
    input.select();
    input.setSelectionRange(0, 99999);
    
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            const btn = input.nextElementSibling;
            const originalHTML = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-check"></i>';
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-success');
            
            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-primary');
            }, 2000);
        } else {
            alert(cfLang('copyFailed', '复制失败，请手动复制'));
        }
    } catch (err) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(() => {
                const btn = input.nextElementSibling;
                const originalHTML = btn.innerHTML;
                
                btn.innerHTML = '<i class="fas fa-check"></i>';
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
                
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                }, 2000);
            }).catch(() => {
                alert(cfLang('copyFailed', '复制失败，请手动复制'));
            });
        } else {
            alert(cfLang('browserNotSupport', '您的浏览器不支持自动复制，请手动复制邀请码'));
        }
    }
}

function showRootdomainInviteCodesModal() {
    var modal = document.getElementById('rootdomainInviteCodesModal');
    if (modal) {
        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
}

window.showRootdomainInviteCodesModal = showRootdomainInviteCodesModal;
window.copyRootdomainInviteCode = copyRootdomainInviteCode;
</script>

<!-- Bootstrap JS -->
<script src="<?php echo htmlspecialchars($cfmodAssetsBase . '/js/bootstrap.bundle.min.js', ENT_QUOTES); ?>"></script>
