<?php
/**
 * Nova JSON API - WordPress REST API 风格接口
 *
 * 用法: /vendor/nova-json/?route=/v1/posts
 *       /vendor/nova-json/?route=/v1/posts/1
 *       /vendor/nova-json/?route=/v1/posts&per_page=5&page=1
 *       /vendor/nova-json/?route=/v1/links
 *       /vendor/nova-json/?route=/v1/auth/login
 *
 * 完整端点列表:
 *   GET    /v1/posts                       文章列表
 *   GET    /v1/posts/{id}                  文章详情
 *   GET    /v1/posts/slug/{slug}           按标识查文章
 *   GET    /v1/posts/{id}/download         下载文章 ZIP
 *   POST   /v1/posts/{id}/download/send-code 发送下载验证码
 *   GET    /v1/categories                  分类列表
 *   POST   /v1/categories                  创建分类
 *   PUT    /v1/categories/{id}             更新分类
 *   DELETE /v1/categories/{id}             删除分类
 *   GET    /v1/tags                        标签列表
 *   GET    /v1/search                      搜索文章
 *   GET    /v1/comments                    评论列表
 *   GET    /v1/comments/{id}               评论详情
 *   POST   /v1/comments                    添加评论
 *   DELETE /v1/comments/{id}               删除评论
 *   POST   /v1/posts/privacy               隐私内容验证
 *   POST   /v1/posts/paid                  付费内容检查
 *   GET    /v1/links                       友链列表
 *   GET    /v1/links/categories            友链分类
 *   POST   /v1/links/apply                 提交友链申请
 *   GET    /v1/links/siteinfo              本站信息
 *   POST   /v1/auth/login                  登录（第一步）
 *   POST   /v1/auth/verify                 验证码登录（第二步）
 *   GET    /v1/auth/me                     获取当前用户
 *   POST   /v1/auth/logout                 退出登录
 *   GET    /v1/public/hitokoto             一言/简介
 *   GET    /v1/statuses/settings           站点配置
 *   GET    /v1/statuses/shuoshuo           说说列表
 *   POST   /v1/statuses/shuoshuo           发布说说
 *   DELETE /v1/statuses/shuoshuo/{id}      删除说说
 *   GET    /v1/statuses/gallery/albums          相册列表
 *   GET    /v1/statuses/gallery/albums/{id}     相册详情（含照片）
 *   POST   /v1/statuses/gallery/albums          创建相册
 *   PUT    /v1/statuses/gallery/albums/{id}     更新相册
 *   DELETE /v1/statuses/gallery/albums/{id}     删除相册
 *   GET    /v1/statuses/gallery/photos          照片列表
 *   GET    /v1/statuses/gallery/photos/{id}     照片详情
 *   POST   /v1/statuses/gallery/photos          上传照片
 *   PUT    /v1/statuses/gallery/photos/{id}     更新照片信息
 *   DELETE /v1/statuses/gallery/photos/{id}     删除照片
 *   GET    /v1/statuses/guestbook               留言列表（含回复）
 *   POST   /v1/statuses/guestbook               提交留言或回复（公开）
 *   PUT    /v1/statuses/guestbook/{id}/reply    管理员回复留言
 *   DELETE /v1/statuses/guestbook/{id}          删除留言
 *   GET    /v1/statuses/terms                   服务条款与隐私政策
 *   GET    /v1/proxy                       代理请求
 *
 * 类文件:
 *   class/rest/class-request.php    Nova_REST_Request   请求封装
 *   class/rest/class-response.php   Nova_REST_Response  响应封装
 *   class/rest/class-server.php     Nova_REST_Server    路由引擎
 *   class/database/class-db.php     Nova_DB             数据库读写
 *   class/system/class-hooks.php    Nova_Hooks          钩子系统
 *   class/system/class-api.php      Nova_API            内部 API 调用
 *   class/plugin/class-plugin.php   Nova_Plugin         插件基类
 *   class/theme/class-theme.php     Nova_Theme          主题基类
 *
 * 插件位于: vendor/nova-plugins/{插件名}/plugin.php
 * 主题位于: vendor/nova-themes/{主题名}/theme.php
 *
 * 若此路径返回 404，请改用根目录入口:
 *   /nova-json.php?route=/v1/posts
 */

define('NOVA_API', true);

require_once __DIR__ . '/init.php';
