<?php
/**
 * PowerDNS API Client
 *
 * Supports self-hosted PowerDNS Authoritative Server with HTTP API enabled.
 * Credentials mapping:
 * - $api_url parameter (access_key_id) -> PowerDNS API URL (e.g., http://localhost:8081/api/v1)
 * - $api_key parameter (access_key_secret) -> X-API-Key header value
 */
class PowerDNSAPI
{
    private const MAX_RETRIES = 3;
    private const RETRY_BASE_DELAY_MS = 200;
    private const DEFAULT_TTL = 3600;

    private $api_url;
    private $api_key;
    private $server_id;
    private $timeout;
    private $supportsRrsetFilter = null;
    private $zoneDetailCache = [];
    private $zoneRrsetIndex = [];

    /**
     * @param string $api_url PowerDNS API base URL (e.g., http://localhost:8081/api/v1)
     * @param string $api_key X-API-Key for authentication
     * @param string $server_id Server ID (default: localhost)
     * @param int $timeout Request timeout in seconds
     */
    public function __construct(string $api_url, string $api_key, string $server_id = 'localhost', int $timeout = 30)
    {
        $this->api_url = rtrim(trim($api_url), '/');
        $this->api_key = trim($api_key);
        $this->server_id = $server_id ?: 'localhost';
        $this->timeout = max(5, $timeout);
    }

    /**
     * Make HTTP request to PowerDNS API with retry logic
     */
    private function request(string $method, string $endpoint, ?array $data = null): array
    {
        $attempt = 0;
        $response = [];
        do {
            $attempt++;
            $response = $this->performRequest($method, $endpoint, $data);
            if (!$this->shouldRetry($response) || $attempt >= self::MAX_RETRIES) {
                break;
            }
            usleep($this->retryDelayMicros($attempt));
        } while (true);

        return $response;
    }

    private function performRequest(string $method, string $endpoint, ?array $data = null): array
    {
        $url = $this->api_url . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        $headers = [
            'X-API-Key: ' . $this->api_key,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'errors' => ['curl_error' => $error],
                'http_code' => $httpCode,
            ];
        }

        // Empty response is OK for DELETE/PATCH operations
        if ($result === '' || $result === false) {
            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true, 'result' => [], 'http_code' => $httpCode];
            }
            return [
                'success' => false,
                'errors' => ['empty_response' => 'Empty response from server'],
                'http_code' => $httpCode,
            ];
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded) && $httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'result' => [], 'http_code' => $httpCode];
        }

        if (!is_array($decoded)) {
            return [
                'success' => false,
                'errors' => ['json_decode_error' => 'Invalid JSON: ' . substr($result, 0, 200)],
                'http_code' => $httpCode,
            ];
        }

        // PowerDNS returns error in 'error' field
        if (isset($decoded['error'])) {
            return [
                'success' => false,
                'errors' => ['pdns_error' => $decoded['error']],
                'http_code' => $httpCode,
            ];
        }

        $ok = $httpCode >= 200 && $httpCode < 300;
        return [
            'success' => $ok,
            'result' => $decoded,
            'http_code' => $httpCode,
        ];
    }

    private function shouldRetry(array $response): bool
    {
        if ($response['success'] ?? false) {
            return false;
        }
        $httpCode = $response['http_code'] ?? 0;
        if ($httpCode === 0 || ($httpCode >= 500 && $httpCode < 600)) {
            return true;
        }
        $errors = $response['errors'] ?? [];
        if (isset($errors['curl_error'])) {
            return true;
        }
        return false;
    }

    private function retryDelayMicros(int $attempt): int
    {
        $delayMs = self::RETRY_BASE_DELAY_MS * max(1, $attempt);
        return min(1500, $delayMs) * 1000;
    }

    private function buildQueryString(array $queryParams): string
    {
        if (empty($queryParams)) {
            return '';
        }
        $filtered = [];
        foreach ($queryParams as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $filtered[$key] = $value;
        }
        if (empty($filtered)) {
            return '';
        }
        $encodingMode = null;
        if (defined('PHP_QUERY_RFC3986')) {
            $encodingMode = constant('PHP_QUERY_RFC3986');
        } elseif (defined('PHP_QUERY_RFC1738')) {
            $encodingMode = constant('PHP_QUERY_RFC1738');
        }
        $queryString = $encodingMode === null
            ? http_build_query($filtered)
            : http_build_query($filtered, '', '&', $encodingMode);
        if ($queryString === '') {
            return '';
        }
        return '?' . $queryString;
    }

    private function buildServerEndpoint(string $path, array $queryParams = []): string
    {
        $endpoint = '/servers/' . urlencode($this->server_id) . $path;
        return $endpoint . $this->buildQueryString($queryParams);
    }

    private function buildZoneEndpoint(string $zoneName, array $queryParams = []): string
    {
        $endpoint = '/servers/' . urlencode($this->server_id) . '/zones/' . urlencode($zoneName);
        return $endpoint . $this->buildQueryString($queryParams);
    }

    private function shouldRetryWithoutRrsetFilter(array $response): bool
    {
        if (($response['http_code'] ?? 0) === 404) {
            return false;
        }
        $errors = $response['errors'] ?? [];
        if (empty($errors)) {
            return false;
        }
        $messages = [];
        foreach ($errors as $value) {
            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    $messages[] = $encoded;
                }
            } elseif (is_scalar($value)) {
                $messages[] = (string) $value;
            }
        }
        $text = strtolower(trim(implode(' ', $messages)));
        if ($text === '') {
            return false;
        }
        return strpos($text, 'rrset_name') !== false
            || strpos($text, 'rrset_type') !== false
            || strpos($text, 'unknown parameter') !== false;
    }

    private function requestZoneDetail(string $zoneName, array $queryParams = [], bool $cacheable = false): array
    {
        $zoneName = $this->normalizeZoneName($zoneName);
        $useCache = $cacheable && empty($queryParams);
        if ($useCache && isset($this->zoneDetailCache[$zoneName])) {
            $cached = $this->zoneDetailCache[$zoneName];
            $this->ensureZoneIndex($zoneName, $cached);
            return $cached;
        }
        $endpoint = $this->buildZoneEndpoint($zoneName, $queryParams);
        $res = $this->request('GET', $endpoint);
        if ($useCache && ($res['success'] ?? false)) {
            $this->zoneDetailCache[$zoneName] = $res;
            $this->ensureZoneIndex($zoneName, $res);
        }
        return $res;
    }

    private function fetchZoneRrsetsRaw(string $zoneName, ?string $recordName = null, ?string $type = null, int $searchLimit = 500, bool $allowFullZoneFallback = true): array
    {
        $zoneName = $this->normalizeZoneName($zoneName);
        $recordNameNormalized = $recordName ? $this->normalizeRecordName($recordName) : null;
        $typeNormalized = $type ? strtoupper($type) : null;

        if ($recordNameNormalized === null) {
            return $this->requestZoneDetail($zoneName, [], true);
        }

        $searchUnavailable = false;

        if ($this->supportsRrsetFilter !== false) {
            $query = [
                'rrsets' => 'true',
                'rrset_name' => $recordNameNormalized,
            ];
            if ($typeNormalized !== null) {
                $query['rrset_type'] = $typeNormalized;
            }
            $res = $this->requestZoneDetail($zoneName, $query, false);
            if ($res['success'] ?? false) {
                $this->supportsRrsetFilter = true;
                return $res;
            }
            if ($this->supportsRrsetFilter === null && $this->shouldRetryWithoutRrsetFilter($res)) {
                $this->supportsRrsetFilter = false;
            } else {
                return $res;
            }
        }

        if ($recordNameNormalized !== null) {
            $search = $this->searchZoneRrsets($zoneName, $recordNameNormalized, $typeNormalized, $searchLimit);
            if ($search['success'] ?? false) {
                return $search;
            }
            $code = isset($search['http_code']) ? (int) $search['http_code'] : 0;
            if (in_array($code, [400, 404, 405, 501], true)) {
                $searchUnavailable = true;
            }
        }

        if (!$allowFullZoneFallback) {
            $errors = ['full_zone_disabled' => 'full zone fetch disabled'];
            if ($searchUnavailable) {
                $errors['search_unavailable'] = 'PowerDNS search-data endpoint unavailable';
            }
            return ['success' => false, 'errors' => $errors];
        }

        $fullZone = $this->requestZoneDetail($zoneName, [], true);
        if ($recordNameNormalized !== null && ($fullZone['success'] ?? false)) {
            $this->ensureZoneIndex($zoneName, $fullZone);
            $matches = [];
            $recordIndex = $this->zoneRrsetIndex[$zoneName][$recordNameNormalized] ?? [];
            if ($typeNormalized !== null) {
                if (isset($recordIndex[$typeNormalized])) {
                    $matches[] = $recordIndex[$typeNormalized];
                }
            } else {
                foreach ($recordIndex as $rrset) {
                    $matches[] = $rrset;
                }
            }
            if (!empty($matches)) {
                return ['success' => true, 'result' => ['rrsets' => $matches]];
            }
        }

        return $fullZone;
    }

    private function searchZoneRrsets(string $zoneName, string $recordName, ?string $type = null, int $limit = 500, bool $strictNameMatch = true, bool $matchSubtree = false): array
    {
        $limit = max(25, min(1000, $limit));
        $endpoint = $this->buildServerEndpoint('/search-data', [
            'q' => $recordName,
            'max' => $limit,
            'object' => 'record',
        ]);
        $res = $this->request('GET', $endpoint);
        if (!($res['success'] ?? false)) {
            return $res;
        }
        $normalizedZone = $this->normalizeZoneName($zoneName);
        $normalizedName = $this->normalizeRecordName($recordName);
        $typeNormalized = $type ? strtoupper($type) : null;
        $suffixNeedle = $normalizedName !== '' ? ('.' . $normalizedName) : '';
        $grouped = [];
        foreach (($res['result'] ?? []) as $entry) {
            $entryZone = $this->normalizeZoneName($entry['zone_id'] ?? ($entry['zone'] ?? ''));
            if ($entryZone !== $normalizedZone) {
                continue;
            }
            $entryName = $this->normalizeRecordName($entry['name'] ?? '');
            if ($entryName === '') {
                continue;
            }
            $nameMatches = true;
            if ($strictNameMatch) {
                $nameMatches = ($entryName === $normalizedName);
            } elseif ($matchSubtree) {
                $nameMatches = ($entryName === $normalizedName) || ($suffixNeedle !== '' && $this->endsWith($entryName, $suffixNeedle));
            }
            if (!$nameMatches) {
                continue;
            }
            $entryType = strtoupper($entry['type'] ?? ($entry['record_type'] ?? ''));
            if ($entryType === '') {
                continue;
            }
            if ($typeNormalized !== null && $entryType !== $typeNormalized) {
                continue;
            }
            if (!isset($grouped[$entryName])) {
                $grouped[$entryName] = [];
            }
            if (!isset($grouped[$entryName][$entryType])) {
                $grouped[$entryName][$entryType] = [
                    'name' => $entryName,
                    'type' => $entryType,
                    'ttl' => $this->normalizeTtl($entry['ttl'] ?? self::DEFAULT_TTL),
                    'records' => [],
                ];
            }
            $grouped[$entryName][$entryType]['records'][] = [
                'content' => $entry['content'] ?? '',
                'disabled' => !empty($entry['disabled']),
            ];
        }
        $flattened = [];
        foreach ($grouped as $nameGroups) {
            foreach ($nameGroups as $rrset) {
                $flattened[] = $rrset;
            }
        }
        return ['success' => true, 'result' => ['rrsets' => $flattened]];
    }

    private function invalidateZoneCache(string $zoneName): void
    {
        $zoneName = $this->normalizeZoneName($zoneName);
        if (isset($this->zoneDetailCache[$zoneName])) {
            unset($this->zoneDetailCache[$zoneName]);
        }
        if (isset($this->zoneRrsetIndex[$zoneName])) {
            unset($this->zoneRrsetIndex[$zoneName]);
        }
    }

    private function ensureZoneIndex(string $zoneName, array $zoneDetail): void
    {
        if (isset($this->zoneRrsetIndex[$zoneName])) {
            return;
        }
        $rrsets = $zoneDetail['result']['rrsets'] ?? [];
        $index = [];
        foreach ($rrsets as $rrset) {
            $name = $rrset['name'] ?? '';
            $type = strtoupper($rrset['type'] ?? '');
            if ($name === '' || $type === '') {
                continue;
            }
            if (!isset($index[$name])) {
                $index[$name] = [];
            }
            $index[$name][$type] = $rrset;
        }
        $this->zoneRrsetIndex[$zoneName] = $index;
    }

    /**
     * Normalize zone name (ensure trailing dot for PowerDNS)
     */
    private function normalizeZoneName(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name !== '' && substr($name, -1) !== '.') {
            $name .= '.';
        }
        return $name;
    }

    /**
     * Normalize record name (ensure trailing dot)
     */
    private function normalizeRecordName(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name !== '' && substr($name, -1) !== '.') {
            $name .= '.';
        }
        return $name;
    }

    /**
     * Remove trailing dot for external compatibility
     */
    private function stripTrailingDot(string $name): string
    {
        return rtrim($name, '.');
    }

    /**
     * Convert PowerDNS record to Cloudflare-compatible format
     */
    private function mapPdnsToCfRecord(array $rrset, string $zoneName): array
    {
        $records = [];
        $type = $rrset['type'] ?? '';
        $name = $this->stripTrailingDot($rrset['name'] ?? '');
        $ttl = intval($rrset['ttl'] ?? self::DEFAULT_TTL);

        foreach (($rrset['records'] ?? []) as $record) {
            $content = $record['content'] ?? '';
            // For certain record types, strip trailing dots from content
            if (in_array($type, ['CNAME', 'MX', 'NS', 'SRV', 'PTR'])) {
                $content = $this->stripTrailingDot($content);
            }
            $records[] = [
                'id' => $this->generateRecordId($name, $type, $content),
                'type' => $type,
                'name' => $name,
                'content' => $content,
                'ttl' => $ttl,
                'proxied' => false,
                'disabled' => !empty($record['disabled']),
            ];
        }
        return $records;
    }

    /**
     * Generate a unique record ID (PowerDNS doesn't have individual record IDs)
     */
    private function generateRecordId(string $name, string $type, string $content): string
    {
        return 'pdns_' . substr(md5($name . '|' . $type . '|' . $content), 0, 16);
    }

    /**
     * Parse record ID to get name, type, content
     */
    private function parseRecordContext(string $recordId, string $zoneName): ?array
    {
        // For PowerDNS, record_id is the name|type|content hash or stored context
        // We need to lookup the record first
        return null;
    }

    private function normalizeContentForId(string $type, string $content): string
    {
        $type = strtoupper($type);
        if (in_array($type, ['CNAME', 'MX', 'NS', 'SRV', 'PTR'], true)) {
            return $this->stripTrailingDot($content);
        }
        return $content;
    }

    private function formatRecordContentForPatch(string $type, string $content, array $data = []): string
    {
        $type = strtoupper($type);
        if ($type === 'MX') {
            $priority = isset($data['priority']) ? (int) $data['priority'] : 10;
            return $priority . ' ' . $this->ensureTrailingDot($content);
        }
        if ($type === 'TXT') {
            return $this->normalizeTxtInput($content, true);
        }
        if (in_array($type, ['CNAME', 'NS', 'PTR'], true)) {
            return $this->ensureTrailingDot($content);
        }
        if ($type === 'CAA' || $type === 'SRV') {
            return $content;
        }
        return $content;
    }

    private function buildRecordIdFromRaw(string $name, string $type, string $content): string
    {
        return $this->generateRecordId(
            $this->stripTrailingDot($name),
            strtoupper($type),
            $this->normalizeContentForId($type, $content)
        );
    }

    private function normalizeTxtInput(string $content, bool $wrapQuotes = true): string
    {
        $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $trimmed = trim($decoded);
        if ($trimmed === '') {
            return $wrapQuotes ? '""' : '';
        }
        if ($trimmed[0] === '"' && substr($trimmed, -1) === '"' && strlen($trimmed) >= 2) {
            $trimmed = substr($trimmed, 1, -1);
        }
        if (!$wrapQuotes) {
            return $trimmed;
        }
        $escaped = str_replace('"', '\"', $trimmed);
        return '"' . $escaped . '"';
    }


    private function normalizeTtl($ttl): int
    {
        $t = intval($ttl);
        if ($t <= 0) {
            return self::DEFAULT_TTL;
        }
        return max(60, $t);
    }

    private function ensureTrailingDot(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && substr($value, -1) !== '.') {
            return $value . '.';
        }
        return $value;
    }

    private function loadZoneDetail(string $zoneName): array
    {
        return $this->requestZoneDetail($zoneName, [], true);
    }

    // ==================== Public API Methods ====================

    /**
     * Get zone ID (for PowerDNS, zone ID is the zone name with trailing dot)
     */
    public function getZoneId(string $domain)
    {
        $zoneName = $this->normalizeZoneName($domain);
        $endpoint = $this->buildZoneEndpoint($zoneName, ['rrsets' => 'false']);
        $res = $this->request('GET', $endpoint);

        if ($res['success'] ?? false) {
            return $this->stripTrailingDot($res['result']['name'] ?? $domain);
        }
        return false;
    }

    /**
     * Validate API credentials
     */
    public function validateCredentials(): bool
    {
        $endpoint = '/servers/' . urlencode($this->server_id);
        $res = $this->request('GET', $endpoint);
        return ($res['success'] ?? false) === true;
    }

    /**
     * Get all zones
     */
    public function getZones(): array
    {
        $endpoint = '/servers/' . urlencode($this->server_id) . '/zones';
        $res = $this->request('GET', $endpoint);

        if (!($res['success'] ?? false)) {
            return ['success' => false, 'errors' => $res['errors'] ?? ['query failed']];
        }

        $zones = [];
        foreach (($res['result'] ?? []) as $z) {
            $name = $this->stripTrailingDot($z['name'] ?? '');
            $zones[] = [
                'name' => $name,
                'id' => $name,
            ];
        }
        return ['success' => true, 'result' => $zones];
    }

    /**
     * Check if domain/record exists
     */
    public function checkDomainExists(string $zoneId, string $domainName): bool
    {
        $zoneName = $this->normalizeZoneName($zoneId);
        $recordName = $this->normalizeRecordName($domainName);
        $detail = $this->fetchZoneRrsetsRaw($zoneName, $recordName, null, 50, false);
        if ($detail['success'] ?? false) {
            $rrsets = $detail['result']['rrsets'] ?? [];
            return !empty($rrsets);
        }
        $errors = $detail['errors'] ?? [];
        if (isset($errors['full_zone_disabled']) || isset($errors['search_unavailable'])) {
            $exportCheck = $this->checkDomainExistsViaZoneExport($zoneName, $recordName);
            if ($exportCheck !== null) {
                return $exportCheck;
            }
        }
        return false;
    }

    /**
     * Get DNS records for a zone
     */
    public function getDnsRecords(string $zoneId, ?string $name = null, array $params = []): array
    {
        $zoneName = $this->normalizeZoneName($zoneId);
        $targetName = $name ? $this->normalizeRecordName($name) : null;
        $typeFilter = !empty($params['type']) ? strtoupper($params['type']) : null;
        $perPage = isset($params['per_page']) ? (int) $params['per_page'] : 500;
        $perPage = max(25, min(1000, $perPage));

        $detail = $this->fetchZoneRrsetsRaw($zoneName, $targetName, $typeFilter, $perPage);
        if (!($detail['success'] ?? false)) {
            return ['success' => false, 'errors' => $detail['errors'] ?? ['query failed']];
        }

        $rrsets = $detail['result']['rrsets'] ?? [];
        $result = [];

        foreach ($rrsets as $rrset) {
            $rrsetName = $rrset['name'] ?? '';

            if ($targetName !== null && $rrsetName !== $targetName) {
                continue;
            }

            if ($typeFilter !== null && strtoupper($rrset['type'] ?? '') !== $typeFilter) {
                continue;
            }

            $mapped = $this->mapPdnsToCfRecord($rrset, $zoneId);
            foreach ($mapped as $rec) {
                $result[] = $rec;
            }
        }

        return ['success' => true, 'result' => $result];
    }

    /**
     * Get records for a specific domain
     */
    public function getDomainRecords(string $zoneId, string $domainName): array
    {
        $res = $this->getDnsRecords($zoneId, $domainName);
        if ($res['success']) {
            return $res['result'];
        }
        return [];
    }

    /**
     * Create a DNS record
     */
    public function createDnsRecord(string $zoneId, string $name, string $type, string $content, $ttl = 3600, bool $proxied = false): array
    {
        $zoneName = $this->normalizeZoneName($zoneId);
        $recordName = $this->normalizeRecordName($name);
        $type = strtoupper($type);
        $ttl = $this->normalizeTtl($ttl);

        // Normalize content for certain record types
        if (in_array($type, ['CNAME', 'MX', 'NS', 'PTR'])) {
            $content = $this->ensureTrailingDot($content);
        }
        if ($type === 'TXT') {
            $content = $this->normalizeTxtInput($content, true);
        }

        // First, get existing records for this name+type
        $existingRecords = [];
        $rrsetDetail = $this->fetchZoneRrsetsRaw($zoneName, $recordName, $type);
        if ($rrsetDetail['success'] ?? false) {
            foreach (($rrsetDetail['result']['rrsets'] ?? []) as $rrset) {
                if (($rrset['name'] ?? '') === $recordName && strtoupper($rrset['type'] ?? '') === $type) {
                    foreach (($rrset['records'] ?? []) as $rec) {
                        $existingRecords[] = ['content' => $rec['content'], 'disabled' => $rec['disabled'] ?? false];
                    }
                    break;
                }
            }
        }

        // Add new record to existing
        $existingRecords[] = ['content' => $content, 'disabled' => false];

        // PATCH the zone with updated RRset
        $endpoint = '/servers/' . urlencode($this->server_id) . '/zones/' . urlencode($zoneName);
        $payload = [
            'rrsets' => [
                [
                    'name' => $recordName,
                    'type' => $type,
                    'ttl' => $ttl,
                    'changetype' => 'REPLACE',
                    'records' => $existingRecords,
                ]
            ]
        ];

        $res = $this->request('PATCH', $endpoint, $payload);

        if (!($res['success'] ?? false)) {
            return ['success' => false, 'errors' => $res['errors'] ?? ['create failed']];
        }

        $this->invalidateZoneCache($zoneName);

        $recordId = $this->generateRecordId($this->stripTrailingDot($recordName), $type, $this->stripTrailingDot($content));

        return [
            'success' => true,
            'result' => [
                'id' => $recordId,
                'name' => $this->stripTrailingDot($recordName),
                'type' => $type,
                'content' => $this->stripTrailingDot($content),
                'ttl' => $ttl,
                'proxied' => false,
            ]
        ];
    }

    /**
     * Update a DNS record
     */
    public function updateDnsRecord(string $zoneId, string $recordId, array $data): array
    {
        $zoneName = $this->normalizeZoneName($zoneId);
        $type = strtoupper($data['type'] ?? 'A');
        $name = $data['name'] ?? '';
        $content = $data['content'] ?? '';
        $ttl = $this->normalizeTtl($data['ttl'] ?? self::DEFAULT_TTL);

        if ($name === '' || $content === '') {
            return ['success' => false, 'errors' => ['missing required fields']];
        }

        $recordName = $this->normalizeRecordName($name);
        $formattedContent = $this->formatRecordContentForPatch($type, $content, $data);

        $zoneDetail = $this->fetchZoneRrsetsRaw($zoneName, $recordName, $type);
        if (!($zoneDetail['success'] ?? false)) {
            return ['success' => false, 'errors' => $zoneDetail['errors'] ?? ['query failed']];
        }

        $rrsets = $zoneDetail['result']['rrsets'] ?? [];
        $recordsPayload = [];
        $recordMatched = false;
        $ttlForPatch = $ttl;

        foreach ($rrsets as $rrset) {
            if (($rrset['name'] ?? '') === $recordName && strtoupper($rrset['type'] ?? '') === $type) {
                $ttlForPatch = $this->normalizeTtl($rrset['ttl'] ?? $ttl);
                foreach (($rrset['records'] ?? []) as $record) {
                    $existingId = $this->buildRecordIdFromRaw($recordName, $type, $record['content'] ?? '');
                    if ($existingId === $recordId) {
                        $recordsPayload[] = [
                            'content' => $formattedContent,
                            'disabled' => !empty($record['disabled']),
                        ];
                        $recordMatched = true;
                    } else {
                        $recordsPayload[] = [
                            'content' => $record['content'],
                            'disabled' => !empty($record['disabled']),
                        ];
                    }
                }
                break;
            }
        }

        if (empty($recordsPayload)) {
            $recordsPayload[] = ['content' => $formattedContent, 'disabled' => false];
        } elseif (!$recordMatched) {
            $recordsPayload[] = ['content' => $formattedContent, 'disabled' => false];
        }

        $endpoint = '/servers/' . urlencode($this->server_id) . '/zones/' . urlencode($zoneName);
        $payload = [
            'rrsets' => [
                [
                    'name' => $recordName,
                    'type' => $type,
                    'ttl' => $ttlForPatch,
                    'changetype' => 'REPLACE',
                    'records' => $recordsPayload,
                ]
            ]
        ];

        $res = $this->request('PATCH', $endpoint, $payload);

        if (!($res['success'] ?? false)) {
            return ['success' => false, 'errors' => $res['errors'] ?? ['update failed']];
        }

        $this->invalidateZoneCache($zoneName);

        $newRecordId = $this->buildRecordIdFromRaw($recordName, $type, $formattedContent);

        return ['success' => true, 'result' => ['id' => $newRecordId]];
    }

    /**
     * Delete a subdomain/record
     */
    public function deleteSubdomain(string $zoneId, string $recordId, array $context = []): array
    {
        $zoneName = $this->normalizeZoneName($zoneId);
        $recordId = (string) $recordId;

        if ($recordId === '') {
            return ['success' => false, 'errors' => ['record id required']];
        }

        $contextName = isset($context['name']) ? $this->normalizeRecordName((string) $context['name']) : null;
        $contextType = isset($context['type']) ? strtoupper((string) $context['type']) : null;
        $rrsetSource = null;

        if ($contextName !== null && $contextType !== null) {
            $rrsetSource = $this->fetchZoneRrsetsRaw($zoneName, $contextName, $contextType);
        }

        if (!($rrsetSource['success'] ?? false)) {
            $rrsetSource = $this->loadZoneDetail($zoneName);
            if (!($rrsetSource['success'] ?? false)) {
                return ['success' => false, 'errors' => $rrsetSource['errors'] ?? ['query failed']];
            }
        }

        $targetName = '';
        $targetType = '';
        $targetRecords = [];
        $ttl = self::DEFAULT_TTL;

        foreach (($rrsetSource['result']['rrsets'] ?? []) as $rrset) {
            $name = $rrset['name'] ?? '';
            $type = strtoupper($rrset['type'] ?? '');
            if ($contextName !== null && $name !== $contextName) {
                continue;
            }
            if ($contextType !== null && $type !== $contextType) {
                continue;
            }
            foreach (($rrset['records'] ?? []) as $record) {
                $existingId = $this->buildRecordIdFromRaw($name, $type, $record['content'] ?? '');
                if ($existingId === $recordId) {
                    $targetName = $name;
                    $targetType = $type;
                    $targetRecords = $rrset['records'] ?? [];
                    $ttl = $this->normalizeTtl($rrset['ttl'] ?? $ttl);
                    break 2;
                }
            }
        }

        if ($targetName === '') {
            return ['success' => false, 'errors' => ['record not found']];
        }

        $remainingRecords = [];
        foreach ($targetRecords as $record) {
            $existingId = $this->buildRecordIdFromRaw($targetName, $targetType, $record['content'] ?? '');
            if ($existingId === $recordId) {
                continue;
            }
            $remainingRecords[] = [
                'content' => $record['content'],
                'disabled' => !empty($record['disabled']),
            ];
        }

        $endpoint = '/servers/' . urlencode($this->server_id) . '/zones/' . urlencode($zoneName);

        if (empty($remainingRecords)) {
            $payload = [
                'rrsets' => [
                    [
                        'name' => $targetName,
                        'type' => $targetType,
                        'changetype' => 'DELETE',
                    ]
                ]
            ];
        } else {
            $payload = [
                'rrsets' => [
                    [
                        'name' => $targetName,
                        'type' => $targetType,
                        'ttl' => $ttl,
                        'changetype' => 'REPLACE',
                        'records' => $remainingRecords,
                    ]
                ]
            ];
        }

        $res = $this->request('PATCH', $endpoint, $payload);

        if (!($res['success'] ?? false)) {
            return ['success' => false, 'errors' => $res['errors'] ?? ['delete failed']];
        }

        $this->invalidateZoneCache($zoneName);

        return ['success' => true, 'result' => []];
    }

    /**
     * Delete all records for a specific name
     */
    public function deleteDomainRecords(string $zoneId, string $domainName): array
    {
        $zoneName = $this->normalizeZoneName($zoneId);
        $recordName = $this->normalizeRecordName($domainName);

        // Get all record types for this name
        $records = $this->getDnsRecords($zoneId, $domainName);
        if (!($records['success'] ?? false)) {
            return ['success' => false, 'errors' => $records['errors'] ?? ['query failed']];
        }

        if (empty($records['result'])) {
            return ['success' => true, 'deleted_count' => 0];
        }

        // Group by type to delete
        $typesSeen = [];
        foreach ($records['result'] as $rec) {
            $typesSeen[$rec['type']] = true;
        }

        $endpoint = '/servers/' . urlencode($this->server_id) . '/zones/' . urlencode($zoneName);
        $rrsets = [];
        foreach (array_keys($typesSeen) as $type) {
            $rrsets[] = [
                'name' => $recordName,
                'type' => $type,
                'changetype' => 'DELETE',
            ];
        }

        $payload = ['rrsets' => $rrsets];
        $res = $this->request('PATCH', $endpoint, $payload);

        if (!($res['success'] ?? false)) {
            return ['success' => false, 'errors' => $res['errors'] ?? ['delete failed']];
        }

        $this->invalidateZoneCache($zoneName);

        return ['success' => true, 'deleted_count' => count($records['result'])];
    }

    /**
     * Delete records for a name and all its subdomains
     */
    public function deleteDomainRecordsDeep(string $zoneId, string $subdomainRoot): array
    {
        $zoneName = $this->normalizeZoneName($zoneId);
        $target = $this->normalizeRecordName($subdomainRoot);
        $targetNoDot = $this->stripTrailingDot($target);

        // Prefer search API to avoid full zone fetch
        $searchLimit = 2000;
        $allRecords = $this->searchZoneRrsets($zoneName, $targetNoDot, null, $searchLimit, false, true);
        if (!($allRecords['success'] ?? false)) {
            // Fallback to legacy full zone fetch when search-data is unavailable
            $allRecords = $this->getDnsRecords($zoneId);
            if (!($allRecords['success'] ?? false)) {
                return ['success' => false, 'errors' => $allRecords['errors'] ?? ['query failed']];
            }
        }

        // Find records matching target or *.target
        $toDelete = [];
        foreach (($allRecords['result']['rrsets'] ?? $allRecords['result'] ?? []) as $rec) {
            $recNameRaw = $rec['name'] ?? '';
            $recNameNormalized = $this->normalizeRecordName($recNameRaw);
            $recNameStripped = $this->stripTrailingDot($recNameNormalized);
            if ($recNameStripped === $targetNoDot || $this->endsWith($recNameStripped, '.' . $targetNoDot)) {
                $key = $recNameNormalized . '|' . ($rec['type'] ?? '');
                if (!isset($toDelete[$key])) {
                    $toDelete[$key] = ['name' => $recNameNormalized, 'type' => $rec['type'] ?? ''];
                }
            }
        }

        if (empty($toDelete)) {
            return ['success' => true, 'deleted_count' => 0, 'note' => 'deep'];
        }

        $endpoint = '/servers/' . urlencode($this->server_id) . '/zones/' . urlencode($zoneName);
        $rrsets = [];
        foreach ($toDelete as $item) {
            $rrsets[] = [
                'name' => $this->normalizeRecordName($item['name']),
                'type' => $item['type'],
                'changetype' => 'DELETE',
            ];
        }

        $payload = ['rrsets' => $rrsets];
        $res = $this->request('PATCH', $endpoint, $payload);

        if (!($res['success'] ?? false)) {
            return ['success' => false, 'errors' => $res['errors'] ?? ['delete failed']];
        }

        $this->invalidateZoneCache($zoneName);

        return ['success' => true, 'deleted_count' => count($toDelete), 'note' => 'deep'];
    }

    /**
     * Delete a specific record by name, type, and content
     */
    public function deleteRecordByContent(string $zoneId, string $name, string $type, string $content): array
    {
        $zoneName = $this->normalizeZoneName($zoneId);
        $recordName = $this->normalizeRecordName($name);
        $type = strtoupper($type);

        // Get existing records for this name+type
        $existing = $this->getDnsRecords($zoneId, $name, ['type' => $type]);
        if (!($existing['success'] ?? false)) {
            return ['success' => false, 'errors' => $existing['errors'] ?? ['query failed']];
        }

        // Filter out the record to delete
        $remaining = [];
        $found = false;
        foreach (($existing['result'] ?? []) as $rec) {
            if (strtolower($rec['content'] ?? '') === strtolower($content)) {
                $found = true;
                continue;
            }
            $remaining[] = ['content' => $rec['content'], 'disabled' => $rec['disabled'] ?? false];
        }

        if (!$found) {
            return ['success' => false, 'errors' => ['record not found']];
        }

        $endpoint = '/servers/' . urlencode($this->server_id) . '/zones/' . urlencode($zoneName);

        if (empty($remaining)) {
            // Delete the entire RRset
            $payload = [
                'rrsets' => [
                    [
                        'name' => $recordName,
                        'type' => $type,
                        'changetype' => 'DELETE',
                    ]
                ]
            ];
        } else {
            // Replace with remaining records
            $ttl = $existing['result'][0]['ttl'] ?? self::DEFAULT_TTL;
            $payload = [
                'rrsets' => [
                    [
                        'name' => $recordName,
                        'type' => $type,
                        'ttl' => $ttl,
                        'changetype' => 'REPLACE',
                        'records' => $remaining,
                    ]
                ]
            ];
        }

        $res = $this->request('PATCH', $endpoint, $payload);

        if (!($res['success'] ?? false)) {
            return ['success' => false, 'errors' => $res['errors'] ?? ['delete failed']];
        }

        $this->invalidateZoneCache($zoneName);

        return ['success' => true, 'result' => []];
    }

    /**
     * Create subdomain with default A record
     */
    public function createSubdomain(string $zoneId, string $subdomain, string $ip = '192.0.2.1', bool $proxied = true, string $type = 'A'): array
    {
        return $this->createDnsRecord($zoneId, $subdomain, $type, $ip, 120, false);
    }

    /**
     * Update subdomain
     */
    public function updateSubdomain(string $zoneId, string $recordId, string $subdomain, string $ip, bool $proxied = true): array
    {
        return $this->updateDnsRecord($zoneId, $recordId, [
            'type' => 'A',
            'name' => $subdomain,
            'content' => $ip,
            'ttl' => 120,
        ]);
    }

    /**
     * Create CNAME record
     */
    public function createCNAMERecord(string $zoneId, string $name, string $target, int $ttl = 3600, bool $proxied = false): array
    {
        return $this->createDnsRecord($zoneId, $name, 'CNAME', $target, $ttl, false);
    }

    /**
     * Create MX record
     */
    public function createMXRecord(string $zoneId, string $name, string $mailServer, int $priority = 10, int $ttl = 3600): array
    {
        // PowerDNS MX format: "priority mailserver."
        $content = $priority . ' ' . $this->ensureTrailingDot($mailServer);
        return $this->createDnsRecord($zoneId, $name, 'MX', $content, $ttl, false);
    }

    /**
     * Create SRV record
     */
    public function createSRVRecord(string $zoneId, string $name, string $target, int $port, int $priority = 0, int $weight = 0, int $ttl = 3600): array
    {
        // PowerDNS SRV format: "priority weight port target."
        $content = $priority . ' ' . $weight . ' ' . $port . ' ' . $this->ensureTrailingDot($target);
        return $this->createDnsRecord($zoneId, $name, 'SRV', $content, $ttl, false);
    }

    /**
     * Create CAA record
     */
    public function createCAARecord(string $zoneId, string $name, int $flags, string $tag, string $value, int $ttl = 3600): array
    {
        // PowerDNS CAA format: "flags tag \"value\""
        $content = $flags . ' ' . $tag . ' "' . str_replace('"', '\\"', $value) . '"';
        return $this->createDnsRecord($zoneId, $name, 'CAA', $content, $ttl, false);
    }

    /**
     * Create TXT record
     */
    public function createTXTRecord(string $zoneId, string $name, string $content, int $ttl = 3600): array
    {
        // Ensure TXT content is quoted
        if (strlen($content) > 0 && $content[0] !== '"') {
            $content = '"' . str_replace('"', '\\"', $content) . '"';
        }
        return $this->createDnsRecord($zoneId, $name, 'TXT', $content, $ttl, false);
    }

    /**
     * Toggle proxy (not supported in PowerDNS)
     */
    public function toggleProxy(string $zoneId, string $recordId, bool $proxied): array
    {
        return ['success' => true, 'result' => ['proxied' => false, 'note' => 'PowerDNS does not support proxy']];
    }

    /**
     * Get single DNS record by ID
     */
    public function getDnsRecord(string $zoneId, string $recordId): array
    {
        // PowerDNS doesn't have individual record IDs, need to search
        $records = $this->getDnsRecords($zoneId);
        if (!($records['success'] ?? false)) {
            return ['success' => false, 'errors' => ['query failed']];
        }

        foreach (($records['result'] ?? []) as $rec) {
            if (($rec['id'] ?? '') === $recordId) {
                return ['success' => true, 'result' => $rec];
            }
        }

        return ['success' => false, 'errors' => ['record not found']];
    }

    /**
     * Raw record creation with full payload support
     */
    public function createDnsRecordRaw(string $zoneId, array $payload): array
    {
        if (!isset($payload['type'], $payload['name'])) {
            return ['success' => false, 'errors' => ['missing required fields']];
        }
        return $this->createDnsRecord(
            $zoneId,
            $payload['name'],
            $payload['type'],
            $payload['content'] ?? '',
            $payload['ttl'] ?? self::DEFAULT_TTL,
            false
        );
    }

    /**
     * Raw record update
     */
    public function updateDnsRecordRaw(string $zoneId, string $recordId, array $payload): array
    {
        return $this->updateDnsRecord($zoneId, $recordId, $payload);
    }

    /**
     * Get account/server info
     */
    public function getAccountInfo(): array
    {
        $ok = $this->validateCredentials();
        return ['success' => $ok];
    }

    /**
     * Search zones
     */
    public function searchZone(string $searchTerm): array
    {
        $res = $this->getZones();
        if (!($res['success'] ?? false)) {
            return $res;
        }
        $term = strtolower($searchTerm);
        $filtered = array_values(array_filter($res['result'] ?? [], function ($z) use ($term) {
            return strpos(strtolower($z['name'] ?? ''), $term) !== false;
        }));
        return ['success' => true, 'result' => $filtered];
    }

    /**
     * Get zone details
     */
    public function getZoneDetails(string $zoneId): array
    {
        $zoneName = $this->normalizeZoneName($zoneId);
        $endpoint = '/servers/' . urlencode($this->server_id) . '/zones/' . urlencode($zoneName);
        $res = $this->request('GET', $endpoint);
        return [
            'success' => $res['success'] ?? false,
            'result' => $res['result'] ?? [],
        ];
    }

    // Unsupported Cloudflare-specific methods
    public function getZoneSettings(string $zoneId): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function updateZoneSetting(string $zoneId, string $settingName, $value): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function enableCDN(string $zoneId): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function getZoneAnalytics(string $zoneId, string $since = '-7d', string $until = 'now'): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function getFirewallRules(string $zoneId): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function createFirewallRule(string $zoneId, string $expression, string $action = 'block', string $description = ''): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function getPageRules(string $zoneId): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function createPageRule(string $zoneId, string $urlPattern, array $actions, int $priority = 1, string $status = 'active'): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function getRateLimits(string $zoneId): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function createRateLimit(string $zoneId, string $expression, int $threshold, int $period, string $action = 'block'): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function purgeCache(string $zoneId, ?array $files = null): array
    {
        return ['success' => false, 'errors' => ['unsupported' => 'PowerDNS']];
    }

    public function batchUpdateDnsRecords(string $zoneId, array $updates): array
    {
        $results = [];
        foreach ($updates as $update) {
            if (isset($update['id'])) {
                $results[] = $this->updateDnsRecord($zoneId, $update['id'], $update);
            } else {
                $results[] = $this->createDnsRecord(
                    $zoneId,
                    $update['name'] ?? '',
                    $update['type'] ?? 'A',
                    $update['content'] ?? '',
                    $update['ttl'] ?? self::DEFAULT_TTL,
                    false
                );
            }
        }
        return $results;
    }

    private function checkDomainExistsViaZoneExport(string $zoneName, string $recordName, ?string $type = null): ?bool
    {
        $endpoint = '/servers/' . urlencode($this->server_id) . '/zones/' . urlencode($zoneName) . '/export';
        $url = $this->api_url . $endpoint;
        $temp = fopen('php://temp', 'w+');
        if ($temp === false) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($temp);
            return null;
        }
        $headers = [
            'X-API-Key: ' . $this->api_key,
            'Accept: text/dns',
        ];
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_FILE, $temp);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($ok === false || $error) {
            fclose($temp);
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            fclose($temp);
            return null;
        }
        rewind($temp);
        $currentOrigin = $this->normalizeZoneName($zoneName);
        $lastOwnerToken = '@';
        $targetName = $this->normalizeRecordName($recordName);
        $typeNormalized = $type ? strtoupper($type) : null;
        while (($line = fgets($temp)) !== false) {
            $parsed = $this->parseZoneExportLine($line, $currentOrigin, $lastOwnerToken);
            if ($parsed === null) {
                continue;
            }
            [$ownerFqdn, $recordType] = $parsed;
            if ($ownerFqdn === $targetName) {
                if ($typeNormalized === null || $recordType === $typeNormalized) {
                    fclose($temp);
                    return true;
                }
            }
        }
        fclose($temp);
        return false;
    }

    private function parseZoneExportLine(string $line, string &$currentOrigin, string &$lastOwnerToken): ?array
    {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === ';') {
            return null;
        }
        if ($trimmed[0] === '$') {
            $this->applyZoneDirective($trimmed, $currentOrigin);
            return null;
        }
        $commentPos = strpos($trimmed, ';');
        if ($commentPos !== false) {
            $trimmed = trim(substr($trimmed, 0, $commentPos));
            if ($trimmed === '') {
                return null;
            }
        }
        $tokens = preg_split('/\s+/', $trimmed);
        if (!$tokens) {
            return null;
        }
        $tokenCount = count($tokens);
        $idx = 0;
        if ($this->isLikelyOwnerToken($tokens[0])) {
            $ownerToken = $tokens[0];
            $lastOwnerToken = $ownerToken;
            $idx = 1;
        } else {
            $ownerToken = $lastOwnerToken ?: '@';
        }
        if ($idx < $tokenCount && $this->isTtlToken($tokens[$idx])) {
            $idx++;
        }
        if ($idx < $tokenCount && $this->isClassToken($tokens[$idx])) {
            $idx++;
        }
        if ($idx >= $tokenCount) {
            return null;
        }
        $typeToken = strtoupper($tokens[$idx]);
        if (!$this->isTypeToken($typeToken)) {
            return null;
        }
        $ownerFqdn = $this->resolveOwnerTokenToFqdn($ownerToken, $currentOrigin);
        return [$ownerFqdn, $typeToken];
    }

    private function applyZoneDirective(string $line, string &$currentOrigin): void
    {
        $parts = preg_split('/\s+/', $line);
        if (!$parts) {
            return;
        }
        $directive = strtoupper($parts[0]);
        if ($directive === '$ORIGIN' && isset($parts[1])) {
            $currentOrigin = $this->normalizeZoneName($parts[1]);
        }
    }

    private function isLikelyOwnerToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '' || $token[0] === ';' || $token[0] === '(' || $token[0] === ')') {
            return false;
        }
        if ($token[0] === '$') {
            return false;
        }
        if ($this->isTtlToken($token)) {
            return false;
        }
        $upper = strtoupper($token);
        if ($this->isClassToken($upper) || $this->isTypeToken($upper)) {
            return false;
        }
        return true;
    }

    private function isTtlToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        return preg_match('/^[0-9]+[SMHDWsmhdw]?$/', $token) === 1;
    }

    private function isClassToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $upper = strtoupper($token);
        return in_array($upper, ['IN', 'CH', 'HS', 'NONE', 'ANY'], true);
    }

    private function isTypeToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        static $types = [
            'A', 'AAAA', 'AFSDB', 'ALIAS', 'ANAME', 'APL', 'CAA', 'CERT', 'CNAME', 'DHCID',
            'DLV', 'DNSKEY', 'DS', 'EUI48', 'EUI64', 'HINFO', 'HTTPS', 'IPSECKEY', 'KEY',
            'LOC', 'MX', 'NAPTR', 'NS', 'NSAP', 'PTR', 'RP', 'SOA', 'SPF', 'SRV', 'SSHFP',
            'SVCB', 'TLSA', 'TXT', 'URI'
        ];
        return in_array(strtoupper($token), $types, true);
    }

    private function resolveOwnerTokenToFqdn(string $ownerToken, string $currentOrigin): string
    {
        $ownerToken = trim($ownerToken);
        if ($ownerToken === '' || $ownerToken === '@') {
            return $currentOrigin;
        }
        if (substr($ownerToken, -1) === '.') {
            return $this->normalizeRecordName($ownerToken);
        }
        $origin = $this->stripTrailingDot($currentOrigin);
        return $this->normalizeRecordName($ownerToken . '.' . $origin);
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        if (strlen($haystack) < $len) {
            return false;
        }
        return substr($haystack, -$len) === $needle;
    }
}
