<?php

declare(strict_types=1);

class CfAiHelpSearchService
{
    private const PROVIDER_GEMINI = 'gemini';
    private const PROVIDER_OPENROUTER = 'openrouter';

    public static function isEnabled(array $settings): bool
    {
        if (!array_key_exists('enable_help_ai_search', $settings)) {
            return false;
        }

        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($settings['enable_help_ai_search']);
        }

        $raw = strtolower(trim((string) $settings['enable_help_ai_search']));
        return in_array($raw, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function getAssistantName(array $settings, string $fallback = 'AI 助手'): string
    {
        $name = trim((string) ($settings['help_ai_assistant_name'] ?? ''));
        if ($name === '') {
            return $fallback;
        }
        return $name;
    }

    public static function ask(int $requestUserId, string $query, array $history = [], array $settings = [], array $context = []): array
    {
        if (empty($settings) && function_exists('cf_get_module_settings_cached')) {
            $settings = cf_get_module_settings_cached();
            if (!is_array($settings)) {
                $settings = [];
            }
        }

        if (!self::isEnabled($settings)) {
            throw new \RuntimeException('AI 搜索功能暂未开启。');
        }

        $maxInputChars = self::resolveMaxInputChars($settings);
        $question = trim($query);
        if ($question === '') {
            throw new \RuntimeException('请输入问题后再进行 AI 查询。');
        }
        $question = self::truncateText($question, $maxInputChars);

        $conversation = self::normalizeHistory($history);
        $conversation[] = [
            'role' => 'user',
            'content' => $question,
        ];

        $provider = self::resolveProvider($settings);
        $systemPrompt = self::resolveSystemPrompt($settings);

        if ($provider === self::PROVIDER_OPENROUTER) {
            $result = self::requestOpenRouter($settings, $systemPrompt, $conversation, $context);
        } else {
            $result = self::requestGemini($settings, $systemPrompt, $conversation);
        }

        $answer = trim((string) ($result['answer'] ?? ''));
        if ($answer === '') {
            throw new \RuntimeException('AI 暂时没有返回可用内容，请稍后重试。');
        }

        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('help_ai_search', [
                'provider' => $provider,
                'model' => (string) ($result['model'] ?? ''),
                'input_length' => self::textLength($question),
                'output_length' => self::textLength($answer),
            ], $requestUserId, null);
        }

        return [
            'answer' => $answer,
            'provider' => $provider,
            'model' => (string) ($result['model'] ?? ''),
            'assistant_name' => self::getAssistantName($settings),
        ];
    }

    private static function requestGemini(array $settings, string $systemPrompt, array $conversation): array
    {
        $apiKey = trim((string) ($settings['help_ai_gemini_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('未配置 Gemini API Key。');
        }

        $model = trim((string) ($settings['help_ai_gemini_model'] ?? 'gemini-2.0-flash'));
        if ($model === '') {
            $model = 'gemini-2.0-flash';
        }

        $contents = [];
        foreach ($conversation as $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => (string) ($message['content'] ?? '')],
                ],
            ];
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.25,
                'maxOutputTokens' => 1024,
            ],
        ];
        if ($systemPrompt !== '') {
            $body['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ];
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        [$statusCode, $decoded, $rawBody] = self::requestJson(
            'POST',
            $url,
            ['Content-Type: application/json'],
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($statusCode >= 400) {
            $message = self::extractErrorMessage($decoded, $rawBody);
            if ($message === '') {
                $message = 'Gemini 请求失败（HTTP ' . $statusCode . '）';
            }
            throw new \RuntimeException($message);
        }

        $candidate = $decoded['candidates'][0]['content']['parts'] ?? [];
        $texts = [];
        if (is_array($candidate)) {
            foreach ($candidate as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $text = trim((string) ($part['text'] ?? ''));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return [
            'answer' => trim(implode("\n", $texts)),
            'model' => $model,
        ];
    }

    private static function requestOpenRouter(array $settings, string $systemPrompt, array $conversation, array $context = []): array
    {
        $apiKey = trim((string) ($settings['help_ai_openrouter_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('未配置 OpenRouter API Key。');
        }

        $model = trim((string) ($settings['help_ai_openrouter_model'] ?? 'meta-llama/llama-3.1-8b-instruct:free'));
        if ($model === '') {
            $model = 'meta-llama/llama-3.1-8b-instruct:free';
        }

        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }
        foreach ($conversation as $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $messages[] = [
                'role' => $role,
                'content' => (string) ($message['content'] ?? ''),
            ];
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.25,
            'max_tokens' => 1024,
        ];

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'X-Title: Domain Hub Help AI',
        ];

        $siteUrl = trim((string) ($context['site_url'] ?? ''));
        if ($siteUrl !== '') {
            $headers[] = 'HTTP-Referer: ' . $siteUrl;
        }

        [$statusCode, $decoded, $rawBody] = self::requestJson(
            'POST',
            'https://openrouter.ai/api/v1/chat/completions',
            $headers,
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($statusCode >= 400) {
            $message = self::extractErrorMessage($decoded, $rawBody);
            if ($message === '') {
                $message = 'OpenRouter 请求失败（HTTP ' . $statusCode . '）';
            }
            throw new \RuntimeException($message);
        }

        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $chunk) {
                if (is_array($chunk)) {
                    $text = trim((string) ($chunk['text'] ?? ''));
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }
            $content = implode("\n", $parts);
        }

        return [
            'answer' => trim((string) $content),
            'model' => $model,
        ];
    }

    private static function requestJson(string $method, string $url, array $headers, ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('当前环境缺少 cURL 扩展，无法调用 AI 接口。');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('初始化 AI 请求失败。');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'DomainHub-HelpAI/1.0',
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $rawBody = curl_exec($ch);
        $curlError = trim((string) curl_error($ch));
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($rawBody)) {
            $rawBody = '';
        }

        if ($curlError !== '') {
            throw new \RuntimeException('AI 网络请求失败：' . $curlError);
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [$statusCode, $decoded, $rawBody];
    }

    private static function resolveProvider(array $settings): string
    {
        $provider = strtolower(trim((string) ($settings['help_ai_provider'] ?? self::PROVIDER_GEMINI)));
        if (!in_array($provider, [self::PROVIDER_GEMINI, self::PROVIDER_OPENROUTER], true)) {
            $provider = self::PROVIDER_GEMINI;
        }
        return $provider;
    }

    private static function resolveSystemPrompt(array $settings): string
    {
        $configured = trim((string) ($settings['help_ai_system_prompt'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return implode("\n", [
            '你是 WHMCS 域名分发插件 domain_hub 的帮助中心 AI 助手。',
            '你的目标是帮助用户排查域名注册、续期、DNS 解析、API 密钥和安全限制相关问题。',
            '回答时优先给出明确步骤，尽量简洁。',
            '如果问题超出插件能力范围，请明确说明并建议用户提交工单。',
            '不要编造不存在的后台开关或接口。',
        ]);
    }

    private static function resolveMaxInputChars(array $settings): int
    {
        $value = (int) ($settings['help_ai_max_input_chars'] ?? 600);
        return max(200, min(2000, $value));
    }

    private static function normalizeHistory(array $history): array
    {
        $result = [];
        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $role = strtolower(trim((string) ($item['role'] ?? 'user')));
            if (!in_array($role, ['user', 'assistant', 'model'], true)) {
                $role = 'user';
            }
            if ($role === 'model') {
                $role = 'assistant';
            }
            $result[] = [
                'role' => $role,
                'content' => self::truncateText($content, 1000),
            ];
        }

        if (count($result) > 10) {
            $result = array_slice($result, -10);
        }

        return $result;
    }

    private static function extractErrorMessage(array $decoded, string $rawBody): string
    {
        $error = trim((string) ($decoded['error']['message'] ?? ''));
        if ($error !== '') {
            return $error;
        }

        $fallback = trim($rawBody);
        if ($fallback === '') {
            return '';
        }

        return self::truncateText($fallback, 200);
    }

    private static function truncateText(string $text, int $maxChars): string
    {
        if ($maxChars <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $maxChars) {
                return $text;
            }
            return mb_substr($text, 0, $maxChars, 'UTF-8');
        }

        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, $maxChars);
    }

    private static function textLength(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($text, 'UTF-8');
        }
        return strlen($text);
    }
}
