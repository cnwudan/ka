<?php

use WHMCS\Database\Capsule;

require_once __DIR__ . '/ProviderResolver.php';

if (!function_exists('cfmod_admin_provider_error_text')) {
    function cfmod_admin_provider_error_text($result): string
    {
        if (is_string($result)) {
            return trim($result);
        }
        if (!is_array($result)) {
            return '';
        }

        $parts = [];
        if (isset($result['code'])) {
            $parts[] = 'code:' . $result['code'];
        }
        if (isset($result['http_code'])) {
            $parts[] = 'http:' . $result['http_code'];
        }

        $errors = $result['errors'] ?? null;
        if (is_array($errors) && !empty($errors)) {
            $parts[] = json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_string($errors) && trim($errors) !== '') {
            $parts[] = $errors;
        }

        $message = $result['message'] ?? ($result['error'] ?? null);
        if (is_array($message) && !empty($message)) {
            $parts[] = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_string($message) && trim($message) !== '') {
            $parts[] = $message;
        }

        return trim(implode(' | ', array_filter($parts)));
    }
}

if (!function_exists('cfmod_admin_provider_not_found')) {
    function cfmod_admin_provider_not_found($result): bool
    {
        if (is_array($result)) {
            $code = $result['code'] ?? ($result['http_code'] ?? null);
            if ($code === 404 || $code === '404') {
                return true;
            }
        }

        $message = strtolower(cfmod_admin_provider_error_text($result));
        if ($message === '') {
            return false;
        }

        return strpos($message, 'not found') !== false
            || strpos($message, 'record not found') !== false
            || strpos($message, 'does not exist') !== false
            || strpos($message, 'no such') !== false
            || strpos($message, '不存在') !== false;
    }
}

if (!function_exists('cfmod_admin_deep_delete_subdomain')) {
    function cfmod_admin_deep_delete_subdomain($cf, $record, string $errorMessage = '当前子域绑定的 DNS 供应商不可用，请联系管理员'): int
    {
        if (!$record) {
            return 0;
        }

        $zoneId = $record->cloudflare_zone_id ?? '';
        if (!$zoneId && !empty($record->rootdomain)) {
            $zoneId = $record->rootdomain;
        }
        $subdomainName = strtolower(trim($record->subdomain ?? ''));
        $deletedCount = 0;

        if (!$cf) {
            $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
            $providerContext = cfmod_acquire_provider_client_for_subdomain($record, $settings);
            if ($providerContext && !empty($providerContext['client'])) {
                $cf = $providerContext['client'];
            } else {
                $cf = null;
            }
        }

        if (!$cf || !$zoneId || $subdomainName === '') {
            throw new \RuntimeException($errorMessage);
        }

        $remoteSuccess = false;
        $remoteNotFound = false;
        $lastRemoteError = '';

        $tryDelete = static function ($res, int &$deletedCount, bool &$remoteSuccess, bool &$remoteNotFound, string &$lastRemoteError): void {
            if (!is_array($res)) {
                return;
            }
            if ($res['success'] ?? false) {
                $deletedCount = max($deletedCount, intval($res['deleted_count'] ?? 0));
                $remoteSuccess = true;
                return;
            }
            if (cfmod_admin_provider_not_found($res)) {
                $remoteNotFound = true;
                return;
            }
            $lastRemoteError = cfmod_admin_provider_error_text($res);
        };

        try {
            $deepRes = $cf->deleteDomainRecordsDeep($zoneId, $subdomainName);
            $tryDelete($deepRes, $deletedCount, $remoteSuccess, $remoteNotFound, $lastRemoteError);
        } catch (\Throwable $e) {
            $lastRemoteError = $e->getMessage();
        }

        if (!$remoteSuccess && !$remoteNotFound) {
            try {
                $fallbackRes = $cf->deleteDomainRecords($zoneId, $subdomainName);
                $tryDelete($fallbackRes, $deletedCount, $remoteSuccess, $remoteNotFound, $lastRemoteError);
            } catch (\Throwable $e) {
                $lastRemoteError = $e->getMessage();
            }
        }

        if (!$remoteSuccess && !$remoteNotFound && !empty($record->dns_record_id)) {
            try {
                $singleRes = $cf->deleteSubdomain($zoneId, $record->dns_record_id, [
                    'name' => $subdomainName,
                ]);
                if (($singleRes['success'] ?? false) || cfmod_admin_provider_not_found($singleRes)) {
                    $remoteSuccess = true;
                    $deletedCount = max($deletedCount, 1);
                } else {
                    $lastRemoteError = cfmod_admin_provider_error_text($singleRes);
                }
            } catch (\Throwable $e) {
                $lastRemoteError = $e->getMessage();
            }
        }

        if (!$remoteSuccess && !$remoteNotFound) {
            $detail = trim($lastRemoteError);
            if ($detail !== '') {
                $detail = function_exists('cfmod_format_provider_error') ? cfmod_format_provider_error($detail) : $detail;
                throw new \RuntimeException($errorMessage . '：' . $detail);
            }
            throw new \RuntimeException($errorMessage);
        }

        Capsule::table('mod_cloudflare_dns_records')->where('subdomain_id', $record->id)->delete();

        return $deletedCount;
    }
}
