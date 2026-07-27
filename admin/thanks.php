<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 获取赞助者列表 (带缓存)
function getSponsors($db, $config) {
    $cacheFile = __DIR__ . '/../config/cache/ifdian_sponsors.json';
    $cacheTime = 3600; // 缓存1小时

    // 检查缓存
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    // 如果未配置爱发电，直接返回空
    if (empty($config['ifdian_user_id']) || empty($config['ifdian_api_token'])) {
        return [];
    }

    require_once __DIR__ . '/api/ifdian/AfdianAPI.php';
    
    try {
        $afdian = new AfdianAPI($config['ifdian_user_id'], $config['ifdian_api_token']);
        
        // 获取方案配置
        // 尝试查询 ifdian_plan_configs 表，如果表不存在则忽略（默认显示）
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

        // 获取赞助者 (获取前5页，共100条)
        $sponsors = [];
        for ($page = 1; $page <= 5; $page++) {
            $res = $afdian->querySponsor($page, '', 20);
            if (!empty($res['list'])) {
                foreach ($res['list'] as $item) {
                    $user = $item['user'];
                    $currentPlan = $item['current_plan'];
                    $allSumAmount = $item['all_sum_amount'];
                    $lastPayTime = $item['last_pay_time'];
                    
                    // 过滤逻辑：
                    // 如果有当前方案，检查配置
                    if (!empty($currentPlan['plan_id']) && isset($planConfigs[$currentPlan['plan_id']])) {
                        $config = $planConfigs[$currentPlan['plan_id']];
                        
                        // 1. 检查是否显示
                        if ($config['is_show'] === 0) {
                            continue;
                        }
                        
                        // 2. 检查时长
                        if ($config['type'] != 0) { // 0为永久，不需要检查
                            $now = time();
                            $isValid = true;
                            
                            switch ($config['type']) {
                                case 1: // 按月
                                    if ($now - $lastPayTime > $config['value'] * 30 * 86400) $isValid = false;
                                    break;
                                case 2: // 按年
                                    if ($now - $lastPayTime > $config['value'] * 365 * 86400) $isValid = false;
                                    break;
                                case 3: // 直到过期
                                    if (!empty($currentPlan['expire_time']) && $now > $currentPlan['expire_time']) $isValid = false;
                                    break;
                                case 4: // 按天
                                    if ($now - $lastPayTime > $config['value'] * 86400) $isValid = false;
                                    break;
                            }
                            
                            if (!$isValid) continue;
                        }
                    }
                    
                    // 计算剩余时间提示
                    $expireInfo = '';
                    if (!empty($currentPlan['plan_id']) && isset($planConfigs[$currentPlan['plan_id']])) {
                        $config = $planConfigs[$currentPlan['plan_id']];
                        if ($config['type'] != 0) {
                            $now = time();
                            $endTime = 0;
                            
                            switch ($config['type']) {
                                case 1: // 按月
                                    $endTime = $lastPayTime + $config['value'] * 30 * 86400;
                                    break;
                                case 2: // 按年
                                    $endTime = $lastPayTime + $config['value'] * 365 * 86400;
                                    break;
                                case 3: // 直到过期
                                    $endTime = $currentPlan['expire_time'];
                                    break;
                                case 4: // 按天
                                    $endTime = $lastPayTime + $config['value'] * 86400;
                                    break;
                            }
                            
                            if ($endTime > $now) {
                                $days = ceil(($endTime - $now) / 86400);
                                $expireInfo = "剩余 {$days} 天";
                            }
                        }
                    }

                    // 构造数据
                    $sponsors[] = [
                        'name' => $user['name'],
                        'avatar' => $user['avatar'],
                        'user_id' => $user['user_id'],
                        'all_sum_amount' => $allSumAmount,
                        'last_pay_time' => $lastPayTime,
                        'expire_info' => $expireInfo
                    ];
                }
                
                // 如果当前页不满20条，说明没有更多数据了
                if (count($res['list']) < 20) break;
            } else {
                break;
            }
        }
        
        // 按赞助金额倒序排序
        usort($sponsors, function($a, $b) {
            return $b['all_sum_amount'] - $a['all_sum_amount'];
        });

        // 写入缓存
        file_put_contents($cacheFile, json_encode($sponsors));
        
        return $sponsors;
    } catch (Exception $e) {
        // 出错返回空，或者尝试读取旧缓存
        if (file_exists($cacheFile)) {
            return json_decode(file_get_contents($cacheFile), true);
        }
        return [];
    }
}

$sponsors = getSponsors($db, $config);

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
    
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.staticfile.net/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
    
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
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link" href="/"><i class="bi bi-house me-1"></i> 首页</a>
                    </li>
                    <li class="nav-item ms-lg-2">
                        <a class="btn btn-primary btn-sm rounded-pill px-3" href="/vendor/guestbook.php">
                            <i class="bi bi-chat-dots me-1"></i> 留言
                        </a>
                    </li>
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
                            <a href="https://afdian.net/a/<?= e($config['ifdian_username']) ?>" target="_blank" class="btn btn-danger btn-lg rounded-pill px-4 shadow-sm">
                                <i class="bi bi-lightning-charge-fill me-2"></i>成为赞助者
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sponsors Section -->
                    <div class="thanks-section">
                        <h3 class="thanks-title"><i class="bi bi-star-fill text-warning me-2"></i> 赞助支持</h3>
                        
                        <?php if (!empty($sponsors)): ?>
                            <div class="contributor-list">
                                <?php foreach ($sponsors as $index => $sponsor): ?>
                                <div class="contributor-item">
                                    <?php if ($index === 0): ?><i class="bi bi-trophy-fill medal medal-1"></i><?php endif; ?>
                                    <?php if ($index === 1): ?><i class="bi bi-trophy-fill medal medal-2"></i><?php endif; ?>
                                    <?php if ($index === 2): ?><i class="bi bi-trophy-fill medal medal-3"></i><?php endif; ?>
                                    
                                    <img src="<?= e($sponsor['avatar']) ?>" alt="<?= e($sponsor['name']) ?>" class="contributor-avatar" onerror="this.src='/assets/img/default-avatar.png'">
                                    <div class="contributor-name"><?= e($sponsor['name']) ?></div>
                                    <div class="contributor-amount">累计赞助 ¥<?= e($sponsor['all_sum_amount']) ?></div>
                                    <?php if (!empty($sponsor['expire_info'])): ?>
                                    <div class="small text-muted mt-2" style="font-size: 0.8rem;">
                                        <i class="bi bi-clock-history me-1"></i><?= e($sponsor['expire_info']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-center mt-4 text-muted small">
                                * 数据来自爱发电，每小时更新一次
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

                    <!-- Contributors Section -->
                    <div class="thanks-section">
                        <h3 class="thanks-title"><i class="bi bi-code-slash text-info me-2"></i> 技术贡献</h3>
                        <div class="text-center p-3">
                            <p>感谢为本站提供技术支持和建议的朋友们。</p>
                            <!-- 可以在这里手动添加技术贡献者 -->
                        </div>
                    </div>
                    
                    <!-- Inspiration Section -->
                    <div class="thanks-section">
                        <h3 class="thanks-title"><i class="bi bi-lightbulb-fill text-success me-2"></i> 灵感来源</h3>
                         <div class="list-group list-group-flush">
                            <div class="list-group-item bg-transparent border-0">
                                <i class="bi bi-arrow-right-circle me-2 text-primary"></i> 感谢开源社区提供的优秀工具
                            </div>
                         </div>
                    </div>

                    <div class="text-center mt-5 pt-4 border-top">
                        <p class="text-muted">排名不分先后，感谢有你。</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/footer.php'; ?>
    
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
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