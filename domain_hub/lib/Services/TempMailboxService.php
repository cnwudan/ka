<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfTempMailboxService
{
    private const DEFAULT_RETENTION_HOURS = 48;
    private const MAX_RETENTION_HOURS = 48;
    private const DEFAULT_PAGE_SIZE = 15;

    public static function isEnabled(array $settings): bool
    {
        return function_exists('cfmod_setting_enabled')
            ? cfmod_setting_enabled($settings['enable_temp_mailbox'] ?? '0')
            : in_array(strtolower(trim((string) ($settings['enable_temp_mailbox'] ?? '0'))), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function resolveMailboxDomain(array $settings): string
    {
        $domain = strtolower(trim((string) ($settings['temp_mailbox_domain'] ?? '')));
        $domain = ltrim($domain, '@');
        $domain = rtrim($domain, '.');

        if ($domain === '' || !preg_match('/^[a-z0-9](?:[a-z0-9\-\.]{0,251}[a-z0-9])$/', $domain)) {
            return '';
        }

        return $domain;
    }

    public static function resolveWebhookSecret(array $settings): string
    {
        return trim((string) ($settings['temp_mailbox_webhook_secret'] ?? ''));
    }

    public static function resolveRetentionHours(array $settings): int
    {
        $hours = (int) ($settings['temp_mailbox_retention_hours'] ?? self::DEFAULT_RETENTION_HOURS);
        if ($hours <= 0) {
            $hours = self::DEFAULT_RETENTION_HOURS;
        }

        return max(1, min(self::MAX_RETENTION_HOURS, $hours));
    }

    public static function buildClientState(int $userId, array $settings, int $page = 1, int $selectedEmailId = 0): array
    {
        $enabled = self::isEnabled($settings);
        $domain = self::resolveMailboxDomain($settings);
        $retentionHours = self::resolveRetentionHours($settings);

        $state = [
            'enabled' => $enabled,
            'configured' => $enabled && $domain !== '',
            'domain' => $domain,
            'retention_hours' => $retentionHours,
            'mailbox' => null,
            'emails' => [
                'items' => [],
                'page' => 1,
                'perPage' => self::DEFAULT_PAGE_SIZE,
                'total' => 0,
                'totalPages' => 1,
            ],
            'selected' => null,
            'notice' => '',
        ];

        if (!$enabled) {
            $state['notice'] = 'disabled';
            return $state;
        }

        if ($domain === '') {
            $state['notice'] = 'domain_missing';
            return $state;
        }

        if ($userId <= 0) {
            $state['notice'] = 'invalid_user';
            return $state;
        }

        $mailbox = self::ensureUserMailbox($userId, $settings);
        if ($mailbox === null) {
            $state['notice'] = 'mailbox_unavailable';
            return $state;
        }

        $state['mailbox'] = $mailbox;
        $state['emails'] = self::listUserEmails($userId, $page, self::DEFAULT_PAGE_SIZE);
        if ($selectedEmailId > 0) {
            $state['selected'] = self::getUserEmail($userId, $selectedEmailId);
        }

        return $state;
    }

    public static function ensureUserMailbox(int $userId, array $settings): ?array
    {
        if ($userId <= 0 || !self::isEnabled($settings)) {
            return null;
        }

        $domain = self::resolveMailboxDomain($settings);
        if ($domain === '') {
            return null;
        }

        try {
            $existing = Capsule::table('mod_cloudflare_temp_mailboxes')
                ->where('userid', $userId)
                ->first();

            if ($existing) {
                $expectedAddress = strtolower(trim((string) ($existing->alias_local ?? ''))) . '@' . $domain;
                $storedAddress = strtolower(trim((string) ($existing->email_address ?? '')));

                if ($storedAddress !== $expectedAddress || $storedAddress === '') {
                    Capsule::table('mod_cloudflare_temp_mailboxes')
                        ->where('id', $existing->id)
                        ->update([
                            'email_address' => $expectedAddress,
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    $existing->email_address = $expectedAddress;
                }

                return self::mapMailboxRow($existing);
            }

            $alias = self::generateAlias($userId);
            $address = $alias . '@' . $domain;
            $now = date('Y-m-d H:i:s');

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $exists = Capsule::table('mod_cloudflare_temp_mailboxes')
                    ->where('alias_local', $alias)
                    ->exists();
                if (!$exists) {
                    break;
                }
                $alias = self::generateAlias($userId);
                $address = $alias . '@' . $domain;
            }

            $insertId = Capsule::table('mod_cloudflare_temp_mailboxes')->insertGetId([
                'userid' => $userId,
                'alias_local' => $alias,
                'email_address' => $address,
                'status' => 'active',
                'last_received_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created = Capsule::table('mod_cloudflare_temp_mailboxes')->where('id', $insertId)->first();
            return $created ? self::mapMailboxRow($created) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function listUserEmails(int $userId, int $page = 1, int $perPage = self::DEFAULT_PAGE_SIZE): array
    {
        $page = max(1, $page);
        $perPage = max(5, min(50, $perPage));
        $now = date('Y-m-d H:i:s');

        if ($userId <= 0) {
            return [
                'items' => [],
                'page' => 1,
                'perPage' => $perPage,
                'total' => 0,
                'totalPages' => 1,
            ];
        }

        try {
            $query = Capsule::table('mod_cloudflare_temp_emails')
                ->where('userid', $userId)
                ->where('expires_at', '>', $now);

            $total = (int) (clone $query)->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $rows = $query
                ->orderBy('received_at', 'desc')
                ->orderBy('id', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            $items = [];
            foreach ($rows as $row) {
                $items[] = self::mapEmailListRow($row);
            }

            return [
                'items' => $items,
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ];
        } catch (\Throwable $e) {
            return [
                'items' => [],
                'page' => 1,
                'perPage' => $perPage,
                'total' => 0,
                'totalPages' => 1,
            ];
        }
    }

    public static function getUserEmail(int $userId, int $emailId): ?array
    {
        if ($userId <= 0 || $emailId <= 0) {
            return null;
        }

        try {
            $row = Capsule::table('mod_cloudflare_temp_emails')
                ->where('id', $emailId)
                ->where('userid', $userId)
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->first();

            return $row ? self::mapEmailDetailRow($row) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function storeInboundPayload(array $payload, array $settings): array
    {
        if (!self::isEnabled($settings)) {
            return ['success' => false, 'error' => 'temp mailbox disabled'];
        }

        $domain = self::resolveMailboxDomain($settings);
        if ($domain === '') {
            return ['success' => false, 'error' => 'temp mailbox domain not configured'];
        }

        $recipients = self::extractRecipients($payload);
        if (empty($recipients)) {
            return ['success' => false, 'error' => 'recipient missing'];
        }

        $retentionHours = self::resolveRetentionHours($settings);
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + ($retentionHours * 3600));

        $sender = trim((string) ($payload['from'] ?? $payload['sender'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $messageId = trim((string) (
            $payload['message_id']
            ?? $payload['message-id']
            ?? $payload['Message-Id']
            ?? $payload['Message-ID']
            ?? ''
        ));
        $textBody = self::truncateText((string) (
            $payload['stripped-text']
            ?? $payload['stripped_text']
            ?? $payload['body-plain']
            ?? $payload['body_plain']
            ?? $payload['text']
            ?? $payload['plain']
            ?? ''
        ), 65000);
        $htmlBody = self::truncateText((string) (
            $payload['stripped-html']
            ?? $payload['stripped_html']
            ?? $payload['body-html']
            ?? $payload['body_html']
            ?? $payload['html']
            ?? ''
        ), 655000);

        if ($textBody === '' && $htmlBody !== '') {
            $textBody = self::truncateText(strip_tags($htmlBody), 65000);
        }

        $source = trim((string) ($payload['provider'] ?? $payload['source'] ?? 'webhook'));
        if ($source === '') {
            $source = 'webhook';
        }
        $source = substr($source, 0, 32);

        try {
            $mailboxRows = Capsule::table('mod_cloudflare_temp_mailboxes')
                ->whereIn('email_address', $recipients)
                ->where('status', 'active')
                ->get();

            if (!$mailboxRows || count($mailboxRows) === 0) {
                return ['success' => false, 'error' => 'mailbox not found'];
            }

            $inserted = 0;
            foreach ($mailboxRows as $mailboxRow) {
                $mailboxId = (int) ($mailboxRow->id ?? 0);
                if ($mailboxId <= 0) {
                    continue;
                }

                if ($messageId !== '') {
                    $alreadyExists = Capsule::table('mod_cloudflare_temp_emails')
                        ->where('mailbox_id', $mailboxId)
                        ->where('message_id', $messageId)
                        ->exists();
                    if ($alreadyExists) {
                        continue;
                    }
                }

                $recipient = strtolower(trim((string) ($mailboxRow->email_address ?? '')));
                Capsule::table('mod_cloudflare_temp_emails')->insert([
                    'mailbox_id' => $mailboxId,
                    'userid' => (int) ($mailboxRow->userid ?? 0),
                    'message_id' => $messageId !== '' ? $messageId : null,
                    'sender' => self::truncateText($sender, 191),
                    'recipient' => self::truncateText($recipient, 191),
                    'subject' => self::truncateText($subject, 255),
                    'body_text' => $textBody,
                    'body_html' => $htmlBody,
                    'raw_headers' => self::truncateText((string) ($payload['message-headers'] ?? $payload['message_headers'] ?? $payload['headers'] ?? ''), 64000),
                    'source' => $source,
                    'received_at' => $now,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                Capsule::table('mod_cloudflare_temp_mailboxes')
                    ->where('id', $mailboxId)
                    ->update([
                        'last_received_at' => $now,
                        'updated_at' => $now,
                    ]);
                $inserted++;
            }

            return [
                'success' => true,
                'inserted' => $inserted,
                'matched_mailboxes' => count($mailboxRows),
                'expires_at' => $expiresAt,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'store failed'];
        }
    }

    public static function cleanupExpired(int $batchSize = 2000): int
    {
        $batchSize = max(50, min(10000, $batchSize));

        try {
            $rows = Capsule::table('mod_cloudflare_temp_emails')
                ->select('id')
                ->where('expires_at', '<=', date('Y-m-d H:i:s'))
                ->orderBy('id', 'asc')
                ->limit($batchSize)
                ->get();

            $ids = [];
            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            if (empty($ids)) {
                return 0;
            }

            return (int) Capsule::table('mod_cloudflare_temp_emails')
                ->whereIn('id', $ids)
                ->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function generateAlias(int $userId): string
    {
        $seed = bin2hex(random_bytes(4));
        return 'tmp-' . $userId . '-' . strtolower($seed);
    }

    private static function mapMailboxRow($row): array
    {
        return [
            'id' => (int) ($row->id ?? 0),
            'userid' => (int) ($row->userid ?? 0),
            'alias_local' => (string) ($row->alias_local ?? ''),
            'email_address' => (string) ($row->email_address ?? ''),
            'status' => (string) ($row->status ?? 'active'),
            'last_received_at' => (string) ($row->last_received_at ?? ''),
        ];
    }

    private static function mapEmailListRow($row): array
    {
        $previewSource = trim((string) ($row->body_text ?? ''));
        if ($previewSource === '') {
            $previewSource = strip_tags((string) ($row->body_html ?? ''));
        }

        return [
            'id' => (int) ($row->id ?? 0),
            'sender' => (string) ($row->sender ?? ''),
            'recipient' => (string) ($row->recipient ?? ''),
            'subject' => (string) ($row->subject ?? ''),
            'preview' => self::truncateText(trim($previewSource), 220),
            'received_at' => (string) ($row->received_at ?? ''),
            'expires_at' => (string) ($row->expires_at ?? ''),
            'has_html' => trim((string) ($row->body_html ?? '')) !== '',
        ];
    }

    private static function mapEmailDetailRow($row): array
    {
        return [
            'id' => (int) ($row->id ?? 0),
            'sender' => (string) ($row->sender ?? ''),
            'recipient' => (string) ($row->recipient ?? ''),
            'subject' => (string) ($row->subject ?? ''),
            'body_text' => (string) ($row->body_text ?? ''),
            'body_html' => (string) ($row->body_html ?? ''),
            'received_at' => (string) ($row->received_at ?? ''),
            'expires_at' => (string) ($row->expires_at ?? ''),
            'source' => (string) ($row->source ?? 'webhook'),
            'message_id' => (string) ($row->message_id ?? ''),
        ];
    }

    private static function extractRecipients(array $payload): array
    {
        $candidates = [];

        foreach (['recipient', 'to', 'delivered_to', 'delivered-to', 'envelope_to', 'envelope-to', 'X-Original-To', 'x-original-to', 'rcpt_to'] as $key) {
            if (isset($payload[$key])) {
                $candidates = array_merge($candidates, self::extractEmailsFromValue($payload[$key]));
            }
        }

        if (isset($payload['envelope'])) {
            $envelope = $payload['envelope'];
            if (is_string($envelope)) {
                $decoded = json_decode($envelope, true);
                if (is_array($decoded)) {
                    $envelope = $decoded;
                }
            }
            if (is_array($envelope) && isset($envelope['to'])) {
                $candidates = array_merge($candidates, self::extractEmailsFromValue($envelope['to']));
            }
        }

        if (isset($payload['headers']) && is_string($payload['headers'])) {
            if (preg_match('/^to\s*:\s*(.+)$/im', $payload['headers'], $matches)) {
                $candidates = array_merge($candidates, self::extractEmailsFromValue($matches[1]));
            }
        }

        $normalized = [];
        foreach ($candidates as $email) {
            $mail = strtolower(trim((string) $email));
            if ($mail === '' || strpos($mail, '@') === false) {
                continue;
            }
            $normalized[$mail] = $mail;
        }

        return array_values($normalized);
    }

    private static function extractEmailsFromValue($value): array
    {
        if (is_array($value)) {
            $emails = [];
            foreach ($value as $item) {
                $emails = array_merge($emails, self::extractEmailsFromValue($item));
            }
            return $emails;
        }

        if (!is_string($value)) {
            return [];
        }

        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,63}/i', $value, $matches);
        return $matches[0] ?? [];
    }

    private static function truncateText(string $text, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $maxLength) {
                return mb_substr($text, 0, $maxLength, 'UTF-8');
            }
            return $text;
        }

        if (strlen($text) > $maxLength) {
            return substr($text, 0, $maxLength);
        }

        return $text;
    }
}
