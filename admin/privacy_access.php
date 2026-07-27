<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';
require_once 'includes/privacy_access_controller.php';

requireLogin();

$db = getDB();
$data = handlePrivacyAccessPage($db);
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$accessRecords = $data['records'];
$paidRecords = $data['paidRecords'];
$stats = $data['stats'];

// 按分类整理记录
$recordsByCategory = [];
$recordsByCategoryJson = [];
foreach ($accessRecords as $record) {
    $category = $record['category'] ?: '未分类';
    if (!isset($recordsByCategory[$category])) {
        $recordsByCategory[$category] = [];
    }
    $recordsByCategory[$category][] = $record;
}
// 排序函数：待审核 → 已授权 → 已撤回
$sortPriority = function($r) {
    $isRevoked = ($r['username'] === '隐私表单重新填写' && !empty($r['answer']) && preg_match('/\s{4}\d+:\S+:\S+@\S+$/', $r['answer']));
    if ($isRevoked) return 2;          // 已撤回排在最后
    if ($r['access_granted'] == 1) return 1; // 已授权排中间
    return 0;                           // 待审核排最前
};

foreach ($recordsByCategory as $category => $records) {
    usort($records, function($a, $b) use ($sortPriority) {
        return $sortPriority($a) - $sortPriority($b);
    });
    $recordsByCategoryJson[md5($category)] = $records;
}

// 按文章标题整理记录
$recordsByPost = [];
$recordsByPostJson = [];
foreach ($accessRecords as $record) {
    $postTitle = $record['post_title'] ?: '未知文章';
    if (!isset($recordsByPost[$postTitle])) {
        $recordsByPost[$postTitle] = [
            'title' => $postTitle,
            'id' => $record['post_id'],
            'category' => $record['category'] ?: '未分类',
            'records' => []
        ];
    }
    $recordsByPost[$postTitle]['records'][] = $record;
}
foreach ($recordsByPost as $title => $data) {
    usort($data['records'], function($a, $b) use ($sortPriority) {
        return $sortPriority($a) - $sortPriority($b);
    });
    $recordsByPostJson[md5($title)] = $data['records'];
}

// 按文章标题整理付费记录
$paidRecordsByPost = [];
$paidTotalAmount = 0;
foreach ($paidRecords as $record) {
    if ($record['status'] == 1) {
        $paidTotalAmount += $record['amount'];
    }
    $postTitle = $record['post_title'] ?: '未知文章';
    if (!isset($paidRecordsByPost[$postTitle])) {
        $paidRecordsByPost[$postTitle] = [
            'title' => $postTitle,
            'id' => $record['post_id'],
            'category' => $record['category'] ?: '未分类',
            'records' => []
        ];
    }
    $paidRecordsByPost[$postTitle]['records'][] = $record;
}
$page_title = '隐私与付费记录';
$extra_css = <<<'CSS'
.accordion-button:not(.collapsed) {
    color: #0d6efd;
    background-color: #e7f1ff;
}
.accordion-button:focus {
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}
.table-responsive {
    border-radius: 0 0 0.375rem 0.375rem;
}
.btn-group .btn {
    margin-right: 2px;
}
.stats-card {
    transition: all 0.3s ease;
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.stats-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.stats-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    font-size: 24px;
    margin-bottom: 1rem;
}
.nav-pills .nav-link {
    border-radius: 8px;
    padding: 0.5rem 1rem;
    color: #6c757d;
    font-weight: 500;
}
.nav-pills .nav-link.active {
    background-color: #0d6efd;
    color: #fff;
    box-shadow: 0 2px 5px rgba(13, 110, 253, 0.3);
}
.answer-preview {
    cursor: pointer;
    border-bottom: 1px dashed #adb5bd;
}
.answer-preview:hover {
    color: #0d6efd;
    border-bottom-color: #0d6efd;
}
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 text-gray-800" id="pageTitle">隐私内容访问记录</h1>
                        <p class="text-muted" id="pageDesc">管理用户对隐私内容的访问申请与记录</p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-4">
                            <input type="radio" class="btn-check" name="mainType" id="typePrivacy" autocomplete="off" checked onchange="switchMainType('privacy')">
                            <label class="btn btn-outline-primary" for="typePrivacy">隐私管理</label>

                            <input type="radio" class="btn-check" name="mainType" id="typePayment" autocomplete="off" onchange="switchMainType('payment')">
                            <label class="btn btn-outline-primary" for="typePayment">支付管理</label>
                        </div>
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                                <i class="bi bi-arrow-clockwise"></i> 刷新
                            </button>
                        </div>
                        <div class="btn-group me-2" id="privacyActionButtons">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="expandAllCategories()">
                                <i class="bi bi-arrows-expand"></i> 全部展开
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="collapseAllCategories()">
                                <i class="bi bi-arrows-collapse"></i> 全部折叠
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="revokeAll()">
                                <i class="bi bi-exclamation-triangle"></i> 一键撤回全部
                            </button>
                        </div>
                        <div class="btn-group" id="batchActionButtons" style="display: none;">
                            <button type="button" class="btn btn-sm btn-danger" onclick="showBatchRevokeModal()">
                                <i class="bi bi-x-circle"></i> 批量撤回+发邮件
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearBatchSelection()">
                                <i class="bi bi-x"></i> 取消选择
                            </button>
                            <span class="badge bg-danger ms-2" id="selectedCount">已选 0 条</span>
                        </div>
                    </div>
                </div>
                
                <div id="privacySection">
                <!-- 统计卡片 -->
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">总记录数</h6>
                                        <h2 class="mb-0 fw-bold"><?= $stats['total'] ?></h2>
                                    </div>
                                    <div class="stats-icon bg-primary bg-opacity-10 text-primary">
                                        <i class="bi bi-list-check"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">已授权</h6>
                                        <h2 class="mb-0 fw-bold text-success"><?= $stats['granted'] ?></h2>
                                    </div>
                                    <div class="stats-icon bg-success bg-opacity-10 text-success">
                                        <i class="bi bi-check-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">待审核</h6>
                                        <h2 class="mb-0 fw-bold text-warning"><?= $stats['pending'] ?></h2>
                                    </div>
                                    <div class="stats-icon bg-warning bg-opacity-10 text-warning">
                                        <i class="bi bi-hourglass-split"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">已撤回</h6>
                                        <h2 class="mb-0 fw-bold text-secondary"><?= $stats['revoked'] ?></h2>
                                    </div>
                                    <div class="stats-icon bg-secondary bg-opacity-10 text-secondary">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 视图切换 -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 border-0">
                        <ul class="nav nav-pills" id="viewTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="category-tab" data-bs-toggle="pill" data-bs-target="#category-view" type="button" role="tab">
                                    <i class="bi bi-folder me-2"></i> 按分类查看
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link ms-2" id="post-tab" data-bs-toggle="pill" data-bs-target="#post-view" type="button" role="tab">
                                    <i class="bi bi-file-text me-2"></i> 按文章查看
                                </button>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="tab-content" id="viewTabContent">
                            <!-- 按分类视图 -->
                            <div class="tab-pane fade show active" id="category-view" role="tabpanel">
                                <div class="accordion accordion-flush" id="categoriesAccordion">
                                    <?php if (empty($recordsByCategory)): ?>
                                        <div class="text-center py-5">
                                            <div class="mb-3">
                                                <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                                            </div>
                                            <h5 class="text-muted">暂无记录</h5>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recordsByCategory as $category => $records): 
                                            $granted = 0;
                                            $pending = 0;
                                            foreach ($records as $r) {
                                                $isRevoked = ($r['username'] === '隐私表单重新填写' && !empty($r['answer']) && preg_match('/\s{4}\d+:\S+:\S+@\S+$/', $r['answer']));
                                                if ($isRevoked) continue;
                                                if ($r['access_granted']) $granted++;
                                                elseif ($r['privacy_type'] === 'open_answer' || $r['privacy_type'] === 'manual_approval') $pending++;
                                            }
                                        ?>
                                        <div class="accordion-item border-bottom">
                                            <h2 class="accordion-header" id="heading-cat-<?= md5($category) ?>">
                                                <button class="accordion-button collapsed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-cat-<?= md5($category) ?>">
                                                    <div class="d-flex align-items-center w-100 me-3">
                                                        <i class="bi bi-folder2-open text-warning me-3 fs-5"></i>
                                                        <span class="fw-bold me-auto"><?= e($category) ?></span>
                                                        <div class="d-flex gap-2">
                                                            <?php if ($pending > 0): ?>
                                                            <span class="badge bg-warning text-dark rounded-pill">待审核: <?= $pending ?></span>
                                                            <?php endif; ?>
                                                            <span class="badge bg-light text-dark border rounded-pill">总计: <?= count($records) ?></span>
                                                        </div>
                                                    </div>
                                                </button>
                                            </h2>
                                            <div id="collapse-cat-<?= md5($category) ?>" class="accordion-collapse collapse" data-bs-parent="#categoriesAccordion">
                                                <div class="accordion-body p-0">
                                                    <?php $catNonRevoked = $granted + $pending; ?>
                                                    <?php if ($pending > 0 || $catNonRevoked > 0): ?>
                                                    <div class="p-3 bg-light border-bottom d-flex gap-2">
                                                        <?php if ($pending > 0): ?>
                                                        <button class="btn btn-sm btn-success" onclick="grantAllInCategory('<?= e($category) ?>')">
                                                            <i class="bi bi-check-all me-1"></i> 一键授权本类目
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php if ($catNonRevoked > 0): ?>
                                                        <button class="btn btn-sm btn-danger" onclick="revokeAllInCategory('<?= e($category) ?>')">
                                                            <i class="bi bi-exclamation-triangle me-1"></i> 一键撤回本类目
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-hover align-middle mb-0">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th class="ps-4">
                                                                        <input type="checkbox" id="selectAllCategory" onchange="toggleSelectAll('category')">
                                                                    </th>
                                                                    <th class="ps-4">用户</th>
                                                                    <th>文章</th>
                                                                    <th>类型</th>
                                                                    <th>答案/内容</th>
                                                                    <th>状态</th>
                                                                    <th>时间</th>
                                                                    <th class="text-end pe-4">操作</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody data-cat-hash="<?= md5($category) ?>">
                                                                <!-- JS renders rows here -->
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- 按文章视图 -->
                            <div class="tab-pane fade" id="post-view" role="tabpanel">
                                <div class="accordion accordion-flush" id="postsAccordion">
                                    <?php if (empty($recordsByPost)): ?>
                                        <div class="text-center py-5">
                                            <div class="mb-3">
                                                <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                                            </div>
                                            <h5 class="text-muted">暂无记录</h5>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recordsByPost as $title => $data): 
                                            $records = $data['records'];
                                            $granted = 0;
                                            $pending = 0;
                                            foreach ($records as $r) {
                                                $isRevoked = ($r['username'] === '隐私表单重新填写' && !empty($r['answer']) && preg_match('/\s{4}\d+:\S+:\S+@\S+$/', $r['answer']));
                                                if ($isRevoked) continue;
                                                if ($r['access_granted']) $granted++;
                                                elseif ($r['privacy_type'] === 'open_answer' || $r['privacy_type'] === 'manual_approval') $pending++;
                                            }
                                        ?>
                                        <div class="accordion-item border-bottom">
                                            <h2 class="accordion-header" id="heading-post-<?= md5($title) ?>">
                                                <button class="accordion-button collapsed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-post-<?= md5($title) ?>">
                                                    <div class="d-flex align-items-center w-100 me-3">
                                                        <i class="bi bi-file-text text-primary me-3 fs-5"></i>
                                                        <div class="me-auto">
                                                            <span class="fw-bold d-block"><?= e($title) ?></span>
                                                            <span class="badge bg-light text-secondary border rounded-pill small fw-normal"><?= e($data['category']) ?></span>
                                                        </div>
                                                        <div class="d-flex gap-2">
                                                            <?php if ($pending > 0): ?>
                                                            <span class="badge bg-warning text-dark rounded-pill">待审核: <?= $pending ?></span>
                                                            <?php endif; ?>
                                                            <span class="badge bg-light text-dark border rounded-pill">总计: <?= count($records) ?></span>
                                                        </div>
                                                    </div>
                                                </button>
                                            </h2>
                                            <div id="collapse-post-<?= md5($title) ?>" class="accordion-collapse collapse" data-bs-parent="#postsAccordion">
                                                <div class="accordion-body p-0">
                                                    <?php $postNonRevoked = $granted + $pending; ?>
                                                    <?php if ($pending > 0 || $postNonRevoked > 0): ?>
                                                    <div class="p-3 bg-light border-bottom d-flex gap-2">
                                                        <?php if ($pending > 0): ?>
                                                        <button class="btn btn-sm btn-success" onclick="grantAllInPost('<?= e($title) ?>')">
                                                            <i class="bi bi-check-all me-1"></i> 一键授权本文
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php if ($postNonRevoked > 0): ?>
                                                        <button class="btn btn-sm btn-danger" onclick="revokeAllInPost('<?= e($title) ?>')">
                                                            <i class="bi bi-exclamation-triangle me-1"></i> 一键撤回本文
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-hover align-middle mb-0">
                                                            <!-- 表头与分类视图一致，略去重复 -->
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th class="ps-4">
                                                                        <input type="checkbox" id="selectAllPost" onchange="toggleSelectAll('post')">
                                                                    </th>
                                                                    <th class="ps-4">用户</th>
                                                                    <th>类型</th>
                                                                    <th>答案/内容</th>
                                                                    <th>状态</th>
                                                                    <th>时间</th>
                                                                    <th class="text-end pe-4">操作</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody data-post-hash="<?= md5($title) ?>">
                                                                <!-- JS renders rows here -->
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                </div> <!-- End privacySection -->

                <!-- 付费记录视图 -->
                <div id="paymentSection" style="display: none;">
                    <!-- 付费统计卡片 -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="card stats-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="text-muted mb-2">总订单数</h6>
                                            <h2 class="mb-0 fw-bold"><?= $stats['paid_total'] ?></h2>
                                        </div>
                                        <div class="stats-icon bg-primary bg-opacity-10 text-primary">
                                            <i class="bi bi-receipt"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stats-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="text-muted mb-2">成功支付</h6>
                                            <h2 class="mb-0 fw-bold text-success"><?= $stats['paid_success'] ?></h2>
                                        </div>
                                        <div class="stats-icon bg-success bg-opacity-10 text-success">
                                            <i class="bi bi-check-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stats-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="text-muted mb-2">待支付</h6>
                                            <h2 class="mb-0 fw-bold text-warning"><?= $stats['paid_total'] - $stats['paid_success'] ?></h2>
                                        </div>
                                        <div class="stats-icon bg-warning bg-opacity-10 text-warning">
                                            <i class="bi bi-hourglass-split"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stats-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="text-muted mb-2">总收入</h6>
                                            <h2 class="mb-0 fw-bold text-danger">￥<?= number_format($paidTotalAmount, 2) ?></h2>
                                        </div>
                                        <div class="stats-icon bg-danger bg-opacity-10 text-danger">
                                            <i class="bi bi-currency-yen"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3 border-0">
                            <h5 class="mb-0"><i class="bi bi-file-text me-2"></i> 按文章查看付费记录</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="accordion accordion-flush" id="paidPostsAccordion">
                                <?php if (empty($paidRecordsByPost)): ?>
                                    <div class="text-center py-5">
                                        <div class="mb-3">
                                            <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                                        </div>
                                        <h5 class="text-muted">暂无付费记录</h5>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($paidRecordsByPost as $title => $data): 
                                        $records = $data['records'];
                                        $successCount = 0;
                                        $totalAmount = 0;
                                        foreach ($records as $r) {
                                            if ($r['status'] == 1) {
                                                $successCount++;
                                                $totalAmount += $r['amount'];
                                            }
                                        }
                                    ?>
                                    <div class="accordion-item border-bottom">
                                        <h2 class="accordion-header" id="heading-paid-post-<?= md5($title) ?>">
                                            <button class="accordion-button collapsed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-paid-post-<?= md5($title) ?>">
                                                <div class="d-flex align-items-center w-100 me-3">
                                                    <i class="bi bi-file-text text-primary me-3 fs-5"></i>
                                                    <div class="me-auto">
                                                        <span class="fw-bold d-block"><?= e($title) ?></span>
                                                        <span class="badge bg-light text-secondary border rounded-pill small fw-normal"><?= e($data['category']) ?></span>
                                                    </div>
                                                    <div class="d-flex gap-2 align-items-center">
                                                        <span class="text-danger fw-bold me-2">￥<?= number_format($totalAmount, 2) ?></span>
                                                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill">成功: <?= $successCount ?></span>
                                                        <span class="badge bg-light text-dark border rounded-pill">总计: <?= count($records) ?></span>
                                                    </div>
                                                </div>
                                            </button>
                                        </h2>
                                        <div id="collapse-paid-post-<?= md5($title) ?>" class="accordion-collapse collapse" data-bs-parent="#paidPostsAccordion">
                                            <div class="accordion-body p-0">
                                                <div class="table-responsive">
                                                    <table class="table table-hover align-middle mb-0">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th class="ps-4">用户</th>
                                                                <th>订单号</th>
                                                                <th>支付金额</th>
                                                                <th>状态</th>
                                                                <th>时间</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($records as $record): 
                                                                $firstLetter = !empty($record['username']) ? strtoupper(substr($record['username'], 0, 1)) : '?';
                                                                $username = e($record['username'] ?? '未知用户');
                                                                $email = !empty($record['email']) ? e($record['email']) : '无邮箱';
                                                            ?>
                                                            <tr>
                                                                <td class="ps-4">
                                                                    <div class="d-flex align-items-center">
                                                                        <div class="avatar-circle bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                                                            <?= $firstLetter ?>
                                                                        </div>
                                                                        <div>
                                                                            <div class="fw-bold"><?= $username ?></div>
                                                                            <div class="small text-muted"><?= $email ?></div>
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                                <td><span class="text-muted small"><?= e($record['trade_no']) ?></span></td>
                                                                <td><span class="fw-bold text-danger">￥<?= number_format($record['amount'], 2) ?></span></td>
                                                                <td>
                                                                    <?php if ($record['status'] == 1): ?>
                                                                        <span class="badge bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle me-1"></i>已支付</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-warning bg-opacity-10 text-warning"><i class="bi bi-hourglass me-1"></i>待支付</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="text-muted small">
                                                                    <?= date('m-d H:i', strtotime($record['created_at'])) ?>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

    <!-- 查看完整答案模态框 -->
    <div class="modal fade" id="fullAnswerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">完整内容</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-break" id="fullAnswerContent" style="white-space: pre-wrap; max-height: 60vh; overflow-y: auto;"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= getResourceUrl('/assets/js/md5.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/blueimp-md5/2.19.0/js/md5.min.js') ?>"></script>
    <script>
        function switchMainType(type) {
            if (type === 'privacy') {
                document.getElementById('privacySection').style.display = 'block';
                document.getElementById('paymentSection').style.display = 'none';
                document.getElementById('pageTitle').textContent = '隐私内容访问记录';
                document.getElementById('pageDesc').textContent = '管理用户对隐私内容的访问申请与记录';
            } else {
                document.getElementById('privacySection').style.display = 'none';
                document.getElementById('paymentSection').style.display = 'block';
                document.getElementById('pageTitle').textContent = '付费内容记录';
                document.getElementById('pageDesc').textContent = '管理用户的付费内容购买记录';
            }
        }

        // 渲染记录数据
        const catRecords = <?= json_encode($recordsByCategoryJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const postRecords = <?= json_encode($recordsByPostJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        function formatDate(dateStr) {
            if (!dateStr) return '';
            const parts = dateStr.split(/[- :]/);
            if (parts.length >= 5) {
                return parts[1] + '-' + parts[2] + ' ' + parts[3] + ':' + parts[4];
            }
            return dateStr;
        }
        
        function renderTableRows(records, isPostView) {
            const types = {
                'login_only': {bg: 'secondary', text: '仅登录'},
                'fixed_answer': {bg: 'info', text: '固定答案'},
                'open_answer': {bg: 'primary', text: '开放问答'},
                'manual_approval': {bg: 'warning', text: '人工审核'}
            };
        
            return records.map(record => {
                const type = types[record.privacy_type] || {bg: 'secondary', text: '未知'};
                
                // 尝试从答案中解析被撤回的原用户信息
                let revertInfo = null;
                if (record.answer) {
                    const revMatch = record.answer.match(/^(.+?)    (\d+):(\S+):(\S+@\S+)$/);
                    if (revMatch) {
                        revertInfo = { id: revMatch[2], username: revMatch[3], email: revMatch[4] };
                    }
                }
                
                // 如果是匿名用户，优先显示原用户信息
                const isAnon = record.username === '隐私表单重新填写';
                const displayName = isAnon && revertInfo ? escapeHtml(revertInfo.username) : escapeHtml(record.username);
                const displayEmail = isAnon && revertInfo ? escapeHtml(revertInfo.email) : (record.email ? escapeHtml(record.email) : '无邮箱');
                const firstLetter = displayName ? displayName.substring(0, 1).toUpperCase() : '?';
                const postTitle = escapeHtml(record.post_title);
                const username = escapeHtml(record.username);
                const answer = escapeHtml(record.answer);
                const isRevoked = isAnon && revertInfo;
                
                // 只有未撤回的记录才显示复选框
                const checkboxHtml = !isRevoked ? `<input type="checkbox" class="form-check-input batch-checkbox" data-id="${record.id}" onchange="updateBatchUI()">` : '';
                
                let answerHtml = '';
                if (record.answer) {
                    // 解析追加的原用户信息（4空格 + ID:用户名:邮箱）
                    let answerDisplay = answer;
                    const revokeMatch = answer.match(/^(.+?)    (\d+):(\S+):(\S+@\S+)$/);
                    if (revokeMatch) {
                        const originalText = escapeHtml(revokeMatch[1]);
                        const revokeId = revokeMatch[2];
                        const revokeUser = escapeHtml(revokeMatch[3]);
                        const revokeEmail = escapeHtml(revokeMatch[4]);
                        answerDisplay = originalText + '    <a href="/admin/admins.php#user-' + revokeId + '" class="text-decoration-none text-primary" title="点击查看该用户">' + revokeUser + '(ID:' + revokeId + ') &lt;' + revokeEmail + '&gt;</a>';
                    }

                    answerHtml = `
                    <div class="d-flex align-items-center">
                        <div class="text-truncate me-2" style="flex: 1;" title="${answer}">
                            ${answerDisplay}
                        </div>
                        <button class="btn btn-link btn-sm p-0 text-decoration-none" onclick="showFullAnswer(this)" data-answer="${answer}" title="查看完整内容">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>`;
                } else {
                    answerHtml = `<div class="d-flex align-items-center"><div class="text-truncate me-2" style="flex: 1;"></div></div>`;
                }
                
                let statusHtml;
                if (isRevoked) {
                    statusHtml = `<span class="badge bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>已撤回</span>`;
                } else if (record.access_granted == 1) {
                    statusHtml = `<span class="badge bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle me-1"></i>已授权</span>`;
                } else {
                    statusHtml = `<span class="badge bg-warning bg-opacity-10 text-warning"><i class="bi bi-hourglass me-1"></i>待审核</span>`;
                }
                    
                let actionsHtml = `<div class="btn-group">`;
                if (!isRevoked) {
                    if (record.access_granted == 0) {
                        actionsHtml += `<button class="btn btn-sm btn-outline-success" onclick="grantAccess(${record.id})">授权</button>`;
                    }
                    actionsHtml += `<button class="btn btn-sm btn-outline-warning" onclick="revokeAnswer(${record.id})">撤回</button>`;
                    if (record.email) {
                        actionsHtml += `<button class="btn btn-sm btn-outline-primary" onclick="sendFollowupEmail(${record.id}, ${record.user_id}, ${record.post_id})">邮件</button>`;
                    }
                } else {
                    actionsHtml += `<span class="text-muted small">无操作</span>`;
                }
                actionsHtml += `</div>`;
        
                let postColumn = isPostView ? '' : `
                    <td>
                        <a href="/blog.php?id=${record.post_id}" target="_blank" class="text-decoration-none text-dark">
                            <i class="bi bi-file-text me-1 text-secondary"></i>
                            ${postTitle}
                        </a>
                    </td>`;
        
                return `
                <tr>
                    <td class="ps-4">${checkboxHtml}</td>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="avatar-circle bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                ${firstLetter}
                            </div>
                            <div>
                                <div class="fw-bold">${displayName}${isAnon && revertInfo ? ' <span class="badge bg-secondary bg-opacity-10 text-secondary" style="font-size:10px;vertical-align:middle;">已撤回</span>' : ''}</div>
                                <div class="small text-muted">${displayEmail}</div>
                            </div>
                        </div>
                    </td>
                    ${postColumn}
                    <td>
                        <span class="badge bg-${type.bg} bg-opacity-10 text-${type.bg}">
                            ${type.text}
                        </span>
                    </td>
                    <td style="max-width: 200px;">
                        ${answerHtml}
                    </td>
                    <td>
                        ${statusHtml}
                    </td>
                    <td class="text-muted small">
                        ${formatDate(record.created_at)}
                    </td>
                    <td class="text-end pe-4">
                        ${actionsHtml}
                    </td>
                </tr>`;
            }).join('');
        }
        
        document.addEventListener('show.bs.collapse', function (e) {
            const target = e.target;
            const tbody = target.querySelector('tbody');
            if (!tbody || tbody.dataset.rendered) return;
            
            const catHash = tbody.dataset.catHash;
            const postHash = tbody.dataset.postHash;
            
            let records = [];
            let isPostView = false;
            
            if (catHash && catRecords[catHash]) {
                records = catRecords[catHash];
            } else if (postHash && postRecords[postHash]) {
                records = postRecords[postHash];
                isPostView = true;
            }
            
            if (records.length > 0) {
                tbody.innerHTML = renderTableRows(records, isPostView);
                tbody.dataset.rendered = 'true';
            }
        });

        // 显示完整答案
        function showFullAnswer(btn) {
            const answer = btn.getAttribute('data-answer');
            document.getElementById('fullAnswerContent').textContent = answer;
            const modal = new bootstrap.Modal(document.getElementById('fullAnswerModal'));
            modal.show();
        }

        // 更新内存中的记录数据（不刷新面板）
        function updateRecordInData(id, updateFn) {
            for (let hash in catRecords) {
                for (let i = 0; i < catRecords[hash].length; i++) {
                    if (catRecords[hash][i].id == id) {
                        catRecords[hash][i] = updateFn(catRecords[hash][i]);
                    }
                }
            }
            for (let hash in postRecords) {
                for (let i = 0; i < postRecords[hash].length; i++) {
                    if (postRecords[hash][i].id == id) {
                        postRecords[hash][i] = updateFn(postRecords[hash][i]);
                    }
                }
            }
        }

        // 重新渲染所有当前展开的面板
        function refreshVisiblePanels() {
            document.querySelectorAll('.accordion-collapse.show').forEach(el => {
                const tbody = el.querySelector('tbody');
                if (!tbody) return;
                const catHash = tbody.dataset.catHash;
                const postHash = tbody.dataset.postHash;
                let records = [];
                let isPostView = false;
                if (catHash && catRecords[catHash]) {
                    records = catRecords[catHash];
                } else if (postHash && postRecords[postHash]) {
                    records = postRecords[postHash];
                    isPostView = true;
                }
                if (records.length > 0) {
                    tbody.innerHTML = renderTableRows(records, isPostView);
                }
            });
        }
        // 展开/折叠功能
        function expandAllCategories() {
            const isPrivacy = document.getElementById('typePrivacy').checked;
            let accordionId = '#paidPostsAccordion';
            
            if (isPrivacy) {
                const activeTab = document.querySelector('.tab-pane.active').id;
                accordionId = activeTab === 'category-view' ? '#categoriesAccordion' : '#postsAccordion';
            }
            
            document.querySelectorAll(`${accordionId} .accordion-collapse`).forEach(el => {
                if (isPrivacy) {
                    // 触发渲染
                    const tbody = el.querySelector('tbody');
                    if (tbody && !tbody.dataset.rendered) {
                        const catHash = tbody.dataset.catHash;
                        const postHash = tbody.dataset.postHash;
                        let records = [];
                        let isPostView = false;
                        if (catHash && catRecords[catHash]) {
                            records = catRecords[catHash];
                        } else if (postHash && postRecords[postHash]) {
                            records = postRecords[postHash];
                            isPostView = true;
                        }
                        if (records.length > 0) {
                            tbody.innerHTML = renderTableRows(records, isPostView);
                            tbody.dataset.rendered = 'true';
                        }
                    }
                }
                el.classList.add('show');
            });
            document.querySelectorAll(`${accordionId} .accordion-button`).forEach(el => el.classList.remove('collapsed'));
        }
        
        function collapseAllCategories() {
            const isPrivacy = document.getElementById('typePrivacy').checked;
            let accordionId = '#paidPostsAccordion';
            
            if (isPrivacy) {
                const activeTab = document.querySelector('.tab-pane.active').id;
                accordionId = activeTab === 'category-view' ? '#categoriesAccordion' : '#postsAccordion';
            }
            
            document.querySelectorAll(`${accordionId} .accordion-collapse`).forEach(el => el.classList.remove('show'));
            document.querySelectorAll(`${accordionId} .accordion-button`).forEach(el => el.classList.add('collapsed'));
        }
        
        // 授权单个
        function grantAccess(id) {
            if(!confirm('确定授权访问？')) return;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=grant_access&access_id=${id}`
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if(data.success) {
                    updateRecordInData(id, function(r) { r.access_granted = '1'; return r; });
                    refreshVisiblePanels();
                }
            });
        }

        // 撤回回答 - 先弹出邮件编辑模态框
        function revokeAnswer(id) {
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'revokeModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-hidden', 'true');

            modal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-danger bg-opacity-10">
                            <h5 class="modal-title">
                                <i class="bi bi-exclamation-triangle text-danger"></i> 撤回回答
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>警告：</strong>此操作将撤回该用户的回答，转移至匿名用户。此操作不可恢复！
                            </div>

                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="revokeSendEmailToggle" checked onchange="toggleRevokeEmailFields(this.checked)">
                                    <label class="form-check-label" for="revokeSendEmailToggle">
                                        <strong>发送撤回通知邮件</strong>
                                    </label>
                                </div>
                            </div>

                            <div id="revokeEmailFields">
                                <div class="mb-3">
                                    <label for="revokeSubject" class="form-label">
                                        <i class="bi bi-tag"></i> 邮件主题（可编辑）
                                    </label>
                                    <input type="text" class="form-control" id="revokeSubject"
                                           value="隐私内容填写提醒">
                                </div>

                                <div class="mb-3">
                                    <label for="revokeBody" class="form-label">
                                        <i class="bi bi-chat-text"></i> 邮件内容（可编辑）
                                    </label>
                                    <textarea class="form-control" id="revokeBody" rows="8" style="font-size: 14px;">您好，您在隐私内容填写中未表明详细信息，根据相关部门要求，将会封禁此用户以及登录的IP地址，1小时后生效。期间您可以给此邮箱回信重新说明情况。</textarea>
                                </div>

                                <div class="alert alert-warning">
                                    <i class="bi bi-info-circle"></i>
                                    请仔细检查邮件内容后再发送，发送后将自动执行撤回操作。
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-circle"></i> 取消
                            </button>
                            <button type="button" class="btn btn-danger" id="confirmRevokeBtn" onclick="confirmRevoke(${id})">
                                <i class="bi bi-check-circle"></i> 确认撤回
                            </button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();

            modal.addEventListener('hidden.bs.modal', function () {
                document.body.removeChild(modal);
            });
        }

        // 切换单独撤回的邮件字段显示
        function toggleRevokeEmailFields(checked) {
            const emailFields = document.getElementById('revokeEmailFields');
            const confirmBtn = document.getElementById('confirmRevokeBtn');

            if (checked) {
                emailFields.style.display = 'block';
                confirmBtn.innerHTML = '<i class="bi bi-send"></i> 发送邮件并撤回';
            } else {
                emailFields.style.display = 'none';
                confirmBtn.innerHTML = '<i class="bi bi-check-circle"></i> 确认撤回';
            }
        }

        // 确认撤回
        function confirmRevoke(id) {
            const sendEmail = document.getElementById('revokeSendEmailToggle').checked;
            const subject = document.getElementById('revokeSubject').value.trim();
            const body = document.getElementById('revokeBody').value.trim();

            if (sendEmail && !body) {
                alert('邮件内容不能为空');
                return;
            }

            const modalElement = document.getElementById('revokeModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            modal.hide();

            const action = sendEmail ? 'revoke_with_email' : 'revoke_no_email';
            const loadingMsg = sendEmail ? '正在发送邮件并撤回...' : '正在撤回...';

            showEmailSendingStatus(loadingMsg);

            let postBody = `action=${action}&access_id=${id}`;
            if (sendEmail) {
                postBody += `&email_subject=${encodeURIComponent(subject)}&email_body=${encodeURIComponent(body)}`;
            }

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: postBody
            })
            .then(res => res.json())
            .then(data => {
                hideEmailSendingStatus();
                alert(data.message);
                if(data.success) {
                    updateRecordInData(id, function(r) {
                        if (r.username !== '隐私表单重新填写') {
                            r.answer = (r.answer || '') + '    ' + (r.user_id || '0') + ':' + (r.username || '') + ':' + (r.email || '');
                            r.username = '隐私表单重新填写';
                            r.email = 'X#######@qq.com';
                            r.access_granted = '0';
                        }
                        return r;
                    });
                    refreshVisiblePanels();
                }
            })
            .catch(error => {
                hideEmailSendingStatus();
                alert('操作失败，请重试');
            });
        }

        // 批量授权 - 通过数据查找ID
        function grantAllInCategory(categoryName) {
            if (!confirm(`确定要授权"${categoryName}"分类下的所有待授权记录吗？`)) return;
            
            const ids = [];
            for (let hash in catRecords) {
                const records = catRecords[hash];
                if (records.length > 0 && records[0].category === categoryName) {
                    records.forEach(record => {
                        if (!record.access_granted && (record.privacy_type === 'open_answer' || record.privacy_type === 'manual_approval')) {
                            ids.push(record.id);
                        }
                    });
                }
            }

            if (ids.length === 0) {
                alert('没有待授权的记录');
                return;
            }

            processBatchGrantIds(ids);
        }

        function grantAllInPost(postTitle) {
            if (!confirm(`确定要授权"${postTitle}"文章下的所有待授权记录吗？`)) return;
            
            const ids = [];
            for (let hash in postRecords) {
                const records = postRecords[hash];
                if (records.length > 0 && records[0].post_title === postTitle) {
                    records.forEach(record => {
                        if (!record.access_granted && (record.privacy_type === 'open_answer' || record.privacy_type === 'manual_approval')) {
                            ids.push(record.id);
                        }
                    });
                }
            }

            if (ids.length === 0) {
                alert('没有待授权的记录');
                return;
            }

            processBatchGrantIds(ids);
        }

        // 一键撤回本类目下所有未撤回记录
        function revokeAllInCategory(categoryName) {
            const ids = [];
            const seenIds = new Set();

            for (let hash in catRecords) {
                const records = catRecords[hash];
                if (records.length > 0 && records[0].category === categoryName) {
                    records.forEach(record => {
                        const isRevoked = record.username === '隐私表单重新填写' && record.answer && /\s{4}\d+:\S+:\S+@\S+$/.test(record.answer);
                        if (!isRevoked && !seenIds.has(record.id)) {
                            ids.push(record.id);
                            seenIds.add(record.id);
                        }
                    });
                }
            }

            if (ids.length === 0) {
                alert(`"${categoryName}"类目下没有可撤回的记录`);
                return;
            }

            if (!confirm(`确定要撤回"${categoryName}"类目下的全部 ${ids.length} 条记录吗？此操作不可恢复！`)) {
                return;
            }

            showBatchRevokeModalWithIds(ids);
        }

        // 一键撤回本文下所有未撤回记录
        function revokeAllInPost(postTitle) {
            const ids = [];
            const seenIds = new Set();

            for (let hash in postRecords) {
                const records = postRecords[hash];
                if (records.length > 0 && records[0].post_title === postTitle) {
                    records.forEach(record => {
                        const isRevoked = record.username === '隐私表单重新填写' && record.answer && /\s{4}\d+:\S+:\S+@\S+$/.test(record.answer);
                        if (!isRevoked && !seenIds.has(record.id)) {
                            ids.push(record.id);
                            seenIds.add(record.id);
                        }
                    });
                }
            }

            if (ids.length === 0) {
                alert(`"${postTitle}"文章下没有可撤回的记录`);
                return;
            }

            if (!confirm(`确定要撤回"${postTitle}"文章下的全部 ${ids.length} 条记录吗？此操作不可恢复！`)) {
                return;
            }

            showBatchRevokeModalWithIds(ids);
        }
        
        async function processBatchGrantIds(ids) {
            let successCount = 0;
            
            for (const id of ids) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=grant_access&access_id=${id}`
                    });
                    const data = await response.json();
                    if(data.success) {
                        successCount++;
                        updateRecordInData(id, function(r) { r.access_granted = '1'; return r; });
                    }
                } catch(e) {
                    console.error(e);
                }
            }
            
            alert(`批量授权完成，成功 ${successCount}/${ids.length} 条记录`);
            refreshVisiblePanels();
        }
        
        // 发送回访邮件 - 使用模态框
        function sendFollowupEmail(accessId, userId, postId) {
            // 创建自定义内容输入模态框
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'followupEmailModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'followupEmailModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            
            modal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="followupEmailModalLabel">
                                <i class="bi bi-envelope-plus"></i> 发送回访邮件
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>提示：</strong>您可以在下方添加个性化内容，这些内容将插入到标准邮件模板中。
                            </div>
                            
                            <div class="mb-3">
                                <label for="customSubject" class="form-label">
                                    <i class="bi bi-tag"></i> 邮件主题（可选）
                                </label>
                                <input type="text" class="form-control" id="customSubject" 
                                       placeholder="留空使用默认主题：感谢您的访问 - 网站名称">
                            </div>
                            
                            <div class="mb-3">
                                <label for="customMessage" class="form-label">
                                    <i class="bi bi-chat-text"></i> 自定义消息内容
                                </label>
                                <textarea class="form-control" id="customMessage" rows="6" 
                                          placeholder="在此输入您想对用户说的话...&#10;&#10;例如：&#10;- 感谢您对我们内容的关注&#10;- 我们注意到您对某个话题特别感兴趣&#10;- 有什么问题可以随时联系我们&#10;- 期待您的反馈和建议"></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="includeContactInfo" checked>
                                    <label class="form-check-label" for="includeContactInfo">
                                        包含联系方式和反馈链接
                                    </label>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>注意：</strong>邮件将使用标准模板格式，您的自定义内容会插入到模板的适当位置。
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-circle"></i> 取消
                            </button>
                            <button type="button" class="btn btn-primary" onclick="confirmSendFollowupEmail(${accessId}, ${userId}, ${postId})">
                                <i class="bi bi-send"></i> 发送邮件
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // 初始化并显示模态框
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            // 模态框关闭时移除DOM元素
            modal.addEventListener('hidden.bs.modal', function () {
                document.body.removeChild(modal);
            });
        }
        
        // 确认发送回访邮件
        function confirmSendFollowupEmail(accessId, userId, postId) {
            const customSubject = document.getElementById('customSubject').value.trim();
            const customMessage = document.getElementById('customMessage').value.trim();
            const includeContactInfo = document.getElementById('includeContactInfo').checked;
            
            // 关闭模态框
            const modalElement = document.getElementById('followupEmailModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            modal.hide();
            
            // 显示发送状态
            showEmailSendingStatus('正在发送邮件...');
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=send_followup_email&access_id=${accessId}&user_id=${userId}&post_id=${postId}&custom_subject=${encodeURIComponent(customSubject)}&custom_message=${encodeURIComponent(customMessage)}&include_contact_info=${includeContactInfo ? '1' : '0'}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                hideEmailSendingStatus();
                
                if (data.success) {
                    showEmailResult('success', data.message);
                    updateFollowupButtonStatus(accessId, 'sent');
                } else {
                    showEmailResult('error', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                hideEmailSendingStatus();
                showEmailResult('error', '发送失败，请重试');
            });
        }
        
        // 显示邮件发送状态
        function showEmailSendingStatus(message) {
            const existingStatus = document.getElementById('emailSendingStatus');
            if (existingStatus) existingStatus.remove();
            
            const statusDiv = document.createElement('div');
            statusDiv.id = 'emailSendingStatus';
            statusDiv.className = 'position-fixed top-50 start-50 translate-middle';
            statusDiv.style.zIndex = '9999';
            statusDiv.innerHTML = `
                <div class="card shadow-lg">
                    <div class="card-body text-center p-4">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <h5 class="card-title">${message}</h5>
                        <p class="card-text text-muted">请稍候，不要关闭页面</p>
                    </div>
                </div>
            `;
            document.body.appendChild(statusDiv);
        }
        
        // 隐藏邮件发送状态
        function hideEmailSendingStatus() {
            const statusDiv = document.getElementById('emailSendingStatus');
            if (statusDiv) statusDiv.remove();
        }
        
        // 显示邮件发送结果
        function showEmailResult(type, message) {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const icon = type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle';
            
            const resultDiv = document.createElement('div');
            resultDiv.className = 'position-fixed top-0 start-50 translate-middle-x mt-3';
            resultDiv.style.zIndex = '9999';
            resultDiv.innerHTML = `
                <div class="alert ${alertClass} alert-dismissible fade show shadow" role="alert">
                    <i class="bi ${icon} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            
            document.body.appendChild(resultDiv);
            
            setTimeout(() => {
                if (resultDiv.parentNode) resultDiv.remove();
            }, 5000);
        }
        
        // 更新回访按钮状态
        function updateFollowupButtonStatus(accessId, status) {
            const buttons = document.querySelectorAll(`button[onclick*="sendFollowupEmail(${accessId}"]`);

            buttons.forEach(btn => {
                if (status === 'sent') {
                    const originalHtml = btn.innerHTML;
                    btn.innerHTML = '<i class="bi bi-check-circle"></i> 已发送';
                    btn.classList.remove('btn-outline-primary');
                    btn.classList.add('btn-success');
                    btn.disabled = true;

                    setTimeout(() => {
                        btn.innerHTML = originalHtml;
                        btn.classList.remove('btn-success');
                        btn.classList.add('btn-outline-primary');
                        btn.disabled = false;
                    }, 3000);
                }
            });
        }

        // 批量选择相关函数
        function toggleSelectAll(view) {
            const prefix = view === 'category' ? 'cat' : 'post';
            const checkboxes = document.querySelectorAll(`[id^="${prefix}Hash"] .batch-checkbox`);
            const selectAllCheckbox = document.getElementById(view === 'category' ? 'selectAllCategory' : 'selectAllPost');
            
            checkboxes.forEach(cb => {
                cb.checked = selectAllCheckbox.checked;
            });
            updateBatchUI();
        }

        function updateBatchUI() {
            const checkboxes = document.querySelectorAll('.batch-checkbox:checked');
            const count = checkboxes.length;
            const batchButtons = document.getElementById('batchActionButtons');
            const selectedCount = document.getElementById('selectedCount');

            if (count > 0) {
                batchButtons.style.display = 'block';
                selectedCount.textContent = `已选 ${count} 条`;
            } else {
                batchButtons.style.display = 'none';
            }
        }

        function getSelectedIds() {
            const checkboxes = document.querySelectorAll('.batch-checkbox:checked');
            return Array.from(checkboxes).map(cb => parseInt(cb.dataset.id));
        }

        function clearBatchSelection() {
            document.querySelectorAll('.batch-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAllCategory').checked = false;
            document.getElementById('selectAllPost').checked = false;
            updateBatchUI();
        }

        // 一键撤回全部：收集所有未撤回的记录ID，弹出批量撤回模态框
        function revokeAll() {
            const allIds = [];
            const seenIds = new Set();

            // 从分类视图中收集
            for (let hash in catRecords) {
                const records = catRecords[hash];
                records.forEach(record => {
                    // 排除已撤回的记录
                    const isRevoked = record.username === '隐私表单重新填写' && record.answer && /\s{4}\d+:\S+:\S+@\S+$/.test(record.answer);
                    if (!isRevoked && !seenIds.has(record.id)) {
                        allIds.push(record.id);
                        seenIds.add(record.id);
                    }
                });
            }

            // 从文章视图中收集（去重）
            for (let hash in postRecords) {
                const records = postRecords[hash];
                records.forEach(record => {
                    if (!seenIds.has(record.id)) {
                        const isRevoked = record.username === '隐私表单重新填写' && record.answer && /\s{4}\d+:\S+:\S+@\S+$/.test(record.answer);
                        if (!isRevoked) {
                            allIds.push(record.id);
                            seenIds.add(record.id);
                        }
                    }
                });
            }

            if (allIds.length === 0) {
                alert('没有可撤回的记录');
                return;
            }

            if (!confirm(`共找到 ${allIds.length} 条未撤回记录，确定要一键撤回全部吗？此操作不可恢复！`)) {
                return;
            }

            // 复用现有的批量撤回模态框
            showBatchRevokeModalWithIds(allIds);
        }

        // 带指定ID列表的批量撤回模态框（用于一键撤回全部）
        function showBatchRevokeModalWithIds(ids) {
            if (ids.length === 0) {
                alert('没有可撤回的记录');
                return;
            }

            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'batchRevokeModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-hidden', 'true');

            modal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-danger bg-opacity-10">
                            <h5 class="modal-title">
                                <i class="bi bi-exclamation-triangle text-danger"></i> 一键撤回全部
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>警告：</strong>此操作将撤回 <strong>全部 ${ids.length} 条</strong> 未撤回记录。此操作不可恢复！
                            </div>

                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="sendEmailToggle" checked onchange="toggleEmailFields(this.checked)">
                                    <label class="form-check-label" for="sendEmailToggle">
                                        <strong>发送撤回通知邮件</strong>
                                    </label>
                                </div>
                            </div>

                            <div id="emailFields">
                                <div class="mb-3">
                                    <label for="batchRevokeSubject" class="form-label">
                                        <i class="bi bi-tag"></i> 邮件主题（可编辑）
                                    </label>
                                    <input type="text" class="form-control" id="batchRevokeSubject"
                                           value="隐私内容填写提醒">
                                </div>

                                <div class="mb-3">
                                    <label for="batchRevokeBody" class="form-label">
                                        <i class="bi bi-chat-text"></i> 邮件内容（可编辑）
                                    </label>
                                    <textarea class="form-control" id="batchRevokeBody" rows="8" style="font-size: 14px;">您好，您在隐私内容填写中未表明详细信息，根据相关部门要求，将会封禁此用户以及登录的IP地址，1小时后生效。期间您可以给此邮箱回信重新说明情况。</textarea>
                                </div>

                                <div class="alert alert-warning">
                                    <i class="bi bi-info-circle"></i>
                                    请仔细检查邮件内容后再发送，发送后将自动执行全部撤回操作。
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-circle"></i> 取消
                            </button>
                            <button type="button" class="btn btn-danger" id="confirmBatchRevokeBtn" onclick="confirmBatchRevoke(${JSON.stringify(ids).replace(/"/g, '&quot;')})">
                                <i class="bi bi-check-circle"></i> 确认撤回全部
                            </button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();

            modal.addEventListener('hidden.bs.modal', function () {
                document.body.removeChild(modal);
            });
        }

        // 批量撤回+发邮件
        function showBatchRevokeModal() {
            const ids = getSelectedIds();
            if (ids.length === 0) {
                alert('请先选择要撤回的记录');
                return;
            }

            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'batchRevokeModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-hidden', 'true');

            modal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-danger bg-opacity-10">
                            <h5 class="modal-title">
                                <i class="bi bi-exclamation-triangle text-danger"></i> 批量撤回
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>警告：</strong>此操作将撤回选中的 ${ids.length} 条记录。此操作不可恢复！
                            </div>

                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="sendEmailToggle" checked onchange="toggleEmailFields(this.checked)">
                                    <label class="form-check-label" for="sendEmailToggle">
                                        <strong>发送撤回通知邮件</strong>
                                    </label>
                                </div>
                            </div>

                            <div id="emailFields">
                                <div class="mb-3">
                                    <label for="batchRevokeSubject" class="form-label">
                                        <i class="bi bi-tag"></i> 邮件主题（可编辑）
                                    </label>
                                    <input type="text" class="form-control" id="batchRevokeSubject"
                                           value="隐私内容填写提醒">
                                </div>

                                <div class="mb-3">
                                    <label for="batchRevokeBody" class="form-label">
                                        <i class="bi bi-chat-text"></i> 邮件内容（可编辑）
                                    </label>
                                    <textarea class="form-control" id="batchRevokeBody" rows="8" style="font-size: 14px;">您好，您在隐私内容填写中未表明详细信息，根据相关部门要求，将会封禁此用户以及登录的IP地址，1小时后生效。期间您可以给此邮箱回信重新说明情况。</textarea>
                                </div>

                                <div class="alert alert-warning">
                                    <i class="bi bi-info-circle"></i>
                                    请仔细检查邮件内容后再发送，发送后将自动执行批量撤回操作。
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-circle"></i> 取消
                            </button>
                            <button type="button" class="btn btn-danger" id="confirmBatchRevokeBtn" onclick="confirmBatchRevoke(${JSON.stringify(ids).replace(/"/g, '&quot;')})">
                                <i class="bi bi-check-circle"></i> 确认撤回
                            </button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();

            modal.addEventListener('hidden.bs.modal', function () {
                document.body.removeChild(modal);
            });
        }

        // 切换邮件字段显示
        function toggleEmailFields(checked) {
            const emailFields = document.getElementById('emailFields');
            const confirmBtn = document.getElementById('confirmBatchRevokeBtn');
            
            if (checked) {
                emailFields.style.display = 'block';
                confirmBtn.innerHTML = '<i class="bi bi-send"></i> 发送邮件并撤回';
            } else {
                emailFields.style.display = 'none';
                confirmBtn.innerHTML = '<i class="bi bi-check-circle"></i> 确认撤回';
            }
        }

        // 确认批量撤回
        function confirmBatchRevoke(ids) {
            if (ids.length === 0) {
                alert('没有选中的记录');
                return;
            }

            const sendEmail = document.getElementById('sendEmailToggle').checked;
            const subject = document.getElementById('batchRevokeSubject').value.trim();
            const body = document.getElementById('batchRevokeBody').value.trim();

            if (sendEmail && !body) {
                alert('邮件内容不能为空');
                return;
            }

            const modalElement = document.getElementById('batchRevokeModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            modal.hide();

            const action = sendEmail ? 'batch_revoke_with_email' : 'batch_revoke_no_email';
            const loadingMsg = sendEmail ? `正在处理 ${ids.length} 条记录的撤回操作...` : `正在撤回 ${ids.length} 条记录...`;

            showEmailSendingStatus(loadingMsg);

            let postBody = `action=${action}&ids=${encodeURIComponent(JSON.stringify(ids))}`;
            if (sendEmail) {
                postBody += `&email_subject=${encodeURIComponent(subject)}&email_body=${encodeURIComponent(body)}`;
            }

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: postBody
            })
            .then(res => res.json())
            .then(data => {
                hideEmailSendingStatus();
                alert(data.message);
                if (data.success) {
                    clearBatchSelection();
                    for (const id of ids) {
                        updateRecordInData(id, function(r) {
                            if (r.username !== '隐私表单重新填写') {
                                r.answer = (r.answer || '') + '    ' + (r.user_id || '0') + ':' + (r.username || '') + ':' + (r.email || '');
                                r.username = '隐私表单重新填写';
                                r.email = 'X#######@qq.com';
                                r.access_granted = '0';
                            }
                            return r;
                        });
                    }
                    refreshVisiblePanels();
                }
            })
            .catch(error => {
                hideEmailSendingStatus();
                alert('操作失败，请重试');
            });
        }
    </script>
<?php require_once 'includes/footer.php'; ?>
