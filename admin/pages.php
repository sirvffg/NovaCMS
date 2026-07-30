<?php
require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../config/content_module_functions.php';

ensureContentModuleTables($db);

$resourceConfig = [
    'table' => 'cms_pages',
    'title' => '页面',
    'singular' => '页面',
    'icon' => 'bi-layout-text-window',
    'eyebrow' => '内容 / 页面',
    'description' => '创建关于我们、服务说明、专题介绍等独立页面，并通过固定链接发布。',
    'primary_field' => 'title',
    'search_fields' => ['title', 'slug', 'summary', 'content'],
    'status_field' => 'status',
    'statuses' => [
        'published' => ['label' => '已发布', 'tone' => 'success', 'icon' => 'bi-check-circle'],
        'draft' => ['label' => '草稿', 'tone' => 'warning', 'icon' => 'bi-pencil-square'],
    ],
    'toggle_map' => ['published' => 'draft', 'draft' => 'published'],
    'unique_fields' => ['slug'],
    'public_url' => '/page/{slug}',
    'public_status' => 'published',
    'default_sort' => 'sort_order ASC, updated_at DESC',
    'search_placeholder' => '搜索页面标题、摘要或正文…',
    'fields' => [
        'title' => [
            'label' => '页面标题',
            'type' => 'text',
            'required' => true,
            'maxlength' => 200,
            'placeholder' => '例如：关于我们',
        ],
        'slug' => [
            'label' => '固定链接',
            'type' => 'slug',
            'source' => 'title',
            'required' => true,
            'maxlength' => 190,
            'placeholder' => '留空将根据标题自动生成',
            'help' => '发布后访问地址为 /page/固定链接。',
        ],
        'summary' => [
            'label' => '页面摘要',
            'type' => 'textarea',
            'rows' => 3,
            'maxlength' => 500,
            'placeholder' => '用一两句话概括页面内容，便于搜索与分享。',
        ],
        'content' => [
            'label' => '页面正文',
            'type' => 'markdown',
            'required' => true,
            'placeholder' => '输入页面正文，支持 Markdown 书写。',
            'help' => '内容将作为独立页面展示；发布前可先保存为草稿。',
        ],
        'template' => [
            'label' => '页面模板',
            'type' => 'select',
            'side' => true,
            'default' => 'default',
            'options' => [
                'default' => '默认页面',
                'wide' => '宽版页面',
                'landing' => '落地页',
            ],
        ],
        'status' => [
            'label' => '发布状态',
            'type' => 'select',
            'side' => true,
            'default' => 'draft',
            'options' => [
                'draft' => '保存为草稿',
                'published' => '立即发布',
            ],
        ],
        'show_in_nav' => [
            'label' => '导航展示',
            'type' => 'checkbox',
            'side' => true,
            'default' => 0,
            'switch_label' => '允许主题导航读取此页面',
        ],
        'sort_order' => [
            'label' => '排序',
            'type' => 'number',
            'side' => true,
            'default' => 0,
            'min' => 0,
            'max' => 9999,
            'help' => '数字越小越靠前。',
        ],
    ],
    'list_columns' => [
        ['key' => 'title', 'label' => '页面', 'type' => 'primary', 'subtitle' => 'slug'],
        ['key' => 'template', 'label' => '模板'],
        ['key' => 'show_in_nav', 'label' => '导航', 'type' => 'boolean', 'yes' => '显示', 'no' => '隐藏'],
        ['key' => 'status', 'label' => '状态', 'type' => 'status'],
        ['key' => 'updated_at', 'label' => '最后更新', 'type' => 'datetime'],
    ],
];

require __DIR__ . '/includes/content-resource-manager.php';
