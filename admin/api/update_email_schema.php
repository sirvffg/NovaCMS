<?php
require_once '../../config/database.php';

function updateDatabaseSchema() {
    $db = getDB();
    
    // 要添加的字段
    $columns = [
        'smtp_host' => "VARCHAR(255) DEFAULT 'smtp.qq.com'",
        'smtp_port' => "INT DEFAULT 465",
        'smtp_username' => "VARCHAR(255) DEFAULT ''",
        'smtp_password' => "VARCHAR(255) DEFAULT ''",
        'smtp_encryption' => "VARCHAR(10) DEFAULT 'ssl'",
        'smtp_from_name' => "VARCHAR(255) DEFAULT 'LyGalaxy'",
        'allowed_email_domains' => "TEXT DEFAULT 'qq.com,vip.qq.com,foxmail.com,163.com,126.com,yeah.net,sina.com,sina.cn,sohu.com,139.com,aliyun.com,gmail.com,outlook.com,hotmail.com,live.com,yahoo.com,yahoo.co.jp,icloud.com,proton.me,protonmail.com,mail.com,gmx.com,gmx.de' COMMENT '允许注册的邮箱后缀'"
    ];

    $existingColumns = [];
    try {
        $rs = $db->query("SELECT * FROM website_config LIMIT 0");
        for ($i = 0; $i < $rs->columnCount(); $i++) {
            $col = $rs->getColumnMeta($i);
            $existingColumns[] = $col['name'];
        }
    } catch (Exception $e) {
        die("无法获取表结构: " . $e->getMessage());
    }

    $added = [];
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existingColumns)) {
            try {
                $db->exec("ALTER TABLE website_config ADD COLUMN $name $definition");
                $added[] = $name;
            } catch (Exception $e) {
                echo "添加字段 $name 失败: " . $e->getMessage() . "\n";
            }
        }
    }

    if (empty($added)) {
        echo "数据库结构已是最新，无需更新。\n";
    } else {
        echo "成功添加字段: " . implode(', ', $added) . "\n";
    }
}

updateDatabaseSchema();
?>