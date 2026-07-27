<?php
/**
 * 爱发电数据同步逻辑
 */

function ensureSponsorTable($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS `ifdian_sponsors` (
        `user_id` varchar(64) NOT NULL COMMENT '爱发电用户ID',
        `name` varchar(255) DEFAULT '' COMMENT '昵称',
        `avatar` varchar(500) DEFAULT '' COMMENT '头像URL',
        `all_sum_amount` decimal(10,2) DEFAULT '0.00' COMMENT '累计赞助金额',
        `last_pay_time` int(11) DEFAULT 0 COMMENT '最后支付时间戳',
        `current_plan_id` varchar(64) DEFAULT '' COMMENT '当前方案ID',
        `current_plan_expire_time` int(11) DEFAULT 0 COMMENT '当前方案过期时间',
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`),
        KEY `idx_amount` (`all_sum_amount`),
        KEY `idx_last_pay` (`last_pay_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='爱发电赞助者缓存'");
}

/**
 * 同步赞助者数据到数据库
 * @param PDO $db 数据库连接
 * @param AfdianAPI $afdian API实例
 * @param string|null $specificUserId 指定同步的用户ID，为空则全量同步
 * @return int 同步的数量
 */
function syncSponsors($db, $afdian, $specificUserId = null) {
    ensureSponsorTable($db);
    
    $count = 0;
    $page = 1;
    $perPage = 50; // 每次获取50条
    
    // 准备 SQL 语句
    $stmt = $db->prepare("INSERT INTO ifdian_sponsors 
        (user_id, name, avatar, all_sum_amount, last_pay_time, current_plan_id, current_plan_expire_time) 
        VALUES (?, ?, ?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        name = VALUES(name), 
        avatar = VALUES(avatar), 
        all_sum_amount = VALUES(all_sum_amount), 
        last_pay_time = VALUES(last_pay_time), 
        current_plan_id = VALUES(current_plan_id), 
        current_plan_expire_time = VALUES(current_plan_expire_time)
    ");

    do {
        // 如果指定了用户ID，只查询该用户
        // 注意：querySponsor 的 userId 参数用于筛选
        $res = $afdian->querySponsor($page, $specificUserId, $perPage);
        
        if (empty($res['list'])) {
            break;
        }
        
        foreach ($res['list'] as $item) {
            $user = $item['user'];
            $currentPlan = $item['current_plan'];
            
            $userId = $user['user_id'];
            $name = $user['name'];
            $avatar = $user['avatar'];
            $allSumAmount = $item['all_sum_amount'];
            $lastPayTime = $item['last_pay_time'];
            
            $planId = $currentPlan['plan_id'] ?? '';
            $expireTime = $currentPlan['expire_time'] ?? 0;
            
            $stmt->execute([
                $userId, 
                $name, 
                $avatar, 
                $allSumAmount, 
                $lastPayTime, 
                $planId, 
                $expireTime
            ]);
            
            $count++;
        }
        
        // 如果是指定用户同步，或者当前页不满，说明没有更多了
        if ($specificUserId || count($res['list']) < $perPage) {
            break;
        }
        
        // 检查总页数
        if (isset($res['total_page']) && $page >= $res['total_page']) {
            break;
        }
        
        $page++;
        
    } while (true);
    
    return $count;
}
