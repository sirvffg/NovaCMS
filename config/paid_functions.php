<?php

/**
 * 检查用户是否已支付文章
 */
function hasPaidAccess($db, $userId, $postId) {
    if ($userId <= 0) return false;
    
    // 如果是管理员，直接放行
    try {
        $stmt = $db->prepare("SELECT role FROM admins WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user && $user['role'] === 'admin') {
            return true;
        }
    } catch (PDOException $e) {
        // 忽略不存在的表错误
    }
    
    // 查询支付记录
    $stmt = $db->prepare("SELECT id FROM blog_paid_access WHERE user_id = ? AND post_id = ?");
    $stmt->execute([$userId, $postId]);
    return $stmt->fetch() !== false;
}

/**
 * 处理付费内容
 */
function processPaidContent($db, $userId, $postId, $content) {
    $stmt = $db->prepare("SELECT has_paid_content, post_price FROM blog_posts WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    
    if (!$post || $post['has_paid_content'] == 0) {
        return $content; // 无付费内容，直接返回
    }
    
    $hasAccess = hasPaidAccess($db, $userId, $postId);
    
    if ($hasAccess) {
        $content = str_replace('[Paid]', '', $content);
        $content = str_replace('[/Paid]', '', $content);
        return $content;
    } else {
        // 无权限，替换付费内容
        $processedContent = '';
        $pattern = '/\[Paid\](.*?)\[\/Paid\]/s';
        
        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        for ($i = 0; $i < count($parts); $i++) {
            if ($i % 2 == 0) {
                $processedContent .= $parts[$i];
            } else {
                $price = number_format($post['post_price'], 2);
                if ($userId > 0) {
                    $processedContent .= '<div class="paid-notice" style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <h5 style="color: #856404; margin-top: 0;">💰 付费内容</h5>
                        <p>此内容需要支付 <strong>￥' . $price . '</strong> 后才能查看。</p>
                        <a href="/vendor/public/epay/pay.php?post_id=' . $postId . '" class="btn btn-warning" target="_blank">
                            <i class="bi bi-cart"></i> 立即支付
                        </a>
                    </div>';
                } else {
                    $processedContent .= '<div class="paid-notice" style="background-color: #f8f9fa; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <h5 style="color: #28a745; margin-top: 0;">🔒 登录并支付后可见</h5>
                        <p>此内容需要支付 <strong>￥' . $price . '</strong>，请先登录。</p>
                        <a href="/vendor/login.php?redirect_url=' . urlencode('/blog?id=' . $postId) . '" class="btn btn-success">
                            <i class="bi bi-box-arrow-in-right"></i> 立即登录
                        </a>
                    </div>';
                }
            }
        }
        
        return $processedContent;
    }
}
