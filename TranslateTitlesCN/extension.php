<?php
require_once('lib/TranslateController.php');

class TranslateTitlesExtension extends Minz_Extension {
    // 默认DeepLX API 地址
    private const ApiUrl = 'http://localhost:1188/translate';
    // 请求内缓存：按签名缓存已生成的包裹内容，避免同一请求内重复翻译
    private $displayCache = array();

    public function init() {
        error_log('TranslateTitlesCN: Plugin initializing...');

        if (!extension_loaded('mbstring')) {
            error_log('TranslateTitlesCN 插件需要 PHP mbstring 扩展支持');
        }

        if (php_sapi_name() == 'cli') {
            // 确保 CLI 模式下有正确的用户上下文
            if (!FreshRSS_Context::$user_conf) {
                error_log('TranslateTitlesCN: No user context in CLI mode');
                // 可能需要手动初始化用户上下文
                $username = 'default'; // 或其他用户名
                FreshRSS_Context::$user_conf = new FreshRSS_UserConfiguration($username);
                FreshRSS_Context::$user_conf->load();
            }
        }

        $this->registerHook('feed_before_insert', array($this, 'addTranslationOption'));
        $this->registerHook('entry_before_insert', array($this, 'translateTitle'));
        // 在展示阶段处理内容翻译与包裹逻辑（只翻一次，变更自动失效）
        $this->registerHook('entry_before_display', array($this, 'onEntryBeforeDisplay'));

        if (is_null(FreshRSS_Context::$user_conf->TranslateService)) {
            FreshRSS_Context::$user_conf->TranslateService = 'google';
        }

        if (is_null(FreshRSS_Context::$user_conf->DeeplxApiUrl)) {
            FreshRSS_Context::$user_conf->DeeplxApiUrl = self::ApiUrl;
        }

        if (is_null(FreshRSS_Context::$user_conf->LibreApiUrl)) {
            FreshRSS_Context::$user_conf->LibreApiUrl = 'http://localhost:5000';
        }

        if (is_null(FreshRSS_Context::$user_conf->LibreApiKey)) {
            FreshRSS_Context::$user_conf->LibreApiKey = '';
        }

        // 新增默认配置项（不启用翻译内容，仅提供默认值）
        if (is_null(FreshRSS_Context::$user_conf->TargetLang)) {
            // 目标语言，默认中文
            FreshRSS_Context::$user_conf->TargetLang = 'zh-cn';
        }

        if (is_null(FreshRSS_Context::$user_conf->TranslateContentEnabled)) {
            // 是否启用内容翻译（默认关闭，0/false 表示不启用）
            FreshRSS_Context::$user_conf->TranslateContentEnabled = false;
        }

        if (is_null(FreshRSS_Context::$user_conf->TranslateTitleEnabled)) {
            // 是否启用标题翻译（默认开启，配合每个源的勾选）
            FreshRSS_Context::$user_conf->TranslateTitleEnabled = true;
        }

        if (is_null(FreshRSS_Context::$user_conf->ContentDisplayMode)) {
            // 内容展示模式：translated_only / orig_then_trans / trans_then_orig
            FreshRSS_Context::$user_conf->ContentDisplayMode = 'orig_then_trans';
        }

        if (is_null(FreshRSS_Context::$user_conf->OpenAIBaseUrl) || FreshRSS_Context::$user_conf->OpenAIBaseUrl === '') {
            FreshRSS_Context::$user_conf->OpenAIBaseUrl = 'https://api.openai.com/v1/';
        }

        if (is_null(FreshRSS_Context::$user_conf->OpenAIModel)) {
            FreshRSS_Context::$user_conf->OpenAIModel = 'gpt-3.5-turbo';
        }

        // 避免每次请求都保存配置，保留在配置动作中保存

        error_log('TranslateTitlesCN: Hooks registered');
        // error_log('TranslateTitlesCN: Current translation config: ' . json_encode(FreshRSS_Context::$user_conf->TranslateTitles));
    }

    public function handleConfigureAction() {
        // 处理配置请求（包含翻译测试与保存配置）
        if (Minz_Request::isPost()) {
            // 优先处理翻译测试：当 POST 中携带 test-text，则不保存配置，仅执行测试
            $testText = Minz_Request::param('test-text', '');
            if (!empty($testText)) {
                try {
                    $translateController = new TranslateController();
                    $testService = Minz_Request::param('TranslateService', FreshRSS_Context::$user_conf->TranslateService ?? 'google');
                    $testTarget = Minz_Request::param('language', FreshRSS_Context::$user_conf->TargetLang ?? 'zh-cn');
                    $translatedText = $translateController->translateTitle($testText, $testService, $testTarget);
                    error_log('[TT] TEST service=' . $testService . ' title=' . $testText . ' result=' . $translatedText);
                    if (!empty($translatedText)) {
                        Minz_Session::_param('notification', [
                            'type' => 'good',
                            'content' => '翻译结果：' . $translatedText,
                        ]);
                    } else {
                        Minz_Session::_param('notification', [
                            'type' => 'error',
                            'content' => '翻译失败，请检查翻译服务配置',
                        ]);
                    }
                } catch (Exception $e) {
                    Minz_Session::_param('notification', [
                        'type' => 'error',
                        'content' => $e->getMessage(),
                    ]);
                }
                // 测试分支结束，直接返回（不保存配置）。核心控制器会继续渲染配置页。
                return;
            }

            $allowedServices = ['google', 'deeplx', 'libre', 'openai'];
            $translateService = Minz_Request::param('TranslateService', FreshRSS_Context::$user_conf->TranslateService ?? 'google');
            if (!in_array($translateService, $allowedServices, true)) {
                $translateService = 'google';
            }
            FreshRSS_Context::$user_conf->TranslateService = $translateService;

            $translateTitles = Minz_Request::param('TranslateTitles', array());
            error_log("TranslateTitlesCN: Saving translation config: " . json_encode($translateTitles));

            // 确保配置是数组形式
            if (!is_array($translateTitles)) {
                $translateTitles = array();
            }

            // 保存配置
            FreshRSS_Context::$user_conf->TranslateTitles = $translateTitles;

            $deeplxApiUrl = Minz_Request::param('DeeplxApiUrl', self::ApiUrl);
            FreshRSS_Context::$user_conf->DeeplxApiUrl = $deeplxApiUrl;

            $libreApiUrl = Minz_Request::param('LibreApiUrl', 'http://localhost:5000');
            FreshRSS_Context::$user_conf->LibreApiUrl = $libreApiUrl;

            $libreApiKey = Minz_Request::param('LibreApiKey', '');
            FreshRSS_Context::$user_conf->LibreApiKey = $libreApiKey;

            // 正文翻译开关与展示模式
            $translationModeParam = Minz_Request::param('TranslationMode', null);
            if (is_string($translationModeParam) && $translationModeParam !== '') {
                switch ($translationModeParam) {
                    case 'content_only':
                        FreshRSS_Context::$user_conf->TranslateTitleEnabled = false;
                        FreshRSS_Context::$user_conf->TranslateContentEnabled = true;
                        break;
                    case 'title_and_content':
                        FreshRSS_Context::$user_conf->TranslateTitleEnabled = true;
                        FreshRSS_Context::$user_conf->TranslateContentEnabled = true;
                        break;
                    case 'title_only':
                    default:
                        FreshRSS_Context::$user_conf->TranslateTitleEnabled = true;
                        FreshRSS_Context::$user_conf->TranslateContentEnabled = false;
                        break;
                }
            } else {
                FreshRSS_Context::$user_conf->TranslateTitleEnabled = Minz_Request::hasParam('TranslateTitleEnabled');
                FreshRSS_Context::$user_conf->TranslateContentEnabled = Minz_Request::hasParam('TranslateContentEnabled');
            }

            $displayMode = Minz_Request::param('ContentDisplayMode', 'orig_then_trans');
            $allowedModes = ['translated_only', 'orig_then_trans', 'trans_then_orig'];
            FreshRSS_Context::$user_conf->ContentDisplayMode = in_array($displayMode, $allowedModes) ? $displayMode : 'orig_then_trans';

            // 目标语言
            $targetLang = Minz_Request::param('language', null);
            if ($targetLang === null) {
                $targetLang = Minz_Request::param('TargetLang', FreshRSS_Context::$user_conf->TargetLang ?? 'zh-cn');
            }
            if (!is_string($targetLang) || $targetLang === '') {
                $targetLang = 'zh-cn';
            }
            $targetLang = strtolower($targetLang);
            FreshRSS_Context::$user_conf->TargetLang = $targetLang;

            // 每个订阅源的原语言
            if (Minz_Request::hasParam('TranslateSourceLang')) {
                $sourceLangMap = Minz_Request::param('TranslateSourceLang', array());
                if (!is_array($sourceLangMap)) { $sourceLangMap = array(); }
                FreshRSS_Context::$user_conf->TranslateSourceLang = $sourceLangMap;
            }

            // OpenAI 兼容接口配置
            $openaiBase = trim((string)Minz_Request::param('OpenAIBaseUrl', FreshRSS_Context::$user_conf->OpenAIBaseUrl ?? ''));
            if ($openaiBase === '') {
                $openaiBase = 'https://api.openai.com/v1/';
            }
            $openaiKey = Minz_Request::param('OpenAIAPIKey', '');
            $openaiModel = Minz_Request::param('OpenAIModel', 'gpt-3.5-turbo');
            FreshRSS_Context::$user_conf->OpenAIBaseUrl = $openaiBase;
            FreshRSS_Context::$user_conf->OpenAIAPIKey = $openaiKey;
            FreshRSS_Context::$user_conf->OpenAIModel = $openaiModel;

            // 保存并记录结果
            $saveResult = FreshRSS_Context::$user_conf->save();
            error_log("TranslateTitlesCN: Config save result: " . ($saveResult ? 'success' : 'failed'));

            // 保存后立即验证配置
            error_log("TranslateTitlesCN: Saved config verification: " .
                json_encode(FreshRSS_Context::$user_conf->TranslateTitles));
        }
    }

    public function handleUninstallAction() {
        // 清除所有与插件相关的用户配置
        if (isset(FreshRSS_Context::$user_conf->TranslateService)) {
            unset(FreshRSS_Context::$user_conf->TranslateService);
        }
        if (isset(FreshRSS_Context::$user_conf->TranslateTitles)) {
            unset(FreshRSS_Context::$user_conf->TranslateTitles);
        }
        if (isset(FreshRSS_Context::$user_conf->DeeplxApiUrl)) {
            unset(FreshRSS_Context::$user_conf->DeeplxApiUrl);
        }
        if (isset(FreshRSS_Context::$user_conf->LibreApiUrl)) {
            unset(FreshRSS_Context::$user_conf->LibreApiUrl);
        }
        if (isset(FreshRSS_Context::$user_conf->LibreApiKey)) {
            unset(FreshRSS_Context::$user_conf->LibreApiKey);
        }
        FreshRSS_Context::$user_conf->save();
    }

    public function translateTitle($entry) {
        // CLI 模式下的特殊处理
        if (php_sapi_name() == 'cli') {
            if (!FreshRSS_Context::$user_conf) {
                // 获取所有用户列表
                $usernames = $this->listUsers();
                foreach ($usernames as $username) {
                    // 初始化用户配置
                    FreshRSS_Context::$user_conf = new FreshRSS_UserConfiguration($username);
                    FreshRSS_Context::$user_conf->load();
                    break; // 只处理第一个用户
                }
            }
        }
        // 暂停标题翻译（等待新算法），直接返回
        error_log('[TT] TITLE disabled');
        return $entry;
    }

    /**
     * Entry 展示前处理：按签名做“只翻一次，变更自动失效”，并包裹容器。
     */
    public function onEntryBeforeDisplay($entry) {
        // 暂停正文翻译与容器/签名逻辑（等待新算法）
        error_log('[TT] DISPLAY disabled');
        return $entry;
    }

    private function containsChinese($text) {
        return preg_match('/\p{Han}+/u', $text) === 1;
    }

    private function isBlankText($text) {
        return trim(preg_replace('/\s+/u', ' ', $text)) === '';
    }

    private function translateContentByParagraph($html, $targetLang, $serviceType, $sourceLang = 'auto') {
        $controller = new TranslateController();

        $paragraphs = [];
        $matches = [];
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $matches)) {
            $paragraphs = $matches[1];
        } else {
            // 退化：按空行分段（对纯文本或无 <p> 的内容）
            $text = strip_tags($html);
            $paragraphs = preg_split('/\R{2,}/u', $text);
        }

        $stats = [
            'total' => 0,
            'skip_cn' => 0,
            'skip_blank' => 0,
            'failed' => 0,
        ];

        $translatedParts = [];
        foreach ($paragraphs as $p) {
            $stats['total']++;
            $text = trim(strip_tags($p));
            if ($this->isBlankText($text)) {
                $stats['skip_blank']++;
                $translatedParts[] = $text; // 保持空白
                continue;
            }
            if ($this->containsChinese($text)) {
                $stats['skip_cn']++;
                $translatedParts[] = $text; // 中文段落直接保留
                continue;
            }

            // 调用翻译（单段）
            $translated = '';
            try {
                $translated = $controller->translateTitle($text, $serviceType, $targetLang, $sourceLang);
            } catch (Exception $e) {
                $translated = '';
            }
            if ($translated === '' || $translated === null) {
                $stats['failed']++;
                $translatedParts[] = $text; // 失败则保留原文
            } else {
                $translatedParts[] = $translated;
            }
        }

        // 重新组装为简单 <p> 段落
        $out = '';
        foreach ($translatedParts as $tp) {
            $out .= '<p>' . htmlspecialchars($tp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return [$out, $stats];
    }

    private function stripTtcnWrapper($html) {
        // 移除最外层 ttcn-wrap 容器，尽力保留内部可视内容（翻译/原文二选一）
        if (!$this->hasTtcnWrapper($html)) {
            return $html;
        }
        // 尝试提取 .ttcn-original 或 .ttcn-translated 可视块
        if (preg_match('/<div[^>]*class="[^"]*ttcn-original[^"]*"[^>]*>([\s\S]*?)<\/div>/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/<div[^>]*class="[^"]*ttcn-translated[^"]*"[^>]*>([\s\S]*?)<\/div>/i', $html, $m)) {
            return $m[1];
        }
        // 最差情况：直接去掉外层标签
        return preg_replace('/<div[^>]*class="[^"]*ttcn-wrap[^"]*"[^>]*>([\s\S]*?)<\/div>/i', '$1', $html, 1);
    }

    private function hasTtcnWrapper($html) {
        return preg_match('/<div[^>]*class="[^"]*ttcn-wrap[^"]*"/i', $html) === 1;
    }

    private function extractWrapperSign($html) {
        if (preg_match('/data-ttcn-sign="([^"]+)"/i', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractOriginalFromWrapper($html) {
        // 优先从注释中的 Base64 取原文
        if (preg_match('/<!--TT_ORIG_B64:([A-Za-z0-9+\/=]+)-->/i', $html, $m)) {
            $b64 = $m[1];
            $raw = base64_decode($b64, true);
            if ($raw !== false) {
                return $raw;
            }
        }
        // 其次尝试从 .ttcn-original 提取
        if (preg_match('/<div[^>]*class="[^"]*ttcn-original[^"]*"[^>]*>([\s\S]*?)<\/div>/i', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private function normalizeForSign($html) {
        // 去容器、去标签、压缩空白，得到稳定文本
        $plain = strip_tags($this->stripTtcnWrapper($html));
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', trim($plain));
        return $plain;
    }

    private function computeSignature($entryId, $originalHtml, $targetLang, $serviceType, $displayMode) {
        $base = $this->normalizeForSign($originalHtml);
        $ver = $this->getExtensionVersion();
        $data = implode('|', [
            $base,
            (string)$targetLang,
            (string)$serviceType,
            (string)$displayMode,
            (string)$entryId,
            (string)$ver,
        ]);
        return sha1($data);
    }

    private function buildWrapper($originalHtml, $translatedHtml, $sign, $displayMode) {
        $origB64 = base64_encode($originalHtml);
        $html = '';
        $html .= '<div class="ttcn-wrap" data-ttcn-sign="' . htmlspecialchars($sign, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        $html .= '<!--TT_ORIG_B64:' . $origB64 . '-->';
        if ($displayMode === 'translated_only') {
            $html .= '<div class="ttcn-translated">' . $translatedHtml . '</div>';
        } elseif ($displayMode === 'trans_then_orig') {
            $html .= '<div class="ttcn-translated">' . $translatedHtml . '</div>';
            $html .= '<div class="ttcn-original">' . $originalHtml . '</div>';
        } else { // orig_then_trans (default)
            $html .= '<div class="ttcn-original">' . $originalHtml . '</div>';
            $html .= '<div class="ttcn-translated">' . $translatedHtml . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function getExtensionVersion() {
        // 从 metadata.json 读取版本
        try {
            $metaPath = __DIR__ . '/metadata.json';
            // 当前文件在扩展根目录下，metadata.json 与之同级
            $metaPath = dirname(__FILE__) . '/metadata.json';
            if (!file_exists($metaPath)) {
                // extension.php 位于扩展根，metadata.json 同目录
                $metaPath = __DIR__ . '/metadata.json';
            }
            if (!file_exists($metaPath)) {
                // 再退一步：TranslateTitlesCN/metadata.json（相对工程根）
                $metaPath = dirname(__FILE__) . '/metadata.json';
            }
            if (file_exists($metaPath)) {
                $json = @file_get_contents($metaPath);
                if ($json !== false) {
                    $m = json_decode($json, true);
                    if (is_array($m) && isset($m['version'])) {
                        return (string)$m['version'];
                    }
                }
            }
        } catch (Exception $e) {
            // ignore
        }
        return '0';
    }

    private function logDisplay($data) {
        // 结构化日志：是否命中防重、签名对比、统计
        $prefix = '[TT] DISPLAY';
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log($prefix . ' ' . $payload);
    }

    // 添加一个辅助函数来获取用户列表
    private function listUsers() {
        $path = DATA_PATH . '/users';
        $users = array();
        if ($handle = opendir($path)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != ".." && is_dir($path . '/' . $entry)) {
                    $users[] = $entry;
                }
            }
            closedir($handle);
        }
        return $users;
    }

    public function addTranslationOption($feed) {
        $feed->TranslateTitles = '0';
        return $feed;
    }

    // 旧的 translate-test 动作已废弃，测试已合并到 handleConfigureAction()
}
