<?php
$partnerIsChinese = strtolower((string) ($currentClientLanguage ?? 'english')) === 'chinese';
$partnerText = static function (string $key, string $zh, string $en, array $params = [], bool $escape = true) use ($partnerIsChinese): string {
    return cfclient_lang($key, $partnerIsChinese ? $zh : $en, $params, $escape);
};

$partnerWebsiteValue = '';
$partnerReasonValue = '';
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'submit_partner_application') {
    $partnerWebsiteValue = trim((string) ($_POST['partner_website'] ?? ''));
    $partnerReasonValue = trim((string) ($_POST['partner_reason'] ?? ''));
}

$resolveBilingualText = static function (string $raw, string $fallbackZh, string $fallbackEn = '') use ($partnerIsChinese): string {
    $raw = trim($raw);
    if ($raw === '') {
        return $partnerIsChinese ? $fallbackZh : ($fallbackEn !== '' ? $fallbackEn : $fallbackZh);
    }

    $parts = preg_split('/[，,]/u', $raw, 2) ?: [];
    if (count($parts) >= 2) {
        $zh = trim((string) ($parts[0] ?? ''));
        $en = trim((string) ($parts[1] ?? ''));
        if ($partnerIsChinese) {
            return $zh !== '' ? $zh : ($en !== '' ? $en : $fallbackZh);
        }
        return $en !== '' ? $en : ($zh !== '' ? $zh : ($fallbackEn !== '' ? $fallbackEn : $fallbackZh));
    }

    return $raw;
};

$sponsorTitle = $resolveBilingualText(
    (string) ($module_settings['sponsor_title'] ?? ''),
    '赞助 DNSHE',
    'Support DNSHE'
);
$sponsorDescription = $resolveBilingualText(
    (string) ($module_settings['sponsor_description'] ?? ''),
    'DNSHE 的成长离不开社区的支持。你的每一份赞助都将用于支付服务器与根域名的续费开支。',
    'DNSHE grows with community support. Every sponsorship helps cover server and root domain renewal costs.'
);

$sponsorMethodRows = [];
$rawSponsorMethods = trim((string) ($module_settings['sponsor_methods'] ?? ''));
if ($rawSponsorMethods !== '') {
    $lines = preg_split('/\r?\n/', $rawSponsorMethods) ?: [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $parts = explode('|', $line, 2);
        $labelRaw = trim((string) ($parts[0] ?? ''));
        $url = trim((string) ($parts[1] ?? ''));
        if ($labelRaw === '') {
            continue;
        }
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = '';
        }
        $sponsorMethodRows[] = [
            'label' => $resolveBilingualText($labelRaw, $labelRaw, $labelRaw),
            'url' => $url,
        ];
    }
}
if (empty($sponsorMethodRows)) {
    $sponsorMethodRows = [
        ['label' => 'USDT', 'url' => ''],
        ['label' => $partnerIsChinese ? '服务器赞助' : 'Server Sponsorship', 'url' => ''],
    ];
}
?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="fas fa-handshake text-primary me-2"></i><?php echo $partnerText('cfclient.partner.title', '合作伙伴计划', 'Partner Program'); ?>
                </h5>
                <p class="text-muted mb-3">
                    <?php echo $partnerText('cfclient.partner.desc', '欢迎申请 DNSHE 域名经销合作。请填写您的网站与申请原因，提交后系统将通过邮件发送至管理员进行审核。', 'Apply for DNSHE reseller cooperation. Fill in your website and reason; the application will be emailed to the administrator for review.'); ?>
                </p>

                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="submit_partner_application">
                    <input type="hidden" name="view" value="partner">
                    <div class="col-12">
                        <label class="form-label" for="partner_website_input"><?php echo $partnerText('cfclient.partner.form.website', '您的网站', 'Your Website'); ?></label>
                        <input
                            type="text"
                            class="form-control"
                            id="partner_website_input"
                            name="partner_website"
                            placeholder="https://example.com"
                            value="<?php echo htmlspecialchars($partnerWebsiteValue, ENT_QUOTES); ?>"
                            maxlength="255"
                            required
                        >
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="partner_reason_input"><?php echo $partnerText('cfclient.partner.form.reason', '申请原因', 'Reason for Application'); ?></label>
                        <textarea
                            class="form-control"
                            id="partner_reason_input"
                            name="partner_reason"
                            rows="5"
                            maxlength="3000"
                            required
                        ><?php echo htmlspecialchars($partnerReasonValue, ENT_QUOTES); ?></textarea>
                        <div class="form-text">
                            <?php echo $partnerText('cfclient.partner.form.reason_hint', '请简要介绍您的业务方向、受众与合作诉求（至少 10 个字符）。', 'Briefly describe your business direction, audience, and cooperation request (minimum 10 characters).'); ?>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-custom">
                            <i class="fas fa-paper-plane me-1"></i><?php echo $partnerText('cfclient.partner.form.submit', '提交合作申请', 'Submit Application'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="fas fa-heart text-danger me-2"></i><?php echo htmlspecialchars($sponsorTitle, ENT_QUOTES); ?>
                </h5>
                <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars($sponsorDescription, ENT_QUOTES)); ?></p>

                <div class="list-group list-group-flush">
                    <?php foreach ($sponsorMethodRows as $method): ?>
                        <div class="list-group-item px-0 border-0 d-flex align-items-center justify-content-between">
                            <span><i class="fas fa-gift text-warning me-2"></i><?php echo htmlspecialchars((string) $method['label'], ENT_QUOTES); ?></span>
                            <?php if (!empty($method['url'])): ?>
                                <a href="<?php echo htmlspecialchars((string) $method['url'], ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">
                                    <?php echo $partnerText('cfclient.partner.sponsor.open', '查看', 'Open'); ?>
                                </a>
                            <?php else: ?>
                                <span class="badge bg-light text-muted"><?php echo $partnerText('cfclient.partner.sponsor.contact', '联系支持', 'Contact Support'); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
