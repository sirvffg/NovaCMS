<?php
/**
 * 备份管理页面
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../vendor/nova-json/class/plugin/class-plugin.php';
require_once '../vendor/nova-plugins/backup/class-backup.php';

$page_title = '备份管理';
$extra_css = '';
require_once 'includes/header.php';

// 渲染管理界面
include '../vendor/nova-plugins/backup/admin/index.php';

require_once 'includes/footer.php';