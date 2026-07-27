<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

/**
 * 获取赞助者列表 (带数据库缓存)
 */
function getSponsors($db, $config) {
    // 强制刷新参数 (用于调试)
    $forceRefresh = isset($_GET['refresh']);

    // 引入同步逻辑
    require_once __DIR__ . '/api/ifdian/sync.php';
    
    // 确保表存在
    ensureSponsorTable($db);

    // 检查是否需要同步
    $needSync = false;
    
    // 1. 如果强制刷新，或者只是普通访问
    // 只要进入这个页面，就触发同步 (但为了性能，我们可以限制频率，比如10分钟一次)
    $lastUpdate = $db->query("SELECT MAX(updated_at) FROM ifdian_sponsors")->fetchColumn();
    
    if ($forceRefresh) {
        $needSync = true;
    } elseif (empty($lastUpdate) || (time() - strtotime($lastUpdate) > 600)) {
        // 如果数据库为空，或者上次更新超过600秒(10分钟)，则同步
        $needSync = true;
    }
    
    // 如果需要同步且已配置爱发电
    if ($needSync && !empty($config['ifdian_user_id']) && !empty($config['ifdian_api_token'])) {
        require_once __DIR__ . '/api/ifdian/AfdianAPI.php';
        try {
            $afdian = new AfdianAPI($config['ifdian_user_id'], $config['ifdian_api_token']);
            syncSponsors($db, $afdian); // 全量同步
        } catch (Exception $e) {
            // 同步失败，忽略错误继续尝试读库
            error_log("Afdian sync failed: " . $e->getMessage());
        }
    }

    // 从数据库读取赞助者列表
    $sponsors = [];
    
    // 1. 获取方案高级配置
    $planConfigs = [];
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'ifdian_plan_configs'");
        if ($stmt->rowCount() > 0) {
            $rows = $db->query("SELECT plan_id, is_show_in_thanks, show_duration_type, show_duration_value FROM ifdian_plan_configs")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $planConfigs[$row['plan_id']] = [
                    'is_show' => (int)$row['is_show_in_thanks'],
                    'type' => (int)$row['show_duration_type'],
                    'value' => (int)$row['show_duration_value']
                ];
            }
        }
    } catch (Exception $e) {
        // 忽略表不存在错误
    }

    // 2. 查询所有赞助者 (按最后支付时间倒序，方便处理)
    // 注意：数据库里存的是所有曾经赞助过的人，我们需要过滤出有效的
    $stmt = $db->query("SELECT * FROM ifdian_sponsors ORDER BY all_sum_amount DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $now = time();

    foreach ($rows as $item) {
        $userId = $item['user_id'];
        $planId = $item['current_plan_id'];
        $lastPayTime = $item['last_pay_time'];
        $expireTime = $item['current_plan_expire_time']; // 数据库里的 expire_time 是方案本身的过期时间(针对订阅)

        // 默认显示状态
        $isValid = true;
        $expireInfo = '';
        $debugInfo = ''; // 调试信息
        
        // 确定使用的配置 (特定方案 > 默认方案)
        $pConfig = null;
        if (!empty($planId) && isset($planConfigs[$planId])) {
            $pConfig = $planConfigs[$planId];
            $debugInfo = "方案: {$planId} (特定配置)";
        } elseif (isset($planConfigs['default'])) {
            $pConfig = $planConfigs['default'];
            $debugInfo = "方案: " . ($planId ?: '无') . " (默认配置)";
        }

        // 检查全局永久显示阈值
        $permanentEnabled = !empty($config['ifdian_permanent_enable']);
        $permanentThreshold = floatval($config['ifdian_permanent_threshold'] ?? 0);
        $userTotalAmount = floatval($item['all_sum_amount']);
        $isPermanentVip = ($permanentEnabled && $permanentThreshold > 0 && $userTotalAmount >= $permanentThreshold);

        if ($isPermanentVip) {
            $isValid = true;
            $expireInfo = '永久 (VIP)';
            $debugInfo .= " | VIP特权 (总额: {$userTotalAmount} >= 阈值: {$permanentThreshold})";
        } elseif ($pConfig) {
            //如果有配置，则按配置执行
            $typeNames = [0 => '永久', 1 => '按月', 2 => '按年', 3 => '直到过期', 4 => '按天'];
            $typeName = $typeNames[$pConfig['type']] ?? '未知';
            $debugInfo .= " | 规则: {$typeName}, 设定值: {$pConfig['value']}, 最后支付: " . date('Y-m-d', $lastPayTime);
            
            // 规则1：如果配置了“不显示”，直接跳过
            if ($pConfig['is_show'] === 0) {
                continue;
            }
            
            // 规则2：检查时长
            // 0: 永久
            // 1: 按月
            // 2: 按年
            // 3: 直到过期 (仅限订阅)
            // 4: 按天
            if ($pConfig['type'] != 0) {
                $calcEndTime = 0;
                $isSubscription = ($expireTime > 0); // 是否为订阅类(有过期时间)

                if ($pConfig['type'] == 3) {
                    // 直到过期
                    if ($isSubscription) {
                        $calcEndTime = $expireTime;
                        $debugInfo .= ", 订阅过期: " . date('Y-m-d', $calcEndTime);
                    } else {
                        // 如果不是订阅类但选了“直到过期”，视为永久
                        $calcEndTime = 0; 
                    }
                } else {
                    // 按时间长度计算
                    $durationSeconds = 0;
                    switch ($pConfig['type']) {
                        case 1: // 按月 (30天)
                            $durationSeconds = $pConfig['value'] * 30 * 86400;
                            break;
                        case 2: // 按年 (365天)
                            $durationSeconds = $pConfig['value'] * 365 * 86400;
                            break;
                        case 4: // 按天
                            $durationSeconds = $pConfig['value'] * 86400;
                            break;
                    }
                    
                    // 计算过期时间：从最后一次支付时间开始算
                    if ($durationSeconds > 0) {
                        $calcEndTime = $lastPayTime + $durationSeconds;
                        $debugInfo .= ", 计算截止: " . date('Y-m-d', $calcEndTime);
                    }
                }
                
                // 检查是否过期
                if ($calcEndTime > 0) {
                    if ($calcEndTime > $now) {
                        $days = ceil(($calcEndTime - $now) / 86400);
                        $expireInfo = "剩余 {$days} 天";
                    } else {
                        $days = floor(($now - $calcEndTime) / 86400);
                        $debugInfo .= " [已过期 {$days} 天]";
                        $isValid = false; // 已过期则不显示
                        
                        // 调试模式下即使过期也显示，方便排查
                        if (isset($_GET['debug'])) {
                            $isValid = true;
                            $expireInfo = "已过期 {$days} 天 (DEBUG)";
                        }
                    }
                }
            }
        } else {
            // 如果没有任何配置，默认永久显示
            $debugInfo = "无配置 (默认永久)";
        }
        
        if ($isValid) {
            $sponsors[] = [
                'name' => $item['name'],
                'avatar' => $item['avatar'],
                'user_id' => $item['user_id'],
                'all_sum_amount' => $item['all_sum_amount'],
                'last_pay_time' => $lastPayTime,
                'expire_info' => $expireInfo,
                'debug_info' => isset($_GET['debug']) ? $debugInfo : ''
            ];
        }
    }
    
    return $sponsors;
}

/**
 * 获取手动鸣谢列表
 */
function getManualSponsors($db) {
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'ifdian_manual_sponsors'");
        if ($stmt->rowCount() == 0) return [];
        return $db->query("SELECT * FROM ifdian_manual_sponsors ORDER BY sort_order ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 获取所有赞助历史 (无过滤)
 */
function getHistorySponsors($db) {
    try {
        return $db->query("SELECT * FROM ifdian_sponsors ORDER BY all_sum_amount DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

$sponsors = getSponsors($db, $config);
$manualSponsors = getManualSponsors($db);
$historySponsors = getHistorySponsors($db);

recordVisit($_SERVER['REQUEST_URI']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>特别鸣谢 - <?= e($config['website_name']) ?></title>
    
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        body {
            background: url('https://api.fuchenboke.cn/api/dongman.php') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.1);
            z-index: -1;
        }

        /* Navbar styles */
        .navbar.fixed-top {
            transition: all 0.3s ease-in-out;
            background: rgba(255, 255, 255, 0.85) !important;
            backdrop-filter: blur(12px);
            border-bottom: 1px solid #dee2e6 !important;
        }
        
        .container-wrapper {
            padding-top: 100px;
            padding-bottom: 40px;
            flex: 1;
        }

        .thanks-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .thanks-section {
            margin-bottom: 40px;
        }

        .thanks-title {
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
            color: #333;
            font-weight: 600;
        }

        .contributor-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 20px;
        }

        .contributor-item {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid #eee;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .contributor-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            border-color: #667eea;
        }

        .contributor-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            margin-bottom: 15px;
            object-fit: cover;
            border: 3px solid #f8f9fa;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .contributor-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            font-size: 1.1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .contributor-amount {
            color: #667eea;
            font-weight: 500;
            font-size: 0.9rem;
        }

        /* 奖牌样式 */
        .medal {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 1.5rem;
        }
        
        .medal-1 { color: #FFD700; text-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        .medal-2 { color: #C0C0C0; text-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        .medal-3 { color: #CD7F32; text-shadow: 0 2px 4px rgba(0,0,0,0.2); }

        .heart-icon {
            color: #ff4757;
            animation: beat 1.5s infinite;
        }

        @keyframes beat {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        /* Footer adjustments */
        footer {
            background-color: #ffffff !important;
            margin-top: auto;
        }
        
        /* Tab Styles */
        .nav-pills .nav-link {
            color: #495057; /* 加深未激活状态的文字颜色 */
            background-color: #e9ecef; /* 加深未激活状态的背景颜色 */
            margin-right: 10px;
            border-radius: 50px;
            padding: 10px 25px;
            font-weight: 600; /* 加粗文字 */
            transition: all 0.3s;
            border: 1px solid #dee2e6; /* 增加边框增加辨识度 */
        }
        
        .nav-pills .nav-link.active {
            background-color: #ff4757;
            color: #fff;
            box-shadow: 0 4px 10px rgba(255, 71, 87, 0.3);
            border-color: #ff4757;
        }
        
        .nav-pills .nav-link:hover:not(.active) {
            background-color: #dee2e6; /* hover时更深一点 */
            color: #212529;
            border-color: #ced4da;
        }

        /* 移动端优化 */
        @media (max-width: 768px) {
            .nav-pills {
                /* flex-wrap: nowrap; */ /* 取消不换行，允许内容根据宽度调整 */
                /* overflow-x: auto; */ /* 取消横向滚动 */
                justify-content: space-between; /* 均匀分布 */
                padding-bottom: 0;
            }
            .nav-pills .nav-item {
                flex: 1 1 auto; /* 让每个标签项自动占据空间 */
                text-align: center;
                padding: 0 2px; /* 减小间距 */
            }
            .nav-pills .nav-link {
                margin-right: 0;
                padding: 8px 5px; /* 减小内边距以适应屏幕 */
                font-size: 0.85rem; /* 稍微减小字体 */
                white-space: nowrap;
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            /* 隐藏图标以节省空间，或者调整图标大小 */
            .nav-pills .nav-link i {
                display: none; /* 如果空间实在不够，可以隐藏图标 */
            }
        }
    </style>
</head>
<body class="d-flex flex-column">
    
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light fixed-top">
        <div class="container">
            <a class="navbar-brand" href="/">
                <i class="bi bi-gift-fill text-primary me-2"></i>
                <span>特别鸣谢 | <?= e($config['website_name']) ?></span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="/">首页</a></li>
                    <li class="nav-item"><a class="nav-link" href="/blog.php">博客</a></li>
                    <li class="nav-item"><a class="nav-link" href="/vendor/shuoshuo.php">说说</a></li>
                    <li class="nav-item"><a class="nav-link" href="/vendor/gallery.php">相册</a></li>
                    <li class="nav-item"><a class="nav-link" href="/vendor/guestbook.php">留言板</a></li>
                    <li class="nav-item"><a class="nav-link active text-danger" href="/vendor/thanks.php">🎁 特别鸣谢</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container container-wrapper">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="thanks-card">
                    <div class="text-center mb-5">
                        <i class="bi bi-heart-fill heart-icon display-1"></i>
                        <h1 class="mt-3">致谢名单</h1>
                        <p class="text-muted">特别鸣谢</p>
                        <p class="lead mt-3">感谢每一位支持本站发展的朋友，是你们的陪伴让这里变得更好。</p>
                        
                        <?php if (!empty($config['ifdian_username'])): ?>
                        <div class="mt-4">
                            <a href="https://ifdian.net/a/<?= e($config['ifdian_username']) ?>" target="_blank" class="btn btn-danger btn-lg rounded-pill px-4 shadow-sm">
                                <i class="bi bi-lightning-charge-fill me-2"></i>成为赞助者
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Tabs -->
                    <ul class="nav nav-pills justify-content-center mb-5" id="thanksTab" role="tablist">
                        <?php 
                        $showSponsor = $config['ifdian_show_tab_sponsor'] ?? 1;
                        $showManual = $config['ifdian_show_tab_manual'] ?? 1;
                        $showHistory = $config['ifdian_show_tab_history'] ?? 1;
                        
                        // 确定默认激活的标签页
                        $activeTab = '';
                        if ($showSponsor) $activeTab = 'sponsor';
                        elseif ($showManual) $activeTab = 'manual';
                        elseif ($showHistory) $activeTab = 'history';
                        ?>

                        <?php if ($showSponsor): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $activeTab == 'sponsor' ? 'active' : '' ?>" id="pills-sponsor-tab" data-bs-toggle="pill" data-bs-target="#pills-sponsor" type="button" role="tab">
                                <i class="bi bi-star-fill me-1"></i> 赞助支持
                            </button>
                        </li>
                        <?php endif; ?>

                        <?php if ($showManual): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $activeTab == 'manual' ? 'active' : '' ?>" id="pills-manual-tab" data-bs-toggle="pill" data-bs-target="#pills-manual" type="button" role="tab">
                                <i class="bi bi-award-fill me-1"></i> 项目建设者
                            </button>
                        </li>
                        <?php endif; ?>

                        <?php if ($showHistory): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $activeTab == 'history' ? 'active' : '' ?>" id="pills-history-tab" data-bs-toggle="pill" data-bs-target="#pills-history" type="button" role="tab">
                                <i class="bi bi-clock-history me-1"></i> 赞助历史记录
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <div class="tab-content" id="thanksTabContent">
                        
                        <!-- Tab 1: 赞助支持 (爱发电活跃) -->
                        <?php if ($showSponsor): ?>
                        <div class="tab-pane fade <?= $activeTab == 'sponsor' ? 'show active' : '' ?>" id="pills-sponsor" role="tabpanel">
                            <div class="thanks-section">
                                <?php if (!empty($sponsors)): ?>
                                    <div class="contributor-list">
                                        <?php foreach ($sponsors as $index => $sponsor): ?>
                                        <div class="contributor-item">
                                            <img src="<?= e($sponsor['avatar']) ?>" alt="<?= e($sponsor['name']) ?>" class="contributor-avatar" onerror="this.src='/assets/img/default-avatar.png'">
                                            <div class="contributor-name"><?= e($sponsor['name']) ?></div>
                                            
                                            <?php if (!empty($sponsor['debug_info'])): ?>
                                            <div class="small text-danger mt-1 border-top pt-1" style="font-size: 0.7rem; word-break: break-all;">
                                                DEBUG: <?= e($sponsor['debug_info']) ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="text-center mt-4 text-muted small">
                                        * 数据来自爱发电，实时同步
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-light border text-center p-4">
                                        <p class="mb-0 text-muted">暂无公开赞助名单，期待您的支持！</p>
                                        <?php if (empty($config['ifdian_user_id'])): ?>
                                        <p class="small text-muted mt-2">(管理员尚未配置爱发电信息)</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Tab 2: 特别鸣谢 (手动) -->
                        <?php if ($showManual): ?>
                        <div class="tab-pane fade <?= $activeTab == 'manual' ? 'show active' : '' ?>" id="pills-manual" role="tabpanel">
                            <div class="thanks-section">
                                <?php if (!empty($manualSponsors)): ?>
                                    <div class="contributor-list">
                                        <?php foreach ($manualSponsors as $item): ?>
                                        <div class="contributor-item">
                                            <?php if (!empty($item['link'])): ?>
                                                <a href="<?= e($item['link']) ?>" target="_blank" class="text-decoration-none">
                                            <?php endif; ?>
                                            
                                            <?php
                                            $avatarUrl = $item['avatar'];
                                            if (empty($avatarUrl) && !empty($item['qq'])) {
                                                // 如果没有设置头像但有QQ号，自动使用QQ头像
                                                $avatarUrl = "https://q1.qlogo.cn/g?b=qq&nk={$item['qq']}&s=100";
                                            }
                                            if (empty($avatarUrl)) {
                                                $avatarUrl = '/assets/img/default-avatar.png';
                                            }
                                            ?>
                                            <img src="<?= e($avatarUrl) ?>" alt="<?= e($item['name']) ?>" class="contributor-avatar" onerror="this.src='/assets/img/default-avatar.png'">
                                            <div class="contributor-name"><?= e($item['name']) ?></div>
                                            
                                            <?php 
                                            $description = $item['description'];
                                            if (empty($description)) {
                                                // 随机选择一个默认描述
                                                $defaultDescriptions = [
                                                    '项目建设',
                                                    '贡献力量',
                                                    '一路相伴',
                                                    '重要伙伴',
                                                    '见证成长',
                                                    '用爱发电'
                                                ];
                                                // 使用 id 或 name 作为种子，保持同一个人的描述固定
                                                $seed = crc32($item['name'] . $item['id']);
                                                $description = $defaultDescriptions[$seed % count($defaultDescriptions)];
                                            }
                                            ?>
                                            <div class="small text-muted mt-2"><?= e($description) ?></div>
                                            
                                            <?php if (!empty($item['link'])): ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-light border text-center p-4">
                                        <p class="mb-0 text-muted">暂无特别鸣谢记录。</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Tab 3: 赞助历史记录 (全部) -->
                        <?php if ($showHistory): ?>
                        <div class="tab-pane fade <?= $activeTab == 'history' ? 'show active' : '' ?>" id="pills-history" role="tabpanel">
                            <div class="thanks-section">
                                <?php if (!empty($historySponsors)): ?>
                                    <div class="contributor-list">
                                        <?php foreach ($historySponsors as $item): ?>
                                        <div class="contributor-item">
                                            <img src="<?= e($item['avatar']) ?>" alt="<?= e($item['name']) ?>" class="contributor-avatar" onerror="this.src='/assets/img/default-avatar.png'">
                                            <div class="contributor-name"><?= e($item['name']) ?></div>
                                            <div class="contributor-amount">
                                                共赞助 ¥<?= e($item['all_sum_amount']) ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="text-center mt-4 text-muted small">
                                        * 显示所有曾提供支持的朋友
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-light border text-center p-4">
                                        <p class="mb-0 text-muted">暂无历史记录。</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                    </div>

                    <div class="text-center mt-5 pt-4 border-top">
                        <p class="text-muted">排名不分先后，感谢有你。</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/footer.php'; ?>
    
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar.fixed-top');
            if (window.scrollY > 10) {
                navbar.classList.add('shadow-sm');
                navbar.style.background = 'rgba(255, 255, 255, 0.95)';
            } else {
                navbar.classList.remove('shadow-sm');
                navbar.style.background = 'rgba(255, 255, 255, 0.85)';
            }
        });
    </script>
</body>
</html>
