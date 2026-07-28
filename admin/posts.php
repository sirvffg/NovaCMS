<?php
// 开启全局缓冲，捕获所有非预期输出（如BOM头、警告等）
ob_start();

session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/ai_functions.php';
require_once '../vendor/generate-rss.php';

requireLogin();

$db = getDB();
aiEnsureSchema($db);

// 自动添加支付相关字段
try {
    $db->exec("ALTER TABLE blog_posts ADD COLUMN has_paid_content TINYINT(1) DEFAULT 0 AFTER has_privacy_content");
    $db->exec("ALTER TABLE blog_posts ADD COLUMN post_price DECIMAL(10,2) DEFAULT 0.00 AFTER has_paid_content");
} catch (Exception $e) {
    // 忽略已存在列的错误
}

// 自动添加隐私自定义提示字段
try {
    $db->exec("ALTER TABLE blog_posts ADD COLUMN privacy_custom_text TEXT DEFAULT NULL AFTER approval_required");
} catch (Exception $e) {
    // 忽略已存在列的错误
}

// $config 移到 AJAX 处理之后，避免未捕获异常影响 JSON 返回

// 处理Ajax请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // 清除缓冲区之前的所有内容
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    try {
        switch ($action) {
            case 'save':
                // 保存文章
                $id = $_POST['id'] ?? null;
                $title = trim($_POST['title'] ?? '');
                $content = trim($_POST['content'] ?? '');
                
                // 处理编码的字段（绕过 WAF）
                if (isset($_POST['encoding']) && $_POST['encoding'] === 'html_entities') {
                    // 解码 HTML 实体
                    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                // 数据库保持本地URL
                $author = trim($_POST['author'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $tags_input = trim($_POST['tags'] ?? '');
                $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
                $is_featured = isset($_POST['is_featured']) ? 1 : 0;
                $is_published = isset($_POST['is_published']) ? (int)$_POST['is_published'] : 1;
                
                // 隐私内容相关参数
                $has_privacy_content = isset($_POST['has_privacy_content']) ? 1 : 0;
                $privacy_question = trim($_POST['privacy_question'] ?? '');
                $privacy_answer = trim($_POST['privacy_answer'] ?? '');
                $privacy_type = $_POST['privacy_type'] ?? 'fixed_answer';
                $approval_required = ($privacy_type === 'open_answer' && isset($_POST['approval_required'])) ? 1 : 0;
                $privacy_custom_text = trim($_POST['privacy_custom_text'] ?? '');
                
                // 支付内容相关参数
                $has_paid_content = isset($_POST['has_paid_content']) ? 1 : 0;
                $post_price = isset($_POST['post_price']) ? (float)$_POST['post_price'] : 0.00;
                if ($has_paid_content && $post_price <= 0) {
                    $has_paid_content = 0;
                }
                
                // 如果使用了 HTML 实体编码，解码隐私问题
                if (isset($_POST['encoding']) && $_POST['encoding'] === 'html_entities' && !empty($privacy_question)) {
                    $privacy_question = html_entity_decode($privacy_question, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                
                // 如果启用了隐私内容但未设置问题，则自动关闭隐私功能
                // 但login_only类型不需要问题
                if ($has_privacy_content && $privacy_type !== 'login_only' && empty($privacy_question)) {
                    $has_privacy_content = 0;
                }
                
                // 只有固定答案类型需要加密答案
                if ($has_privacy_content && $privacy_type === 'fixed_answer' && !empty($privacy_answer)) {
                    $privacy_answer = md5(strtolower($privacy_answer));
                } else if ($privacy_type === 'login_only') {
                    // 仅需登录，不需要问题和答案
                    $privacy_question = '';
                    $privacy_answer = null;
                } else if ($privacy_type !== 'fixed_answer') {
                    // 开放答案和人工审核不需要存储答案
                    $privacy_answer = null;
                } else {
                    $privacy_answer = null;
                }
                
                // 音频相关参数
                $data_music_file = $_POST['data_music_file'] ?? '';
                $data_lyric_file = $_POST['data_lyric_file'] ?? '';
                $data_music_enabled = isset($_POST['data_music_enabled']) ? 'true' : 'false';
                $data_autoplay = $_POST['data_autoplay'] ?? 'false';
                $data_position = $_POST['data_position'] ?? 'static';
                $data_theme = $_POST['data_theme'] ?? 'auto';
                $data_size = $_POST['data_size'] ?? 'normal';
                $data_embed = isset($_POST['data_embed']) ? 'true' : 'false';
                $data_cover_mode = isset($_POST['data_cover_mode']) ? 'true' : 'false';
                
                // 封面图片参数
                $cover_image = $_POST['cover_image'] ?? '';
                
                // 许可协议参数
                $license = $_POST['license'] ?? 'CC BY-NC-SA 4.0';
                
                // 验证必填字段
                if (empty($title)) {
                    throw new Exception('文章标题不能为空');
                }
                
                if (empty($content)) {
                    throw new Exception('文章内容不能为空');
                }
                
                if (empty($author)) {
                    throw new Exception('作者不能为空');
                }
                
                // 处理标签
                $tags = '';
                if (!empty($tags_input)) {
                    $tags_array = array_filter(explode('#', trim($tags_input, '#')));
                    $tags_array = array_unique(array_map('trim', $tags_array));
                    $tags = implode(',', $tags_array);
                }
                
                if ($id) {
                    $stmt = $db->prepare("UPDATE blog_posts SET title=?, content=?, author=?, category=?, tags=?, is_pinned=?, is_featured=?, has_privacy_content=?, privacy_question=?, privacy_answer=?, privacy_type=?, approval_required=?, privacy_custom_text=?, has_paid_content=?, post_price=?, data_music_file=?, data_lyric_file=?, data_music_enabled=?, data_autoplay=?, data_position=?, data_theme=?, data_size=?, data_embed=?, data_cover_mode=?, cover_image=?, license=?, is_published=?, updated_at=NOW() WHERE id=?");
                    $stmt->execute([$title, $content, $author, $category, $tags, $is_pinned, $is_featured, $has_privacy_content, $privacy_question, $privacy_answer, $privacy_type, $approval_required, $privacy_custom_text, $has_paid_content, $post_price, $data_music_file, $data_lyric_file, $data_music_enabled, $data_autoplay, $data_position, $data_theme, $data_size, $data_embed, $data_cover_mode, $cover_image, $license, $is_published, $id]);
                    $response = [
                        'success' => true,
                        'message' => '文章已更新',
                        'id' => $id,
                        'isNew' => false
                    ];
                } else {
                    try {
                        // 获取当前最大ID，生成新ID
                        $maxIdResult = $db->query("SELECT MAX(id) as max_id FROM blog_posts")->fetch();
                        $newId = ($maxIdResult['max_id'] ?? 0) + 1;
                        
                        // 完整的INSERT语句
                        $sql = "INSERT INTO blog_posts (
                            id, 
                            title, 
                            content, 
                            summary, 
                            author, 
                            cover_image, 
                            category, 
                            tags, 
                            views, 
                            is_published, 
                            is_pinned, 
                            is_featured, 
                            published_at, 
                            created_at, 
                            updated_at, 
                            data_music_file, 
                            data_lyric_file, 
                            data_music_enabled, 
                            data_autoplay, 
                            data_position, 
                            data_theme, 
                            data_size, 
                            data_embed, 
                            data_cover_mode, 
                            privacy_question, 
                            privacy_answer, 
                            has_privacy_content, 
                            privacy_type, 
                            approval_required, 
                            privacy_custom_text,
                            has_paid_content,
                            post_price,
                            license
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                            ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?
                        )";
                        
                        $params = [
                            $newId,                      // 1: id
                            $title,                      // 2: title
                            $content,                    // 3: content
                            null,                        // 4: summary
                            $author,                     // 5: author
                            $cover_image,                // 6: cover_image
                            $category,                   // 7: category
                            $tags,                       // 8: tags
                            0,                           // 9: views
                            $is_published,               // 10: is_published
                            $is_pinned,                  // 11: is_pinned
                            $is_featured,                // 12: is_featured
                            null,                        // 13: published_at
                            // 14: created_at - NOW()
                            // 15: updated_at - NOW()
                            $data_music_file,            // 16: data_music_file
                            $data_lyric_file,            // 17: data_lyric_file
                            $data_music_enabled,         // 18: data_music_enabled
                            $data_autoplay,              // 19: data_autoplay
                            $data_position,              // 20: data_position
                            $data_theme,                 // 21: data_theme
                            $data_size,                  // 22: data_size
                            $data_embed,                 // 23: data_embed
                            $data_cover_mode,            // 24: data_cover_mode
                            $privacy_question,           // 25: privacy_question
                            $privacy_answer,             // 26: privacy_answer
                            $has_privacy_content,        // 27: has_privacy_content
                            $privacy_type,               // 28: privacy_type
                            $approval_required,          // 29: approval_required
                            $privacy_custom_text,        // 30: privacy_custom_text
                            $has_paid_content,           // 31: has_paid_content
                            $post_price,                 // 32: post_price
                            $license                     // 33: license
                        ];
                        
                        // 执行INSERT
                        $stmt = $db->prepare($sql);
                        $result = $stmt->execute($params);
                        
                        if (!$result) {
                            $errorInfo = $stmt->errorInfo();
                            throw new Exception("SQL执行失败: " . $errorInfo[2] . " (SQL State: " . $errorInfo[0] . ")");
                        }
                        
                        $id = $newId;
                        $response = [
                            'success' => true,
                            'message' => '文章已发布',
                            'id' => $id,
                            'isNew' => true,
                            'debug' => [
                                'sql' => $sql,
                                'params_count' => count($params),
                                'new_id' => $newId
                            ]
                        ];
                    } catch (PDOException $e) {
                        throw new Exception("数据库错误: " . $e->getMessage() . 
                            "<br>SQL State: " . $e->getCode() . 
                            "<br>字段数: 30 (含created_at和updated_at)<br>参数占位符: 28个? + 2个NOW()<br>实际参数: " . count($params) .
                            "<br>New ID: {$newId}");
                    }
                }
                // 保存后重新生成 RSS
                if (!empty($response['success'])) {
                    generateRssXml();
                }
                break;

            case 'delete':
                // 删除文章
                $id = $_POST['id'] ?? null;
                if (!$id) {
                    throw new Exception('文章ID不能为空');
                }
                
                // 先获取文章数据，获取关联文件
                $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id=?");
                $stmt->execute([$id]);
                $post = $stmt->fetch();
                
                if (!$post) {
                    throw new Exception('文章不存在');
                }

                // 删除相关文件
                $deleted_files = [];

                // 删除封面图片
                if (!empty($post['cover_image'])) {
                    $cover_url = ltrim($post['cover_image'], '/');

                    // 检查封面是否被其他文章使用
                    $coverUsageStmt = $db->prepare("SELECT COUNT(*) as cnt FROM blog_posts WHERE content LIKE ? AND id != ?");
                    $coverUsageStmt->execute(['%' . $post['cover_image'] . '%', $id]);
                    $coverUsageCount = (int) $coverUsageStmt->fetch()['cnt'];

                    if ($coverUsageCount === 0) {
                        // 封面只被这篇文章使用，删除本地文件
                        $coverPath = '../' . $cover_url;
                        if (file_exists($coverPath) && unlink($coverPath)) {
                            $deleted_files[] = $post['cover_image'];
                        }
                    }
                }

                // 删除音频文件
                if (!empty($post['data_music_file'])) {
                    $audio_path = '../' . $post['data_music_file'];
                    if (file_exists($audio_path) && unlink($audio_path)) {
                        $deleted_files[] = $post['data_music_file'];
                    }
                }

                // 删除歌词文件
                if (!empty($post['data_lyric_file'])) {
                    $lyric_path = '../' . $post['data_lyric_file'];
                    if (file_exists($lyric_path) && unlink($lyric_path)) {
                        $deleted_files[] = $post['data_lyric_file'];
                    }
                }

                // 从文章内容中提取并删除本地图片/视频
                $content = $post['content'] ?? '';
                if (!empty($content)) {
                    // 提取 Markdown 图片语法中的图片
                    preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $content, $md_img_matches);

                    // 提取 img 标签的 src
                    preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $img_matches);

                    // 提取 video/source 标签的 src
                    preg_match_all('/<(?:video|source)[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $video_matches);

                    // 合并所有媒体URL
                    $all_media = array_merge($md_img_matches[2] ?? [], $img_matches[1] ?? [], $video_matches[1] ?? []);

                    foreach ($all_media as $media_src) {
                        // 只处理本地文件（uploads目录）
                        if (strpos($media_src, '/uploads/') !== false) {
                            // 检查图片是否被其他文章使用
                            $usageStmt = $db->prepare("SELECT COUNT(*) as cnt FROM blog_posts WHERE content LIKE ? AND id != ?");
                            $usageStmt->execute(['%' . $media_src . '%', $id]);
                            $usageCount = (int) $usageStmt->fetch()['cnt'];

                            // 只有当图片只被这一篇文章使用时才删除本地文件
                            if ($usageCount === 0) {
                                $mediaPath = '../' . ltrim($media_src, '/');
                                if (file_exists($mediaPath) && unlink($mediaPath)) {
                                    $deleted_files[] = $media_src;
                                }
                            }
                        }
                    }
                }

                // 删除文章
                $stmt = $db->prepare("DELETE FROM blog_posts WHERE id=?");
                $stmt->execute([$id]);

                $message = '文章已删除';
                if (count($deleted_files) > 0) {
                    $message .= '，已清理 ' . count($deleted_files) . ' 个本地文件';
                }

                $response = [
                    'success' => true,
                    'message' => $message,
                    'id' => $id,
                    'deleted_files' => $deleted_files
                ];
                // 删除后重新生成 RSS
                generateRssXml();
                break;
                
            case 'get_post':
                // 获取文章数据
                $id = $_POST['id'] ?? null;
                if (!$id) {
                    throw new Exception('文章ID不能为空');
                }
                
                $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id=?");
                $stmt->execute([$id]);
                $post = $stmt->fetch();
                
                if ($post) {
                    $response = [
                        'success' => true,
                        'post' => $post
                    ];
                } else {
                    throw new Exception('文章不存在');
                }
                break;
                
            case 'upload_audio':
                // 上传音频文件
                if (!isset($_FILES['audio_file'])) {
                    throw new Exception('没有上传文件');
                }
                
                $file = $_FILES['audio_file'];
                $uploadDir = '../uploads/audio/';
                $fileName = $file['name'];
                
                // 检查目录是否存在，不存在则创建
                if (!file_exists($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        throw new Exception('无法创建上传目录');
                    }
                }
                
                // 检查目录是否可写
                if (!is_writable($uploadDir)) {
                    throw new Exception('上传目录不可写');
                }
                
                // 检查文件类型
                $allowedTypes = ['audio/mpeg', 'audio/wav', 'audio/mp3', 'audio/m4a', 'audio/ogg'];
                if (!in_array($file['type'], $allowedTypes)) {
                    throw new Exception('不支持的音频格式，请上传 MP3、WAV、M4A 或 OGG 格式');
                }
                
                // 检查文件大小 (20MB)
                if ($file['size'] > 20 * 1024 * 1024) {
                    throw new Exception('音频文件大小不能超过 20MB');
                }
                
                // 检查上传错误
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE => '文件大小超过 php.ini 中设置的限制',
                        UPLOAD_ERR_FORM_SIZE => '文件大小超过表单中设置的限制',
                        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                        UPLOAD_ERR_NO_FILE => '没有文件被上传',
                        UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                        UPLOAD_ERR_CANT_WRITE => '文件写入失败'
                    ];
                    $errorMsg = $uploadErrors[$file['error']] ?? '未知上传错误';
                    throw new Exception('上传错误: ' . $errorMsg);
                }
                
                // 检查是否已存在同名文件
                if (file_exists($uploadDir . $fileName)) {
                    $response = [
                        'success' => true,
                        'message' => '音频文件已存在，直接使用',
                        'file_path' => 'uploads/audio/' . $fileName,
                        'file_name' => $fileName,
                        'exists' => true
                    ];
                } else {
                    // 上传新文件
                    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                        throw new Exception('文件上传失败，可能是权限问题');
                    }
                    
                    $response = [
                        'success' => true,
                        'message' => '音频上传成功',
                        'file_path' => 'uploads/audio/' . $fileName,
                        'file_name' => $fileName,
                        'exists' => false
                    ];
                }
                break;
                
            case 'upload_lyric':
                // 上传歌词文件
                if (!isset($_FILES['lyric_file'])) {
                    throw new Exception('没有上传歌词文件');
                }
                
                $file = $_FILES['lyric_file'];
                $uploadDir = '../uploads/audio/';
                $fileName = $file['name'];
                
                // 检查目录是否存在，不存在则创建
                if (!file_exists($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        throw new Exception('无法创建上传目录');
                    }
                }
                
                // 检查目录是否可写
                if (!is_writable($uploadDir)) {
                    throw new Exception('上传目录不可写');
                }
                
                // 检查文件类型
                $allowedTypes = ['text/plain', 'application/octet-stream'];
                if (!in_array($file['type'], $allowedTypes)) {
                    throw new Exception('歌词文件必须是 .lrc 或 .txt 格式');
                }
                
                // 检查文件扩展名
                $fileInfo = pathinfo($file['name']);
                if (!in_array(strtolower($fileInfo['extension']), ['lrc', 'txt'])) {
                    throw new Exception('歌词文件必须是 .lrc 或 .txt 格式');
                }
                
                // 检查文件大小 (1MB)
                if ($file['size'] > 1024 * 1024) {
                    throw new Exception('歌词文件大小不能超过 1MB');
                }
                
                // 检查上传错误
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE => '文件大小超过 php.ini 中设置的限制',
                        UPLOAD_ERR_FORM_SIZE => '文件大小超过表单中设置的限制',
                        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                        UPLOAD_ERR_NO_FILE => '没有文件被上传',
                        UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                        UPLOAD_ERR_CANT_WRITE => '文件写入失败'
                    ];
                    $errorMsg = $uploadErrors[$file['error']] ?? '未知上传错误';
                    throw new Exception('上传错误: ' . $errorMsg);
                }
                
                // 检查是否已存在同名文件
                if (file_exists($uploadDir . $fileName)) {
                    $response = [
                        'success' => true,
                        'message' => '歌词文件已存在，直接使用',
                        'file_path' => 'uploads/audio/' . $fileName,
                        'file_name' => $fileName,
                        'exists' => true
                    ];
                } else {
                    // 上传新文件
                    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                        throw new Exception('歌词文件上传失败，可能是权限问题');
                    }
                    
                    $response = [
                        'success' => true,
                        'message' => '歌词上传成功',
                        'file_path' => 'uploads/audio/' . $fileName,
                        'file_name' => $fileName,
                        'exists' => false
                    ];
                }
                break;
                
            case 'upload_cover':
                // 上传封面图片
                if (!isset($_FILES['cover_image_file'])) {
                    throw new Exception('没有上传封面图片');
                }
                
                $file = $_FILES['cover_image_file'];
                $uploadDir = '../uploads/cover/';
                $fileName = $file['name'];
                
                // 检查目录是否存在，不存在则创建
                if (!file_exists($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        throw new Exception('无法创建上传目录');
                    }
                }
                
                // 检查目录是否可写
                if (!is_writable($uploadDir)) {
                    throw new Exception('上传目录不可写');
                }
                
                // 检查文件类型
                $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($file['type'], $allowedTypes)) {
                    throw new Exception('封面图片必须是 JPG、PNG、GIF 或 WEBP 格式');
                }
                
                // 检查文件扩展名
                $fileInfo = pathinfo($file['name']);
                if (!in_array(strtolower($fileInfo['extension']), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    throw new Exception('封面图片必须是 JPG、PNG、GIF 或 WEBP 格式');
                }
                
                // 检查文件大小 (5MB)
                if ($file['size'] > 5 * 1024 * 1024) {
                    throw new Exception('封面图片大小不能超过 5MB');
                }
                
                // 检查上传错误
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE => '文件大小超过 php.ini 中设置的限制',
                        UPLOAD_ERR_FORM_SIZE => '文件大小超过表单中设置的限制',
                        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                        UPLOAD_ERR_NO_FILE => '没有文件被上传',
                        UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                        UPLOAD_ERR_CANT_WRITE => '文件写入失败'
                    ];
                    $errorMsg = $uploadErrors[$file['error']] ?? '未知上传错误';
                    throw new Exception('上传错误: ' . $errorMsg);
                }
                
                // 生成唯一文件名，避免冲突
                $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
                $uniqueFileName = uniqid('cover_') . '.' . $fileExt;
                $filePath = $uploadDir . $uniqueFileName;
                
                // 上传文件
                if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                    throw new Exception('封面图片上传失败，可能是权限问题');
                }
                
                // 获取配置
                $localUrl = '/uploads/cover/' . $uniqueFileName;

                $response = [
                    'success' => true,
                    'message' => '封面上传成功（本地存储）',
                    'file_path' => $localUrl,
                    'file_name' => $uniqueFileName
                ];
                break;
                
            case 'get_posts':
                // 获取所有文章列表
                $posts = $db->query("SELECT * FROM blog_posts ORDER BY is_pinned DESC, created_at DESC")->fetchAll();
                
                // 获取所有分类
                $categories = $db->query("SELECT * FROM blog_categories ORDER BY sort_order, name")->fetchAll();
                $categoryMap = [];
                foreach ($categories as $cat) {
                    $categoryMap[$cat['name']] = $cat['color'] ?? '#007bff';
                }
                
                $html = '';
                if (empty($posts)) {
                    $html = '<tr><td colspan="8" class="text-center py-5"><i class="bi bi-file-text display-1 text-muted"></i><p class="lead mt-3">还没有文章，赶快发布一篇吧！</p></td></tr>';
                } else {
                    foreach ($posts as $post) {
                        $postCategory = $post['category'] ?? '';
                        $categoryColor = isset($categoryMap[$postCategory]) ? $categoryMap[$postCategory] : '#007bff';
                        $postTags = $post['tags'] ? explode(',', $post['tags']) : [];
                        
                        $html .= '<tr id="post-row-' . $post['id'] . '" class="post-row">';
                        $html .= '<td>';
                        $html .= '<strong>' . htmlspecialchars($post['title']) . '</strong>';
                        if ($post['is_pinned']) {
                            $html .= '<i class="bi bi-pin-angle-fill text-danger" title="置顶"></i>';
                        }
                        if ($post['is_featured']) {
                            $html .= '<i class="bi bi-star-fill text-warning" title="精选"></i>';
                        }
                        $html .= '</td>';
                        $html .= '<td>' . htmlspecialchars($post['author']) . '</td>';
                        $html .= '<td>';
                        if ($postCategory) {
                            $html .= '<span class="badge category-badge" style="background-color: ' . $categoryColor . '">';
                            $html .= '<span class="color-dot" style="background-color: ' . adjustBrightness($categoryColor, 30) . '"></span>';
                            $html .= htmlspecialchars($postCategory);
                            $html .= '</span>';
                        } else {
                            $html .= '<span class="text-muted">-</span>';
                        }
                        $html .= '</td>';
                        $html .= '<td>';
                        if (!empty($postTags)) {
                            $html .= '<div style="max-width: 200px;">';
                            foreach ($postTags as $tag) {
                                if (trim($tag)) {
                                    $tagColor = isset($categoryMap[trim($tag)]) ? $categoryMap[trim($tag)] : getRandomColor(trim($tag));
                                    $html .= '<span class="badge tag-badge" style="background-color: ' . $tagColor . '">';
                                    $html .= '<span class="tag-color-dot" style="background-color: ' . adjustBrightness($tagColor, 30) . '"></span>';
                                    $html .= htmlspecialchars(trim($tag));
                                    $html .= '</span>';
                                }
                            }
                            $html .= '</div>';
                        } else {
                            $html .= '<span class="text-muted">-</span>';
                        }
                        $html .= '</td>';
                        $html .= '<td>';
                        if (!$post['is_published']) {
                            $html .= '<span class="badge bg-secondary">草稿</span>';
                        }
                        if ($post['is_pinned']) {
                            $html .= '<span class="badge bg-danger">置顶</span>';
                        }
                        if ($post['is_featured']) {
                            $html .= '<span class="badge bg-warning">精选</span>';
                        }
                        $html .= '</td>';
                        $html .= '<td><span class="badge bg-info">' . $post['views'] . '</span></td>';
                        $html .= '<td><small class="text-muted">' . date('Y-m-d H:i', strtotime($post['created_at'])) . '</small></td>';
                        $html .= '<td>';
                        $html .= '<div class="btn-group btn-group-sm" role="group">';
                        $html .= '<a href="/blog.php?id=' . $post['id'] . '" class="btn btn-outline-info" target="_blank" title="查看"><i class="bi bi-eye"></i></a>';
                        $html .= '<button type="button" class="btn btn-outline-primary edit-post" data-id="' . $post['id'] . '" title="编辑"><i class="bi bi-pencil"></i></button>';
                        $html .= '<button type="button" class="btn btn-outline-danger delete-post" data-id="' . $post['id'] . '" title="删除"><i class="bi bi-trash"></i></button>';
                        $html .= '</div>';
                        $html .= '</td>';
                        $html .= '</tr>';
                    }
                }
                
                $response = [
                    'success' => true,
                    'html' => $html
                ];
                break;

            case 'ai_generate_summary':
                if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
                    throw new Exception('安全验证失败，请刷新页面后重试');
                }
                $postId = (int)($_POST['post_id'] ?? 0);
                $modelPick = isset($_POST['ai_model_id']) ? trim((string)$_POST['ai_model_id']) : '';
                $modelOpt = $modelPick !== '' ? (int)$modelPick : null;
                if ($postId <= 0) {
                    throw new Exception('无效的文章 ID');
                }
                $adminId = (int)$_SESSION['admin_id'];
                $gen = aiGeneratePostSummary($db, $postId, $adminId, $modelOpt);
                if (!$gen['success']) {
                    throw new Exception($gen['message']);
                }
                $response = [
                    'success' => true,
                    'message' => $gen['message'],
                    'summary' => $gen['summary'] ?? '',
                ];
                break;
                
            default:
                throw new Exception('不支持的操作类型');
        }
    } catch (Throwable $e) {
        $response = [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
    
    // 清除缓冲区并输出JSON
    if (ob_get_length()) ob_clean();
    echo json_encode($response);
    exit();
}

// 获取全局配置（AJAX 请求不需要，且如果出错会导致 HTML 错误页）
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 获取分页参数
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// 确定当前激活的标签页
$activeTab = $_GET['tab'] ?? 'posts';

// 处理分类操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['category_action'])) {
    $action = $_POST['category_action'];
    
    if ($action === 'delete' && isset($_POST['category_id'])) {
        $db->prepare("DELETE FROM blog_categories WHERE id=?")->execute([$_POST['category_id']]);
        $_SESSION['success'] = '分类已删除';
        header('Location: posts.php?tab=categories');
        exit;
    } elseif ($action === 'save') {
        $id = $_POST['category_id'] ?? null;
        $name = trim($_POST['category_name'] ?? '');
        $slug = trim($_POST['category_slug'] ?? '');
        $description = trim($_POST['category_description'] ?? '');
        $sort_order = intval($_POST['category_sort_order'] ?? 0);
        $color = $_POST['category_color'] ?? '#007bff';
        
        if (empty($slug)) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        }
        
        // 检查重复
        if ($id) {
            $check = $db->prepare("SELECT id FROM blog_categories WHERE slug=? AND id!=?");
            $check->execute([$slug, $id]);
        } else {
            $check = $db->prepare("SELECT id FROM blog_categories WHERE slug=?");
            $check->execute([$slug]);
        }
        
        if ($check->fetch()) {
            $_SESSION['error'] = '分类别名已存在';
        } else {
            if ($id) {
                $stmt = $db->prepare("UPDATE blog_categories SET name=?, slug=?, description=?, sort_order=?, color=? WHERE id=?");
                $stmt->execute([$name, $slug, $description, $sort_order, $color, $id]);
                $_SESSION['success'] = '分类已更新';
            } else {
                $stmt = $db->prepare("INSERT INTO blog_categories (name, slug, description, sort_order, color) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $slug, $description, $sort_order, $color]);
                $_SESSION['success'] = '分类已添加';
            }
            header('Location: posts.php?tab=categories');
            exit;
        }
    }
}

// 处理评论操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_action'])) {
    $action = $_POST['comment_action'];
    
    if ($action === 'delete' && isset($_POST['comment_id'])) {
        $comment_id = $_POST['comment_id'];
        $db->prepare("DELETE FROM blog_comments WHERE id=?")->execute([$comment_id]);
        $_SESSION['success'] = '评论已删除';
        header('Location: posts.php?tab=comments');
        exit;
    } elseif (($action === 'approve' || $action === 'reject') && isset($_POST['comment_id'])) {
        $comment_id = $_POST['comment_id'];
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $db->prepare("UPDATE blog_comments SET status = ? WHERE id = ?");
        $stmt->execute([$status, $comment_id]);
        $_SESSION['success'] = $status === 'approved' ? '评论已批准' : '评论已拒绝';
        header('Location: posts.php?tab=comments');
        exit;
    }
}

// 获取所有分类作为标签选项（用于初始页面加载）
$categories = $db->query("SELECT * FROM blog_categories ORDER BY sort_order, name")->fetchAll();
$categoryMap = [];
foreach ($categories as $cat) {
    $categoryMap[$cat['name']] = $cat['color'] ?? '#007bff';
}

// 获取文章总数（用于分页）
$totalPosts = $db->query("SELECT COUNT(*) as total FROM blog_posts")->fetch()['total'];
$total_pages = ceil($totalPosts / $per_page);

// 获取文章列表（带分页）
$posts = $db->query("SELECT * FROM blog_posts ORDER BY is_pinned DESC, created_at DESC LIMIT {$per_page} OFFSET {$offset}")->fetchAll();

// 如果有edit参数，预加载文章数据
$editPost = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $editPost = $stmt->fetch();
}

$aiModelsEnabled = [];
try {
    $aiModelsEnabled = $db->query("SELECT id, name, model_id, is_default FROM blog_ai_models WHERE enabled = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (Exception $e) {
    $aiModelsEnabled = [];
}

// 获取编辑的分类
$editCategory = null;
if (isset($_GET['edit_category'])) {
    $stmt = $db->prepare("SELECT * FROM blog_categories WHERE id=?");
    $stmt->execute([$_GET['edit_category']]);
    $editCategory = $stmt->fetch();
}

// 获取评论分页参数
$comment_page = max(1, intval($_GET['comment_page'] ?? 1));
$comment_per_page = 20;
$comment_offset = ($comment_page - 1) * $comment_per_page;

// 获取评论筛选参数
$status_filter = $_GET['status'] ?? 'all';
$post_filter = $_GET['post'] ?? 'all';

// 构建查询条件
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "c.status = ?";
    $params[] = $status_filter;
}

if ($post_filter !== 'all' && is_numeric($post_filter)) {
    $where_conditions[] = "c.post_id = ?";
    $params[] = $post_filter;
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// 获取评论总数
$count_sql = "SELECT COUNT(*) as total FROM blog_comments c $where_clause";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_comments = $stmt->fetch()['total'];
$total_comment_pages = ceil($total_comments / $comment_per_page);

// 获取评论列表
$sql = "
    SELECT c.*, p.title as post_title, a.username as admin_username, a.role as user_role,
           (SELECT COUNT(*) FROM blog_comments WHERE parent_id = c.id) as reply_count
    FROM blog_comments c
    LEFT JOIN blog_posts p ON c.post_id = p.id
    LEFT JOIN admins a ON c.user_id = a.id
    $where_clause
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
";
$params[] = $comment_per_page;
$params[] = $comment_offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$comments = $stmt->fetchAll();

// 获取所有文章用于筛选
$posts_for_filter = $db->query("SELECT id, title FROM blog_posts ORDER BY title ASC")->fetchAll();

// 获取评论统计
$stats = [
    'total' => $db->query("SELECT COUNT(*) as count FROM blog_comments")->fetch()['count'],
    'approved' => $db->query("SELECT COUNT(*) as count FROM blog_comments WHERE status = 'approved'")->fetch()['count'],
    'pending' => $db->query("SELECT COUNT(*) as count FROM blog_comments WHERE status = 'pending'")->fetch()['count'],
    'rejected' => $db->query("SELECT COUNT(*) as count FROM blog_comments WHERE status = 'rejected'")->fetch()['count']
];
$page_title = '博客管理';
$extra_css = <<<'CSS'
.category-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 13px;
    color: white;
    font-weight: 500;
}
.color-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 6px;
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.tag-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 12px;
    color: white;
    margin: 2px;
}
.tag-color-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 4px;
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.tag-input-hint {
    font-size: 12px;
    color: #6c757d;
    margin-top: 5px;
}
.tags-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 10px;
    min-height: 40px;
    padding: 10px;
    border: 1px dashed #dee2e6;
    border-radius: 0.375rem;
    background-color: #f8f9fa;
}
.suggested-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 10px;
}
.suggested-tag {
    cursor: pointer;
    font-size: 12px;
    padding: 3px 8px;
    border-radius: 15px;
    background-color: #e9ecef;
    border: 1px solid #dee2e6;
    color: #495057;
    transition: all 0.2s;
}
.suggested-tag:hover {
    background-color: #dee2e6;
    transform: translateY(-1px);
}
.card {
    border: 1px solid rgba(0,0,0,.125);
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075);
}
.btn-primary {
    background-color: #0d6efd;
    border-color: #0d6efd;
}
.btn-primary:hover {
    background-color: #0b5ed7;
    border-color: #0a58ca;
}
.table th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.8);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 9999;
}
.spinner {
    width: 50px;
    height: 50px;
    border: 5px solid #f3f3f3;
    border-top: 5px solid #3498db;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
.alert-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 10000;
    min-width: 300px;
    opacity: 0;
    transform: translateY(-20px);
    transition: all 0.3s ease;
}
.alert-toast.show {
    opacity: 1;
    transform: translateY(0);
}
.post-row {
    transition: all 0.3s ease;
}
.post-row.deleting {
    opacity: 0.5;
    background-color: #f8d7da !important;
}
.post-row.deleted {
    display: none;
}
.posts-loading {
    text-align: center;
    padding: 20px;
}
.music-config-card {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
}
.music-config-card .card-body {
    padding: 1.25rem;
}
@media (max-width: 768px) {
    .col-md-3 {
        margin-bottom: 1rem;
    }
}

/* 编辑器全屏模式：隐藏侧边栏，让编辑区真正全屏 */
body.editor-fullscreen-active .sidebar {
    display: none !important;
}
body.editor-fullscreen-active .main-content {
    margin-left: 0 !important;
    width: 100% !important;
}
body.editor-fullscreen-active .mobile-overlay {
    display: none !important;
}
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">博客管理</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="newPost()" style="<?= $activeTab !== 'posts' ? 'display:none;' : '' ?>">
                            <i class="bi bi-plus-circle"></i> 新建文章
                        </button>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $_SESSION['success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $_SESSION['error'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <!-- Toast消息提示 -->
                <div class="alert-toast" id="toastMessage"></div>

                <!-- 标签页导航 -->
                <ul class="nav nav-tabs mb-4" id="blogTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $activeTab === 'posts' ? 'active' : '' ?>" id="posts-tab" data-bs-toggle="tab" data-bs-target="#posts-content" type="button" role="tab">
                            <i class="bi bi-file-text"></i> 文章管理
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $activeTab === 'categories' ? 'active' : '' ?>" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories-content" type="button" role="tab">
                            <i class="bi bi-tags"></i> 分类管理
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $activeTab === 'comments' ? 'active' : '' ?>" id="comments-tab" data-bs-toggle="tab" data-bs-target="#comments-content" type="button" role="tab">
                            <i class="bi bi-chat-dots"></i> 评论管理
                            <?php if ($stats['pending'] > 0): ?>
                            <span class="badge bg-danger ms-1"><?= $stats['pending'] ?></span>
                            <?php endif; ?>
                        </button>
                    </li>
                </ul>

                <!-- 标签页内容 -->
                <div class="tab-content" id="blogTabsContent">

                    <!-- 文章管理标签页 -->
                    <div class="tab-pane fade <?= $activeTab === 'posts' ? 'show active' : '' ?>" id="posts-content" role="tabpanel">
                <!-- 添加/编辑表单 -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0" id="formTitle">
                            <i class="bi bi-pencil-square"></i> <?= $editPost ? '编辑文章' : '发布新文章' ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form id="postForm" novalidate>
                            <!-- 隐藏字段 -->
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="csrf_token" id="csrf_token" value="<?= e(generateCSRFToken()) ?>">
                            <input type="hidden" name="id" id="postId" value="<?= e($editPost['id'] ?? '') ?>">
                            
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label class="form-label">文章标题 *</label>
                                        <input type="text" name="title" id="title" class="form-control" 
                                               value="<?= e($editPost['title'] ?? '') ?>" 
                                               placeholder="请输入文章标题">
                                        <div class="invalid-feedback">请填写文章标题</div>
                                    </div>
                                    
                                    <!-- 封面图片上传 -->
                                    <div class="mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-image"></i> 文章封面图片
                                        </label>
                                        <div class="input-group">
                                            <input type="text" name="cover_image" id="cover_image" 
                                                   class="form-control" 
                                                   value="<?= e($editPost['cover_image'] ?? '') ?>"
                                                   placeholder="上传封面图片或输入URL">
                                            <input type="file" id="coverImageFileInput" accept="image/*" style="display: none;">
                                            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('coverImageFileInput').click()">
                                                <i class="bi bi-upload"></i> 上传
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" onclick="clearCoverImage()">
                                                <i class="bi bi-x"></i> 清除
                                            </button>
                                        </div>
                                        <small class="text-muted">支持 JPG、PNG、GIF、WEBP 格式，最大 5MB</small>
                                        
                                        <!-- 封面预览 -->
                                        <div id="coverPreview" class="mt-2" style="<?= !empty($editPost['cover_image']) ? '' : 'display: none;' ?>">
                                            <img src="<?= e($editPost['cover_image'] ?? '') ?>" alt="封面预览" 
                                                 style="max-width: 200px; max-height: 150px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">文章内容 (支持Markdown) *</label>
                                        <textarea name="content" id="post_content" class="form-control" rows="15"><?= e($editPost['content'] ?? '') ?></textarea>
                                        <div class="invalid-feedback">请填写文章内容</div>
                                        <small class="text-muted">支持Markdown语法，可直接上传图片</small>
                                    </div>

                                    <div class="card border-info mb-3">
                                        <div class="card-header bg-light py-2"><i class="bi bi-stars"></i> AI 摘要（访客可见）</div>
                                        <div class="card-body">
                                            <p class="small text-muted mb-2">仅将公开正文发给模型；<code>[Paid]</code>、<code>[Privacy]</code> 区块已排除。保存文章修改后请重新生成以同步摘要。</p>
                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-6">
                                                    <label class="form-label small mb-1">选用模型</label>
                                                    <select id="ai_model_select" class="form-select form-select-sm">
                                                        <?php if (empty($aiModelsEnabled)): ?>
                                                        <option value="">请先在「AI 管理」中添加模型</option>
                                                        <?php endif; ?>
                                                        <?php foreach ($aiModelsEnabled as $am): ?>
                                                        <option value="<?= (int)$am['id'] ?>" <?= !empty($am['is_default']) ? 'selected' : '' ?>><?= e($am['name']) ?> (<?= e($am['model_id']) ?>)</option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <button type="button" class="btn btn-sm btn-primary" id="btnAiGenerateSummary"><i class="bi bi-magic"></i> 生成 / 重新生成摘要</button>
                                                </div>
                                            </div>
                                            <?php if (!empty($editPost['ai_summary'])): ?>
                                            <div class="mt-2 p-2 bg-light rounded small" id="aiSummaryPreviewBox">
                                                <strong>当前摘要：</strong>
                                                <span id="aiSummaryPreviewText"><?= nl2br(e($editPost['ai_summary'])) ?></span>
                                            </div>
                                            <?php else: ?>
                                            <div class="mt-2 small text-muted" id="aiSummaryPreviewBox" style="display:none;"></div>
                                            <?php endif; ?>
                                            <div class="mt-2 small text-danger" id="aiSummaryError" style="display:none;"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- 音乐配置区域 - 移动到主要内容区域 -->
                                    <div class="mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-music-note-beamed"></i> 背景音乐
                                        </label>
                                        
                                        <!-- 是否启用音乐播放器 -->
                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="data_music_enabled" id="data_music_enabled" 
                                                       value="true" <?= ($editPost['data_music_enabled'] ?? 'false') === 'true' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="data_music_enabled">
                                                    <i class="bi bi-music-note-beamed"></i> 启用音乐播放器
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <!-- 音乐配置区域 -->
                                        <div id="musicConfigArea" class="card music-config-card" style="<?= ($editPost['data_music_enabled'] ?? 'false') === 'true' ? '' : 'display: none;' ?>">
                                            <div class="card-body">
                                                <div class="row">
                                                    <!-- 音频文件上传 -->
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label text-muted small">音频文件 *</label>
                                                        <div class="input-group">
                                                            <input type="text" name="data_music_file" id="data_music_file" 
                                                                   class="form-control" 
                                                                   value="<?= e($editPost['data_music_file'] ?? '') ?>"
                                                                   placeholder="上传音频文件或输入路径"
                                                           readonly>
                                                            <input type="file" id="audioFileInput" accept="audio/*" style="display: none;">
                                                            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('audioFileInput').click()">
                                                                <i class="bi bi-upload"></i> 上传
                                                            </button>
                                                            <button type="button" class="btn btn-outline-danger" onclick="clearAudioFile()">
                                                                <i class="bi bi-x"></i> 清除
                                                            </button>
                                                        </div>
                                                        <small class="text-muted">支持 MP3、WAV、M4A、OGG 格式，最大 20MB</small>
                                                        
                                                        <!-- 音频预览 -->
                                                        <div id="audioPreview" class="mt-2" style="display: none;">
                                                            <audio controls class="w-100" style="height: 32px;"></audio>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- 歌词文件上传 -->
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label text-muted small">歌词文件 (可选)</label>
                                                        <div class="input-group">
                                                            <input type="text" name="data_lyric_file" id="data_lyric_file" 
                                                                   class="form-control" 
                                                                   value="<?= e($editPost['data_lyric_file'] ?? '') ?>"
                                                                   placeholder="上传歌词文件或输入路径"
                                                           readonly>
                                                            <input type="file" id="lyricFileInput" accept=".lrc,.txt" style="display: none;">
                                                            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('lyricFileInput').click()">
                                                                <i class="bi bi-file-text"></i> 上传
                                                            </button>
                                                            <button type="button" class="btn btn-outline-danger" onclick="clearLyricFile()">
                                                                <i class="bi bi-x"></i> 清除
                                                            </button>
                                                        </div>
                                                        <small class="text-muted">支持 .lrc 和 .txt 格式，最大 1MB</small>
                                                    </div>
                                                </div>
                                                
                                                <!-- 播放器设置 -->
                                                <div class="border-top pt-3 mt-3">
                                                    <label class="form-label text-muted small">播放器设置</label>
                                                    
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-md-3">
                                                            <label class="form-label small">播放器位置</label>
                                                            <select name="data_position" class="form-select form-select-sm">
                                                                <option value="static" <?= ($editPost['data_position'] ?? 'static') === 'static' ? 'selected' : '' ?>>静态定位</option>
                                                                <option value="top-left" <?= ($editPost['data_position'] ?? 'static') === 'top-left' ? 'selected' : '' ?>>左上角</option>
                                                                <option value="top-right" <?= ($editPost['data_position'] ?? 'static') === 'top-right' ? 'selected' : '' ?>>右上角</option>
                                                                <option value="bottom-left" <?= ($editPost['data_position'] ?? 'static') === 'bottom-left' ? 'selected' : '' ?>>左下角</option>
                                                                <option value="bottom-right" <?= ($editPost['data_position'] ?? 'static') === 'bottom-right' ? 'selected' : '' ?>>右下角</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label small">主题模式</label>
                                                            <select name="data_theme" class="form-select form-select-sm">
                                                                <option value="auto" <?= ($editPost['data_theme'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>自动主题</option>
                                                                <option value="light" <?= ($editPost['data_theme'] ?? 'auto') === 'light' ? 'selected' : '' ?>>浅色主题</option>
                                                                <option value="dark" <?= ($editPost['data_theme'] ?? 'auto') === 'dark' ? 'selected' : '' ?>>深色主题</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label small">播放器尺寸</label>
                                                            <select name="data_size" class="form-select form-select-sm">
                                                                <option value="compact" <?= ($editPost['data_size'] ?? 'normal') === 'compact' ? 'selected' : '' ?>>紧凑</option>
                                                                <option value="normal" <?= ($editPost['data_size'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>正常</option>
                                                                <option value="large" <?= ($editPost['data_size'] ?? 'normal') === 'large' ? 'selected' : '' ?>>大尺寸</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label small">自动播放</label>
                                                            <select name="data_autoplay" id="data_autoplay" class="form-select form-select-sm" onchange="handleAutoplayChange()">
                                                                <option value="false" <?= ($editPost['data_autoplay'] ?? 'false') === 'false' ? 'selected' : '' ?>>手动播放</option>
                                                                <option value="true" <?= ($editPost['data_autoplay'] ?? 'false') === 'true' ? 'selected' : '' ?>>自动播放</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="checkbox" name="data_embed" id="data_embed" 
                                                               value="true" <?= ($editPost['data_embed'] ?? 'false') === 'true' ? 'checked' : '' ?>
                                                               onchange="handleMusicModeChange()">
                                                        <label class="form-check-label small" for="data_embed">
                                                            嵌入模式
                                                        </label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="checkbox" name="data_cover_mode" id="data_cover_mode" 
                                                               value="true" <?= ($editPost['data_cover_mode'] ?? 'false') === 'true' ? 'checked' : '' ?>>
                                                        <label class="form-check-label small" for="data_cover_mode">
                                                            黑胶唱片模式
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- 支付内容设置区域 -->
                                    <div class="mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-wallet2"></i> 支付内容设置
                                        </label>
                                        
                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="has_paid_content" id="has_paid_content" 
                                                       value="1" <?= ($editPost['has_paid_content'] ?? 0) == 1 ? 'checked' : '' ?>
                                                       onchange="togglePaidSettings()">
                                                <label class="form-check-label" for="has_paid_content">
                                                    <i class="bi bi-wallet2"></i> 启用支付内容
                                                </label>
                                            </div>
                                            <small class="text-muted">启用后，用户需要支付指定金额才能查看标记为[Paid]和[/Paid]之间的内容</small>
                                        </div>
                                        
                                        <!-- 支付配置区域 -->
                                        <div id="paidConfigArea" class="card" style="<?= ($editPost['has_paid_content'] ?? 0) == 1 ? '' : 'display: none;' ?>">
                                            <div class="card-body">
                                                <div class="mb-3" id="postPriceField">
                                                    <label class="form-label">文章价格 (元) <span id="priceRequiredStar">*</span></label>
                                                    <input type="number" step="0.01" min="0.01" name="post_price" id="post_price" 
                                                           class="form-control" 
                                                           value="<?= e($editPost['post_price'] ?? '0.00') ?>"
                                                           placeholder="请输入文章价格">
                                                    <small class="text-muted">用户支付此金额后可查看付费内容</small>
                                                </div>
                                                
                                                <div class="alert alert-info">
                                                    <i class="bi bi-info-circle"></i> 在文章内容中使用 <code>[Paid]</code> 和 <code>[/Paid]</code> 标记包围付费内容
                                                    <br>示例：这是免费内容<code>[Paid]</code>这是付费内容<code>[/Paid]</code>这是更多免费内容
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- 隐私内容设置区域 -->
                                    <div class="mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-shield-lock"></i> 隐私内容设置
                                        </label>
                                        
                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="has_privacy_content" id="has_privacy_content" 
                                                       value="1" <?= ($editPost['has_privacy_content'] ?? 0) == 1 ? 'checked' : '' ?>
                                                       onchange="togglePrivacySettings()">
                                                <label class="form-check-label" for="has_privacy_content">
                                                    <i class="bi bi-shield-lock"></i> 启用隐私内容
                                                </label>
                                            </div>
                                            <small class="text-muted">启用后，用户需要登录并通过验证才能查看标记为[Privacy]和[/Privacy]之间的内容</small>
                                        </div>
                                        
                                        <!-- 隐私配置区域 -->
                                        <div id="privacyConfigArea" class="card" style="<?= ($editPost['has_privacy_content'] ?? 0) == 1 ? '' : 'display: none;' ?>">
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label">验证方式</label>
                                                    <select name="privacy_type" id="privacy_type" class="form-select" onchange="togglePrivacyAnswerField()">
                                                        <option value="login_only" <?= ($editPost['privacy_type'] ?? 'fixed_answer') === 'login_only' ? 'selected' : '' ?>>
                                                            仅需登录即可查看
                                                        </option>
                                                        <option value="fixed_answer" <?= ($editPost['privacy_type'] ?? 'fixed_answer') === 'fixed_answer' ? 'selected' : '' ?>>
                                                            固定答案验证
                                                        </option>
                                                        <option value="open_answer" <?= ($editPost['privacy_type'] ?? 'fixed_answer') === 'open_answer' ? 'selected' : '' ?>>
                                                            开放答案验证
                                                        </option>
                                                        <option value="manual_approval" <?= ($editPost['privacy_type'] ?? 'fixed_answer') === 'manual_approval' ? 'selected' : '' ?>>
                                                            人工审核验证
                                                        </option>
                                                    </select>
                                                </div>
                                                
                                                <div class="mb-3" id="privacyQuestionField">
                                                    <label class="form-label">访问问题 <span id="questionRequiredStar">*</span></label>
                                                    <input type="text" name="privacy_question" id="privacy_question" 
                                                           class="form-control" 
                                                           value="<?= e($editPost['privacy_question'] ?? '') ?>"
                                                           placeholder="请输入访问隐私内容需要回答的问题">
                                                    <small class="text-muted">示例：您为什么想查看这篇文章？或 这篇文章对您有什么帮助？</small>
                                                </div>
                                                
                                                <!-- 固定答案字段 -->
                                                <div id="fixedAnswerField" class="mb-3">
                                                    <label class="form-label">问题答案 *</label>
                                                    <input type="text" name="privacy_answer" id="privacy_answer" 
                                                           class="form-control" 
                                                           value="<?= isset($editPost['privacy_answer']) ? '' : '' ?>"
                                                           placeholder="请输入问题的正确答案">
                                                    <small class="text-muted">用户需要输入此答案才能查看隐私内容（不区分大小写）</small>
                                                </div>
                                                
                                                <!-- 开放答案选项 -->
                                                <div id="openAnswerOptions" class="mb-3" style="display: none;">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="approval_required" id="approval_required" 
                                                               value="1" <?= ($editPost['approval_required'] ?? 0) == 1 ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="approval_required">
                                                            需要管理员审核
                                                        </label>
                                                    </div>
                                                    <small class="text-muted">勾选后，用户提交的答案需要管理员审核后才能获得访问权限</small>
                                                </div>
                                                
                                                <!-- 自定义提示内容 -->
                                                <div class="mb-3">
                                                    <label class="form-label">
                                                        <i class="bi bi-chat-left-text"></i> 自定义提示内容
                                                    </label>
                                                    <textarea name="privacy_custom_text" id="privacy_custom_text" 
                                                              class="form-control" rows="3"
                                                              placeholder="在此输入自定义提示内容，将在隐私内容提示框中显示。支持 &lt;color:xxx&gt;彩色文字&lt;/color&gt; 语法"><?= e($editPost['privacy_custom_text'] ?? '') ?></textarea>
                                                    <small class="text-muted">留空则使用默认提示。支持彩色文字语法，例如 &lt;color:red&gt;重要提示&lt;/color&gt;</small>
                                                </div>
                                                
                                                <div class="alert alert-info">
                                                    <i class="bi bi-info-circle"></i> 在文章内容中使用 <code>[Privacy]</code> 和 <code>[/Privacy]</code> 标记包围隐私内容
                                                    <br>示例：这是公开内容<code>[Privacy]</code>这是隐私内容<code>[/Privacy]</code>这是更多公开内容
                                                    <br><small class="text-muted">注意：必须使用配对标记，单标记格式不再支持。</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">作者 *</label>
                                        <input type="text" name="author" id="author" class="form-control" 
                                               value="<?= e($editPost['author'] ?? $_SESSION['admin_username']) ?>">
                                        <div class="invalid-feedback">请填写作者</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">文章分类</label>
                                        <select name="category" class="form-select" id="categorySelect">
                                            <option value="">选择分类</option>
                                            <?php foreach ($categories as $cat): ?>
                                            <option value="<?= e($cat['name']) ?>" 
                                                    data-color="<?= e($cat['color'] ?? '#007bff') ?>"
                                                    <?= ($editPost['category'] ?? '') === $cat['name'] ? 'selected' : '' ?>>
                                                <?= e($cat['name']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">文章标签</label>
                                        <input type="text" name="tags" id="tagsInput" class="form-control" 
                                               value="<?= e(formatTagsForInput($editPost['tags'] ?? '')) ?>"
                                               placeholder="例如：#技术分享#项目案例#学习笔记">
                                        <div class="tag-input-hint">
                                            使用 # 符号分隔多个标签，例如：#标签1#标签2#标签3
                                        </div>
                                        
                                        <!-- 标签预览 -->
                                        <div class="tags-preview" id="tagsPreview"></div>
                                        
                                        <!-- 推荐标签 -->
                                        <div class="suggested-tags">
                                            <span class="tag-input-hint">推荐标签：</span>
                                            <?php 
                                            // 获取所有历史标签作为推荐
                                            $allTags = [];
                                            $tagsQuery = $db->query("SELECT tags FROM blog_posts WHERE tags != ''")->fetchAll();
                                            foreach ($tagsQuery as $row) {
                                                if ($row['tags']) {
                                                    $postTags = explode(',', $row['tags']);
                                                    $allTags = array_merge($allTags, $postTags);
                                                }
                                            }
                                            $allTags = array_unique(array_filter($allTags));
                                            $allTags = array_slice($allTags, 0, 10);
                                            
                                            foreach ($allTags as $tag): 
                                            ?>
                                            <span class="suggested-tag" onclick="addTag('<?= e($tag) ?>')">#<?= e($tag) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">文章设置</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="is_pinned" id="is_pinned"
                                                   value="1" <?= ($editPost['is_pinned'] ?? 0) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="is_pinned">
                                                <i class="bi bi-pin-angle-fill text-danger"></i> 置顶文章
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="is_featured" id="is_featured"
                                                   value="1" <?= ($editPost['is_featured'] ?? 0) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="is_featured">
                                                <i class="bi bi-star-fill text-warning"></i> 精选文章
                                            </label>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><i class="bi bi-shield-check"></i> 文章许可协议</label>
                                        <select name="license" class="form-select" id="license" onchange="updateLicenseDescription()">
                                            <!-- 个人博客、文章类 -->
                                            <optgroup label="📝 个人博客、文章">
                                                <option value="CC BY 4.0" data-desc="允许他人自由共享、修改作品，但必须注明原作者姓名及来源" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'CC BY 4.0' ? 'selected' : '' ?>>
                                                    CC BY 4.0 - 署名 4.0
                                                </option>
                                                <option value="CC BY-NC 4.0" data-desc="允许他人非商业性使用、修改作品，必须注明原作者及来源，不得用于商业目的" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'CC BY-NC 4.0' ? 'selected' : '' ?>>
                                                    CC BY-NC 4.0 - 署名-非商业性使用 4.0
                                                </option>
                                                <option value="CC BY-SA 4.0" data-desc="允许他人自由共享、修改作品，必须注明原作者，且衍生作品须采用相同许可协议" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'CC BY-SA 4.0' ? 'selected' : '' ?>>
                                                    CC BY-SA 4.0 - 署名-相同方式共享 4.0
                                                </option>
                                                <option value="CC BY-NC-SA 4.0" data-desc="允许他人非商业性使用、修改作品，必须注明原作者，且衍生作品须采用相同许可协议，不得用于商业目的" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'CC BY-NC-SA 4.0' ? 'selected' : '' ?>>
                                                    CC BY-NC-SA 4.0 - 署名-非商业性使用-相同方式共享 4.0
                                                </option>
                                                <option value="CC BY-ND 4.0" data-desc="允许他人自由共享作品，但必须注明原作者及来源，不得对作品进行任何修改或衍生" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'CC BY-ND 4.0' ? 'selected' : '' ?>>
                                                    CC BY-ND 4.0 - 署名-禁止演绎 4.0
                                                </option>
                                                <option value="CC BY-NC-ND 4.0" data-desc="允许他人非商业性共享作品，必须注明原作者及来源，不得修改、衍生或用于商业目的" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'CC BY-NC-ND 4.0' ? 'selected' : '' ?>>
                                                    CC BY-NC-ND 4.0 - 署名-非商业性使用-禁止演绎 4.0
                                                </option>
                                            </optgroup>

                                            <!-- 开源软件类 -->
                                            <optgroup label="💻 开源软件">
                                                <option value="MIT" data-desc="最宽松的开源许可，允许任何人以任何目的使用、复制、修改、合并、出版发行、散布、再授权及贩售软件的副本" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'MIT' ? 'selected' : '' ?>>
                                                    MIT License
                                                </option>
                                                <option value="Apache-2.0" data-desc="允许自由使用、修改和分发，要求保留版权声明和许可声明，提供专利授权，适用于大型商业项目" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'Apache-2.0' ? 'selected' : '' ?>>
                                                    Apache License 2.0
                                                </option>
                                                <option value="GPL-3.0" data-desc="强传染性开源协议，要求衍生作品也必须采用GPL协议，修改后的源码必须公开" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'GPL-3.0' ? 'selected' : '' ?>>
                                                    GNU General Public License 3.0
                                                </option>
                                                <option value="LGPL-3.0" data-desc="较宽松的GPL，允许链接到库而不使整个程序受GPL约束，适用于库和组件" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'LGPL-3.0' ? 'selected' : '' ?>>
                                                    GNU Lesser General Public License 3.0
                                                </option>
                                                <option value="BSD-3-Clause" data-desc="宽松开源协议，允许使用、修改和分发，只需保留版权声明和免责条款，没有传染性" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'BSD-3-Clause' ? 'selected' : '' ?>>
                                                    BSD 3-Clause License
                                                </option>
                                            </optgroup>

                                            <!-- 数据库、开放数据类 -->
                                            <optgroup label="🗄️ 数据库、开放数据">
                                                <option value="ODbL" data-desc="开放数据库许可，要求共享-相同方式，适用于数据库内容，如OpenStreetMap" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'ODbL' ? 'selected' : '' ?>>
                                                    ODbL - Open Database License
                                                </option>
                                                <option value="CC0 1.0" data-desc="放弃所有版权，将作品完全置于公有领域，允许任何人以任何方式使用，无需署名" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'CC0 1.0' ? 'selected' : '' ?>>
                                                    CC0 1.0 - 公有领域奉献
                                                </option>
                                            </optgroup>

                                            <!-- 科研论文类 -->
                                            <optgroup label="🔬 科研论文、学术出版">
                                                <option value="CC BY 4.0" data-desc="允许他人自由共享、修改作品，但必须注明原作者姓名及来源" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'CC BY 4.0' ? 'selected' : '' ?>>
                                                    CC BY 4.0 - 署名 4.0
                                                </option>
                                                <option value="PLOS" data-desc="PLOS期刊的开放获取许可，基于CC BY，允许自由使用、分发和改编，必须注明来源" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'PLOS' ? 'selected' : '' ?>>
                                                    PLOS License
                                                </option>
                                                <option value="ArXiv" data-desc="arXiv预印本平台的许可协议，通常基于CC协议，促进学术成果的快速传播" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'ArXiv' ? 'selected' : '' ?>>
                                                    ArXiv License
                                                </option>
                                            </optgroup>

                                            <!-- 游戏内容类 -->
                                            <optgroup label="🎮 游戏内容、规则">
                                                <option value="OGL" data-desc="开放游戏许可，允许使用、修改和分发游戏内容，适用于桌面角色扮演游戏规则" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'OGL' ? 'selected' : '' ?>>
                                                    Open Game License
                                                </option>
                                            </optgroup>

                                            <!-- 技术文档类 -->
                                            <optgroup label="📚 技术文档、手册">
                                                <option value="GFDL" data-desc="GNU自由文档许可，要求复制和修改时保留许可声明，适用于维基百科等文档" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'GFDL' ? 'selected' : '' ?>>
                                                    GNU Free Documentation License
                                                </option>
                                                <option value="CC BY-SA 4.0" data-desc="允许他人自由共享、修改作品，必须注明原作者，且衍生作品须采用相同许可协议" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === 'CC BY-SA 4.0' ? 'selected' : '' ?>>
                                                    CC BY-SA 4.0 - 署名-相同方式共享 4.0
                                                </option>
                                            </optgroup>

                                            <!-- 其他 -->
                                            <optgroup label="🔖 其他">
                                                <option value="无协议" data-desc="保留所有版权，未经授权不得使用、复制、修改或分发" <?= ($editPost['license'] ?? 'CC BY-NC-ND 4.0') === '无协议' ? 'selected' : '' ?>>
                                                    无协议（保留所有权利）
                                                </option>
                                            </optgroup>
                                        </select>
                                        <div class="alert alert-info mt-2" style="font-size: 12px;">
                                            <i class="bi bi-info-circle"></i>
                                            <strong>协议说明：</strong>
                                            <span id="licenseDescription">
                                                <?= getLicenseDescription($editPost['license'] ?? 'CC BY-NC-ND 4.0') ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><i class="bi bi-save"></i> 文章保存类型</label>
                                        <select name="is_published" class="form-select">
                                            <option value="1" <?= ($editPost['is_published'] ?? 1) == 1 ? 'selected' : '' ?>>发布</option>
                                            <option value="0" <?= ($editPost['is_published'] ?? 1) == 0 ? 'selected' : '' ?>>草稿</option>
                                        </select>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                            <i class="bi bi-check-circle"></i> <?= $editPost ? '更新文章' : '发布文章' ?>
                                        </button>
                                        <button type="button" class="btn btn-secondary" onclick="resetForm()" id="cancelBtn">
                                            取消<?= $editPost ? '编辑' : '' ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- 文章列表 -->
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> 文章列表</h5>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="refreshPostsList(true)">
                            <i class="bi bi-arrow-clockwise"></i> 刷新列表
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="postsLoading" class="posts-loading" style="display: none;">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">加载中...</span>
                            </div>
                            <p class="mt-2">正在加载文章列表...</p>
                        </div>
                        <div id="postsContent">
                            <?php if (empty($posts)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-file-text display-1 text-muted"></i>
                                <p class="lead mt-3">还没有文章，赶快发布一篇吧！</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th width="30%">标题</th>
                                            <th>作者</th>
                                            <th>分类</th>
                                            <th>标签</th>
                                            <th>状态</th>
                                            <th>浏览量</th>
                                            <th>发布时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody id="postsTableBody">
                                        <?php foreach ($posts as $post): 
                                        $postCategory = $post['category'] ?? '';
                                        $categoryColor = isset($categoryMap[$postCategory]) ? $categoryMap[$postCategory] : '#007bff';
                                        $postTags = $post['tags'] ? explode(',', $post['tags']) : [];
                                        $rowId = 'post-row-' . $post['id'];
                                        ?>
                                        <tr id="<?= $rowId ?>" class="post-row">
                                            <td>
                                                <strong><?= e($post['title']) ?></strong>
                                                <?php if ($post['is_pinned']): ?>
                                                <i class="bi bi-pin-angle-fill text-danger" title="置顶"></i>
                                                <?php endif; ?>
                                                <?php if ($post['is_featured']): ?>
                                                <i class="bi bi-star-fill text-warning" title="精选"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e($post['author']) ?></td>
                                            <td>
                                                <?php if ($postCategory): ?>
                                                <span class="badge category-badge" style="background-color: <?= $categoryColor ?>">
                                                    <span class="color-dot" style="background-color: <?= adjustBrightness($categoryColor, 30) ?>"></span>
                                                    <?= e($postCategory) ?>
                                                </span>
                                                <?php else: ?>
                                                <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($postTags)): ?>
                                                <div style="max-width: 200px;">
                                                    <?php foreach ($postTags as $tag): 
                                                        if (trim($tag)):
                                                            $tagColor = isset($categoryMap[trim($tag)]) ? $categoryMap[trim($tag)] : getRandomColor(trim($tag));
                                                    ?>
                                                    <span class="badge tag-badge" style="background-color: <?= $tagColor ?>">
                                                        <span class="tag-color-dot" style="background-color: <?= adjustBrightness($tagColor, 30) ?>"></span>
                                                        <?= e(trim($tag)) ?>
                                                    </span>
                                                    <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php else: ?>
                                                <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$post['is_published']): ?>
                                                <span class="badge bg-secondary">草稿</span>
                                                <?php endif; ?>
                                                <?php if ($post['is_pinned']): ?>
                                                <span class="badge bg-danger">置顶</span>
                                                <?php endif; ?>
                                                <?php if ($post['is_featured']): ?>
                                                <span class="badge bg-warning">精选</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-info"><?= $post['views'] ?></span></td>
                                            <td><small class="text-muted"><?= date('Y-m-d H:i', strtotime($post['created_at'])) ?></small></td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="/blog.php?id=<?= $post['id'] ?>" class="btn btn-outline-info" target="_blank" title="查看">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-outline-primary edit-post" 
                                                            data-id="<?= $post['id'] ?>" title="编辑">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger delete-post" 
                                                            data-id="<?= $post['id'] ?>" title="删除">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- 分页 -->
                        <?php if ($total_pages > 1): ?>
                        <div class="card-footer">
                            <nav>
                                <ul class="pagination justify-content-center mb-0">
                                    <?php
                                    $current_url = $_SERVER['REQUEST_URI'];
                                    $url_parts = parse_url($current_url);
                                    $query_params = [];
                                    if (isset($url_parts['query'])) {
                                        parse_str($url_parts['query'], $query_params);
                                    }
                                    $query_params['tab'] = 'posts';
                                    $base_url = $url_parts['path'] . '?' . http_build_query($query_params);
                                    ?>

                                    <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?= $base_url ?>&page=<?= $page - 1 ?>">上一页</a>
                                    </li>
                                    <?php endif; ?>

                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);

                                    for ($i = $start_page; $i <= $end_page; $i++):
                                    ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= $base_url ?>&page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                    <?php endfor; ?>

                                    <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?= $base_url ?>&page=<?= $page + 1 ?>">下一页</a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                    </div>

                    <!-- 分类管理标签页 -->
                    <div class="tab-pane fade <?= $activeTab === 'categories' ? 'show active' : '' ?>" id="categories-content" role="tabpanel">
                        <!-- 添加/编辑分类表单 -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5><?= $editCategory ? '编辑分类' : '添加新分类' ?></h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="category_action" value="save">
                                    <input type="hidden" name="category_id" value="<?= $editCategory ? $editCategory['id'] : '' ?>">

                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label class="form-label">分类名称 *</label>
                                                <input type="text" name="category_name" class="form-control"
                                                       value="<?= e($editCategory['name'] ?? '') ?>" required>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">排序</label>
                                                <input type="number" name="category_sort_order" class="form-control"
                                                       value="<?= e($editCategory['sort_order'] ?? 0) ?>" min="0">
                                                <small class="text-muted">数字越小越靠前</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">分类别名</label>
                                        <input type="text" name="category_slug" class="form-control"
                                               value="<?= e($editCategory['slug'] ?? '') ?>"
                                               placeholder="留空将自动生成">
                                        <small class="text-muted">用于URL和标识符，如：tech</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">分类描述</label>
                                        <textarea name="category_description" class="form-control" rows="2"
                                                  placeholder="描述这个分类的用途..."><?= e($editCategory['description'] ?? '') ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">标签颜色</label>
                                        <div class="input-group">
                                            <input type="color" name="category_color" class="form-control form-control-color"
                                                   value="<?= e($editCategory['color'] ?? '#007bff') ?>"
                                                   title="选择颜色"
                                                   style="width: 3rem; height: calc(1.5em + 0.75rem + 2px); padding: 0.375rem;">
                                            <input type="text" class="form-control"
                                                   value="<?= e($editCategory['color'] ?? '#007bff') ?>"
                                                   placeholder="#007bff" maxlength="7" readonly>
                                        </div>
                                        <div class="mt-2">
                                            <small>预设颜色：</small>
                                            <?php
                                            $presetColors = ['#007bff', '#28a745', '#fd7e14', '#6f42c1', '#e83e8c', '#20c997', '#ffc107', '#dc3545', '#17a2b8', '#6c757d'];
                                            foreach ($presetColors as $presetColor):
                                            ?>
                                            <button type="button" class="btn btn-sm m-1"
                                                    style="background-color: <?= $presetColor ?>; width: 24px; height: 24px; padding: 0; border: 1px solid #dee2e6;"
                                                    onclick="setCategoryColor('<?= $presetColor ?>')"
                                                    title="<?= $presetColor ?>">
                                            </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save"></i> <?= $editCategory ? '更新分类' : '添加分类' ?>
                                    </button>
                                    <?php if ($editCategory): ?>
                                    <a href="?tab=categories" class="btn btn-secondary">取消编辑</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <!-- 分类列表 -->
                        <div class="card">
                            <div class="card-header">
                                <h5>所有分类 (<?= count($categories) ?>)</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($categories)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-tags" style="font-size: 64px;"></i>
                                    <p class="mt-3">暂无分类，请添加分类</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>排序</th>
                                                <th>分类名称</th>
                                                <th>颜色</th>
                                                <th>别名</th>
                                                <th>描述</th>
                                                <th>文章数</th>
                                                <th>操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($categories as $category):
                                                // 获取该分类下的文章数
                                                $postCount = $db->prepare("SELECT COUNT(*) as count FROM blog_posts WHERE category = ?");
                                                $postCount->execute([$category['name']]);
                                                $count = $postCount->fetch()['count'];
                                            ?>
                                            <tr>
                                                <td><?= $category['sort_order'] ?></td>
                                                <td>
                                                    <span class="color-preview" style="background-color: <?= e($category['color'] ?? '#007bff') ?>; width: 20px; height: 20px; display: inline-block; border-radius: 3px; margin-right: 8px; vertical-align: middle; border: 1px solid #dee2e6;"></span>
                                                    <strong><?= e($category['name']) ?></strong>
                                                </td>
                                                <td>
                                                    <code><?= e($category['color'] ?? '#007bff') ?></code>
                                                </td>
                                                <td><code><?= e($category['slug']) ?></code></td>
                                                <td><?= e($category['description'] ?? '-') ?></td>
                                                <td><span class="badge bg-info"><?= $count ?> 篇</span></td>
                                                <td>
                                                    <a href="?tab=categories&edit_category=<?= $category['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-pencil"></i> 编辑
                                                    </a>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('确定删除此分类？')">
                                                        <input type="hidden" name="category_action" value="delete">
                                                        <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash"></i> 删除
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 评论管理标签页 -->
                    <div class="tab-pane fade <?= $activeTab === 'comments' ? 'show active' : '' ?>" id="comments-content" role="tabpanel">
                        <!-- 统计卡片 -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card text-white bg-primary">
                                    <div class="card-body">
                                        <h5 class="card-title"><i class="bi bi-chat-dots"></i> 总评论数</h5>
                                        <h3><?= $stats['total'] ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-white bg-success">
                                    <div class="card-body">
                                        <h5 class="card-title"><i class="bi bi-check-circle"></i> 已批准</h5>
                                        <h3><?= $stats['approved'] ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-white bg-warning">
                                    <div class="card-body">
                                        <h5 class="card-title"><i class="bi bi-clock"></i> 待审核</h5>
                                        <h3><?= $stats['pending'] ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-white bg-danger">
                                    <div class="card-body">
                                        <h5 class="card-title"><i class="bi bi-x-circle"></i> 已拒绝</h5>
                                        <h3><?= $stats['rejected'] ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 筛选器 -->
                        <div class="card mb-4">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">状态筛选</label>
                                        <select name="status" class="form-select">
                                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>全部状态</option>
                                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>待审核</option>
                                            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>已批准</option>
                                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>已拒绝</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">文章筛选</label>
                                        <select name="post" class="form-select">
                                            <option value="all">全部文章</option>
                                            <?php foreach ($posts_for_filter as $post): ?>
                                            <option value="<?= $post['id'] ?>" <?= $post_filter == $post['id'] ? 'selected' : '' ?>>
                                                <?= e($post['title']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">&nbsp;</label>
                                        <div>
                                            <input type="hidden" name="tab" value="comments">
                                            <button type="submit" class="btn btn-primary w-100">筛选</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 评论列表 -->
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">评论列表</h5>
                                <span class="badge bg-secondary">共 <?= $total_comments ?> 条评论</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($comments)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-chat-dots display-4 text-muted"></i>
                                    <p class="text-muted mt-2">暂无评论</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>评论内容</th>
                                                <th>文章</th>
                                                <th>作者</th>
                                                <th>状态</th>
                                                <th>时间</th>
                                                <th>操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($comments as $comment): ?>
                                            <tr>
                                                <td>
                                                    <div class="comment-content">
                                                        <div class="text-truncate" style="max-width: 300px;" title="<?= e($comment['content']) ?>">
                                                            <?= e(mb_substr(strip_tags($comment['content']), 0, 50)) ?>
                                                            <?= mb_strlen(strip_tags($comment['content'])) > 50 ? '...' : '' ?>
                                                        </div>
                                                        <?php if ($comment['reply_count'] > 0): ?>
                                                        <small class="text-muted">
                                                            <i class="bi bi-reply"></i> <?= $comment['reply_count'] ?> 条回复
                                                        </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <a href="/blog.php?id=<?= $comment['post_id'] ?>" target="_blank" class="text-decoration-none">
                                                        <?= e($comment['post_title']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <?php if ($comment['admin_username']): ?>
                                                    <?php if ($comment['user_role'] === 'admin'): ?>
                                                    <span class="badge bg-danger">
                                                        <i class="bi bi-shield-check"></i> <?= e($comment['admin_username']) ?> (管理员)
                                                    </span>
                                                    <?php elseif ($comment['user_role'] === 'user'): ?>
                                                    <span class="badge bg-secondary">
                                                        <i class="bi bi-person"></i> <?= e($comment['admin_username']) ?> (普通用户)
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-person-circle"></i> <?= e($comment['admin_username']) ?>
                                                    </span>
                                                    <?php endif; ?>
                                                    <?php else: ?>
                                                    <span class="text-muted">游客</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_colors = [
                                                        'pending' => 'warning',
                                                        'approved' => 'success',
                                                        'rejected' => 'danger'
                                                    ];
                                                    $status_texts = [
                                                        'pending' => '待审核',
                                                        'approved' => '已批准',
                                                        'rejected' => '已拒绝'
                                                    ];
                                                    ?>
                                                    <span class="badge bg-<?= $status_colors[$comment['status']] ?>">
                                                        <?= $status_texts[$comment['status']] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= date('Y-m-d H:i', strtotime($comment['created_at'])) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <?php if ($comment['status'] === 'pending'): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="comment_action" value="approve">
                                                            <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                            <input type="hidden" name="tab" value="comments">
                                                            <button type="submit" class="btn btn-success" title="批准">
                                                                <i class="bi bi-check"></i>
                                                            </button>
                                                        </form>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="comment_action" value="reject">
                                                            <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                            <input type="hidden" name="tab" value="comments">
                                                            <button type="submit" class="btn btn-warning" title="拒绝">
                                                                <i class="bi bi-x"></i>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('确定要删除这条评论吗？')">
                                                            <input type="hidden" name="comment_action" value="delete">
                                                            <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                            <input type="hidden" name="tab" value="comments">
                                                            <button type="submit" class="btn btn-danger" title="删除">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- 评论分页 -->
                                <?php if ($total_comment_pages > 1): ?>
                                <div class="card-footer">
                                    <nav>
                                        <ul class="pagination justify-content-center mb-0">
                                            <?php
                                            $current_url = $_SERVER['REQUEST_URI'];
                                            $url_parts = parse_url($current_url);
                                            $query_params = [];
                                            if (isset($url_parts['query'])) {
                                                parse_str($url_parts['query'], $query_params);
                                            }
                                            unset($query_params['comment_page']);
                                            $query_params['tab'] = 'comments';
                                            $base_url = $url_parts['path'] . '?' . http_build_query($query_params);
                                            ?>

                                            <?php if ($comment_page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?= $base_url ?>&comment_page=<?= $comment_page - 1 ?>">上一页</a>
                                            </li>
                                            <?php endif; ?>

                                            <?php
                                            $start_page = max(1, $comment_page - 2);
                                            $end_page = min($total_comment_pages, $comment_page + 2);

                                            for ($i = $start_page; $i <= $end_page; $i++):
                                            ?>
                                            <li class="page-item <?= $i === $comment_page ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= $base_url ?>&comment_page=<?= $i ?>"><?= $i ?></a>
                                            </li>
                                            <?php endfor; ?>

                                            <?php if ($comment_page < $total_comment_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?= $base_url ?>&comment_page=<?= $comment_page + 1 ?>">下一页</a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>

    <!-- 加载遮罩层 -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <div class="mt-3" id="loadingText">正在处理，请稍候...</div>
    </div>

    <?php include 'includes/markdown_editor.php'; ?>
    
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        // 初始化文章内容编辑器
        initMarkdownEditor('post_content');

        // 监听 EasyMDE 全屏切换，隐藏/显示侧边栏
        (function() {
            const toolbar = document.querySelector('.editor-toolbar');
            if (toolbar) {
                const observer = new MutationObserver(function() {
                    if (toolbar.classList.contains('fullscreen')) {
                        document.body.classList.add('editor-fullscreen-active');
                    } else {
                        document.body.classList.remove('editor-fullscreen-active');
                    }
                });
                observer.observe(toolbar, { attributes: true, attributeFilter: ['class'] });
            }
        })();

        // Ctrl+S / Cmd+S 保存文章（仅在编辑器内生效）
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                const editor = document.querySelector('.EasyMDEContainer');
                if (!editor || !editor.contains(document.activeElement)) return;
                e.preventDefault();
                const form = document.getElementById('postForm');
                if (form) form.requestSubmit();
            }
        });
        
        // 全局变量
        let currentPostId = '<?= e($editPost['id'] ?? '') ?>';
        const tagsInput = document.getElementById('tagsInput');
        const tagsPreview = document.getElementById('tagsPreview');
        const postForm = document.getElementById('postForm');
        const submitBtn = document.getElementById('submitBtn');
        const loadingOverlay = document.getElementById('loadingOverlay');
        const loadingText = document.getElementById('loadingText');
        const toastMessage = document.getElementById('toastMessage');
        const postsLoading = document.getElementById('postsLoading');
        const postsContent = document.getElementById('postsContent');
        
        // 重置提交按钮状态
        function resetSubmitButton() {
            if (currentPostId) {
                submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> 更新文章';
            } else {
                submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> 发布文章';
            }
            submitBtn.disabled = false;
        }
        
        // 显示Toast消息
        function showToast(message, type = 'success') {
            toastMessage.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    <i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'}"></i> 
                    ${message}
                    <button type="button" class="btn-close" onclick="hideToast()"></button>
                </div>
            `;
            toastMessage.classList.add('show');
            
            setTimeout(hideToast, 5000);
        }
        
        // 隐藏Toast消息
        function hideToast() {
            toastMessage.classList.remove('show');
            setTimeout(() => {
                toastMessage.innerHTML = '';
            }, 300);
        }
        
        // 显示加载状态
        function showLoading(text = '正在处理，请稍候...') {
            loadingText.textContent = text;
            loadingOverlay.style.display = 'flex';
        }
        
        // 隐藏加载状态
        function hideLoading() {
            loadingOverlay.style.display = 'none';
        }
        
        // 显示文章列表加载状态
        function showPostsLoading() {
            postsLoading.style.display = 'block';
            postsContent.style.opacity = '0.5';
        }
        
        // 隐藏文章列表加载状态
        function hidePostsLoading() {
            postsLoading.style.display = 'none';
            postsContent.style.opacity = '1';
        }
        
        // 发送Ajax请求
        function sendAjaxRequest(data) {
            return fetch('posts.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(data)
            })
            .then(response => {
                // 检查响应内容类型
                const contentType = response.headers.get('content-type');
                
                // 如果响应不是 JSON，可能是被 WAF 拦截了
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        // 检查是否是 WAF 拦截页面
                        if (text.includes('<!doctype') || text.includes('<html') || text.includes('403')) {
                            throw new Error('请求被防火墙拦截。请检查内容中是否包含敏感标签（如 iframe），系统已尝试自动编码绕过，如仍失败请联系管理员。');
                        }
                        // 尝试解析为 JSON（某些服务器可能不设置正确的 content-type）
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            throw new Error('服务器返回了非预期的响应格式');
                        }
                    });
                }
                
                return response.json();
            });
        }

        function escapeHtmlAi(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        const btnAiGen = document.getElementById('btnAiGenerateSummary');
        if (btnAiGen) {
            btnAiGen.addEventListener('click', function() {
                const postId = document.getElementById('postId').value;
                if (!postId) {
                    showToast('请先保存文章', 'danger');
                    return;
                }
                const sel = document.getElementById('ai_model_select');
                if (sel && !sel.value) {
                    showToast('请先在「AI 管理」中添加并启用模型', 'danger');
                    return;
                }
                const aiModelId = sel ? sel.value : '';
                showLoading('正在请求 AI 生成摘要（可能需要数十秒）...');
                const errEl = document.getElementById('aiSummaryError');
                if (errEl) {
                    errEl.style.display = 'none';
                    errEl.textContent = '';
                }
                sendAjaxRequest({
                    ajax: '1',
                    action: 'ai_generate_summary',
                    csrf_token: document.getElementById('csrf_token').value,
                    post_id: postId,
                    ai_model_id: aiModelId
                }).then(res => {
                    hideLoading();
                    if (!res.success) {
                        throw new Error(res.message || '生成失败');
                    }
                    showToast(res.message || '摘要已生成');
                    const box = document.getElementById('aiSummaryPreviewBox');
                    if (box) {
                        box.style.display = '';
                        box.className = 'mt-2 p-2 bg-light rounded small';
                        box.innerHTML = '<strong>当前摘要：</strong><span id="aiSummaryPreviewText">' + escapeHtmlAi(res.summary || '').replace(/\n/g, '<br>') + '</span>';
                    }
                }).catch(err => {
                    hideLoading();
                    const eEl = document.getElementById('aiSummaryError');
                    if (eEl) {
                        eEl.style.display = 'block';
                        eEl.textContent = err.message || '请求失败';
                    }
                    showToast(err.message || '请求失败', 'danger');
                });
            });
        }
        
        // 保存文章
        postForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const title = document.getElementById('title').value.trim();
            const content = document.getElementById('post_content').value.trim();
            const author = document.getElementById('author').value.trim();
            
            // 验证
            let isValid = true;
            document.getElementById('title').classList.remove('is-invalid');
            document.getElementById('post_content').classList.remove('is-invalid');
            document.getElementById('author').classList.remove('is-invalid');
            
            if (!title) {
                document.getElementById('title').classList.add('is-invalid');
                isValid = false;
            }
            if (!content) {
                document.getElementById('post_content').classList.add('is-invalid');
                isValid = false;
            }
            if (!author) {
                document.getElementById('author').classList.add('is-invalid');
                isValid = false;
            }
            
            if (!isValid) {
                showToast('请填写所有必填字段（标*号）', 'danger');
                return;
            }
            
            // 收集表单数据
            const formData = new FormData(this);
            const data = {};
            for (let [key, value] of formData.entries()) {
                data[key] = value;
            }
            
            // 尝试编码内容以绕过 WAF (Web Application Firewall)
            // 使用 HTML 实体编码 + 自定义分隔符，比 Base64 更隐蔽
            const fieldsToEncode = ['content', 'title', 'privacy_question'];
            let hasEncoded = false;
            
            // 自定义编码函数：将 < > 等敏感字符转为实体编码
            function encodeForWAF(str) {
                if (!str) return str;
                // 使用 HTML 实体编码敏感字符
                return str
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }
            
            fieldsToEncode.forEach(field => {
                if (data[field]) {
                    data[field] = encodeForWAF(data[field]);
                    hasEncoded = true;
                }
            });
            
            if (hasEncoded) {
                data.encoding = 'html_entities';
            }
            
            // 添加 WAF 白名单标识参数
            // WAF 规则配置：POST 请求参数 postssave 等于 addgalaxy.cn 时放行
            data.postssave = 'addgalaxy.cn';
            
            // 发送请求
            showLoading('正在保存文章...');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 保存中...';
            
            sendAjaxRequest(data)
                .then(response => {
                    hideLoading();
                    
                    if (response.success) {
                        showToast(response.message, 'success');
                        
                        // 更新表单状态
                        if (response.isNew) {
                            currentPostId = response.id;
                            document.getElementById('postId').value = response.id;
                            document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square"></i> 编辑文章';
                            document.getElementById('submitBtn').innerHTML = '<i class="bi bi-check-circle"></i> 更新文章';
                            document.getElementById('cancelBtn').textContent = '取消编辑';
                            
                            // 更新URL
                            const url = new URL(window.location.href);
                            url.searchParams.set('edit', response.id);
                            window.history.replaceState({}, document.title, url.toString());
                        }
                        
                        // 立即重置按钮状态
                        resetSubmitButton();
                        
                        // 刷新文章列表
                        setTimeout(() => {
                            refreshPostsList();
                        }, 500);
                        
                    } else {
                        showToast(response.message, 'danger');
                        // 重置按钮状态
                        resetSubmitButton();
                    }
                })
                .catch(error => {
                    hideLoading();
                    showToast('网络错误: ' + error.message, 'danger');
                    // 重置按钮状态
                    resetSubmitButton();
                    console.error('Error:', error);
                });
        });
        
        // 刷新文章列表
        function refreshPostsList(showMessage = false) {
            showPostsLoading();
            
            sendAjaxRequest({
                ajax: 1,
                action: 'get_posts'
            })
            .then(response => {
                hidePostsLoading();
                
                if (response.success) {
                    // 更新表格内容
                    document.getElementById('postsTableBody').innerHTML = response.html;
                    
                    if (showMessage) {
                        showToast('文章列表已刷新', 'success');
                    }
                    
                    // 重新绑定事件
                    bindTableEvents();
                    
                } else {
                    showToast('刷新失败: ' + response.message, 'danger');
                }
            })
            .catch(error => {
                hidePostsLoading();
                showToast('刷新失败: ' + error.message, 'danger');
                console.error('Error:', error);
            });
        }
        
        // 绑定表格事件
        function bindTableEvents() {
            // 绑定编辑按钮事件
            document.querySelectorAll('.edit-post').forEach(button => {
                button.addEventListener('click', function() {
                    const postId = this.getAttribute('data-id');
                    editPost(postId);
                });
            });
            
            // 绑定删除按钮事件
            document.querySelectorAll('.delete-post').forEach(button => {
                button.addEventListener('click', function() {
                    const postId = this.getAttribute('data-id');
                    deletePost(postId);
                });
            });
        }
        
        // 新建文章
        function newPost() {
            // 重置表单
            resetForm();
            
            // 更新表单标题和按钮
            document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square"></i> 发布新文章';
            document.getElementById('submitBtn').innerHTML = '<i class="bi bi-check-circle"></i> 发布文章';
            document.getElementById('cancelBtn').textContent = '取消';
            
            // 清空ID
            currentPostId = '';
            document.getElementById('postId').value = '';
            
            // 移除URL中的edit参数
            const url = new URL(window.location.href);
            url.searchParams.delete('edit');
            window.history.replaceState({}, document.title, url.toString());
            
            // 焦点到标题
            document.getElementById('title').focus();
        }
        
        // 重置表单
        function resetForm() {
            postForm.reset();
            document.getElementById('title').value = '';
            document.getElementById('post_content').value = '';
            document.getElementById('author').value = '<?= e($_SESSION['admin_username']) ?>';
            document.getElementById('categorySelect').value = '';
            document.getElementById('tagsInput').value = '';
            document.getElementById('is_pinned').checked = false;
            document.getElementById('is_featured').checked = false;

            // 重置发布状态
            const isPublishedSelect = document.querySelector('select[name="is_published"]');
            if (isPublishedSelect) {
                isPublishedSelect.value = '1';
            }
            
            // 重置音频字段
            document.getElementById('data_music_file').value = '';
            document.getElementById('data_lyric_file').value = '';
            document.getElementById('audioPreview').style.display = 'none';
            document.getElementById('audioFileInput').value = '';
            document.getElementById('lyricFileInput').value = '';
            
            // 设置音频字段默认值
            document.getElementById('data_music_enabled').checked = false;
            document.querySelector('select[name="data_position"]').value = 'static';
            document.querySelector('select[name="data_theme"]').value = 'auto';
            document.querySelector('select[name="data_size"]').value = 'normal';
            document.querySelector('select[name="data_autoplay"]').value = 'false';
            document.getElementById('data_embed').checked = false;
            document.getElementById('data_cover_mode').checked = false;
            
            // 隐藏音乐配置区域
            document.getElementById('musicConfigArea').style.display = 'none';
            
            // 重置封面图片
            document.getElementById('cover_image').value = '';
            document.getElementById('coverPreview').style.display = 'none';
            document.getElementById('coverImageFileInput').value = '';
            
            // 重置隐私设置
            document.getElementById('has_privacy_content').checked = false;
            document.getElementById('privacy_question').value = '';
            document.getElementById('privacy_answer').value = '';
            document.getElementById('privacy_type').value = 'fixed_answer';
            document.getElementById('approval_required').checked = false;
            document.getElementById('privacy_custom_text').value = '';
            document.getElementById('fixedAnswerField').style.display = 'block';
            document.getElementById('openAnswerOptions').style.display = 'none';
            document.getElementById('privacyConfigArea').style.display = 'none';
            
            // 重置支付设置
            document.getElementById('has_paid_content').checked = false;
            document.getElementById('post_price').value = '0.00';
            document.getElementById('paidConfigArea').style.display = 'none';
            
            // 重置 AI 摘要区域
            const aiSummaryBox = document.getElementById('aiSummaryPreviewBox');
            if (aiSummaryBox) {
                aiSummaryBox.style.display = 'none';
                aiSummaryBox.innerHTML = '';
            }
            const aiSummaryError = document.getElementById('aiSummaryError');
            if (aiSummaryError) {
                aiSummaryError.style.display = 'none';
                aiSummaryError.textContent = '';
            }
            
            // 清除验证状态
            document.getElementById('title').classList.remove('is-invalid');
            document.getElementById('post_content').classList.remove('is-invalid');
            document.getElementById('author').classList.remove('is-invalid');
            
            // 更新标签预览
            updateTagsPreview();
            
            // 重置编辑器内容
            const editor = document.querySelector('.CodeMirror');
            if (editor && editor.CodeMirror) {
                editor.CodeMirror.setValue('');
            }
            
            // 重置按钮状态
            resetSubmitButton();
        }
        
        // 编辑文章
        function editPost(postId) {
            if (!postId) return;
            
            showLoading('正在加载文章数据...');
            
            sendAjaxRequest({
                ajax: 1,
                action: 'get_post',
                id: postId
            })
            .then(response => {
                hideLoading();
                
                if (response.success) {
                    const post = response.post;
                    
                    // 填充表单
                    document.getElementById('postId').value = post.id;
                    document.getElementById('title').value = post.title;
                    document.getElementById('author').value = post.author;
                    document.getElementById('categorySelect').value = post.category || '';
                    document.getElementById('tagsInput').value = formatTagsForInput(post.tags || '');
                    document.getElementById('is_pinned').checked = post.is_pinned == 1;
                    document.getElementById('is_featured').checked = post.is_featured == 1;

                    // 填充发布状态
                    const isPublishedSelect = document.querySelector('select[name="is_published"]');
                    if (isPublishedSelect) {
                        isPublishedSelect.value = post.is_published;
                    }
                    
                    // 填充许可协议字段
                    const licenseSelect = document.getElementById('license');
                    if (licenseSelect) {
                        licenseSelect.value = post.license || 'CC BY-NC-ND 4.0';
                        // 更新协议说明
                        updateLicenseDescription();
                    }
                    
                    // 填充封面图片字段
                    document.getElementById('cover_image').value = post.cover_image || '';
                    if (post.cover_image) {
                        document.getElementById('coverPreview').innerHTML = `
                            <img src="/${post.cover_image}" alt="封面预览" 
                                 style="max-width: 200px; max-height: 150px; border: 1px solid #ddd; border-radius: 4px;">
                        `;
                        document.getElementById('coverPreview').style.display = 'block';
                    } else {
                        document.getElementById('coverPreview').style.display = 'none';
                    }
                    
                    // 填充音频字段
                    document.getElementById('data_music_file').value = post.data_music_file || '';
                    document.getElementById('data_lyric_file').value = post.data_lyric_file || '';
                    document.getElementById('data_music_enabled').checked = (post.data_music_enabled || 'false') === 'true';
                    document.querySelector('select[name="data_position"]').value = post.data_position || 'static';
                    document.querySelector('select[name="data_theme"]').value = post.data_theme || 'auto';
                    document.querySelector('select[name="data_size"]').value = post.data_size || 'normal';
                    document.querySelector('select[name="data_autoplay"]').value = post.data_autoplay || 'false';
                    document.getElementById('data_embed').checked = (post.data_embed || 'false') === 'true';
                    document.getElementById('data_cover_mode').checked = (post.data_cover_mode || 'false') === 'true';
                    
                    // 根据音乐播放器开关状态显示/隐藏配置区域
                    const musicConfigArea = document.getElementById('musicConfigArea');
                    const musicEnabled = (post.data_music_enabled || 'false') === 'true';
                    if (musicEnabled) {
                        musicConfigArea.style.display = 'block';
                    } else {
                        musicConfigArea.style.display = 'none';
                    }
                    
                    // 加载隐私设置
                    const privacyConfigArea = document.getElementById('privacyConfigArea');
                    const hasPrivacyContent = (post.has_privacy_content || 0) == 1;
                    document.getElementById('has_privacy_content').checked = hasPrivacyContent;
                    document.getElementById('privacy_question').value = post.privacy_question || '';
                    document.getElementById('privacy_type').value = post.privacy_type || 'fixed_answer';
                    document.getElementById('approval_required').checked = (post.approval_required || 0) == 1;
                    document.getElementById('privacy_custom_text').value = post.privacy_custom_text || '';
                    // 注意：出于安全原因，不加载密码/答案字段
                    document.getElementById('privacy_answer').value = '';
                    
                    // 根据验证类型显示/隐藏相应字段
                    togglePrivacyAnswerField();
                    
                    if (hasPrivacyContent) {
                        privacyConfigArea.style.display = 'block';
                    } else {
                        privacyConfigArea.style.display = 'none';
                    }
                    
                    // 加载支付设置
                    const paidConfigArea = document.getElementById('paidConfigArea');
                    const hasPaidContent = (post.has_paid_content || 0) == 1;
                    document.getElementById('has_paid_content').checked = hasPaidContent;
                    document.getElementById('post_price').value = post.post_price || '0.00';
                    
                    if (hasPaidContent) {
                        paidConfigArea.style.display = 'block';
                    } else {
                        paidConfigArea.style.display = 'none';
                    }
                    
                    // 加载 AI 摘要
                    const aiSummaryBox = document.getElementById('aiSummaryPreviewBox');
                    const aiSummaryError = document.getElementById('aiSummaryError');
                    if (post.ai_summary) {
                        if (aiSummaryBox) {
                            aiSummaryBox.style.display = '';
                            aiSummaryBox.className = 'mt-2 p-2 bg-light rounded small';
                            aiSummaryBox.innerHTML = '<strong>当前摘要：</strong><span id="aiSummaryPreviewText">' + escapeHtmlAi(post.ai_summary || '').replace(/\n/g, '<br>') + '</span>';
                        }
                    } else {
                        if (aiSummaryBox) {
                            aiSummaryBox.style.display = 'none';
                            aiSummaryBox.innerHTML = '';
                        }
                    }
                    if (aiSummaryError) {
                        aiSummaryError.style.display = 'none';
                        aiSummaryError.textContent = '';
                    }
                    
                    // 显示音频预览
                    if (post.data_music_file) {
                        showAudioPreview(post.data_music_file);
                    } else {
                        document.getElementById('audioPreview').style.display = 'none';
                    }
                    
                    // 更新编辑器内容
                    const editor = document.querySelector('.CodeMirror');
                    if (editor && editor.CodeMirror) {
                        editor.CodeMirror.setValue(post.content || '');
                    } else {
                        document.getElementById('post_content').value = post.content || '';
                    }
                    
                    // 更新界面状态
                    currentPostId = post.id;
                    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square"></i> 编辑文章';
                    document.getElementById('submitBtn').innerHTML = '<i class="bi bi-check-circle"></i> 更新文章';
                    document.getElementById('cancelBtn').textContent = '取消编辑';
                    
                    // 更新URL
                    const url = new URL(window.location.href);
                    url.searchParams.set('edit', post.id);
                    window.history.replaceState({}, document.title, url.toString());
                    
                    // 更新标签预览
                    updateTagsPreview();
                    
                    // 焦点到标题
                    document.getElementById('title').focus();
                    
                    // 滚动到表单
                    document.querySelector('.card.mb-4').scrollIntoView({ behavior: 'smooth' });
                    
                } else {
                    showToast(response.message, 'danger');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('加载失败: ' + error.message, 'danger');
                console.error('Error:', error);
            });
        }
        
        // 删除文章
        function deletePost(postId) {
            if (!confirm('确定要删除这篇文章吗？此操作不可恢复！')) {
                return;
            }
            
            const row = document.getElementById('post-row-' + postId);
            if (row) {
                row.classList.add('deleting');
            }
            
            showLoading('正在删除文章...');
            
            sendAjaxRequest({
                ajax: 1,
                action: 'delete',
                id: postId
            })
            .then(response => {
                hideLoading();
                
                if (response.success) {
                    showToast(response.message, 'success');
                    
                    // 动画移除行
                    if (row) {
                        row.classList.add('deleted');
                        setTimeout(() => {
                            row.remove();
                            
                            // 如果没有文章了，显示空状态
                            const tableBody = document.getElementById('postsTableBody');
                            if (tableBody.children.length === 0) {
                                tableBody.innerHTML = '<tr><td colspan="8" class="text-center py-5"><i class="bi bi-file-text display-1 text-muted"></i><p class="lead mt-3">还没有文章，赶快发布一篇吧！</p></td></tr>';
                            }
                        }, 300);
                    }
                    
                    // 如果删除的是当前正在编辑的文章，清空表单
                    if (currentPostId === postId) {
                        newPost();
                    }
                    
                } else {
                    showToast(response.message, 'danger');
                    if (row) {
                        row.classList.remove('deleting');
                    }
                }
            })
            .catch(error => {
                hideLoading();
                showToast('删除失败: ' + error.message, 'danger');
                if (row) {
                    row.classList.remove('deleting');
                }
                console.error('Error:', error);
            });
        }
        
        // 标签输入实时预览
        tagsInput.addEventListener('input', updateTagsPreview);
        
        // 更新标签预览
        function updateTagsPreview() {
            const inputValue = tagsInput.value.trim();
            tagsPreview.innerHTML = '';
            
            if (!inputValue) {
                tagsPreview.innerHTML = '<span class="text-muted">暂无标签</span>';
                return;
            }
            
            const tags = inputValue.split('#').filter(tag => tag.trim() !== '');
            
            if (tags.length === 0) {
                tagsPreview.innerHTML = '<span class="text-muted">暂无标签</span>';
                return;
            }
            
            tags.forEach(tag => {
                const tagElement = document.createElement('span');
                tagElement.className = 'badge tag-badge';
                tagElement.style.backgroundColor = getTagColor(tag.trim());
                tagElement.innerHTML = `
                    <span class="tag-color-dot" style="background-color: ${adjustColorBrightness(getTagColor(tag.trim()), 30)}"></span>
                    ${tag.trim()}
                    <i class="bi bi-x" onclick="removeTag('${tag.trim()}')" style="margin-left: 5px; cursor: pointer; font-size: 10px;"></i>
                `;
                tagsPreview.appendChild(tagElement);
            });
        }
        
        // 添加标签
        function addTag(tag) {
            let currentValue = tagsInput.value.trim();
            
            if (!currentValue) {
                tagsInput.value = tag;
            } else {
                if (currentValue.endsWith('#')) {
                    currentValue = currentValue.slice(0, -1);
                }
                if (currentValue && !currentValue.endsWith('#')) {
                    currentValue += '#';
                }
                tagsInput.value = currentValue + tag;
            }
            updateTagsPreview();
        }
        
        // 移除标签
        function removeTag(tagToRemove) {
            let tags = tagsInput.value.split('#').filter(tag => tag.trim() !== '' && tag.trim() !== tagToRemove);
            tagsInput.value = tags.length > 0 ? '#' + tags.join('#') : '';
            updateTagsPreview();
        }
        
        // 获取标签颜色
        function getTagColor(tag) {
            const colors = ['#007bff', '#28a745', '#fd7e14', '#6f42c1', '#e83e8c', '#20c997', '#ffc107', '#dc3545', '#17a2b8', '#6c757d'];
            let sum = 0;
            for (let i = 0; i < tag.length; i++) {
                sum += tag.charCodeAt(i);
            }
            return colors[sum % colors.length];
        }
        
        // 调整颜色亮度
        function adjustColorBrightness(hex, percent) {
            const num = parseInt(hex.slice(1), 16);
            const amt = Math.round(2.55 * percent);
            const R = (num >> 16) + amt;
            const G = (num >> 8 & 0x00FF) + amt;
            const B = (num & 0x0000FF) + amt;
            
            return "#" + (
                0x1000000 +
                (R < 255 ? (R < 1 ? 0 : R) : 255) * 0x10000 +
                (G < 255 ? (G < 1 ? 0 : G) : 255) * 0x100 +
                (B < 255 ? (B < 1 ? 0 : B) : 255)
            ).toString(16).slice(1);
        }
        
        // 格式化标签用于输入框显示
        function formatTagsForInput(tags) {
            if (!tags) return '';
            const tagsArray = tags.split(',').filter(tag => tag.trim() !== '');
            return tagsArray.length > 0 ? '#' + tagsArray.join('#') : '';
        }
        
        // 音频上传相关函数
        function uploadAudioFile(file) {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'upload_audio');
            formData.append('audio_file', file);
            
            showLoading('正在上传音频文件...');
            
            return fetch('posts.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    document.getElementById('data_music_file').value = data.file_path;
                    showAudioPreview(data.file_path);
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'danger');
                }
                return data;
            })
            .catch(error => {
                hideLoading();
                showToast('音频上传失败: ' + error.message, 'danger');
                console.error('Error:', error);
            });
        }
        
        function uploadLyricFile(file) {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'upload_lyric');
            formData.append('lyric_file', file);
            
            showLoading('正在上传歌词文件...');
            
            return fetch('posts.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    document.getElementById('data_lyric_file').value = data.file_path;
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'danger');
                }
                return data;
            })
            .catch(error => {
                hideLoading();
                showToast('歌词上传失败: ' + error.message, 'danger');
                console.error('Error:', error);
            });
        }
        
        function showAudioPreview(filePath) {
            const preview = document.getElementById('audioPreview');
            const audio = preview.querySelector('audio');
            audio.src = '/' + filePath;
            preview.style.display = 'block';
        }
        
        function clearAudioFile() {
            document.getElementById('data_music_file').value = '';
            document.getElementById('audioPreview').style.display = 'none';
            document.getElementById('audioFileInput').value = '';
        }
        
        function clearLyricFile() {
            document.getElementById('data_lyric_file').value = '';
            document.getElementById('lyricFileInput').value = '';
        }
        
        // 封面图片相关函数
        document.getElementById('coverImageFileInput').addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                // 显示加载动画
                const previewDiv = document.getElementById('coverPreview');
                previewDiv.style.display = 'block';
                previewDiv.innerHTML = `
                    <div class="d-flex align-items-center text-muted py-2">
                        <div class="spinner-border spinner-border-sm me-2" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <span>正在上传...</span>
                    </div>
                `;

                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('action', 'upload_cover');
                formData.append('cover_image_file', file);
                
                fetch('posts.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('cover_image').value = data.file_path;
                        previewDiv.innerHTML = `
                            <img src="/${data.file_path}" alt="封面预览" 
                                 style="max-width: 200px; max-height: 150px; border: 1px solid #ddd; border-radius: 4px;">
                        `;
                        previewDiv.style.display = 'block';
                        showToast(data.message, 'success');
                    } else {
                        previewDiv.innerHTML = '';
                        previewDiv.style.display = 'none';
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    previewDiv.innerHTML = '';
                    previewDiv.style.display = 'none';
                    showToast('上传失败，请重试', 'error');
                });
            }
        });
        
        function clearCoverImage() {
            document.getElementById('cover_image').value = '';
            document.getElementById('coverPreview').style.display = 'none';
            document.getElementById('coverImageFileInput').value = '';
        }
        
        // 处理音乐模式互斥逻辑
        function handleMusicModeChange() {
            const embedCheckbox = document.getElementById('data_embed');
            const autoplaySelect = document.getElementById('data_autoplay');
            
            if (embedCheckbox && autoplaySelect) {
                if (embedCheckbox.checked) {
                    // 如果选择嵌入模式，自动设置为手动播放
                    autoplaySelect.value = 'false';
                    showToast('嵌入模式下已自动关闭自动播放', 'info');
                }
            }
        }
        
        function handleAutoplayChange() {
            const embedCheckbox = document.getElementById('data_embed');
            const autoplaySelect = document.getElementById('data_autoplay');
            
            if (embedCheckbox && autoplaySelect) {
                if (autoplaySelect.value === 'true') {
                    // 如果选择自动播放，自动关闭嵌入模式
                    embedCheckbox.checked = false;
                    showToast('自动播放模式下已自动关闭嵌入模式', 'info');
                }
            }
        }
        
        // 页面初始化
        document.addEventListener('DOMContentLoaded', function() {
            updateTagsPreview();
            
            // 音乐播放器开关事件
            document.getElementById('data_music_enabled').addEventListener('change', function(e) {
                const musicConfigArea = document.getElementById('musicConfigArea');
                if (e.target.checked) {
                    musicConfigArea.style.display = 'block';
                } else {
                    musicConfigArea.style.display = 'none';
                }
            });
            
            // 初始化音乐配置区域显示状态
            const musicEnabled = document.getElementById('data_music_enabled').checked;
            const musicConfigArea = document.getElementById('musicConfigArea');
            if (musicEnabled) {
                musicConfigArea.style.display = 'block';
            } else {
                musicConfigArea.style.display = 'none';
            }
            
            // 隐私内容设置切换
            document.getElementById('has_privacy_content').addEventListener('change', function() {
                const privacyConfigArea = document.getElementById('privacyConfigArea');
                if (this.checked) {
                    privacyConfigArea.style.display = 'block';
                } else {
                    privacyConfigArea.style.display = 'none';
                }
            });
            
            // 初始化隐私配置区域显示状态
            const privacyEnabled = document.getElementById('has_privacy_content').checked;
            const privacyConfigArea = document.getElementById('privacyConfigArea');
            if (privacyEnabled) {
                privacyConfigArea.style.display = 'block';
            } else {
                privacyConfigArea.style.display = 'none';
            }
            
            // 音频文件上传事件
            document.getElementById('audioFileInput').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    uploadAudioFile(file);
                }
            });
            
            // 歌词文件上传事件
            document.getElementById('lyricFileInput').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    uploadLyricFile(file);
                }
            });
            
            // 支付设置切换函数
            window.togglePaidSettings = function() {
                const isChecked = document.getElementById('has_paid_content').checked;
                const paidConfigArea = document.getElementById('paidConfigArea');
                
                if (isChecked) {
                    paidConfigArea.style.display = 'block';
                } else {
                    paidConfigArea.style.display = 'none';
                }
            }

            // 隐私设置切换函数
            window.togglePrivacySettings = function() {
                const isChecked = document.getElementById('has_privacy_content').checked;
                const privacyConfigArea = document.getElementById('privacyConfigArea');
                
                if (isChecked) {
                    privacyConfigArea.style.display = 'block';
                } else {
                    privacyConfigArea.style.display = 'none';
                }
            }
            
            // 隐私答案字段切换函数
            window.togglePrivacyAnswerField = function() {
                const privacyType = document.getElementById('privacy_type').value;
                const privacyQuestion = document.getElementById('privacyQuestionField');
                const fixedAnswerField = document.getElementById('fixedAnswerField');
                const openAnswerOptions = document.getElementById('openAnswerOptions');
                const questionRequiredStar = document.getElementById('questionRequiredStar');
                
                if (privacyType === 'login_only') {
                    // 仅需登录，隐藏问题和答案字段
                    privacyQuestion.style.display = 'none';
                    fixedAnswerField.style.display = 'none';
                    openAnswerOptions.style.display = 'none';
                    if (questionRequiredStar) questionRequiredStar.style.display = 'none';
                } else if (privacyType === 'fixed_answer') {
                    // 固定答案，显示问题和答案字段
                    privacyQuestion.style.display = 'block';
                    fixedAnswerField.style.display = 'block';
                    openAnswerOptions.style.display = 'none';
                    if (questionRequiredStar) questionRequiredStar.style.display = 'inline';
                } else if (privacyType === 'open_answer') {
                    // 开放答案，显示问题和开放选项
                    privacyQuestion.style.display = 'block';
                    fixedAnswerField.style.display = 'none';
                    openAnswerOptions.style.display = 'block';
                    if (questionRequiredStar) questionRequiredStar.style.display = 'inline';
                } else if (privacyType === 'manual_approval') {
                    // 人工审核，显示问题但隐藏其他选项
                    privacyQuestion.style.display = 'block';
                    fixedAnswerField.style.display = 'none';
                    openAnswerOptions.style.display = 'none';
                    if (questionRequiredStar) questionRequiredStar.style.display = 'inline';
                }
            }
            
            // 初始化音频预览
            const audioPath = document.getElementById('data_music_file').value;
            if (audioPath) {
                showAudioPreview(audioPath);
            }
            
            // 初始化隐私字段显示状态
            togglePrivacyAnswerField();
            
            // 绑定表格事件
            bindTableEvents();
            
            <?php if ($editPost): ?>
            document.getElementById('title').focus();
            <?php endif; ?>
        });
    </script>

    <script>
        // 更新协议说明
        function updateLicenseDescription() {
            const licenseSelect = document.getElementById('license');
            const selectedOption = licenseSelect.options[licenseSelect.selectedIndex];
            const description = selectedOption.getAttribute('data-desc') || '';

            const descElement = document.getElementById('licenseDescription');
            if (descElement) {
                descElement.textContent = description;
            }
        }

        // 页面加载时初始化协议说明
        document.addEventListener('DOMContentLoaded', function() {
            updateLicenseDescription();
        });

        // 分类颜色设置函数
        function setCategoryColor(color) {
            const colorInputs = document.querySelectorAll('input[name="category_color"]');
            colorInputs.forEach(input => {
                if (input.type === 'color') {
                    input.value = color;
                } else {
                    input.value = color;
                }
            });
        }
    </script>
<?php require_once 'includes/footer.php'; ?>

<?php
/**
 * 获取许可协议的详细说明
 */
function getLicenseDescription($license) {
    $descriptions = [
        'CC BY 4.0' => '允许他人自由共享、修改作品，但必须注明原作者姓名及来源',
        'CC BY-NC 4.0' => '允许他人非商业性使用、修改作品，必须注明原作者及来源，不得用于商业目的',
        'CC BY-SA 4.0' => '允许他人自由共享、修改作品，必须注明原作者，且衍生作品须采用相同许可协议',
        'CC BY-NC-SA 4.0' => '允许他人非商业性使用、修改作品，必须注明原作者，且衍生作品须采用相同许可协议，不得用于商业目的',
        'CC BY-ND 4.0' => '允许他人自由共享作品，但必须注明原作者及来源，不得对作品进行任何修改或衍生',
        'CC BY-NC-ND 4.0' => '允许他人非商业性共享作品，必须注明原作者及来源，不得修改、衍生或用于商业目的',
        'MIT' => '最宽松的开源许可，允许任何人以任何目的使用、复制、修改、合并、出版发行、散布、再授权及贩售软件的副本',
        'Apache-2.0' => '允许自由使用、修改和分发，要求保留版权声明和许可声明，提供专利授权，适用于大型商业项目',
        'GPL-3.0' => '强传染性开源协议，要求衍生作品也必须采用GPL协议，修改后的源码必须公开',
        'LGPL-3.0' => '较宽松的GPL，允许链接到库而不使整个程序受GPL约束，适用于库和组件',
        'BSD-3-Clause' => '宽松开源协议，允许使用、修改和分发，只需保留版权声明和免责条款，没有传染性',
        'ODbL' => '开放数据库许可，要求共享-相同方式，适用于数据库内容，如OpenStreetMap',
        'CC0 1.0' => '放弃所有版权，将作品完全置于公有领域，允许任何人以任何方式使用，无需署名',
        'PLOS' => 'PLOS期刊的开放获取许可，基于CC BY，允许自由使用、分发和改编，必须注明来源',
        'ArXiv' => 'arXiv预印本平台的许可协议，通常基于CC协议，促进学术成果的快速传播',
        'OGL' => '开放游戏许可，允许使用、修改和分发游戏内容，适用于桌面角色扮演游戏规则',
        'GFDL' => 'GNU自由文档许可，要求复制和修改时保留许可声明，适用于维基百科等文档',
        '无协议' => '保留所有版权，未经授权不得使用、复制、修改或分发'
    ];

    return $descriptions[$license] ?? '请选择一个许可协议';
}
?>

<?php
// 辅助函数：调整颜色亮度
function adjustBrightness($hex, $percent) {
    $hex = str_replace('#', '', $hex);
    
    if (strlen($hex) == 3) {
        $hex = str_repeat(substr($hex,0,1), 2).str_repeat(substr($hex,1,1), 2).str_repeat(substr($hex,2,1), 2);
    }
    
    $r = hexdec(substr($hex,0,2));
    $g = hexdec(substr($hex,2,2));
    $b = hexdec(substr($hex,4,2));
    
    $r = max(0, min(255, $r + $percent));
    $g = max(0, min(255, $g + $percent));
    $b = max(0, min(255, $b + $percent));
    
    return '#'.str_pad(dechex($r), 2, '0', STR_PAD_LEFT)
               .str_pad(dechex($g), 2, '0', STR_PAD_LEFT)
               .str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
}

// 格式化标签用于输入框显示
function formatTagsForInput($tags) {
    if (empty($tags)) return '';
    
    $tagsArray = explode(',', $tags);
    $tagsArray = array_filter(array_map('trim', $tagsArray));
    
    return !empty($tagsArray) ? '#' . implode('#', $tagsArray) : '';
}

// 根据标签名生成随机颜色
function getRandomColor($str) {
    $colors = ['#007bff', '#28a745', '#fd7e14', '#6f42c1', '#e83e8c', '#20c997', '#ffc107', '#dc3545', '#17a2b8', '#6c757d'];
    
    // 使用 crc32 生成哈希值，避免大数溢出导致 float 转换警告
    $hash = crc32($str);
    
    // 确保索引为正数且在数组范围内
    $index = abs($hash) % count($colors);
    
    return $colors[$index];
}