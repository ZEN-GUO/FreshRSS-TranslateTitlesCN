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
        // 仅在新增/更新稳定阶段触发翻译，不在 insert（过滤中）阶段
        $this->registerHook('entry_before_add', array($this, 'onEntryBeforeAdd'));
        // 更新条目的 Hook
        $this->registerHook('entry_before_update', array($this, 'onEntryBeforeUpdate'));

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

        if (is_null(FreshRSS_Context::$user_conf->OpenAITranslationPrompt)) {
            FreshRSS_Context::$user_conf->OpenAITranslationPrompt = '';
        }

        // 避免每次请求都保存配置，保留在配置动作中保存

        error_log('TranslateTitlesCN: Hooks registered');
        // error_log('TranslateTitlesCN: Current translation config: ' . json_encode(FreshRSS_Context::$user_conf->TranslateTitles));
    }

    public function handleConfigureAction() {
        // 处理配置请求（保存用户自定义的翻译设置）
        if (Minz_Request::isPost()) {
            // 测试按钮：仅测试连接，不保存配置
            if (Minz_Request::hasParam('DoTranslateTest')) {
                $testService = Minz_Request::param('TranslateService', FreshRSS_Context::$user_conf->TranslateService ?? 'google');
                $testTarget = Minz_Request::param('language', FreshRSS_Context::$user_conf->TargetLang ?? 'zh-cn');
                // 汇总覆盖参数（使用表单中当前值进行测试）
                $overrides = [
                    'DeeplxApiUrl' => Minz_Request::param('DeeplxApiUrl', FreshRSS_Context::$user_conf->DeeplxApiUrl ?? ''),
                    'LibreApiUrl' => Minz_Request::param('LibreApiUrl', FreshRSS_Context::$user_conf->LibreApiUrl ?? ''),
                    'LibreApiKey' => Minz_Request::param('LibreApiKey', FreshRSS_Context::$user_conf->LibreApiKey ?? ''),
                    'OpenAIBaseUrl' => Minz_Request::param('OpenAIBaseUrl', FreshRSS_Context::$user_conf->OpenAIBaseUrl ?? ''),
                    'OpenAIAPIKey' => Minz_Request::param('OpenAIAPIKey', FreshRSS_Context::$user_conf->OpenAIAPIKey ?? ''),
                    'OpenAIModel' => Minz_Request::param('OpenAIModel', FreshRSS_Context::$user_conf->OpenAIModel ?? 'gpt-3.5-turbo'),
                    'OpenAITranslationPrompt' => Minz_Request::param('OpenAITranslationPrompt', FreshRSS_Context::$user_conf->OpenAITranslationPrompt ?? ''),
                ];
                try {
                    error_log('[TT][TEST] Start connectivity service=' . $testService . ' target=' . $testTarget);
                    $svc = new TranslationService($testService, $overrides);
                    $probe = $svc->testConnectivity($testTarget, 'auto');
                    $ok = is_array($probe) && !empty($probe['ok']);
                    $msg = is_array($probe) && isset($probe['message']) ? (string)$probe['message'] : '';
                    if ($ok) {
                        Minz_Session::_param('notification', [
                            'type' => 'good',
                            'content' => '连接正常：' . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        ]);
                        error_log('[TT][TEST] OK ' . $msg);
                    } else {
                        Minz_Session::_param('notification', [
                            'type' => 'error',
                            'content' => '连接失败：' . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        ]);
                        error_log('[TT][TEST] FAIL ' . $msg);
                    }
                } catch (Exception $e) {
                    Minz_Session::_param('notification', [
                        'type' => 'error',
                        'content' => '测试异常：' . $e->getMessage(),
                    ]);
                    error_log('[TT][TEST] EXCEPTION ' . $e->getMessage());
                }
                return; // 不保存配置，直接返回
            }

            if (Minz_Request::hasParam('DoForceUpdate')) {
                $limit = (int)Minz_Request::param('ForceUpdateCount', 10);
                if ($limit <= 0) { $limit = 10; }
                try {
                    $summary = $this->forceUpdateSelectedFeeds($limit);
                    $ok = !empty($summary['ok']);
                    $msg = $summary['message'] ?? '';
                    Minz_Session::_param('notification', [
                        'type' => $ok ? 'good' : 'error',
                        'content' => ($ok ? '强制更新完成：' : '强制更新失败：') . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    ]);
                    error_log('[TT][FORCE] ' . ($ok ? 'OK ' : 'FAIL ') . $msg);
                } catch (Exception $e) {
                    Minz_Session::_param('notification', [
                        'type' => 'error',
                        'content' => '强制更新异常：' . $e->getMessage(),
                    ]);
                    error_log('[TT][FORCE] EXCEPTION ' . $e->getMessage());
                }
                return; // 不保存配置，直接返回
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
            $openaiBase = rtrim($openaiBase, '/');
            $openaiKey = trim((string)Minz_Request::param('OpenAIAPIKey', ''));
            $openaiModel = trim((string)Minz_Request::param('OpenAIModel', FreshRSS_Context::$user_conf->OpenAIModel ?? 'gpt-3.5-turbo'));
            if ($openaiModel === '') {
                $openaiModel = 'gpt-3.5-turbo';
            }
            $openaiPrompt = trim((string)Minz_Request::param('OpenAITranslationPrompt', FreshRSS_Context::$user_conf->OpenAITranslationPrompt ?? ''));

            FreshRSS_Context::$user_conf->OpenAIBaseUrl = $openaiBase;
            FreshRSS_Context::$user_conf->OpenAIAPIKey = $openaiKey;
            FreshRSS_Context::$user_conf->OpenAIModel = $openaiModel;
            FreshRSS_Context::$user_conf->OpenAITranslationPrompt = $openaiPrompt;

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

    public function onEntryBeforeAdd($entry) {
        error_log('[TT][HOOK] onEntryBeforeAdd');
        return $this->processEntryForTranslation($entry, 'add', false);
    }

    public function onEntryBeforeUpdate($entry) {
        error_log('[TT][HOOK] onEntryBeforeUpdate');
        return $this->processEntryForTranslation($entry, 'update', false);
    }

    private function processEntryForTranslation($entry, $context = 'add', $force = false) {
        if (!is_object($entry)) {
            return $entry;
        }

        if (php_sapi_name() === 'cli' && !FreshRSS_Context::$user_conf) {
            $usernames = $this->listUsers();
            foreach ($usernames as $username) {
                FreshRSS_Context::$user_conf = new FreshRSS_UserConfiguration($username);
                FreshRSS_Context::$user_conf->load();
                break;
            }
        }

        $feedId = $this->getFeedIdFromEntry($entry);
        $entryKey = $this->makeEntrySignatureKey($entry);
        error_log('[TT][FLOW][' . $context . '] entryKey=' . $entryKey . ' feedId=' . var_export($feedId, true));
        if (!$this->isFeedTranslationEnabled($feedId)) {
            error_log('[TT][FLOW][' . $context . '] feed disabled for translation');
            return $entry;
        }

        $userConf = FreshRSS_Context::$user_conf;
        $serviceType = $userConf->TranslateService ?? 'google';
        $targetLang = strtolower((string)($userConf->TargetLang ?? 'zh-cn'));
        $sourceLangMap = [];
        if (isset($userConf->TranslateSourceLang) && is_array($userConf->TranslateSourceLang)) {
            $sourceLangMap = $userConf->TranslateSourceLang;
        }
        $sourceLang = isset($sourceLangMap[$feedId]) && $sourceLangMap[$feedId] !== '' ? $sourceLangMap[$feedId] : 'auto';

        $translateTitleEnabled = (bool)($userConf->TranslateTitleEnabled ?? true);
        $translateContentEnabled = (bool)($userConf->TranslateContentEnabled ?? false);
        if (!$translateTitleEnabled && !$translateContentEnabled) {
            error_log('[TT][FLOW][' . $context . '] both title/content translation disabled by user');
            return $entry;
        }

        $displayMode = $userConf->ContentDisplayMode ?? 'orig_then_trans';
        error_log('[TT][CONF] svc=' . $serviceType . ' target=' . $targetLang . ' source=' . $sourceLang . ' title=' . ($translateTitleEnabled ? '1' : '0') . ' content=' . ($translateContentEnabled ? '1' : '0') . ' mode=' . $displayMode);
        $controller = new TranslateController();

        $originalTitleCurrent = method_exists($entry, 'title') ? (string)$entry->title() : '';
        $originalTitleSource = $originalTitleCurrent;
        if (method_exists($entry, 'attributeString')) {
            $storedTitle = $entry->attributeString('ttcn_original_title');
            if ($storedTitle !== null && $storedTitle !== '') {
                $originalTitleSource = $storedTitle;
            } elseif (method_exists($entry, '_attribute')) {
                $entry->_attribute('ttcn_original_title', $originalTitleCurrent);
            }
        }

        if ($translateTitleEnabled && method_exists($entry, 'title')) {
            // 标题在 update 时的幂等保护：上游未变更则复用历史译文，避免重复请求
            if ($context === 'update' && !$force) {
                try { $prev = $this->loadStoredEntryForCompare($entry, $feedId); } catch (Throwable $e) { $prev = null; }
                if ($prev) {
                    $prevOrig = null;
                    if (method_exists($prev, 'attributeString')) {
                        $prevOrig = $prev->attributeString('ttcn_original_title');
                    }
                    if (!empty($prevOrig)) {
                        $normPrev = $this->normalizeForSign((string)$prevOrig);
                        $normCurr = $this->normalizeForSign((string)$originalTitleSource);
                        if ($normPrev === $normCurr) {
                            $prevTrans = method_exists($prev, 'attributeString') ? $prev->attributeString('ttcn_translated_title') : '';
                            if (!empty($prevTrans)) {
                                $rebuiltTitle = $this->buildTitleByDisplayMode($prevOrig, $prevTrans, $displayMode);
                                if (method_exists($entry, '_title')) { $entry->_title($rebuiltTitle); } else { $entry->title = $rebuiltTitle; }
                                error_log('[TT][TITLE][' . $context . '] unchanged upstream: reused previous translation');
                                // 确保属性仍在
                                if (method_exists($entry, '_attribute')) {
                                    $entry->_attribute('ttcn_original_title', $prevOrig);
                                    $entry->_attribute('ttcn_translated_title', $prevTrans);
                                }
                                // 跳过翻译调用
                                goto SKIP_TITLE_TRANSLATION;
                            } else {
                                // 无已存译文则复用上次最终显示标题
                                $prevDisplay = method_exists($prev, 'title') ? (string)$prev->title() : '';
                                if ($prevDisplay !== '') {
                                    if (method_exists($entry, '_title')) { $entry->_title($prevDisplay); } else { $entry->title = $prevDisplay; }
                                    error_log('[TT][TITLE][' . $context . '] unchanged upstream: reused previous display title');
                                    goto SKIP_TITLE_TRANSLATION;
                                }
                            }
                        }
                    }
                }
            }

            if (!$force && $this->shouldSkipTextForTarget($originalTitleSource, $targetLang)) {
                error_log('[TT][TITLE][' . $context . '] skip by target-language heuristic');
            } else {
                try {
                    $translatedTitle = $controller->translateTitle($originalTitleSource, $serviceType, $targetLang, $sourceLang);
                } catch (Exception $e) {
                    $translatedTitle = '';
                    error_log('[TT][TITLE][' . $context . '] exception: ' . $e->getMessage());
                }
                if ($translatedTitle !== '' && $translatedTitle !== $originalTitleSource) {
                    $newTitle = $this->buildTitleByDisplayMode($originalTitleSource, $translatedTitle, $displayMode);
                    if ($newTitle !== null) {
                        if (method_exists($entry, '_title')) {
                            $entry->_title($newTitle);
                        } else {
                            $entry->title = $newTitle;
                        }
                        // 存储原题与译题，便于后续重载复用
                        if (method_exists($entry, '_attribute')) {
                            $entry->_attribute('ttcn_original_title', $originalTitleSource);
                            $entry->_attribute('ttcn_translated_title', $translatedTitle);
                        }
                        error_log('[TT][TITLE][' . $context . '] feed=' . $feedId . ' mode=' . $displayMode);
                    }
                }
            }
            SKIP_TITLE_TRANSLATION:;
        }

        if ($translateContentEnabled && method_exists($entry, 'content')) {
            $currentContent = (string)$entry->content();
            $existingSign = $this->extractWrapperSign($currentContent);
            $contentForTranslate = $this->prepareContentForTranslation($currentContent);
            $plainForCheck = strip_tags($contentForTranslate);
            error_log('[TT][CONTENT][' . $context . '] existingSign=' . var_export($existingSign, true) . ' lenRaw=' . strlen((string)$currentContent) . ' lenPrepared=' . strlen((string)$contentForTranslate));

            if ($this->isBlankText($plainForCheck) || $this->shouldSkipTextForTarget($plainForCheck, $targetLang)) {
                error_log('[TT][CONTENT][' . $context . '] skip: blank or already target language');
                return $entry;
            }

            // Idempotent reload handling: if upstream content is unchanged, avoid re-translation.
            if ($context === 'update' && !$force) {
                try {
                    $prev = $this->loadStoredEntryForCompare($entry, $feedId);
                } catch (Throwable $e) { $prev = null; }
                if ($prev && method_exists($prev, 'content')) {
                    $prevRaw = (string)$prev->content(false);
                    $prevPrepared = $this->prepareContentForTranslation($prevRaw);
                    $oldBase = $this->normalizeForSign($prevPrepared);
                    $newBase = $this->normalizeForSign($contentForTranslate);
                    if ($oldBase === $newBase) {
                        if ($this->hasTtcnWrapper($prevRaw)) {
                            $origPrev = $this->extractOriginalFromWrapper($prevRaw);
                            $transPrev = $this->extractTranslatedFromWrapper($prevRaw);
                            if ($origPrev !== null && $transPrev !== null) {
                                $rebuiltSign = $this->computeSignature($entryKey, $contentForTranslate, $targetLang, $serviceType, $displayMode);
                                $rebuilt = $this->buildWrapper($contentForTranslate, $transPrev, $rebuiltSign, $displayMode);
                                if (method_exists($entry, '_content')) { $entry->_content($rebuilt); } else { $entry->content = $rebuilt; }
                                error_log('[TT][CONTENT][' . $context . '] unchanged upstream: reused previous translation (rebuilt wrapper)');
                                return $entry;
                            } else {
                                if (method_exists($entry, '_content')) { $entry->_content($prevRaw); } else { $entry->content = $prevRaw; }
                                error_log('[TT][CONTENT][' . $context . '] unchanged upstream: reused previous wrapper (raw)');
                                return $entry;
                            }
                        } else {
                            error_log('[TT][CONTENT][' . $context . '] unchanged upstream: skip translation (no previous wrapper)');
                            return $entry;
                        }
                    }
                }
            }

            $computedSign = $this->computeSignature($entryKey, $contentForTranslate, $targetLang, $serviceType, $displayMode);
            if (!$force && !empty($existingSign) && $existingSign === $computedSign) {
                error_log('[TT][CONTENT][' . $context . '] skip: signature match');
                return $entry;
            }

            try {
                [$translatedHtml, $stats] = $this->translateContentByParagraph($contentForTranslate, $targetLang, $serviceType, $sourceLang);
            } catch (Exception $e) {
                error_log('[TT][CONTENT][' . $context . '] exception: ' . $e->getMessage());
                return $entry;
            }

            if (!empty($translatedHtml)) {
                $wrapped = $this->buildWrapper($contentForTranslate, $translatedHtml, $computedSign, $displayMode);
                error_log('[TT][CONTENT][' . $context . '] wrappedSnippet=' . mb_substr(strip_tags($wrapped), 0, 160, 'UTF-8'));
                if (method_exists($entry, '_content')) {
                    $entry->_content($wrapped);
                } else {
                    $entry->content = $wrapped;
                }
                error_log('[TT][CONTENT][' . $context . '] feed=' . $feedId . ' sign=' . $computedSign . ' stats=' . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

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
        $pattern = '/<(?P<tag>p|li|blockquote|pre|h[1-6])(?P<attr>[^>]*)>(?P<body>.*?)<\/(?P=tag)>/is';

        $stats = [
            'total' => 0,
            'skip_cn' => 0,
            'skip_blank' => 0,
            'failed' => 0,
        ];

        if (!preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            $plain = trim(strip_tags($html));
            if ($plain === '' || $this->containsChinese($plain)) {
                if ($plain === '') {
                    $stats['skip_blank']++;
                } else {
                    $stats['skip_cn']++;
                }
                return [$html, $stats];
            }
            try {
                $translated = $controller->translateTitle($plain, $serviceType, $targetLang, $sourceLang);
            } catch (Exception $e) {
                $translated = '';
            }
            if ($translated === '') {
                $stats['failed']++;
                return [$html, $stats];
            }
            $stats['total'] = 1;
            $escaped = htmlspecialchars($translated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $escaped = preg_replace("/\r\n|\r|\n/", '<br />', $escaped);
            return ['<p>' . $escaped . '</p>', $stats];
        }

        $result = '';
        $lastPos = 0;
        $matchCount = count($matches[0]);
        for ($i = 0; $i < $matchCount; $i++) {
            $full = $matches[0][$i][0];
            $start = $matches[0][$i][1];
            $result .= substr($html, $lastPos, $start - $lastPos);

            $tag = strtolower($matches['tag'][$i][0]);
            $attr = $matches['attr'][$i][0];
            $attrString = trim($attr);
            $attrString = $attrString === '' ? '' : ' ' . $attrString;
            $body = $matches['body'][$i][0];
            $text = trim(strip_tags($body));

            $stats['total']++;
            if ($text === '') {
                $stats['skip_blank']++;
                $result .= $full;
            } elseif ($this->containsChinese($text)) {
                $stats['skip_cn']++;
                $result .= $full;
            } else {
                $translated = '';
                try {
                    $translated = $controller->translateTitle($text, $serviceType, $targetLang, $sourceLang);
                } catch (Exception $e) {
                    $translated = '';
                }
                if ($translated === '') {
                    $stats['failed']++;
                    $result .= $full;
                } else {
                    $escaped = htmlspecialchars($translated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $escaped = preg_replace("/\r\n|\r|\n/", '<br />', $escaped);
                    $result .= '<' . $tag . $attrString . '>' . $escaped . '</' . $tag . '>';
                }
            }

            $lastPos = $start + strlen($full);
        }

        $result .= substr($html, $lastPos);
        return [$result, $stats];
    }

    private function prepareContentForTranslation($html) {
        if ($this->hasTtcnWrapper($html)) {
            $original = $this->extractOriginalFromWrapper($html);
            if ($original !== null) {
                return $original;
            }
            return $this->stripTtcnWrapper($html);
        }
        return $html;
    }

    private function getFeedIdFromEntry($entry) {
        if (method_exists($entry, 'feedId')) {
            $id = $entry->feedId();
            if ($id !== null && $id !== '') {
                return (string)$id;
            }
        }
        if (method_exists($entry, 'feed')) {
            $feed = $entry->feed();
            if (is_object($feed)) {
                if (method_exists($feed, 'id')) {
                    $feedId = $feed->id();
                    if ($feedId !== null && $feedId !== '') {
                        return (string)$feedId;
                    }
                }
                if (property_exists($feed, 'id') && $feed->id !== null && $feed->id !== '') {
                    return (string)$feed->id;
                }
            }
        }
        if (property_exists($entry, 'feed_id') && $entry->feed_id !== null && $entry->feed_id !== '') {
            return (string)$entry->feed_id;
        }
        return null;
    }

    private function isFeedTranslationEnabled($feedId) {
        if ($feedId === null) {
            return false;
        }
        $map = FreshRSS_Context::$user_conf->TranslateTitles ?? [];
        return isset($map[$feedId]) && $map[$feedId] === '1';
    }

    private function makeEntrySignatureKey($entry) {
        if (method_exists($entry, 'id')) {
            $id = $entry->id();
            if (!empty($id)) {
                return (string)$id;
            }
        }
        if (method_exists($entry, 'guid')) {
            $guid = $entry->guid();
            if (!empty($guid)) {
                return (string)$guid;
            }
        }
        if (method_exists($entry, 'hash')) {
            $hash = $entry->hash();
            if (!empty($hash)) {
                return (string)$hash;
            }
        }
        if (method_exists($entry, 'url')) {
            $url = $entry->url();
            if (!empty($url)) {
                return sha1((string)$url);
            }
        }
        return spl_object_hash($entry);
    }

    private function isTargetLanguageChinese($targetLang) {
        $lang = strtolower((string)$targetLang);
        return in_array($lang, ['zh', 'zh-cn', 'zh-hans', 'zh-hant', 'zh-tw'], true);
    }

    private function shouldSkipTextForTarget($text, $targetLang) {
        $plain = trim(strip_tags((string)$text));
        if ($this->isBlankText($plain)) {
            return true;
        }
        if ($this->isTargetLanguageChinese($targetLang) && $this->containsChinese($plain)) {
            return true;
        }
        return false;
    }

    private function buildTitleByDisplayMode($original, $translated, $displayMode) {
        $displayMode = strtolower((string)$displayMode);
        switch ($displayMode) {
            case 'translated_only':
                return $translated;
            case 'trans_then_orig':
                return $translated . ' | ' . $original;
            case 'orig_then_trans':
            default:
                return $original . ' | ' . $translated;
        }
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

    private function extractTranslatedFromWrapper($html) {
        if (preg_match('/<div[^>]*class="[^"]*ttcn-translated[^"]*"[^>]*>([\s\S]*?)<\/div>/i', $html, $m)) {
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

    private function forceUpdateFeed($feedId, $limit = 10) {
        $feedId = (string)$feedId;
        if ($feedId === '') {
            return ['ok' => false, 'message' => '未提供订阅源 ID'];
        }
        $limit = max(1, (int)$limit);
        $dao = null;
        try {
            $dao = FreshRSS_Factory::createEntryDao();
        } catch (Exception $e) {
            // ignore
        }
        if (!$dao) {
            return ['ok' => false, 'message' => '无法获取 EntryDAO'];
        }
        error_log('[TT][FORCE] feed=' . $feedId . ' limit=' . $limit . ' dao=' . get_class($dao));
        $entries = $this->listFeedEntries($dao, $feedId, $limit);
        if (!is_array($entries) || empty($entries)) {
            error_log('[TT][FORCE] feed=' . $feedId . ' no entries fetched');
            return ['ok' => false, 'message' => '未获取到该源的条目或接口不兼容'];
        }
        error_log('[TT][FORCE] feed=' . $feedId . ' fetched entries=' . count($entries));

        $updated = 0; $processed = 0; $errors = 0;
        foreach ($entries as $entry) {
            $processed++;
            try {
                $ek = $this->makeEntrySignatureKey($entry);
                $raw = '';
                // 准备：若已有包裹，取原文以触发重新翻译
                if (method_exists($entry, 'content')) {
                    $raw = (string)$entry->content();
                    $prepared = $this->prepareContentForTranslation($raw);
                    // 清空签名以确保不会命中“签名相同跳过”
                    $rawNoSign = $this->stripTtcnWrapper($raw);
                    $entry->content($rawNoSign);
                }
                error_log('[TT][FORCE] entry=' . $ek . ' rawLen=' . strlen((string)$raw));
                // 直接走统一流程（使用 update 上下文），内部将根据配置翻译标题/正文并写回 entry 对象
                $this->processEntryForTranslation($entry, 'force', true);

                // 持久化：生成 DAO 更新数组并调用 updateEntry；兼容旧版则尝试 update($entry)
                $saved = false;
                if (method_exists($dao, 'updateEntry')) {
                    $vals = $this->entryToDaoArray($entry);
                    error_log('[TT][FORCE] entry=' . $ek . ' daoSnippet=' . mb_substr(strip_tags((string)($vals['content'] ?? '')), 0, 160, 'UTF-8'));
                    $saved = (bool)$dao->updateEntry($vals);
                    error_log('[TT][FORCE] entry=' . $ek . ' save=updateEntry result=' . ($saved ? '1' : '0'));
                } elseif (method_exists($dao, 'update')) {
                    $saved = (bool)$dao->update($entry);
                    error_log('[TT][FORCE] entry=' . $ek . ' save=update result=' . ($saved ? '1' : '0'));
                } else {
                    error_log('[TT][FORCE] entry=' . $ek . ' no save method available');
                }
                if ($saved) {
                    $updated++;
                    try {
                        $verify = null;
                        if (method_exists($dao, 'searchByGuid') && method_exists($entry, 'guid')) {
                            $verify = $dao->searchByGuid((int)$feedId, (string)$entry->guid());
                        } elseif (method_exists($dao, 'searchById') && method_exists($entry, 'id')) {
                            $verify = $dao->searchById((string)$entry->id());
                        }
                        if ($verify && method_exists($verify, 'content')) {
                            $snippet = substr(strip_tags((string)$verify->content(false)), 0, 120);
                            error_log('[TT][FORCE] entry=' . $ek . ' verifySnippet=' . $snippet);
                        }
                    } catch (Throwable $e) {
                        error_log('[TT][FORCE] entry=' . $ek . ' verify exception=' . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                $errors++;
                error_log('[TT][FORCE] entry error: ' . $e->getMessage());
            }
            if ($updated >= $limit) { break; }
        }

        $msg = '处理=' . $processed . ' 更新=' . $updated . ' 错误=' . $errors;
        return ['ok' => $updated > 0, 'message' => $msg];
    }

    private function forceUpdateSelectedFeeds($limit = 10) {
        $limit = max(1, (int)$limit);
        $map = FreshRSS_Context::$user_conf->TranslateTitles ?? [];
        if (!is_array($map) || empty($map)) {
            return ['ok' => false, 'message' => '未选择任何订阅源'];
        }
        $feeds = [];
        foreach ($map as $id => $flag) {
            if ($flag === '1' || $flag === 1 || $flag === true) {
                $feeds[] = (string)$id;
            }
        }
        if (empty($feeds)) {
            return ['ok' => false, 'message' => '未选择任何订阅源'];
        }
        error_log('[TT][FORCE] selected feeds=' . implode(',', $feeds) . ' limit=' . $limit);
        $totalProcessed = 0; $totalUpdated = 0; $totalErrors = 0;
        foreach ($feeds as $feedId) {
            $res = $this->forceUpdateFeed($feedId, $limit);
            $msg = $res['message'] ?? '';
            error_log('[TT][FORCE] feed=' . $feedId . ' summary=' . $msg);
            if (!empty($res['ok'])) {
                if (preg_match('/处理=(\d+)\s+更新=(\d+)\s+错误=(\d+)/u', $msg, $m)) {
                    $totalProcessed += (int)$m[1];
                    $totalUpdated += (int)$m[2];
                    $totalErrors += (int)$m[3];
                }
            }
        }
        $summary = '处理=' . $totalProcessed . ' 更新=' . $totalUpdated . ' 错误=' . $totalErrors;
        if ($totalUpdated > 0 && class_exists('FreshRSS_UserDAO')) {
            try { FreshRSS_UserDAO::touch(); } catch (Throwable $e) {}
        }
        return ['ok' => $totalUpdated > 0, 'message' => $summary];
    }

    private function loadStoredEntryForCompare($entry, $feedId) {
        try {
            $dao = FreshRSS_Factory::createEntryDao();
        } catch (Throwable $e) { return null; }
        if (!$dao) { return null; }
        // Prefer GUID lookup when available
        try {
            if (method_exists($entry, 'guid') && method_exists($dao, 'searchByGuid')) {
                $guid = (string)$entry->guid();
                if ($guid !== '') {
                    $found = $dao->searchByGuid((int)$feedId, $guid);
                    if ($found) { return $found; }
                }
            }
        } catch (Throwable $e) {}
        try {
            if (method_exists($entry, 'id') && method_exists($dao, 'searchById')) {
                $id = $entry->id();
                if ($id !== null && $id !== '') {
                    $found = $dao->searchById((string)$id);
                    if ($found) { return $found; }
                }
            }
        } catch (Throwable $e) {}
        return null;
    }

    private function listFeedEntries($dao, $feedId, $limit) {
        $limit = max(1, (int)$limit);
        $entries = [];
        $cnt = function ($x) { return is_countable($x) ? count($x) : (is_array($x) ? count($x) : 0); };

        if (method_exists($dao, 'listByFeed')) {
            try {
                $entries = $dao->listByFeed($feedId, 0, $limit);
                error_log('[TT][FORCE][list] method=listByFeed count=' . $cnt($entries));
            } catch (Throwable $e) {
                error_log('[TT][FORCE][list] method=listByFeed exception=' . $e->getMessage());
                $entries = [];
            }
        } else {
            error_log('[TT][FORCE][list] method=listByFeed not exists');
        }

        if (empty($entries) && method_exists($dao, 'listEntries')) {
            try {
                // 尝试 feed 键名为 feed / id_feed
                $crit = ['feed' => $feedId, 'order' => 'desc', 'limit' => $limit];
                $entries = $dao->listEntries($crit);
                if (empty($entries)) {
                    $crit = ['id_feed' => (int)$feedId, 'order' => 'desc', 'limit' => $limit];
                    $entries = $dao->listEntries($crit);
                }
                error_log('[TT][FORCE][list] method=listEntries count=' . $cnt($entries));
            } catch (Throwable $e) {
                error_log('[TT][FORCE][list] method=listEntries exception=' . $e->getMessage());
                $entries = [];
            }
        } elseif (empty($entries)) {
            error_log('[TT][FORCE][list] method=listEntries not exists');
        }

        if (empty($entries) && method_exists($dao, 'listWhere')) {
            $feedIdInt = (int)$feedId;
            // FreshRSS listWhere $type 使用单字母，feed 对应 'f'
            $typeKey = 'f';
            $filtersCandidates = [null];
            if (class_exists('FreshRSS_BooleanSearch')) {
                try { $filtersCandidates[] = new FreshRSS_BooleanSearch(); } catch (Throwable $e) {}
            }
            $numberCandidates = [$limit, null];
            $stateCandidates = $this->getEntryStateAllCandidates();
            $sortCandidates = ['id', 'date', 'lastUserModified'];

            foreach ($stateCandidates as $stateVal) {
                foreach ($filtersCandidates as $filtersVal) {
                    foreach ($numberCandidates as $numVal) {
                        foreach ($sortCandidates as $sortVal) {
                            try {
                                $tmp = $dao->listWhere($typeKey, $feedIdInt, $stateVal, $filtersVal, '0', '0', $sortVal, 'DESC', '0', [], ($numVal ?? $limit), 0);
                                $c = ($tmp instanceof Traversable) ? 0 : $cnt($tmp);
                                if ($tmp instanceof Traversable) {
                                    $collected = $this->iterToArrayLimit($tmp, $limit);
                                    $c = count($collected);
                                    $tmp = $collected;
                                }
                                error_log('[TT][FORCE][list] method=listWhere type=' . $typeKey . ' state=' . var_export($stateVal,true) . ' filters=' . (is_object($filtersVal)?get_class($filtersVal):'null') . ' number=' . var_export($numVal,true) . ' sort=' . $sortVal . ' count=' . $c);
                                if (!empty($tmp)) {
                                    $entries = $tmp;
                                    break 4;
                                }
                            } catch (Throwable $e) {
                                error_log('[TT][FORCE][list] method=listWhere exception=' . $e->getMessage());
                            }
                        }
                    }
                }
            }
        } elseif (empty($entries)) {
            error_log('[TT][FORCE][list] method=listWhere not exists');
        }

        return $entries;
    }

    private function iterToArrayLimit($iter, $limit) {
        $out = [];
        if ($iter instanceof Traversable) {
            foreach ($iter as $e) {
                $out[] = $e;
                if (count($out) >= $limit) { break; }
            }
        }
        return $out;
    }

    private function entryToDaoArray($entry) {
        // Map a FreshRSS_Entry object to the array expected by EntryDAO::updateEntry()
        // Provide sensible defaults to avoid missing bindings
        $feedId = method_exists($entry, 'feedId') ? (int)$entry->feedId() : 0;
        $guid = method_exists($entry, 'guid') ? (string)$entry->guid() : '';
        $guid = substr($guid, 0, 767);
        $title = method_exists($entry, 'title') ? (string)$entry->title() : '';
        $title = mb_strcut($title, 0, 8192, 'UTF-8');
        // authors(true) returns a semicolon-joined string; fallback to empty string
        $author = method_exists($entry, 'authors') ? (string)$entry->authors(true) : (method_exists($entry, 'author') ? (string)$entry->author() : '');
        $author = mb_strcut($author, 0, 1024, 'UTF-8');
        $content = method_exists($entry, 'content') ? (string)$entry->content(false) : '';
        $link = method_exists($entry, 'link') ? (string)$entry->link(true) : '';
        $date = method_exists($entry, 'date') ? (int)$entry->date(true) : time();
        $now = time();
        $lastSeen = method_exists($entry, 'lastSeen') ? (int)$entry->lastSeen() : $now;
        if ($lastSeen <= 0) { $lastSeen = $now; }
        $lastUserModified = method_exists($entry, 'lastUserModified') ? (int)$entry->lastUserModified() : 0;
        if ($lastUserModified <= 0) { $lastUserModified = $now; } else { $lastUserModified = max($lastUserModified, $now); }
        $hash = method_exists($entry, 'hash') ? (string)$entry->hash() : md5($link . $title . $author . $content);
        $isRead = method_exists($entry, 'isRead') ? $entry->isRead() : null;
        $isFavorite = method_exists($entry, 'isFavorite') ? $entry->isFavorite() : null;
        $tags = method_exists($entry, 'tags') ? (string)$entry->tags(true) : '';
        $tags = mb_strcut($tags, 0, 2048, 'UTF-8');
        $attributes = method_exists($entry, 'attributes') ? $entry->attributes() : [];

        return [
            'id' => '0',
            'id_feed' => $feedId,
            'guid' => $guid,
            'title' => $title,
            'author' => $author,
            'content' => $content,
            'link' => $link,
            'date' => $date,
            'lastSeen' => $lastSeen,
            'lastUserModified' => $lastUserModified,
            'hash' => $hash,
            'is_read' => $isRead,
            'is_favorite' => $isFavorite,
            'tags' => $tags,
            'attributes' => $attributes,
        ];
    }

    private function getEntryStateAllCandidates() {
        $c = [];
        // Common "ALL" variants
        foreach (['FreshRSS_Entry::STATE_ALL', 'FreshRSS_Entry::STATE_BOTH', 'FreshRSS_Entry::STATE_ALL_READ'] as $name) {
            try { $v = @constant($name); if ($v !== null && $v !== false) { $c[] = (int)$v; } } catch (Throwable $e) {}
        }
        // Individual flags
        $read = @constant('FreshRSS_Entry::STATE_READ');
        $notRead = @constant('FreshRSS_Entry::STATE_NOT_READ');
        if (is_int($read)) { $c[] = (int)$read; }
        if (is_int($notRead)) { $c[] = (int)$notRead; }
        if (is_int($read) && is_int($notRead)) { $c[] = ($read | $notRead); }
        // Generic fallbacks
        $c[] = 0; // some versions treat 0 as all
        $c[] = -1; // fallback
        $out = [];
        foreach ($c as $v) { if (!in_array($v, $out, true)) { $out[] = $v; } }
        return $out;
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
