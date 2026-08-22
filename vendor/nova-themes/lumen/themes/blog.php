<?php
// lumen 作为 default 的子主题，直接复用 default 的文章模板（含评论组件）
// 主题路径/资源 URL 仍指向 lumen 自身，CSS 通过 @import、JS 通过 stub 复用 default
require dirname(__DIR__, 2) . '/default/themes/blog.php';
