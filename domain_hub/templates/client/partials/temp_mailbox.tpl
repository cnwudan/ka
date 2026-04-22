<?php
$tempMailbox = is_array($tempMailbox ?? null) ? $tempMailbox : [];
$tempMailboxEnabled = !empty($tempMailbox['enabled']);
$tempMailboxConfigured = !empty($tempMailbox['configured']);
$tempMailboxNotice = (string) ($tempMailbox['notice'] ?? '');
$tempMailboxDomain = (string) ($tempMailbox['domain'] ?? '');
$tempMailboxRetentionHours = max(1, (int) ($tempMailbox['retention_hours'] ?? 48));
$tempMailboxInfo = is_array($tempMailbox['mailbox'] ?? null) ? $tempMailbox['mailbox'] : null;
$tempMailboxAddress = (string) ($tempMailboxInfo['email_address'] ?? '');
$tempMailboxLastReceivedAt = (string) ($tempMailboxInfo['last_received_at'] ?? '');
$tempMailboxEmails = is_array($tempMailbox['emails'] ?? null) ? $tempMailbox['emails'] : ['items' => [], 'page' => 1, 'totalPages' => 1, 'total' => 0, 'perPage' => 15];
$tempMailboxItems = is_array($tempMailboxEmails['items'] ?? null) ? $tempMailboxEmails['items'] : [];
$tempMailboxPage = max(1, (int) ($tempMailboxEmails['page'] ?? 1));
$tempMailboxTotalPages = max(1, (int) ($tempMailboxEmails['totalPages'] ?? 1));
$tempMailboxTotal = max(0, (int) ($tempMailboxEmails['total'] ?? 0));
$tempMailboxSelected = is_array($tempMailbox['selected'] ?? null) ? $tempMailbox['selected'] : null;
$tempMailboxSelectedId = (int) ($tempMailboxSelected['id'] ?? 0);

$tempMailboxBaseParams = is_array($cfClientBaseEntryQuery ?? null) ? $cfClientBaseEntryQuery : ['m' => $moduleSlug];
$tempMailboxBaseParams['view'] = 'tempmail';

$tempMailboxBuildUrl = static function(array $extra = []) use ($tempMailboxBaseParams, $cfClientEntryScript) {
    $params = $tempMailboxBaseParams;
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }
    return ($cfClientEntryScript ?? 'index.php') . '?' . http_build_query($params);
};
?>

<?php if (!$tempMailboxEnabled): ?>
    <div class="alert alert-info mb-0">
        <i class="fas fa-info-circle me-1"></i>
        <?php echo cfclient_lang('cfclient.tempmail.disabled', '临时邮箱功能尚未开启，请联系管理员。', [], true); ?>
    </div>
<?php elseif (!$tempMailboxConfigured): ?>
    <div class="alert alert-warning mb-0">
        <i class="fas fa-exclamation-triangle me-1"></i>
        <?php echo cfclient_lang('cfclient.tempmail.not_configured', '临时邮箱域名尚未配置，请联系管理员完成 Cloudflare Email Routing 设置。', [], true); ?>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                <div>
                    <h6 class="mb-1"><i class="fas fa-at text-primary me-2"></i><?php echo cfclient_lang('cfclient.tempmail.address_title', '当前临时邮箱地址', [], true); ?></h6>
                    <div class="fw-semibold text-break"><?php echo htmlspecialchars($tempMailboxAddress !== '' ? $tempMailboxAddress : ('@' . $tempMailboxDomain), ENT_QUOTES); ?></div>
                    <div class="small text-muted mt-1">
                        <?php echo cfclient_lang('cfclient.tempmail.retention', '邮件最多保留 %s 小时，超过时限会自动清理。', [strval($tempMailboxRetentionHours)], true); ?>
                        <?php if ($tempMailboxLastReceivedAt !== ''): ?>
                            <span class="ms-2"><?php echo cfclient_lang('cfclient.tempmail.last_received', '最近收件：%s', [$tempMailboxLastReceivedAt], true); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyText('<?php echo htmlspecialchars(addslashes($tempMailboxAddress), ENT_QUOTES); ?>')">
                        <i class="fas fa-copy me-1"></i><?php echo cfclient_lang('cfclient.tempmail.copy', '复制邮箱地址', [], true); ?>
                    </button>
                </div>
            </div>
            <div class="small text-muted mt-3">
                <?php echo cfclient_lang('cfclient.tempmail.notice', '请将 Cloudflare Email Routing 的转发目标指向接收服务并绑定到本地址，页面仅提供只读查看，不支持发送邮件。', [], true); ?>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h6 class="mb-0"><i class="fas fa-inbox text-secondary me-2"></i><?php echo cfclient_lang('cfclient.tempmail.list_title', '最近接收邮件', [], true); ?></h6>
                        <span class="badge bg-light text-dark"><?php echo intval($tempMailboxTotal); ?></span>
                    </div>
                    <?php if (!empty($tempMailboxItems)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th><?php echo cfclient_lang('cfclient.tempmail.col.subject', '主题', [], true); ?></th>
                                        <th><?php echo cfclient_lang('cfclient.tempmail.col.from', '发件人', [], true); ?></th>
                                        <th><?php echo cfclient_lang('cfclient.tempmail.col.received', '接收时间', [], true); ?></th>
                                        <th><?php echo cfclient_lang('cfclient.tempmail.col.expires', '过期时间', [], true); ?></th>
                                        <th class="text-end"><?php echo cfclient_lang('cfclient.tempmail.col.action', '操作', [], true); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tempMailboxItems as $mailItem): ?>
                                        <?php
                                        $mailId = (int) ($mailItem['id'] ?? 0);
                                        $openUrl = $tempMailboxBuildUrl([
                                            'temp_mail_page' => $tempMailboxPage,
                                            'temp_mail_id' => $mailId,
                                        ]);
                                        $isActiveRow = $tempMailboxSelectedId > 0 && $mailId === $tempMailboxSelectedId;
                                        ?>
                                        <tr class="<?php echo $isActiveRow ? 'table-primary' : ''; ?>">
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) (($mailItem['subject'] ?? '') !== '' ? $mailItem['subject'] : cfclient_lang('cfclient.tempmail.subject_empty', '(无主题)', [], false)), ENT_QUOTES); ?></div>
                                                <?php if (!empty($mailItem['preview'])): ?>
                                                    <div class="small text-muted text-break"><?php echo htmlspecialchars((string) $mailItem['preview'], ENT_QUOTES); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-break"><?php echo htmlspecialchars((string) ($mailItem['sender'] ?? '-'), ENT_QUOTES); ?></td>
                                            <td class="small"><?php echo htmlspecialchars((string) ($mailItem['received_at'] ?? '-'), ENT_QUOTES); ?></td>
                                            <td class="small"><?php echo htmlspecialchars((string) ($mailItem['expires_at'] ?? '-'), ENT_QUOTES); ?></td>
                                            <td class="text-end">
                                                <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars($openUrl, ENT_QUOTES); ?>">
                                                    <i class="fas fa-eye me-1"></i><?php echo cfclient_lang('cfclient.tempmail.open', '查看', [], true); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($tempMailboxTotalPages > 1): ?>
                            <nav class="mt-3">
                                <ul class="pagination pagination-sm mb-0">
                                    <?php for ($mailPage = 1; $mailPage <= $tempMailboxTotalPages; $mailPage++): ?>
                                        <?php
                                        $pageUrl = $tempMailboxBuildUrl([
                                            'temp_mail_page' => $mailPage,
                                            'temp_mail_id' => $tempMailboxSelectedId > 0 ? $tempMailboxSelectedId : null,
                                        ]);
                                        ?>
                                        <li class="page-item <?php echo $mailPage === $tempMailboxPage ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo htmlspecialchars($pageUrl, ENT_QUOTES); ?>"><?php echo $mailPage; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-light border mb-0">
                            <i class="fas fa-info-circle me-1"></i><?php echo cfclient_lang('cfclient.tempmail.empty', '当前没有可查看的邮件，请稍后刷新。', [], true); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="mb-3"><i class="fas fa-file-alt text-info me-2"></i><?php echo cfclient_lang('cfclient.tempmail.detail_title', '邮件内容（只读）', [], true); ?></h6>
                    <?php if ($tempMailboxSelected): ?>
                        <div class="small mb-3">
                            <div><span class="text-muted"><?php echo cfclient_lang('cfclient.tempmail.detail.from', '发件人：', [], true); ?></span><?php echo htmlspecialchars((string) ($tempMailboxSelected['sender'] ?? '-'), ENT_QUOTES); ?></div>
                            <div><span class="text-muted"><?php echo cfclient_lang('cfclient.tempmail.detail.to', '收件人：', [], true); ?></span><?php echo htmlspecialchars((string) ($tempMailboxSelected['recipient'] ?? '-'), ENT_QUOTES); ?></div>
                            <div><span class="text-muted"><?php echo cfclient_lang('cfclient.tempmail.detail.received', '接收时间：', [], true); ?></span><?php echo htmlspecialchars((string) ($tempMailboxSelected['received_at'] ?? '-'), ENT_QUOTES); ?></div>
                            <div><span class="text-muted"><?php echo cfclient_lang('cfclient.tempmail.detail.expires', '过期时间：', [], true); ?></span><?php echo htmlspecialchars((string) ($tempMailboxSelected['expires_at'] ?? '-'), ENT_QUOTES); ?></div>
                        </div>
                        <div class="mb-2 fw-semibold text-break"><?php echo htmlspecialchars((string) (($tempMailboxSelected['subject'] ?? '') !== '' ? $tempMailboxSelected['subject'] : cfclient_lang('cfclient.tempmail.subject_empty', '(无主题)', [], false)), ENT_QUOTES); ?></div>
                        <?php if (!empty($tempMailboxSelected['body_text'])): ?>
                            <pre class="bg-light border rounded p-2 small mb-3" style="white-space: pre-wrap; max-height: 340px; overflow: auto;"><?php echo htmlspecialchars((string) $tempMailboxSelected['body_text'], ENT_QUOTES); ?></pre>
                        <?php elseif (!empty($tempMailboxSelected['body_html'])): ?>
                            <pre class="bg-light border rounded p-2 small mb-3" style="white-space: pre-wrap; max-height: 340px; overflow: auto;"><?php echo htmlspecialchars((string) $tempMailboxSelected['body_html'], ENT_QUOTES); ?></pre>
                        <?php else: ?>
                            <div class="alert alert-light border mb-0"><?php echo cfclient_lang('cfclient.tempmail.body_empty', '此邮件未包含可展示内容。', [], true); ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-light border mb-0">
                            <i class="fas fa-mouse-pointer me-1"></i><?php echo cfclient_lang('cfclient.tempmail.select_tip', '从左侧列表选择一封邮件查看详情。', [], true); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
