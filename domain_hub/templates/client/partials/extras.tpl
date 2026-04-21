<?php
$clientLanguageCode = isset($currentClientLanguage) ? strtolower((string) $currentClientLanguage) : 'english';
$isClientLanguageChinese = $clientLanguageCode === 'chinese';
$extrasTexts = [
    'tipsTitle' => cfclient_lang('cfclient.extras.tips.title', $isClientLanguageChinese ? '域名知识小贴士' : 'Domain Tips', [], true),
    'domainTitle' => cfclient_lang('cfclient.extras.tips.domain.title', $isClientLanguageChinese ? '📚 域名概念' : '📚 Domain Basics', [], true),
    'dnsTitle' => cfclient_lang('cfclient.extras.tips.dns.title', $isClientLanguageChinese ? '🔧 DNS记录说明' : '🔧 DNS Records', [], true),
    'warning' => cfclient_lang('cfclient.extras.warning', $isClientLanguageChinese ? '重要提示：DNS记录添加、修改或删除可能需要几分钟时间生效，请耐心等待。' : 'Important: Adding, editing, or deleting DNS records can take a few minutes to propagate. Please wait patiently.', [], true),
    'warningExtra' => cfclient_lang('cfclient.extras.warning.extra', $isClientLanguageChinese ? '重要提示：若您遇到解析记录无法新增或删除的情况，请提交工单并注明具体域名与错误详情获取协助。您也可以访问 t.cc.cd 加入官方 TG 社区群组，实时掌握最新动态。' : 'Important: If you cannot add or remove DNS records, open a ticket with the domain and error details for help. You can also visit t.cc.cd to join our official TG community for real-time updates.', [], true),
    'supportTitle' => cfclient_lang('cfclient.extras.support.title', $isClientLanguageChinese ? '需要帮助？' : 'Need help?', [], true),

    'supportTicket' => cfclient_lang('cfclient.extras.support.ticket', $isClientLanguageChinese ? '提交工单' : 'Open Ticket', [], true),
    'supportAppeal' => cfclient_lang('cfclient.extras.support.appeal', $isClientLanguageChinese ? '提交封禁申诉工单' : 'Submit Ban Appeal', [], true),
    'supportKb' => cfclient_lang('cfclient.extras.support.kb', $isClientLanguageChinese ? '知识库' : 'Knowledgebase', [], true),
    'supportContact' => cfclient_lang('cfclient.extras.support.contact', $isClientLanguageChinese ? '联系我们' : 'Contact Us', [], true),
    'backToPortal' => cfclient_lang('cfclient.extras.back_to_portal', $isClientLanguageChinese ? '返回客户中心' : 'Back to Client Area', [], true),
];
$deleteTipKey = !empty($clientDeleteEnabled) ? 'cfclient.extras.tips.domain.delete_enabled' : 'cfclient.extras.tips.domain.delete';
$deleteTipDefault = !empty($clientDeleteEnabled)
    ? ($isClientLanguageChinese ? '域名删除：可在“查看详情”中提交自助删除申请。' : 'Deletion: submit a self-service request under “View details”.')
    : ($isClientLanguageChinese ? '域名删除：域名成功注册后不支持删除！' : 'Deletion: once registered, domains cannot be removed.');
$extrasList = [
    'domain' => [
        cfclient_lang('cfclient.extras.tips.domain.transfer', '域名转赠：域名转赠成功后无法撤回操作，请在分享前确认。', [], true),
        cfclient_lang('cfclient.extras.tips.domain.content', '禁止内容：域名禁止用于任何违法违规行为,一经发现立即封禁!', [], true),
        cfclient_lang('cfclient.extras.tips.domain.renewal', '域名续期：系统将在域名到期前 180 天之内开启免费续期通道。您可通过管理控制面板或 API 接口实现一键续期。', [], true),
        cfclient_lang($deleteTipKey, $deleteTipDefault, [], true),
    ],
    'dns' => [
        cfclient_lang('cfclient.extras.tips.dns.root', '@ 记录：表示域名本身（如 blog.example.com）', [], true),
        cfclient_lang('cfclient.extras.tips.dns.propagation', 'DNS解析：DNS记录修改可能需要几分钟时间生效，请耐心等待。', [], true),
        cfclient_lang('cfclient.extras.tips.dns.line', '线路限制：部分域名不支持按运营商或地域返回不同的 DNS 记录。', [], true),
        cfclient_lang('cfclient.extras.tips.dns.error', '解析错误：如遇解析错误,无法解析的情况可以提交工单联系客服获取帮助！', [], true),
    ],
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
?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-info text-white">
                <h6 class="card-title mb-0">
                    <i class="fas fa-lightbulb"></i> <?php echo $extrasTexts['tipsTitle']; ?>
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary"><?php echo $extrasTexts['domainTitle']; ?></h6>
                        <ul class="list-unstyled">
                            <?php foreach ($extrasList['domain'] as $item): ?>
                                <li><?php echo $item; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-success"><?php echo $extrasTexts['dnsTitle']; ?></h6>
                        <ul class="list-unstyled">
                            <?php foreach ($extrasList['dns'] as $item): ?>
                                <li><?php echo $item; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <div class="alert alert-warning mt-3 mb-0" id="dnsTimeoutWarning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong><?php echo $extrasTexts['warning']; ?></strong>
                </div>
                <div class="alert alert-info mt-2 mb-0" id="dnsSupportWarning">
                    <i class="fas fa-life-ring"></i>
                    <strong><?php echo $extrasTexts['warningExtra']; ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 工单入口导航 -->
<div class="row mt-5 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <h6 class="card-title text-primary mb-3">
                    <i class="fas fa-life-ring"></i> <?php echo $extrasTexts['supportTitle']; ?>
                </h6>
                <p class="text-muted mb-3"><?php echo $extrasTexts['supportBody']; ?></p>
                <div class="d-flex justify-content-center gap-3">
                    <?php if (!empty($isUserBannedOrInactive) && $isUserBannedOrInactive): ?>
                        <a href="submitticket.php?step=2&deptid=1&subject=<?php echo urlencode($banAppealSubject); ?>&message=<?php echo urlencode($banAppealMessage); ?>" class="btn btn-danger btn-custom">
                            <i class="fas fa-gavel"></i> <?php echo $extrasTexts['supportAppeal']; ?>
                        </a>
                    <?php else: ?>
                        <a href="submitticket.php" class="btn btn-primary btn-custom">
                            <i class="fas fa-ticket-alt"></i> <?php echo $extrasTexts['supportTicket']; ?>
                        </a>
                    <?php endif; ?>
                    <a href="knowledgebase.php" class="btn btn-outline-primary btn-custom">
                        <i class="fas fa-book"></i> <?php echo $extrasTexts['supportKb']; ?>
                   <a href="https://t.me/+l9I5TNRDLP5lZDBh" 
   class="btn btn-outline-secondary btn-custom"
   target="_blank" 
   rel="noopener noreferrer">
    <i class="fa-brands fa-telegram"></i> <?php echo $extrasTexts['supportContact']; ?>
</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 底部导航 -->
<div class="row mt-4">
    <div class="col-12">
        <div class="text-center">
            <a href="index.php" class="btn btn-outline-secondary btn-custom">
                <i class="fas fa-arrow-left"></i> <?php echo $extrasTexts['backToPortal']; ?>
            </a>
        </div>
    </div>
</div>
