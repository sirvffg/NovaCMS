<?php
/**
 * 内容模块公共路由。
 *
 * GET  /v1/content/pages
 * GET  /v1/content/pages/{slug}
 * POST /v1/content/subscribe
 */

register_rest_route('v1', '/content/pages', [
    'methods'  => 'GET',
    'callback' => 'nova_content_api_pages',
]);

register_rest_route('v1', '/content/pages/{slug}', [
    'methods'  => 'GET',
    'callback' => 'nova_content_api_page',
]);

register_rest_route('v1', '/content/subscribe', [
    'methods'  => 'POST',
    'callback' => 'nova_content_api_subscribe',
]);

function nova_content_api_response($data, $status = 200, array $headers = []) {
    $response = new Nova_REST_Response($data, $status);
    foreach ($headers as $name => $value) {
        $response->set_header($name, $value);
    }
    return $response;
}

function nova_content_api_success($data, $message = '获取成功', $status = 200) {
    return nova_content_api_response([
        'code'    => 'rest_ok',
        'message' => $message,
        'data'    => array_merge(['status' => $status], $data),
    ], $status);
}

function nova_content_api_error($code, $message, $status, array $headers = []) {
    return nova_content_api_response([
        'code'    => $code,
        'message' => $message,
        'data'    => ['status' => $status],
    ], $status, $headers);
}

function nova_content_api_internal_error($exception, $context) {
    error_log('Content API ' . $context . ' failed: ' . $exception->getMessage());
    return nova_content_api_error(
        'rest_content_error',
        '请求处理失败，请稍后重试',
        500
    );
}

function nova_content_api_boolean($value, $default = false) {
    if ($value === null || $value === '') {
        return $default;
    }
    if (!is_scalar($value)) {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed === null ? $default : $parsed;
}

function nova_content_api_pages($request) {
    try {
        $navOnly = nova_content_api_boolean($request->get_param('nav'), false);
        $limit = $request->get_param('limit') ?? 100;
        $items = contentModuleListPublishedPages($navOnly, $limit);

        return nova_content_api_success([
            'items'          => $items,
            'total'          => contentModuleCountPublishedPages($navOnly),
            'returned_count' => count($items),
        ]);
    } catch (Throwable $e) {
        return nova_content_api_internal_error($e, 'page list');
    }
}

function nova_content_api_page($request) {
    try {
        $item = contentModuleGetPublishedPageBySlug($request->get_param('slug'));
        if (!$item) {
            return nova_content_api_error('rest_not_found', '页面不存在', 404);
        }
        return nova_content_api_success(['item' => $item]);
    } catch (Throwable $e) {
        return nova_content_api_internal_error($e, 'page detail');
    }
}

function nova_content_api_subscribe($request) {
    $emailParam = $request->get_param('email');
    $nameParam = $request->get_param('name');
    $sourceParam = $request->get_param('source');
    if (!is_scalar($emailParam) || ($nameParam !== null && !is_scalar($nameParam)) || ($sourceParam !== null && !is_scalar($sourceParam))) {
        return nova_content_api_error('rest_invalid_request', '提交内容格式有误', 400);
    }
    $email = contentModuleLowercase(trim((string)$emailParam));
    $name = trim((string)($nameParam ?? ''));
    $source = trim((string)($sourceParam ?? 'website'));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
        return nova_content_api_error('rest_invalid_email', '请输入有效的邮箱地址', 400);
    }
    if (contentModuleTextLength($name) > 100) {
        return nova_content_api_error('rest_invalid_name', '称呼不能超过 100 个字符', 400);
    }
    // 与持久化层使用同一兼容规则，并在消耗限流配额前完成规范化。
    if (!preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $source)) {
        $source = 'website';
    }

    $rateLimit = checkRateLimit('content_subscribe', 5, 3600);
    if (!$rateLimit['allowed']) {
        return nova_content_api_error(
            'rest_rate_limited',
            '提交过于频繁，请稍后再试',
            429,
            ['Retry-After' => (string)max(1, (int)$rateLimit['retryAfter'])]
        );
    }

    $emailRateLimit = checkRateLimit(
        'content_subscriber_email',
        3,
        86400,
        hash('sha256', $email)
    );
    if (!$emailRateLimit['allowed']) {
        return nova_content_api_error(
            'rest_rate_limited',
            '提交过于频繁，请稍后再试',
            429,
            ['Retry-After' => (string)max(1, (int)$emailRateLimit['retryAfter'])]
        );
    }

    try {
        contentModuleSubscribe($email, $name, $source);
        return nova_content_api_success([], '订阅成功', 201);
    } catch (InvalidArgumentException $e) {
        return nova_content_api_error('rest_invalid_request', '提交内容格式有误', 400);
    } catch (Throwable $e) {
        return nova_content_api_internal_error($e, 'subscription');
    }
}
