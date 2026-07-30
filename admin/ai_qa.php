<?php
require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../config/content_module_functions.php';

ensureContentModuleTables($db);

$resourceConfig = [
    'table' => 'cms_ai_qa',
    'title' => '智能问答知识库',
    'singular' => '问答',
    'icon' => 'bi-stars',
    'eyebrow' => '内容 / 智能问答',
    'description' => '维护常见问题、标准答案与匹配关键词，为站内助手提供可控知识来源。',
    'primary_field' => 'question',
    'search_fields' => ['question', 'answer', 'keywords', 'category'],
    'status_field' => 'status',
    'statuses' => [
        'active' => ['label' => '已启用', 'tone' => 'success', 'icon' => 'bi-check-circle'],
        'draft' => ['label' => '草稿', 'tone' => 'warning', 'icon' => 'bi-pencil-square'],
    ],
    'toggle_map' => ['active' => 'draft', 'draft' => 'active'],
    'default_sort' => 'sort_order ASC, updated_at DESC',
    'search_placeholder' => '搜索问题、答案或关键词…',
    'fields' => [
        'question' => [
            'label' => '用户问题',
            'type' => 'text',
            'required' => true,
            'maxlength' => 500,
            'placeholder' => '例如：如何重置登录密码？',
        ],
        'answer' => [
            'label' => '标准答案',
            'type' => 'markdown',
            'required' => true,
            'maxlength' => 20000,
            'placeholder' => '输入助手应返回的准确答案。',
            'help' => '答案来自管理员维护的知识库，不会自动向外部服务发送站点数据。',
        ],
        'keywords' => [
            'label' => '匹配关键词',
            'type' => 'text',
            'maxlength' => 1000,
            'placeholder' => '密码, 重置密码, 忘记密码',
            'help' => '用逗号分隔同义词和常用说法，可提升问题命中率。',
        ],
        'category' => [
            'label' => '问答分类',
            'type' => 'text',
            'maxlength' => 100,
            'placeholder' => '例如：账号与安全',
        ],
        'status' => [
            'label' => '知识状态',
            'type' => 'select',
            'side' => true,
            'default' => 'draft',
            'options' => [
                'draft' => '保存为草稿',
                'active' => '启用问答',
            ],
        ],
        'sort_order' => [
            'label' => '匹配优先级',
            'type' => 'number',
            'side' => true,
            'default' => 0,
            'min' => 0,
            'max' => 9999,
            'help' => '数字越小，在相近匹配中越优先。',
        ],
    ],
    'list_columns' => [
        ['key' => 'question', 'label' => '问题', 'type' => 'primary', 'subtitle' => 'category'],
        ['key' => 'keywords', 'label' => '关键词', 'length' => 42],
        ['key' => 'hit_count', 'label' => '命中', 'type' => 'number'],
        ['key' => 'status', 'label' => '状态', 'type' => 'status'],
        ['key' => 'updated_at', 'label' => '最后更新', 'type' => 'datetime'],
    ],
];

require __DIR__ . '/includes/content-resource-manager.php';
