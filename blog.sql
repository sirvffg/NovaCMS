-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2026-07-28 20:40:35
-- 服务器版本： 5.7.44-log
-- PHP 版本： 8.2.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `blog`
--

-- --------------------------------------------------------

--
-- 表的结构 `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL COMMENT '管理员ID',
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '用户名',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '密码(加密)',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '邮箱',
  `role` enum('admin','user') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user' COMMENT '角色: admin-管理员, user-普通用户',
  `last_login` timestamp NULL DEFAULT NULL COMMENT '最后登录时间',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `is_banned` tinyint(1) DEFAULT '0' COMMENT '是否封禁: 0-正常, 1-封禁',
  `login_attempts` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '连续登录失败次数',
  `last_login_attempt` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '最后登录尝试时间戳',
  `register_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '注册IP'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员表';

--
-- 转存表中的数据 `admins`
--

INSERT INTO `admins` (`id`, `username`, `password`, `email`, `role`, `last_login`, `created_at`, `is_banned`, `login_attempts`, `last_login_attempt`, `register_ip`) VALUES
(1, 'admin', '$2y$12$/04Cj5MqHMmiUnuGy8cmUeVhU4BDzxeIAPX8HV187wxuxj7eKAse6', '2648181326@qq.com', 'admin', '2026-07-27 05:43:02', '2025-12-02 19:21:11', 0, 0, 0, ''),
(2, '隐私表单重新填写', '$2y$12$k0OyVK0zZ3SUSfxgPgVkueNOo97KAl7MoPHAKRHyvCtb9NSDpqFMG', 'X#######@qq.com', 'user', NULL, '2025-12-10 21:46:34', 0, 0, 0, '');

-- --------------------------------------------------------

--
-- 表的结构 `appeals`
--

CREATE TABLE `appeals` (
  `id` int(11) NOT NULL,
  `appeal_type` enum('ip','user','ip_user') NOT NULL COMMENT '申诉类型',
  `ip_address` varchar(45) NOT NULL COMMENT '申诉IP地址',
  `user_id` int(11) DEFAULT NULL COMMENT '关联用户ID（用户申诉时）',
  `contact_name` varchar(50) NOT NULL COMMENT '联系人姓名',
  `contact_email` varchar(100) NOT NULL COMMENT '联系邮箱',
  `reason` text NOT NULL COMMENT '申诉理由',
  `status` enum('pending','approved','rejected','processing') DEFAULT 'pending' COMMENT '状态: pending-待审核, approved-通过, rejected-拒绝, processing-处理中',
  `admin_reply` text COMMENT '管理员回复',
  `reviewed_by` int(11) DEFAULT NULL COMMENT '审核管理员ID',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '提交时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='申诉记录表';

-- --------------------------------------------------------

--
-- 表的结构 `appeal_tokens`
--

CREATE TABLE `appeal_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='申诉页面登录token';

-- --------------------------------------------------------

--
-- 表的结构 `blog_categories`
--

CREATE TABLE `blog_categories` (
  `id` int(11) NOT NULL COMMENT '分类ID',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分类名称',
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分类别名',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '分类描述',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#007bff' COMMENT '分类标签颜色（十六进制颜色码）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='博客分类表';

--
-- 转存表中的数据 `blog_categories`
--

INSERT INTO `blog_categories` (`id`, `name`, `slug`, `description`, `sort_order`, `created_at`, `color`) VALUES
(1, '分享', 'tech', '技术相关的文章', 1, '2025-12-02 19:21:11', '#dc3545'),
(2, '新闻', 'news', '行业新闻', 2, '2025-12-02 19:21:11', '#007bff'),
(3, '工具', 'tools', '一些实用的工具', 3, '2025-12-02 19:21:11', '#ffc107'),
(4, '项目', 'projects', '一些其他的分享', 6, '2025-12-02 19:21:11', '#6c757d'),
(5, '游戏', 'game', '游戏', 4, '2026-03-06 00:25:29', '#20c997'),
(6, '说说', 'shuoshuo', '一些杂谈', 5, '2026-05-11 18:53:19', '#17a2b8');

-- --------------------------------------------------------

--
-- 表的结构 `blog_comments`
--

CREATE TABLE `blog_comments` (
  `id` int(11) NOT NULL COMMENT '评论ID',
  `post_id` int(11) NOT NULL COMMENT '文章ID',
  `user_id` int(11) DEFAULT NULL COMMENT '用户ID（管理员）',
  `username` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户名',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '邮箱',
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '评论内容',
  `parent_id` int(11) DEFAULT NULL COMMENT '父评论ID（用于回复）',
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '状态: pending-待审核, approved-已通过, rejected-已拒绝',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP地址',
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户代理',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='博客评论表';

-- --------------------------------------------------------

--
-- 表的结构 `blog_paid_access`
--

CREATE TABLE `blog_paid_access` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT '购买用户ID',
  `post_id` int(11) NOT NULL COMMENT '购买文章ID',
  `trade_no` varchar(64) DEFAULT NULL COMMENT '本站订单号',
  `amount` decimal(10,2) NOT NULL COMMENT '支付金额',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0: 待支付, 1: 已支付',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章付费记录表';

-- --------------------------------------------------------

--
-- 表的结构 `blog_paid_access_temporary`
--

CREATE TABLE `blog_paid_access_temporary` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT '购买用户ID',
  `post_id` int(11) NOT NULL COMMENT '购买文章ID',
  `trade_no` varchar(64) DEFAULT NULL COMMENT '本站订单号',
  `amount` decimal(10,2) NOT NULL COMMENT '支付金额',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章付费记录临时表';

-- --------------------------------------------------------

--
-- 表的结构 `blog_posts`
--

CREATE TABLE `blog_posts` (
  `id` int(11) NOT NULL COMMENT '文章ID',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文章标题',
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '文章内容(支持Markdown)',
  `summary` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '文章摘要',
  `author` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '作者',
  `cover_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '封面图片URL',
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '分类',
  `tags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '标签(逗号分隔)',
  `views` int(11) DEFAULT '0' COMMENT '浏览量',
  `is_published` tinyint(1) DEFAULT '1' COMMENT '是否发布(1:发布 0:草稿)',
  `is_pinned` tinyint(1) DEFAULT '0' COMMENT '是否置顶(1:置顶 0:普通)',
  `is_featured` tinyint(1) DEFAULT '0' COMMENT '是否精选(1:精选 0:普通)',
  `published_at` timestamp NULL DEFAULT NULL COMMENT '发布时间',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `privacy_question` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '访问隐私内容需要回答的问题',
  `privacy_answer` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '访问隐私内容需要的答案（md5加密）',
  `has_privacy_content` tinyint(1) DEFAULT '0' COMMENT '是否包含隐私内容(0:无 1:有)',
  `has_paid_content` tinyint(1) DEFAULT '0' COMMENT '是否包含付费内容',
  `post_price` decimal(10,2) DEFAULT '0.00' COMMENT '文章价格',
  `privacy_type` enum('fixed_answer','open_answer','manual_approval','login_only') COLLATE utf8mb4_unicode_ci DEFAULT 'fixed_answer' COMMENT '隐私内容验证类型: fixed_answer-固定答案, open_answer-开放答案, manual_approval-人工审核, login_only-仅需登录',
  `approval_required` tinyint(1) DEFAULT '0' COMMENT '开放答案是否需要管理员审核(0:自动授权 1:需要审核)',
  `privacy_custom_text` text COLLATE utf8mb4_unicode_ci,
  `license` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'CC BY-NC-SA 4.0' COMMENT '文章许可协议'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='博客文章表';

--
-- 转存表中的数据 `blog_posts`
--

INSERT INTO `blog_posts` (`id`, `title`, `content`, `summary`, `author`, `cover_image`, `category`, `tags`, `views`, `is_published`, `is_pinned`, `is_featured`, `published_at`, `created_at`, `updated_at`, `privacy_question`, `privacy_answer`, `has_privacy_content`, `has_paid_content`, `post_price`, `privacy_type`, `approval_required`, `privacy_custom_text`, `license`) VALUES
(1, '如何使用Markdown写作', '我来为你详细介绍Markdown的使用方法...', NULL, 'admin', NULL, '项目案例', '笔记', 516, 1, 0, 0, '2025-12-02 19:21:11', '2025-12-02 19:21:11', '2026-07-27 05:48:56', '您看到信息的平台+你的账号ID  以及对网站的建议...', NULL, 1, 0, 0.00, 'manual_approval', 1, '下载的内容在填写表单之后会显示哦！...', 'CC BY 4.0');

-- --------------------------------------------------------

--
-- 表的结构 `blog_privacy_access`
--

CREATE TABLE `blog_privacy_access` (
  `id` int(11) NOT NULL COMMENT '记录ID',
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `post_id` int(11) NOT NULL COMMENT '文章ID',
  `answer` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '用户提交的答案（原始文本）',
  `is_correct` tinyint(1) NOT NULL COMMENT '答案是否正确(0:错误 1:正确)',
  `access_granted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否获得访问权限(0:未授权 1:已授权)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='博客隐私访问记录表';

-- --------------------------------------------------------

--
-- 表的结构 `bot_blacklist`
--

CREATE TABLE `bot_blacklist` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `bot_whitelist_ip`
--

CREATE TABLE `bot_whitelist_ip` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(500) DEFAULT NULL COMMENT '备注',
  `added_by` int(11) DEFAULT NULL COMMENT '添加者ID',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP白名单';

-- --------------------------------------------------------

--
-- 表的结构 `bot_whitelist_ua`
--

CREATE TABLE `bot_whitelist_ua` (
  `id` int(11) NOT NULL,
  `ua_pattern` varchar(500) NOT NULL COMMENT 'UA标识',
  `reason` varchar(500) DEFAULT NULL COMMENT '备注',
  `added_by` int(11) DEFAULT NULL COMMENT '添加者ID',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='UA白名单';

-- --------------------------------------------------------

--
-- 表的结构 `captcha_sessions`
--

CREATE TABLE `captcha_sessions` (
  `token` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '验证令牌',
  `salt` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'POW盐值',
  `difficulty` tinyint(4) NOT NULL DEFAULT '5' COMMENT 'POW难度',
  `status` enum('init','pow_verified','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'init' COMMENT '会话状态',
  `valid_x` smallint(6) NOT NULL DEFAULT '0' COMMENT '正确X坐标',
  `valid_y` smallint(6) NOT NULL DEFAULT '0' COMMENT '正确Y坐标',
  `valid_shape` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '正确形状',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '请求IP',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `expire_at` timestamp NOT NULL COMMENT '过期时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='验证码会话表';

-- --------------------------------------------------------

--
-- 表的结构 `captcha_tokens`
--

CREATE TABLE `captcha_tokens` (
  `token` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '业务令牌',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '验证时IP',
  `verified_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '验证通过时间',
  `expire_at` timestamp NOT NULL COMMENT '过期时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='验证码业务令牌表';

-- --------------------------------------------------------

--
-- 表的结构 `crawler_logs`
--

CREATE TABLE `crawler_logs` (
  `id` int(11) NOT NULL,
  `crawler_name` varchar(50) NOT NULL,
  `user_agent` text NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `request_url` text NOT NULL,
  `visit_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `status_code` int(11) DEFAULT '200'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `email_verification`
--

CREATE TABLE `email_verification` (
  `id` int(11) NOT NULL COMMENT '验证码ID',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '邮箱地址',
  `code` varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '验证码',
  `purpose` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'register' COMMENT '用途: register=注册, reset=重置密码',
  `is_used` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已使用(0:未使用 1:已使用)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `expires_at` timestamp NOT NULL COMMENT '过期时间',
  `used_at` timestamp NULL DEFAULT NULL COMMENT '使用时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮箱验证码表';

-- --------------------------------------------------------

--
-- 表的结构 `friend_links`
--

CREATE TABLE `friend_links` (
  `id` int(11) NOT NULL COMMENT '链接ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '网站名称',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '网站链接',
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '网站Logo',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '网站描述',
  `rss_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'RSS地址',
  `contact_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `category_id` int(11) DEFAULT NULL COMMENT '分类ID',
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '分类',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序(数字越小越靠前)',
  `is_active` tinyint(1) DEFAULT '1' COMMENT '是否显示(1:显示 0:隐藏)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='友情链接表';

-- --------------------------------------------------------

--
-- 表的结构 `friend_link_applications`
--

CREATE TABLE `friend_link_applications` (
  `id` int(11) NOT NULL COMMENT '申请ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '网站名称',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '网站链接',
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '网站Logo',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '网站描述',
  `rss_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'RSS地址',
  `category_id` int(11) DEFAULT NULL COMMENT '分类ID',
  `contact_email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '联系邮箱',
  `contact_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '联系人',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态(0:待审核 1:已通过 2:已拒绝)',
  `user_id` int(11) DEFAULT NULL COMMENT '提交用户ID',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '提交IP地址',
  `review_notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核备注',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '申请时间',
  `reviewed_at` timestamp NULL DEFAULT NULL COMMENT '审核时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='友情链接申请表';

-- --------------------------------------------------------

--
-- 表的结构 `friend_link_categories`
--

CREATE TABLE `friend_link_categories` (
  `id` int(11) NOT NULL COMMENT '分类ID',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分类名称',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '分类描述',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序(数字越小越靠前)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='友情链接分类表';

--
-- 转存表中的数据 `friend_link_categories`
--

INSERT INTO `friend_link_categories` (`id`, `name`, `description`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, '技术社区', '开发者技术社区和论坛', 2, '2026-01-12 12:16:02', '2026-07-07 15:19:24'),
(2, '友情链接', '个人博客以及朋友们', 3, '2026-01-12 12:16:02', '2026-07-07 15:19:30'),
(3, '工具服务', '在线工具和API服务', 4, '2026-01-12 12:16:02', '2026-07-07 15:19:36'),
(4, '博客聚合平台', '博客聚合地-承接每个博客的RSS', 1, '2026-07-06 15:42:35', '2026-07-07 15:20:11'),
(5, '失联博客', '暂时失联或者关站博客', 1000000001, '2026-07-06 17:00:26', '2026-07-09 17:53:30'),
(6, '单向友链', '未互链的网站喵', 1000000000, '2026-07-07 14:41:49', '2026-07-09 17:53:00');

-- --------------------------------------------------------

--
-- 表的结构 `guestbook`
--

CREATE TABLE `guestbook` (
  `id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT '0',
  `nickname` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `reply_content` text,
  `reply_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `hitokoto`
--

CREATE TABLE `hitokoto` (
  `id` int(11) NOT NULL,
  `hitokoto` text NOT NULL COMMENT '内容',
  `from` varchar(255) DEFAULT NULL COMMENT '来源',
  `from_who` varchar(255) DEFAULT NULL COMMENT '作者',
  `creator` varchar(255) DEFAULT NULL COMMENT '添加者',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一言表';

-- --------------------------------------------------------

--
-- 表的结构 `honeypot_logs`
--

CREATE TABLE `honeypot_logs` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `trap_type` varchar(50) NOT NULL,
  `trap_value` varchar(200) DEFAULT NULL,
  `triggered_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `ip_whitelist`
--

CREATE TABLE `ip_whitelist` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `license_announcements`
--

CREATE TABLE `license_announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `license_keys`
--

CREATE TABLE `license_keys` (
  `id` int(11) NOT NULL,
  `key_code` varchar(128) NOT NULL,
  `status` enum('unused','used') DEFAULT 'unused',
  `generated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `used_at` datetime DEFAULT NULL,
  `description` varchar(255) DEFAULT '',
  `verification_id` varchar(255) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `license_verification_logs`
--

CREATE TABLE `license_verification_logs` (
  `id` int(11) NOT NULL,
  `license_key` varchar(255) NOT NULL,
  `verification_id` varchar(255) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `system_version` varchar(50) DEFAULT NULL,
  `status` enum('valid','invalid','expired') DEFAULT 'valid',
  `check_time` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `license_version_updates`
--

CREATE TABLE `license_version_updates` (
  `id` int(11) NOT NULL,
  `version` varchar(50) NOT NULL,
  `update_type` enum('patch','minor','major') DEFAULT 'patch',
  `is_mandatory` tinyint(1) DEFAULT '0',
  `download_url` varchar(255) NOT NULL,
  `changelog` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `my_projects`
--

CREATE TABLE `my_projects` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT '项目名称',
  `description` text COMMENT '项目描述',
  `url` varchar(255) DEFAULT NULL COMMENT '项目链接',
  `icon` varchar(255) DEFAULT NULL COMMENT '图标(类名或图片路径)',
  `tags` varchar(255) DEFAULT NULL COMMENT '标签(逗号分隔)',
  `start_date` varchar(50) DEFAULT NULL COMMENT '开始时间',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序',
  `is_active` tinyint(1) DEFAULT '1' COMMENT '是否启用',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='我的项目表';

-- --------------------------------------------------------

--
-- 表的结构 `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL COMMENT '通知ID',
  `user_id` int(11) NOT NULL COMMENT '接收通知的用户ID',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '通知类型: comment-评论, reply-回复',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '通知标题',
  `content` longtext COLLATE utf8mb4_unicode_ci COMMENT '通知内容',
  `related_id` int(11) DEFAULT NULL COMMENT '相关ID（评论ID或文章ID）',
  `is_read` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已读(0:未读 1:已读)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知表';

-- --------------------------------------------------------

--
-- 表的结构 `photos`
--

CREATE TABLE `photos` (
  `id` int(11) NOT NULL,
  `album_id` int(11) NOT NULL DEFAULT '1',
  `url` varchar(255) NOT NULL,
  `title` varchar(100) DEFAULT NULL,
  `description` text,
  `sort_order` int(11) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `photo_albums`
--

CREATE TABLE `photo_albums` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `cover_image` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- 转存表中的数据 `photo_albums`
--

INSERT INTO `photo_albums` (`id`, `name`, `description`, `cover_image`, `sort_order`, `created_at`) VALUES
(1, '默认相册', '默认存储未分类的照片', NULL, 0, '2026-01-30 04:44:11');

-- --------------------------------------------------------

--
-- 表的结构 `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL,
  `identifier` varchar(255) NOT NULL COMMENT '标识符(IP/用户ID)',
  `action` varchar(50) NOT NULL COMMENT '操作类型',
  `attempts` int(11) NOT NULL DEFAULT '1' COMMENT '尝试次数',
  `first_attempt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '首次尝试时间',
  `last_attempt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后尝试时间',
  `blocked_until` timestamp NULL DEFAULT NULL COMMENT '封锁到期时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='速率限制表';

-- --------------------------------------------------------

--
-- 表的结构 `shuoshuo`
--

CREATE TABLE `shuoshuo` (
  `id` int(10) UNSIGNED NOT NULL,
  `content` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `ua_whitelist`
--

CREATE TABLE `ua_whitelist` (
  `id` int(11) NOT NULL,
  `ua_pattern` varchar(500) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT '关联 admins.id',
  `device_token` varchar(128) NOT NULL COMMENT '设备唯一标识（Cookie 值）',
  `device_name` varchar(100) DEFAULT '' COMMENT '设备名称（自动解析）',
  `ip_address` varchar(45) NOT NULL COMMENT '登录 IP',
  `user_agent` varchar(500) DEFAULT '' COMMENT '浏览器 UA',
  `login_method` varchar(20) DEFAULT 'password' COMMENT '登录方式: password/auto',
  `is_active` tinyint(1) DEFAULT '1' COMMENT '是否活跃: 1=在线, 0=已下线/过期',
  `is_current` tinyint(1) DEFAULT '0' COMMENT '是否当前设备: 1=是',
  `status` varchar(20) DEFAULT 'success' COMMENT '结果: success/failed',
  `fail_reason` varchar(200) DEFAULT NULL COMMENT '失败原因',
  `login_at` datetime NOT NULL COMMENT '登录时间',
  `last_active_at` datetime DEFAULT NULL COMMENT '最后活跃时间',
  `expires_at` datetime DEFAULT NULL COMMENT '过期时间',
  `deleted_by_user` tinyint(1) DEFAULT '0' COMMENT '用户是否主动删除: 1=是'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户会话与登录日志';

-- --------------------------------------------------------

--
-- 表的结构 `visit_stats`
--

CREATE TABLE `visit_stats` (
  `id` int(11) NOT NULL COMMENT '记录ID',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'IP地址',
  `user_agent` text COLLATE utf8mb4_unicode_ci COMMENT '浏览器信息',
  `page_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '访问页面',
  `visitor_username` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visitor_email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referer` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '来源页面',
  `visit_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间',
  `country` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '国家',
  `province` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '省份',
  `city` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '城市',
  `isp` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '运营商'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='访问统计表';

--
-- 转存表中的数据 `visit_stats`
--

INSERT INTO `visit_stats` (`id`, `ip_address`, `user_agent`, `page_url`, `visitor_username`, `visitor_email`, `referer`, `visit_time`, `country`, `province`, `city`, `isp`) VALUES
(1, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-27 06:01:40', NULL, NULL, NULL, NULL),
(2, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-27 06:01:42', NULL, NULL, NULL, NULL),
(3, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:47:15', NULL, NULL, NULL, NULL),
(4, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:49:38', NULL, NULL, NULL, NULL),
(5, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:49:38', NULL, NULL, NULL, NULL),
(6, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:49:41', NULL, NULL, NULL, NULL),
(7, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:49:41', NULL, NULL, NULL, NULL),
(8, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:49:53', NULL, NULL, NULL, NULL),
(9, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:49:53', NULL, NULL, NULL, NULL),
(10, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:49:58', NULL, NULL, NULL, NULL),
(11, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:49:58', NULL, NULL, NULL, NULL),
(12, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:50:33', NULL, NULL, NULL, NULL),
(13, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:50:33', NULL, NULL, NULL, NULL),
(14, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:52:27', NULL, NULL, NULL, NULL),
(15, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:53:03', NULL, NULL, NULL, NULL),
(16, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:53:03', NULL, NULL, NULL, NULL),
(17, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:58:24', NULL, NULL, NULL, NULL),
(18, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 08:58:24', NULL, NULL, NULL, NULL),
(19, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 09:38:20', NULL, NULL, NULL, NULL),
(20, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 09:38:20', NULL, NULL, NULL, NULL),
(21, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 09:38:21', NULL, NULL, NULL, NULL),
(22, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 09:38:22', NULL, NULL, NULL, NULL),
(23, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 09:38:22', NULL, NULL, NULL, NULL),
(24, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 09:38:22', NULL, NULL, NULL, NULL),
(25, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 11:05:38', NULL, NULL, NULL, NULL),
(26, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 11:29:49', NULL, NULL, NULL, NULL),
(27, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 11:29:51', NULL, NULL, NULL, NULL),
(28, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 11:29:55', NULL, NULL, NULL, NULL),
(29, '192.168.142.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '/', NULL, NULL, NULL, '2026-07-28 11:30:09', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- 表的结构 `website_config`
--

CREATE TABLE `website_config` (
  `id` int(11) NOT NULL COMMENT '配置ID',
  `website_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '我的网站' COMMENT '网站名称',
  `website_author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '站点所有者名称',
  `website_intro` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '网站简介',
  `use_local_hitokoto` tinyint(1) DEFAULT '0' COMMENT '是否使用本站一言',
  `website_announcement` text COLLATE utf8mb4_unicode_ci COMMENT '网站公告(支持Markdown)',
  `website_announcement_date` datetime DEFAULT NULL COMMENT '公告发布日期',
  `website_announcement_popup` tinyint(1) DEFAULT '0' COMMENT '是否弹窗展示公告',
  `website_announcement_enable` tinyint(1) DEFAULT '1' COMMENT '是否开启公告展示',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '网站描述（友链网站描述）',
  `robot_description` longtext COLLATE utf8mb4_unicode_ci COMMENT 'SEO描述（用于搜索引擎收录）',
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '网站Logo',
  `favicon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '网站图标',
  `contact_email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '联系邮箱',
  `contact_qq` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '联系QQ',
  `social_wechat` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '微信号',
  `social_github` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'GitHub',
  `email_mode` enum('test','production') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'test' COMMENT '邮件模式: test-测试模式, production-生产模式',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `website_start_time` datetime DEFAULT NULL COMMENT '网站开办时间',
  `social_douyin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '抖音',
  `social_kuaishou` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '快手',
  `social_bilibili` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'B站',
  `social_xiaohongshu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '小红书',
  `social_whatsapp` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `social_x` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `social_discord` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `social_youtube` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `smtp_host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'smtp.qq.com',
  `smtp_port` int(11) DEFAULT '465',
  `smtp_username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `smtp_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `smtp_encryption` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'ssl',
  `smtp_from_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'LyGalaxy',
  `smtp_ip_cache` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'SMTP服务器IP缓存',
  `smtp_ip_cache_time` int(11) DEFAULT '0' COMMENT 'SMTP IP缓存时间戳',
  `allowed_email_domains` text COLLATE utf8mb4_unicode_ci COMMENT '允许注册的邮箱后缀',
  `footer_extra` text COLLATE utf8mb4_unicode_ci COMMENT '页脚附加信息',
  `redirect_whitelist` text COLLATE utf8mb4_unicode_ci COMMENT '跳转白名单域名',
  `epay_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '易支付接口地址',
  `epay_pid` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '易支付商户ID',
  `epay_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '易支付商户密钥',
  `max_devices` int(11) DEFAULT '2' COMMENT '单用户最大同时在线设备数',
  `remember_duration` int(11) DEFAULT '30' COMMENT '记住我有效期（天）',
  `icp_record` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'ICP备案号',
  `public_security_record` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '公安备案号',
  `terms_content` longtext COLLATE utf8mb4_unicode_ci COMMENT '服务条款内容(支持HTML)',
  `privacy_content` longtext COLLATE utf8mb4_unicode_ci COMMENT '隐私政策内容(支持HTML)',
  `active_theme` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default' COMMENT '激活的主题'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='网站配置表';

--
-- 转存表中的数据 `website_config`
--

INSERT INTO `website_config` (`id`, `website_name`, `website_author`, `website_intro`, `use_local_hitokoto`, `website_announcement`, `website_announcement_date`, `website_announcement_popup`, `website_announcement_enable`, `description`, `robot_description`, `logo`, `favicon`, `contact_email`, `contact_qq`, `social_wechat`, `social_github`, `email_mode`, `updated_at`, `website_start_time`, `social_douyin`, `social_kuaishou`, `social_bilibili`, `social_xiaohongshu`, `social_whatsapp`, `social_x`, `social_discord`, `social_youtube`, `smtp_host`, `smtp_port`, `smtp_username`, `smtp_password`, `smtp_encryption`, `smtp_from_name`, `smtp_ip_cache`, `smtp_ip_cache_time`, `allowed_email_domains`, `footer_extra`, `redirect_whitelist`, `epay_url`, `epay_pid`, `epay_key`, `max_devices`, `remember_duration`, `icp_record`, `public_security_record`, `terms_content`, `privacy_content`, `active_theme`) VALUES
(1, '冷月笙寒的小窝', '冷月笙寒', 'https://api.fuchenboke.cn/api/shici.php', 1, '', NULL, 0, 0, '', '', '/assets/images/favicon.png', '', '', '', '', '', 'production', '2026-07-28 09:37:54', '2025-05-20 05:20:00', '', '', '', '', '', '', '', '', 'smtp.qq.com', 587, '', '', 'tls', '', '43.129.255.54', 1785128559, 'qq.com', 'Powered by LyGalaxy', '', '', '', '', 3, 20, '京ICP备20240428112号-1', '京公网安备1101050201637号', '1. 接受条款\r\n通过访问和使用本网站，您同意遵守这些服务条款。如果您不同意这些条款，请不要使用本网站。\r\n\r\n2. 网站描述\r\n本网站是一个展示个人作品、博客文章和相关服务的平台。我们致力于提供高质量的内容和良好的用户体验。\r\n\r\n3. 使用许可\r\n我们授予您有限的、非独占的、不可转让的许可来使用本网站，但您必须遵守以下条件：\r\n- 不得将网站用于任何非法或未经授权的目的\r\n- 不得干扰或破坏网站的正常运行\r\n- 不得试图获取未经授权的访问权限\r\n- 不得复制或重复使用网站内容，除非获得明确许可\r\n\r\n4. 内容所有权\r\n网站上的所有内容，包括但不限于文字、图片、代码、设计等，均受版权法和其他知识产权法保护。未经我们明确书面许可，您不得使用、复制或分发任何内容。\r\n\r\n5. 用户责任\r\n作为用户，您同意：\r\n- 提供准确和真实的信息\r\n- 不发布虚假、误导性或违法内容\r\n- 尊重他人的知识产权和隐私权\r\n- 不从事任何可能损害网站声誉的活动\r\n\r\n6. 免责声明\r\n本网站按\"现状\"提供，我们不对以下内容做任何保证：\r\n- 网站服务的连续性或无中断\r\n- 网站内容的准确性或完整性\r\n- 网站免受病毒或其他恶意组件的侵害\r\n- 因使用网站而导致的任何损失或损害\r\n\r\n7. 服务限制\r\n我们保留以下权利：\r\n- 随时修改或终止网站服务\r\n- 拒绝向任何人提供服务\r\n- 删除违反服务条款的内容\r\n- 暂停或终止违规用户的访问权限\r\n\r\n8. 第三方链接\r\n本网站可能包含指向第三方网站的链接。我们不对这些外部网站的内容、隐私政策或做法负责。访问第三方网站的风险由您自行承担。\r\n\r\n9. 争议解决\r\n这些服务条款受中国法律管辖。如发生争议，双方应首先通过友好协商解决。协商不成的，任何一方均可向网站经营者所在地人民法院提起诉讼。\r\n\r\n10. 条款修改\r\n我们保留随时修改这些服务条款的权利。修改后的条款将在网站上发布，并立即生效。继续使用本网站即表示您接受修改后的条款。\r\n\r\n11. 联系我们\r\n如果您对这些服务条款有任何疑问，请通过以下方式联系我们：\r\n邮箱：2648181326@qq.com\r\n\r\n最后更新：2026年7月20日', '1. 信息收集\r\n我们可能收集以下类型的信息：\r\n- 您通过联系表单提供的姓名、电子邮件地址等信息\r\n- 访问网站时的技术信息（IP地址、浏览器类型、访问时间等）\r\n- 通过Cookie收集的使用偏好信息\r\n\r\n2. 信息使用\r\n收集的信息可能用于：\r\n- 回复您的咨询和请求\r\n- 改善网站内容和用户体验\r\n- 发送重要的通知和更新\r\n- 网站分析和安全监控\r\n\r\n3. 信息共享\r\n我们不会向第三方出售、交易或转让您的个人信息，除非：\r\n- 获得您的明确同意\r\n- 法律要求或法律程序需要\r\n- 保护网站、用户或公众的权利、财产或安全\r\n\r\n4. 数据安全\r\n我们采取适当的安全措施来保护您的个人信息，包括：\r\n- 使用安全的服务器和加密技术\r\n- 限制对个人信息的访问权限\r\n- 定期更新安全协议\r\n\r\n5. Cookie使用\r\n本网站可能使用Cookie来：\r\n- 记住您的偏好设置\r\n- 分析网站流量和使用情况\r\n- 提供个性化的内容\r\n您可以通过浏览器设置控制Cookie的使用。\r\n\r\n6. 您的权利\r\n您有权：\r\n- 访问您的个人信息\r\n- 更正不准确的信息\r\n- 删除您的个人信息\r\n- 反对处理您的信息\r\n\r\n7. 政策更新\r\n我们可能会不时更新此隐私政策。重大变更时，我们会通过网站通知您。建议您定期查看此页面以获取最新信息。\r\n\r\n8. 联系我们\r\n如果您对此隐私政策有任何疑问或关注，请通过以下方式联系我们：\r\n邮箱：2648181326@qq.com\r\n\r\n最后更新：2026年7月20日', 'default');

--
-- 转储表的索引
--

--
-- 表的索引 `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- 表的索引 `appeals`
--
ALTER TABLE `appeals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_type` (`appeal_type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_ip` (`ip_address`),
  ADD KEY `idx_email` (`contact_email`),
  ADD KEY `idx_created` (`created_at`);

--
-- 表的索引 `appeal_tokens`
--
ALTER TABLE `appeal_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_token` (`token`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- 表的索引 `blog_categories`
--
ALTER TABLE `blog_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- 表的索引 `blog_comments`
--
ALTER TABLE `blog_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_post_id` (`post_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_parent_id` (`parent_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- 表的索引 `blog_paid_access`
--
ALTER TABLE `blog_paid_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_trade_no` (`trade_no`),
  ADD KEY `idx_user_post` (`user_id`,`post_id`);

--
-- 表的索引 `blog_paid_access_temporary`
--
ALTER TABLE `blog_paid_access_temporary`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trade_no` (`trade_no`);

--
-- 表的索引 `blog_posts`
--
ALTER TABLE `blog_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `views` (`views`),
  ADD KEY `is_pinned` (`is_pinned`),
  ADD KEY `is_featured` (`is_featured`),
  ADD KEY `idx_list` (`is_published`,`category`,`created_at`);

--
-- 表的索引 `blog_privacy_access`
--
ALTER TABLE `blog_privacy_access`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_post` (`user_id`,`post_id`),
  ADD KEY `idx_post_access` (`post_id`,`access_granted`);

--
-- 表的索引 `bot_blacklist`
--
ALTER TABLE `bot_blacklist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ip_address` (`ip_address`),
  ADD KEY `idx_ip` (`ip_address`);

--
-- 表的索引 `bot_whitelist_ip`
--
ALTER TABLE `bot_whitelist_ip`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ip_address` (`ip_address`),
  ADD KEY `idx_ip` (`ip_address`);

--
-- 表的索引 `bot_whitelist_ua`
--
ALTER TABLE `bot_whitelist_ua`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ua` (`ua_pattern`(191));

--
-- 表的索引 `captcha_sessions`
--
ALTER TABLE `captcha_sessions`
  ADD PRIMARY KEY (`token`),
  ADD KEY `idx_expire` (`expire_at`),
  ADD KEY `idx_ip` (`ip`,`created_at`);

--
-- 表的索引 `captcha_tokens`
--
ALTER TABLE `captcha_tokens`
  ADD PRIMARY KEY (`token`),
  ADD KEY `idx_expire` (`expire_at`);

--
-- 表的索引 `crawler_logs`
--
ALTER TABLE `crawler_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `visit_time` (`visit_time`),
  ADD KEY `crawler_name` (`crawler_name`),
  ADD KEY `status_code` (`status_code`);

--
-- 表的索引 `email_verification`
--
ALTER TABLE `email_verification`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email_purpose_unused` (`email`,`purpose`,`is_used`),
  ADD KEY `idx_email_expires` (`email`,`expires_at`);

--
-- 表的索引 `friend_links`
--
ALTER TABLE `friend_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sort_order` (`sort_order`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `idx_category` (`category_id`);

--
-- 表的索引 `friend_link_applications`
--
ALTER TABLE `friend_link_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- 表的索引 `friend_link_categories`
--
ALTER TABLE `friend_link_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- 表的索引 `guestbook`
--
ALTER TABLE `guestbook`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `hitokoto`
--
ALTER TABLE `hitokoto`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `honeypot_logs`
--
ALTER TABLE `honeypot_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip` (`ip_address`),
  ADD KEY `idx_time` (`triggered_at`);

--
-- 表的索引 `ip_whitelist`
--
ALTER TABLE `ip_whitelist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ip_address` (`ip_address`),
  ADD KEY `idx_ip` (`ip_address`);

--
-- 表的索引 `license_announcements`
--
ALTER TABLE `license_announcements`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `license_keys`
--
ALTER TABLE `license_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key_code` (`key_code`);

--
-- 表的索引 `license_verification_logs`
--
ALTER TABLE `license_verification_logs`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `license_version_updates`
--
ALTER TABLE `license_version_updates`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `my_projects`
--
ALTER TABLE `my_projects`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_is_read` (`is_read`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- 表的索引 `photos`
--
ALTER TABLE `photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_album_id` (`album_id`);

--
-- 表的索引 `photo_albums`
--
ALTER TABLE `photo_albums`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_identifier_action` (`identifier`,`action`),
  ADD KEY `idx_last_attempt` (`last_attempt`),
  ADD KEY `idx_blocked_until` (`blocked_until`);

--
-- 表的索引 `shuoshuo`
--
ALTER TABLE `shuoshuo`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `ua_whitelist`
--
ALTER TABLE `ua_whitelist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ua` (`ua_pattern`(191));

--
-- 表的索引 `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_token` (`device_token`),
  ADD KEY `idx_active` (`user_id`,`is_active`),
  ADD KEY `idx_expires` (`expires_at`),
  ADD KEY `idx_token_active` (`device_token`,`is_active`),
  ADD KEY `idx_user_active_status` (`user_id`,`is_active`,`status`),
  ADD KEY `idx_user_loginat` (`user_id`,`login_at`),
  ADD KEY `idx_status_loginat` (`status`,`login_at`),
  ADD KEY `idx_inactive_success` (`is_active`,`status`,`login_at`);

--
-- 表的索引 `visit_stats`
--
ALTER TABLE `visit_stats`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ip_address` (`ip_address`),
  ADD KEY `visit_time` (`visit_time`),
  ADD KEY `page_url` (`page_url`),
  ADD KEY `idx_country` (`country`),
  ADD KEY `idx_province` (`province`),
  ADD KEY `idx_city` (`city`),
  ADD KEY `idx_ip_geo` (`ip_address`,`country`,`province`,`city`),
  ADD KEY `idx_visit_time` (`visit_time`),
  ADD KEY `idx_ip` (`ip_address`);

--
-- 表的索引 `website_config`
--
ALTER TABLE `website_config`
  ADD PRIMARY KEY (`id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '管理员ID', AUTO_INCREMENT=3382;

--
-- 使用表AUTO_INCREMENT `appeals`
--
ALTER TABLE `appeals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `appeal_tokens`
--
ALTER TABLE `appeal_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `blog_categories`
--
ALTER TABLE `blog_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '分类ID', AUTO_INCREMENT=7;

--
-- 使用表AUTO_INCREMENT `blog_comments`
--
ALTER TABLE `blog_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '评论ID';

--
-- 使用表AUTO_INCREMENT `blog_paid_access`
--
ALTER TABLE `blog_paid_access`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `blog_paid_access_temporary`
--
ALTER TABLE `blog_paid_access_temporary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `blog_posts`
--
ALTER TABLE `blog_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '文章ID', AUTO_INCREMENT=2;

--
-- 使用表AUTO_INCREMENT `blog_privacy_access`
--
ALTER TABLE `blog_privacy_access`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '记录ID';

--
-- 使用表AUTO_INCREMENT `bot_blacklist`
--
ALTER TABLE `bot_blacklist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `bot_whitelist_ip`
--
ALTER TABLE `bot_whitelist_ip`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `bot_whitelist_ua`
--
ALTER TABLE `bot_whitelist_ua`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `crawler_logs`
--
ALTER TABLE `crawler_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `email_verification`
--
ALTER TABLE `email_verification`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '验证码ID';

--
-- 使用表AUTO_INCREMENT `friend_links`
--
ALTER TABLE `friend_links`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '链接ID';

--
-- 使用表AUTO_INCREMENT `friend_link_applications`
--
ALTER TABLE `friend_link_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '申请ID';

--
-- 使用表AUTO_INCREMENT `friend_link_categories`
--
ALTER TABLE `friend_link_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '分类ID', AUTO_INCREMENT=7;

--
-- 使用表AUTO_INCREMENT `guestbook`
--
ALTER TABLE `guestbook`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `hitokoto`
--
ALTER TABLE `hitokoto`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `honeypot_logs`
--
ALTER TABLE `honeypot_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `ip_whitelist`
--
ALTER TABLE `ip_whitelist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `license_announcements`
--
ALTER TABLE `license_announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `license_keys`
--
ALTER TABLE `license_keys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `license_verification_logs`
--
ALTER TABLE `license_verification_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `license_version_updates`
--
ALTER TABLE `license_version_updates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `my_projects`
--
ALTER TABLE `my_projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '通知ID';

--
-- 使用表AUTO_INCREMENT `photos`
--
ALTER TABLE `photos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `photo_albums`
--
ALTER TABLE `photo_albums`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 使用表AUTO_INCREMENT `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `shuoshuo`
--
ALTER TABLE `shuoshuo`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `ua_whitelist`
--
ALTER TABLE `ua_whitelist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `visit_stats`
--
ALTER TABLE `visit_stats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '记录ID', AUTO_INCREMENT=30;

--
-- 使用表AUTO_INCREMENT `website_config`
--
ALTER TABLE `website_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '配置ID', AUTO_INCREMENT=2;

--
-- 限制导出的表
--

--
-- 限制表 `friend_links`
--
ALTER TABLE `friend_links`
  ADD CONSTRAINT `fk_friend_links_category` FOREIGN KEY (`category_id`) REFERENCES `friend_link_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
