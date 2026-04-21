<?php
$giftIsChinese = strtolower((string) ($currentClientLanguage ?? 'english')) === 'chinese';
$giftText = static function (string $key, string $zh, string $en) use ($giftIsChinese): string {
    return cfclient_lang($key, $giftIsChinese ? $zh : $en, [], true);
};
?>

<?php if (!empty($domainGiftEnabled)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
                <div>
                    <h5 class="card-title mb-1">
                        <i class="fas fa-exchange-alt text-primary me-2"></i><?php echo $giftText('cfclient.gift_center.title', '域名转赠中心', 'Domain Transfer Center'); ?>
                    </h5>
                    <div class="text-muted small"><?php echo $giftText('cfclient.gift_center.desc', '在这里发起转赠、领取接收码并管理历史记录。', 'Initiate transfers, accept codes, and manage transfer history here.'); ?></div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary" onclick="openDomainGiftModal('initiate')">
                        <i class="fas fa-paper-plane me-1"></i><?php echo $giftText('cfclient.gift_center.button.initiate', '发起转赠', 'Initiate Transfer'); ?>
                    </button>
                    <button type="button" class="btn btn-outline-success" onclick="openDomainGiftModal('accept')">
                        <i class="fas fa-download me-1"></i><?php echo $giftText('cfclient.gift_center.button.accept', '接收转赠', 'Accept Transfer'); ?>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="openDomainGiftModal('history')">
                        <i class="fas fa-history me-1"></i><?php echo $giftText('cfclient.gift_center.button.history', '查看历史', 'View History'); ?>
                    </button>
                </div>
            </div>

            <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle me-1"></i>
                <?php echo $giftText('cfclient.gift_center.notice', '提示：域名转赠成功后将自动变更归属，请在分享接收码前确认对象。', 'Note: ownership changes immediately after transfer acceptance. Confirm recipient before sharing the code.'); ?>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-1"></i><?php echo $giftText('cfclient.gift_center.disabled', '当前未启用域名转赠功能。', 'Domain transfer feature is currently disabled.'); ?>
    </div>
<?php endif; ?>
