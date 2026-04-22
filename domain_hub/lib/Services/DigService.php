<?php

declare(strict_types=1);

class CfDigService
{
    private const DEFAULT_RESOLVERS = [
        [
            'key' => 'cloudflare',
            'name' => 'Cloudflare DNS',
            'url' => 'https://cloudflare-dns.com/dns-query?name={domain}&type={type}',
        ],
        [
            'key' => 'google',
            'name' => 'Google DNS',
            'url' => 'https://dns.google/resolve?name={domain}&type={type}',
        ],
        [
            'key' => 'quad9',
            'name' => 'Quad9 DNS',
            'url' => 'https://dns.quad9.net/dns-query?name={domain}&type={type}',
        ],
    ];

    private const TYPE_TO_CODE = [
        'A' => 1,
        'NS' => 2,
        'CNAME' => 5,
        'SOA' => 6,
        'PTR' => 12,
        'MX' => 15,
        'TXT' => 16,
        'AAAA' => 28,
        'SRV' => 33,
    ];

    public static function isEnabled(array $moduleSettings): bool
    {
        if (!array_key_exists('enable_dig_center', $moduleSettings)) {
            return true;
        }

        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($moduleSettings['enable_dig_center']);
        }

        $raw = strtolower(trim((string) $moduleSettings['enable_dig_center']));
        return in_array($raw, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function getSupportedTypes(): array
    {
        return array_keys(self::TYPE_TO_CODE);
    }

    public static function lookup(int $requestUserId, string $domainInput, string $recordTypeInput, array $moduleSettings = [], array $context = []): array
    {
        if (empty($moduleSettings) && function_exists('cf_get_module_settings_cached')) {
            $moduleSettings = cf_get_module_settings_cached();
            if (!is_array($moduleSettings)) {
                $moduleSettings = [];
            }
        }

        $domain = self::normalizeDomain($domainInput);
        if ($domain === '') {
            throw new \RuntimeException('请输入有效域名后再查询。');
        }

        $recordType = self::normalizeRecordType($recordTypeInput);
        if ($recordType === '') {
            throw new \RuntimeException('不支持的记录类型。');
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('当前环境缺少 cURL 扩展，无法执行 Dig 查询。');
        }

        $timeoutSeconds = self::resolveTimeoutSeconds($moduleSettings);
        $queryStart = microtime(true);
        $resolverResults = self::queryResolvers($domain, $recordType, $timeoutSeconds);
        $durationMs = (int) round((microtime(true) - $queryStart) * 1000);
        $summary = self::buildSummary($resolverResults);

        self::logLookupMeta($moduleSettings, $requestUserId, $domain, $recordType, $summary, $durationMs, $context);

        return [
            'success' => true,
            'result' => [
                'domain' => $domain,
                'record_type' => $recordType,
                'queried_at' => date('Y-m-d H:i:s'),
                'duration_ms' => $durationMs,
                'summary' => $summary,
                'resolvers' => $resolverResults,
            ],
        ];
    }

    private static function queryResolvers(string $domain, string $recordType, int $timeoutSeconds): array
    {
        $resolvers = self::DEFAULT_RESOLVERS;
        if (function_exists('curl_multi_init') && function_exists('curl_multi_exec')) {
            return self::queryResolversByMultiCurl($domain, $recordType, $resolvers, $timeoutSeconds);
        }

        $results = [];
        foreach ($resolvers as $resolver) {
            $results[] = self::querySingleResolver($resolver, $domain, $recordType, $timeoutSeconds);
        }
        return $results;
    }

    private static function queryResolversByMultiCurl(string $domain, string $recordType, array $resolvers, int $timeoutSeconds): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($resolvers as $resolver) {
            $url = self::buildResolverUrl((string) $resolver['url'], $domain, $recordType);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeoutSeconds),
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/dns-json, application/json;q=0.9, */*;q=0.8',
                ],
                CURLOPT_USERAGENT => 'DomainHub-Dig/1.0',
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[(int) $ch] = [
                'resolver' => $resolver,
                'handle' => $ch,
            ];
        }

        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running && $status === CURLM_OK) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $item) {
            $resolver = $item['resolver'];
            $ch = $item['handle'];
            $body = curl_multi_getcontent($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseMs = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $curlError = trim((string) curl_error($ch));

            $results[] = self::formatResolverResponse($resolver, $recordType, $httpCode, $responseMs, (string) $body, $curlError);

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);
        return $results;
    }

    private static function querySingleResolver(array $resolver, string $domain, string $recordType, int $timeoutSeconds): array
    {
        $url = self::buildResolverUrl((string) ($resolver['url'] ?? ''), $domain, $recordType);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/dns-json, application/json;q=0.9, */*;q=0.8',
            ],
            CURLOPT_USERAGENT => 'DomainHub-Dig/1.0',
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseMs = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $curlError = trim((string) curl_error($ch));
        curl_close($ch);

        return self::formatResolverResponse($resolver, $recordType, $httpCode, $responseMs, is_string($body) ? $body : '', $curlError);
    }

    private static function formatResolverResponse(array $resolver, string $recordType, int $httpCode, int $responseMs, string $body, string $curlError): array
    {
        $resolverKey = (string) ($resolver['key'] ?? 'resolver');
        $resolverName = (string) ($resolver['name'] ?? $resolverKey);

        $payload = null;
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $success = $httpCode >= 200 && $httpCode < 300 && is_array($payload);
        $dnsStatus = $success ? (int) ($payload['Status'] ?? 0) : null;
        $answers = [];
        $values = [];

        if ($success) {
            $answerRows = $payload['Answer'] ?? [];
            if (is_array($answerRows)) {
                foreach ($answerRows as $answer) {
                    if (!is_array($answer)) {
                        continue;
                    }
                    $answerTypeCode = (int) ($answer['type'] ?? 0);
                    $answerType = self::codeToType($answerTypeCode);
                    $answerData = trim((string) ($answer['data'] ?? ''));
                    if ($answerData === '') {
                        continue;
                    }
                    $answers[] = [
                        'name' => trim((string) ($answer['name'] ?? '')),
                        'type' => $answerType,
                        'ttl' => max(0, (int) ($answer['TTL'] ?? 0)),
                        'data' => $answerData,
                    ];
                    if ($answerType === $recordType) {
                        $values[] = $answerData;
                    }
                }
            }
        }

        $values = array_values(array_unique($values));

        $errorMessage = '';
        if ($curlError !== '') {
            $errorMessage = $curlError;
        } elseif (!$success) {
            $errorMessage = 'HTTP ' . $httpCode;
        } elseif ($dnsStatus !== null && $dnsStatus !== 0 && empty($answers)) {
            $errorMessage = 'DNS Status ' . $dnsStatus;
        }

        return [
            'resolver_key' => $resolverKey,
            'resolver_name' => $resolverName,
            'success' => $success,
            'http_code' => $httpCode,
            'dns_status' => $dnsStatus,
            'response_ms' => max(0, $responseMs),
            'answers' => $answers,
            'values' => $values,
            'error' => $errorMessage,
        ];
    }

    private static function buildSummary(array $resolverResults): array
    {
        $resolverTotal = count($resolverResults);
        $resolverSuccess = 0;
        $resolverWithAnswer = 0;
        $signatures = [];
        $allValues = [];

        foreach ($resolverResults as $resolverResult) {
            if (!is_array($resolverResult)) {
                continue;
            }
            if (!empty($resolverResult['success'])) {
                $resolverSuccess++;
            }
            $values = is_array($resolverResult['values'] ?? null) ? $resolverResult['values'] : [];
            if (!empty($values)) {
                $resolverWithAnswer++;
                sort($values, SORT_NATURAL);
                $signatures[] = sha1(implode('|', $values));
                foreach ($values as $value) {
                    $allValues[$value] = $value;
                }
            }
        }

        $consensus = !empty($signatures) && count(array_unique($signatures)) === 1;

        return [
            'resolver_total' => $resolverTotal,
            'resolver_success' => $resolverSuccess,
            'resolver_with_answer' => $resolverWithAnswer,
            'consensus' => $consensus,
            'flatten_values' => array_values($allValues),
        ];
    }

    private static function logLookupMeta(array $moduleSettings, int $requestUserId, string $domain, string $recordType, array $summary, int $durationMs, array $context): void
    {
        $logMode = strtolower(trim((string) ($moduleSettings['dig_log_mode'] ?? 'meta')));
        if ($logMode === 'off') {
            return;
        }

        if (!function_exists('cloudflare_subdomain_log')) {
            return;
        }

        $details = [
            'domain' => $domain,
            'record_type' => $recordType,
            'resolver_total' => (int) ($summary['resolver_total'] ?? 0),
            'resolver_success' => (int) ($summary['resolver_success'] ?? 0),
            'resolver_with_answer' => (int) ($summary['resolver_with_answer'] ?? 0),
            'consensus' => !empty($summary['consensus']) ? 1 : 0,
            'duration_ms' => max(0, $durationMs),
        ];

        if (isset($context['cache_hit'])) {
            $details['cache_hit'] = !empty($context['cache_hit']) ? 1 : 0;
        }

        cloudflare_subdomain_log('client_dig_lookup', $details, $requestUserId, null);
    }

    private static function buildResolverUrl(string $template, string $domain, string $recordType): string
    {
        return strtr($template, [
            '{domain}' => rawurlencode($domain),
            '{type}' => rawurlencode($recordType),
        ]);
    }

    private static function resolveTimeoutSeconds(array $moduleSettings): int
    {
        $timeout = (int) ($moduleSettings['dig_timeout_seconds'] ?? 6);
        if ($timeout <= 0) {
            $timeout = 6;
        }
        return max(2, min(15, $timeout));
    }

    private static function normalizeDomain(string $input): string
    {
        $input = trim(strtolower($input));
        if ($input === '') {
            return '';
        }

        $input = preg_replace('#^https?://#i', '', $input);
        $input = explode('/', $input)[0] ?? $input;
        $input = trim($input, '.');

        if ($input === '') {
            return '';
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/', $input)) {
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
            $ascii = idn_to_ascii($input, IDNA_DEFAULT, $variant);
            if ($ascii === false) {
                $ascii = idn_to_ascii($input);
            }
            if (is_string($ascii) && $ascii !== '') {
                $input = strtolower($ascii);
            }
        }

        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $input)) {
            return '';
        }

        return $input;
    }

    private static function normalizeRecordType(string $input): string
    {
        $value = strtoupper(trim($input));
        return array_key_exists($value, self::TYPE_TO_CODE) ? $value : '';
    }

    private static function codeToType(int $code): string
    {
        $type = array_search($code, self::TYPE_TO_CODE, true);
        if (is_string($type) && $type !== '') {
            return $type;
        }
        return (string) $code;
    }
}
