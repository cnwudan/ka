<?php
// 获取用户API密钥
$userId = $_SESSION['uid'];
$apiKeys = \WHMCS\Database\Capsule::table('mod_cloudflare_api_keys')
    ->where('userid', $userId)
    ->orderBy('created_at', 'desc')
    ->get();

$apiKeyIds = [];
foreach ($apiKeys as $apiKeyRow) {
    $apiKeyIds[] = intval($apiKeyRow->id ?? 0);
}
$apiKeyIds = array_values(array_filter(array_unique($apiKeyIds)));
if (!empty($apiKeyIds)) {
    try {
        $usageRows = \WHMCS\Database\Capsule::table('mod_cloudflare_api_logs')
            ->select('api_key_id', \WHMCS\Database\Capsule::raw('COUNT(*) as request_count'), \WHMCS\Database\Capsule::raw('MAX(created_at) as last_used_at'))
            ->whereIn('api_key_id', $apiKeyIds)
            ->groupBy('api_key_id')
            ->get();

        $usageMap = [];
        foreach ($usageRows as $usageRow) {
            $usageKeyId = intval($usageRow->api_key_id ?? 0);
            if ($usageKeyId <= 0) {
                continue;
            }
            $usageMap[$usageKeyId] = [
                'request_count' => intval($usageRow->request_count ?? 0),
                'last_used_at' => $usageRow->last_used_at ?? null,
            ];
        }

        foreach ($apiKeys as $apiKeyRow) {
            $usage = $usageMap[intval($apiKeyRow->id ?? 0)] ?? null;
            if ($usage === null) {
                continue;
            }
            $apiKeyRow->request_count = intval($usage['request_count'] ?? $apiKeyRow->request_count ?? 0);
            if (!empty($usage['last_used_at'])) {
                $apiKeyRow->last_used_at = $usage['last_used_at'];
            }
        }
    } catch (\Throwable $e) {
    }
}

$apiDailySeriesDays = 7;
$apiDailyCallStats = [];
$apiToday = new \DateTimeImmutable('today');
for ($offset = $apiDailySeriesDays - 1; $offset >= 0; $offset--) {
    $day = $apiToday->sub(new \DateInterval('P' . $offset . 'D'));
    $dayKey = $day->format('Y-m-d');
    $apiDailyCallStats[$dayKey] = [
        'date' => $dayKey,
        'label' => $day->format('m-d'),
        'count' => 0,
        'success_count' => 0,
        'success_rate' => 100.0,
    ];
}
if (!empty($apiKeyIds)) {
    try {
        $startAt = $apiToday->sub(new \DateInterval('P' . ($apiDailySeriesDays - 1) . 'D'))->format('Y-m-d 00:00:00');
        $dailyCallRows = \WHMCS\Database\Capsule::table('mod_cloudflare_api_logs')
            ->select(
                \WHMCS\Database\Capsule::raw('DATE(created_at) as day'),
                \WHMCS\Database\Capsule::raw('COUNT(*) as total_calls'),
                \WHMCS\Database\Capsule::raw('SUM(CASE WHEN response_code < 400 THEN 1 ELSE 0 END) as success_calls')
            )
            ->whereIn('api_key_id', $apiKeyIds)
            ->where('created_at', '>=', $startAt)
            ->groupBy(\WHMCS\Database\Capsule::raw('DATE(created_at)'))
            ->orderBy('day', 'asc')
            ->get();

        foreach ($dailyCallRows as $dailyCallRow) {
            $dayKey = (string) ($dailyCallRow->day ?? '');
            if ($dayKey === '' || !isset($apiDailyCallStats[$dayKey])) {
                continue;
            }
            $totalCalls = intval($dailyCallRow->total_calls ?? 0);
            $successCalls = intval($dailyCallRow->success_calls ?? 0);
            $successRate = $totalCalls > 0 ? round(($successCalls / $totalCalls) * 100, 1) : 100.0;

            $apiDailyCallStats[$dayKey]['count'] = $totalCalls;
            $apiDailyCallStats[$dayKey]['success_count'] = $successCalls;
            $apiDailyCallStats[$dayKey]['success_rate'] = $successRate;
        }
    } catch (\Throwable $e) {
    }
}
$apiDailyCallStats = array_values($apiDailyCallStats);
$apiDailyTrendPayload = [
    'labels' => array_values(array_map(static function (array $row): string {
        return (string) ($row['label'] ?? '');
    }, $apiDailyCallStats)),
    'dates' => array_values(array_map(static function (array $row): string {
        return (string) ($row['date'] ?? '');
    }, $apiDailyCallStats)),
    'values' => array_values(array_map(static function (array $row): int {
        return intval($row['count'] ?? 0);
    }, $apiDailyCallStats)),
    'successRates' => array_values(array_map(static function (array $row): float {
        return round((float) ($row['success_rate'] ?? 100), 1);
    }, $apiDailyCallStats)),
];

$moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
$moduleSlugAttr = htmlspecialchars($moduleSlug, ENT_QUOTES);
$moduleSlugUrl = urlencode($moduleSlug);

// 获取模块设置
$settings = [];
$rows = \WHMCS\Database\Capsule::table('tbladdonmodules')
    ->where('module', $moduleSlug)
    ->get();
if (count($rows) === 0) {
    $rows = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : 'cloudflare_subdomain')
        ->get();
}
foreach ($rows as $r) {
    $settings[$r->setting] = $r->value;
}

$apiEnabled = ($settings['enable_user_api'] ?? 'on') === 'on';
$maxApiKeys = intval($settings['api_keys_per_user'] ?? 3);
$requireQuota = intval($settings['api_require_quota'] ?? 1);
$ipWhitelistEnabled = ($settings['api_enable_ip_whitelist'] ?? 'no') === 'on';

// 获取用户配额
$quota = \WHMCS\Database\Capsule::table('mod_cloudflare_subdomain_quotas')
    ->where('userid', $userId)
    ->first();
$totalQuota = intval($quota->max_count ?? 0);
$canCreateApi = $totalQuota >= $requireQuota;

if (!$apiEnabled) {
    return;
}

$apiSectionShouldExpand = true;
$cfApiText = static function (string $key, string $default, array $params = [], bool $escape = true): string {
    return cfclient_lang($key, $default, $params, $escape);
};
$apiLocaleIsChinese = strtolower((string) ($currentClientLanguage ?? 'english')) === 'chinese';
$apiTrendTitleDefault = $apiLocaleIsChinese
    ? sprintf('近 %d 天每日总调用次数', $apiDailySeriesDays)
    : sprintf('Total Daily Calls (Last %d Days)', $apiDailySeriesDays);
$apiTrendHintDefault = $apiLocaleIsChinese
    ? '将鼠标悬停在折线点上可查看明细；成功率低于 90% 会显示红色预警点。'
    : 'Hover over points to see details. Red points indicate success rate below 90%.';
$apiTrendCountLabel = $apiLocaleIsChinese ? '调用次数' : 'Calls';
$apiTrendCountUnit = $apiLocaleIsChinese ? '次' : '';
$apiTrendSuccessLabel = $apiLocaleIsChinese ? '成功率' : 'Success Rate';
$apiTrendSeparator = $apiLocaleIsChinese ? '：' : ': ';
?>

<div class="card mt-4" id="api-management-card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <button class="btn btn-link text-white text-decoration-none p-0 d-flex align-items-center gap-2" type="button" id="apiManagementToggleBtn" aria-expanded="<?php echo $apiSectionShouldExpand ? 'true' : 'false'; ?>" aria-controls="apiManagementBody">
            <span class="h5 mb-0 d-flex align-items-center gap-2">
                <i class="fas fa-key"></i>
                <span><?php echo $cfApiText('cfclient.api.card.title', 'API密钥管理', [], true); ?></span>
            </span>
            <i class="fas <?php echo $apiSectionShouldExpand ? 'fa-chevron-up' : 'fa-chevron-down'; ?> small" id="apiManagementToggleIcon"></i>
        </button>
    </div>
    <div class="card-body collapse <?php echo $apiSectionShouldExpand ? 'show' : ''; ?>" id="apiManagementBody">
        
        <!-- API说明 -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> 
            <strong><?php echo $cfApiText('cfclient.api.alert.title', 'API功能：', [], true); ?></strong><?php echo $cfApiText('cfclient.api.alert.body', '通过API密钥，您可以在程序中自动管理域名和DNS记录，无需手动操作。', [], true); ?>
            <a href="#" data-bs-toggle="modal" data-bs-target="#apiDocModal" class="alert-link"><?php echo $cfApiText('cfclient.api.alert.docs', '查看API文档', [], true); ?></a>
        </div>

        <?php if (!$canCreateApi): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo $cfApiText('cfclient.api.warning.requirement', '您的配额不足，需要至少有 %1$s 个域名注册配额才能创建API密钥。', [sprintf('<strong>%s</strong>', $requireQuota)], false); ?>
            <br>
            <?php echo $cfApiText('cfclient.api.warning.current_quota', '当前注册额度：%s', [sprintf('<strong>%s</strong>', $totalQuota)], false); ?>
        </div>
        <?php endif; ?>

        <!-- 创建API密钥按钮 -->
        <?php if (count($apiKeys) < $maxApiKeys && $canCreateApi): ?>
        <div class="mb-3">
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createApiKeyModal">
                <i class="fas fa-plus"></i> <?php echo $cfApiText('cfclient.api.button.create', '创建API密钥', [], true); ?>
            </button>
            <span class="text-muted ms-2">
                <?php echo $cfApiText('cfclient.api.stats.created', '已创建 %1$s / %2$s 个', [number_format(count($apiKeys)), number_format($maxApiKeys)], true); ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- API密钥列表 -->
        <?php if (count($apiKeys) > 0): ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                    <tr>
                        <th><?php echo $cfApiText('cfclient.api.table.name', '密钥名称', [], true); ?></th>
                        <th>API Key</th>
                        <th><?php echo $cfApiText('cfclient.api.table.status', '状态', [], true); ?></th>
                        <th><?php echo $cfApiText('cfclient.api.table.requests', '请求次数', [], true); ?></th>
                        <th><?php echo $cfApiText('cfclient.api.table.last_used', '最后使用', [], true); ?></th>
                        <th><?php echo $cfApiText('cfclient.api.table.created_at', '创建时间', [], true); ?></th>
                        <th><?php echo $cfApiText('cfclient.api.table.actions', '操作', [], true); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apiKeys as $key): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($key->key_name); ?></strong>
                        </td>
                        <td>
                            <code class="user-select-all"><?php echo htmlspecialchars($key->api_key); ?></code>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyToClipboard('<?php echo htmlspecialchars($key->api_key); ?>')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </td>
                        <td>
                            <?php if ($key->status === 'active'): ?>
                                <span class="badge bg-success"><?php echo $cfApiText('cfclient.api.status.active', '启用', [], true); ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?php echo $cfApiText('cfclient.api.status.disabled', '禁用', [], true); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($key->request_count); ?></td>
                        <td><?php echo $key->last_used_at ? date('Y-m-d H:i', strtotime($key->last_used_at)) : $cfApiText('cfclient.api.table.never_used', '从未使用', [], true); ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($key->created_at)); ?></td>
                        <td>
                            <?php if ($key->status === 'disabled'): ?>
                                <div class="text-danger small">
                                    <i class="fas fa-lock"></i> <?php echo $cfApiText('cfclient.api.status.disabled_by_admin', '已被管理员禁用', [], true); ?>
                                    <br>
                                    <button type="button" class="btn btn-sm btn-info mt-1" onclick="showApiKeyDetails(<?php echo $key->id; ?>)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.view', '查看详情', [], true)); ?>">
                                        <i class="fas fa-eye"></i> <?php echo $cfApiText('cfclient.api.actions.view', '查看', [], true); ?>
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-info" onclick="showApiKeyDetails(<?php echo $key->id; ?>)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.view', '查看详情', [], true)); ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-warning" onclick="regenerateApiKey(<?php echo $key->id; ?>)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.regenerate', '重新生成', [], true)); ?>">
                                        <i class="fas fa-sync"></i>
                                    </button>
                                    <button type="button" class="btn btn-danger" onclick="deleteApiKey(<?php echo $key->id; ?>)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.delete', '删除', [], true)); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-secondary text-center">
            <i class="fas fa-key fa-3x mb-3 text-muted"></i>
            <p><?php echo $cfApiText('cfclient.api.empty.message', '您还没有创建任何API密钥', [], true); ?></p>
            <?php if ($canCreateApi): ?>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createApiKeyModal">
                <i class="fas fa-plus"></i> <?php echo $cfApiText('cfclient.api.button.create_now', '立即创建', [], true); ?>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="mt-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><?php echo $cfApiText('cfclient.api.trend.title', $apiTrendTitleDefault, [], true); ?></h6>
                <span class="badge bg-light text-secondary border"><?php echo intval($apiDailySeriesDays); ?>D</span>
            </div>
            <div class="api-usage-trend-chart-wrap">
                <canvas id="apiUsageTrendCanvas" class="api-usage-trend-canvas" height="240"></canvas>
                <div id="apiUsageTrendTooltip" class="api-usage-trend-tooltip" role="status" aria-live="polite"></div>
            </div>
            <div class="small text-muted mt-2"><?php echo $cfApiText('cfclient.api.trend.hint', $apiTrendHintDefault, [], true); ?></div>
        </div>

        <!-- API端点信息 -->
        <div class="mt-3">
            <h6><?php echo $cfApiText('cfclient.api.endpoint.title', 'API端点链接地址：', [], true); ?></h6>
<div class="input-group">
    <input type="text" class="form-control" id="apiEndpoint" readonly
        value="https://api005.dnshe.com/index.php?m=<?php echo $moduleSlug; ?>">
    <button class="btn btn-outline-secondary" type="button"
        onclick="copyToClipboard(document.getElementById('apiEndpoint').value)">
        <i class="fas fa-copy"></i> <?php echo $cfApiText('cfclient.api.actions.copy', '复制', [], true); ?>
 
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 创建API密钥模态框 -->
<div class="modal fade" id="createApiKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $cfApiText('cfclient.api.modal.create.title', '创建API密钥', [], true); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createApiKeyForm">
                    <div class="mb-3">
                        <label class="form-label"><?php echo $cfApiText('cfclient.api.modal.create.name_label', '密钥名称 *', [], true); ?></label>
                        <input type="text" class="form-control" name="key_name" required 
                            placeholder="<?php echo htmlspecialchars($cfApiText('cfclient.api.modal.create.name_placeholder', '例如：生产环境、测试环境', [], true)); ?>">
                        <small class="form-text text-muted"><?php echo $cfApiText('cfclient.api.modal.create.name_hint', '用于识别此密钥的用途', [], true); ?></small>
                    </div>
                    <?php if ($ipWhitelistEnabled): ?>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $cfApiText('cfclient.api.modal.create.ip_label', 'IP白名单（可选）', [], true); ?></label>
                        <textarea class="form-control" name="ip_whitelist" rows="3" 
                            placeholder="<?php echo htmlspecialchars($cfApiText('cfclient.api.modal.create.ip_placeholder', '192.168.1.1\n192.168.1.2\n留空则允许所有IP', [], true)); ?>"></textarea>
                        <small class="form-text text-muted"><?php echo $cfApiText('cfclient.api.modal.create.ip_hint', '每行一个IP地址，只有这些IP可以使用此密钥', [], true); ?></small>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $cfApiText('cfclient.api.modal.button.cancel', '取消', [], true); ?></button>
                <button type="button" class="btn btn-primary" onclick="createApiKey()"><?php echo $cfApiText('cfclient.api.modal.button.create', '创建', [], true); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- API密钥详情模态框 -->
<div class="modal fade" id="apiKeyDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $cfApiText('cfclient.api.modal.detail.title', 'API密钥详情', [], true); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="apiKeyDetailsContent">
                <!-- 动态加载内容 -->
            </div>
        </div>
    </div>
</div>

<!-- API新密钥显示模态框 -->
<div class="modal fade" id="newApiKeyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check-circle"></i> <?php echo $cfApiText('cfclient.api.modal.secret.title', 'API密钥创建成功', [], true); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong><?php echo $cfApiText('cfclient.api.modal.secret.important', '重要：', [], true); ?></strong><?php echo $cfApiText('cfclient.api.modal.secret.notice', 'API Secret只会显示一次，请立即保存！', [], true); ?>
                </div>
                <div class="mb-3">
                    <label class="form-label"><strong>API Key：</strong></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="newApiKey" readonly>
                        <button class="btn btn-outline-secondary" onclick="copyToClipboard(document.getElementById('newApiKey').value)">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><strong>API Secret：</strong></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="newApiSecret" readonly>
                        <button class="btn btn-outline-secondary" onclick="copyToClipboard(document.getElementById('newApiSecret').value)">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="alert alert-info">
                  <strong><?php echo $cfApiText('cfclient.api.modal.secret.examples', '使用方法示例：', [], true); ?></strong>
<pre class="mb-0"><code>curl -X GET "https://api005.dnshe.com/index.php?m=<?php echo $moduleSlug; ?>&endpoint=subdomains&action=list" \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "X-API-Secret: YOUR_API_SECRET"</code></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal"><?php echo $cfApiText('cfclient.api.modal.secret.button_saved', '我已保存密钥', [], true); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- API文档模态框 -->
<div class="modal fade" id="apiDocModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-book"></i> <?php echo $cfApiText('cfclient.api.docs.modal.title', 'API使用文档', [], true); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div class="mb-4">
                    <h6><?php echo $cfApiText('cfclient.api.docs.section1.title', '1. 认证方式', [], true); ?></h6>
                    <p><?php echo $cfApiText('cfclient.api.docs.section1.body', '所有API请求需要携带API Key和API Secret进行认证。', [], true); ?></p>
                    <p><strong><?php echo $cfApiText('cfclient.api.docs.section1.method_header', '方式1：HTTP Header（推荐使用）', [], true); ?></strong></p>
                    <pre><code>X-API-Key: cfsd_xxxxxxxxxx
X-API-Secret: yyyyyyyyyyyy</code></pre>
                    <p><strong><?php echo $cfApiText('cfclient.api.docs.section1.method_query', '方式2：URL参数（已废弃）', [], true); ?></strong></p>
                    <pre><code>?api_key=cfsd_xxxxxxxxxx&amp;api_secret=yyyyyyyyyyyy</code></pre>
                </div>

                <div class="mb-4">
                    <h6><?php echo $cfApiText('cfclient.api.docs.section2.title', '2. 可用端点', [], true); ?></h6>
                    <ul>
                        <li><code>subdomains</code> - <?php echo $cfApiText('cfclient.api.docs.section2.subdomains', '子域名管理', [], true); ?></li>
                        <li><code>dns_records</code> - <?php echo $cfApiText('cfclient.api.docs.section2.records', 'DNS记录管理', [], true); ?></li>
                        <li><code>keys</code> - <?php echo $cfApiText('cfclient.api.docs.section2.keys', 'API密钥管理', [], true); ?></li>
                        <li><code>quota</code> - <?php echo $cfApiText('cfclient.api.docs.section2.quota', '配额查询', [], true); ?></li>
                    </ul>
                </div>

                <div class="mb-4">
                 <h6><?php echo $cfApiText('cfclient.api.docs.section3.title', '3. 示例：列出子域名', [], true); ?></h6>
<pre><code>curl -X GET "https://api005.dnshe.com/index.php?m=<?php echo $moduleSlug; ?>&endpoint=subdomains&action=list" \
  -H "X-API-Key: cfsd_xxxxxxxxxx" \
  -H "X-API-Secret: yyyyyyyyyyyy"
</code></pre>


                <div class="mb-4">
                    <h6><?php echo $cfApiText('cfclient.api.docs.section4.title', '4. 示例：注册子域名', [], true); ?></h6>
<pre><code>curl -X POST "https://api005.dnshe.com/index.php?m=<?php echo $moduleSlug; ?>&endpoint=subdomains&action=register" \
  -H "X-API-Key: cfsd_xxxxxxxxxx" \
  -H "X-API-Secret: yyyyyyyyyyyy" \
  -H "Content-Type: application/json" \
  -d '{
    "subdomain": "myapp",
    "rootdomain": "example.com"
  }'
</code></pre>

                </div>

                <div class="mb-4">
                    <h6><?php echo $cfApiText('cfclient.api.docs.section5.title', '5. 示例：创建DNS记录', [], true); ?></h6>
                 <pre><code>curl -X POST "https://api005.dnshe.com/index.php?m=<?php echo $moduleSlug; ?>&endpoint=dns_records&action=create" \
  -H "X-API-Key: cfsd_xxxxxxxxxx" \
  -H "X-API-Secret: yyyyyyyyyyyy" \
  -H "Content-Type: application/json" \
  -d '{
    "subdomain_id": 1,
    "type": "A",
    "content": "192.168.1.1",
    "ttl": 600
  }'
</code></pre>

                </div>

              <div class="alert alert-info">
    <i class="fas fa-download"></i>
    <strong><?php echo $cfApiText('cfclient.api.docs.full.title', '完整API文档：', [], true); ?></strong>
    <a href="https://my.dnshe.com/knowledgebase/1/Free-Domain-Name-Service-API-User-Manual.html"
       target="_blank"
       class="alert-link">
        <?php echo $cfApiText('cfclient.api.docs.full.link', '点击查看完整文档', [], true); ?>
    </a>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 复制到剪贴板
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        alert(cfLang('api.copySuccess', '已复制到剪贴板'));
    }, function(err) {
        console.error(cfLang('api.copyFailed', '复制失败：'), err);
    });
}

// 创建API密钥
function createApiKey() {
    const form = document.getElementById('createApiKeyForm');
    const formData = new FormData(form);
    
    // 转换为JSON
    const data = {};
    formData.forEach((value, key) => {
        data[key] = value;
    });
    
    // 如果有IP白名单，转换为逗号分隔
    if (data.ip_whitelist) {
        data.ip_whitelist = data.ip_whitelist.split('\n').map(ip => ip.trim()).filter(ip => ip).join(',');
    }
    
    fetch('?m=<?php echo $moduleSlugAttr; ?>&action=ajax_create_api_key', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': (window.CF_MOD_CSRF || '')
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            // 关闭创建模态框
            const createModal = bootstrap.Modal.getInstance(document.getElementById('createApiKeyModal'));
            createModal.hide();
            
            // 显示新密钥
            document.getElementById('newApiKey').value = result.api_key;
            document.getElementById('newApiSecret').value = result.api_secret;
            const newKeyModal = new bootstrap.Modal(document.getElementById('newApiKeyModal'));
            newKeyModal.show();
            
            // 刷新页面
            newKeyModal._element.addEventListener('hidden.bs.modal', function() {
                location.reload();
            });
        } else {
            alert(cfLang('api.createFailedWithReason', '创建失败：') + result.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert(cfLang('api.createFailedGeneric', '创建失败，请重试'));
    });
}

// 查看API密钥详情
function showApiKeyDetails(keyId) {
    fetch('?m=<?php echo $moduleSlugAttr; ?>&action=ajax_get_api_key_details&key_id=' + keyId)
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                const key = result.key;
                const html = `
                    <dl class="row">
                        <dt class="col-sm-3"><?php echo $cfApiText('cfclient.api.modal.detail.name_label', '密钥名称：', [], true); ?></dt>
                        <dd class="col-sm-9">${key.key_name}</dd>
                        
                        <dt class="col-sm-3"><?php echo $cfApiText('cfclient.api.modal.detail.key_label', 'API Key：', [], true); ?></dt>
                        <dd class="col-sm-9"><code>${key.api_key}</code></dd>
                        
                        <dt class="col-sm-3"><?php echo $cfApiText('cfclient.api.modal.detail.status_label', '状态：', [], true); ?></dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-${key.status === 'active' ? 'success' : 'danger'}">
                                ${key.status === 'active' ? cfLang('api.statusActive', '启用') : cfLang('api.statusDisabled', '禁用')}
                            </span>
                        </dd>
                        
                        <dt class="col-sm-3"><?php echo $cfApiText('cfclient.api.modal.detail.requests_label', '请求次数：', [], true); ?></dt>
                        <dd class="col-sm-9">${key.request_count.toLocaleString()}</dd>
                        
                        <dt class="col-sm-3"><?php echo $cfApiText('cfclient.api.modal.detail.last_used_label', '最后使用：', [], true); ?></dt>
                        <dd class="col-sm-9">${key.last_used_at || cfLang('api.neverUsed', '从未使用')}</dd>
                        
                        <dt class="col-sm-3"><?php echo $cfApiText('cfclient.api.modal.detail.created_label', '创建时间：', [], true); ?></dt>
                        <dd class="col-sm-9">${key.created_at}</dd>
                        
                        ${key.ip_whitelist ? `
                        <dt class="col-sm-3"><?php echo $cfApiText('cfclient.api.modal.detail.ip_label', 'IP白名单：', [], true); ?></dt>
                        <dd class="col-sm-9"><pre>${key.ip_whitelist.split(',').join('\n')}</pre></dd>
                        ` : ''}
                    </dl>
                `;
                document.getElementById('apiKeyDetailsContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('apiKeyDetailsModal'));
                modal.show();
            } else {
                alert(cfLang('api.detailsFailed', '获取详情失败：') + result.error);
            }
        });
}

// 重新生成API密钥
function regenerateApiKey(keyId) {
    if (!confirm(cfLang('api.regenerateConfirm', '重新生成后，旧的API Secret将立即失效，确定继续吗？'))) {
        return;
    }
    
    fetch('?m=<?php echo $moduleSlugAttr; ?>&action=ajax_regenerate_api_key', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': (window.CF_MOD_CSRF || '')
        },
        body: JSON.stringify({ key_id: keyId })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            document.getElementById('newApiKey').value = result.api_key;
            document.getElementById('newApiSecret').value = result.api_secret;
            const modal = new bootstrap.Modal(document.getElementById('newApiKeyModal'));
            modal.show();
        } else {
            alert(cfLang('api.regenerateFailed', '重新生成失败：') + result.error);
        }
    });
}

// 删除API密钥
function deleteApiKey(keyId) {
    if (!confirm(cfLang('api.deleteConfirm', '确定要删除此API密钥吗？删除后无法恢复！'))) {
        return;
    }
    
    fetch('?m=<?php echo $moduleSlugAttr; ?>&action=ajax_delete_api_key', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': (window.CF_MOD_CSRF || '')
        },
        body: JSON.stringify({ key_id: keyId })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            alert(cfLang('api.deleteSuccess', '删除成功'));
            location.reload();
        } else {
            alert(cfLang('api.deleteFailed', '删除失败：') + result.error);
        }
    });
}

const apiUsageTrendPayload = <?php echo json_encode($apiDailyTrendPayload, CFMOD_SAFE_JSON_FLAGS); ?>;
const apiUsageTrendCountLabel = <?php echo json_encode($apiTrendCountLabel, CFMOD_SAFE_JSON_FLAGS); ?>;
const apiUsageTrendCountUnit = <?php echo json_encode($apiTrendCountUnit, CFMOD_SAFE_JSON_FLAGS); ?>;
const apiUsageTrendSuccessLabel = <?php echo json_encode($apiTrendSuccessLabel, CFMOD_SAFE_JSON_FLAGS); ?>;
const apiUsageTrendSeparator = <?php echo json_encode($apiTrendSeparator, CFMOD_SAFE_JSON_FLAGS); ?>;

function renderApiUsageTrendChart() {
    const canvas = document.getElementById('apiUsageTrendCanvas');
    const tooltip = document.getElementById('apiUsageTrendTooltip');
    if (!canvas || !canvas.getContext) {
        return;
    }

    const ctx = canvas.getContext('2d');
    const labels = Array.isArray(apiUsageTrendPayload.labels) ? apiUsageTrendPayload.labels : [];
    const dates = Array.isArray(apiUsageTrendPayload.dates) ? apiUsageTrendPayload.dates : [];
    const values = Array.isArray(apiUsageTrendPayload.values)
        ? apiUsageTrendPayload.values.map(function(item){ return Number(item) || 0; })
        : [];
    const successRates = Array.isArray(apiUsageTrendPayload.successRates)
        ? apiUsageTrendPayload.successRates.map(function(item){ return Number(item) || 100; })
        : values.map(function(){ return 100; });

    const formatAxisValue = function(value) {
        if (value >= 1000) {
            const k = value / 1000;
            if (Math.abs(k - Math.round(k)) < 0.01) {
                return Math.round(k) + 'k';
            }
            return k.toFixed(1) + 'k';
        }
        return String(Math.round(value));
    };

    const formatTooltipValue = function(value) {
        return (Number(value) || 0).toLocaleString('en-US');
    };

    const calcNiceMax = function(rawMax) {
        const safeMax = Math.max(1, Number(rawMax) || 0);
        const exponent = Math.floor(Math.log10(safeMax));
        const magnitude = Math.pow(10, exponent);
        const normalized = safeMax / magnitude;
        let niceBase = 10;
        if (normalized <= 1) {
            niceBase = 1;
        } else if (normalized <= 2) {
            niceBase = 2;
        } else if (normalized <= 5) {
            niceBase = 5;
        }
        return niceBase * magnitude;
    };

    let points = [];

    const draw = function() {
        const dpr = window.devicePixelRatio || 1;
        const cssWidth = Math.max(320, canvas.clientWidth || 320);
        const cssHeight = Math.max(220, canvas.clientHeight || 220);
        canvas.width = Math.floor(cssWidth * dpr);
        canvas.height = Math.floor(cssHeight * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cssWidth, cssHeight);

        const padding = { left: 48, right: 16, top: 16, bottom: 34 };
        const plotWidth = cssWidth - padding.left - padding.right;
        const plotHeight = cssHeight - padding.top - padding.bottom;
        const maxValue = calcNiceMax(Math.max.apply(null, values.concat([0])));
        const tickCount = 4;

        ctx.strokeStyle = '#e9ecef';
        ctx.fillStyle = '#6c757d';
        ctx.lineWidth = 1;
        ctx.font = '12px Arial, sans-serif';

        for (let i = 0; i <= tickCount; i++) {
            const ratio = i / tickCount;
            const y = padding.top + plotHeight * ratio;
            const value = maxValue * (1 - ratio);
            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(cssWidth - padding.right, y);
            ctx.stroke();

            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillText(formatAxisValue(value), padding.left - 8, y);
        }

        ctx.beginPath();
        ctx.moveTo(padding.left, padding.top);
        ctx.lineTo(padding.left, cssHeight - padding.bottom);
        ctx.lineTo(cssWidth - padding.right, cssHeight - padding.bottom);
        ctx.strokeStyle = '#cfd4da';
        ctx.stroke();

        points = [];
        const len = values.length;
        const stepX = len > 1 ? plotWidth / (len - 1) : 0;

        for (let i = 0; i < len; i++) {
            const x = len > 1 ? (padding.left + stepX * i) : (padding.left + plotWidth / 2);
            const ratio = maxValue > 0 ? values[i] / maxValue : 0;
            const y = padding.top + plotHeight * (1 - ratio);
            points.push({
                x: x,
                y: y,
                value: values[i],
                date: dates[i] || labels[i] || '',
                successRate: Number(successRates[i] ?? 100)
            });
        }

        if (points.length > 1) {
            ctx.beginPath();
            ctx.lineWidth = 2;
            ctx.strokeStyle = '#0d6efd';
            ctx.moveTo(points[0].x, points[0].y);
            for (let i = 1; i < points.length; i++) {
                ctx.lineTo(points[i].x, points[i].y);
            }
            ctx.stroke();
        }

        points.forEach(function(point) {
            const warningPoint = Number(point.value || 0) > 0 && Number(point.successRate || 0) < 90;
            ctx.beginPath();
            ctx.fillStyle = warningPoint ? '#fff5f5' : '#ffffff';
            ctx.strokeStyle = warningPoint ? '#dc3545' : '#0d6efd';
            ctx.lineWidth = 2;
            ctx.arc(point.x, point.y, 3.5, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
        });

        ctx.fillStyle = '#6c757d';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        ctx.font = '11px Arial, sans-serif';
        for (let i = 0; i < labels.length; i++) {
            const x = labels.length > 1 ? (padding.left + stepX * i) : (padding.left + plotWidth / 2);
            ctx.fillText(labels[i], x, cssHeight - padding.bottom + 8);
        }
    };

    const hideTooltip = function() {
        if (!tooltip) {
            return;
        }
        tooltip.style.opacity = '0';
        tooltip.style.visibility = 'hidden';
    };

    const showTooltip = function(point) {
        if (!tooltip || !point) {
            return;
        }
        const countSuffix = apiUsageTrendCountUnit ? (' ' + apiUsageTrendCountUnit) : '';
        const successRateText = (Number(point.successRate) || 0).toFixed(1) + '%';
        tooltip.innerHTML = point.date + '<br>'
            + apiUsageTrendCountLabel + apiUsageTrendSeparator + formatTooltipValue(point.value) + countSuffix + '<br>'
            + apiUsageTrendSuccessLabel + apiUsageTrendSeparator + successRateText;
        tooltip.style.opacity = '1';
        tooltip.style.visibility = 'visible';

        const tipWidth = tooltip.offsetWidth || 0;
        const tipHeight = tooltip.offsetHeight || 0;
        let left = point.x + 12;
        let top = point.y - tipHeight - 10;

        const maxLeft = (canvas.clientWidth || 0) - tipWidth - 6;
        if (left > maxLeft) {
            left = point.x - tipWidth - 12;
        }
        if (left < 6) {
            left = 6;
        }
        if (top < 6) {
            top = point.y + 10;
        }

        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
    };

    canvas.addEventListener('mousemove', function(event) {
        const rect = canvas.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;

        let hovered = null;
        for (let i = 0; i < points.length; i++) {
            const point = points[i];
            const dx = x - point.x;
            const dy = y - point.y;
            if ((dx * dx + dy * dy) <= 64) {
                hovered = point;
                break;
            }
        }

        if (hovered) {
            showTooltip(hovered);
        } else {
            hideTooltip();
        }
    });

    canvas.addEventListener('mouseleave', hideTooltip);

    draw();
    window.addEventListener('resize', draw);
}

document.addEventListener('DOMContentLoaded', function () {
    renderApiUsageTrendChart();

    var collapseEl = document.getElementById('apiManagementBody');
    var iconEl = document.getElementById('apiManagementToggleIcon');
    var toggleBtn = document.getElementById('apiManagementToggleBtn');
    if (!collapseEl || !iconEl) {
        return;
    }
    var collapseInstance = null;
    if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        collapseInstance = bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false });
        if (collapseEl.classList.contains('show')) {
            collapseInstance.show();
        } else {
            collapseInstance.hide();
        }
    }
    if (toggleBtn && collapseInstance) {
        toggleBtn.addEventListener('click', function (event) {
            event.preventDefault();
            collapseInstance.toggle();
            var expanded = toggleBtn.getAttribute('aria-expanded') === 'true';
            toggleBtn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        });
    }
    var updateIcon = function () {
        var isExpanded = collapseEl.classList.contains('show');
        if (iconEl) {
            if (isExpanded) {
                iconEl.classList.remove('fa-chevron-down');
                iconEl.classList.add('fa-chevron-up');
            } else {
                iconEl.classList.remove('fa-chevron-up');
                iconEl.classList.add('fa-chevron-down');
            }
        }
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        }
    };
    collapseEl.addEventListener('shown.bs.collapse', updateIcon);
    collapseEl.addEventListener('hidden.bs.collapse', updateIcon);
    updateIcon();
});
</script>

<style>
#api-management-card code {
    background-color: #f8f9fa;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.9em;
}

#api-management-card pre {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    overflow-x: auto;
}

#api-management-card .user-select-all {
    user-select: all;
}

#api-management-card .api-usage-trend-chart-wrap {
    position: relative;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    background: #ffffff;
    padding: 8px 8px 4px;
}

#api-management-card .api-usage-trend-canvas {
    display: block;
    width: 100%;
    height: 240px;
}

#api-management-card .api-usage-trend-tooltip {
    position: absolute;
    left: 0;
    top: 0;
    visibility: hidden;
    opacity: 0;
    pointer-events: none;
    background: rgba(33, 37, 41, 0.92);
    color: #fff;
    font-size: 12px;
    border-radius: 6px;
    padding: 6px 8px;
    white-space: normal;
    line-height: 1.45;
    min-width: 136px;
    transition: opacity 0.15s ease;
    z-index: 6;
}
</style>

