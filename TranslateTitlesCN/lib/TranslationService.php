<?php

class TranslationService {
    private const GOOGLE_ENDPOINT = 'https://translate.googleapis.com/translate_a/single';
    private const DEFAULT_OPENAI_MODEL = 'gpt-3.5-turbo';

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

    public function __construct($serviceType) {
        $this->serviceType = $serviceType;
        $this->deeplxBaseUrl = rtrim((string)(FreshRSS_Context::$user_conf->DeeplxApiUrl ?? ''), '/');
        $this->googleBaseUrl = self::GOOGLE_ENDPOINT;
        $this->libreBaseUrl = rtrim((string)(FreshRSS_Context::$user_conf->LibreApiUrl ?? ''), '/');
        $this->libreApiKey = (string)(FreshRSS_Context::$user_conf->LibreApiKey ?? '');
        $this->openAiBaseUrl = rtrim((string)(FreshRSS_Context::$user_conf->OpenAIBaseUrl ?? ''), '/');
        $this->openAiApiKey = (string)(FreshRSS_Context::$user_conf->OpenAIAPIKey ?? '');
        $this->openAiModel = (string)(FreshRSS_Context::$user_conf->OpenAIModel ?? self::DEFAULT_OPENAI_MODEL);
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

    private function translateWithLibre($text, $target, $source) {
        if ($this->libreBaseUrl === '') {
            error_log('LibreTranslate base URL is not configured.');
            return '';
        }

        $apiUrl = $this->libreBaseUrl . '/translate';

        $postData = [
            'q' => $text,
            'source' => ($source ?: 'auto'),
            'target' => $target,
            'format' => 'text',
        ];

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

        return $this->translateWithOpenAiCompatible(
            $text,
            $target,
            $source,
            $endpoint,
            $this->openAiApiKey,
            $this->openAiModel ?: self::DEFAULT_OPENAI_MODEL
        );
    }

    private function translateWithOpenAiCompatible($text, $target, $source, $endpoint, $apiKey, $model) {
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

        $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            error_log('OpenAI-compatible payload encoding failed.');
            return '';
        }

        $options = [
            'http' => [
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
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
        $sourceInstruction = '';
        if ($source !== 'auto' && $source !== '') {
            $sourceName = $this->describeLanguage($source);
            $sourceInstruction = ' from ' . $sourceName . ' (locale: ' . $source . ')';
        }

        return 'You are a translation engine. Translate the user messages' . $sourceInstruction .
            ' into ' . $targetName . ' (locale: ' . $target . '). Answer with the translated text only.';
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
