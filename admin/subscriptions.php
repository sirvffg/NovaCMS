<?php
require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../config/content_module_functions.php';

ensureContentModuleTables($db);

$resourceConfig = [
    'table' => 'cms_subscribers',
    'title' => '订阅',
    'singular' => '订阅者',
    'icon' => 'bi-rss',
    'eyebrow' => '内容 / 订阅',
    'description' => '管理通过网站订阅接口收集的读者，记录来源、状态与运营备注。',
    'primary_field' => 'email',
    'search_fields' => ['email', 'name', 'source', 'notes'],
    'status_field' => 'status',
    'statuses' => [
        'active' => ['label' => '订阅中', 'tone' => 'success', 'icon' => 'bi-check-circle'],
        'unsubscribed' => ['label' => '已退订', 'tone' => 'secondary', 'icon' => 'bi-slash-circle'],
        'bounced' => ['label' => '地址异常', 'tone' => 'danger', 'icon' => 'bi-exclamation-circle'],
    ],
    'toggle_map' => ['active' => 'unsubscribed', 'unsubscribed' => 'active', 'bounced' => 'active'],
    'status_timestamp' => ['field' => 'unsubscribed_at', 'status' => 'unsubscribed'],
    'unique_fields' => ['email'],
    'default_sort' => 'updated_at DESC',
    'search_placeholder' => '搜索邮箱、姓名或来源…',
    'fields' => [
        'email' => [
            'label' => '邮箱地址',
            'type' => 'email',
            'required' => true,
            'maxlength' => 190,
            'placeholder' => 'reader@example.com',
        ],
        'name' => [
            'label' => '订阅者姓名',
            'type' => 'text',
            'maxlength' => 100,
            'placeholder' => '可选，用于个性化称呼',
        ],
        'notes' => [
            'label' => '运营备注',
            'type' => 'textarea',
            'rows' => 5,
            'maxlength' => 1000,
            'placeholder' => '记录读者偏好或需要跟进的信息，仅管理员可见。',
        ],
        'status' => [
            'label' => '订阅状态',
            'type' => 'select',
            'side' => true,
            'default' => 'active',
            'options' => [
                'active' => '订阅中',
                'unsubscribed' => '已退订',
                'bounced' => '地址异常',
            ],
        ],
        'source' => [
            'label' => '订阅来源',
            'type' => 'select',
            'side' => true,
            'default' => 'manual',
            'options' => [
                'manual' => '后台添加',
                'website' => '网站表单',
                'import' => '批量导入',
            ],
        ],
    ],
    'list_columns' => [
        ['key' => 'email', 'label' => '订阅者', 'type' => 'primary', 'subtitle' => 'name'],
        ['key' => 'source', 'label' => '来源'],
        ['key' => 'status', 'label' => '状态', 'type' => 'status'],
        ['key' => 'created_at', 'label' => '加入时间', 'type' => 'datetime'],
        ['key' => 'updated_at', 'label' => '最后更新', 'type' => 'datetime'],
    ],
];

require __DIR__ . '/includes/content-resource-manager.php';
