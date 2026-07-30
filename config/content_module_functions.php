<?php
/**
 * 内容模块公共能力。
 *
 * 页面、文档、邮件订阅和智能问答共用这里的建表、查询与输入规则。
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
               AND table_name IN ('cms_pages', 'cms_documents', 'cms_subscribers', 'cms_ai_qa', 'cms_ai_settings')"
        );
        if ((int)$tableCheck->fetchColumn() === 5) {
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

    $db->exec("CREATE TABLE IF NOT EXISTS `cms_documents` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `slug` varchar(191) NOT NULL,
        `category` varchar(100) NOT NULL DEFAULT '',
        `summary` text NULL,
        `content` longtext NULL,
        `file_url` varchar(2048) NOT NULL DEFAULT '',
        `status` varchar(20) NOT NULL DEFAULT 'draft',
        `is_featured` tinyint(1) NOT NULL DEFAULT 0,
        `download_count` int unsigned NOT NULL DEFAULT 0,
        `sort_order` int NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_cms_documents_slug` (`slug`),
        KEY `idx_cms_documents_public` (`status`, `is_featured`, `sort_order`),
        KEY `idx_cms_documents_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CMS 文档'");

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

    $db->exec("CREATE TABLE IF NOT EXISTS `cms_ai_qa` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `question` varchar(500) NOT NULL,
        `answer` longtext NOT NULL,
        `keywords` varchar(1000) NULL,
        `category` varchar(100) NOT NULL DEFAULT '',
        `status` varchar(20) NOT NULL DEFAULT 'active',
        `sort_order` int NOT NULL DEFAULT 0,
        `hit_count` int unsigned NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_cms_ai_qa_public` (`status`, `sort_order`),
        KEY `idx_cms_ai_qa_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CMS 智能问答知识库'");

    $db->exec("CREATE TABLE IF NOT EXISTS `cms_ai_settings` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `enabled` tinyint(1) NOT NULL DEFAULT 1,
        `welcome_message` varchar(500) NOT NULL DEFAULT '您好，请问有什么可以帮您？',
        `fallback_message` varchar(500) NOT NULL DEFAULT '暂时没有找到合适的答案，请换个说法再试。',
        `match_threshold` decimal(5,4) NOT NULL DEFAULT 0.3500,
        `max_results` smallint unsigned NOT NULL DEFAULT 3,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CMS 智能问答设置'");

    $db->exec("INSERT INTO `cms_ai_settings`
        (`id`, `enabled`, `welcome_message`, `fallback_message`, `match_threshold`, `max_results`)
        VALUES (1, 1, '您好，请问有什么可以帮您？', '暂时没有找到合适的答案，请换个说法再试。', 0.3500, 3)
        ON DUPLICATE KEY UPDATE `id` = `id`");

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

function contentModuleFormatDocument(array $row, $includeContent = true) {
    $item = [
        'id'             => (int)$row['id'],
        'title'          => (string)$row['title'],
        'slug'           => (string)$row['slug'],
        'category'       => (string)($row['category'] ?? ''),
        'summary'        => (string)($row['summary'] ?? ''),
        'file_url'       => (string)($row['file_url'] ?? ''),
        'is_featured'    => !empty($row['is_featured']),
        'download_count' => (int)($row['download_count'] ?? 0),
        'sort_order'     => (int)($row['sort_order'] ?? 0),
        'created_at'     => $row['created_at'] ?? null,
        'updated_at'     => $row['updated_at'] ?? null,
    ];
    if ($includeContent) {
        $item['content'] = (string)($row['content'] ?? '');
    }
    return $item;
}

/**
 * 获取已发布文档，返回分页数据。
 */
function contentModuleListPublishedDocuments(array $options = []) {
    $db = ensureContentModuleTables();
    // 公开列表使用 OFFSET 分页，限制可请求页码以避免构造昂贵的超大偏移。
    $maximumPage = 10000;
    $pageValue = $options['page'] ?? 1;
    $perPageValue = $options['per_page'] ?? 12;
    $categoryValue = $options['category'] ?? '';
    $searchValue = $options['search'] ?? '';
    $page = contentModuleClamp(
        is_scalar($pageValue) ? (int)$pageValue : 1,
        1,
        $maximumPage
    );
    $perPage = contentModuleClamp(is_scalar($perPageValue) ? (int)$perPageValue : 12, 1, 50);
    $category = is_scalar($categoryValue) ? trim((string)$categoryValue) : '';
    $search = is_scalar($searchValue) ? trim((string)$searchValue) : '';
    $featured = array_key_exists('featured', $options) ? $options['featured'] : null;

    $where = ["status = 'published'"];
    $params = [];

    if ($category !== '') {
        $where[] = 'category = :category';
        $params[':category'] = contentModuleSubstring($category, 0, 100);
    }
    if ($search !== '') {
        $where[] = '(title LIKE :search_title OR summary LIKE :search_summary OR category LIKE :search_category)';
        $searchValue = '%' . contentModuleSubstring($search, 0, 100) . '%';
        $params[':search_title'] = $searchValue;
        $params[':search_summary'] = $searchValue;
        $params[':search_category'] = $searchValue;
    }
    if ($featured !== null) {
        $where[] = 'is_featured = :featured';
        $params[':featured'] = $featured ? 1 : 0;
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $db->prepare("SELECT COUNT(*) FROM cms_documents WHERE {$whereSql}");
    foreach ($params as $name => $value) {
        $countStmt->bindValue($name, $value);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare(
        "SELECT id, title, slug, category, summary, file_url, is_featured,
                download_count, sort_order, created_at, updated_at
         FROM cms_documents
         WHERE {$whereSql}
         ORDER BY is_featured DESC, sort_order ASC, updated_at DESC, id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = contentModuleFormatDocument($row, false);
    }

    return [
        'items'       => $items,
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
    ];
}

function contentModuleGetDocumentCategories() {
    $db = ensureContentModuleTables();
    $stmt = $db->query(
        "SELECT category, COUNT(*) AS document_count
         FROM cms_documents
         WHERE status = 'published' AND category <> ''
         GROUP BY category
         ORDER BY category ASC"
    );

    return array_map(function($row) {
        return [
            'name'  => (string)$row['category'],
            'count' => (int)$row['document_count'],
        ];
    }, $stmt->fetchAll());
}

function contentModuleGetPublishedDocumentBySlug($slug) {
    $slug = contentModuleNormalizeSlug($slug);
    if ($slug === '') {
        return null;
    }

    $db = ensureContentModuleTables();
    $stmt = $db->prepare(
        "SELECT id, title, slug, category, summary, content, file_url, is_featured,
                download_count, sort_order, created_at, updated_at
         FROM cms_documents
         WHERE slug = ? AND status = 'published'
         LIMIT 1"
    );
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ? contentModuleFormatDocument($row, true) : null;
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

function contentModuleGetAiSettings() {
    $db = ensureContentModuleTables();
    $stmt = $db->query(
        "SELECT id, enabled, welcome_message, fallback_message, match_threshold,
                max_results, updated_at
         FROM cms_ai_settings
         ORDER BY id ASC
         LIMIT 1"
    );
    $row = $stmt->fetch() ?: [];

    return [
        'enabled'          => !isset($row['enabled']) || !empty($row['enabled']),
        'welcome_message'  => (string)($row['welcome_message'] ?? '您好，请问有什么可以帮您？'),
        'fallback_message' => (string)($row['fallback_message'] ?? '暂时没有找到合适的答案，请换个说法再试。'),
        'match_threshold'  => contentModuleClamp((float)($row['match_threshold'] ?? 0.35), 0.0, 1.0),
        'max_results'      => contentModuleClamp((int)($row['max_results'] ?? 3), 1, 10),
    ];
}

function contentModuleNormalizeSearchText($value) {
    $value = contentModuleLowercase(strip_tags((string)$value));
    $value = preg_replace('/[\p{P}\p{S}\s]+/u', ' ', $value);
    return trim((string)$value);
}

function contentModuleUnicodeNgrams($value, $size = 2) {
    $normalized = str_replace(' ', '', contentModuleNormalizeSearchText($value));
    if ($normalized === '') {
        return [];
    }
    $characters = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($characters) || !$characters) {
        return [];
    }
    $size = max(1, (int)$size);
    if (count($characters) <= $size) {
        return [implode('', $characters)];
    }
    $tokens = [];
    for ($index = 0, $last = count($characters) - $size; $index <= $last; $index++) {
        $tokens[implode('', array_slice($characters, $index, $size))] = true;
    }
    return array_keys($tokens);
}

function contentModuleAiSearchTokens($question) {
    $normalized = contentModuleNormalizeSearchText($question);
    $tokens = [];
    foreach (preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        if (contentModuleTextLength($word) >= 2) {
            $tokens[$word] = true;
        }
    }
    foreach (contentModuleUnicodeNgrams($normalized, 2) as $token) {
        $tokens[$token] = true;
    }
    return array_slice(array_keys($tokens), 0, 12);
}

function contentModuleKeywordList($keywords) {
    $items = preg_split('/[,，、;；|\r\n]+/u', (string)$keywords, -1, PREG_SPLIT_NO_EMPTY);
    $normalized = [];
    foreach ($items as $item) {
        $item = contentModuleNormalizeSearchText($item);
        if ($item !== '' && contentModuleTextLength($item) >= 2) {
            $normalized[$item] = true;
        }
    }
    return array_keys($normalized);
}

function contentModuleAiMatchScore($question, array $row) {
    $needle = contentModuleNormalizeSearchText($question);
    $candidate = contentModuleNormalizeSearchText($row['question'] ?? '');
    if ($needle === '' || $candidate === '') {
        return 0.0;
    }
    if ($needle === $candidate) {
        return 1.0;
    }

    $score = 0.0;
    if (contentModuleTextLength($needle) >= 2 && (
        strpos($needle, $candidate) !== false || strpos($candidate, $needle) !== false
    )) {
        $score = 0.88;
    }

    $keywords = contentModuleKeywordList($row['keywords'] ?? '');
    if ($keywords) {
        $matched = 0;
        foreach ($keywords as $keyword) {
            if (strpos($needle, $keyword) !== false) {
                $matched++;
            }
        }
        if ($matched > 0) {
            $keywordScore = 0.55 + (0.4 * ($matched / count($keywords)));
            $score = max($score, $keywordScore);
        }
    }

    $needleTokens = contentModuleUnicodeNgrams($needle, 2);
    $candidateTokens = contentModuleUnicodeNgrams($candidate, 2);
    if ($needleTokens && $candidateTokens) {
        $intersection = count(array_intersect($needleTokens, $candidateTokens));
        $union = count(array_unique(array_merge($needleTokens, $candidateTokens)));
        if ($union > 0) {
            $score = max($score, ($intersection / $union) * 0.72);
        }
    }
    return contentModuleClamp($score, 0.0, 1.0);
}

/**
 * 从启用的本地问答库匹配问题。
 */
function contentModuleAsk($question) {
    $question = trim(strip_tags((string)$question));
    $length = contentModuleTextLength($question);
    if ($length < 2 || $length > 500) {
        throw new InvalidArgumentException('invalid_question');
    }

    $settings = contentModuleGetAiSettings();
    if (!$settings['enabled']) {
        return [
            'enabled' => false,
            'matched' => false,
            'answer'  => $settings['fallback_message'],
            'matches' => [],
        ];
    }

    $db = ensureContentModuleTables();
    $searchTokens = contentModuleAiSearchTokens($question);
    if (!$searchTokens) {
        return [
            'enabled' => true,
            'matched' => false,
            'answer'  => $settings['fallback_message'],
            'matches' => [],
        ];
    }

    $candidateClauses = [];
    $candidateParams = [];
    foreach ($searchTokens as $token) {
        $candidateClauses[] = '(question LIKE ? OR keywords LIKE ?)';
        $likeToken = '%' . $token . '%';
        $candidateParams[] = $likeToken;
        $candidateParams[] = $likeToken;
    }
    $stmt = $db->prepare(
        "SELECT id, question, keywords
         FROM (
             SELECT id, LEFT(question, 500) AS question, LEFT(keywords, 1000) AS keywords,
                    sort_order
             FROM cms_ai_qa
             WHERE status = 'active'
             ORDER BY sort_order ASC, id ASC
             LIMIT 1000
         ) AS bounded_knowledge
         WHERE " . implode(' OR ', $candidateClauses) . "
         ORDER BY sort_order ASC, id ASC
         LIMIT 120"
    );
    $stmt->execute($candidateParams);

    $matches = [];
    $candidateOrder = 0;
    while ($row = $stmt->fetch()) {
        $score = contentModuleAiMatchScore($question, $row);
        if ($score < $settings['match_threshold']) {
            $candidateOrder++;
            continue;
        }
        $matches[] = [
            'id'       => (int)$row['id'],
            'question' => (string)$row['question'],
            'score'    => round($score, 4),
            '_sort'    => $candidateOrder,
        ];
        $candidateOrder++;
    }

    usort($matches, function($left, $right) {
        if ($left['score'] === $right['score']) {
            return $left['_sort'] <=> $right['_sort'];
        }
        return $left['score'] < $right['score'] ? 1 : -1;
    });
    $matches = array_slice($matches, 0, $settings['max_results']);

    if ($matches) {
        $ids = array_column($matches, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $answerStatement = $db->prepare(
            "SELECT id, LEFT(answer, 20000) AS answer, LEFT(category, 100) AS category
             FROM cms_ai_qa
             WHERE status = 'active' AND id IN ({$placeholders})"
        );
        $answerStatement->execute($ids);
        $answers = [];
        while ($answerRow = $answerStatement->fetch()) {
            $answers[(int)$answerRow['id']] = [
                'answer'   => (string)$answerRow['answer'],
                'category' => (string)($answerRow['category'] ?? ''),
            ];
        }
        foreach ($matches as $matchIndex => $match) {
            if (!array_key_exists($match['id'], $answers)) {
                unset($matches[$matchIndex]);
                continue;
            }
            $matches[$matchIndex]['answer'] = $answers[$match['id']]['answer'];
            $matches[$matchIndex]['category'] = $answers[$match['id']]['category'];
        }
        $matches = array_values($matches);
        $ids = array_column($matches, 'id');
    }

    if ($matches) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $update = $db->prepare("UPDATE cms_ai_qa SET hit_count = hit_count + 1 WHERE status = 'active' AND id IN ({$placeholders})");
        $update->execute($ids);
    }

    foreach ($matches as &$match) {
        unset($match['_sort']);
    }
    unset($match);

    return [
        'enabled' => true,
        'matched' => !empty($matches),
        'answer'  => $matches ? $matches[0]['answer'] : $settings['fallback_message'],
        'matches' => $matches,
    ];
}

/**
 * 仅允许站内路径及 HTTP(S) 地址进入公开下载链接。
 */
function contentModuleSafeUrl($url) {
    $url = trim((string)$url);
    if (
        $url === '' ||
        preg_match('/[\x00-\x1F\x7F\\\\]/', $url) ||
        preg_match('/%(?:0a|0d|5c)/i', $url)
    ) {
        return '';
    }
    if ($url[0] === '/' && substr($url, 0, 2) !== '//') {
        return $url;
    }
    $parts = parse_url($url);
    if (
        !$parts ||
        empty($parts['scheme']) ||
        empty($parts['host']) ||
        isset($parts['user']) ||
        isset($parts['pass'])
    ) {
        return '';
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return '';
    }

    // Reuse the project URL guard so literal private/reserved hosts and known
    // local endpoints cannot be offered as public download targets.
    if (function_exists('validateUrl') && validateUrl($url, ['http', 'https']) === false) {
        return '';
    }
    return $url;
}
