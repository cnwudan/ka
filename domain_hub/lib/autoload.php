<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    static $classMap = [
        'CfSettingsRepository' => __DIR__ . '/Repositories/SettingsRepository.php',
        'CfCsrf' => __DIR__ . '/Support/Csrf.php',
        'CfProviderService' => __DIR__ . '/Services/ProviderService.php',
        'CfQuotaRepository' => __DIR__ . '/Repositories/QuotaRepository.php',
        'CfQuotaRedeemService' => __DIR__ . '/Services/QuotaRedeemService.php',
        'CfSubdomainService' => __DIR__ . '/Services/SubdomainService.php',
        'CfLoggingService' => __DIR__ . '/Services/LoggingService.php',
        'CfAdminController' => __DIR__ . '/Http/AdminController.php',
        'CfClientController' => __DIR__ . '/Http/ClientController.php',
        'CfApiDispatcher' => __DIR__ . '/Http/ApiDispatcher.php',
        'CfClientViewModelBuilder' => __DIR__ . '/Services/ClientViewModelBuilder.php',
        'CfClientActionService' => __DIR__ . '/Services/ClientActionService.php',
        'CfAsyncDnsJobService' => __DIR__ . '/Services/AsyncDnsJobService.php',
        'CfAdminActionService' => __DIR__ . '/Services/AdminActionService.php',
        'CfAdminViewModelBuilder' => __DIR__ . '/Services/AdminViewModelBuilder.php',
        'CfAdminStatsSnapshotService' => __DIR__ . '/Services/AdminStatsSnapshotService.php',
        'CfDnsUnlockService' => __DIR__ . '/Services/DnsUnlockService.php',
        'CfVpnDetectionService' => __DIR__ . '/Services/VpnDetectionService.php',
        'CfInviteRegistrationService' => __DIR__ . '/Services/InviteRegistrationService.php',
        'CfInviteRegistrationGithubService' => __DIR__ . '/Services/InviteRegistrationGithubService.php',
        'CfRootdomainInviteService' => __DIR__ . '/Services/RootdomainInviteService.php',
        'CfRenewalNoticeService' => __DIR__ . '/Services/RenewalNoticeService.php',
        'CfGithubStarRewardService' => __DIR__ . '/Services/GithubStarRewardService.php',
        'CfTelegramGroupRewardService' => __DIR__ . '/Services/TelegramGroupRewardService.php',
        'CfTelegramExpiryReminderService' => __DIR__ . '/Services/TelegramExpiryReminderService.php',
        'CfSslCertificateService' => __DIR__ . '/Services/SslCertificateService.php',
        'CfWhoisService' => __DIR__ . '/Services/WhoisService.php',
        'CfDigService' => __DIR__ . '/Services/DigService.php',
        'CfAiHelpSearchService' => __DIR__ . '/Services/AiHelpSearchService.php',

        'CfRateLimiter' => __DIR__ . '/Services/RateLimiter.php',
        'CfModuleSettings' => __DIR__ . '/Support/ModuleSettings.php',
        'CfModuleInstaller' => __DIR__ . '/Setup/ModuleInstaller.php',
        'CfHookRegistrar' => __DIR__ . '/Hooks/Registrar.php',
        'CfApiRouter' => __DIR__ . '/Support/ApiRouter.php',
    ];

    if (isset($classMap[$class])) {
        $path = $classMap[$class];
        if (is_file($path)) {
            require_once $path;
        }
    }
});

require_once __DIR__ . '/Services/LoggingService.php';
require_once __DIR__ . '/Support/SecuritySupport.php';
require_once __DIR__ . '/Support/LoggingSupport.php';
require_once __DIR__ . '/Support/BanSupport.php';
require_once __DIR__ . '/Support/QuotaSupport.php';
require_once __DIR__ . '/Support/ClientTemplateHelpers.php';
require_once __DIR__ . '/Support/I18n.php';
