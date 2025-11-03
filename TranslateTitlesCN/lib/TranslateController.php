<?php
require_once('TranslationService.php');

class TranslateController {
    public function translateTitle($title, $serviceOverride = null, $targetOverride = null, $sourceOverride = 'auto') {
        error_log('TranslateTitlesCN: Controller entered translateTitle()');
        if (empty($title)) {
            error_log('TranslateTitlesCN: Empty title provided');
            return '';
        }

        $serviceType = $serviceOverride ?? (FreshRSS_Context::$user_conf->TranslateService ?? 'google');
        $translationService = new TranslationService($serviceType);
        $target = $targetOverride ?? (FreshRSS_Context::$user_conf->TargetLang ?? 'zh-cn');
        $source = $sourceOverride ?? 'auto';
        $translatedTitle = '';
        $attempts = 0;
        $sleepTime = 1;

        error_log('TranslateTitlesCN: Service: ' . $serviceType . ', Title: ' . $title);

        while ($attempts < 2) {
            try {
                $translatedTitle = $translationService->translate($title, $target, $source);
            } catch (Exception $e) {
                error_log('TranslateTitlesCN: Translation exception on attempt ' . ($attempts + 1) . ' - ' . $e->getMessage());
            }

            if (!empty($translatedTitle)) {
                error_log('TranslateTitlesCN: Translation successful: ' . $translatedTitle);
                break;
            }

            $attempts++;
            error_log('TranslateTitlesCN: Translation failed or empty on attempt ' . $attempts);
            if ($attempts < 2) {
                sleep($sleepTime);
                $sleepTime *= 2;
            }
        }

        $needsFallback = in_array($serviceType, ['deeplx', 'libre', 'openai'], true);

        if (empty($translatedTitle) && $needsFallback) {
            $svcName = strtoupper($serviceType);
            error_log("TranslateTitlesCN: {$svcName} failed, falling back to Google Translate");
            $translationService = new TranslationService('google');
            try {
                $translatedTitle = $translationService->translate($title, $target, $source);
                if (!empty($translatedTitle)) {
                    error_log('TranslateTitlesCN: Google Translate fallback successful: ' . $translatedTitle);
                }
            } catch (Exception $e) {
                error_log('TranslateTitlesCN: Google Translate fallback failed - ' . $e->getMessage());
            }
        }

        if (empty($translatedTitle)) {
            error_log('TranslateTitlesCN: All translation attempts failed, returning original title');
            return $title;
        }

        return $translatedTitle;
    }
}
