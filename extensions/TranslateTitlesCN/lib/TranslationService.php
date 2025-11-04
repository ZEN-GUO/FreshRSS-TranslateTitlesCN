<?php

class TranslationService {
    private const GOOGLE_ENDPOINT = 'https://translate.googleapis.com/translate_a/single';
    private const DEFAULT_OPENAI_MODEL = 'gpt-3.5-turbo';
    private const DEFAULT_OPENAI_PROMPT = 'You are a translation engine. Translate the user messages{{source_instruction}} into {{target_lang_name}} (locale: {{target_lang}}). Answer with the translated text only.';

    private static $languageNames = [
        'cs'    => 'Czech',
        'de'    => 'German',
        'el'    => 'Greek',
        'en'    => 'English',
        'en-us' => 'English (United States)',
        'es'    => 'Spanish',
        'fa'    => 'Persian',
        'fi'    => 'Finnish',
        'fr'    => 'French',
        'he'    => 'Hebrew',
        'hu'    => 'Hungarian',
        'id'    => 'Indonesian',
        'it'    => 'Italian',
        'ja'    => 'Japanese',
        'ko'    => 'Korean',
        'lv'    => 'Latvian',
        'nl'    => 'Dutch',
        'oc'    => 'Occitan',
        'pl'    => 'Polish',
        'pt-br' => 'Portuguese (Brazil)',
        'pt-pt' => 'Portuguese (Portugal)',
        'ru'    => 'Russian',
        'sk'    => 'Slovak',
        'tr'    => 'Turkish',
        'uk'    => 'Ukrainian',
        'zh-cn' => 'Simplified Chinese',
        'zh-tw' => 'Traditional Chinese',
    ];

    private $serviceType;
    private $deeplxBaseUrl;
    private $googleBaseUrl;
    private $libreBaseUrl;
    private $libreApiKey;
    private $openAiBaseUrl;
    private $openAiApiKey;
    private $openAiModel;
    private $openAiPrompt;

    public function __construct($serviceType, $overrides = null) {
        $this->serviceType = $serviceType;
        $this->deeplxBaseUrl = rtrim((string)(FreshRSS_Context::$user_conf->DeeplxApiUrl ?? ''), '/');
        $this->googleBaseUrl = self::GOOGLE_ENDPOINT;
        $this->libreBaseUrl = rtrim((string)(FreshRSS_Context::$user_conf->LibreApiUrl ?? ''), '/');
        $this->libreApiKey = (string)(FreshRSS_Context::$user_conf->LibreApiKey ?? '');
        $this->openAiBaseUrl = rtrim((string)(FreshRSS_Context::$user_conf->OpenAIBaseUrl ?? ''), '/');
        $this->openAiApiKey = (string)(FreshRSS_Context::$user_conf->OpenAIAPIKey ?? '');
        $this->openAiModel = (string)(FreshRSS_Context::$user_conf->OpenAIModel ?? self::DEFAULT_OPENAI_MODEL);
        $this->openAiPrompt = trim((string)(FreshRSS_Context::$user_conf->OpenAITranslationPrompt ?? ''));

        // Apply per-request overrides for testing without saving config
        if (is_array($overrides) && !empty($overrides)) {
            if (!empty($overrides['DeeplxApiUrl'])) {
                $this->deeplxBaseUrl = rtrim((string)$overrides['DeeplxApiUrl'], '/');
            }
            if (!empty($overrides['LibreApiUrl'])) {
                $this->libreBaseUrl = rtrim((string)$overrides['LibreApiUrl'], '/');
            }
            if (array_key_exists('LibreApiKey', $overrides)) {
                $this->libreApiKey = (string)$overrides['LibreApiKey'];
            }
            if (!empty($overrides['OpenAIBaseUrl'])) {
                $this->openAiBaseUrl = rtrim((string)$overrides['OpenAIBaseUrl'], '/');
            }
            if (array_key_exists('OpenAIAPIKey', $overrides)) {
                $this->openAiApiKey = (string)$overrides['OpenAIAPIKey'];
            }
            if (!empty($overrides['OpenAIModel'])) {
                $this->openAiModel = (string)$overrides['OpenAIModel'];
            }
            if (array_key_exists('OpenAITranslationPrompt', $overrides)) {
                $this->openAiPrompt = trim((string)$overrides['OpenAITranslationPrompt']);
            }
        }
    }

    public function translate($text, $target = 'zh-cn', $source = 'auto') {
        $text = (string)$text;
        if (trim($text) === '') {
            return '';
        }

        $target = $this->normalizeLanguageCode($target, false);
        $source = $this->normalizeLanguageCode($source, true);

        switch ($this->serviceType) {
            case 'deeplx':
                return $this->translateWithDeeplx($text, $target);
            case 'libre':
                return $this->translateWithLibre($text, $target, $source);
            case 'openai':
                return $this->translateWithOpenAi($text, $target, $source);
            default:
                return $this->translateWithGoogle($text, $target, $source);
        }
    }

    /**
     * 仅验证联通性/凭据：不以译文正确性为准
     * 返回 [ok=>bool, message=>string]
     */
    public function testConnectivity($target = 'en', $source = 'auto') {
        try {
            switch ($this->serviceType) {
                case 'libre':
                    return $this->testLibreConnectivity();
                case 'deeplx':
                    return $this->testDeeplxConnectivity();
                case 'openai':
                    return $this->testOpenAiConnectivity($target, $source);
                case 'google':
                default:
                    return $this->testGoogleConnectivity($target, $source);
            }
        } catch (Exception $e) {
            error_log('[TT][TEST] exception: ' . $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function parseHttpStatus($headers) {
        if (!is_array($headers) || empty($headers)) return null;
        $line = $headers[0];
        if (preg_match('/\s(\d{3})\s/', $line, $m)) {
            return intval($m[1]);
        }
        return null;
    }

    private function testGoogleConnectivity($target, $source) {
        $queryParams = http_build_query([
            'client' => 'gtx',
            'sl' => ($source ?: 'auto'),
            'tl' => $this->formatGoogleLanguage($target),
            'dt' => 't',
            'q' => 'ping',
        ]);
        $url = $this->googleBaseUrl . '?' . $queryParams;
        $opts = ['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]];
        $ctx = stream_context_create($opts);
        $result = @file_get_contents($url, false, $ctx);
        $status = $this->parseHttpStatus($http_response_header ?? []);
        error_log('[TT][TEST][google] status=' . $status);
        if ($status === 200 && $result !== false) {
            return ['ok' => true, 'message' => 'Google 接口连通'];
        }
        return ['ok' => false, 'message' => 'Google 接口不可用（HTTP ' . ($status ?? 0) . ')'];
    }

    private function testDeeplxConnectivity() {
        if ($this->deeplxBaseUrl === '') {
            return ['ok' => false, 'message' => 'DeeplX URL 未配置'];
        }
        $payload = ['text' => 'PING', 'source_lang' => 'auto', 'target_lang' => 'EN'];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $opts = ['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $json,
            'timeout' => 5,
            'ignore_errors' => true,
        ]];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($this->deeplxBaseUrl, false, $ctx);
        $status = $this->parseHttpStatus($http_response_header ?? []);
        error_log('[TT][TEST][deeplx] status=' . $status);
        if ($status === 200 && $res !== false) {
            return ['ok' => true, 'message' => 'DeeplX 接口连通'];
        }
        return ['ok' => false, 'message' => 'DeeplX 接口不可用（HTTP ' . ($status ?? 0) . ')'];
    }

    private function testLibreConnectivity() {
        if ($this->libreBaseUrl === '') {
            return ['ok' => false, 'message' => 'LibreTranslate URL 未配置'];
        }
        // 先 GET /languages
        $url = $this->libreBaseUrl . '/languages';
        $opts = ['http' => [
            'method' => 'GET',
            'header' => 'Content-Type: application/json',
            'timeout' => 5,
            'ignore_errors' => true,
        ]];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($url, false, $ctx);
        $status = $this->parseHttpStatus($http_response_header ?? []);
        error_log('[TT][TEST][libre] GET /languages status=' . $status);
        if ($status === 200 && $res !== false) {
            return ['ok' => true, 'message' => 'LibreTranslate 接口连通'];
        }
        // 若需要校验 key，则尝试一次最小翻译
        $apiUrl = $this->libreBaseUrl . '/translate';
        $post = ['q' => 'ping', 'source' => 'auto', 'target' => 'en', 'format' => 'text'];
        if ($this->libreApiKey !== '') { $post['api_key'] = $this->libreApiKey; }
        $json = json_encode($post, JSON_UNESCAPED_UNICODE);
        $opts = ['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $json,
            'timeout' => 8,
            'ignore_errors' => true,
        ]];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($apiUrl, false, $ctx);
        $status = $this->parseHttpStatus($http_response_header ?? []);
        error_log('[TT][TEST][libre] POST /translate status=' . $status);
        if ($status === 200 && $res !== false) {
            return ['ok' => true, 'message' => 'LibreTranslate 接口连通（含 Key 验证）'];
        }
        return ['ok' => false, 'message' => 'LibreTranslate 接口不可用（HTTP ' . ($status ?? 0) . ')'];
    }

    private function testOpenAiConnectivity($target, $source) {
        if ($this->openAiBaseUrl === '') {
            return ['ok' => false, 'message' => 'OpenAI Base URL 未配置'];
        }
        if ($this->openAiApiKey === '') {
            return ['ok' => false, 'message' => 'OpenAI API Key 未配置'];
        }
        $variant = $this->detectOpenAiVariant($this->openAiBaseUrl);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->openAiApiKey,
        ];
        if ($variant === 'openrouter') {
            $headers[] = 'HTTP-Referer: https://github.com/FreshRSS/FreshRSS';
            $headers[] = 'X-Title: TranslateTitlesCN';
        }
        // 尝试 GET /models
        $url = rtrim($this->openAiBaseUrl, '/') . '/models';
        $opts = ['http' => [
            'method' => 'GET',
            'header' => $headers,
            'timeout' => 8,
            'ignore_errors' => true,
        ]];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($url, false, $ctx);
        $status = $this->parseHttpStatus($http_response_header ?? []);
        error_log('[TT][TEST][openai] GET /models status=' . $status . ' variant=' . $variant);
        if ($status === 200 && $res !== false) {
            return ['ok' => true, 'message' => 'OpenAI 兼容接口连通（/models）'];
        }
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'message' => 'OpenAI 认证失败（HTTP ' . $status . ')'];
        }
        // Fallback: POST /chat/completions（校验 URL/Key；若模型错误也视为连通）
        $endpoint = rtrim($this->openAiBaseUrl, '/') . '/chat/completions';
        $payload = [
            'model' => ($this->openAiModel ?: self::DEFAULT_OPENAI_MODEL),
            'messages' => [ ['role' => 'user', 'content' => 'PING'] ],
            'max_tokens' => 1,
            'temperature' => 0,
        ];
        if ($variant === 'qwen') {
            $payload['extra_body'] = [ 'translation_options' => [ 'source_lang' => 'auto', 'target_lang' => 'en' ] ];
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $opts = ['http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => $json,
            'timeout' => 10,
            'ignore_errors' => true,
        ]];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($endpoint, false, $ctx);
        $status = $this->parseHttpStatus($http_response_header ?? []);
        error_log('[TT][TEST][openai] POST /chat/completions status=' . $status);
        if ($status === 200 && $res !== false) {
            return ['ok' => true, 'message' => 'OpenAI 兼容接口连通（/chat/completions）'];
        }
        // 解析错误体，若为模型错误则视为连通
        if ($res !== false) {
            $resp = json_decode($res, true);
            $errMsg = '';
            if (is_array($resp)) {
                if (!empty($resp['error']['message'])) { $errMsg = $resp['error']['message']; }
                if (!empty($resp['error']['type'])) { $errMsg .= ' [' . $resp['error']['type'] . ']'; }
                if (stripos($errMsg, 'model') !== false) {
                    return ['ok' => true, 'message' => 'OpenAI 连接正常，但模型无效：' . $errMsg];
                }
            }
            if ($status === 401 || $status === 403) {
                return ['ok' => false, 'message' => 'OpenAI 认证失败（HTTP ' . $status . '）'];
            }
            return ['ok' => false, 'message' => 'OpenAI 接口不可用（HTTP ' . ($status ?? 0) . '）' . ($errMsg ? '：' . $errMsg : '')];
        }
        return ['ok' => false, 'message' => 'OpenAI 接口不可用（HTTP ' . ($status ?? 0) . ')'];
    }

    private function translateWithLibre($text, $target, $source) {
        if ($this->libreBaseUrl === '') {
            error_log('LibreTranslate base URL is not configured.');
            return '';
        }

        $apiUrl = $this->libreBaseUrl . '/translate';

        $sourceCode = ($source === 'auto') ? 'auto' : $this->mapLibreLanguageCode($source);
        $targetCode = $this->mapLibreLanguageCode($target);

        $postData = [
            'q' => $text,
            'source' => $sourceCode,
            'target' => $targetCode,
            'format' => 'text',
        ];

        error_log('LibreTranslate request url=' . $apiUrl . ' source=' . $sourceCode . ' target=' . $targetCode);

        if ($this->libreApiKey !== '') {
            $postData['api_key'] = $this->libreApiKey;
        }

        $jsonData = json_encode($postData, JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            error_log('LibreTranslate payload encoding failed.');
            return '';
        }

        $options = [
            'http' => [
                'header' => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($jsonData),
                ],
                'method' => 'POST',
                'content' => $jsonData,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        $context = stream_context_create($options);

        try {
            $result = @file_get_contents($apiUrl, false, $context);
            $responseHeaders = $http_response_header ?? [];
            error_log('LibreTranslate Response Status: ' . ($responseHeaders[0] ?? 'n/a'));

            if ($result === false) {
                error_log('LibreTranslate API request failed.');
                return '';
            }

            $response = json_decode($result, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('LibreTranslate JSON decode error: ' . json_last_error_msg());
                return '';
            }

            if (!empty($response['translatedText'])) {
                return mb_convert_encoding($response['translatedText'], 'UTF-8', 'UTF-8');
            }

            if (!empty($response['error'])) {
                error_log('LibreTranslate API error: ' . $response['error']);
            } else {
                error_log('LibreTranslate unexpected response: ' . print_r($response, true));
            }
        } catch (Exception $e) {
            error_log('LibreTranslate exception: ' . $e->getMessage());
        }

        return '';
    }

    private function translateWithGoogle($text, $target, $source) {
        $translatedText = '';

        $queryParams = http_build_query([
            'client' => 'gtx',
            'sl' => ($source ?: 'auto'),
            'tl' => $this->formatGoogleLanguage($target),
            'dt' => 't',
            'q' => $text,
        ]);

        $url = $this->googleBaseUrl . '?' . $queryParams;

        $options = [
            'http' => [
                'method' => 'GET',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($options);

        try {
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                throw new Exception('Failed to get content from Google Translate API.');
            }

            $response = json_decode($result, true);
            if (!empty($response[0][0][0])) {
                $translatedText = $response[0][0][0];
            } else {
                throw new Exception('Google Translate API returned an empty translation.');
            }
        } catch (Exception $e) {
            error_log('Google Translate error: ' . $e->getMessage());
        }

        return $translatedText;
    }

    private function translateWithDeeplx($text, $target) {
        if ($this->deeplxBaseUrl === '') {
            error_log('DeeplX base URL is not configured.');
            return '';
        }

        $payload = [
            'text' => $text,
            'source_lang' => 'auto',
            'target_lang' => $this->formatDeeplxLanguage($target),
        ];

        $postData = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($postData === false) {
            error_log('DeeplX payload encoding failed.');
            return '';
        }

        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\n",
                'method' => 'POST',
                'content' => $postData,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($options);

        try {
            $result = @file_get_contents($this->deeplxBaseUrl, false, $context);
            if ($result === false) {
                throw new Exception('Failed to get content from DeeplX API.');
            }

            $response = json_decode($result, true);
            if (!empty($response['data'])) {
                return $response['data'];
            }

            throw new Exception('DeeplX API returned an empty translation. Response: ' . json_encode($response));
        } catch (Exception $e) {
            error_log('DeeplX error: ' . $e->getMessage());
        }

        return '';
    }

    private function translateWithOpenAi($text, $target, $source) {
        if ($this->openAiBaseUrl === '' || $this->openAiApiKey === '') {
            error_log('OpenAI compatible service not configured.');
            return '';
        }

        $endpoint = $this->openAiBaseUrl . '/chat/completions';
        $variant = $this->detectOpenAiVariant($this->openAiBaseUrl);
        error_log('OpenAI-compatible request endpoint=' . $endpoint . ' variant=' . $variant . ' model=' . ($this->openAiModel ?: self::DEFAULT_OPENAI_MODEL));

        return $this->translateWithOpenAiCompatible(
            $text,
            $target,
            $source,
            $endpoint,
            $this->openAiApiKey,
            $this->openAiModel ?: self::DEFAULT_OPENAI_MODEL,
            $variant
        );
    }

    private function translateWithOpenAiCompatible($text, $target, $source, $endpoint, $apiKey, $model, $variant = 'generic') {
        $payload = [
            'model' => $model,
            'temperature' => 0,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->buildSystemPrompt($target, $source),
                ],
                [
                    'role' => 'user',
                    'content' => $text,
                ],
            ],
        ];

        if ($variant === 'qwen') {
            $payload['extra_body'] = [
                'translation_options' => [
                    'source_lang' => $source === 'auto' ? 'auto' : $source,
                    'target_lang' => $target,
                ],
            ];
        }

        $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            error_log('OpenAI-compatible payload encoding failed.');
            return '';
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];

        if ($variant === 'openrouter') {
            $headers[] = 'HTTP-Referer: https://github.com/FreshRSS/FreshRSS';
            $headers[] = 'X-Title: TranslateTitlesCN';
        }

        $options = [
            'http' => [
                'header' => $headers,
                'method' => 'POST',
                'content' => $jsonData,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($options);

        try {
            $result = @file_get_contents($endpoint, false, $context);
            if ($result === false) {
                throw new Exception('OpenAI-compatible request failed.');
            }

            $response = json_decode($result, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('OpenAI-compatible JSON decode error: ' . json_last_error_msg());
            }

            if (!empty($response['choices'][0]['message']['content'])) {
                return trim($response['choices'][0]['message']['content']);
            }

            if (!empty($response['choices'][0]['text'])) {
                return trim($response['choices'][0]['text']);
            }

            if (!empty($response['error']['message'])) {
                throw new Exception('OpenAI-compatible service error: ' . $response['error']['message']);
            }

            throw new Exception('OpenAI-compatible unexpected response: ' . json_encode($response));
        } catch (Exception $e) {
            error_log($e->getMessage());
        }

        return '';
    }

    private function detectOpenAiVariant($baseUrl) {
        $host = $this->extractHostFromUrl($baseUrl);
        if ($host === null) {
            return 'generic';
        }

        $host = strtolower($host);
        if (strpos($host, 'openrouter.ai') !== false) {
            return 'openrouter';
        }
        if (strpos($host, 'aliyuncs.com') !== false || strpos($host, 'dashscope') !== false) {
            return 'qwen';
        }
        if (strpos($host, 'siliconflow.cn') !== false) {
            return 'siliconflow';
        }
        if (strpos($host, 'openai.com') !== false) {
            return 'openai';
        }

        return 'generic';
    }

    private function extractHostFromUrl($url) {
        $parsed = @parse_url($url, PHP_URL_HOST);
        if (!empty($parsed)) {
            return $parsed;
        }

        // 兼容缺少 scheme 的配置
        $withScheme = @parse_url('https://' . ltrim((string)$url, '/'), PHP_URL_HOST);
        return $withScheme ?: null;
    }

    private function mapLibreLanguageCode($code) {
        $code = strtolower(str_replace('_', '-', trim((string)$code)));
        if ($code === '' || $code === 'auto') {
            return 'auto';
        }

        $map = [
            'zh-cn' => 'zh',
            'zh-tw' => 'zh',
            'en-us' => 'en',
            'pt-br' => 'pt',
            'pt-pt' => 'pt',
        ];

        if (isset($map[$code])) {
            return $map[$code];
        }

        if (strpos($code, '-') !== false) {
            [$primary] = explode('-', $code, 2);
            return $primary;
        }

        return $code;
    }

    private function normalizeLanguageCode($code, $allowAuto) {
        $code = strtolower(str_replace('_', '-', trim((string)$code)));
        if ($allowAuto && ($code === '' || $code === 'auto')) {
            return 'auto';
        }
        if ($code === '' || $code === 'auto') {
            return 'zh-cn';
        }
        return $code;
    }

    private function formatGoogleLanguage($code) {
        $code = strtolower($code);
        $code = str_replace('_', '-', $code);
        if (strpos($code, '-') !== false) {
            [$primary, $variant] = explode('-', $code, 2);
            return $primary . '-' . strtoupper($variant);
        }
        return $code;
    }

    private function formatDeeplxLanguage($code) {
        return strtoupper(str_replace('-', '_', $code));
    }

    private function buildSystemPrompt($target, $source) {
        $targetName = $this->describeLanguage($target);
        $sourceName = $this->describeLanguage($source);
        $sourceInstruction = '';
        if ($source !== 'auto' && $source !== '') {
            $sourceInstruction = ' from ' . $sourceName . ' (locale: ' . $source . ')';
        }

        $template = ($this->openAiPrompt !== '') ? $this->openAiPrompt : self::DEFAULT_OPENAI_PROMPT;

        $replacements = [
            '{{target_lang}}' => $target,
            '{{target_lang_name}}' => $targetName,
            '{{source_lang}}' => $source,
            '{{source_lang_name}}' => $sourceName,
            '{{source_instruction}}' => $sourceInstruction,
        ];

        return strtr($template, $replacements);
    }

    private function describeLanguage($code) {
        $key = strtolower($code);
        if (isset(self::$languageNames[$key])) {
            return self::$languageNames[$key];
        }
        if ($key === 'auto') {
            return 'auto-detected language';
        }
        return strtoupper($code);
    }
}
