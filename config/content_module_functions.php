<?php
/**
 * 内容模块公共能力。
 *
 * 页面和邮件订阅共用这里的建表、查询与输入规则。
 * 公共查询只返回已发布/已启用的数据，后台草稿不会从这里泄露。
 */

/**
 * 幂等创建内容模块所需数据表。
 *
 * @param PDO|null $db
 * @return PDO
 */
function ensureContentModuleTables($db = null) {
    static $ensured = false;

    $db = $db ?: getDB();
    if ($ensured) {
        return $db;
    }

    // 正常请求只做一次轻量元数据检查；仅在首次部署缺表时执行 DDL，
    // 避免每次 API/页面访问都申请 CREATE TABLE 元数据锁。
    try {
        $tableCheck = $db->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('cms_pages', 'cms_subscribers')"
        );
        if ((int)$tableCheck->fetchColumn() === 2) {
            $ensured = true;
            return $db;
        }
    } catch (Throwable $exception) {
        // 元数据视图不可用时继续使用幂等建表语句，保持共享主机兼容性。
        error_log('Content module table check failed: ' . $exception->getMessage());
    }

    $db->exec("CREATE TABLE IF NOT EXISTS `cms_pages` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `slug` varchar(191) NOT NULL,
        `summary` text NULL,
        `content` longtext NULL,
        `template` varchar(100) NOT NULL DEFAULT 'default',
        `status` varchar(20) NOT NULL DEFAULT 'draft',
        `show_in_nav` tinyint(1) NOT NULL DEFAULT 0,
        `sort_order` int NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_cms_pages_slug` (`slug`),
        KEY `idx_cms_pages_public` (`status`, `show_in_nav`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CMS 页面'");

    $db->exec("CREATE TABLE IF NOT EXISTS `cms_subscribers` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `email` varchar(191) NOT NULL,
        `name` varchar(100) NOT NULL DEFAULT '',
        `status` varchar(20) NOT NULL DEFAULT 'active',
        `source` varchar(50) NOT NULL DEFAULT 'website',
        `notes` text NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `unsubscribed_at` datetime NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_cms_subscribers_email` (`email`),
        KEY `idx_cms_subscribers_status` (`status`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CMS 邮件订阅'");

    $ensured = true;
    return $db;
}

function contentModuleTextLength($value) {
    $value = (string)$value;
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    return strlen($value);
}

function contentModuleSubstring($value, $start, $length = null) {
    $value = (string)$value;
    if (function_exists('mb_substr')) {
        return $length === null
            ? mb_substr($value, $start, null, 'UTF-8')
            : mb_substr($value, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function contentModuleLowercase($value) {
    $value = (string)$value;
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function contentModuleClamp($value, $minimum, $maximum) {
    return max($minimum, min($maximum, $value));
}

/**
 * 校验路由 slug。保留中文、字母、数字、下划线与连字符。
 */
function contentModuleNormalizeSlug($slug) {
    $slug = trim(rawurldecode((string)$slug));
    if ($slug === '' || contentModuleTextLength($slug) > 191) {
        return '';
    }
    if (!preg_match('/^[\p{L}\p{N}_-]+$/u', $slug)) {
        return '';
    }
    return contentModuleLowercase($slug);
}

function contentModuleFormatPage(array $row, $includeContent = true) {
    $item = [
        'id'          => (int)$row['id'],
        'title'       => (string)$row['title'],
        'slug'        => (string)$row['slug'],
        'summary'     => (string)($row['summary'] ?? ''),
        'template'    => (string)($row['template'] ?? 'default'),
        'show_in_nav' => !empty($row['show_in_nav']),
        'sort_order'  => (int)($row['sort_order'] ?? 0),
        'created_at'  => $row['created_at'] ?? null,
        'updated_at'  => $row['updated_at'] ?? null,
    ];
    if ($includeContent) {
        $item['content'] = (string)($row['content'] ?? '');
    }
    return $item;
}

function contentModuleListPublishedPages($navOnly = false, $limit = 100) {
    $db = ensureContentModuleTables();
    $limit = contentModuleClamp(is_scalar($limit) ? (int)$limit : 100, 1, 100);
    $sql = "SELECT id, title, slug, summary, template, show_in_nav, sort_order, created_at, updated_at
            FROM cms_pages
            WHERE status = 'published'";
    if ($navOnly) {
        $sql .= ' AND show_in_nav = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC LIMIT :limit';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = contentModuleFormatPage($row, false);
    }
    return $items;
}

function contentModuleCountPublishedPages($navOnly = false) {
    $db = ensureContentModuleTables();
    $sql = "SELECT COUNT(*) FROM cms_pages WHERE status = 'published'";
    if ($navOnly) {
        $sql .= ' AND show_in_nav = 1';
    }
    return (int)$db->query($sql)->fetchColumn();
}

function contentModuleGetPublishedPageBySlug($slug) {
    $slug = contentModuleNormalizeSlug($slug);
    if ($slug === '') {
        return null;
    }

    $db = ensureContentModuleTables();
    $stmt = $db->prepare(
        "SELECT id, title, slug, summary, content, template, show_in_nav,
                sort_order, created_at, updated_at
         FROM cms_pages
         WHERE slug = ? AND status = 'published'
         LIMIT 1"
    );
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ? contentModuleFormatPage($row, true) : null;
}

/**
 * 创建新订阅。已有邮箱保持原状态和资料不变，调用方统一返回成功文案。
 */
function contentModuleSubscribe($email, $name = '', $source = 'website') {
    $email = contentModuleLowercase(trim((string)$email));
    $name = trim(strip_tags((string)$name));
    $source = trim((string)$source);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
        throw new InvalidArgumentException('invalid_email');
    }
    if (contentModuleTextLength($name) > 100) {
        throw new InvalidArgumentException('invalid_name');
    }
    if (!preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $source)) {
        $source = 'website';
    }

    $db = ensureContentModuleTables();
    // Existing records are deliberately left untouched. A public request must
    // never reactivate an address that an administrator marked as unsubscribed
    // or bounced, nor overwrite subscriber profile data.
    $stmt = $db->prepare(
        "INSERT IGNORE INTO cms_subscribers
            (email, name, status, source, created_at, updated_at, unsubscribed_at)
         VALUES (?, ?, 'active', ?, NOW(), NOW(), NULL)"
    );
    $stmt->execute([$email, $name, $source]);
}
