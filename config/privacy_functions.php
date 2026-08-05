<?php
/**
 * 处理博客隐私内容访问的函数
 */

/**
 * 检查用户是否为管理员
 * @param PDO $db 数据库连接
 * @param int $userId 用户ID
 * @return bool 是否为管理员
 */
function privacyAnswerLength($value) {
    $value = (string)$value;
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

function isAdmin($db, $userId) {
    if (!$userId) return false;
    
    $stmt = $db->prepare("SELECT role FROM admins WHERE id = ? AND role = 'admin'");
    $stmt->execute([$userId]);
    return $stmt->fetch() !== false;
}

/**
 * 检查用户是否已有权限查看隐私内容
 * @param PDO $db 数据库连接
 * @param int $userId 用户ID
 * @param int $postId 文章ID
 * @return bool 是否有权限
 */
function hasPrivacyAccess($db, $userId, $postId) {
    if (!$userId || !$postId) return false;
    
    // 管理员无需授权即可查看所有隐私内容
    if (isAdmin($db, $userId)) {
        return true;
    }
    
    // 检查文章的隐私类型
    $stmt = $db->prepare("SELECT privacy_type FROM blog_posts WHERE id = ? AND has_privacy_content = 1");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    
    // 如果是仅需登录即可查看的类型
    if ($post && $post['privacy_type'] === 'login_only') {
        return true;
    }
    
    $stmt = $db->prepare("SELECT id FROM blog_privacy_access 
                          WHERE user_id = ? AND post_id = ? AND access_granted = 1 
                          LIMIT 1");
    $stmt->execute([$userId, $postId]);
    return $stmt->fetch() !== false;
}

/**
 * 验证用户提交的答案
 * @param PDO $db 数据库连接
 * @param int $userId 用户ID
 * @param int $postId 文章ID
 * @param string $answer 用户提交的答案
 * @return array 返回验证结果和访问状态
 */
function validatePrivacyAnswer($db, $userId, $postId, $answer) {
    if (!$userId || !$postId || trim((string)$answer) === '') {
        return ['success' => false, 'message' => '参数错误'];
    }
    if (privacyAnswerLength($answer) > 255) {
        return ['success' => false, 'message' => '回答不能超过 255 个字符'];
    }
    
    // 获取文章的隐私设置
    $stmt = $db->prepare("SELECT privacy_answer, privacy_type, approval_required FROM blog_posts WHERE id = ? AND has_privacy_content = 1");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    
    if (!$post) {
        return ['success' => false, 'message' => '文章不存在或无隐私内容'];
    }
    
    $isCorrect = 0;
    $accessGranted = 0;
    $success = true;
    $message = '';
    
    // 根据验证类型处理
    switch ($post['privacy_type']) {
        case 'fixed_answer':
            // 固定答案验证
            $hashedAnswer = md5(strtolower(trim($answer)));
            $isCorrect = $hashedAnswer === $post['privacy_answer'] ? 1 : 0;
            $accessGranted = $isCorrect;
            $success = (bool)$isCorrect;
            $message = $isCorrect ? '答案正确，您现在可以查看隐私内容' : '答案错误，请重试';
            break;
            
        case 'open_answer':
            // 开放答案处理
            if ($post['approval_required'] == 1) {
                // 需要管理员审核
                $isCorrect = 0; // 暂不标记为正确，等待审核
                $accessGranted = 0;
                $message = '您的答案已提交，管理员审核后即可查看隐私内容';
            } else {
                // 自动授权
                $isCorrect = 1;
                $accessGranted = 1;
                $message = '感谢回答，您现在可以查看隐私内容';
            }
            break;
            
        case 'manual_approval':
            // 人工审核
            $isCorrect = 0;
            $accessGranted = 0;
            $message = '您的申请已提交，管理员审核后即可查看隐私内容';
            break;
            
        default:
            return ['success' => false, 'message' => '未知的验证类型'];
    }
    
    // 记录用户的访问尝试
    // 存储原始答案，而不是MD5哈希值，以便管理员查看
    $stmt = $db->prepare("INSERT INTO blog_privacy_access 
                         (user_id, post_id, answer, is_correct, access_granted) 
                         VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $postId, $answer, $isCorrect, $accessGranted]);
    
    $pendingApproval = $accessGranted == 0 && (
        $post['privacy_type'] === 'manual_approval'
        || ($post['privacy_type'] === 'open_answer' && $post['approval_required'] == 1)
    );

    return [
        'success' => $success,
        'message' => $message,
        'access_granted' => (bool)$accessGranted,
        'pending_approval' => $pendingApproval,
    ];
}

/**
 * 处理博客内容，根据权限过滤隐私内容
 * @param PDO $db 数据库连接
 * @param int $userId 用户ID
 * @param int $postId 文章ID
 * @param string $content 原始内容
 * @return string 处理后的内容
 */
function processBlogContent($db, $userId, $postId, $content) {
    // 检查文章是否有隐私内容
    $stmt = $db->prepare("SELECT has_privacy_content, privacy_type, privacy_custom_text FROM blog_posts WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    
    if (!$post || $post['has_privacy_content'] == 0) {
        return $content; // 无隐私内容，直接返回
    }
    
    // 检查用户是否有权限
    $hasAccess = hasPrivacyAccess($db, $userId, $postId);
    
    // 检查用户是否已提交但等待审核
    $isPendingApproval = false;
    if (!$hasAccess && $userId > 0) {
        $stmt2 = $db->prepare("SELECT id, access_granted FROM blog_privacy_access 
                              WHERE user_id = ? AND post_id = ? 
                              ORDER BY created_at DESC LIMIT 1");
        $stmt2->execute([$userId, $postId]);
        $existingAccess = $stmt2->fetch();
        
        if ($existingAccess && $existingAccess['access_granted'] == 0) {
            $isManualApproval = $post['privacy_type'] == 'manual_approval';
            $isOpenAnswerWithApproval = $post['privacy_type'] == 'open_answer';
            if ($isManualApproval || $isOpenAnswerWithApproval) {
                $isPendingApproval = true;
            }
        }
    }
    
    if ($hasAccess) {
        // 有权限，移除所有隐私标记，显示完整内容
        $content = str_replace('[Privacy]', '', $content);
        $content = str_replace('[/Privacy]', '', $content);
        return $content;
    } else {
        // 无权限，查找所有隐私内容区域并替换为提示信息
        $processedContent = '';
        $pattern = '/\[Privacy\](.*?)\[\/Privacy\]/s';
        
        // 分割内容，保留隐私区域前后的文本
        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        // 处理自定义提示内容中的彩色标签
        $customText = '';
        if (!empty($post['privacy_custom_text'])) {
            $customText = processColorTags($post['privacy_custom_text']);
        }
        
        for ($i = 0; $i < count($parts); $i++) {
            if ($i % 2 == 0) {
                // 非隐私内容，直接添加
                $processedContent .= $parts[$i];
            } else {
                if ($isPendingApproval) {
                    // 已提交但等待审核
                    $processedContent .= '<div class="privacy-notice" style="background-color: #f8f9fa; border-left: 4px solid #0d6efd; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <h5 style="color: #0d6efd; margin-top: 0;">🔒 隐私内容</h5>
                        <p>您的申请已提交，正在等待管理员审核。审核通过后您即可查看此内容。</p>' .
                        ($customText ? '<div style="margin-top: 10px; padding: 10px; background: rgba(13,110,253,0.08); border-radius: 4px; font-size: 14px;">' . $customText . '</div>' : '') .
                        '<button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#privacyAccessModal">
                            <i class="bi bi-hourglass-split"></i> 查看审核状态
                        </button>
                    </div>';
                } elseif ($post['privacy_type'] === 'login_only') {
                    // 仅需登录即可查看
                    $processedContent .= '<div class="privacy-notice" style="background-color: #f8f9fa; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <h5 style="color: #28a745; margin-top: 0;">🔒 登录可见内容</h5>
                        <p>此内容需要登录后才能查看。</p>' .
                        ($customText ? '<div style="margin-top: 10px; padding: 10px; background: rgba(40,167,69,0.08); border-radius: 4px; font-size: 14px;">' . $customText . '</div>' : '') .
                        '<a href="/vendor/login.php?redirect_url=' . urlencode('/blog?id=' . $postId) . '" class="btn btn-success">
                            <i class="bi bi-box-arrow-in-right"></i> 立即登录
                        </a>
                    </div>';
                } else {
                    // 需要回答问题的隐私内容
                    $processedContent .= '<div class="privacy-notice" style="background-color: #f8f9fa; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <h5 style="color: #007bff; margin-top: 0;">🔒 隐私内容</h5>
                        <p>此内容需要登录并回答问题才能查看。</p>' .
                        ($customText ? '<div style="margin-top: 10px; padding: 10px; background: rgba(0,123,255,0.08); border-radius: 4px; font-size: 14px;">' . $customText . '</div>' : '') .
                        '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#privacyAccessModal">
                            申请访问权限
                        </button>
                    </div>';
                }
            }
        }
        
        return $processedContent;
    }
}

/**
 * 获取文章的隐私问题
 * @param PDO $db 数据库连接
 * @param int $postId 文章ID
 * @return array|null 隐私设置数组
 */
function getPrivacySettings($db, $postId) {
    $stmt = $db->prepare("SELECT privacy_question, privacy_type, approval_required, privacy_custom_text FROM blog_posts WHERE id = ? AND has_privacy_content = 1");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    
    if (!$post) return null;
    
    // 对于login_only类型，提供默认问题
    $question = $post['privacy_question'];
    if ($post['privacy_type'] === 'login_only' && empty($question)) {
        $question = '此内容需要登录后才能查看';
    }
    
    return [
        'question' => $question,
        'type' => $post['privacy_type'],
        'approval_required' => $post['approval_required'],
        'custom_text' => $post['privacy_custom_text'] ?? ''
    ];
}

/**
 * 获取文章的隐私问题文本
 * @param PDO $db 数据库连接
 * @param int $postId 文章ID
 * @return string|null 隐私问题文本
 */
function getPrivacyQuestion($db, $postId) {
    $stmt = $db->prepare("SELECT privacy_question FROM blog_posts WHERE id = ? AND has_privacy_content = 1");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    
    return $post ? $post['privacy_question'] : null;
}

/**
 * 处理彩色文字标签，将 <color:xxx>文字</color> 转换为 <span style="color:xxx">文字</span>
 * @param string $text 原始文本
 * @return string 处理后的HTML
 */
function processColorTags($text) {
    $colorNames = [
        'red' => '#e74c3c', 'blue' => '#3498db', 'green' => '#2ecc71', 'orange' => '#e67e22',
        'purple' => '#9b59b6', 'pink' => '#e91e63', 'yellow' => '#f1c40f', 'cyan' => '#00bcd4',
        'white' => '#ffffff', 'black' => '#333333', 'gray' => '#95a5a6', 'brown' => '#8b4513',
        'gold' => '#ffd700', 'indigo' => '#3f51b5', 'teal' => '#009688', 'lime' => '#8bc34a',
        'coral' => '#ff7f50', 'salmon' => '#fa8072', 'crimson' => '#dc143c', 'navy' => '#000080'
    ];
    
    // 先处理彩色标签，再转换换行符
    $result = preg_replace_callback('/<color:([^>]+)>(.*?)<\/color>/si', function($matches) use ($colorNames) {
        $color = $matches[1];
        $text = $matches[2];
        $resolvedColor = isset($colorNames[strtolower($color)]) ? $colorNames[strtolower($color)] : $color;
        return '<span style="color:' . $resolvedColor . ';font-weight:inherit">' . $text . '</span>';
    }, $text);
    
    // 将换行符转换为 <br>
    $result = nl2br($result, false);
    
    return $result;
}
