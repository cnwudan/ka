<?php
$modalText = function (string $key, string $default, array $params = [], bool $escape = true) {
    return cfclient_lang($key, $default, $params, $escape);
};
$modalLanguage = strtolower((string) ($currentClientLanguage ?? 'english'));
$modalIsChinese = $modalLanguage === 'chinese';
$nsListLabelDefault = $modalIsChinese ? 'NS 服务器列表' : 'Name Server List';
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
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.ttl', 'TTL (秒)'); ?></label>
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
                                <li><?php echo $modalText('cfclient.modals.dns.alert.2', 'DNS解析支持按线路（运营商/地域）返回记录'); ?></li>
                                <li><strong><?php echo $modalText('cfclient.modals.dns.alert.3', '可以同时设置 @ 记录和三级域名记录，互不影响'); ?></strong></li>
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
                        <div class="d-flex align-items-center gap-1 mb-3">
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

    <?php if ($domainGiftEnabled): ?>
    <!-- 域名转赠模态框 -->
    <div class="modal fade" id="domainGiftModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exchange-alt text-primary"></i> <?php echo $modalText('cfclient.modals.gift.title', '域名转赠'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="giftAlertPlaceholder"></div>
                    <ul class="nav nav-tabs" id="giftTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="gift-initiate-tab" data-bs-toggle="tab" data-bs-target="#gift-initiate-pane" type="button" role="tab"><?php echo $modalText('cfclient.modals.gift.tabs.initiate', '发起转赠'); ?></button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="gift-accept-tab" data-bs-toggle="tab" data-bs-target="#gift-accept-pane" type="button" role="tab"><?php echo $modalText('cfclient.modals.gift.tabs.accept', '接受转赠'); ?></button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="gift-history-tab" data-bs-toggle="tab" data-bs-target="#gift-history-pane" type="button" role="tab"><?php echo $modalText('cfclient.modals.gift.tabs.history', '转赠历史'); ?></button>
                        </li>
                    </ul>
                    <div class="tab-content mt-3">
                        <div class="tab-pane fade show active" id="gift-initiate-pane" role="tabpanel">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $modalText('cfclient.modals.gift.label.select_domain', '选择要转赠的域名'); ?></label>
                                <select class="form-select" id="giftDomainSelect">
                                    <option value=""><?php echo $modalText('cfclient.modals.gift.placeholder.domain', '请选择域名'); ?></option>
                                </select>
                                <div class="form-text"><?php echo $modalText('cfclient.modals.gift.hint.domain', '仅支持转赠状态正常且未锁定的域名，转赠后域名将暂时锁定，直到完成或取消。'); ?></div>
                            </div>
                            <button type="button" class="btn btn-primary" id="generateGiftButton">
                                <i class="fas fa-magic"></i> <?php echo $modalText('cfclient.modals.gift.button.generate', '生成接收码'); ?>
                            </button>
                            <div class="alert alert-success mt-3 d-none" id="giftCodeResult">
                                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                                    <div>
                                        <div class="fw-bold mb-1"><?php echo $modalText('cfclient.modals.gift.result.code', '接收码：'); ?><span id="giftCodeValue" class="fs-5 text-uppercase"></span></div>
                                        <div class="text-muted small"><?php echo $modalText('cfclient.modals.gift.result.expire', '有效期至：'); ?><span id="giftCodeExpire"></span></div>
                                        <div class="text-muted small"><?php echo $modalText('cfclient.modals.gift.result.domain', '域名：'); ?><span id="giftCodeDomain"></span></div>
                                    </div>
                                    <div class="text-md-end">
                                        <button type="button" class="btn btn-outline-light text-dark" id="giftCopyButton">
                                            <i class="fas fa-copy"></i> <?php echo $modalText('cfclient.modals.gift.button.copy', '复制接收码'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="gift-accept-pane" role="tabpanel">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $modalText('cfclient.modals.gift.label.code', '输入接收码'); ?></label>
                                <input type="text" id="giftAcceptCode" class="form-control text-uppercase" placeholder="<?php echo $modalText('cfclient.modals.gift.placeholder.code', '请输入18位接收码'); ?>">
                                <div class="form-text"><?php echo $modalText('cfclient.modals.gift.hint.code', '接收码由赠送方生成，有效期 %s 小时。', [intval($domainGiftTtlHours)]); ?></div>
                            </div>
                            <button type="button" class="btn btn-success" id="acceptGiftButton">
                                <i class="fas fa-hand-holding"></i> <?php echo $modalText('cfclient.modals.gift.button.accept', '立即领取'); ?>
                            </button>
                        </div>
                        <div class="tab-pane fade" id="gift-history-pane" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th><?php echo $modalText('cfclient.modals.gift.table.domain', '域名'); ?></th>
                                            <th><?php echo $modalText('cfclient.modals.gift.table.code', '接收码'); ?></th>
                                            <th><?php echo $modalText('cfclient.modals.gift.table.status', '状态'); ?></th>
                                            <th><?php echo $modalText('cfclient.modals.gift.table.time', '时间'); ?></th>
                                            <th class="text-end"><?php echo $modalText('cfclient.modals.gift.table.actions', '操作'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="giftHistoryTableBody">
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3"><?php echo $modalText('cfclient.modals.gift.table.empty', '暂无转赠记录'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <nav class="mt-3">
                                <ul class="pagination pagination-sm justify-content-end mb-0" id="giftHistoryPagination"></ul>
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
                    <small class="text-muted"><?php echo $modalText('cfclient.invite_registration.logs_hint', '最多展示最近 10 条记录，邮箱已脱敏'); ?></small>
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

<?php if (!$inviteRegUnlocked): ?>
<?php
$inviteGateMode = (string) ($inviteRegistrationGateMode ?? 'invite_only');
$inviteGateInviteEnabled = !empty($inviteRegistrationInviteEnabled);
$inviteGateGithubEnabled = !empty($inviteRegistrationGithubEnabled);
$inviteGateGithubConfigured = !empty($inviteRegistrationGithubConfigured);
$inviteGateGithubAuthUrl = trim((string) ($inviteRegistrationGithubAuthUrl ?? ''));
$inviteGateGithubMinMonths = max(0, intval($inviteRegistrationGithubMinMonths ?? 0));
$inviteGateGithubMinRepos = max(0, intval($inviteRegistrationGithubMinRepos ?? 0));
$inviteGateFlash = is_array($inviteRegistrationGateFlash ?? null) ? $inviteRegistrationGateFlash : null;
?>
<div class="modal fade" id="inviteRegistrationRequiredModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-lock me-2"></i> <?php echo $modalText('cfclient.invite_registration.required_title', '准入验证'); ?></h5>
            </div>
            <div class="modal-body">
                <?php if ($inviteGateFlash && !empty($inviteGateFlash['message'])): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($inviteGateFlash['type'] ?? 'danger', ENT_QUOTES); ?>">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        <?php echo htmlspecialchars((string) ($inviteGateFlash['message'] ?? ''), ENT_QUOTES); ?>
                    </div>
                <?php endif; ?>

                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    <?php echo $modalText('cfclient.invite_registration.required_notice', '首次使用前请先完成准入验证。'); ?>
                </div>

                <?php if ($inviteGateInviteEnabled): ?>
                <form method="post" id="inviteRegRequiredForm" class="mb-3">
                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                    <input type="hidden" name="action" value="invite_registration_unlock">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="invite_reg_code_input"><?php echo $modalText('cfclient.invite_registration.input_label', '输入邀请码'); ?></label>
                        <input type="text" class="form-control form-control-lg text-uppercase" name="invite_reg_code" id="invite_reg_code_input" placeholder="<?php echo $modalText('cfclient.invite_registration.input_placeholder', '例如：ABCD1234EFGH'); ?>" maxlength="20" <?php echo $inviteGateInviteEnabled ? 'required' : ''; ?> autofocus>
                        <div class="form-text"><?php echo $modalText('cfclient.invite_registration.input_hint', '邀请码不区分大小写，请仔细核对后提交。'); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-check"></i> <?php echo $modalText('cfclient.invite_registration.submit', '验证邀请码'); ?>
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($inviteGateInviteEnabled && $inviteGateGithubEnabled): ?>
                    <div class="text-center text-muted my-2">—— <?php echo $modalText('cfclient.invite_registration.or', '或'); ?> ——</div>
                <?php endif; ?>

                <?php if ($inviteGateGithubEnabled): ?>
                    <?php if ($inviteGateGithubConfigured && $inviteGateGithubAuthUrl !== ''): ?>
                        <a href="<?php echo htmlspecialchars($inviteGateGithubAuthUrl, ENT_QUOTES); ?>" class="btn btn-dark w-100 d-flex align-items-center justify-content-center gap-2 mb-2">
                            <svg aria-hidden="true" height="20" viewBox="0 0 16 16" width="20" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8a8 8 0 0 0 5.47 7.59c.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.5-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8 8 0 0 0 16 8c0-4.42-3.58-8-8-8Z"></path></svg>
                            <?php echo $modalText('cfclient.invite_registration.github.button', '使用 GitHub 快捷认证'); ?>
                        </a>
                        <?php if ($inviteGateGithubMinMonths > 0): ?>
                            <div class="form-text text-muted mb-2"><?php echo $modalText('cfclient.invite_registration.github.min_months', 'GitHub 账号需至少注册 %s 个月。', [$inviteGateGithubMinMonths]); ?></div>
                        <?php endif; ?>
                        <?php if ($inviteGateGithubMinRepos > 0): ?>
                            <div class="form-text text-muted mb-3"><?php echo $modalText('cfclient.invite_registration.github.min_repos', 'GitHub 账号公开仓库数需至少 %s 个。', [$inviteGateGithubMinRepos]); ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-3">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo $modalText('cfclient.invite_registration.github.not_configured', '管理员尚未配置 GitHub OAuth，请联系管理员。'); ?>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    var inviteRegRequiredModal = document.getElementById('inviteRegistrationRequiredModal');
    if (inviteRegRequiredModal) {
        var bsModal = new bootstrap.Modal(inviteRegRequiredModal);
        bsModal.show();
    }
});
</script>
<?php endif; ?>

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
