<?php
require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../config/content_module_functions.php';

ensureContentModuleTables($db);

$resourceConfig = [
    'table' => 'cms_documents',
    'title' => '文档',
    'singular' => '文档',
    'icon' => 'bi-journal-text',
    'eyebrow' => '内容 / 文档',
    'description' => '整理帮助中心、使用指南和可下载资料，集中维护正文与附件地址。',
    'primary_field' => 'title',
    'search_fields' => ['title', 'slug', 'category', 'summary', 'content'],
    'status_field' => 'status',
    'statuses' => [
        'published' => ['label' => '已发布', 'tone' => 'success', 'icon' => 'bi-check-circle'],
        'draft' => ['label' => '草稿', 'tone' => 'warning', 'icon' => 'bi-pencil-square'],
    ],
    'toggle_map' => ['published' => 'draft', 'draft' => 'published'],
    'unique_fields' => ['slug'],
    'public_url' => '/docs/{slug}',
    'public_status' => 'published',
    'default_sort' => 'sort_order ASC, updated_at DESC',
    'search_placeholder' => '搜索文档标题、分类或内容…',
    'fields' => [
        'title' => [
            'label' => '文档标题',
            'type' => 'text',
            'required' => true,
            'maxlength' => 200,
            'placeholder' => '例如：快速开始',
        ],
        'slug' => [
            'label' => '文档地址',
            'type' => 'slug',
            'source' => 'title',
            'required' => true,
            'maxlength' => 190,
            'placeholder' => '留空将根据标题自动生成',
            'help' => '发布后访问地址为 /docs/文档地址。',
        ],
        'category' => [
            'label' => '文档分类',
            'type' => 'text',
            'maxlength' => 100,
            'placeholder' => '例如：入门指南',
        ],
        'summary' => [
            'label' => '文档摘要',
            'type' => 'textarea',
            'rows' => 3,
            'maxlength' => 500,
            'placeholder' => '说明这份文档能帮助读者解决什么问题。',
        ],
        'content' => [
            'label' => '文档正文',
            'type' => 'markdown',
            'required' => true,
            'placeholder' => '输入文档内容，支持 Markdown 书写。',
        ],
        'file_url' => [
            'label' => '附件地址',
            'type' => 'url',
            'maxlength' => 500,
            'placeholder' => '/uploads/files/example.pdf',
            'help' => '可填写附件管理中的站内路径，或完整的外部下载地址。',
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
        'is_featured' => [
            'label' => '推荐文档',
            'type' => 'checkbox',
            'side' => true,
            'default' => 0,
            'switch_label' => '在文档中心优先展示',
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
        ['key' => 'title', 'label' => '文档', 'type' => 'primary', 'subtitle' => 'slug'],
        ['key' => 'category', 'label' => '分类'],
        ['key' => 'is_featured', 'label' => '推荐', 'type' => 'boolean'],
        ['key' => 'download_count', 'label' => '下载', 'type' => 'number'],
        ['key' => 'status', 'label' => '状态', 'type' => 'status'],
        ['key' => 'updated_at', 'label' => '最后更新', 'type' => 'datetime'],
    ],
];

require __DIR__ . '/includes/content-resource-manager.php';
