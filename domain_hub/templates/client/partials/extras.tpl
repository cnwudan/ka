<?php
$clientLanguageCode = isset($currentClientLanguage) ? strtolower((string) $currentClientLanguage) : 'english';
$isClientLanguageChinese = $clientLanguageCode === 'chinese';
$supportTicketUrl = isset($cfClientSupportTicketUrl) && trim((string) $cfClientSupportTicketUrl) !== '' ? (string) $cfClientSupportTicketUrl : 'submitticket.php';
$supportGroupUrl = isset($cfClientSupportGroupUrl) && trim((string) $cfClientSupportGroupUrl) !== '' ? (string) $cfClientSupportGroupUrl : 'https://t.me/+l9I5TNRDLP5lZDBh';
$clientPortalUrl = isset($cfClientPortalUrl) && trim((string) $cfClientPortalUrl) !== '' ? (string) $cfClientPortalUrl : 'index.php';
$helpAiEnabled = !empty($helpAiSearchEnabled);
$helpAiAssistantDisplayName = trim((string) ($helpAiAssistantName ?? 'AI 助手'));
if ($helpAiAssistantDisplayName === '') {
    $helpAiAssistantDisplayName = $isClientLanguageChinese ? 'AI 助手' : 'AI Assistant';
}
$helpAiMaxChars = max(200, min(2000, intval($helpAiMaxInputChars ?? 600)));
$extrasTexts = [
    'tipsTitle' => cfclient_lang('cfclient.extras.tips.title', $isClientLanguageChinese ? '帮助中心知识库' : 'Help Center Knowledge Base', [], true),
    'searchPlaceholder' => cfclient_lang('cfclient.extras.search.placeholder', $isClientLanguageChinese ? '搜索关键字，例如：DNS 生效、解析报错、域名转赠' : 'Search keywords, e.g. DNS propagation, record errors, domain transfer', [], true),
    'searchEmpty' => cfclient_lang('cfclient.extras.search.empty', $isClientLanguageChinese ? '未找到匹配内容，请尝试更换关键字。' : 'No matching topics found. Try another keyword.', [], true),
    'banner' => cfclient_lang('cfclient.extras.banner', $isClientLanguageChinese ? '记录变更通常在几分钟内生效。如遇解析异常，请通过工单或 TG 社群获取实时支持。' : 'Record changes usually propagate within minutes. If DNS behaves unexpectedly, please open a ticket or contact TG community support for real-time help.', [], true),
    'coreTitle' => cfclient_lang('cfclient.extras.section.core', $isClientLanguageChinese ? '高频问题（优先查看）' : 'Top Issues (Start Here)', [], true),
    'domainTitle' => cfclient_lang('cfclient.extras.section.domain', $isClientLanguageChinese ? '域名规则与管理' : 'Domain Rules & Management', [], true),
    'dnsTitle' => cfclient_lang('cfclient.extras.section.dns', $isClientLanguageChinese ? 'DNS 记录说明' : 'DNS Record Guidance', [], true),
    'supportTitle' => cfclient_lang('cfclient.extras.support.title', $isClientLanguageChinese ? '自助支持入口' : 'Self-Service Support', [], true),
    'supportBody' => cfclient_lang('cfclient.extras.support.body', $isClientLanguageChinese ? '选择对应入口快速处理问题，建议优先提交工单以便追踪处理进度。' : 'Choose the channel that fits your issue. Opening a ticket first is recommended for better tracking.', [], true),
    'supportTicket' => cfclient_lang('cfclient.extras.support.ticket', $isClientLanguageChinese ? '提交工单' : 'Open Ticket', [], true),
    'supportAppeal' => cfclient_lang('cfclient.extras.support.appeal', $isClientLanguageChinese ? '封禁申诉工单' : 'Ban Appeal Ticket', [], true),
    'supportKb' => cfclient_lang('cfclient.extras.support.kb', $isClientLanguageChinese ? '知识库' : 'Knowledgebase', [], true),
    'supportContact' => cfclient_lang('cfclient.extras.support.contact', $isClientLanguageChinese ? 'TG 社群' : 'TG Community', [], true),
    'supportTicketDesc' => cfclient_lang('cfclient.extras.support.ticket_desc', $isClientLanguageChinese ? '反馈解析异常、记录操作失败等问题' : 'Report DNS errors, failed record operations, and account issues', [], true),
    'supportAppealDesc' => cfclient_lang('cfclient.extras.support.appeal_desc', $isClientLanguageChinese ? '账号封禁或停用后提交人工复核申请' : 'Submit manual review requests for banned or disabled accounts', [], true),
    'supportKbDesc' => cfclient_lang('cfclient.extras.support.kb_desc', $isClientLanguageChinese ? '查看 WHMCS 官方知识库文档与教程' : 'Browse WHMCS documentation and tutorials', [], true),
    'supportContactDesc' => cfclient_lang('cfclient.extras.support.contact_desc', $isClientLanguageChinese ? '加入社群获取实时公告与交流支持' : 'Join the community for announcements and real-time support', [], true),
    'backToPortal' => cfclient_lang('cfclient.extras.back_to_portal', $isClientLanguageChinese ? '返回客户中心' : 'Back to Client Area', [], true),
    'aiButton' => cfclient_lang('cfclient.extras.ai.button', $isClientLanguageChinese ? 'AI 搜索/问答' : 'AI Search & Chat', [], true),
    'aiModalTitle' => cfclient_lang('cfclient.extras.ai.modal_title', $isClientLanguageChinese ? '帮助中心 AI 助手' : 'Help Center AI Assistant', [], true),
    'aiModalHint' => cfclient_lang('cfclient.extras.ai.modal_hint', $isClientLanguageChinese ? '可咨询域名注册、续期、DNS 解析、API 密钥等插件相关问题。' : 'Ask about plugin topics such as registration, renewal, DNS records, and API keys.', [], true),
    'aiInputPlaceholder' => cfclient_lang('cfclient.extras.ai.input_placeholder', $isClientLanguageChinese ? '请输入你的问题…' : 'Type your question…', [], true),
    'aiSend' => cfclient_lang('cfclient.extras.ai.send', $isClientLanguageChinese ? '发送' : 'Send', [], true),
    'aiThinking' => cfclient_lang('cfclient.extras.ai.thinking', $isClientLanguageChinese ? '思考中…' : 'Thinking…', [], true),
    'aiWelcome' => cfclient_lang('cfclient.extras.ai.welcome', $isClientLanguageChinese ? '你好，我是 %s。请告诉我你遇到的问题。' : 'Hi, I\'m %s. Tell me what you need help with.', [$helpAiAssistantDisplayName], true),
    'aiUserLabel' => cfclient_lang('cfclient.extras.ai.user_label', $isClientLanguageChinese ? '我' : 'You', [], true),
    'aiEmptyQuestion' => cfclient_lang('cfclient.extras.ai.empty_question', $isClientLanguageChinese ? '请输入问题后再发送。' : 'Please enter a question before sending.', [], true),
    'aiTooLong' => cfclient_lang('cfclient.extras.ai.too_long', $isClientLanguageChinese ? '问题过长，请控制在 %s 字以内。' : 'Your question is too long. Keep it within %s characters.', [$helpAiMaxChars], true),
    'aiRequestFailed' => cfclient_lang('cfclient.extras.ai.request_failed', $isClientLanguageChinese ? 'AI 请求失败，请稍后再试。' : 'AI request failed. Please try again later.', [], true),
];
$privilegedDeleteHistoryEnabled = !empty($privilegedAllowDeleteWithDnsHistory);
$deleteTipKey = !empty($clientDeleteEnabled) ? 'cfclient.extras.tips.domain.delete_enabled' : 'cfclient.extras.tips.domain.delete';
$deleteTipDefault = !empty($clientDeleteEnabled)
    ? ($privilegedDeleteHistoryEnabled
        ? ($isClientLanguageChinese ? '域名删除：您当前可提交任意域名的自助删除申请（含曾配置过解析的域名）。' : 'Domain deletion: you can submit self-service deletion requests for any domain, including domains with DNS history.')
        : ($isClientLanguageChinese ? '域名删除：可在“查看详情”中提交自助删除申请。' : 'Domain deletion: submit a self-service request under “View details”.'))
    : ($isClientLanguageChinese ? '域名删除：域名成功注册后不支持删除。' : 'Domain deletion: domains cannot be removed after successful registration.');
$coreTips = [
    cfclient_lang('cfclient.extras.tips.core.propagation', $isClientLanguageChinese ? '生效时间：DNS 记录新增、修改或删除通常在几分钟内完成生效，个别线路可能略有延迟。' : 'Propagation: DNS add/update/delete changes usually take effect within minutes, with occasional route delays.', [], true),
    cfclient_lang('cfclient.extras.tips.core.error', $isClientLanguageChinese ? '异常处理：若出现无法新增、删除或更新记录，请提交工单并附上域名与错误详情。' : 'Troubleshooting: If records cannot be added, removed, or updated, open a ticket with the domain and exact error details.', [], true),
    cfclient_lang('cfclient.extras.tips.core.renewal', $isClientLanguageChinese ? '自动续期：系统会在域名到期前 180 天内开放免费续期，可通过控制台或 API 一键续期。' : 'Renewal: free renewal opens within 180 days before expiration, and supports one-click renew via panel or API.', [], true),
];
$domainTips = [
    cfclient_lang('cfclient.extras.tips.domain.transfer', $isClientLanguageChinese ? '域名转赠：转赠成功后无法撤回，请在分享前确认接收方信息。' : 'Domain transfer: transfer actions cannot be reversed once completed. Verify recipient details before sharing.', [], true),
    cfclient_lang('cfclient.extras.tips.domain.content', $isClientLanguageChinese ? '合规要求：域名禁止用于违法违规内容，违规将触发封禁处理。' : 'Compliance: domains must not be used for illegal or abusive content. Violations can trigger suspension.', [], true),
    cfclient_lang($deleteTipKey, $deleteTipDefault, [], true),
];
$dnsTips = [
    cfclient_lang('cfclient.extras.tips.dns.root', $isClientLanguageChinese ? '@ 记录：表示当前完整域名本身，例如 blog.example.com。' : '@ record: represents the full current domain itself, e.g. blog.example.com.', [], true),
    cfclient_lang('cfclient.extras.tips.dns.line', $isClientLanguageChinese ? '线路限制：部分根域名不支持按运营商或地域拆分返回不同记录。' : 'Line routing: some root domains do not support geo/carrier split routing responses.', [], true),
    cfclient_lang('cfclient.extras.tips.dns.caa_srv', $isClientLanguageChinese ? '参数建议：配置 SRV、CAA 等高级记录时，请完整填写所有字段并核对格式。' : 'Advanced records: for SRV/CAA and similar types, fill all fields and verify formatting carefully.', [], true),
];
$banAppealSubject = $isClientLanguageChinese ? '封禁申诉' : 'Ban Appeal';
$banAppealMessageBase = $isClientLanguageChinese
    ? '我的账号被封禁/停用。'
    : 'My account has been banned or disabled.';
$banAppealMessageTail = $isClientLanguageChinese ? '请协助核查并解除限制。' : 'Please review and lift the restriction.';
$banAppealReason = '';
if (!empty($banReasonText)) {
    $banAppealReason = '\n' . strip_tags($banReasonText);
}
$banAppealMessage = $banAppealMessageBase . $banAppealReason . '\n' . $banAppealMessageTail;
$supportEntries = [];
if (!empty($isUserBannedOrInactive) && $isUserBannedOrInactive) {
    $supportEntries[] = [
        'href' => 'submitticket.php?step=2&deptid=1&subject=' . urlencode($banAppealSubject) . '&message=' . urlencode($banAppealMessage),
        'icon' => 'far fa-flag',
        'label' => $extrasTexts['supportAppeal'],
        'desc' => $extrasTexts['supportAppealDesc'],
        'external' => false,
    ];
} else {
    $supportEntries[] = [
        'href' => $supportTicketUrl,
        'icon' => 'far fa-life-ring',
        'label' => $extrasTexts['supportTicket'],
        'desc' => $extrasTexts['supportTicketDesc'],
        'external' => true,
    ];
}
$supportEntries[] = [
    'href' => 'knowledgebase.php',
    'icon' => 'far fa-file-alt',
    'label' => $extrasTexts['supportKb'],
    'desc' => $extrasTexts['supportKbDesc'],
    'external' => false,
];
$supportEntries[] = [
    'href' => $supportGroupUrl,
    'icon' => 'far fa-comments',
    'label' => $extrasTexts['supportContact'],
    'desc' => $extrasTexts['supportContactDesc'],
    'external' => true,
];
$helpSections = [
    [
        'id' => 'core',
        'icon' => 'far fa-compass',
        'title' => $extrasTexts['coreTitle'],
        'items' => $coreTips,
        'expanded' => true,
    ],
    [
        'id' => 'domain',
        'icon' => 'far fa-clone',
        'title' => $extrasTexts['domainTitle'],
        'items' => $domainTips,
        'expanded' => false,
    ],
    [
        'id' => 'dns',
        'icon' => 'far fa-hdd',
        'title' => $extrasTexts['dnsTitle'],
        'items' => $dnsTips,
        'expanded' => false,
    ],
];
?>
<div class="cf-help-center mt-4">
    <div class="cf-help-search-wrap mb-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="position-relative flex-grow-1">
                <i class="fas fa-search cf-help-search-icon"></i>
                <input
                    type="search"
                    class="form-control cf-help-search-input"
                    id="cfHelpSearchInput"
                    placeholder="<?php echo $extrasTexts['searchPlaceholder']; ?>"
                    autocomplete="off"
                >
            </div>
            <?php if ($helpAiEnabled): ?>
                <button type="button" class="btn btn-primary" id="cfHelpAiOpenBtn">
                    <i class="fas fa-robot me-1"></i><?php echo $extrasTexts['aiButton']; ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card cf-help-knowledge-card">
        <div class="card-body p-4">
            <div class="d-flex align-items-center mb-3">
                <h6 class="mb-0 fw-semibold text-dark">
                    <i class="far fa-lightbulb me-2 text-primary"></i><?php echo $extrasTexts['tipsTitle']; ?>
                </h6>
            </div>

            <div class="cf-help-banner mb-3">
                <i class="fas fa-rocket me-2"></i><?php echo $extrasTexts['banner']; ?>
            </div>

            <div class="accordion cf-help-accordion" id="cfHelpAccordion">
                <?php foreach ($helpSections as $index => $section): ?>
                    <?php
                    $headingId = 'cfHelpHeading' . $index;
                    $collapseId = 'cfHelpCollapse' . $index;
                    $isExpanded = !empty($section['expanded']);
                    ?>
                    <div class="accordion-item cf-help-accordion-item" data-default-expanded="<?php echo $isExpanded ? '1' : '0'; ?>">
                        <h2 class="accordion-header" id="<?php echo $headingId; ?>">
                            <button
                                class="accordion-button <?php echo $isExpanded ? '' : 'collapsed'; ?>"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#<?php echo $collapseId; ?>"
                                aria-expanded="<?php echo $isExpanded ? 'true' : 'false'; ?>"
                                aria-controls="<?php echo $collapseId; ?>"
                            >
                                <span class="cf-help-accordion-icon"><i class="<?php echo htmlspecialchars($section['icon'], ENT_QUOTES); ?>"></i></span>
                                <span><?php echo $section['title']; ?></span>
                            </button>
                        </h2>
                        <div
                            id="<?php echo $collapseId; ?>"
                            class="accordion-collapse collapse <?php echo $isExpanded ? 'show' : ''; ?>"
                            aria-labelledby="<?php echo $headingId; ?>"
                            data-bs-parent="#cfHelpAccordion"
                        >
                            <div class="accordion-body pt-2 pb-3">
                                <ul class="cf-help-list mb-0">
                                    <?php foreach ($section['items'] as $item): ?>
                                        <li class="cf-help-item">
                                            <span class="cf-help-item-icon"><i class="far fa-circle"></i></span>
                                            <span class="cf-help-item-text"><?php echo $item; ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="cf-help-search-empty d-none" id="cfHelpSearchEmpty">
                <?php echo $extrasTexts['searchEmpty']; ?>
            </div>
        </div>
    </div>

    <div class="card cf-help-support-card mt-4 mb-4">
        <div class="card-body p-4">
            <h6 class="mb-2 fw-semibold text-dark"><i class="far fa-life-ring me-2 text-primary"></i><?php echo $extrasTexts['supportTitle']; ?></h6>
            <p class="text-muted small mb-3"><?php echo $extrasTexts['supportBody']; ?></p>
            <div class="row g-3">
                <?php foreach ($supportEntries as $entry): ?>
                    <div class="col-md-4">
                        <a
                            href="<?php echo htmlspecialchars($entry['href'], ENT_QUOTES); ?>"
                            class="cf-help-support-entry"
                            <?php if (!empty($entry['external'])): ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>
                        >
                            <span class="cf-help-support-icon"><i class="<?php echo htmlspecialchars($entry['icon'], ENT_QUOTES); ?>"></i></span>
                            <span class="cf-help-support-label"><?php echo $entry['label']; ?></span>
                            <span class="cf-help-support-desc"><?php echo $entry['desc']; ?></span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($helpAiEnabled): ?>
<div class="modal fade" id="cfHelpAiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-robot text-primary me-2"></i><?php echo $extrasTexts['aiModalTitle']; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3"><?php echo $extrasTexts['aiModalHint']; ?></div>
                <div class="border rounded p-2 bg-light" id="cfHelpAiMessages" style="max-height: 360px; overflow: auto;"></div>
                <div class="text-danger small mt-2 d-none" id="cfHelpAiError"></div>
            </div>
            <div class="modal-footer">
                <div class="input-group">
                    <input type="text" class="form-control" id="cfHelpAiInput" maxlength="<?php echo intval($helpAiMaxChars); ?>" placeholder="<?php echo $extrasTexts['aiInputPlaceholder']; ?>">
                    <button type="button" class="btn btn-primary" id="cfHelpAiSendBtn"><?php echo $extrasTexts['aiSend']; ?></button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row mt-4">
    <div class="col-12">
        <div class="text-center">
            <a href="<?php echo htmlspecialchars($clientPortalUrl, ENT_QUOTES); ?>" class="btn btn-outline-secondary btn-custom">
                <i class="fas fa-arrow-left"></i> <?php echo $extrasTexts['backToPortal']; ?>
            </a>
        </div>
    </div>
</div>

<script>
(function () {
    var searchInput = document.getElementById('cfHelpSearchInput');
    var sections = Array.prototype.slice.call(document.querySelectorAll('.cf-help-accordion-item'));
    var emptyState = document.getElementById('cfHelpSearchEmpty');

    if (!searchInput || !sections.length) {
        return;
    }

    var normalize = function (value) {
        return (value || '').toString().toLowerCase().trim();
    };

    var resetAccordionState = function () {
        sections.forEach(function (section) {
            section.classList.remove('d-none');
            var items = Array.prototype.slice.call(section.querySelectorAll('.cf-help-item'));
            items.forEach(function (item) {
                item.classList.remove('d-none');
            });

            var collapseEl = section.querySelector('.accordion-collapse');
            var button = section.querySelector('.accordion-button');
            var shouldExpand = section.getAttribute('data-default-expanded') === '1';

            if (collapseEl) {
                collapseEl.classList.toggle('show', shouldExpand);
            }
            if (button) {
                button.classList.toggle('collapsed', !shouldExpand);
                button.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
            }
        });

        if (emptyState) {
            emptyState.classList.add('d-none');
        }
    };

    var applyFilter = function (keyword) {
        var normalizedKeyword = normalize(keyword);

        if (normalizedKeyword === '') {
            resetAccordionState();
            return;
        }

        var visibleCount = 0;

        sections.forEach(function (section) {
            var items = Array.prototype.slice.call(section.querySelectorAll('.cf-help-item'));
            var sectionVisible = false;

            items.forEach(function (item) {
                var matched = normalize(item.textContent).indexOf(normalizedKeyword) !== -1;
                item.classList.toggle('d-none', !matched);
                if (matched) {
                    sectionVisible = true;
                    visibleCount += 1;
                }
            });

            section.classList.toggle('d-none', !sectionVisible);

            var collapseEl = section.querySelector('.accordion-collapse');
            var button = section.querySelector('.accordion-button');

            if (sectionVisible) {
                if (collapseEl) {
                    collapseEl.classList.add('show');
                }
                if (button) {
                    button.classList.remove('collapsed');
                    button.setAttribute('aria-expanded', 'true');
                }
            }
        });

        if (emptyState) {
            emptyState.classList.toggle('d-none', visibleCount > 0);
        }
    };

    searchInput.addEventListener('input', function () {
        applyFilter(searchInput.value);
    });
})();

(function () {
    var aiEnabled = <?php echo $helpAiEnabled ? 'true' : 'false'; ?>;
    if (!aiEnabled) {
        return;
    }

    var openBtn = document.getElementById('cfHelpAiOpenBtn');
    var modalEl = document.getElementById('cfHelpAiModal');
    var messagesEl = document.getElementById('cfHelpAiMessages');
    var errorEl = document.getElementById('cfHelpAiError');
    var inputEl = document.getElementById('cfHelpAiInput');
    var sendBtn = document.getElementById('cfHelpAiSendBtn');
    var modal = null;
    var history = [];
    var busy = false;
    var maxChars = <?php echo intval($helpAiMaxChars); ?>;

    if (!openBtn || !modalEl || !messagesEl || !inputEl || !sendBtn) {
        return;
    }

    var text = {
        assistantName: <?php echo json_encode($helpAiAssistantDisplayName, JSON_UNESCAPED_UNICODE); ?>,
        userName: <?php echo json_encode($extrasTexts['aiUserLabel'], JSON_UNESCAPED_UNICODE); ?>,
        welcome: <?php echo json_encode($extrasTexts['aiWelcome'], JSON_UNESCAPED_UNICODE); ?>,
        emptyQuestion: <?php echo json_encode($extrasTexts['aiEmptyQuestion'], JSON_UNESCAPED_UNICODE); ?>,
        tooLong: <?php echo json_encode($extrasTexts['aiTooLong'], JSON_UNESCAPED_UNICODE); ?>,
        requestFailed: <?php echo json_encode($extrasTexts['aiRequestFailed'], JSON_UNESCAPED_UNICODE); ?>,
        send: <?php echo json_encode($extrasTexts['aiSend'], JSON_UNESCAPED_UNICODE); ?>,
        thinking: <?php echo json_encode($extrasTexts['aiThinking'], JSON_UNESCAPED_UNICODE); ?>
    };

    var escapeHtml = function (value) {
        return String(value || '').replace(/[&<>"']/g, function (ch) {
            return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'})[ch];
        });
    };

    var renderMessage = function (role, content) {
        var row = document.createElement('div');
        row.className = 'mb-2';

        var badgeClass = role === 'assistant' ? 'bg-primary' : 'bg-secondary';
        var roleName = role === 'assistant' ? text.assistantName : text.userName;
        var body = '<div class="small mb-1"><span class="badge ' + badgeClass + '">' + escapeHtml(roleName) + '</span></div>' +
            '<div class="border rounded bg-white p-2 small" style="white-space: pre-wrap;">' + escapeHtml(content) + '</div>';
        row.innerHTML = body;
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    };

    var showError = function (message) {
        if (!errorEl) {
            return;
        }
        var textMessage = String(message || '').trim();
        if (textMessage === '') {
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
            return;
        }
        errorEl.textContent = textMessage;
        errorEl.classList.remove('d-none');
    };

    var setBusy = function (state) {
        busy = !!state;
        sendBtn.disabled = busy;
        sendBtn.textContent = busy ? text.thinking : text.send;
        inputEl.disabled = busy;
    };

    var ensureModal = function () {
        if (!window.bootstrap || !bootstrap.Modal) {
            return null;
        }
        if (!modal) {
            modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        }
        return modal;
    };

    var buildUrl = function () {
        if (typeof cfClientBuildModuleUrl === 'function') {
            return cfClientBuildModuleUrl('ajax_help_ai_search');
        }
        return 'index.php?m=domain_hub&module_action=ajax_help_ai_search';
    };

    var sendQuestion = function () {
        if (busy) {
            return;
        }
        var question = (inputEl.value || '').trim();
        if (!question) {
            showError(text.emptyQuestion);
            return;
        }
        if (question.length > maxChars) {
            showError(text.tooLong);
            return;
        }

        showError('');
        renderMessage('user', question);
        history.push({ role: 'user', content: question });
        inputEl.value = '';
        setBusy(true);

        fetch(buildUrl(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CF_MOD_CSRF || ''
            },
            body: JSON.stringify({
                query: question,
                history: history.slice(-8)
            })
        }).then(function (res) {
            return res.json();
        }).then(function (res) {
            if (res && res.success && res.data && res.data.answer) {
                var answer = String(res.data.answer || '').trim();
                if (answer !== '') {
                    renderMessage('assistant', answer);
                    history.push({ role: 'assistant', content: answer });
                    if (history.length > 12) {
                        history = history.slice(-12);
                    }
                    return;
                }
            }
            var errorText = (res && res.error) ? res.error : text.requestFailed;
            showError(errorText);
        }).catch(function () {
            showError(text.requestFailed);
        }).finally(function () {
            setBusy(false);
            inputEl.focus();
        });
    };

    openBtn.addEventListener('click', function () {
        var instance = ensureModal();
        if (instance) {
            instance.show();
        }
        if (!messagesEl.dataset.initialized) {
            renderMessage('assistant', text.welcome);
            messagesEl.dataset.initialized = '1';
        }
        inputEl.focus();
    });

    sendBtn.addEventListener('click', sendQuestion);
    inputEl.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendQuestion();
        }
    });
})();
</script>
