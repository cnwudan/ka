<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../AtomicOperations.php';
require_once __DIR__ . '/../ErrorFormatter.php';
require_once __DIR__ . '/../TtlHelper.php';
require_once __DIR__ . '/../RootDomainLimitHelper.php';
require_once __DIR__ . '/../ProviderResolver.php';
require_once __DIR__ . '/DnsUnlockService.php';
require_once __DIR__ . '/InviteRegistrationService.php';

class CfClientActionService
{
    public static function process(array $globals): array
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return [];
        }

        if (!array_key_exists('action', $_POST)) {
            return [];
        }

        $action = (string) $_POST['action'];
        if ($action === '' || in_array($action, ['__csrf_failed__', '__maintenance__', '__banned__'], true)) {
            return [];
        }

        $isAsyncReplay = !empty($_POST['__cf_async_dns']);
        if ($isAsyncReplay) {
            unset($_POST['__cf_async_dns']);
        }

        extract($globals, EXTR_SKIP);

        $module_settings = $module_settings ?? [];
        $resolvedUserId = intval($userid ?? 0);
        $isPrivilegedUser = $resolvedUserId > 0
            && function_exists('cf_is_user_privileged')
            && cf_is_user_privileged($resolvedUserId);
        $privilegedAllowRegisterSuspendedRoot = $isPrivilegedUser
            && function_exists('cf_is_privileged_feature_enabled')
            && cf_is_privileged_feature_enabled('allow_register_suspended_root', $module_settings);
        $privilegedAllowDeleteWithDnsHistory = $isPrivilegedUser
            && function_exists('cf_is_privileged_feature_enabled')
            && cf_is_privileged_feature_enabled('allow_delete_with_dns_history', $module_settings);
        $enableDnsUnlockFeature = cfmod_setting_enabled($module_settings['enable_dns_unlock'] ?? '0');
        $dnsUnlockPurchaseEnabled = cfmod_setting_enabled($module_settings['dns_unlock_purchase_enabled'] ?? '0');
        $dnsUnlockShareEnabled = cfmod_setting_enabled($module_settings['dns_unlock_share_enabled'] ?? '1');
        $dnsUnlockPurchasePrice = round(max(0, (float)($module_settings['dns_unlock_purchase_price'] ?? 0)), 2);
        $enablePermanentUpgrade = cfmod_setting_enabled($module_settings['enable_domain_permanent_upgrade'] ?? '0');
        $domainPermanentUpgradePrice = round(max(0, (float) ($module_settings['domain_permanent_upgrade_price'] ?? 0)), 2);
        $msg = $globals['msg'] ?? '';
        $msg_type = $globals['msg_type'] ?? '';
        $registerError = $globals['registerError'] ?? '';

        try {
            self::enforceClientRateLimit($action, $module_settings, intval($userid ?? 0));
        } catch (CfRateLimitExceededException $e) {
            $minutes = CfRateLimiter::formatRetryMinutes($e->getRetryAfterSeconds());
            $rateMessage = self::actionText('rate_limit.hit', '操作频率过高，请 %s 分钟后再试。', [$minutes]);
            if ($action === 'register') {
                $registerError = $rateMessage;
            } else {
                $msg = $rateMessage;
                $msg_type = 'danger';
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'purchase_dns_unlock') {
            if (!$enableDnsUnlockFeature) {
                $msg = self::actionText('dns.unlock.disabled', '当前未启用 DNS 解锁功能。');
                $msg_type = 'warning';
            } elseif (!$dnsUnlockPurchaseEnabled) {
                $msg = self::actionText('dns.unlock.purchase_disabled', 'Paid unlock is disabled.');
                $msg_type = 'warning';
            } elseif ($dnsUnlockPurchasePrice <= 0) {
                $msg = self::actionText('dns.unlock.purchase_invalid_price', 'Unlock price is not configured.');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionText('dns.unlock.invalid', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } elseif (CfDnsUnlockService::userHasUnlocked($userid ?? 0)) {
                $msg = self::actionText('dns.unlock.already', '您已完成 DNS 解锁，无需再次操作。');
                $msg_type = 'info';
            } else {
                try {
                    $email = self::resolveClientEmail($userid);
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    Capsule::transaction(function () use ($userid, $dnsUnlockPurchasePrice, $email, $ip) {
                        $clientRow = Capsule::table('tblclients')->where('id', $userid)->lockForUpdate()->first();
                        if (!$clientRow) {
                            throw new \RuntimeException('client_missing');
                        }
                        $currentCredit = (float) ($clientRow->credit ?? 0);
                        if (($currentCredit + 1e-6) < $dnsUnlockPurchasePrice) {
                            throw new \RuntimeException('insufficient_credit');
                        }
                        $newCredit = $currentCredit - $dnsUnlockPurchasePrice;
                        Capsule::table('tblclients')
                            ->where('id', $userid)
                            ->update(['credit' => number_format($newCredit, 2, '.', '')]);
                        static $creditSchemaInfoPurchase = null;
                        if ($creditSchemaInfoPurchase === null) {
                            $creditSchemaInfoPurchase = [
                                'has_table' => false,
                                'has_relid' => false,
                                'has_refundid' => false,
                            ];
                            try {
                                $creditSchemaInfoPurchase['has_table'] = Capsule::schema()->hasTable('tblcredit');
                                if ($creditSchemaInfoPurchase['has_table']) {
                                    $creditSchemaInfoPurchase['has_relid'] = Capsule::schema()->hasColumn('tblcredit', 'relid');
                                    $creditSchemaInfoPurchase['has_refundid'] = Capsule::schema()->hasColumn('tblcredit', 'refundid');
                                }
                            } catch (\Throwable $ignored) {
                                $creditSchemaInfoPurchase = [
                                    'has_table' => false,
                                    'has_relid' => false,
                                    'has_refundid' => false,
                                ];
                            }
                        }
                        if ($creditSchemaInfoPurchase['has_table']) {
                            $creditRow = [
                                'clientid' => $userid,
                                'date' => date('Y-m-d H:i:s'),
                                'description' => self::actionText('dns.unlock.purchase_credit_desc', 'DNS unlock purchase'),
                                'amount' => 0 - $dnsUnlockPurchasePrice,
                            ];
                            if ($creditSchemaInfoPurchase['has_relid']) {
                                $creditRow['relid'] = 0;
                            }
                            if ($creditSchemaInfoPurchase['has_refundid']) {
                                $creditRow['refundid'] = 0;
                            }
                            Capsule::table('tblcredit')->insert($creditRow);
                        }
                        CfDnsUnlockService::unlockByPurchase($userid, $email, $ip);
                    });
                    $msg = self::actionText('dns.unlock.purchase_success', 'DNS unlock completed via account balance.');
                    $msg_type = 'success';
                } catch (\RuntimeException $e) {
                    if ($e->getMessage() === 'insufficient_credit') {
                        $msg = self::actionText('dns.unlock.purchase_insufficient', 'Insufficient balance for unlock.');
                        $msg_type = 'danger';
                    } else {
                        $msg = self::actionText('dns.unlock.error', '解锁失败：%s', [$e->getMessage()]);
                        $msg_type = 'danger';
                    }
                } catch (\InvalidArgumentException $e) {
                    $reason = $e->getMessage();
                    if ($reason === 'owner_banned') {
                        $msg = self::actionText('dns.unlock.owner_banned', '该解锁码已失效，请联系管理员。');
                        $msg_type = 'danger';
                    } else {
                        $msg = self::actionText('dns.unlock.already', '您已完成 DNS 解锁，无需再次操作。');
                        $msg_type = 'info';
                    }
                } catch (\Throwable $e) {
                    $msg = self::actionText('dns.unlock.error', '解锁失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'dns_unlock') {
            if (!$enableDnsUnlockFeature) {
                $msg = self::actionText('dns.unlock.disabled', '当前未启用 DNS 解锁功能。');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionText('dns.unlock.invalid', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } elseif (!$dnsUnlockShareEnabled) {
                $msg = self::actionText('dns.unlock.share_disabled', '管理员已关闭解锁码分享，请使用余额解锁。');
                $msg_type = 'warning';
            } else {
                $inputCode = strtoupper(trim($_POST['unlock_code'] ?? ''));
                if ($inputCode === '') {
                    $msg = self::actionText('dns.unlock.code_required', '请输入解锁码后再提交。');
                    $msg_type = 'warning';
                } else {
                    try {
                        $email = self::resolveClientEmail($userid);
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        CfDnsUnlockService::unlockForUser($userid, $inputCode, $userid, $email, $ip);
                        $msg = self::actionText('dns.unlock.success', 'DNS 解锁成功，现在可以设置 NS 服务器。');
                        $msg_type = 'success';
                    } catch (\InvalidArgumentException $e) {
                        $reason = $e->getMessage();
                        if ($reason === 'self_code') {
                            $msg = self::actionText('dns.unlock.self', '不能使用自己的解锁码。');
                        } elseif ($reason === 'invalid_code') {
                            $msg = self::actionText('dns.unlock.invalid', '解锁码不正确，请核对后重试。');
                        } elseif ($reason === 'owner_banned') {
                            $msg = self::actionText('dns.unlock.owner_banned', '该解锁码已失效，请联系管理员。');
                        } elseif ($reason === 'already_unlocked') {
                            $msg = self::actionText('dns.unlock.already', '您已完成 DNS 解锁，无需再次操作。');
                        } else {
                            $msg = self::actionText('dns.unlock.error', '解锁失败：%s', [$reason]);
                        }
                        $msg_type = 'danger';
                    } catch (\Throwable $e) {
                        $msg = self::actionText('dns.unlock.error', '解锁失败：%s', [$e->getMessage()]);
                        $msg_type = 'danger';
                    }
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        // 处理 Telegram 静默准入验证
        if ($_POST['action'] === 'invite_registration_telegram_unlock') {
            $inviteRegistrationEnabled = CfInviteRegistrationService::isGateEnabled($module_settings);
            $telegramOptionEnabled = CfInviteRegistrationService::isTelegramOptionEnabled($module_settings);
            if (!$inviteRegistrationEnabled) {
                $msg = self::actionText('invite_registration.disabled', '当前未启用邀请注册功能。');
                $msg_type = 'warning';
            } elseif (!$telegramOptionEnabled) {
                $msg = self::actionText('invite_registration.telegram_option_disabled', '当前准入模式未启用 Telegram 静默验证，或管理员尚未完成 Telegram 配置。');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } else {
                $authPayload = [
                    'id' => trim((string) ($_POST['telegram_auth_id'] ?? $_POST['telegram_id'] ?? '')),
                    'username' => trim((string) ($_POST['telegram_auth_username'] ?? $_POST['telegram_username'] ?? '')),
                    'first_name' => trim((string) ($_POST['telegram_auth_first_name'] ?? '')),
                    'last_name' => trim((string) ($_POST['telegram_auth_last_name'] ?? '')),
                    'photo_url' => trim((string) ($_POST['telegram_auth_photo_url'] ?? '')),
                    'auth_date' => trim((string) ($_POST['telegram_auth_date'] ?? '')),
                    'hash' => trim((string) ($_POST['telegram_auth_hash'] ?? '')),
                ];

                try {
                    CfInviteRegistrationService::unlockByTelegram((int) $userid, $module_settings, $authPayload);
                    $msg = self::actionText('invite_registration.telegram_success', 'Telegram 准入验证成功，现在可以正常使用。');
                    $msg_type = 'success';
                } catch (\InvalidArgumentException $e) {
                    $reason = trim((string) $e->getMessage());
                    if ($reason === 'telegram_auth_expired') {
                        $msg = self::actionText('invite_registration.telegram_auth_expired', 'Telegram 授权已过期，请重新点击 Telegram 按钮授权。');
                    } elseif ($reason === 'telegram_auth_invalid') {
                        $msg = self::actionText('invite_registration.telegram_auth_invalid', 'Telegram 授权信息无效，请重新授权后重试。');
                    } elseif ($reason === 'telegram_used') {
                        $msg = self::actionText('invite_registration.telegram_used', '该 Telegram 账号已被其他用户绑定，请使用自己的 Telegram 账号。');
                    } elseif ($reason === 'telegram_invalid_config' || $reason === 'telegram_not_enabled') {
                        $msg = self::actionText('invite_registration.telegram_invalid_config', '管理员尚未完成 Telegram 准入配置，请稍后再试。');
                    } elseif ($reason === 'invalid_user') {
                        $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                    } else {
                        $msg = self::actionText('invite_registration.telegram_error', 'Telegram 验证失败：%s', [$reason]);
                    }
                    $msg_type = 'danger';
                } catch (\Throwable $e) {
                    $msg = self::actionText('invite_registration.telegram_error', 'Telegram 验证失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        // 处理邀请注册解锁
        if ($_POST['action'] === 'invite_registration_unlock') {
            $inviteRegistrationEnabled = CfInviteRegistrationService::isGateEnabled($module_settings);
            $inviteOptionEnabled = CfInviteRegistrationService::isInviteOptionEnabled($module_settings);
            if (!$inviteRegistrationEnabled) {
                $msg = self::actionText('invite_registration.disabled', '当前未启用邀请注册功能。');
                $msg_type = 'warning';
            } elseif (!$inviteOptionEnabled) {
                $msg = self::actionText('invite_registration.invite_option_disabled', '当前准入模式不支持邀请码验证，请使用可用的 GitHub / Telegram 认证方式。');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } else {
                $inputCode = strtoupper(trim($_POST['invite_reg_code'] ?? ''));
                if ($inputCode === '') {
                    $msg = self::actionText('invite_registration.code_required', '请输入邀请码后再提交。');
                    $msg_type = 'warning';
                } else {
                    try {
                        $email = self::resolveClientEmail($userid);
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        CfInviteRegistrationService::unlockForUser($userid, $inputCode, $email, $ip);
                        $msg = self::actionText('invite_registration.success', '邀请注册验证成功，现在可以正常使用。');
                        $msg_type = 'success';
                    } catch (\InvalidArgumentException $e) {
                        $reason = $e->getMessage();
                        if ($reason === 'self_code') {
                            $msg = self::actionText('invite_registration.self', '不能使用自己的邀请码。');
                        } elseif ($reason === 'invalid_code') {
                            $msg = self::actionText('invite_registration.invalid', '邀请码不正确，请核对后重试。');
                        } elseif ($reason === 'inviter_banned') {
                            $msg = self::actionText('invite_registration.inviter_banned', '该邀请码已失效，请联系管理员。');
                        } elseif ($reason === 'inviter_age_insufficient') {
                            $requiredMonths = class_exists('CfInviteRegistrationService')
                                ? CfInviteRegistrationService::getInviterMinMonthsRequirement()
                                : 0;
                            $requiredMonths = max(0, intval($requiredMonths));
                            $msg = self::actionText('invite_registration.inviter_age_insufficient', '该邀请码发放者未满足最低月龄要求（至少 %s 个月），请使用其他邀请码。', [$requiredMonths]);
                        } elseif ($reason === 'already_unlocked') {
                            $msg = self::actionText('invite_registration.already', '您已完成邀请注册验证，无需再次操作。');
                        } elseif ($reason === 'inviter_limit_reached') {
                            $msg = self::actionText('invite_registration.inviter_limit', '该邀请码发放者已达到邀请上限，请使用其他邀请码。');
                        } elseif ($reason === 'invalid_user') {
                            $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                        } else {
                            $msg = self::actionText('invite_registration.error', '验证失败：%s', [$reason]);
                        }
                        $msg_type = 'danger';
                    } catch (\Throwable $e) {
                        $msg = self::actionText('invite_registration.error', '验证失败：%s', [$e->getMessage()]);
                        $msg_type = 'danger';
                    }
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }


        if ($_POST['action'] === 'submit_partner_application') {
            if ($userid <= 0) {
                $msg = self::actionText('partner.invalid_user', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } elseif ($isUserBannedOrInactive) {
                $msg = self::actionText('partner.banned', '您的账号已被封禁或停用，暂无法提交合作申请。') . ($banReasonText ? (' ' . $banReasonText) : '');
                $msg_type = 'danger';
            } else {
                $websiteRaw = trim((string) ($_POST['partner_website'] ?? ''));
                $reasonRaw = trim((string) ($_POST['partner_reason'] ?? ''));

                $website = self::normalizePartnerWebsite($websiteRaw);
                if ($website === '') {
                    $msg = self::actionText('partner.invalid_website', '请填写有效的网站地址（例如 https://example.com）。');
                    $msg_type = 'warning';
                } elseif (function_exists('mb_strlen') ? mb_strlen($reasonRaw, 'UTF-8') < 10 : strlen($reasonRaw) < 10) {
                    $msg = self::actionText('partner.invalid_reason', '申请原因过短，请至少填写 10 个字符。');
                    $msg_type = 'warning';
                } else {
                    $recipients = self::resolvePartnerAdminRecipients($module_settings);
                    if (empty($recipients)) {
                        $msg = self::actionText('partner.email_not_configured', '管理员尚未配置合作伙伴计划接收邮箱，请稍后再试。');
                        $msg_type = 'warning';
                    } else {
                        try {
                            $clientEmail = self::resolveClientEmail((int) $userid);
                            $client = Capsule::table('tblclients')
                                ->select('firstname', 'lastname')
                                ->where('id', (int) $userid)
                                ->first();
                            $clientName = trim((string) (($client->firstname ?? '') . ' ' . ($client->lastname ?? '')));
                            $submittedAt = date('Y-m-d H:i:s');
                            $subject = self::actionText('partner.email_subject', '[DNSHE] 新合作伙伴申请 - %s', [$website]);
                            $body = self::buildPartnerApplicationEmailBody([
                                'userid' => (int) $userid,
                                'client_name' => $clientName,
                                'client_email' => $clientEmail,
                                'website' => $website,
                                'reason' => $reasonRaw,
                                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                                'submitted_at' => $submittedAt,
                            ]);
                            self::sendPartnerApplicationEmail($recipients, $subject, $body, $clientEmail);

                            if (function_exists('cloudflare_subdomain_log')) {
                                cloudflare_subdomain_log('partner_plan_application', [
                                    'website' => $website,
                                    'reason' => $reasonRaw,
                                    'submit_to' => $recipients,
                                ], (int) $userid, null);
                            }

                            $msg = self::actionText('partner.submit_success', '申请已提交成功，我们会在审核后尽快与您联系。');
                            $msg_type = 'success';
                        } catch (\Throwable $e) {
                            $msg = self::actionText('partner.submit_failed', '申请提交失败：%s', [$e->getMessage()]);
                            $msg_type = 'danger';
                        }
                    }
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'claim_github_star_reward') {
            try {
                if (!class_exists('CfGithubStarRewardService')) {
                    throw new \RuntimeException('service_missing');
                }
                $githubUsername = trim((string) ($_POST['github_username'] ?? ''));
                $result = CfGithubStarRewardService::claim(
                    intval($userid ?? 0),
                    $module_settings,
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    $githubUsername
                );
                $msg = self::actionTextByLanguage('github_star_reward.success', '感谢支持！已奖励 %s 个注册额度。', 'Thanks for your support! You have received %s additional registration quota.', [intval($result['reward_amount'] ?? 0)]);
                $msg_type = 'success';
            } catch (CfGithubStarRewardException $e) {
                $reason = $e->getReason();
                if ($reason === 'disabled') {
                    $msg = self::actionTextByLanguage('github_star_reward.disabled', '当前未启用 GitHub 点赞奖励功能。', 'GitHub star reward is currently disabled.');
                } elseif ($reason === 'invalid_repo') {
                    $msg = self::actionTextByLanguage('github_star_reward.invalid_repo', '管理员尚未配置有效的 GitHub 仓库地址。', 'The administrator has not configured a valid GitHub repository URL yet.');
                } elseif ($reason === 'invalid_username') {
                    $msg = self::actionTextByLanguage('github_star_reward.invalid_username', '请输入有效的 GitHub 用户名后再领取。', 'Please enter a valid GitHub username before claiming.');
                } elseif ($reason === 'username_used') {
                    $msg = self::actionTextByLanguage('github_star_reward.username_used', '该 GitHub 用户名已被其他账号用于领取奖励，请使用您自己的账号。', 'This GitHub username has already been used by another account. Please use your own GitHub account.');
                } elseif ($reason === 'not_starred') {
                    $msg = self::actionTextByLanguage('github_star_reward.not_starred', '系统未核验到该账号已 Star 指定仓库，请完成点赞后再领取（需公开可见）。', 'We could not verify that this account has starred the configured repository. Please star it first and then claim again (must be publicly visible).');
                } elseif ($reason === 'verify_rate_limited') {
                    $msg = self::actionTextByLanguage('github_star_reward.verify_rate_limited', 'GitHub 核验请求过于频繁，请稍后再试。', 'Too many GitHub verification requests. Please try again later.');
                } elseif ($reason === 'verify_failed') {
                    $msg = self::actionTextByLanguage('github_star_reward.verify_failed', '暂时无法完成 GitHub 核验，请稍后再试。', 'GitHub verification is temporarily unavailable. Please try again later.');
                } elseif ($reason === 'already_claimed') {
                    $msg = self::actionTextByLanguage('github_star_reward.already', '您已经领取过该 GitHub 仓库的点赞奖励。', 'You have already claimed the star reward for this repository.');
                } elseif ($reason === 'quota_unavailable') {
                    $msg = self::actionTextByLanguage('github_star_reward.quota_error', '配额信息读取失败，请稍后重试。', 'Unable to load quota data. Please try again later.');
                } elseif ($reason === 'invalid_user') {
                    $msg = self::actionTextByLanguage('github_star_reward.invalid_user', '未找到登录用户，请刷新页面后重试。', 'No logged-in user was found. Please refresh the page and try again.');
                } else {
                    $msg = self::actionTextByLanguage('github_star_reward.error', '领取失败：%s', 'Claim failed: %s', [$e->getMessage()]);
                }
                $msg_type = 'danger';
            } catch (\Throwable $e) {
                $msg = self::actionTextByLanguage('github_star_reward.error', '领取失败：%s', 'Claim failed: %s', [$e->getMessage()]);
                $msg_type = 'danger';
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'claim_telegram_group_reward') {
            try {
                if (!class_exists('CfTelegramGroupRewardService')) {
                    throw new \RuntimeException('service_missing');
                }
                $authPayload = [
                    'id' => trim((string) ($_POST['telegram_auth_id'] ?? $_POST['telegram_id'] ?? '')),
                    'username' => trim((string) ($_POST['telegram_auth_username'] ?? $_POST['telegram_username'] ?? '')),
                    'first_name' => trim((string) ($_POST['telegram_auth_first_name'] ?? '')),
                    'last_name' => trim((string) ($_POST['telegram_auth_last_name'] ?? '')),
                    'photo_url' => trim((string) ($_POST['telegram_auth_photo_url'] ?? '')),
                    'auth_date' => trim((string) ($_POST['telegram_auth_date'] ?? '')),
                    'hash' => trim((string) ($_POST['telegram_auth_hash'] ?? '')),
                ];
                $result = CfTelegramGroupRewardService::claim(
                    intval($userid ?? 0),
                    $module_settings,
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    $authPayload
                );
                $msg = self::actionTextByLanguage(
                    'telegram_group_reward.success',
                    '验证成功！已奖励 %s 个注册额度。',
                    'Verification passed! You have received %s additional registration quota.',
                    [intval($result['reward_amount'] ?? 0)]
                );
                $msg_type = 'success';
            } catch (CfTelegramGroupRewardException $e) {
                $reason = $e->getReason();
                if ($reason === 'disabled') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.disabled', '当前未启用 Telegram 社群奖励功能。', 'Telegram group reward is currently disabled.');
                } elseif ($reason === 'invalid_group_link' || $reason === 'invalid_chat_id' || $reason === 'invalid_bot_token') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.invalid_config', '管理员尚未完成 Telegram 奖励配置，请稍后再试。', 'The Telegram reward settings are incomplete. Please try again later.');
                } elseif ($reason === 'auth_required') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.auth_required', '请先完成 Telegram 授权绑定后再领取奖励。', 'Please complete Telegram authorization before claiming the reward.');
                } elseif ($reason === 'auth_invalid') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.auth_invalid', 'Telegram 授权信息无效，请重新授权后再试。', 'Telegram authorization data is invalid. Please authorize again.');
                } elseif ($reason === 'auth_expired') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.auth_expired', 'Telegram 授权已过期，请重新授权后再试。', 'Telegram authorization has expired. Please authorize again.');
                } elseif ($reason === 'already_claimed') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.already', '您已经领取过 Telegram 社群奖励。', 'You have already claimed the Telegram group reward.');
                } elseif ($reason === 'telegram_used') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.telegram_used', '该 Telegram 账号已被其他用户领取奖励，请使用自己的账号。', 'This Telegram account has already been used by another user to claim rewards.');
                } elseif ($reason === 'not_joined' || $reason === 'member_not_found') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.not_joined', '未检测到您已加入指定社群，请加入后再重试。', 'We could not verify that you joined the required group. Please join and try again.');
                } elseif ($reason === 'verify_rate_limited') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.verify_rate_limited', 'Telegram 校验请求过于频繁，请稍后再试。', 'Too many Telegram verification requests. Please try again later.');
                } elseif ($reason === 'verify_failed') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.verify_failed', '暂时无法完成 Telegram 校验，请稍后再试。', 'Telegram verification is temporarily unavailable. Please try again later.');
                } elseif ($reason === 'quota_unavailable') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.quota_error', '配额信息读取失败，请稍后重试。', 'Unable to load quota data. Please try again later.');
                } elseif ($reason === 'invalid_user') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.invalid_user', '未找到登录用户，请刷新页面后重试。', 'No logged-in user was found. Please refresh the page and try again.');
                } else {
                    $msg = self::actionTextByLanguage('telegram_group_reward.error', '领取失败：%s', 'Claim failed: %s', [$e->getMessage()]);
                }
                $msg_type = 'danger';
            } catch (\Throwable $e) {
                $msg = self::actionTextByLanguage('telegram_group_reward.error', '领取失败：%s', 'Claim failed: %s', [$e->getMessage()]);
                $msg_type = 'danger';
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }


        if ($_POST['action'] === 'update_expiry_telegram_reminder') {
            try {
                if (!class_exists('CfTelegramExpiryReminderService')) {
                    throw new \RuntimeException('service_missing');
                }

                $enableReminder = isset($_POST['expiry_telegram_reminder_enabled'])
                    && (string) $_POST['expiry_telegram_reminder_enabled'] === '1';
                $authPayload = [
                    'id' => trim((string) ($_POST['telegram_auth_id'] ?? $_POST['telegram_id'] ?? '')),
                    'username' => trim((string) ($_POST['telegram_auth_username'] ?? $_POST['telegram_username'] ?? '')),
                    'first_name' => trim((string) ($_POST['telegram_auth_first_name'] ?? '')),
                    'last_name' => trim((string) ($_POST['telegram_auth_last_name'] ?? '')),
                    'photo_url' => trim((string) ($_POST['telegram_auth_photo_url'] ?? '')),
                    'auth_date' => trim((string) ($_POST['telegram_auth_date'] ?? '')),
                    'hash' => trim((string) ($_POST['telegram_auth_hash'] ?? '')),
                ];

                $state = CfTelegramExpiryReminderService::updateUserSubscription(
                    intval($userid ?? 0),
                    $module_settings,
                    $enableReminder,
                    $authPayload
                );

                if (!empty($state['subscribed'])) {
                    $msg = self::actionTextByLanguage(
                        'expiry_telegram_reminder.enabled',
                        'Telegram 到期提醒已开启。',
                        'Telegram expiry reminders are now enabled.'
                    );
                } else {
                    $msg = self::actionTextByLanguage(
                        'expiry_telegram_reminder.disabled',
                        'Telegram 到期提醒已关闭。',
                        'Telegram expiry reminders have been disabled.'
                    );
                }
                $msg_type = 'success';
            } catch (CfTelegramExpiryReminderException $e) {
                $reason = $e->getReason();
                if ($reason === 'feature_disabled') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.feature_disabled', '管理员暂未启用 Telegram 到期提醒。', 'Telegram expiry reminders are currently disabled by the administrator.');
                    $msg_type = 'warning';
                } elseif ($reason === 'auth_required') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.auth_required', '请先完成 Telegram 授权绑定后再开启提醒。', 'Please complete Telegram authorization before enabling reminders.');
                    $msg_type = 'warning';
                } elseif ($reason === 'auth_invalid') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.auth_invalid', 'Telegram 授权信息无效，请重新授权后再试。', 'Telegram authorization data is invalid. Please authorize again.');
                    $msg_type = 'danger';
                } elseif ($reason === 'auth_expired') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.auth_expired', 'Telegram 授权已过期，请重新授权后再试。', 'Telegram authorization has expired. Please authorize again.');
                    $msg_type = 'danger';
                } elseif ($reason === 'telegram_used') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.telegram_used', '该 Telegram 账号已被其他用户绑定，请使用自己的账号。', 'This Telegram account has already been bound by another user. Please use your own Telegram account.');
                    $msg_type = 'danger';
                } elseif ($reason === 'invalid_bot_token') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.invalid_config', '管理员尚未完成 Telegram Bot 配置，请稍后再试。', 'The Telegram bot settings are incomplete. Please try again later.');
                    $msg_type = 'warning';
                } elseif ($reason === 'invalid_user') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.invalid_user', '未找到登录用户，请刷新页面后重试。', 'No logged-in user was found. Please refresh and try again.');
                    $msg_type = 'danger';
                } else {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.error', '设置失败：%s', 'Update failed: %s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            } catch (\Throwable $e) {
                $msg = self::actionTextByLanguage('expiry_telegram_reminder.error', '设置失败：%s', 'Update failed: %s', [$e->getMessage()]);
                $msg_type = 'danger';
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }


        if ($_POST['action'] === 'request_ssl_certificate') {
            try {
                if (!class_exists('CfSslCertificateService')) {
                    throw new \RuntimeException('ssl_service_missing');
                }
                if ($isUserBannedOrInactive) {
                    $msg = self::actionTextByLanguage(
                        'ssl.request.banned',
                        '您的账号已被封禁或停用，暂无法申请 SSL 证书。',
                        'Your account is banned or inactive, so SSL requests are unavailable.'
                    ) . ($banReasonText ? (' ' . $banReasonText) : '');
                    $msg_type = 'danger';
                } else {
                    $sslSubdomainId = intval($_POST['ssl_subdomain_id'] ?? 0);
                    $result = CfSslCertificateService::requestCertificate(
                        intval($userid ?? 0),
                        $sslSubdomainId,
                        $module_settings,
                        (string) ($_SERVER['REMOTE_ADDR'] ?? '')
                    );
                    $msg = self::actionTextByLanguage(
                        'ssl.request.success',
                        'SSL 申请成功：%s，证书已签发并保存。',
                        'SSL request succeeded: %s. The certificate has been issued and saved.',
                        [($result['domain'] ?? '')]
                    );
                    $msg_type = 'success';
                }
            } catch (CfSslCertificateException $e) {
                $reason = $e->getReason();
                if ($reason === 'disabled') {
                    $msg = self::actionTextByLanguage('ssl.request.disabled', '当前未启用 SSL 申请功能。', 'SSL request feature is currently disabled.');
                } elseif ($reason === 'invalid_subdomain' || $reason === 'subdomain_not_found') {
                    $msg = self::actionTextByLanguage('ssl.request.subdomain_invalid', '请选择有效域名后再提交 SSL 申请。', 'Please select a valid domain before submitting the SSL request.');
                } elseif ($reason === 'request_exists') {
                    $msg = self::actionTextByLanguage('ssl.request.exists', '该域名已有进行中的 SSL 申请，请稍后刷新查看结果。', 'An SSL request for this domain is already in progress. Please refresh later.');
                } elseif ($reason === 'subdomain_inactive') {
                    $msg = self::actionTextByLanguage('ssl.request.domain_inactive', '该域名当前状态不支持申请 SSL。', 'The current domain status does not support SSL requests.');
                } elseif ($reason === 'acme_library_missing') {
                    $msg = self::actionTextByLanguage(
                        'ssl.request.acme_library_missing',
                        '未检测到可用 ACME PHP 库，请安装 acme-php/core 或 yourivw/leclient。',
                        'No available ACME PHP library detected. Please install acme-php/core or yourivw/leclient.'
                    );
                } elseif ($reason === 'email_missing') {
                    $msg = self::actionTextByLanguage('ssl.request.email_missing', '管理员尚未配置 Let\'s Encrypt 邮箱，暂无法申请。', 'The administrator has not configured a Let\'s Encrypt email yet.');
                } elseif ($reason === 'acme_issue_failed' || $reason === 'dns_challenge_create_failed' || $reason === 'dns_challenge_missing') {
                    $msg = self::actionTextByLanguage('ssl.request.acme_issue_failed', 'Let\'s Encrypt 申请失败，请稍后重试。', 'Let\'s Encrypt issuance failed. Please try again later.');
                } else {
                    $msg = self::actionTextByLanguage('ssl.request.failed', 'SSL 申请失败：%s', 'SSL request failed: %s', [$e->getMessage()]);
                }
                $msg_type = 'danger';
            } catch (\Throwable $e) {
                $msg = self::actionTextByLanguage('ssl.request.failed', 'SSL 申请失败：%s', 'SSL request failed: %s', [$e->getMessage()]);
                $msg_type = 'danger';
            }

            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

// 处理填写他人邀请码以解锁额度
if($_POST['action'] == 'claim_invite') {
    if ($hideInviteFeature) { $msg = self::actionText('invite.closed', '当前邀请功能已关闭'); $msg_type = 'warning'; }
    else {
    $inputCode = strtoupper(trim($_POST['invite_code'] ?? ''));
    // 动态刷新最新的全局邀请上限，并在每次填码前若用户配额未自定义上限，则对其 invite_bonus_limit 进行同步
    try {
        $inviteLimitGlobalSetting = Capsule::table('tbladdonmodules')
            ->where('module', defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub')
            ->where('setting','invite_bonus_limit_global')
            ->value('value');
        if ($inviteLimitGlobalSetting === null) {
            $inviteLimitGlobalSetting = Capsule::table('tbladdonmodules')
                ->where('module', defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : 'cloudflare_subdomain')
                ->where('setting','invite_bonus_limit_global')
                ->value('value');
        }
        $inviteLimitGlobal = intval($inviteLimitGlobalSetting ?? 5);
        $q = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->first();
        if ($q) {
            $currentLimit = intval($q->invite_bonus_limit ?? 0);
            // 仅在用户当前上限等于默认值（或为0）时，同步为最新全局值，避免覆盖管理员为个别用户单独设置的上限
            if ($currentLimit <= 0 || $currentLimit === 5 || $currentLimit === intval($module_settings['invite_bonus_limit_global'] ?? 5)) {
                Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->update([
                    'invite_bonus_limit' => $inviteLimitGlobal,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->first();
            }
        }
    } catch (Exception $e) { /* 忽略 */ }
    if ($isUserBannedOrInactive) { $msg = self::actionText('invite.banned', '您的账号已被封禁或停用，无法解锁额度。') . ($banReasonText ? (' ' . $banReasonText) : ''); $msg_type = 'danger'; }
    elseif ($inputCode === '') { $msg = self::actionText('invite.input_empty', '请输入邀请码'); $msg_type = 'danger'; }
    else {
        try {
            $result = Capsule::connection()->transaction(function() use ($userid, $inputCode, $max, $inviteLimitGlobal) {
                $codeRow = Capsule::table('mod_cloudflare_invitation_codes')->where('code', $inputCode)->first();
                if (!$codeRow) { throw new Exception(self::actionText('invite.invalid_code', '邀请码无效')); }
                if (intval($codeRow->userid) === intval($userid)) { throw new Exception(self::actionText('invite.self', '不能使用自己的邀请码')); }

                // 受邀者不可重复使用同一个邀请码
                $claimedSameCode = Capsule::table('mod_cloudflare_invitation_claims')
                    ->where('invitee_userid', $userid)
                    ->where('code', $inputCode)
                    ->first();
                if ($claimedSameCode) { throw new Exception(self::actionText('invite.used', '您已使用过该邀请码')); }

                // 确保双方配额记录存在
                $inviterQuota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $codeRow->userid)->first();
                if (!$inviterQuota) {
                    Capsule::table('mod_cloudflare_subdomain_quotas')->insert([
                        'userid' => $codeRow->userid,
                        'used_count' => 0,
                        'max_count' => $max,
                        'invite_bonus_count' => 0,
                        'invite_bonus_limit' => $inviteLimitGlobal > 0 ? $inviteLimitGlobal : 5,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $inviterQuota = (object)['userid'=>$codeRow->userid,'used_count'=>0,'max_count'=>$max,'invite_bonus_count'=>0,'invite_bonus_limit'=>($inviteLimitGlobal > 0 ? $inviteLimitGlobal : 5)];
                }
                $inviteeQuota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->first();
                if (!$inviteeQuota) {
                    Capsule::table('mod_cloudflare_subdomain_quotas')->insert([
                        'userid' => $userid,
                        'used_count' => 0,
                        'max_count' => $max,
                        'invite_bonus_count' => 0,
                        'invite_bonus_limit' => $inviteLimitGlobal > 0 ? $inviteLimitGlobal : 5,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $inviteeQuota = (object)['userid'=>$userid,'used_count'=>0,'max_count'=>$max,'invite_bonus_count'=>0,'invite_bonus_limit'=>($inviteLimitGlobal > 0 ? $inviteLimitGlobal : 5)];
                }

                $inviterLimit = intval($inviterQuota->invite_bonus_limit ?? 5);
                $inviteeLimit = intval($inviteeQuota->invite_bonus_limit ?? 5);
                $inviterBonus = intval($inviterQuota->invite_bonus_count ?? 0);
                $inviteeBonus = intval($inviteeQuota->invite_bonus_count ?? 0);

                // 若受邀者已达上限，则双方均不可获得加成
                if ($inviteeBonus >= $inviteeLimit) {
                    throw new Exception(self::actionText('invite.limit_reached', '达到额度上限，无法再增加'));
                }

                $inviterAdded = 0;
                $inviteeAdded = 0;

                // 邀请方加成（不超过上限）
                if ($inviterBonus < $inviterLimit) {
                    Capsule::table('mod_cloudflare_subdomain_quotas')
                        ->where('userid', $codeRow->userid)
                        ->update([
                            'invite_bonus_count' => $inviterBonus + 1,
                            'max_count' => intval($inviterQuota->max_count) + 1,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    $inviterAdded = 1;
                }

                // 受邀方加成（不超过上限）
                if ($inviteeBonus < $inviteeLimit) {
                    Capsule::table('mod_cloudflare_subdomain_quotas')
                        ->where('userid', $userid)
                        ->update([
                            'invite_bonus_count' => $inviteeBonus + 1,
                            'max_count' => intval($inviteeQuota->max_count) + 1,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    $inviteeAdded = 1;
                }

                // 记录本次使用
                Capsule::table('mod_cloudflare_invitation_claims')->insert([
                    'inviter_userid' => $codeRow->userid,
                    'invitee_userid' => $userid,
                    'code' => $inputCode,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                return ['inviterAdded' => $inviterAdded, 'inviteeAdded' => $inviteeAdded];
            });

            // 刷新本地配额信息
            try {
                $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->first();
            } catch (Exception $e) {}

            if ($result['inviteeAdded'] && $result['inviterAdded']) {
                $msg = self::actionText('invite.success_both', '解锁成功，您与邀请方各增加 1 个注册额度');
                $msg_type = 'success';
            } elseif ($result['inviteeAdded'] && !$result['inviterAdded']) {
                $msg = self::actionText('invite.success_self', '解锁成功，您增加 1 个注册额度（邀请方已达上限）');
                $msg_type = 'success';
            } else {
                $msg = self::actionText('invite.success_none', '未增加注册额度');
                $msg_type = 'warning';
            }
        } catch (Exception $e) {
            // 邀请码相关错误不应使用 cfmod_format_provider_error，直接显示原始错误信息
            $msg = $e->getMessage();
            $msg_type = 'danger';
        }
    }}
}

// 兑换礼品申请
if($_POST['action'] == 'request_invite_reward') {
    try {
        // 计算上期结算的周期：支持自定义周期开始
        if ($inviteCycleStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $inviteCycleStart)) {
            $startTs = strtotime($inviteCycleStart);
            $todayTs = strtotime(date('Y-m-d'));
            if ($todayTs >= $startTs) {
                $k = (int) floor((($todayTs - $startTs) / 86400) / $inviteLeaderboardDays);
                // 计算上期的开始和结束日期
                $periodStart = date('Y-m-d', strtotime('+' . (($k - 1) * $inviteLeaderboardDays) . ' days', $startTs));
                $periodEnd = date('Y-m-d', strtotime('+' . (($k * $inviteLeaderboardDays) - 1) . ' days', $startTs));
            } else {
                // 如果当前日期在周期开始日期之前，则无法申请
                $periodStart = date('Y-m-d', strtotime('yesterday'));
                $periodEnd = date('Y-m-d', strtotime('yesterday'));
            }
        } else {
            // 默认按周计算，上期结束日为昨天
            $periodEnd = date('Y-m-d', strtotime('yesterday'));
            $periodStart = date('Y-m-d', strtotime($periodEnd . ' -' . ($inviteLeaderboardDays - 1) . ' days'));
        }
        // 只查奖励表（历史快照数据），不查实时统计
        $winners = Capsule::table('mod_cloudflare_invite_rewards as r')
            ->select('r.inviter_userid','r.rank','r.count','r.code')
            ->where('r.period_start', $periodStart)
            ->where('r.period_end', $periodEnd)
            ->orderBy('r.rank','asc')
            ->limit(5)
            ->get();
        // 如果没有历史快照数据，则无法申请兑换
        $rank = null; $count = 0; $codeVal = '';
        $i = 1; foreach ($winners as $w) {
            $thisRank = isset($w->rank) ? intval($w->rank) : $i;
            $thisCount = isset($w->count) ? intval($w->count) : intval($w->cnt ?? 0);
            if (intval($w->inviter_userid) === intval($userid)) { $rank = $thisRank; $count = $thisCount; break; }
            $i++;
        }
        if ($rank === null) { $msg = self::actionText('invite.reward.not_ranked', '上期未上榜，无法申请兑换'); $msg_type = 'warning'; }
        else {
            $codeRow = Capsule::table('mod_cloudflare_invitation_codes')->select('code')->where('userid', $userid)->first();
            $codeVal = $codeRow ? ($codeRow->code ?? '') : '';
            $existing = Capsule::table('mod_cloudflare_invite_rewards')
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->where('inviter_userid', $userid)
                ->first();
            if ($existing) {
                if ($existing->status === 'claimed') { $msg = self::actionText('invite.reward.already_claimed', '本期奖励已领取'); $msg_type = 'success'; }
                else {
                    Capsule::table('mod_cloudflare_invite_rewards')
                        ->where('id', $existing->id)
                        ->update(['status' => 'pending', 'requested_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                    $msg = self::actionText('invite.reward.submitted', '兑换申请已提交，请等待处理'); $msg_type = 'success';
                }
            } else {
                Capsule::table('mod_cloudflare_invite_rewards')->insert([
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'inviter_userid' => $userid,
                    'code' => $codeVal,
                    'rank' => $rank,
                    'count' => $count,
                    'status' => 'pending',
                    'requested_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $msg = self::actionText('invite.reward.submitted', '兑换申请已提交，请等待处理'); $msg_type = 'success';
            }
        }
    } catch (Exception $e) {
        // 邀请奖励相关错误不应使用 cfmod_format_provider_error，直接显示原始错误信息
        $errorText = $e->getMessage();
        if (trim($errorText) === '') {
            $errorText = self::actionText('invite.reward.retry', '申请失败，请稍后重试。');
        }
        $msg = self::actionText('invite.reward.failed', '申请失败：%s', [$errorText]);
        $msg_type = 'danger';
    }
}

// 处理注册请求 - 不创建解析，仅记录保存
if($_POST['action'] == "register") {
    if ($pauseFreeRegistration) {
        $msg = self::actionText('register.paused', '当前已暂停免费域名注册，请稍后再试。');
        $msg_type = 'warning';
        $registerError = $msg;
    } else {
        if ($isUserBannedOrInactive) {
            $msg = self::actionText('register.banned', '您的账号已被封禁或停用，禁止注册新域名。') . ($banReasonText ? (' ' . $banReasonText) : '');
            $msg_type = 'danger';
            $registerError = $msg;
        } else {
            // VPN/代理检测
            $vpnCheckPassed = true;
            $vpnCheckResult = null;
            if (class_exists('CfVpnDetectionService') && CfVpnDetectionService::isEnabled($module_settings)) {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
                $vpnCheckResult = CfVpnDetectionService::shouldBlockRegistration($clientIp, $module_settings);
                if (!empty($vpnCheckResult['blocked'])) {
                    $vpnCheckPassed = false;
                    $msg = self::actionText('register.vpn_blocked', '检测到您正在使用VPN或代理，请关闭后再尝试注册域名。');
                    $msg_type = 'warning';
                    $registerError = $msg;
                    // 记录日志
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('vpn_detection_blocked', [
                            'ip' => $clientIp,
                            'reason' => $vpnCheckResult['reason'] ?? 'unknown',
                            'is_vpn' => $vpnCheckResult['is_vpn'] ?? false,
                            'is_proxy' => $vpnCheckResult['is_proxy'] ?? false,
                            'is_hosting' => $vpnCheckResult['is_hosting'] ?? false,
                        ], $userid ?? 0, null);
                    }
                }
            }

            if ($vpnCheckPassed) {
            $inviteGateBlocked = false;
            if (($userid ?? 0) > 0 && CfInviteRegistrationService::isGateEnabled($module_settings)) {
                try {
                    $inviteGateBlocked = !CfInviteRegistrationService::userHasUnlocked((int) $userid);
                } catch (\Throwable $e) {
                    $inviteGateBlocked = true;
                }
            }

            if ($inviteGateBlocked) {
                $msg = self::actionText('invite_registration.gate_locked', '首次使用前请先完成准入验证。');
                $msg_type = 'warning';
                $registerError = $msg;
            } else {
            $subprefix = trim($_POST['subdomain']);
            $rootdomain = trim($_POST['rootdomain']);
            $subprefixLen = strlen($subprefix);

            // 根域名邀请码验证 - 提前验证
            $rootdomainInviteVerified = true;
            $rootdomainInviteRequired = false;
            try {
                if (class_exists('CfRootdomainInviteService') && $rootdomain !== '') {
                    $rootdomainInviteRequired = CfRootdomainInviteService::isInviteRequired($rootdomain);
                }
            } catch (\Throwable $e) {
                $rootdomainInviteRequired = false;
            }
            
            if ($rootdomainInviteRequired && $subprefix !== '' && $rootdomain !== '') {
                $rootdomainInviteCode = trim($_POST['rootdomain_invite_code'] ?? '');
                if ($rootdomainInviteCode === '') {
                    $msg = self::actionText('rootdomain_invite.code_required', '该根域名需要邀请码才能注册，请输入邀请码。');
                    $msg_type = 'warning';
                    $registerError = $msg;
                    $rootdomainInviteVerified = false;
                } else {
                    // 立即验证邀请码（但不记录使用，等注册成功后再记录）
                    try {
                        if (!class_exists('CfRootdomainInviteService')) {
                            throw new \Exception('邀请服务不可用');
                        }
                        
                        $clientEmail = self::resolveClientEmail($userid);
                        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
                        $fullsub = strtolower($subprefix) . '.' . strtolower($rootdomain);
                        
                        // 先进行预验证（不使用邀请码）
                        $codeRow = Capsule::table('mod_cloudflare_rootdomain_invite_codes')
                            ->where('invite_code', strtoupper($rootdomainInviteCode))
                            ->where('rootdomain', $rootdomain)
                            ->first();

                        if (!$codeRow) {
                            throw new \InvalidArgumentException('invalid_code');
                        }

                        $inviterId = (int) ($codeRow->userid ?? 0);

                        // 不能使用自己的邀请码
                        if ($inviterId === $userid) {
                            throw new \InvalidArgumentException('self_code');
                        }

                        // 检查邀请人状态
                        $inviterStatus = Capsule::table('tblclients')->where('id', $inviterId)->value('status');
                        if ($inviterStatus !== null && strtolower((string) $inviterStatus) !== 'active') {
                            throw new \InvalidArgumentException('inviter_banned');
                        }

                        // 检查封禁状态
                        if (function_exists('cfmod_resolve_user_ban_state')) {
                            $banState = cfmod_resolve_user_ban_state($inviterId);
                            if (!empty($banState['is_banned'])) {
                                throw new \InvalidArgumentException('inviter_banned');
                            }
                        }

                        // 检查邀请人是否达到邀请上限
                        if (!CfRootdomainInviteService::checkInviterLimit($inviterId, $rootdomain)) {
                            throw new \InvalidArgumentException('inviter_limit_reached');
                        }

                        // 验证通过，存储待记录信息，在注册成功后才调用
                        $_POST['__rootdomain_invite_verified__'] = [
                            'userid' => $userid,
                            'rootdomain' => $rootdomain,
                            'code' => $rootdomainInviteCode,
                            'subdomain' => $fullsub,
                            'email' => $clientEmail,
                            'ip' => $clientIp,
                        ];
                        $rootdomainInviteVerified = true;
                    } catch (\InvalidArgumentException $e) {
                        $errorKey = $e->getMessage();
                        $errorMessages = [
                            'invalid_code' => '邀请码无效，请检查后重试。',
                            'self_code' => '不能使用自己的邀请码。',
                            'inviter_banned' => '邀请人账户状态异常，无法使用该邀请码。',
                            'inviter_limit_reached' => '该邀请码已达使用上限。',
                        ];
                        $msg = self::actionText('rootdomain_invite.' . $errorKey, $errorMessages[$errorKey] ?? '邀请码验证失败。');
                        $msg_type = 'danger';
                        $registerError = $msg;
                        $rootdomainInviteVerified = false;
                    } catch (\Throwable $e) {
                        $msg = self::actionText('rootdomain_invite.error', '邀请码验证失败：%s', [$e->getMessage()]);
                        $msg_type = 'danger';
                        $registerError = $msg;
                        $rootdomainInviteVerified = false;
                    }
                }
            }

            if ($subprefix === '' || $rootdomain === '' || ($rootdomainInviteRequired && !$rootdomainInviteVerified)) {
                if ($subprefix === '' || $rootdomain === '') {
                    $msg = self::actionText('register.missing_fields', '请填写完整信息');
                } else {
                    $msg = self::actionText('rootdomain_invite.verification_required', '邀请码验证未通过，无法继续注册。');
                }
                $msg_type = 'danger';
                $registerError = $msg;
            } elseif (is_object($quota) && intval($quota->max_count ?? 0) > 0 && intval($quota->used_count ?? 0) >= intval($quota->max_count ?? 0)) {
                $msg = self::actionText('register.limit_reached', '已达到最大注册数量限制 (%s)', [intval($quota->max_count ?? 0)]);
                $msg_type = 'warning';
                $registerError = $msg;
            } elseif (in_array(strtolower($subprefix), array_map('strtolower', $forbidden))) {
                $msg = self::actionText('register.forbidden_prefix', "该前缀 '%s' 禁止使用", [$subprefix]);
                $msg_type = 'danger';
                $registerError = $msg;
            } elseif (!preg_match('/^[a-zA-Z0-9\-]+$/', $subprefix)) {
                $msg = self::actionText('register.invalid_chars', '子域名前缀只能包含字母、数字和连字符');
                $msg_type = 'danger';
                $registerError = $msg;
            } elseif (cfmod_has_invalid_edge_character($subprefix)) {
                $msg = self::actionText('register.edge_error', "子域名前缀不能以 '.' 或 '-' 开头或结尾");
                $msg_type = 'danger';
                $registerError = $msg;
            } elseif ($subprefixLen < $subPrefixMinLength || $subprefixLen > $subPrefixMaxLength) {
                $msg = self::actionText('register.length_error', '子域名前缀长度必须在%1$s-%2$s个字符之间', [$subPrefixMinLength, $subPrefixMaxLength]);
                $msg_type = 'danger';
                $registerError = $msg;
            } else {
                $fullsub = strtolower($subprefix) . '.' . strtolower($rootdomain);

                $isForbidden = Capsule::table('mod_cloudflare_forbidden_domains')->where('domain', $fullsub)->count() > 0;
                if ($isForbidden) {
                    $msg = self::actionText('register.forbidden_domain', '该域名已被禁止注册');
                    $msg_type = 'danger';
                    $registerError = $msg;
                } elseif (Capsule::table('mod_cloudflare_subdomain')->where('subdomain', $fullsub)->count() > 0) {
                    $msg = self::actionText('register.duplicate', "域名 '%s' 已被注册,请更换后重试.", [$fullsub]);
                    $msg_type = 'danger';
                    $registerError = $msg;
                } else {
                    try {
                        $rootAllowed = in_array($rootdomain, $roots);
                        if (!$rootAllowed) {
                            $msg = self::actionText('register.root_not_allowed', '根域名未被允许注册');
                            $msg_type = 'danger';
                            $registerError = $msg;
                        } else {
                            try {
                                $dbHasRoots = Capsule::table('mod_cloudflare_rootdomains')->count() > 0;
                                if ($dbHasRoots) {
                                    $st = Capsule::table('mod_cloudflare_rootdomains')
                                        ->select('status')
                                        ->whereRaw('LOWER(domain)=?', [strtolower($rootdomain)])
                                        ->first();
                                    $rootStatus = strtolower((string)($st->status ?? ''));
                                    if ($st && $rootStatus !== 'active' && !$privilegedAllowRegisterSuspendedRoot) {
                                        $msg = self::actionText('register.root_suspended', '该根域名已停止新注册');
                                        $msg_type = 'danger';
                                        $registerError = $msg;
                                        throw new Exception('suspended rootdomain');
                                    }
                                }
                            } catch (Exception $e) {}

                            $limitCheck = function_exists('cfmod_check_rootdomain_user_limit')
                                ? cfmod_check_rootdomain_user_limit($userid, $rootdomain, 1)
                                : ['allowed' => true, 'limit' => 0];

                            if (!$limitCheck['allowed']) {
                                $limitMessage = cfmod_format_rootdomain_limit_message($rootdomain, $limitCheck['limit']);
                                if ($limitMessage === '') {
                                    $limitValueText = max(1, intval($limitCheck['limit'] ?? 0));
                                    $limitMessage = self::actionText('register.root_user_limit', '%1$s 每个账号最多注册 %2$s 个，您已达到上限', [$rootdomain, $limitValueText]);
                                }
                                $msg = $limitMessage;
                                $msg_type = 'warning';
                                $registerError = $msg;
                            } else {
                                $providerContext = cfmod_make_provider_client(null, $rootdomain, null, $module_settings);
                                if (!$providerContext || empty($providerContext['client'])) {
                                    $msg = self::actionText('register.provider_missing', '当前根域未配置有效的 DNS 供应商，请联系管理员');
                                    $msg_type = 'danger';
                                    $registerError = $msg;
                                } else {
                                    $cf = $providerContext['client'];
                                    $selectedProviderId = intval($providerContext['provider_account_id'] ?? 0);
                                    $zone_id = $cf->getZoneId($rootdomain);

                                    if ($zone_id) {
                                        $skipProviderExistsCheck = self::shouldSkipProviderExistsCheck($providerContext, $module_settings, $rootdomain);
                                        $existsOnCF = false;
                                        if (!$skipProviderExistsCheck) {
                                            $existsOnCF = $cf->checkDomainExists($zone_id, $fullsub);
                                        }
                                        if ($existsOnCF) {
                                            $msg = self::actionText('register.provider_exists', '该域名在阿里云DNS上已存在解析记录，无法注册');
                                            $msg_type = 'danger';
                                            $registerError = $msg;
                                        } else {
                                            $created = null;
                                            try {
                                                $created = cf_atomic_register_subdomain(
                                                    $userid,
                                                    $fullsub,
                                                    $rootdomain,
                                                    $zone_id,
                                                    $module_settings,
                                                    [
                                                        'dns_record_id' => null,
                                                        'notes' => '已注册，等待解析设置',
                                                        'provider_account_id' => $selectedProviderId
                                                    ]
                                                );
                                            } catch (CfAtomicQuotaExceededException $e) {
                                                $totalLimit = null;
                                                if (is_object($quota) && isset($quota->max_count)) {
                                                    $totalLimit = $quota->max_count;
                                                } elseif (isset($module_settings['max_subdomain_per_user'])) {
                                                    $totalLimit = $module_settings['max_subdomain_per_user'];
                                                }
                                                $limitText = $totalLimit !== null ? intval($totalLimit) : self::actionText('common.configured_limit', '已配置的上限');
                                                $msg = self::actionText('register.limit_reached', '已达到最大注册数量限制 (%s)', [$limitText]);
                                                $msg_type = 'warning';
                                                $registerError = $msg;
                                            } catch (CfAtomicAlreadyRegisteredException $e) {
                                                $msg = self::actionText('register.duplicate', "域名 '%s' 已被注册,请更换后重试.", [$fullsub]);
                                                $msg_type = 'danger';
                                                $registerError = $msg;
                                            } catch (CfAtomicInvalidPrefixLengthException $e) {
                                                $msg = self::actionText('register.length_error', '子域名前缀长度必须在%1$s-%2$s个字符之间', [$subPrefixMinLength, $subPrefixMaxLength]);
                                                $msg_type = 'danger';
                                                $registerError = $msg;
                                            }

                                            if ($created) {
                                                if (is_object($quota)) {
                                                    $quota->used_count = $created['used_count'];
                                                    $quota->max_count = $created['max_count'];
                                                } else {
                                                    $quota = (object) [
                                                        'used_count' => $created['used_count'],
                                                        'max_count' => $created['max_count'],
                                                        'invite_bonus_count' => 0,
                                                        'invite_bonus_limit' => intval($module_settings['invite_bonus_limit_global'] ?? 5)
                                                    ];
                                                }

                                                if (function_exists('cloudflare_subdomain_log')) {
                                                    cloudflare_subdomain_log('client_register_subdomain', ['subdomain' => $fullsub, 'root' => $rootdomain], $userid, $created['id']);
                                                }

                                                // 处理根域名邀请码（注册成功后）
                                                if (isset($_POST['__rootdomain_invite_verified__']) && is_array($_POST['__rootdomain_invite_verified__'])) {
                                                    try {
                                                        $inviteData = $_POST['__rootdomain_invite_verified__'];
                                                        if (class_exists('CfRootdomainInviteService')) {
                                                            CfRootdomainInviteService::validateAndUseInviteCode(
                                                                (int) $inviteData['userid'],
                                                                (string) $inviteData['rootdomain'],
                                                                (string) $inviteData['code'],
                                                                (string) $inviteData['subdomain'],
                                                                (string) $inviteData['email'],
                                                                (string) $inviteData['ip']
                                                            );
                                                        }
                                                    } catch (\Throwable $e) {
                                                        // 邀请码记录失败不影响注册结果，仅记录日志
                                                        if (function_exists('cloudflare_subdomain_log')) {
                                                            cloudflare_subdomain_log('rootdomain_invite_code_log_failed', ['error' => $e->getMessage()], $userid, $created['id']);
                                                        }
                                                    }
                                                    unset($_POST['__rootdomain_invite_verified__']);
                                                }

                                                $msg = self::actionText('register.success_detail', "注册成功！域名 '%s' 已创建，现在您可以设置解析了", [$fullsub]);
                                                $msg_type = 'success';
                                                $registerError = '';

                                                list($existing, $existing_total, $domainTotalPages, $domainPage) = cfmod_client_load_subdomains_paginated(
                                                    $userid,
                                                    1,
                                                    $domainPageSize,
                                                    $domainSearchTerm
                                                );
                                            }
                                        }
                                    } else {
                                        $msg = self::actionText('register.error_generic', '错误：错误代码#1001,请稍后重试。');
                                        $msg_type = 'danger';
                                        $registerError = $msg;
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {}
                }
            }
            }
            } // end if ($vpnCheckPassed)
        }
    }
}
// 处理续期请求
if($_POST['action'] == "renew" && isset($_POST['subdomain_id'])) {
    $subdomainId = intval($_POST['subdomain_id']);
    $nowTs = time();
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    } catch (Exception $e) {}

    $renewSig = 'renew|' . $subdomainId;
    if (!empty($_SESSION['cfmod_last_renew_sig']) && $_SESSION['cfmod_last_renew_sig'] === $renewSig && isset($_SESSION['cfmod_last_renew_time']) && ($nowTs - intval($_SESSION['cfmod_last_renew_time'])) < 5) {
        $msg = self::actionText('common.duplicate_submit', '操作已提交，请勿重复点击');
        $msg_type = 'warning';
    } else {
        $_SESSION['cfmod_last_renew_sig'] = $renewSig;
        $_SESSION['cfmod_last_renew_time'] = $nowTs;

        $termYearsRaw = $module_settings['domain_registration_term_years'] ?? 1;
        $termYears = is_numeric($termYearsRaw) ? (int)$termYearsRaw : 1;
        if ($termYears <= 0) {
            $msg = self::actionText('renew.term_missing', '当前未配置有效的续期年限，请联系管理员');
            $msg_type = 'danger';
            unset($_SESSION['cfmod_last_renew_sig'], $_SESSION['cfmod_last_renew_time']);
        } else {
            $freeWindowDays = max(0, intval($module_settings['domain_free_renew_window_days'] ?? 30));
            $graceDaysRaw = $module_settings['domain_grace_period_days'] ?? ($module_settings['domain_auto_delete_grace_days'] ?? 45);
            $graceDays = is_numeric($graceDaysRaw) ? (int)$graceDaysRaw : 45;
            if ($graceDays < 0) { $graceDays = 0; }
            $redemptionDaysRaw = $module_settings['domain_redemption_days'] ?? 0;
            $redemptionDays = is_numeric($redemptionDaysRaw) ? (int)$redemptionDaysRaw : 0;
            if ($redemptionDays < 0) { $redemptionDays = 0; }
            $freeWindowSeconds = $freeWindowDays * 86400;
            $graceSeconds = $graceDays * 86400;
            $redemptionSeconds = $redemptionDays * 86400;

            try {
                $renewResult = Capsule::transaction(function () use ($subdomainId, $userid, $termYears, $freeWindowSeconds, $graceSeconds, $redemptionSeconds, $nowTs, $redemptionModeSetting, $redemptionFeeSetting) {
                    $nowStr = date('Y-m-d H:i:s');
                    $subdomain = Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subdomainId)
                        ->where('userid', $userid)
                        ->lockForUpdate()
                        ->first();

                    if (!$subdomain) {
                        throw new \RuntimeException(self::actionText('renew.not_found', '未找到该域名或无权操作'));
                    }

                    if (intval($subdomain->never_expires ?? 0) === 1) {
                        throw new \RuntimeException(self::actionText('renew.never_expires', '此域名为永久有效，无需续期'));
                    }

                    $statusLower = strtolower($subdomain->status ?? '');
                    if (!in_array($statusLower, ['active', 'pending'], true)) {
                        throw new \RuntimeException(self::actionText('renew.status_invalid', '当前状态不允许续期'));
                    }

                    $expiresRaw = $subdomain->expires_at ?? null;
                    if (!$expiresRaw) {
                        throw new \RuntimeException(self::actionText('renew.no_expiry', '尚未设置到期时间，请联系管理员'));
                    }

                    $expiresTs = strtotime($expiresRaw);
                    if ($expiresTs === false) {
                        throw new \RuntimeException(self::actionText('renew.parse_failed', '无法解析当前到期时间'));
                    }

                    if ($freeWindowSeconds > 0 && $nowTs < ($expiresTs - $freeWindowSeconds)) {
                        throw new \RuntimeException(self::actionText('renew.not_in_window', '尚未进入续期窗口'));
                    }

                    $graceDeadlineTs = $expiresTs + $graceSeconds;
                    $chargeAmount = 0.0;
                    if ($nowTs > $graceDeadlineTs) {
                        $redemptionDeadlineTs = $graceDeadlineTs + $redemptionSeconds;
                        if ($redemptionSeconds > 0 && $nowTs <= $redemptionDeadlineTs) {
                            if ($redemptionModeSetting === 'auto_charge') {
                                if ($redemptionFeeSetting > 0) {
                                    $clientRow = Capsule::table('tblclients')
                                        ->where('id', $userid)
                                        ->lockForUpdate()
                                        ->first();
                                    if (!$clientRow) {
                                        throw new \RuntimeException(self::actionText('renew.balance_unavailable', '无法读取账户余额信息，请稍后重试'));
                                    }
                                    $currentCredit = (float) ($clientRow->credit ?? 0.0);
                                    if ($currentCredit + 1e-8 < $redemptionFeeSetting) {
                                        throw new \RuntimeException(self::actionText('renew.redemption_insufficient_balance', '赎回期续费需要 ￥%s，账户余额不足，请先充值后再试。', [number_format($redemptionFeeSetting, 2)]));
                                    }
                                    $newCredit = round($currentCredit - $redemptionFeeSetting, 2);
                                    Capsule::table('tblclients')
                                        ->where('id', $userid)
                                        ->update([
                                            'credit' => number_format($newCredit, 2, '.', ''),
                                        ]);

                                    static $creditSchemaInfoLocal = null;
                                    if ($creditSchemaInfoLocal === null) {
                                        $creditSchemaInfoLocal = [
                                            'has_table' => false,
                                            'has_relid' => false,
                                            'has_refundid' => false,
                                        ];
                                        try {
                                            $creditSchemaInfoLocal['has_table'] = Capsule::schema()->hasTable('tblcredit');
                                            if ($creditSchemaInfoLocal['has_table']) {
                                                $creditSchemaInfoLocal['has_relid'] = Capsule::schema()->hasColumn('tblcredit', 'relid');
                                                $creditSchemaInfoLocal['has_refundid'] = Capsule::schema()->hasColumn('tblcredit', 'refundid');
                                            }
                                        } catch (\Throwable $ignored) {
                                            $creditSchemaInfoLocal = [
                                                'has_table' => false,
                                                'has_relid' => false,
                                                'has_refundid' => false,
                                            ];
                                        }
                                    }
                                    if ($creditSchemaInfoLocal['has_table']) {
                                        $creditInsert = [
                                            'clientid' => $userid,
                                            'date' => date('Y-m-d H:i:s', $nowTs),
                                            'description' => '赎回期续费自动扣费',
                                            'amount' => 0 - $redemptionFeeSetting,
                                        ];
                                        if ($creditSchemaInfoLocal['has_relid']) {
                                            $creditInsert['relid'] = 0;
                                        }
                                        if ($creditSchemaInfoLocal['has_refundid']) {
                                            $creditInsert['refundid'] = 0;
                                        }
                                        Capsule::table('tblcredit')->insert($creditInsert);
                                    }
                                    $chargeAmount = $redemptionFeeSetting;
                                }
                            } else {
                                throw new \RuntimeException(self::actionText('renew.redemption_contact_admin', '域名处于赎回期，需要联系管理员续期'));
                            }
                        } else {
                            throw new \RuntimeException($redemptionSeconds > 0 ? self::actionText('renew.redemption_expired', '域名已超过赎回期，无法续期') : self::actionText('renew.grace_expired', '已超过续期宽限期，无法续期'));
                        }
                    }

                    $baseTs = max($expiresTs, $nowTs);
                    $newExpiryTs = strtotime('+' . $termYears . ' years', $baseTs);
                    if ($newExpiryTs === false) {
                        throw new \RuntimeException(self::actionText('renew.calculation_failed', '续期计算失败，请稍后重试'));
                    }

                    $newExpiry = date('Y-m-d H:i:s', $newExpiryTs);

                    Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subdomainId)
                        ->update([
                            'expires_at' => $newExpiry,
                            'renewed_at' => $nowStr,
                            'never_expires' => 0,
                            'updated_at' => $nowStr
                        ]);

                    return [
                        'new_expires_at' => $newExpiry,
                        'previous_expires_at' => $expiresRaw,
                        'subdomain' => $subdomain->subdomain,
                        'charged_amount' => $chargeAmount,
                    ];
                });

                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('client_renew_subdomain', [
                        'subdomain' => $renewResult['subdomain'],
                        'previous_expires_at' => $renewResult['previous_expires_at'],
                        'new_expires_at' => $renewResult['new_expires_at'],
                        'charged_amount' => $renewResult['charged_amount'] ?? 0,
                    ], $userid, $subdomainId);
                }

                $chargedAmount = isset($renewResult['charged_amount']) ? (float)$renewResult['charged_amount'] : 0.0;
                $msg = self::actionText('renew.success', '续期成功，新到期时间：%s', [date('Y-m-d H:i', strtotime($renewResult['new_expires_at']))]);
                if ($chargedAmount > 0) {
                    $msg .= self::actionText('renew.success_charge_suffix', '（已扣除 ￥%s）', [number_format($chargedAmount, 2)]);
                }
                $msg_type = 'success';

                $existing = Capsule::table('mod_cloudflare_subdomain')
                    ->where('userid', $userid)
                    ->where('status', 'active')
                    ->orderBy('created_at', 'desc')
                    ->get();
                if (!is_array($existing)) {
                    $existing = [];
                }
            } catch (\Throwable $e) {
                unset($_SESSION['cfmod_last_renew_sig'], $_SESSION['cfmod_last_renew_time']);
                $errorText = cfmod_format_provider_error($e->getMessage(), self::actionText('renew.failed_default', '续期失败，请稍后再试。'));
                $msg = self::actionText('renew.failed_detail', '续期失败：%s', [$errorText]);
                $msg_type = 'danger';
            }
        }
    }
}


// 处理升级永久请求
if($_POST['action'] == "upgrade_permanent" && isset($_POST['subdomain_id'])) {
    if (!$enablePermanentUpgrade) {
        $msg = self::actionText('permanent_upgrade.disabled', '管理员暂未开启域名升级永久功能。');
        $msg_type = 'warning';
    } elseif ($userid <= 0) {
        $msg = self::actionText('permanent_upgrade.invalid_user', '未找到登录信息，请刷新页面后重试。');
        $msg_type = 'danger';
    } else {
        $subdomainId = intval($_POST['subdomain_id']);
        $nowTs = time();
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        } catch (Exception $e) {}

        $upgradeSig = 'upgrade_permanent|' . $subdomainId;
        if (!empty($_SESSION['cfmod_last_permanent_upgrade_sig'])
            && $_SESSION['cfmod_last_permanent_upgrade_sig'] === $upgradeSig
            && isset($_SESSION['cfmod_last_permanent_upgrade_time'])
            && ($nowTs - intval($_SESSION['cfmod_last_permanent_upgrade_time'])) < 5
        ) {
            $msg = self::actionText('common.duplicate_submit', '操作已提交，请勿重复点击');
            $msg_type = 'warning';
        } else {
            $_SESSION['cfmod_last_permanent_upgrade_sig'] = $upgradeSig;
            $_SESSION['cfmod_last_permanent_upgrade_time'] = $nowTs;

            try {
                $upgradeResult = Capsule::transaction(function () use ($subdomainId, $userid, $domainPermanentUpgradePrice, $nowTs) {
                    $nowStr = date('Y-m-d H:i:s', $nowTs);
                    $subdomain = Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subdomainId)
                        ->where('userid', $userid)
                        ->lockForUpdate()
                        ->first();

                    if (!$subdomain) {
                        throw new \RuntimeException(self::actionText('permanent_upgrade.not_found', '未找到该域名或无权操作'));
                    }

                    if (intval($subdomain->never_expires ?? 0) === 1) {
                        throw new \RuntimeException(self::actionText('permanent_upgrade.already', '该域名已经是永久有效，无需重复升级'));
                    }

                    $statusLower = strtolower($subdomain->status ?? '');
                    if (!in_array($statusLower, ['active', 'pending'], true)) {
                        throw new \RuntimeException(self::actionText('permanent_upgrade.status_invalid', '当前状态不允许升级为永久域名'));
                    }

                    $chargeAmount = 0.0;
                    if ($domainPermanentUpgradePrice > 0) {
                        $clientRow = Capsule::table('tblclients')
                            ->where('id', $userid)
                            ->lockForUpdate()
                            ->first();
                        if (!$clientRow) {
                            throw new \RuntimeException(self::actionText('permanent_upgrade.balance_unavailable', '无法读取账户余额信息，请稍后重试'));
                        }

                        $currentCredit = (float) ($clientRow->credit ?? 0.0);
                        if ($currentCredit + 1e-8 < $domainPermanentUpgradePrice) {
                            throw new \RuntimeException(self::actionText('permanent_upgrade.insufficient_balance', '升级永久域名需要 ￥%s，账户余额不足，请先充值后再试。', [number_format($domainPermanentUpgradePrice, 2)]));
                        }

                        $newCredit = round($currentCredit - $domainPermanentUpgradePrice, 2);
                        Capsule::table('tblclients')
                            ->where('id', $userid)
                            ->update([
                                'credit' => number_format($newCredit, 2, '.', ''),
                            ]);

                        static $creditSchemaInfoPermanentUpgrade = null;
                        if ($creditSchemaInfoPermanentUpgrade === null) {
                            $creditSchemaInfoPermanentUpgrade = [
                                'has_table' => false,
                                'has_relid' => false,
                                'has_refundid' => false,
                            ];
                            try {
                                $creditSchemaInfoPermanentUpgrade['has_table'] = Capsule::schema()->hasTable('tblcredit');
                                if ($creditSchemaInfoPermanentUpgrade['has_table']) {
                                    $creditSchemaInfoPermanentUpgrade['has_relid'] = Capsule::schema()->hasColumn('tblcredit', 'relid');
                                    $creditSchemaInfoPermanentUpgrade['has_refundid'] = Capsule::schema()->hasColumn('tblcredit', 'refundid');
                                }
                            } catch (\Throwable $ignored) {
                                $creditSchemaInfoPermanentUpgrade = [
                                    'has_table' => false,
                                    'has_relid' => false,
                                    'has_refundid' => false,
                                ];
                            }
                        }

                        if ($creditSchemaInfoPermanentUpgrade['has_table']) {
                            $creditInsert = [
                                'clientid' => $userid,
                                'date' => date('Y-m-d H:i:s', $nowTs),
                                'description' => self::actionText('permanent_upgrade.credit_desc', '域名升级永久有效'),
                                'amount' => 0 - $domainPermanentUpgradePrice,
                            ];
                            if ($creditSchemaInfoPermanentUpgrade['has_relid']) {
                                $creditInsert['relid'] = 0;
                            }
                            if ($creditSchemaInfoPermanentUpgrade['has_refundid']) {
                                $creditInsert['refundid'] = 0;
                            }
                            Capsule::table('tblcredit')->insert($creditInsert);
                        }

                        $chargeAmount = $domainPermanentUpgradePrice;
                    }

                    Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subdomainId)
                        ->update([
                            'expires_at' => null,
                            'renewed_at' => $nowStr,
                            'never_expires' => 1,
                            'auto_deleted_at' => null,
                            'updated_at' => $nowStr,
                        ]);

                    return [
                        'subdomain' => $subdomain->subdomain,
                        'previous_expires_at' => $subdomain->expires_at ?? null,
                        'charged_amount' => $chargeAmount,
                    ];
                });

                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('client_upgrade_subdomain_permanent', [
                        'subdomain' => $upgradeResult['subdomain'],
                        'previous_expires_at' => $upgradeResult['previous_expires_at'],
                        'charged_amount' => $upgradeResult['charged_amount'] ?? 0,
                    ], $userid, $subdomainId);
                }

                $existing = Capsule::table('mod_cloudflare_subdomain')
                    ->where('userid', $userid)
                    ->where('status', 'active')
                    ->orderBy('created_at', 'desc')
                    ->get();
                if (!is_array($existing)) {
                    $existing = [];
                }

                $chargedAmount = isset($upgradeResult['charged_amount']) ? (float) $upgradeResult['charged_amount'] : 0.0;
                $msg = self::actionText('permanent_upgrade.success', '升级成功，该域名已永久有效。');
                if ($chargedAmount > 0) {
                    $msg .= self::actionText('permanent_upgrade.success_charge_suffix', '（已扣除 ￥%s）', [number_format($chargedAmount, 2)]);
                }
                $msg_type = 'success';
            } catch (\Throwable $e) {
                unset($_SESSION['cfmod_last_permanent_upgrade_sig'], $_SESSION['cfmod_last_permanent_upgrade_time']);
                $errorText = cfmod_format_provider_error($e->getMessage(), self::actionText('permanent_upgrade.failed_default', '升级失败，请稍后再试。'));
                $msg = self::actionText('permanent_upgrade.failed_detail', '升级失败：%s', [$errorText]);
                $msg_type = 'danger';
            }
        }
    }
}

// 处理删除请求（禁用：用户不能删除自己的域名）
if($_POST['action'] == "delete" && isset($_POST['subdomain_id'])) {
    $subdomain_id = intval($_POST['subdomain_id']);
    $msg = self::actionText('delete.not_supported', '成功注册的免费域名暂不支持删除。如需处理，请提交工单获取支持。');
    $msg_type = "warning";
    if (function_exists('cloudflare_subdomain_log')) {
        cloudflare_subdomain_log('client_attempt_delete_subdomain', ['subdomain_id' => $subdomain_id], $userid, $subdomain_id);
    }
}

// 处理DNS记录创建请求（支持记录表持久化）
if($_POST['action'] == "create_dns" && isset($_POST['subdomain_id'])) {
    $createDnsSubdomainId = intval($_POST['subdomain_id']);
    $createDnsRootdomain = self::getSubdomainRootdomain($createDnsSubdomainId);
    if (self::isRootdomainInMaintenance($createDnsRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$createDnsRootdomain]);
        $msg_type = 'warning';
    } elseif ($disableDnsWrite) {
        $msg = self::actionText('dns.operations_disabled', '当前已禁止新增/修改 DNS 记录');
        $msg_type = 'warning';
    } else {
        $record_type = trim($_POST['record_type'] ?? '');
        if ($record_type === '') {
            $record_type = 'A';
        }
        $record_type_upper = strtoupper($record_type);
        if ($enableDnsUnlockFeature && $record_type_upper === 'NS' && !CfDnsUnlockService::userHasUnlocked($userid ?? 0)) {
            $msg = self::actionText('dns.unlock.required', '请先完成 DNS 解锁后再设置 NS 记录。');
            $msg_type = 'warning';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }
        // VPN/代理检测（仅NS记录）
        if ($record_type_upper === 'NS' && class_exists('CfVpnDetectionService') && CfVpnDetectionService::isDnsCheckEnabled($module_settings)) {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $vpnCheckResult = CfVpnDetectionService::shouldBlockDnsOperation($clientIp, $module_settings);
            if (!empty($vpnCheckResult['blocked'])) {
                $msg = self::actionText('dns.vpn_blocked', '检测到您正在使用VPN或代理，请关闭后再进行DNS操作。');
                $msg_type = 'warning';
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('vpn_detection_blocked_dns', [
                        'action' => 'create_dns',
                        'type' => 'NS',
                        'ip' => $clientIp,
                        'reason' => $vpnCheckResult['reason'] ?? 'unknown',
                    ], $userid ?? 0, null);
                }
                return [
                    'msg' => $msg,
                    'msg_type' => $msg_type,
                    'registerError' => $registerError,
                ];
            }
        }
        if ($isUserBannedOrInactive) {
            $msg = self::actionText('dns.create.banned', '您的账号已被封禁或停用，禁止创建DNS记录。') . ($banReasonText ? (' ' . $banReasonText) : '');
            $msg_type = 'danger';
        } elseif (self::shouldUseAsyncDns('create_dns', $module_settings, $isAsyncReplay)) {
            $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'create_dns');
            $msg = self::formatAsyncQueuedMessage($jobId);
            $msg_type = 'info';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        $subdomain_id = intval($_POST['subdomain_id']);
        $record_content = trim($_POST['record_content']);
        $record_ttl = cfmod_normalize_ttl($_POST['record_ttl'] ?? 600);
        $record_priority_raw = $_POST['record_priority'] ?? null;
        $record_priority = is_numeric($record_priority_raw) ? intval($record_priority_raw) : 0;
        if ($record_priority < 0) {
            $record_priority = 0;
        }
        if ($record_priority > 65535) {
            $record_priority = 65535;
        }
        $line = trim($_POST['line'] ?? 'default');
        $record_name = trim($_POST['record_name'] ?? '@');
        if ($record_name === '') {
            $record_name = '@';
        }
        $record_weight = intval($_POST['record_weight'] ?? 0);
        if ($record_weight < 0) {
            $record_weight = 0;
        }
        if ($record_weight > 65535) {
            $record_weight = 65535;
        }
        $record_port = intval($_POST['record_port'] ?? 0);
        $record_target = trim($_POST['record_target'] ?? '');
        if ($record_type_upper === 'MX' && $record_priority === 0) {
            $record_priority = 10;
        }
        $shouldProceedDnsCreate = true;
        if ($record_port < 0) {
            $record_port = 0;
        }
        if ($record_port > 65535) {
            $record_port = 65535;
        }

        if ($shouldProceedDnsCreate && $record_type_upper === 'SRV') {
            if ($record_port < 1 || $record_port > 65535) {
                $msg = self::actionText('dns.validation.srv_port', 'SRV记录的端口必须在1-65535之间');
                $msg_type = 'danger';
                $shouldProceedDnsCreate = false;
            }
            $record_priority = max(0, min(65535, $record_priority));
            $record_weight = max(0, min(65535, $record_weight));
            $record_target_clean = rtrim($record_target, '.');
            $record_target_clean = trim($record_target_clean);
            if ($shouldProceedDnsCreate && $record_target_clean === '') {
                $msg = self::actionText('dns.validation.srv_target_required', 'SRV记录的目标地址不能为空');
                $msg_type = 'danger';
                $shouldProceedDnsCreate = false;
            }
            if ($shouldProceedDnsCreate && !cfmod_is_valid_hostname($record_target_clean)) {
                $msg = self::actionText('dns.validation.srv_target_invalid', '请输入有效的SRV目标主机名');
                $msg_type = 'danger';
                $shouldProceedDnsCreate = false;
            }
            if ($shouldProceedDnsCreate) {
                $record_target = $record_target_clean;
                $record_content = $record_priority . ' ' . $record_weight . ' ' . $record_port . ' ' . $record_target;
            }
        }

        if (cfmod_dns_name_has_invalid_edges($record_name)) {
            $msg = self::actionText('dns.validation.name_invalid', "解析名称不能以 '.' 或 '-' 开头或结尾，也不能包含连续的 '.'");
            $msg_type = "danger";
            $shouldProceedDnsCreate = false;
        }

        if ($shouldProceedDnsCreate) {
            // 简单幂等：短时间内相同参数的重复提交直接忽略
            try {
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }
            } catch (Exception $e) {}

            $idemSig = 'create|' . implode('|', [
                $subdomain_id,
                $record_type_upper,
                $record_name,
                $record_content,
                $record_ttl,
                $record_priority,
                $line
            ]);
            $nowTs = time();

            if (!empty($_SESSION['cfmod_last_dns_sig'])
                && $_SESSION['cfmod_last_dns_sig'] === $idemSig
                && isset($_SESSION['cfmod_last_dns_time'])
                && ($nowTs - intval($_SESSION['cfmod_last_dns_time'])) < 5
            ) {
                $msg = self::actionText('common.duplicate_submit', '操作已提交，请勿重复点击');
                $msg_type = 'warning';
            } else {
                $_SESSION['cfmod_last_dns_sig'] = $idemSig;
                $_SESSION['cfmod_last_dns_time'] = $nowTs;

                try {
                    $record = Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subdomain_id)
                        ->where('userid', $userid)
                        ->first();

                    if ($record) {
                        if ($record->status === 'suspended') {
                            $msg = self::actionText('dns.domain_suspended', '该域名已被暂停，无法进行解析操作');
                            $msg_type = "warning";
                        } else {
                            list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($record, $module_settings);
                            if (!$cf) {
                                $msg = $providerError;
                                $msg_type = 'danger';
                            } else {
                                $limitPerSub = intval($module_settings['max_dns_records_per_subdomain'] ?? 0);
                                $final_name = $record_name === '@' ? $record->subdomain : ($record_name . '.' . $record->subdomain);
                                $isRootNs = ($record_type_upper === 'NS' && $final_name === $record->subdomain);
                                $effectiveLimit = $isRootNs ? 0 : $limitPerSub;
                                $creation = null;

                                try {
                                    $creation = cf_atomic_run_with_dns_limit(
                                        $record->id,
                                        $effectiveLimit,
                                        function () use (
                                            $cf,
                                            $record,
                                            $record_type_upper,
                                            $record_content,
                                            $record_priority,
                                            $record_ttl,
                                            $line,
                                            $final_name,
                                            $nsMaxPerDomain,
                                            $isRootNs,
                                            $record_weight,
                                            $record_port,
                                            $record_target
                                        ) {
                                            if ($isRootNs) {
                                                $currentNs = Capsule::table('mod_cloudflare_dns_records')
                                                    ->where('subdomain_id', $record->id)
                                                    ->where('type', 'NS')
                                                    ->where('name', $record->subdomain)
                                                    ->lockForUpdate()
                                                    ->count();
                                                if ($currentNs >= $nsMaxPerDomain) {
                                                    throw new CfAtomicRecordLimitException('ns_limit');
                                                }
                                            }

                                            switch ($record_type_upper) {
                                                case 'MX':
                                                    $res = $cf->createMXRecord($record->cloudflare_zone_id, $final_name, $record_content, $record_priority, $record_ttl);
                                                    break;
                                                case 'SRV':
                                                    $res = $cf->createSRVRecord($record->cloudflare_zone_id, $final_name, $record_target, $record_port, $record_priority, $record_weight, $record_ttl);
                                                    break;
                                                case 'CAA':
                                                    $caa_flag = intval($_POST['caa_flag'] ?? 0);
                                                    $caa_tag = trim($_POST['caa_tag'] ?? 'issue');
                                                    $caa_value = trim($_POST['caa_value'] ?? '');
                                                    if ($caa_value === '') {
                                                        throw new \RuntimeException(self::actionText('dns.validation.caa_value_required', 'CAA记录的Value不能为空'));
                                                    }
                                                    $res = $cf->createCAARecord($record->cloudflare_zone_id, $final_name, $caa_flag, $caa_tag, $caa_value, $record_ttl);
                                                    break;
                                                default:
                                                    $res = $cf->createDnsRecordRaw($record->cloudflare_zone_id, [
                                                        'type' => $record_type_upper,
                                                        'name' => $final_name,
                                                        'content' => $record_content,
                                                        'ttl' => $record_ttl,
                                                        'line' => $line
                                                    ]);
                                                    break;
                                            }

                                            if (!($res['success'] ?? false)) {
                                                $message = $res['errors'][0] ?? ($res['errors'] ?? 'create failed');
                                                if (is_array($message)) {
                                                    $message = json_encode($message, JSON_UNESCAPED_UNICODE);
                                                }
                                                throw new \RuntimeException((string) $message);
                                            }

                                            $cfRecordId = $res['result']['id'] ?? ($res['RecordId'] ?? null);
                                            $now = date('Y-m-d H:i:s');

                                            Capsule::table('mod_cloudflare_dns_records')->insert([
                                                'subdomain_id' => $record->id,
                                                'zone_id' => $record->cloudflare_zone_id,
                                                'record_id' => $cfRecordId !== null ? (string) $cfRecordId : null,
                                                'name' => $final_name,
                                                'type' => $record_type_upper,
                                                'content' => $record_content,
                                                'ttl' => $record_ttl,
                                                'proxied' => 0,
                                                'line' => $line,
                                                'priority' => in_array($record_type_upper, ['MX','SRV']) ? $record_priority : null,
                                                'status' => 'active',
                                                'created_at' => $now,
                                                'updated_at' => $now
                                            ]);
                                            CfSubdomainService::markHasDnsHistory($record->id);

                                            $updateData = [
                                                'notes' => '已解析',
                                                'updated_at' => $now
                                            ];
                                            if ($final_name === $record->subdomain) {
                                                $updateData['dns_record_id'] = $cfRecordId !== null ? (string) $cfRecordId : null;
                                            }
                                            Capsule::table('mod_cloudflare_subdomain')
                                                ->where('id', $record->id)
                                                ->update($updateData);

                                            return [
                                                'record_id' => $cfRecordId !== null ? (string) $cfRecordId : null,
                                                'result' => $res,
                                                'final_name' => $final_name
                                            ];
                                        }
                                    );
                                } catch (CfAtomicRecordLimitException $e) {
                                    if ($e->getMessage() === 'ns_limit') {
                                        $msg = self::actionText('dns.ns.limit_reached', 'NS 服务器最多允许 %s 条，当前已达到上限', [$nsMaxPerDomain]);
                                        $msg_type = 'warning';
                                    } else {
                                        $limitText = $limitPerSub > 0 ? $limitPerSub : self::actionText('common.configured_limit', '配置的上限');
                                        $msg = self::actionText('dns.limit_reached', '已达到该域名的解析数量上限（%s）', [$limitText]);
                                        $msg_type = 'warning';
                                    }
                                    throw new Exception('__handled_limit__');
                                } catch (\RuntimeException $e) {
                                    $errorText = cfmod_format_provider_error($e->getMessage());
                                    $msg = self::actionText('dns.create.failed', 'DNS记录创建失败：%s', [$errorText]);
                                    $msg_type = "danger";
                                    throw new Exception('__handled_error__');
                                }

                                if ($creation) {
                                    try {
                                        $fresh = $cf->getDnsRecords($record->cloudflare_zone_id, $record->subdomain);
                                        if (($fresh['success'] ?? false)) {
                                            foreach (($fresh['result'] ?? []) as $fr) {
                                                $exists = self::findLocalRecordByRemote($subdomain_id, $fr);
                                                if (!$exists) {
                                                    Capsule::table('mod_cloudflare_dns_records')->insert([
                                                        'subdomain_id' => $subdomain_id,
                                                        'zone_id' => $record->cloudflare_zone_id,
                                                        'record_id' => isset($fr['id']) ? (string) $fr['id'] : null,
                                                        'name' => ($fr['name'] ?? $record->subdomain),
                                                        'type' => strtoupper(trim((string)($fr['type'] ?? 'A'))),
                                                        'content' => (string)($fr['content'] ?? ''),
                                                        'ttl' => intval($fr['ttl'] ?? 600),
                                                        'proxied' => 0,
                                                        'status' => 'active',
                                                        'created_at' => date('Y-m-d H:i:s'),
                                                        'updated_at' => date('Y-m-d H:i:s')
                                                    ]);
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                    }

                                    CfSubdomainService::syncDnsHistoryFlag($subdomain_id);

                                    if (function_exists('cloudflare_subdomain_log')) {
                                        cloudflare_subdomain_log(
                                            'client_create_dns',
                                            ['type' => $record_type_upper, 'content' => $record_content, 'ttl' => $record_ttl, 'line' => $line],
                                            $userid,
                                            $subdomain_id
                                        );
                                    }

                                    $successDomain = $record_name === '@' ? $record->subdomain : $final_name;
                                $msg = self::actionText('dns.create.success', "DNS记录创建成功！域名 '%s' 现在可以正常访问了", [$successDomain]);
                                $msg_type = "success";
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    if (!in_array($e->getMessage(), ['__handled_limit__', '__handled_error__'], true)) {
                        $errorText = cfmod_format_provider_error($e->getMessage());
                        $msg = self::actionText('dns.create.failed', 'DNS记录创建失败：%s', [$errorText]);
                        $msg_type = "danger";
                    }
                }
            }
        }
    }
}

// 处理域名自助删除
if($_POST['action'] == 'delete_subdomain' && isset($_POST['subdomain_id'])) {
    if (empty($clientDeleteEnabled)) {
        $msg = self::actionText('delete.not_supported', '注册的域名暂不支持自助删除，如需协助请提交工单。');
        $msg_type = 'warning';
    } elseif ($isUserBannedOrInactive) {
        $msg = self::actionText('delete.banned', '您的账号已被封禁或停用，暂无法提交删除申请。') . ($banReasonText ? (' ' . $banReasonText) : '');
        $msg_type = 'danger';
    } else {
        $subdomainId = intval($_POST['subdomain_id']);
        if ($subdomainId <= 0) {
            $msg = self::actionText('delete.invalid_subdomain', '未找到该域名，请刷新后重试。');
            $msg_type = 'warning';
        } else {
            $subdomain = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomainId)
                ->where('userid', $userid)
                ->first();
            if (!$subdomain) {
                $msg = self::actionText('delete.invalid_subdomain', '未找到该域名，请刷新后重试。');
                $msg_type = 'warning';
            } else {
                $statusLower = strtolower((string)($subdomain->status ?? ''));
                $everHadDns = intval($subdomain->has_dns_history ?? 0) === 1;
                if (!$everHadDns) {
                    try {
                        $currentDnsExists = Capsule::table('mod_cloudflare_dns_records')
                            ->where('subdomain_id', $subdomainId)
                            ->exists();
                        if ($currentDnsExists) {
                            $everHadDns = true;
                            CfSubdomainService::markHasDnsHistory($subdomainId);
                        }
                    } catch (\Throwable $e) {
                    }
                }

                if (in_array($statusLower, ['pending_delete', 'pending_remove'], true)) {
                    $msg = self::actionText('delete.pending', '删除申请已提交，系统稍后会自动处理。');
                    $msg_type = 'info';
                } elseif ($statusLower === 'deleted') {
                    $msg = self::actionText('delete.already_deleted', '该域名已被清理，无需重复操作。');
                    $msg_type = 'info';
                } elseif (intval($subdomain->gift_lock_id ?? 0) > 0) {
                    $msg = self::actionText('delete.gift_locked', '域名当前处于转赠/锁定状态，请先取消后再尝试删除。');
                    $msg_type = 'warning';
                } elseif ($everHadDns && !$privilegedAllowDeleteWithDnsHistory) {
                    $msg = self::actionText('delete.history_blocked', '仅允许从未设置解析记录的域名自助删除，如需协助请提交工单。');
                    $msg_type = 'warning';
                } else {
                    $now = date('Y-m-d H:i:s');
                    $deleteNote = '[client_delete ' . $now . '] 用户提交自助删除';
                    $existingNotes = trim((string)($subdomain->notes ?? ''));
                    $noteToStore = $existingNotes === '' ? $deleteNote : ($existingNotes . "\n" . $deleteNote);
                    Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subdomainId)
                        ->update([
                            'status' => 'pending_delete',
                            'expires_at' => '1999-12-31 00:00:00',
                            'never_expires' => 0,
                            'auto_deleted_at' => null,
                            'notes' => $noteToStore,
                            'updated_at' => $now,
                        ]);
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('client_request_delete', [
                            'subdomain' => $subdomain->subdomain ?? '',
                            'userid' => $userid,
                            'requested_at' => $now,
                        ], $userid, $subdomainId);
                    }
                    $msg = self::actionText('delete.request_submitted', '删除申请已提交，系统将在稍后自动清理该域名。');
                    $msg_type = 'success';
                }
            }
        }
    }

    return [
        'msg' => $msg,
        'msg_type' => $msg_type,
        'registerError' => $registerError,
    ];
}

// 处理DNS记录更新请求（同步记录表）
if($_POST['action'] == "update_dns" && isset($_POST['subdomain_id'])) {
    $updateDnsSubdomainId = intval($_POST['subdomain_id']);
    $updateDnsRootdomain = self::getSubdomainRootdomain($updateDnsSubdomainId);
    if (self::isRootdomainInMaintenance($updateDnsRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$updateDnsRootdomain]);
        $msg_type = 'warning';
    } elseif ($disableDnsWrite) {
        $msg = self::actionText('dns.operations_disabled', '当前已禁止新增/修改 DNS 记录');
        $msg_type = 'warning';
    } else {
        $record_type = trim($_POST['record_type'] ?? '');
        if ($record_type === '') {
            $record_type = 'A';
        }
        $record_type_upper = strtoupper($record_type);
        if ($enableDnsUnlockFeature && $record_type_upper === 'NS' && !CfDnsUnlockService::userHasUnlocked($userid ?? 0)) {
            $msg = self::actionText('dns.unlock.required', '请先完成 DNS 解锁后再设置 NS 记录。');
            $msg_type = 'warning';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }
        // VPN/代理检测（仅NS记录）
        if ($record_type_upper === 'NS' && class_exists('CfVpnDetectionService') && CfVpnDetectionService::isDnsCheckEnabled($module_settings)) {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $vpnCheckResult = CfVpnDetectionService::shouldBlockDnsOperation($clientIp, $module_settings);
            if (!empty($vpnCheckResult['blocked'])) {
                $msg = self::actionText('dns.vpn_blocked', '检测到您正在使用VPN或代理，请关闭后再进行DNS操作。');
                $msg_type = 'warning';
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('vpn_detection_blocked_dns', [
                        'action' => 'update_dns',
                        'type' => 'NS',
                        'ip' => $clientIp,
                        'reason' => $vpnCheckResult['reason'] ?? 'unknown',
                    ], $userid ?? 0, null);
                }
                return [
                    'msg' => $msg,
                    'msg_type' => $msg_type,
                    'registerError' => $registerError,
                ];
            }
        }

        if ($isUserBannedOrInactive) {
            $msg = self::actionText('dns.update.banned', '您的账号已被封禁或停用，禁止更新DNS记录。') . ($banReasonText ? (' ' . $banReasonText) : '');
            $msg_type = 'danger';
        } elseif (self::shouldUseAsyncDns('update_dns', $module_settings, $isAsyncReplay)) {
            $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'update_dns');
            $msg = self::formatAsyncQueuedMessage($jobId);
            $msg_type = 'info';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        $subdomain_id = intval($_POST['subdomain_id']);
        $record_content = trim($_POST['record_content']);
        $record_ttl = cfmod_normalize_ttl($_POST['record_ttl'] ?? 600);
        $record_priority_raw = $_POST['record_priority'] ?? null;
        $record_priority = is_numeric($record_priority_raw) ? intval($record_priority_raw) : 0;
        if ($record_priority < 0) {
            $record_priority = 0;
        }
        if ($record_priority > 65535) {
            $record_priority = 65535;
        }
        $line = trim($_POST['line'] ?? 'default');
        $record_id = trim($_POST['record_id'] ?? '');
        $record_name = trim($_POST['record_name'] ?? '@');
        if ($record_name === '') {
            $record_name = '@';
        }
        $record_weight = intval($_POST['record_weight'] ?? 0);
        if ($record_weight < 0) {
            $record_weight = 0;
        }
        if ($record_weight > 65535) {
            $record_weight = 65535;
        }
        $record_port = intval($_POST['record_port'] ?? 0);
        $record_target = trim($_POST['record_target'] ?? '');
        if ($record_type_upper === 'MX' && $record_priority === 0) {
            $record_priority = 10;
        }
        $shouldProceedDnsUpdate = true;
        if ($record_port < 0) {
            $record_port = 0;
        }
        if ($record_port > 65535) {
            $record_port = 65535;
        }

        if ($shouldProceedDnsUpdate && $record_type_upper === 'SRV') {
            if ($record_port < 1 || $record_port > 65535) {
                $msg = self::actionText('dns.validation.srv_port', 'SRV记录的端口必须在1-65535之间');
                $msg_type = 'danger';
                $shouldProceedDnsUpdate = false;
            }
            $record_priority = max(0, min(65535, $record_priority));
            $record_weight = max(0, min(65535, $record_weight));
            $record_target_clean = rtrim($record_target, '.');
            $record_target_clean = trim($record_target_clean);
            if ($shouldProceedDnsUpdate && $record_target_clean === '') {
                $msg = self::actionText('dns.validation.srv_target_required', 'SRV记录的目标地址不能为空');
                $msg_type = 'danger';
                $shouldProceedDnsUpdate = false;
            }
            if ($shouldProceedDnsUpdate && !cfmod_is_valid_hostname($record_target_clean)) {
                $msg = self::actionText('dns.validation.srv_target_invalid', '请输入有效的SRV目标主机名');
                $msg_type = 'danger';
                $shouldProceedDnsUpdate = false;
            }
            if ($shouldProceedDnsUpdate) {
                $record_target = $record_target_clean;
                $record_content = $record_priority . ' ' . $record_weight . ' ' . $record_port . ' ' . $record_target;
            }
        }

        if (cfmod_dns_name_has_invalid_edges($record_name)) {
            $msg = self::actionText('dns.validation.name_invalid', "解析名称不能以 '.' 或 '-' 开头或结尾，也不能包含连续的 '.'");
            $msg_type = "danger";
            $shouldProceedDnsUpdate = false;
        }

        if ($shouldProceedDnsUpdate) {
            try {
                $record = Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomain_id)
                    ->where('userid', $userid)
                    ->first();

                if ($record) {
                    if ($record->status === 'suspended') {
                        $msg = self::actionText('dns.domain_suspended', '该域名已被暂停，无法进行解析操作');
                        $msg_type = "warning";
                    } else {
                        $targetRecord = null;
                        if ($record_id) {
                            $targetRecord = Capsule::table('mod_cloudflare_dns_records')
                                ->where('subdomain_id', $subdomain_id)
                                ->where('record_id', $record_id)
                                ->first();
                        } elseif ($record_name) {
                            $fullName = $record_name === '@' ? $record->subdomain : ($record_name . '.' . $record->subdomain);
                            $targetRecord = Capsule::table('mod_cloudflare_dns_records')
                                ->where('subdomain_id', $subdomain_id)
                                ->where('name', $fullName)
                                ->first();
                        }

                        if ($targetRecord) {
                            list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($record, $module_settings);
                            if (!$cf) {
                                $msg = $providerError;
                                $msg_type = 'danger';
                            } else {
                                $newFullName = ($record_name === '@') ? $record->subdomain : ($record_name . '.' . $record->subdomain);
                                $caa_content = null;

                                if ($record_type_upper === 'CAA') {
                                    $caa_flag = intval($_POST['caa_flag'] ?? 0);
                                    $caa_tag = trim($_POST['caa_tag'] ?? 'issue');
                                    $caa_value = trim($_POST['caa_value'] ?? '');
                                    if ($caa_value === '') {
                                        $msg = self::actionText('dns.validation.caa_value_required', 'CAA记录的Value不能为空');
                                        $msg_type = 'warning';
                                        throw new Exception($msg);
                                    }
                                    $caa_content = $caa_flag . ' ' . $caa_tag . ' "' . str_replace('"', '\"', $caa_value) . '"';
                                    $res = $cf->updateDnsRecord($record->cloudflare_zone_id, $targetRecord->record_id, [
                                        'type' => $record_type_upper,
                                        'name' => $newFullName,
                                        'content' => $caa_content,
                                        'ttl' => $record_ttl
                                    ]);
                                } else {
                                    $res = $cf->updateDnsRecordRaw($record->cloudflare_zone_id, $targetRecord->record_id, [
                                        'type' => $record_type_upper,
                                        'name' => $newFullName,
                                        'content' => $record_content,
                                        'ttl' => $record_ttl,
                                        'line' => $line,
                                        'priority' => $record_priority
                                    ]);
                                }

                                if ($res['success']) {
                                    try {
                                        $fresh = $cf->getDnsRecords($record->cloudflare_zone_id, $record->subdomain);
                                        if (($fresh['success'] ?? false)) {
                                            foreach (($fresh['result'] ?? []) as $fr) {
                                                $exists = self::findLocalRecordByRemote($subdomain_id, $fr);
                                                if (!$exists) {
                                                    Capsule::table('mod_cloudflare_dns_records')->insert([
                                                        'subdomain_id' => $subdomain_id,
                                                        'zone_id' => $record->cloudflare_zone_id,
                                                        'record_id' => isset($fr['id']) ? (string) $fr['id'] : null,
                                                        'name' => ($fr['name'] ?? $record->subdomain),
                                                        'type' => strtoupper(trim((string)($fr['type'] ?? 'A'))),
                                                        'content' => (string)($fr['content'] ?? ''),
                                                        'ttl' => intval($fr['ttl'] ?? 600),
                                                        'proxied' => 0,
                                                        'status' => 'active',
                                                        'created_at' => date('Y-m-d H:i:s'),
                                                        'updated_at' => date('Y-m-d H:i:s')
                                                    ]);
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                    }

                                    CfSubdomainService::syncDnsHistoryFlag($subdomain_id);

                                    $final_content = $record_content;
                                    if ($record_type_upper === 'CAA' && $caa_content !== null) {
                                        $final_content = $caa_content;
                                    }

                                    $updatedRecordId = isset($res['result']['id']) ? (string) $res['result']['id'] : null;

                                    $updatePayload = [
                                        'type' => $record_type_upper,
                                        'name' => $newFullName,
                                        'content' => $final_content,
                                        'ttl' => $record_ttl,
                                        'proxied' => 0,
                                        'line' => $line,
                                        'priority' => in_array($record_type_upper, ['MX','SRV']) ? $record_priority : null,
                                        'updated_at' => date('Y-m-d H:i:s')
                                    ];
                                    if ($updatedRecordId !== null && $updatedRecordId !== '') {
                                        $updatePayload['record_id'] = $updatedRecordId;
                                        $targetRecord->record_id = $updatedRecordId;
                                    }

                                    Capsule::table('mod_cloudflare_dns_records')
                                        ->where('id', $targetRecord->id)
                                        ->update($updatePayload);

                                    if ($newFullName === $record->subdomain) {
                                        Capsule::table('mod_cloudflare_subdomain')
                                            ->where('id', $subdomain_id)
                                            ->update([
                                                'notes' => '已解析',
                                                'updated_at' => date('Y-m-d H:i:s')
                                            ]);
                                    }

                                    if (function_exists('cloudflare_subdomain_log')) {
                                        cloudflare_subdomain_log(
                                            'client_update_dns',
                                            ['record_id' => $targetRecord->record_id, 'type' => $record_type_upper, 'content' => $record_content, 'ttl' => $record_ttl, 'line' => $line],
                                            $userid,
                                            $subdomain_id
                                        );
                                    }

                                    if ($record_name === '@') {
                                        $msg = self::actionText('dns.update.success', 'DNS记录更新成功！域名解析已更新');
                                    } else {
                                        $msg = self::actionText('dns.update.success', 'DNS记录更新成功！域名解析已更新');
                                    }
                                    $msg_type = "success";
                                } else {
                                    $errorText = cfmod_format_provider_error($res['errors'] ?? '');
                                    $msg = self::actionText('dns.update.failed', 'DNS记录更新失败：%s', [$errorText]);
                                    $msg_type = "danger";
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $errorText = cfmod_format_provider_error($e->getMessage());
                $msg = self::actionText('dns.update.failed', 'DNS记录更新失败：%s', [$errorText]);
                $msg_type = "danger";
            }
        }
    }
}

// 处理CDN控制请求（同步记录表）
if($_POST['action'] == "toggle_cdn" && isset($_POST['subdomain_id'])) {
    $toggleCdnSubdomainId = intval($_POST['subdomain_id']);
    $toggleCdnRootdomain = self::getSubdomainRootdomain($toggleCdnSubdomainId);
    if (self::isRootdomainInMaintenance($toggleCdnRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$toggleCdnRootdomain]);
        $msg_type = 'warning';
    } elseif (!$disableDnsWrite && !$isUserBannedOrInactive && self::shouldUseAsyncDns('toggle_cdn', $module_settings, $isAsyncReplay)) {
        $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'toggle_cdn');
        $msg = self::formatAsyncQueuedMessage($jobId);
        $msg_type = 'info';
    } elseif ($disableDnsWrite) {
        $msg = self::actionText('dns.operations_disabled', '当前已禁止新增/修改 DNS 记录');
        $msg_type = 'warning';
    } else {
        if ($isUserBannedOrInactive) {
            $msg = self::actionText('dns.cdn.domain.banned', '您的账号已被封禁或停用，禁止更改CDN代理状态。') . ($banReasonText ? (' ' . $banReasonText) : '');
            $msg_type = 'danger';
        }

        $subdomain_id = intval($_POST['subdomain_id']);
        $proxied = $_POST['proxied'] == '1';

        try {
            $record = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomain_id)
                ->where('userid', $userid)
                ->first();

            if ($record && $record->dns_record_id) {
                if ($record->status === 'suspended') {
                    $msg = self::actionText('dns.domain_suspended', '该域名已被暂停，无法进行解析操作');
                    $msg_type = "warning";
                } else {
                    list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($record, $module_settings);
                    if (!$cf) {
                        $msg = $providerError;
                        $msg_type = 'danger';
                    } else {
                        $res = $cf->toggleProxy($record->cloudflare_zone_id, $record->dns_record_id, $proxied);

                        if ($res['success']) {
                            Capsule::table('mod_cloudflare_dns_records')
                                ->where('subdomain_id', $subdomain_id)
                                ->where('record_id', $record->dns_record_id)
                                ->update([
                                    'proxied' => $proxied ? 1 : 0,
                                    'updated_at' => date('Y-m-d H:i:s')
                                ]);

                            if (function_exists('cloudflare_subdomain_log')) {
                                cloudflare_subdomain_log('client_toggle_cdn', ['proxied' => $proxied], $userid, $subdomain_id);
                            }

                            $statusLabel = $proxied ? self::actionText('common.enabled', '启用') : self::actionText('common.disabled', '禁用');
                            $msg = self::actionText('dns.cdn.domain.status', 'CDN状态已%s', [$statusLabel]);
                            $msg_type = "success";
                        } else {
                            $msg = self::actionText('dns.cdn.domain.update_failed', 'CDN状态更新失败');
                            $msg_type = "danger";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $errorText = cfmod_format_provider_error($e->getMessage(), self::actionText('dns.cdn.domain.retry', 'CDN状态更新失败，请稍后再试。'));
            $msg = self::actionText('dns.cdn.domain.control_failed', 'CDN控制失败：%s', [$errorText]);
            $msg_type = "danger";
        }
    }
}

// 处理单条记录的CDN代理开关
if($_POST['action'] == "toggle_record_cdn" && isset($_POST['subdomain_id']) && isset($_POST['record_id'])) {
    $toggleRecordCdnSubdomainId = intval($_POST['subdomain_id']);
    $toggleRecordCdnRootdomain = self::getSubdomainRootdomain($toggleRecordCdnSubdomainId);
    if (self::isRootdomainInMaintenance($toggleRecordCdnRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$toggleRecordCdnRootdomain]);
        $msg_type = 'warning';
    } elseif (!$disableDnsWrite && !$isUserBannedOrInactive && self::shouldUseAsyncDns('toggle_record_cdn', $module_settings, $isAsyncReplay)) {
        $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'toggle_record_cdn');
        $msg = self::formatAsyncQueuedMessage($jobId);
        $msg_type = 'info';
    } elseif ($disableDnsWrite) {
        $msg = self::actionText('dns.operations_disabled', '当前已禁止新增/修改 DNS 记录');
        $msg_type = 'warning';
    } else {
        if ($isUserBannedOrInactive) {
            $msg = self::actionText('dns.cdn.record.banned', '您的账号已被封禁或停用，禁止更改记录的CDN代理状态。') . ($banReasonText ? (' ' . $banReasonText) : '');
            $msg_type = 'danger';
        }

        $subdomain_id = intval($_POST['subdomain_id']);
        $record_id = trim($_POST['record_id']);
        $proxied = $_POST['proxied'] == '1';

        try {
            $sub = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomain_id)
                ->where('userid', $userid)
                ->first();

            if ($sub) {
                if ($sub->status === 'suspended') {
                    $msg = self::actionText('dns.domain_suspended', '该域名已被暂停，无法进行解析操作');
                    $msg_type = "warning";
                } else {
                    $rec = Capsule::table('mod_cloudflare_dns_records')
                        ->where('subdomain_id', $subdomain_id)
                        ->where('record_id', $record_id)
                        ->first();

                    if ($rec && in_array($rec->type, ['A','AAAA','CNAME'])) {
                        list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($sub, $module_settings);
                        if (!$cf) {
                            $msg = $providerError;
                            $msg_type = 'danger';
                        } else {
                            $res = $cf->toggleProxy($sub->cloudflare_zone_id, $record_id, $proxied);
                            if ($res['success']) {
                                Capsule::table('mod_cloudflare_dns_records')
                                    ->where('id', $rec->id)
                                    ->update([
                                        'proxied' => $proxied ? 1 : 0,
                                        'updated_at' => date('Y-m-d H:i:s')
                                    ]);

                                if (function_exists('cloudflare_subdomain_log')) {
                                    cloudflare_subdomain_log('client_toggle_record_cdn', ['record_id' => $record_id, 'proxied' => $proxied], $userid, $subdomain_id);
                                }

                                $statusLabel = $proxied ? self::actionText('common.enabled', '启用') : self::actionText('common.disabled', '禁用');
                                $msg = self::actionText('dns.cdn.record.status', '记录CDN状态已%s', [$statusLabel]);
                                $msg_type = "success";
                            } else {
                                $msg = self::actionText('dns.cdn.record.update_failed', '记录CDN状态更新失败');
                                $msg_type = "danger";
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $errorText = cfmod_format_provider_error($e->getMessage());
            $msg = self::actionText('dns.cdn.record.control_failed', '记录CDN控制失败：%s', [$errorText]);
            $msg_type = "danger";
        }
    }
}

// 处理DNS记录删除请求（仅删除某条记录）- 删除操作不检测VPN
if($_POST['action'] == "delete_dns_record" && isset($_POST['record_id']) && isset($_POST['subdomain_id'])) {
    $deleteDnsSubdomainId = intval($_POST['subdomain_id']);
    $deleteDnsRootdomain = self::getSubdomainRootdomain($deleteDnsSubdomainId);
    if (self::isRootdomainInMaintenance($deleteDnsRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$deleteDnsRootdomain]);
        $msg_type = 'warning';
    } elseif ($isUserBannedOrInactive) {
        $msg = self::actionText('dns.delete.banned', '您的账号已被封禁或停用，禁止删除DNS记录。') . ($banReasonText ? (' ' . $banReasonText) : '');
        $msg_type = 'danger';
    } elseif (self::shouldUseAsyncDns('delete_dns_record', $module_settings, $isAsyncReplay)) {
        $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'delete_dns_record');
        $msg = self::formatAsyncQueuedMessage($jobId);
        $msg_type = 'info';
        return [
            'msg' => $msg,
            'msg_type' => $msg_type,
            'registerError' => $registerError,
        ];
    }

    $subdomain_id = intval($_POST['subdomain_id']);
    $record_id = trim($_POST['record_id']);

    try {
        $sub = Capsule::table('mod_cloudflare_subdomain')
            ->where('id', $subdomain_id)
            ->where('userid', $userid)
            ->first();
        if (!$sub) {
            throw new \RuntimeException('subdomain_not_found');
        }

        $rec = Capsule::table('mod_cloudflare_dns_records')
            ->where('subdomain_id', $subdomain_id)
            ->where('record_id', $record_id)
            ->first();
        if (!$rec && ctype_digit($record_id)) {
            $rec = Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $subdomain_id)
                ->where('id', intval($record_id))
                ->first();
        }
        if (!$rec) {
            throw new \RuntimeException('record_not_found');
        }

        list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($sub, $module_settings);
        if (!$cf) {
            throw new \RuntimeException($providerError ?: 'provider_unavailable');
        }

        $effectiveZone = (string) ($rec->zone_id ?: ($sub->cloudflare_zone_id ?: $sub->rootdomain));
        $effectiveRecordId = trim((string) ($rec->record_id ?? ''));
        $deleteFailed = null;

        if ($effectiveRecordId === '') {
            $resolvedRecordId = self::findRemoteRecordIdForLocal($cf, $effectiveZone, $rec);
            if ($resolvedRecordId === null) {
                $deleteFailed = 'provider lookup failed';
            } else {
                $effectiveRecordId = $resolvedRecordId;
            }
        }

        if ($deleteFailed === null && $effectiveRecordId !== '') {
            try {
                $delRes = $cf->deleteSubdomain($effectiveZone, $effectiveRecordId, [
                    'name' => $rec->name ?? null,
                    'type' => $rec->type ?? null,
                    'content' => $rec->content ?? null,
                ]);
                if (!($delRes['success'] ?? false)) {
                    $errorCode = $delRes['code'] ?? null;
                    $errorMessage = $delRes['errors'] ?? ($delRes['message'] ?? '');
                    if (is_array($errorMessage)) {
                        $errorMessage = json_encode($errorMessage, JSON_UNESCAPED_UNICODE);
                    }
                    $errorMessage = (string) $errorMessage;
                    $isNotFound = $errorCode === 404
                        || $errorCode === '404'
                        || stripos($errorMessage, 'not found') !== false
                        || stripos($errorMessage, '不存在') !== false
                        || stripos($errorMessage, 'does not exist') !== false
                        || stripos($errorMessage, 'record not found') !== false;
                    if (!$isNotFound) {
                        $deleteFailed = $errorMessage;
                    }
                }
            } catch (\Throwable $e) {
                $deleteFailed = $e->getMessage();
            }
        }

        if ($deleteFailed === null) {
            $remoteExists = self::remoteRecordExistsForLocal($cf, $effectiveZone, $rec);
            if ($remoteExists === null) {
                $deleteFailed = 'provider verify failed';
            } elseif ($remoteExists === true) {
                $deleteFailed = 'remote delete not confirmed';
            }
        }

        if ($deleteFailed !== null) {
            throw new \RuntimeException('dns_delete_failed: ' . (string) $deleteFailed);
        }

        $result = Capsule::transaction(function () use ($subdomain_id, $userid, $rec, $effectiveRecordId) {
            $sub = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomain_id)
                ->where('userid', $userid)
                ->lockForUpdate()
                ->first();
            if (!$sub) {
                throw new \RuntimeException('subdomain_not_found');
            }

            $deletedRows = Capsule::table('mod_cloudflare_dns_records')
                ->where('id', intval($rec->id))
                ->where('subdomain_id', $subdomain_id)
                ->delete();
            if ($deletedRows <= 0) {
                throw new \RuntimeException('record_not_found');
            }

            $subDnsRecordId = trim((string) ($sub->dns_record_id ?? ''));
            $localRecordId = trim((string) ($rec->record_id ?? ''));
            if ((string) ($rec->name ?? '') === (string) ($sub->subdomain ?? '')
                && ($subDnsRecordId !== '' && ($subDnsRecordId === $localRecordId || $subDnsRecordId === $effectiveRecordId))) {
                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomain_id)
                    ->update([
                        'dns_record_id' => null,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }

            $remainingRecords = Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $subdomain_id)
                ->count();

            if ($remainingRecords == 0) {
                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomain_id)
                    ->update([
                        'notes' => '已注册，等待解析设置',
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }

            CfSubdomainService::syncDnsHistoryFlag($subdomain_id);

            return [
                'subdomain_id' => $subdomain_id,
                'record_id' => (string) ($effectiveRecordId !== '' ? $effectiveRecordId : ($rec->record_id ?? '')),
                'record_name' => $rec->name ?? '',
                'record_type' => $rec->type ?? '',
            ];
        });

        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('client_delete_dns_record', [
                'record_id' => $result['record_id'],
                'name' => $result['record_name'],
                'type' => $result['record_type']
            ], $userid, $result['subdomain_id']);
        }

        $msg = self::actionText('dns.delete.success', '已删除DNS记录');
        $msg_type = "success";

    } catch (\Throwable $e) {
        $errorMessage = $e->getMessage();

        if (strpos($errorMessage, 'subdomain_not_found') !== false) {
            $msg = self::actionText('dns.delete.subdomain_not_found', '域名不存在或已被删除，请刷新页面');
            $msg_type = 'warning';
        } elseif (strpos($errorMessage, 'record_not_found') !== false) {
            $msg = self::actionText('dns.delete.record_not_found', 'DNS记录不存在或已被删除，请刷新页面');
            $msg_type = 'warning';
        } elseif (strpos($errorMessage, 'provider_unavailable') !== false) {
            $msg = self::actionText('dns.delete.provider_error', 'DNS供应商暂时不可用，请稍后再试');
            $msg_type = 'danger';
        } elseif (strpos($errorMessage, 'dns_delete_failed:') !== false) {
            $errorDetail = cfmod_format_provider_error(str_replace('dns_delete_failed: ', '', $errorMessage));
            $msg = self::actionText('dns.delete.failed_detail', '删除DNS记录失败：%s', [$errorDetail]);
            $msg_type = 'danger';
        } else {
            $errorDetail = cfmod_format_provider_error($errorMessage);
            $msg = self::actionText('dns.delete.failed_detail', '删除DNS记录失败：%s', [$errorDetail]);
            $msg_type = 'danger';
        }
    }
}

// 一键替换入整组 NS（域名委派）
if($_POST['action'] == 'replace_ns_group' && isset($_POST['subdomain_id'])) {
    $replaceNsSubdomainId = intval($_POST['subdomain_id']);
    $replaceNsRootdomain = self::getSubdomainRootdomain($replaceNsSubdomainId);
    if (self::isRootdomainInMaintenance($replaceNsRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$replaceNsRootdomain]);
        $msg_type = 'warning';
        return [
            'msg' => $msg,
            'msg_type' => $msg_type,
            'registerError' => $registerError,
        ];
    }
    if ($enableDnsUnlockFeature && !CfDnsUnlockService::userHasUnlocked($userid ?? 0)) {
        $msg = self::actionText('dns.unlock.required', '请先完成 DNS 解锁后再设置 DNS 服务器。');
        $msg_type = 'warning';
        return [
            'msg' => $msg,
            'msg_type' => $msg_type,
            'registerError' => $registerError,
        ];
    }
    // VPN/代理检测（NS替换操作）
    if (class_exists('CfVpnDetectionService') && CfVpnDetectionService::isDnsCheckEnabled($module_settings)) {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $vpnCheckResult = CfVpnDetectionService::shouldBlockDnsOperation($clientIp, $module_settings);
        if (!empty($vpnCheckResult['blocked'])) {
            $msg = self::actionText('dns.vpn_blocked', '检测到您正在使用VPN或代理，请关闭后再进行DNS操作。');
            $msg_type = 'warning';
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('vpn_detection_blocked_dns', [
                    'action' => 'replace_ns_group',
                    'ip' => $clientIp,
                    'reason' => $vpnCheckResult['reason'] ?? 'unknown',
                ], $userid ?? 0, null);
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }
    }
    if (!$disableNsManagement && !$disableDnsWrite && self::shouldUseAsyncDns('replace_ns_group', $module_settings, $isAsyncReplay)) {
        $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'replace_ns_group');
        $msg = self::formatAsyncQueuedMessage($jobId);
        $msg_type = 'info';
        return [
            'msg' => $msg,
            'msg_type' => $msg_type,
            'registerError' => $registerError,
        ];
    }

    if ($disableNsManagement || $disableDnsWrite) {
        $msg = self::actionText('dns.operations_disabled', '当前已禁止新增/修改 DNS 记录');
        $msg_type = 'warning';
    } else {
        if ($disableNsManagement) {
            $msg = self::actionText('dns.ns.disabled', '已禁止设置 DNS 服务器（NS）。');
            $msg_type = 'warning';
        } else {
            $subdomain_id = intval($_POST['subdomain_id']);
            $lines = trim($_POST['ns_lines'] ?? '');
            $forceReplace = isset($_POST['force_replace']) && $_POST['force_replace'] == '1';

            $preList = array_filter(
                array_unique(array_map(function ($x) {
                    return strtolower(trim($x));
                }, explode("\n", $lines))),
                function ($v) {
                    return !empty($v);
                }
            );
            if (count($preList) > $nsMaxPerDomain) {
                $msg = self::actionText('dns.ns.submission_limit', 'NS 服务器最多允许 %1$s 条；当前提交 %2$s 条，请删减后重试', [$nsMaxPerDomain, count($preList)]);
                $msg_type = 'warning';
                throw new Exception($msg);
            }

            try {
                $sub = Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomain_id)
                    ->where('userid', $userid)
                    ->first();

                if ($sub) {
                    if ($sub->status === 'suspended') {
                        $msg = self::actionText('dns.domain_suspended', '该域名已被暂停，无法进行解析操作');
                        $msg_type = "warning";
                    } else {
                        list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($sub, $module_settings);
                        if (!$cf) {
                            $msg = $providerError;
                            $msg_type = 'danger';
                        } else {
                            $zoneId = (string) ($sub->cloudflare_zone_id ?: $sub->rootdomain);
                            if ($zoneId === '') {
                                throw new Exception(self::actionText('dns.ns.zone_missing', '缺少 Zone 信息，请联系管理员检查域名配置。'));
                            }

                            $list = array_filter(
                                array_unique(array_map(function ($x) {
                                    return strtolower(trim($x));
                                }, explode("\n", $lines))),
                                function ($v) {
                                    return !empty($v);
                                }
                            );
                            $validList = [];
                            $invalidList = [];
                            $domainRegex = '/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+\.?$/i';
                            foreach ($list as $ns) {
                                if (preg_match($domainRegex, $ns)) {
                                    $validList[] = rtrim($ns, '.');
                                } else {
                                    $invalidList[] = $ns;
                                }
                            }
                            if (count($validList) === 0) {
                                $msg = self::actionText('dns.ns.no_valid_servers', '未检测到有效的 NS 服务器，请检查格式（每行一个完整域名）');
                                $msg_type = 'warning';
                                throw new Exception($msg);
                            }

                            $deleteLocalByRemote = static function (int $targetSubdomainId, array $remoteRecord): void {
                                $recordId = trim((string) ($remoteRecord['id'] ?? ''));
                                if ($recordId !== '') {
                                    $deleted = Capsule::table('mod_cloudflare_dns_records')
                                        ->where('subdomain_id', $targetSubdomainId)
                                        ->where('record_id', $recordId)
                                        ->delete();
                                    if ($deleted > 0) {
                                        return;
                                    }
                                }

                                $name = strtolower(trim((string) ($remoteRecord['name'] ?? '')));
                                $type = strtoupper(trim((string) ($remoteRecord['type'] ?? '')));
                                $content = trim((string) ($remoteRecord['content'] ?? ''));
                                if ($name === '' || $type === '') {
                                    return;
                                }

                                $match = Capsule::table('mod_cloudflare_dns_records')
                                    ->where('subdomain_id', $targetSubdomainId)
                                    ->whereRaw('LOWER(name) = ?', [$name])
                                    ->whereRaw('UPPER(type) = ?', [$type])
                                    ->where(function ($query) use ($content) {
                                        $query->where('content', $content)
                                            ->orWhereRaw('LOWER(content) = ?', [strtolower($content)]);
                                    })
                                    ->orderBy('id', 'asc')
                                    ->first();
                                if ($match) {
                                    Capsule::table('mod_cloudflare_dns_records')
                                        ->where('id', intval($match->id ?? 0))
                                        ->delete();
                                }
                            };

                            $resolveDeleteErrorText = static function ($response): string {
                                if (!is_array($response)) {
                                    return '';
                                }
                                $errorMessage = $response['errors'] ?? ($response['message'] ?? ($response['error'] ?? ''));
                                if (is_array($errorMessage)) {
                                    $errorMessage = json_encode($errorMessage, JSON_UNESCAPED_UNICODE);
                                }
                                return trim((string) $errorMessage);
                            };

                            $isProviderDeleteSuccess = static function ($response) use ($resolveDeleteErrorText): bool {
                                if (!is_array($response)) {
                                    return false;
                                }
                                if (!empty($response['success'])) {
                                    return true;
                                }
                                $errorCode = $response['code'] ?? ($response['http_code'] ?? null);
                                if ($errorCode === 404 || $errorCode === '404') {
                                    return true;
                                }
                                $errorText = strtolower($resolveDeleteErrorText($response));
                                if ($errorText === '') {
                                    return false;
                                }
                                return strpos($errorText, 'not found') !== false
                                    || strpos($errorText, 'record not found') !== false
                                    || strpos($errorText, 'does not exist') !== false
                                    || strpos($errorText, '不存在') !== false;
                            };

                            $limitPerSub = intval($module_settings['max_dns_records_per_subdomain'] ?? 0);
                            if ($limitPerSub > 0) {
                                $currentCount = Capsule::table('mod_cloudflare_dns_records')
                                    ->where('subdomain_id', $subdomain_id)
                                    ->count();
                                $plannedNsRemove = Capsule::table('mod_cloudflare_dns_records')
                                    ->where('subdomain_id', $subdomain_id)
                                    ->whereRaw('LOWER(name) = ?', [strtolower((string) $sub->subdomain)])
                                    ->whereRaw('UPPER(type) = ?', ['NS'])
                                    ->count();
                                $plannedConflictRemove = 0;
                                if ($forceReplace) {
                                    $plannedConflictRemove = Capsule::table('mod_cloudflare_dns_records')
                                        ->where('subdomain_id', $subdomain_id)
                                        ->whereRaw('LOWER(name) = ?', [strtolower((string) $sub->subdomain)])
                                        ->whereRaw('UPPER(type) <> ?', ['NS'])
                                        ->count();
                                }
                                $projectedCount = max(0, intval($currentCount) - intval($plannedNsRemove) - intval($plannedConflictRemove)) + count($validList);
                                if ($projectedCount > $limitPerSub) {
                                    $msg = self::actionText('dns.ns.limit_exceeded', '此次替换将超出该域名的解析数量上限（%s），已取消操作', [$limitPerSub]);
                                    $msg_type = 'warning';
                                    throw new Exception($msg);
                                }
                            }

                            $deletedCount = 0;
                            $conflictDeleted = 0;
                            $cleanupErrors = [];

                            $existing = $cf->getDnsRecords($zoneId, $sub->subdomain, ['type' => 'NS', 'per_page' => 1000]);
                            if (!($existing['success'] ?? false)) {
                                $errorText = $resolveDeleteErrorText($existing);
                                throw new Exception(self::actionText('dns.ns.fetch_failed', '读取现有 NS 记录失败：%s', [$errorText !== '' ? $errorText : self::actionText('errors.unknown', '未知错误')]));
                            }
                            foreach (($existing['result'] ?? []) as $r) {
                                $recordType = strtoupper((string) ($r['type'] ?? ''));
                                $recordName = strtolower((string) ($r['name'] ?? ''));
                                if ($recordType !== 'NS' || $recordName !== strtolower((string) $sub->subdomain)) {
                                    continue;
                                }
                                $recordId = trim((string) ($r['id'] ?? ''));
                                if ($recordId === '') {
                                    $cleanupErrors[] = ['record' => 'NS:?', 'error' => 'missing_record_id'];
                                    continue;
                                }
                                $delRes = $cf->deleteSubdomain($zoneId, $recordId, [
                                    'name' => $r['name'] ?? $sub->subdomain,
                                    'type' => $r['type'] ?? 'NS',
                                    'content' => $r['content'] ?? null,
                                ]);
                                if ($isProviderDeleteSuccess($delRes)) {
                                    $deletedCount++;
                                    $deleteLocalByRemote($subdomain_id, $r);
                                } else {
                                    $cleanupErrors[] = [
                                        'record' => 'NS:' . $recordId,
                                        'error' => $resolveDeleteErrorText($delRes),
                                    ];
                                }
                            }

                            if ($forceReplace) {
                                $allAt = $cf->getDnsRecords($zoneId, $sub->subdomain, ['per_page' => 1000]);
                                if (!($allAt['success'] ?? false)) {
                                    $errorText = $resolveDeleteErrorText($allAt);
                                    throw new Exception(self::actionText('dns.ns.fetch_failed', '读取现有 NS 记录失败：%s', [$errorText !== '' ? $errorText : self::actionText('errors.unknown', '未知错误')]));
                                }
                                foreach (($allAt['result'] ?? []) as $r) {
                                    $recordType = strtoupper((string) ($r['type'] ?? ''));
                                    $recordName = strtolower((string) ($r['name'] ?? ''));
                                    if ($recordName !== strtolower((string) $sub->subdomain) || $recordType === 'NS') {
                                        continue;
                                    }
                                    $recordId = trim((string) ($r['id'] ?? ''));
                                    if ($recordId === '') {
                                        $cleanupErrors[] = ['record' => $recordType . ':?', 'error' => 'missing_record_id'];
                                        continue;
                                    }
                                    $delRes = $cf->deleteSubdomain($zoneId, $recordId, [
                                        'name' => $r['name'] ?? $sub->subdomain,
                                        'type' => $r['type'] ?? $recordType,
                                        'content' => $r['content'] ?? null,
                                    ]);
                                    if ($isProviderDeleteSuccess($delRes)) {
                                        $conflictDeleted++;
                                        $deleteLocalByRemote($subdomain_id, $r);
                                    } else {
                                        $cleanupErrors[] = [
                                            'record' => $recordType . ':' . $recordId,
                                            'error' => $resolveDeleteErrorText($delRes),
                                        ];
                                    }
                                }
                            }

                            if (!empty($cleanupErrors)) {
                                $preview = [];
                                foreach (array_slice($cleanupErrors, 0, 3) as $entry) {
                                    $preview[] = ($entry['record'] ?? '?') . ' => ' . (($entry['error'] ?? '') !== '' ? $entry['error'] : self::actionText('errors.unknown', '未知错误'));
                                }
                                throw new Exception(self::actionText('dns.ns.cleanup_failed', '清理旧记录失败：%s', [implode('; ', $preview)]));
                            }

                            $created = [];
                            $errors = [];
                            foreach ($validList as $ns) {
                                $res = $cf->createDnsRecord($zoneId, $sub->subdomain, 'NS', $ns, 86400, false);
                                if ($res['success']) {
                                    $rid = $res['result']['id'];
                                    Capsule::table('mod_cloudflare_dns_records')->insert([
                                        'subdomain_id' => $subdomain_id,
                                        'zone_id' => $zoneId,
                                        'record_id' => $rid !== null ? (string) $rid : null,
                                        'name' => $sub->subdomain,
                                        'type' => 'NS',
                                        'content' => $ns,
                                        'ttl' => 86400,
                                        'proxied' => 0,
                                        'line' => null,
                                        'status' => 'active',
                                        'created_at' => date('Y-m-d H:i:s'),
                                        'updated_at' => date('Y-m-d H:i:s')
                                    ]);
                                    $created[] = $ns;
                                } else {
                                    $errMsg = self::actionText('errors.unknown', '未知错误');
                                    if (!empty($res['errors'])) {
                                        $errMsg = is_array($res['errors'])
                                            ? json_encode($res['errors'], JSON_UNESCAPED_UNICODE)
                                            : (string) $res['errors'];
                                    }
                                    $errors[] = ['ns' => $ns, 'error' => $errMsg];
                                }
                            }
                            CfSubdomainService::syncDnsHistoryFlag($subdomain_id);

                            if (function_exists('cloudflare_subdomain_log')) {
                                cloudflare_subdomain_log('client_replace_ns_group', [
                                    'deletedCount' => $deletedCount,
                                    'conflictDeleted' => $conflictDeleted,
                                    'created' => $created,
                                    'invalid' => $invalidList,
                                    'errors' => $errors
                                ], $userid, $subdomain_id);
                            }

                            $parts = [];
                            $parts[] = self::actionText('dns.ns.summary.deleted', '已删除旧 NS %s 条', [$deletedCount]);
                            if ($forceReplace) {
                                $parts[] = self::actionText('dns.ns.summary.conflicts', '已清理冲突记录 %s 条', [$conflictDeleted]);
                            }
                            $parts[] = self::actionText('dns.ns.summary.created', '新增成功 %s 条', [count($created)]);
                            if (count($invalidList) > 0) {
                                $preview = implode(', ', array_slice($invalidList, 0, 3));
                                if ($preview) {
                                    $parts[] = self::actionText('dns.ns.summary.invalid_preview', '忽略无效格式 %1$s 条（示例：%2$s）', [count($invalidList), $preview]);
                                } else {
                                    $parts[] = self::actionText('dns.ns.summary.invalid', '忽略无效格式 %s 条', [count($invalidList)]);
                                }
                            }
                            if (count($errors) > 0) {
                                $previewErr = [];
                                foreach (array_slice($errors, 0, 3) as $er) {
                                    $previewErr[] = ($er['ns'] ?? '?') . ' => ' . ($er['error'] ?? '');
                                }
                                if (count($previewErr)) {
                                    $parts[] = self::actionText('dns.ns.summary.failed_preview', '新增失败 %1$s 条（示例：%2$s）', [count($errors), implode('; ', $previewErr)]);
                                } else {
                                    $parts[] = self::actionText('dns.ns.summary.failed', '新增失败 %s 条', [count($errors)]);
                                }
                            }
                            $separator = self::actionText('common.list_separator', '，');
                            $msg = implode($separator, $parts);
                            $msg_type = (count($created) > 0 && count($errors) === 0)
                                ? 'success'
                                : (count($created) > 0 ? 'warning' : 'danger');

                            list($existing, $existing_total, $domainTotalPages, $domainPage) = cfmod_client_load_subdomains_paginated(
                                $userid,
                                $domainPage,
                                $domainPageSize,
                                $domainSearchTerm
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                if (!isset($msg) || !$msg) {
                    $errorText = cfmod_format_provider_error($e->getMessage());
                    $msg = self::actionText('dns.ns.bulk_failed', '批量替换 NS 失败：%s', [$errorText]);
                }
                $msg_type = isset($msg_type) && $msg_type ? $msg_type : 'danger';
            }
        }
    }
}



        return [
            'msg' => $msg,
            'msg_type' => $msg_type,
            'registerError' => $registerError,
        ];
    }

    private static function enforceClientRateLimit(string $action, array $settings, int $userid): void
    {
        $scope = self::resolveClientRateLimitScope($action);
        if ($scope === null) {
            return;
        }
        $limit = CfRateLimiter::resolveLimit($scope, $settings);
        CfRateLimiter::enforce($scope, $limit, [
            'userid' => $userid,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'identifier' => $action,
        ]);
    }

    private static function resolveClientRateLimitScope(string $action): ?string
    {
        static $dnsActions = ['create_dns', 'update_dns', 'delete_dns_record', 'toggle_cdn', 'toggle_record_cdn', 'delete_subdomain'];
        static $quotaActions = ['claim_invite', 'request_invite_reward', 'claim_github_star_reward', 'claim_telegram_group_reward', 'update_expiry_telegram_reminder', 'submit_partner_application', 'upgrade_permanent'];
        if ($action === 'register') {
            return CfRateLimiter::SCOPE_REGISTER;
        }
        if (in_array($action, $dnsActions, true)) {
            return CfRateLimiter::SCOPE_DNS;
        }
        if (in_array($action, $quotaActions, true)) {
            return CfRateLimiter::SCOPE_QUOTA_GIFT;
        }
        if ($action === 'dns_unlock' || $action === 'purchase_dns_unlock') {
            return CfRateLimiter::SCOPE_DNS_UNLOCK;
        }
        return null;
    }

    private static function shouldUseAsyncDns(string $action, array $settings, bool $isAsyncReplay): bool
    {
        if ($isAsyncReplay) {
            return false;
        }
        if (!cfmod_setting_enabled($settings['enable_async_dns_operations'] ?? '0')) {
            return false;
        }
        static $supported = ['create_dns', 'update_dns', 'delete_dns_record', 'replace_ns_group', 'toggle_cdn', 'toggle_record_cdn'];
        return in_array($action, $supported, true);
    }

    private static function shouldSkipProviderExistsCheck(array $providerContext, array $settings, string $rootdomain = ''): bool
    {
        $providerType = strtolower(trim((string) ($providerContext['account']['provider_type'] ?? ($providerContext['provider_type'] ?? ''))));
        if ($providerType !== '') {
            $isPdns = ($providerType === 'powerdns');
        } else {
            $client = $providerContext['client'] ?? null;
            $isPdns = is_object($client) && stripos(get_class($client), 'powerdns') !== false;
        }

        if (!$isPdns) {
            return false;
        }

        $strategy = strtolower(trim((string) ($settings['pdns_register_strategy'] ?? '')));
        if (!in_array($strategy, ['local_only', 'hybrid', 'strict_remote'], true)) {
            $legacyRaw = trim((string) ($settings['pdns_register_local_check_only'] ?? '1'));
            if ($legacyRaw === '') {
                $strategy = 'local_only';
            } else {
                $strategy = cfmod_setting_enabled($legacyRaw) ? 'local_only' : 'strict_remote';
            }
        }

        if ($strategy === 'local_only') {
            return true;
        }
        if ($strategy === 'strict_remote') {
            return false;
        }

        $thresholdRaw = trim((string) ($settings['pdns_register_hybrid_local_threshold'] ?? '800'));
        $threshold = is_numeric($thresholdRaw) ? (int) $thresholdRaw : 800;
        $threshold = max(100, min(50000, $threshold));

        $normalizedRoot = strtolower(trim($rootdomain));
        if ($normalizedRoot === '') {
            return false;
        }

        try {
            $localSubdomains = (int) Capsule::table('mod_cloudflare_subdomain')
                ->whereRaw('LOWER(rootdomain) = ?', [$normalizedRoot])
                ->count();
        } catch (\Throwable $e) {
            return true;
        }

        return $localSubdomains >= $threshold;
    }

    private static function enqueueAsyncDnsJob(int $userid, string $action): ?int
    {
        if (!class_exists('CfAsyncDnsJobService')) {
            return null;
        }
        if ($userid <= 0) {
            return null;
        }
        $payload = self::buildAsyncPostPayload($_POST);
        return CfAsyncDnsJobService::enqueue($userid, $action, $payload);
    }

    private static function buildAsyncPostPayload(array $input): array
    {
        $payload = $input;
        unset($payload['token'], $payload['cfmod_csrf_token']);
        $payload['__cf_async_dns'] = 1;
        return $payload;
    }

    private static function formatAsyncQueuedMessage(?int $jobId): string
    {
        $message = self::actionText('dns.async.queued', '操作已提交到后台队列，将在稍后自动执行。');
        if ($jobId) {
            $message .= ' ' . self::actionText('dns.async.job', '任务编号：#%s', [$jobId]);
        }
        return $message;
    }

    private static function normalizePartnerWebsite(string $websiteRaw): string
    {
        $website = trim($websiteRaw);
        if ($website === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            $website = mb_substr($website, 0, 255, 'UTF-8');
        } else {
            $website = substr($website, 0, 255);
        }
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if (!filter_var($website, FILTER_VALIDATE_URL)) {
            return '';
        }
        $host = parse_url($website, PHP_URL_HOST);
        if (!is_string($host) || trim($host) === '') {
            return '';
        }
        return $website;
    }

    private static function resolvePartnerAdminRecipients(array $settings): array
    {
        $raw = trim((string) ($settings['partner_plan_admin_email'] ?? ''));
        if ($raw === '') {
            $raw = self::resolveSystemFromEmail();
        }
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $result = [];
        foreach ($parts as $item) {
            $email = trim((string) $item);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $result[strtolower($email)] = $email;
        }
        return array_values($result);
    }

    private static function resolveSystemFromEmail(): string
    {
        try {
            $candidates = Capsule::table('tblconfiguration')
                ->whereIn('setting', ['SystemEmailsFromEmail', 'Email', 'SupportEmail'])
                ->get();
            foreach ($candidates as $row) {
                $value = trim((string) ($row->value ?? ''));
                if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return $value;
                }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    private static function buildPartnerApplicationEmailBody(array $payload): string
    {
        $lines = [
            '收到新的合作伙伴计划申请：',
            '',
            '提交时间：' . (string) ($payload['submitted_at'] ?? ''),
            '用户ID：' . (string) ($payload['userid'] ?? 0),
            '用户姓名：' . (string) ($payload['client_name'] ?? ''),
            '用户邮箱：' . (string) ($payload['client_email'] ?? ''),
            '网站地址：' . (string) ($payload['website'] ?? ''),
            '提交IP：' . (string) ($payload['ip'] ?? ''),
            '',
            '申请原因：',
            (string) ($payload['reason'] ?? ''),
        ];

        return implode("\n", $lines);
    }

    private static function sendPartnerApplicationEmail(array $recipients, string $subject, string $body, string $replyTo = ''): void
    {
        if (empty($recipients)) {
            throw new \RuntimeException('missing_recipient');
        }

        $normalizedSubject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
        if ($normalizedSubject === '') {
            $normalizedSubject = 'Partner Application';
        }

        if (function_exists('mb_encode_mimeheader')) {
            $encodedSubject = mb_encode_mimeheader($normalizedSubject, 'UTF-8', 'B', "\r\n");
        } else {
            $encodedSubject = $normalizedSubject;
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $fromEmail = self::resolveSystemFromEmail();
        if ($fromEmail !== '') {
            $headers[] = 'From: ' . $fromEmail;
        }

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $headerString = implode("\r\n", $headers);
        $sent = 0;

        foreach ($recipients as $recipient) {
            $target = trim((string) $recipient);
            if ($target === '') {
                continue;
            }
            if (@mail($target, $encodedSubject, $body, $headerString)) {
                $sent++;
            }
        }

        if ($sent <= 0) {
            throw new \RuntimeException('邮件发送失败，请检查服务器 mail 配置。');
        }
    }

    private static function actionTextByLanguage(string $key, string $zhDefault, string $enDefault, array $params = []): string
    {
        $default = self::isChineseLanguage() ? $zhDefault : $enDefault;
        return self::actionText($key, $default, $params);
    }

    private static function isChineseLanguage(): bool
    {
        try {
            if (function_exists('cfmod_resolve_language_preference')) {
                $meta = cfmod_resolve_language_preference();
                $normalized = strtolower(trim((string) ($meta['normalized'] ?? '')));
                if ($normalized !== '') {
                    return $normalized === 'chinese';
                }
            }
        } catch (\Throwable $e) {
        }

        $sessionLanguage = strtolower(trim((string) ($_SESSION['Language'] ?? ($_SESSION['language'] ?? 'english'))));
        return $sessionLanguage === 'chinese';
    }

    private static function actionText(string $key, string $default, array $params = []): string
    {
        $text = cfmod_trans('cfclient.actions.' . $key, $default);
        if (!empty($params)) {
            try {
                $text = vsprintf($text, $params);
            } catch (\Throwable $e) {
                // ignore formatting errors
            }
        }
        return $text;
    }

    private static function resolveClientEmail(int $userid): string
    {
        if ($userid <= 0) {
            return '';
        }
        try {
            $row = Capsule::table('tblclients')->select('email')->where('id', $userid)->first();
            return trim((string) ($row->email ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function normalizeDnsContent(string $content, string $type = ''): string
    {
        $value = trim($content);
        if ($value === '') {
            return '';
        }
        if (strlen($value) >= 2 && substr($value, 0, 1) === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        }
        if (strpos($value, ' ') === false && substr($value, -1) === '.') {
            $value = rtrim($value, '.');
        }
        $recordType = strtoupper(trim($type));
        if ($recordType === 'TXT') {
            return $value;
        }
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function remoteRecordMatchesLocal(array $remoteRecord, $localRecord): bool
    {
        $remoteName = strtolower(trim((string) ($remoteRecord['name'] ?? '')));
        $remoteType = strtoupper(trim((string) ($remoteRecord['type'] ?? '')));
        $remoteContent = self::normalizeDnsContent((string) ($remoteRecord['content'] ?? ''), $remoteType);

        $localName = strtolower(trim((string) ($localRecord->name ?? '')));
        $localType = strtoupper(trim((string) ($localRecord->type ?? '')));
        $localContent = self::normalizeDnsContent((string) ($localRecord->content ?? ''), $localType);

        return $remoteName === $localName
            && $remoteType === $localType
            && $remoteContent === $localContent;
    }

    private static function fetchRemoteRecordsForLocal($providerClient, string $zoneId, $localRecord): ?array
    {
        if (!$providerClient || !method_exists($providerClient, 'getDnsRecords')) {
            return null;
        }

        try {
            $name = (string) ($localRecord->name ?? '');
            $type = strtoupper(trim((string) ($localRecord->type ?? '')));
            $params = ['per_page' => 1000];
            if ($type !== '') {
                $params['type'] = $type;
            }
            $listRes = $providerClient->getDnsRecords($zoneId, $name, $params);
            if (!($listRes['success'] ?? false)) {
                return null;
            }
            return is_array($listRes['result'] ?? null) ? ($listRes['result'] ?? []) : [];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function findRemoteRecordIdForLocal($providerClient, string $zoneId, $localRecord): ?string
    {
        $records = self::fetchRemoteRecordsForLocal($providerClient, $zoneId, $localRecord);
        if ($records === null) {
            return null;
        }
        foreach ($records as $remoteRecord) {
            if (!is_array($remoteRecord)) {
                continue;
            }
            if (!self::remoteRecordMatchesLocal($remoteRecord, $localRecord)) {
                continue;
            }
            $id = isset($remoteRecord['id']) ? trim((string) $remoteRecord['id']) : '';
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }

    private static function remoteRecordExistsForLocal($providerClient, string $zoneId, $localRecord): ?bool
    {
        $records = self::fetchRemoteRecordsForLocal($providerClient, $zoneId, $localRecord);
        if ($records === null) {
            return null;
        }
        foreach ($records as $remoteRecord) {
            if (!is_array($remoteRecord)) {
                continue;
            }
            if (self::remoteRecordMatchesLocal($remoteRecord, $localRecord)) {
                return true;
            }
        }
        return false;
    }

    private static function findLocalRecordByRemote(int $subdomainId, array $remoteRecord)
    {
        if ($subdomainId <= 0) {
            return null;
        }
        $remoteRecordId = $remoteRecord['id'] ?? ($remoteRecord['record_id'] ?? null);
        if ($remoteRecordId !== null && $remoteRecordId !== '') {
            $match = Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $subdomainId)
                ->where('record_id', (string) $remoteRecordId)
                ->first();
            if ($match) {
                return $match;
            }
        }

        $remoteNameLower = strtolower(trim((string)($remoteRecord['name'] ?? '')));
        $remoteTypeUpper = strtoupper(trim((string)($remoteRecord['type'] ?? '')));
        $remoteContent = (string)($remoteRecord['content'] ?? '');
        if ($remoteNameLower === '' || $remoteTypeUpper === '') {
            return null;
        }
        return Capsule::table('mod_cloudflare_dns_records')
            ->where('subdomain_id', $subdomainId)
            ->whereRaw('LOWER(name) = ?', [$remoteNameLower])
            ->whereRaw('UPPER(type) = ?', [$remoteTypeUpper])
            ->where(function ($query) use ($remoteContent) {
                $query->where('content', $remoteContent)
                    ->orWhereRaw('LOWER(content) = ?', [strtolower($remoteContent)]);
            })
            ->first();
    }

    private static function isRootdomainInMaintenance(string $rootdomain): bool
    {
        if ($rootdomain === '') {
            return false;
        }
        try {
            $row = Capsule::table('mod_cloudflare_rootdomains')
                ->select('maintenance')
                ->whereRaw('LOWER(domain) = ?', [strtolower($rootdomain)])
                ->first();
            if ($row) {
                return intval($row->maintenance ?? 0) === 1;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return false;
    }

    private static function getSubdomainRootdomain(int $subdomainId): string
    {
        if ($subdomainId <= 0) {
            return '';
        }
        try {
            $row = Capsule::table('mod_cloudflare_subdomain')
                ->select('rootdomain')
                ->where('id', $subdomainId)
                ->first();
            return (string)($row->rootdomain ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
