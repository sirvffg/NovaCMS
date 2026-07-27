<?php
/**
 * 仪表盘统计数据控制器
 * 用于获取后台首页所需的各类统计数据
 */

// 基础统计数据
function getDashboardStats($db) {
    $stats = [];
    
    // 网站配置
    $stats['config'] = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
    
    // 1. 基础总数
    $stats['totalVisits'] = getTotalVisits();
    $stats['uniqueVisitors'] = getUniqueVisitors();
    $stats['totalPosts'] = $db->query("SELECT COUNT(*) as total FROM blog_posts")->fetch()['total'];
    $stats['totalComments'] = $db->query("SELECT COUNT(*) as total FROM blog_comments")->fetch()['total'];
    // 获取匿名撤回用户的ID
    $anonUser = $db->query("SELECT id FROM admins WHERE username = '隐私表单重新填写' LIMIT 1")->fetch();
    $anonUserId = $anonUser ? (int)$anonUser['id'] : -1;
    
    $stats['totalForms'] = $db->query("SELECT COUNT(*) as total FROM blog_privacy_access WHERE user_id != $anonUserId")->fetch()['total'];
    
    // 待办事项：待审核的表单（排除已撤回）
    $stats['pendingForms'] = $db->query("
        SELECT COUNT(*) as total 
        FROM blog_privacy_access p 
        JOIN blog_posts b ON p.post_id = b.id 
        WHERE p.access_granted = 0 
        AND (b.privacy_type = 'open_answer' OR b.privacy_type = 'manual_approval')
        AND p.user_id != $anonUserId
    ")->fetch()['total'];
    
    // 2. 访问统计图表数据
    // 最近7天的访问量
    $stats['recentVisits'] = getVisitStats(7);
    
    // 页面访问统计
    $stats['pageStats'] = getPageVisitStats(7, 15);
    
    // 访问趋势（用于图表）
    $stats['visitTrends'] = getVisitTrends(7);
    
    // 热门页面（总排行）
    $stats['popularPages'] = getPopularPages(20);
    
    // 3. 今日实时概览
    $stats['todayVisits'] = $db->query("SELECT COUNT(*) as total FROM visit_stats WHERE DATE(visit_time) = CURDATE()")->fetch()['total'];
    $stats['todayUnique'] = $db->query("SELECT COUNT(DISTINCT ip_address) as total FROM visit_stats WHERE DATE(visit_time) = CURDATE()")->fetch()['total'];
    
    return $stats;
}
