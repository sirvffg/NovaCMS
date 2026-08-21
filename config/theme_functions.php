<?php
/**
 * NovaCMS theme discovery and validation helpers.
 *
 * Keeping theme rules here ensures the public renderer, the administration
 * screen and content editors all agree on what an installable theme is.
 */

if (!function_exists('novaThemeRoot')) {
    function novaThemeRoot($root = null)
    {
        return $root !== null ? rtrim((string)$root, '/\\') : dirname(__DIR__) . '/vendor/nova-themes';
    }
}

if (!function_exists('novaThemeIsValidSlug')) {
    function novaThemeIsValidSlug($slug)
    {
        return is_string($slug)
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}\z/D', $slug) === 1;
    }
}

if (!function_exists('novaThemeKnownTemplates')) {
    function novaThemeKnownTemplates()
    {
        return [
            'index'        => '首页',
            'blog'         => '文章列表',
            'page'         => '独立页面',
            'docs'         => '文档列表',
            'document'     => '文档详情',
            'shuoshuo'     => '瞬间',
            'guestbook'    => '留言板',
            'gallery'      => '图库',
            'friend-links' => '友情链接',
            'announcement' => '公告',
            'profile'      => '个人中心',
            '404'          => '错误页',
        ];
    }
}

if (!function_exists('novaThemePathIsInside')) {
    function novaThemePathIsInside($path, $parent)
    {
        $realPath = realpath($path);
        $realParent = realpath($parent);
        if ($realPath === false || $realParent === false) {
            return false;
        }

        $realPath = rtrim(str_replace('\\', '/', $realPath), '/');
        $realParent = rtrim(str_replace('\\', '/', $realParent), '/');
        return $realPath === $realParent || strpos($realPath . '/', $realParent . '/') === 0;
    }
}

if (!function_exists('novaThemeSafeText')) {
    function novaThemeSafeText($value, $fallback, $maxLength = 255)
    {
        if (!is_scalar($value)) {
            return $fallback;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return $fallback;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return substr($value, 0, $maxLength);
    }
}

if (!function_exists('novaThemeNormalizeRelativeFile')) {
    function novaThemeNormalizeRelativeFile($themePath, $relative, array $extensions = [])
    {
        if (!is_string($relative)) {
            return '';
        }

        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || $relative[0] === '/' || preg_match('/^[a-zA-Z]:\//', $relative)) {
            return '';
        }

        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x1F\x7F]/', $segment)) {
                return '';
            }
        }

        if ($extensions) {
            $extension = strtolower((string)pathinfo($relative, PATHINFO_EXTENSION));
            if (!in_array($extension, $extensions, true)) {
                return '';
            }
        }

        $candidate = rtrim($themePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        return is_file($candidate) && novaThemePathIsInside($candidate, $themePath) ? $relative : '';
    }
}

if (!function_exists('novaThemeNormalizePageTemplates')) {
    function novaThemeNormalizePageTemplates($value)
    {
        $templates = [];
        if (!is_array($value)) {
            return $templates;
        }

        foreach ($value as $key => $label) {
            $key = trim((string)$key);
            if (!novaThemeIsValidSlug($key) || !is_scalar($label)) {
                continue;
            }
            $label = novaThemeSafeText($label, '', 80);
            if ($label !== '') {
                $templates[$key] = $label;
            }
        }
        return $templates;
    }
}

if (!function_exists('novaThemeInspect')) {
    function novaThemeInspect($themePath, $slug = null, $themesRoot = null)
    {
        $slug = $slug !== null ? (string)$slug : basename(rtrim((string)$themePath, '/\\'));
        $themesRoot = novaThemeRoot($themesRoot);
        $themePath = rtrim((string)$themePath, '/\\');
        $knownTemplates = novaThemeKnownTemplates();
        $theme = [
            'slug'              => $slug,
            'name'              => $slug,
            'version'           => '1.0.0',
            'author'            => 'Unknown',
            'description'       => '',
            'homepage'          => '',
            'min_nova_version'  => '',
            'parent'            => '',
            'license'           => '',
            'path'              => $themePath,
            'logo'              => '',
            'logo_url'          => '',
            'screenshot'        => '',
            'screenshot_url'    => '',
            'templates'         => [],
            'missing_templates' => [],
            'page_templates'    => ['default' => '默认页面'],
            'errors'            => [],
            'warnings'          => [],
            'valid'             => false,
        ];

        if (!novaThemeIsValidSlug($slug)) {
            $theme['errors'][] = '目录名只能包含字母、数字、短横线和下划线，且必须以字母或数字开头';
        }
        if (!is_dir($themePath)) {
            $theme['errors'][] = '主题目录不存在';
            return $theme;
        }
        if (!novaThemePathIsInside($themePath, $themesRoot)) {
            $theme['errors'][] = '主题目录位于允许范围之外';
            return $theme;
        }

        $manifestFile = $themePath . '/theme.json';
        if (!is_file($manifestFile)) {
            $theme['errors'][] = '缺少 theme.json';
            return $theme;
        }

        $rawManifest = file_get_contents($manifestFile);
        if ($rawManifest === false) {
            $theme['errors'][] = '无法读取 theme.json';
            return $theme;
        }

        $manifest = json_decode($rawManifest, true);
        if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE) {
            $theme['errors'][] = 'theme.json 格式错误：' . json_last_error_msg();
            return $theme;
        }

        $theme['name'] = novaThemeSafeText($manifest['name'] ?? null, $slug, 120);
        $theme['version'] = novaThemeSafeText($manifest['version'] ?? null, '1.0.0', 40);
        $theme['author'] = novaThemeSafeText($manifest['author'] ?? null, 'Unknown', 120);
        $theme['description'] = novaThemeSafeText($manifest['description'] ?? null, '', 500);
        $theme['min_nova_version'] = novaThemeSafeText($manifest['min_nova_version'] ?? null, '', 40);

        if (isset($manifest['slug']) && (!is_scalar($manifest['slug']) || (string)$manifest['slug'] !== $slug)) {
            $theme['errors'][] = 'theme.json 中的 slug 必须与目录名一致';
        }

        if (isset($manifest['parent']) && trim((string)(is_scalar($manifest['parent']) ? $manifest['parent'] : '')) !== '') {
            $parentSlug = is_scalar($manifest['parent']) ? trim((string)$manifest['parent']) : '';
            $parentPath = $themesRoot . DIRECTORY_SEPARATOR . $parentSlug;
            if (!novaThemeIsValidSlug($parentSlug) || $parentSlug === $slug) {
                $theme['errors'][] = '父主题标识无效';
            } elseif (!is_dir($parentPath) || !novaThemePathIsInside($parentPath, $themesRoot) || !is_file($parentPath . '/theme.json')) {
                $theme['errors'][] = '父主题「' . $parentSlug . '」不存在或不完整';
            } else {
                $theme['parent'] = $parentSlug;
            }
        } elseif (isset($manifest['parent']) && !is_scalar($manifest['parent'])) {
            $theme['errors'][] = '父主题标识必须是字符串';
        }

        $homepageValue = $manifest['homepage'] ?? $manifest['theme_uri'] ?? '';
        $homepage = is_scalar($homepageValue) ? trim((string)$homepageValue) : '';
        if ($homepageValue !== '' && !is_scalar($homepageValue)) {
            $theme['warnings'][] = '主页地址必须是字符串';
        }
        if ($homepage !== '') {
            $scheme = strtolower((string)parse_url($homepage, PHP_URL_SCHEME));
            if (filter_var($homepage, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)) {
                $theme['homepage'] = $homepage;
            } else {
                $theme['warnings'][] = '主页地址不是有效的 HTTP(S) URL';
            }
        }

        $requestedScreenshot = isset($manifest['screenshot']) && is_scalar($manifest['screenshot'])
            ? (string)$manifest['screenshot']
            : 'screenshot.png';
        if (isset($manifest['screenshot']) && !is_scalar($manifest['screenshot'])) {
            $theme['warnings'][] = '主题截图路径必须是字符串';
        }
        $screenshot = novaThemeNormalizeRelativeFile($themePath, $requestedScreenshot, ['png', 'jpg', 'jpeg', 'webp', 'gif']);
        if ($screenshot !== '') {
            $theme['screenshot'] = $screenshot;
            $encodedSegments = array_map('rawurlencode', explode('/', $screenshot));
            $theme['screenshot_url'] = '/vendor/nova-themes/' . rawurlencode($slug) . '/' . implode('/', $encodedSegments);
        } elseif (isset($manifest['screenshot']) && is_scalar($manifest['screenshot']) && trim((string)$manifest['screenshot']) !== '') {
            $theme['warnings'][] = '主题截图不存在或路径不安全';
        }

        // 主题小图标 logo（默认 logo.png），仅限图片格式
        $requestedLogo = isset($manifest['logo']) && is_scalar($manifest['logo'])
            ? (string)$manifest['logo']
            : 'logo.png';
        if (isset($manifest['logo']) && !is_scalar($manifest['logo'])) {
            $theme['warnings'][] = '主题图标路径必须是字符串';
        }
        $logo = novaThemeNormalizeRelativeFile($themePath, $requestedLogo, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'ico']);
        if ($logo !== '') {
            $theme['logo'] = $logo;
            $encodedSegments = array_map('rawurlencode', explode('/', $logo));
            $theme['logo_url'] = '/vendor/nova-themes/' . rawurlencode($slug) . '/' . implode('/', $encodedSegments);
        } elseif (isset($manifest['logo']) && is_scalar($manifest['logo']) && trim((string)$manifest['logo']) !== '') {
            $theme['warnings'][] = '主题图标不存在或路径不安全';
        }

        // 许可证标识（SPDX 字符串或自定义文本，如 MIT、GPL-2.0、proprietary）
        $licenseValue = $manifest['license'] ?? '';
        $license = is_scalar($licenseValue) ? trim((string)$licenseValue) : '';
        if ($licenseValue !== '' && !is_scalar($licenseValue)) {
            $theme['warnings'][] = '许可证标识必须是字符串';
        }
        if ($license !== '') {
            // 限制长度，防止滥用
            $theme['license'] = mb_substr($license, 0, 64);
        }

        foreach ($knownTemplates as $template => $label) {
            if (is_file($themePath . '/themes/' . $template . '.php')) {
                $theme['templates'][$template] = $label;
            } else {
                $theme['missing_templates'][$template] = $label;
            }
        }

        foreach (['index' => '首页', '404' => '错误页'] as $requiredTemplate => $label) {
            if (!isset($theme['templates'][$requiredTemplate])) {
                $theme['errors'][] = '缺少必需模板：' . $requiredTemplate . '.php（' . $label . '）';
            }
        }

        $pageTemplates = novaThemeNormalizePageTemplates($manifest['page_templates'] ?? []);
        if ($pageTemplates) {
            $theme['page_templates'] = $pageTemplates;
        }
        if (!isset($theme['page_templates']['default'])) {
            $theme['page_templates'] = ['default' => '默认页面'] + $theme['page_templates'];
        }

        $theme['valid'] = empty($theme['errors']);
        return $theme;
    }
}

if (!function_exists('novaThemeScan')) {
    function novaThemeScan($themesRoot = null)
    {
        $themesRoot = novaThemeRoot($themesRoot);
        if (!is_dir($themesRoot)) {
            return [];
        }

        $themes = [];
        $entries = scandir($themesRoot);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            $path = $themesRoot . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }
            $themes[] = novaThemeInspect($path, $entry, $themesRoot);
        }

        usort($themes, static function ($left, $right) {
            return strcasecmp((string)$left['name'], (string)$right['name']);
        });
        return $themes;
    }
}

if (!function_exists('novaThemeFind')) {
    function novaThemeFind($slug, $themesRoot = null)
    {
        if (!novaThemeIsValidSlug($slug)) {
            return null;
        }
        $themesRoot = novaThemeRoot($themesRoot);
        $themePath = $themesRoot . DIRECTORY_SEPARATOR . $slug;
        return is_dir($themePath) ? novaThemeInspect($themePath, $slug, $themesRoot) : null;
    }
}

if (!function_exists('novaThemeResolveActive')) {
    function novaThemeResolveActive($configuredSlug, $themesRoot = null, $fallbackSlug = 'default')
    {
        $configuredSlug = novaThemeIsValidSlug($configuredSlug) ? (string)$configuredSlug : '';
        $theme = $configuredSlug !== '' ? novaThemeFind($configuredSlug, $themesRoot) : null;
        if ($theme !== null && $theme['valid']) {
            $theme['configured_slug'] = $configuredSlug;
            $theme['using_fallback'] = false;
            return $theme;
        }

        $fallback = novaThemeFind($fallbackSlug, $themesRoot);
        if ($fallback === null) {
            $fallback = novaThemeInspect(novaThemeRoot($themesRoot) . DIRECTORY_SEPARATOR . $fallbackSlug, $fallbackSlug, $themesRoot);
        }
        $fallback['configured_slug'] = $configuredSlug;
        $fallback['using_fallback'] = true;
        $fallback['fallback_reason'] = $theme === null
            ? '配置的主题不存在或标识无效'
            : implode('；', $theme['errors']);
        return $fallback;
    }
}

if (!function_exists('novaThemePreviewToken')) {
    function novaThemePreviewToken($slug)
    {
        if (!novaThemeIsValidSlug($slug) || empty($_SESSION['csrf_token'])) {
            return '';
        }
        return hash_hmac('sha256', 'nova-theme-preview:' . $slug, (string)$_SESSION['csrf_token']);
    }
}

if (!function_exists('novaThemeValidatePreviewToken')) {
    function novaThemeValidatePreviewToken($slug, $token)
    {
        $expected = novaThemePreviewToken($slug);
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }
}
