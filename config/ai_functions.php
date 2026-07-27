<?php
/**
 * AI 摘要：OpenAI 兼容 API、多模型、保护内容剥离
 * 无全局 API Key，仅使用 blog_ai_models 每行独立 Key
 */

/** 兼容接口必填 max_tokens；取较大值以允许长摘要，实际上限以模型/服务商为准 */
const AI_SUMMARY_MAX_OUTPUT_TOKENS = 131072;

/**
 * 确保表与列存在（幂等，可重复调用）
 */
function aiEnsureSchema(PDO $db) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `blog_ai_models` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `api_base` varchar(500) NOT NULL,
            `api_key` text NOT NULL,
            `model_id` varchar(200) NOT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `is_default` tinyint(1) NOT NULL DEFAULT 0,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_enabled` (`enabled`),
            KEY `idx_default` (`is_default`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 模型（每行独立 Key）'");
    } catch (Exception $e) {
        // ignore
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `blog_ai_usage_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `post_id` int(11) DEFAULT NULL,
            `admin_id` int(11) DEFAULT NULL,
            `ai_model_id` int(11) DEFAULT NULL,
            `model_id_str` varchar(200) DEFAULT NULL,
            `prompt_tokens` int(11) NOT NULL DEFAULT 0,
            `completion_tokens` int(11) NOT NULL DEFAULT 0,
            `total_tokens` int(11) NOT NULL DEFAULT 0,
            `success` tinyint(1) NOT NULL DEFAULT 0,
            `error_message` text,
            `request_payload` mediumtext,
            `response_payload` mediumtext,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_created` (`created_at`),
            KEY `idx_post` (`post_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 调用用量'");
    } catch (Exception $e) {
        // ignore
    }

    $blogCols = [
        "ALTER TABLE `blog_posts` ADD COLUMN `ai_summary` text COMMENT 'AI摘要'",
        "ALTER TABLE `blog_posts` ADD COLUMN `ai_summary_hash` char(64) DEFAULT NULL COMMENT '清洗正文SHA256'",
        "ALTER TABLE `blog_posts` ADD COLUMN `ai_summary_model_id` int(11) DEFAULT NULL",
        "ALTER TABLE `blog_posts` ADD COLUMN `ai_summary_at` datetime DEFAULT NULL",
        "ALTER TABLE `blog_posts` ADD COLUMN `ai_summary_error` text COMMENT '上次生成失败原因'",
    ];
    foreach ($blogCols as $sql) {
        try {
            $db->exec($sql);
        } catch (Exception $e) {
            // 列已存在
        }
    }

    try {
        $db->exec("ALTER TABLE `blog_posts` MODIFY COLUMN `ai_summary` MEDIUMTEXT COMMENT 'AI摘要(可较长)'");
    } catch (Exception $e) {
        // ignore
    }

    $siteCols = [
        "ALTER TABLE `website_config` ADD COLUMN `ai_feature_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'AI摘要总开关'",
        "ALTER TABLE `website_config` ADD COLUMN `ai_max_input_chars` int(11) NOT NULL DEFAULT 12000",
        "ALTER TABLE `website_config` ADD COLUMN `ai_max_output_tokens` int(11) NOT NULL DEFAULT 512",
        "ALTER TABLE `website_config` ADD COLUMN `ai_temperature` decimal(4,2) NOT NULL DEFAULT 0.30",
        "ALTER TABLE `website_config` ADD COLUMN `ai_summary_section_title` varchar(100) DEFAULT '文章摘要'",
    ];
    foreach ($siteCols as $sql) {
        try {
            $db->exec($sql);
        } catch (Exception $e) {
            // ignore
        }
    }

    // 日志表新增字段（兼容已有数据库）
    $logCols = [
        "ALTER TABLE `blog_ai_usage_log` ADD COLUMN `request_payload` MEDIUMTEXT COMMENT '请求体'",
        "ALTER TABLE `blog_ai_usage_log` ADD COLUMN `response_payload` MEDIUMTEXT COMMENT '响应体'",
    ];
    foreach ($logCols as $sql) {
        try {
            $db->exec($sql);
        } catch (Exception $e) {
            // 列已存在
        }
    }
}

/**
 * 剥离付费与隐私区块后的正文（用于哈希与模型输入）
 */
function aiStripProtectedBlocksForSummary($content) {
    $text = (string)$content;
    $text = preg_replace('/\[Paid\].*?\[\/Paid\]/s', '', $text);
    $text = preg_replace('/\[Privacy\].*?\[\/Privacy\]/s', '', $text);
    return $text;
}

/**
 * 全文规范化（仅换行统一与首尾空白），不截断，用于哈希与模型输入
 */
function aiNormalizeFullTextForSummary($text) {
    $text = preg_replace('/\r\n|\r/', "\n", (string)$text);
    return trim($text);
}

function aiContentHashForSummary($rawContent) {
    $stripped = aiStripProtectedBlocksForSummary($rawContent);
    $normalized = aiNormalizeFullTextForSummary($stripped);
    return hash('sha256', $normalized);
}

function aiMaskApiKey($key) {
    $k = (string)$key;
    $len = strlen($k);
    if ($len <= 8) {
        return str_repeat('•', min(12, $len));
    }
    return substr($k, 0, 4) . str_repeat('•', 12) . substr($k, -4);
}

function aiChatCompletionsUrl($apiBase) {
    $b = rtrim(trim($apiBase), '/');
    if ($b === '') {
        return '';
    }
    if (preg_match('#/v1$#', $b)) {
        return $b . '/chat/completions';
    }
    return $b . '/v1/chat/completions';
}

/**
 * OpenAI 兼容 chat completions
 *
 * @return array{ok:bool,content:?string,usage:?array,error:?string,http_code:int}
 */
function aiOpenAiChatCompletion($apiBase, $apiKey, $modelId, array $messages, $maxTokens, $temperature) {
    $url = aiChatCompletionsUrl($apiBase);
    if ($url === '' || $apiKey === '' || $modelId === '') {
        return ['ok' => false, 'content' => null, 'usage' => null, 'error' => '缺少 API 地址、密钥或模型名', 'http_code' => 0];
    }

    $body = json_encode([
        'model' => $modelId,
        'messages' => $messages,
        'max_tokens' => (int)$maxTokens,
        'temperature' => (float)$temperature,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $cerr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $raw === false) {
        return ['ok' => false, 'content' => null, 'usage' => null, 'error' => '请求失败: ' . ($cerr ?: ('curl #' . $errno)), 'http_code' => $httpCode];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'content' => null, 'usage' => null, 'error' => '响应非 JSON: HTTP ' . $httpCode, 'http_code' => $httpCode];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $err = $json['error']['message'] ?? $json['message'] ?? $raw;
        return ['ok' => false, 'content' => null, 'usage' => null, 'error' => is_string($err) ? $err : json_encode($json, JSON_UNESCAPED_UNICODE), 'http_code' => $httpCode];
    }

    $content = $json['choices'][0]['message']['content'] ?? null;
    if (is_string($content)) {
        $content = trim($content);
    } else {
        $content = null;
    }

    $usage = isset($json['usage']) && is_array($json['usage']) ? $json['usage'] : null;

    return [
        'ok' => $content !== null && $content !== '',
        'content' => $content,
        'usage' => $usage,
        'error' => $content ? null : '模型未返回文本',
        'http_code' => $httpCode,
    ];
}

function aiGetSiteAiSettings(PDO $db) {
    $defaults = [
        'ai_feature_enabled' => 1,
        'ai_temperature' => 0.3,
        'ai_summary_section_title' => '文章摘要',
    ];
    try {
        $row = $db->query("SELECT ai_feature_enabled, ai_temperature, ai_summary_section_title FROM website_config WHERE id = 1 LIMIT 1")->fetch();
    } catch (Exception $e) {
        return $defaults;
    }
    if (!$row) {
        return $defaults;
    }
    return [
        'ai_feature_enabled' => (int)($row['ai_feature_enabled'] ?? 1),
        'ai_temperature' => (float)($row['ai_temperature'] ?? 0.3),
        'ai_summary_section_title' => $row['ai_summary_section_title'] ?? '文章摘要',
    ];
}

/**
 * 选取模型：指定 id，否则默认启用且 is_default
 */
function aiResolveModelRow(PDO $db, $requestedModelId = null) {
    $rid = (int)($requestedModelId ?? 0);
    if ($rid > 0) {
        $stmt = $db->prepare("SELECT * FROM blog_ai_models WHERE id = ? AND enabled = 1 LIMIT 1");
        $stmt->execute([$rid]);
        $row = $stmt->fetch();
        if ($row && trim($row['api_key']) !== '') {
            return $row;
        }
        return null;
    }
    $stmt = $db->query("SELECT * FROM blog_ai_models WHERE enabled = 1 AND is_default = 1 ORDER BY id ASC LIMIT 1");
    $row = $stmt ? $stmt->fetch() : false;
    if ($row && trim($row['api_key']) !== '') {
        return $row;
    }
    $stmt = $db->query("SELECT * FROM blog_ai_models WHERE enabled = 1 ORDER BY sort_order, id ASC LIMIT 1");
    $row = $stmt ? $stmt->fetch() : false;
    if ($row && trim($row['api_key']) !== '') {
        return $row;
    }
    return null;
}

/**
 * 生成并写入文章 AI 摘要
 *
 * @return array{success:bool,message:string,summary?:string,hash?:string}
 */
function aiGeneratePostSummary(PDO $db, $postId, $adminId, $requestedModelId = null) {
    aiEnsureSchema($db);

    $settings = aiGetSiteAiSettings($db);
    if (empty($settings['ai_feature_enabled'])) {
        return ['success' => false, 'message' => 'AI 摘要功能已在网站配置中关闭'];
    }

    $stmt = $db->prepare("SELECT id, content FROM blog_posts WHERE id = ?");
    $stmt->execute([(int)$postId]);
    $post = $stmt->fetch();
    if (!$post) {
        return ['success' => false, 'message' => '文章不存在'];
    }

    $model = aiResolveModelRow($db, $requestedModelId);
    if (!$model) {
        return ['success' => false, 'message' => '未找到可用的已启用模型（请检查模型是否填写独立 API Key）'];
    }

    $stripped = aiStripProtectedBlocksForSummary($post['content']);
    $inputText = aiNormalizeFullTextForSummary($stripped);
    if ($inputText === '') {
        return ['success' => false, 'message' => '剥离付费/隐私区块后无可用正文，无法生成摘要'];
    }

    $hash = hash('sha256', $inputText);

    $sys = '你是中文博客编辑。请阅读用户给出的全文（已排除付费与隐私区块），输出完整、连贯的摘要或导读，覆盖全文要点，勿遗漏重要信息；不要使用 Markdown 标题符号单独成行；不要道歉或自称。';
    $user = "以下为文章公开全文：\n\n" . $inputText;

    $requestPayload = json_encode([
        'model' => $model['model_id'],
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'max_tokens' => AI_SUMMARY_MAX_OUTPUT_TOKENS,
        'temperature' => (float)$settings['ai_temperature'],
    ], JSON_UNESCAPED_UNICODE);

    $result = aiOpenAiChatCompletion(
        $model['api_base'],
        $model['api_key'],
        $model['model_id'],
        [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        AI_SUMMARY_MAX_OUTPUT_TOKENS,
        $settings['ai_temperature']
    );

    $pt = isset($result['usage']['prompt_tokens']) ? (int)$result['usage']['prompt_tokens'] : 0;
    $ct = isset($result['usage']['completion_tokens']) ? (int)$result['usage']['completion_tokens'] : 0;
    $tt = isset($result['usage']['total_tokens']) ? (int)$result['usage']['total_tokens'] : ($pt + $ct);
    $responsePayload = json_encode($result, JSON_UNESCAPED_UNICODE);

    $logStmt = $db->prepare("INSERT INTO blog_ai_usage_log (post_id, admin_id, ai_model_id, model_id_str, prompt_tokens, completion_tokens, total_tokens, success, error_message, request_payload, response_payload) VALUES (?,?,?,?,?,?,?,?,?,?,?)");

    if (!$result['ok'] || $result['content'] === null) {
        $err = $result['error'] ?? '未知错误';
        $logStmt->execute([(int)$postId, (int)$adminId, (int)$model['id'], $model['model_id'], $pt, $ct, $tt, 0, $err, $requestPayload, $responsePayload]);
        $db->prepare("UPDATE blog_posts SET ai_summary_error = ? WHERE id = ?")->execute([$err, (int)$postId]);
        return ['success' => false, 'message' => $err];
    }

    $summary = $result['content'];
    $logStmt->execute([(int)$postId, (int)$adminId, (int)$model['id'], $model['model_id'], $pt, $ct, $tt, 1, null, $requestPayload, $responsePayload]);

    $upd = $db->prepare("UPDATE blog_posts SET ai_summary = ?, ai_summary_hash = ?, ai_summary_model_id = ?, ai_summary_at = NOW(), ai_summary_error = NULL WHERE id = ?");
    $upd->execute([$summary, $hash, (int)$model['id'], (int)$postId]);

    return ['success' => true, 'message' => '摘要已生成', 'summary' => $summary, 'hash' => $hash];
}

/**
 * 连通性测试（最小 tokens）
 */
function aiTestModelConnection(array $modelRow) {
    return aiOpenAiChatCompletion(
        $modelRow['api_base'],
        $modelRow['api_key'],
        $modelRow['model_id'],
        [
            ['role' => 'user', 'content' => 'Reply with exactly: ok'],
        ],
        16,
        0
    );
}

function aiSummaryHashStale(PDO $db, array $postRow) {
    if (empty($postRow['content'])) {
        return true;
    }
    $current = aiContentHashForSummary($postRow['content']);
    return empty($postRow['ai_summary_hash']) || $postRow['ai_summary_hash'] !== $current;
}
