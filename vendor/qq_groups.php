<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// 记录访问
recordVisit($_SERVER['REQUEST_URI']);

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 获取所有群组，按排序（只显示 is_show=1 的群组）
try {
    // 检查表是否存在
    $db->query("SELECT 1 FROM qq_groups LIMIT 1");
    // 检查是否有 is_show 字段
    try {
        $db->query("SELECT is_show FROM qq_groups LIMIT 1");
        $groups = $db->query("SELECT * FROM qq_groups WHERE is_show = 1 ORDER BY sort_order ASC, id DESC")->fetchAll();
    } catch (PDOException $e) {
        // is_show 字段不存在，返回所有群组
        $groups = $db->query("SELECT * FROM qq_groups ORDER BY sort_order ASC, id DESC")->fetchAll();
    }
} catch (PDOException $e) {
    $groups = [];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QQ群聊 - <?= e($config['website_name']) ?></title>
    
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/harmonyos-sans.css" rel="stylesheet">
    <style>
        :root {
            --bg-transition-duration: 1s;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            /* 移除之前的渐变背景 */
            background: #333;
            min-height: 100vh;
            font-family: 'HarmonyOS Sans', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            position: relative;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
        }

        .page-content {
            flex: 1 0 auto;
            position: relative;
            z-index: 1;
        }
        
        /* 背景层容器 */
        .bg-layer {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            transition: opacity var(--bg-transition-duration) ease-in-out;
        }
        
        .bg-current {
            opacity: 1;
            z-index: -1;
        }
        
        .bg-next {
            opacity: 0;
            z-index: -2;
        }
        
        /* 遮罩层，确保文字可读性 */
        .bg-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
        }

        .line-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .qq-group-card {
            transition: transform 0.3s ease;
            border-radius: 16px;
            overflow: hidden;
            height: 100%;
            /* 液态玻璃效果 */
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .qq-group-card:hover {
            transform: translateY(-5px);
            border-color: rgba(255, 255, 255, 0.4);
        }

        .card-body {
            min-height: 280px;
            height: 100%;
        }
        
        .loading-spinner {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        /* Floating Action Button */
        .fab-back {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 3.5rem;
            height: 3.5rem;
            background: #ffffff;
            border-radius: 50%;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1f2937;
            font-size: 1.25rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            z-index: 100;
        }

        .fab-back:hover {
            transform: scale(1.1) rotate(-90deg);
            color: #4f46e5;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        /* 群号和复制按钮样式 */
        .group-info-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px 8px;
        }
        
        .group-id-display {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
        }
        
        .copy-group-btn {
            background: transparent;
            border: none;
            color: #0d6efd;
            font-size: 0.85rem;
            padding: 2px 8px;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 4px;
        }
        
        .copy-group-btn:hover {
            background: rgba(13, 110, 253, 0.1);
            color: #0a58ca;
        }
        
        .copy-group-btn:active {
            transform: scale(0.95);
        }
        
        .copy-group-btn.btn-success {
            color: #198754;
        }
        
        .copy-group-btn.btn-success:hover {
            background: rgba(25, 135, 84, 0.1);
            color: #146c43;
        }
        
        .copy-group-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* 页脚样式 - 与主页一致 */
        footer {
            background-color: #212529 !important;
            color: rgba(255, 255, 255, 0.7) !important;
            border-top: none !important;
            font-size: 0.85rem;
        }

        footer .footer-links a,
        footer .footer-extra a {
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            transition: color 0.2s;
        }

        footer .footer-links a:hover,
        footer .footer-extra a:hover {
            color: #fff;
        }

        footer .footer-info {
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 0.25rem !important;
        }
    </style>
</head>
<body>
    <a href="/" class="fab-back" title="返回首页">
        <i class="bi bi-arrow-left"></i>
    </a>

    <!-- 背景层 -->
    <div id="bg-layer-1" class="bg-layer bg-current"></div>
    <div id="bg-layer-2" class="bg-layer bg-next"></div>
    <!-- 遮罩层 -->
    <div class="bg-overlay"></div>

    <div class="page-content">
        <div class="container py-5">
            <div class="text-center mb-3">
                <h1 class="fw-bold text-white mb-2" style="text-shadow: 0 2px 4px rgba(0,0,0,0.2);">QQ群聊</h1>
                <p class="text-white text-opacity-75" style="text-shadow: 0 1px 2px rgba(0,0,0,0.1);">与志同道合的朋友一起交流学习</p>
            </div>

            <div class="row g-4">
            <?php if (empty($groups)): ?>
            <div class="col-12 text-center py-5">
                <div class="text-muted">
                    <i class="bi bi-people display-1 mb-3 d-block opacity-25"></i>
                    <p class="fs-5">暂无QQ群聊</p>
                </div>
            </div>
            <?php else: ?>
                <?php foreach ($groups as $index => $group): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="qq-group-card position-relative"
                         data-url="<?= e($group['link']) ?>"
                         data-id="<?= $group['id'] ?>"
                         data-name="<?= e($group['name']) ?>"
                         data-desc="<?= e($group['description']) ?>"
                         data-members="<?= e($group['max_members'] ?? 200) ?>"
                         data-api-show="<?= ($group['api_show'] ?? 1) ? '1' : '0' ?>">
                        
                        <!-- 加载动画 -->
                        <div class="loading-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background: inherit; z-index: 2;">
                            <div class="spinner-border text-white" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>

                        <!-- 内容区域 -->
                        <div class="card-body d-flex flex-column p-4 position-relative" style="z-index: 1;">
                            <div class="mb-3 d-flex align-items-center">
                                <img src="/assets/images/default-avatar.png" class="rounded-circle me-3 group-avatar bg-white p-1" style="width: 48px; height: 48px; object-fit: cover; opacity: 0;">
                                <div class="flex-grow-1">
                                    <h5 class="fw-bold text-white mb-1 group-name"><?= e($group['name']) ?></h5>
                                    <!-- 修改：群号和复制按钮放在同一行，复制按钮显示为文字"复制" -->
                                    <div class="group-info-row">
                                        <span class="group-id-display">群号: 解析中...</span>
                                        <button class="copy-group-btn" disabled>复制</button>
                                    </div>
                                </div>
                            </div>
                            
                            <p class="card-text text-white-50 mb-3 small line-clamp-3 group-desc">
                                <?= e($group['description'] ?: '正在获取群介绍...') ?>
                            </p>

                            <?php if (!empty($group['note'])): ?>
                            <div class="mb-2 small text-white-40 group-note" style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.4);">
                                <?= e($group['note']) ?>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3 group-tags">
                                <span class="badge bg-white bg-opacity-10 text-white border border-white border-opacity-25 me-1 mb-1 group-member-count">
                                    <i class="bi bi-person-fill me-1"></i>0 / <?= e($group['max_members'] ?? 200) ?>
                                </span>
                            </div>
                            
                            <div class="mt-auto pt-3 border-top border-white border-opacity-10 d-flex justify-content-between align-items-center">
                                <button class="text-decoration-none fw-bold text-white d-flex align-items-center group-link-btn" style="font-size: 0.9rem; background: none; border: none; padding: 0; cursor: pointer; outline: none; user-select: none;" data-group-url="<?= e($group['link']) ?>">
                                    加入群组 <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                                <small class="text-white-50 group-create-time" style="font-size: 0.8rem;"></small>
                            </div>
                        </div>
                        <!-- 隐藏的iframe用于PC端加群 -->
                        <iframe class="group-join-iframe" style="display:none;" sandbox="allow-forms allow-scripts allow-same-origin allow-popups"></iframe>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/footer.php'; ?>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 背景轮播逻辑
            const bgLayer1 = document.getElementById('bg-layer-1');
            const bgLayer2 = document.getElementById('bg-layer-2');
            let currentLayer = bgLayer1; // 当前显示的层
            let nextLayer = bgLayer2;    // 下一张要显示的层（隐藏）
            
            // 初始化：先加载第一张图给 bgLayer1
            loadNextImage(bgLayer1).then(() => {
                // 确保第一张图显示
                bgLayer1.style.opacity = '1';
                bgLayer1.classList.add('bg-current');
                // 第一张加载显示后，开始循环切换
                setTimeout(startRotation, 5000);
            }).catch(err => {
                console.error('首张背景加载失败', err);
                // 失败重试
                setTimeout(() => location.reload(), 3000);
            });

            function startRotation() {
                // 1. 预加载下一张图到 nextLayer
                loadNextImage(nextLayer).then(() => {
                    // 2. 加载完成，执行切换
                    // 让 nextLayer 显现 (opacity 0 -> 1)
                    nextLayer.classList.remove('bg-next');
                    nextLayer.classList.add('bg-current');
                    nextLayer.style.opacity = '1';
                    
                    // 让 currentLayer 隐藏 (opacity 1 -> 0)
                    currentLayer.classList.remove('bg-current');
                    currentLayer.classList.add('bg-next');
                    currentLayer.style.opacity = '0';
                    
                    // 3. 交换引用，为下一次做准备
                    const temp = currentLayer;
                    currentLayer = nextLayer;
                    nextLayer = temp;
                    
                    // 4. 设置下一次切换的定时器
                    setTimeout(startRotation, 5000);
                }).catch(err => {
                    console.error('背景加载失败，跳过本次切换', err);
                    setTimeout(startRotation, 3000); // 失败后稍作延迟再试
                });
            }

            function loadNextImage(layerElement) {
                return new Promise((resolve, reject) => {
                    const img = new Image();
                    // 添加随机参数防止缓存，确保每次获取新图片
                    const url = `https://bing.img.run/rand_uhd.php?t=${Date.now()}`;
                    
                    img.onload = () => {
                        // 图片加载成功后，才设置背景图
                        layerElement.style.backgroundImage = `url('${url}')`;
                        resolve();
                    };
                    
                    img.onerror = () => {
                        reject('Image load failed: ' + url);
                    };
                    
                    // 开始加载
                    img.src = url;
                });
            }

            // 卡片数据加载逻辑
            const cards = document.querySelectorAll('.qq-group-card');

            // 复制功能函数 - 修改为文字复制按钮
            function copyToClipboard(text, btn) {
                navigator.clipboard.writeText(text).then(() => {
                    const originalText = btn.textContent;
                    btn.textContent = '已复制';
                    btn.classList.add('btn-success');
                    setTimeout(() => {
                        btn.textContent = originalText;
                        btn.classList.remove('btn-success');
                    }, 2000);
                }).catch(err => {
                    console.error('复制失败:', err);
                    alert('复制失败，请手动复制');
                });
            }

            // 加群按钮点击事件处理
            cards.forEach(card => {
                const joinBtn = card.querySelector('.group-link-btn');
                if (joinBtn) {
                    joinBtn.addEventListener('click', function() {
                        const groupUrl = this.getAttribute('data-group-url');
                        const iframe = card.querySelector('.group-join-iframe');

                        if (groupUrl) {
                            // 检测是否为移动设备
                            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

                            if (isMobile) {
                                // 手机版直接跳转
                                window.location.href = groupUrl;
                                console.log('移动设备，直接跳转:', groupUrl);
                            } else {
                                // PC版使用隐藏iframe
                                if (iframe) {
                                    iframe.src = groupUrl;
                                    console.log('PC设备，通过隐藏iframe加载:', groupUrl);
                                }
                            }
                        } else {
                            alert('未找到加群链接！');
                        }
                    });
                }
            });

            cards.forEach(card => {
                const url = card.dataset.url;
                const dbName = card.dataset.name;
                const dbDesc = card.dataset.desc;
                const maxMembers = card.dataset.members;

                // 构建 API URL
                const apiUrl = `/vendor/api/qq_group/api.php?url=${encodeURIComponent(url)}`;

                fetch(apiUrl)
                    .then(response => response.json())
                    .then(data => {
                        const loading = card.querySelector('.loading-overlay');
                        const avatar = card.querySelector('.group-avatar');
                        const nameEl = card.querySelector('.group-name');
                        const idEl = card.querySelector('.group-id-display');
                        const descEl = card.querySelector('.group-desc');
                        const memberCountEl = card.querySelector('.group-member-count');
                        const timeEl = card.querySelector('.group-create-time');
                        const linkBtn = card.querySelector('.group-link-btn');
                        const copyBtn = card.querySelector('.copy-group-btn');
                        const iframe = card.querySelector('.group-join-iframe');

                        // 移除加载动画
                        if (loading) {
                            loading.style.opacity = '0';
                            setTimeout(() => loading.remove(), 300);
                        }

                        if (data.success && data.info) {
                            const info = data.info;

                            // 更新头像 (使用新API的avatar字段)
                            if (info.avatar) {
                                avatar.src = info.avatar;
                                avatar.style.opacity = '1';
                            }

                            // 更新群名 (API获取的优先，如果API为空则用数据库的)
                            if (info.name) {
                                nameEl.textContent = info.name;
                            }

                            // 更新群号 (使用新API的groupCode字段)
                            if (info.groupCode) {
                                idEl.textContent = '群号: ' + info.groupCode;
                                // 启用复制按钮
                                if (copyBtn) {
                                    copyBtn.disabled = false;
                                    copyBtn.title = '复制群号';
                                    copyBtn.onclick = () => copyToClipboard(info.groupCode, copyBtn);
                                }
                            }

                            // 更新描述
                            if (info.description) {
                                descEl.textContent = info.description;
                            } else if (!dbDesc) {
                                descEl.textContent = '暂无介绍';
                            }

                            // 更新成员数 (使用新API的memberCount字段)
                            if (info.memberCount !== undefined && info.memberCount !== null) {
                                memberCountEl.innerHTML = `<i class="bi bi-person-fill me-1"></i>${info.memberCount} / ${maxMembers}`;
                            }

                            // 更新创建时间 (使用新API的createTime字段)
                            if (info.createTime) {
                                timeEl.textContent = '建群时间: ' + info.createTime.split(' ')[0]; // 只显示日期
                            } else {
                                const now = new Date();
                                timeEl.textContent = '建群时间: ' + `${now.getFullYear()}年${now.getMonth()+1}月`;
                            }

                            // 更新标签 - 显示所有标签
                            if (info.tags && Array.isArray(info.tags) && info.tags.length > 0) {
                                const tagsContainer = card.querySelector('.group-tags');
                                const firstBadge = tagsContainer.firstElementChild; // "QQ群" 标签

                                // 创建临时容器
                                const tempDiv = document.createElement('div');
                                // 显示所有标签，不再限制数量
                                info.tags.forEach(tag => {
                                    tempDiv.innerHTML += `<span class="badge bg-white bg-opacity-10 text-white border border-white border-opacity-25 me-1 mb-1">${tag}</span>`;
                                });

                                // 插入新标签
                                if (firstBadge) {
                                    while (tempDiv.firstChild) {
                                        tagsContainer.insertBefore(tempDiv.firstChild, firstBadge.nextSibling);
                                    }
                                }
                            }
                        } else {
                            // API 解析失败，使用数据库中的默认数据
                            console.warn('API解析失败:', data.error);
                            avatar.style.opacity = '1'; // 显示默认头像
                            idEl.textContent = '群号: 未知';
                            if (!dbDesc) descEl.textContent = '暂无介绍';

                            const now = new Date();
                            timeEl.textContent = `${now.getFullYear()}年${now.getMonth()+1}月`;
                        }
                    })
                    .catch(err => {
                        console.error('请求错误:', err);
                        const loading = card.querySelector('.loading-overlay');
                        if (loading) loading.remove();
                        const avatar = card.querySelector('.group-avatar');
                        avatar.style.opacity = '1';
                    });
            });
        });
    </script>
</body>
</html>