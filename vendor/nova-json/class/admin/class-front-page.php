<?php
/**
 * Nova JSON API: Nova_Front_Page
 *
 * 前台页面基类。插件可以通过继承此类快速创建独立前台页面，
 * 提供统一的页面标题、SEO 元信息、模板渲染等功能。
 *
 * 用法：
 *   class MyPage extends Nova_Front_Page {
 *       protected $slug = 'my-page';
 *       protected $title = '我的页面';
 *
 *       public function render() {
 *           $this->setTitle('我的页面');
 *           $this->setDescription('这是通过插件创建的页面');
 *           $this->content('<h1>Hello World</h1>');
 *       }
 *   }
 *   new MyPage();
 *
 *   // 在 blog.php 或其他入口文件中：
 *   if ($page = Nova_Front_Page::match($_SERVER['REQUEST_URI'])) {
 *       $page->renderPage();
 *   }
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_Front_Page {

    /** @var string 页面 slug（URL 路径） */
    protected $slug = '';

    /** @var string 页面标题 */
    protected $title = '';

    /** @var string 页面描述（SEO） */
    protected $description = '';

    /** @var string 页面关键词（SEO） */
    protected $keywords = '';

    /** @var string 页面模板文件路径 */
    protected $template = '';

    /** @var array 页面数据 */
    protected $data = [];

    /** @var array 注册的所有页面实例 */
    protected static $pages = [];

    /** @var string 页面布局文件 */
    protected $layout = '';

    /** @var string 自定义 CSS */
    protected $extraCss = '';

    /** @var string 自定义 JS */
    protected $extraJs = '';

    /**
     * 构造函数：注册页面
     */
    public function __construct() {
        if (empty($this->slug)) {
            $this->slug = sanitize_title($this->title);
        }

        self::$pages[$this->slug] = $this;

        // 注册路由钩子
        Nova_Hooks::add_action('nova_front_page_' . $this->slug, [$this, 'renderPage']);
    }

    /**
     * 根据 URL 匹配已注册的页面
     *
     * @param string $uri 请求 URI
     * @return Nova_Front_Page|null
     */
    public static function match($uri) {
        $path = parse_url($uri, PHP_URL_PATH);
        $path = trim($path, '/');

        foreach (self::$pages as $slug => $page) {
            // 精确匹配 /slug
            if ($path === $slug || $path === 'page/' . $slug) {
                return $page;
            }
        }

        // 模糊匹配（支持 /slug/xxx 参数）
        foreach (self::$pages as $slug => $page) {
            if (strpos($path, $slug) === 0) {
                $remainder = substr($path, strlen($slug));
                if ($remainder === '' || $remainder[0] === '/') {
                    return $page;
                }
            }
        }

        return null;
    }

    /**
     * 获取所有已注册的页面
     * @return self[]
     */
    public static function getPages() {
        return self::$pages;
    }

    /**
     * 根据 slug 获取页面实例
     * @param string $slug
     * @return self|null
     */
    public static function get($slug) {
        return self::$pages[$slug] ?? null;
    }

    /**
     * 设置页面标题
     * @param string $title
     * @return $this
     */
    public function setTitle($title) {
        $this->title = $title;
        return $this;
    }

    /**
     * 设置页面描述
     * @param string $description
     * @return $this
     */
    public function setDescription($description) {
        $this->description = $description;
        return $this;
    }

    /**
     * 设置页面关键词
     * @param string $keywords
     * @return $this
     */
    public function setKeywords($keywords) {
        $this->keywords = $keywords;
        return $this;
    }

    /**
     * 设置页面模板
     * @param string $templatePath 模板文件绝对路径
     * @return $this
     */
    public function setTemplate($templatePath) {
        $this->template = $templatePath;
        return $this;
    }

    /**
     * 传递数据到模板
     * @param string|array $key   键名或数据数组
     * @param mixed        $value 值
     * @return $this
     */
    public function with($key, $value = null) {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }
        return $this;
    }

    /**
     * 输出页面内容（子类应重写此方法）
     */
    public function render() {
        // 子类重写
        echo '<h1>' . e($this->title) . '</h1>';
    }

    /**
     * 输出页面内容（快捷方法）
     * @param string $content HTML 内容
     */
    protected function content($content) {
        echo $content;
    }

    /**
     * 获取页面数据
     * @param string $key     键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function getData($key = null, $default = null) {
        if ($key === null) {
            return $this->data;
        }
        return $this->data[$key] ?? $default;
    }

    /**
     * 获取页面标题
     * @return string
     */
    public function getTitle() {
        return $this->title;
    }

    /**
     * 获取页面描述
     * @return string
     */
    public function getDescription() {
        return $this->description;
    }

    /**
     * 获取页面 slug
     * @return string
     */
    public function getSlug() {
        return $this->slug;
    }

    /**
     * 渲染页面（供外部调用）
     * 会根据模板设置自动选择模板渲染或直接输出
     */
    public function renderPage() {
        // 输出 SEO 元信息头
        if ($this->title) {
            echo '<title>' . e($this->title) . '</title>' . "\n";
        }
        if ($this->description) {
            echo '<meta name="description" content="' . e($this->description) . '">' . "\n";
        }
        if ($this->keywords) {
            echo '<meta name="keywords" content="' . e($this->keywords) . '">' . "\n";
        }

        // 如果有模板文件，使用模板渲染
        if ($this->template && file_exists($this->template)) {
            extract($this->data);
            require $this->template;
            return;
        }

        // 否则调用 render() 方法
        $this->render();
    }

    /**
     * 包含头部文件
     * @param string $headerPath 头部文件路径
     */
    protected function includeHeader($headerPath = '') {
        if ($headerPath && file_exists($headerPath)) {
            require $headerPath;
        }
    }

    /**
     * 包含尾部文件
     * @param string $footerPath 尾部文件路径
     */
    protected function includeFooter($footerPath = '') {
        if ($footerPath && file_exists($footerPath)) {
            require $footerPath;
        }
    }

    /**
     * 添加自定义 CSS
     * @param string $css
     * @return $this
     */
    public function addCss($css) {
        $this->extraCss .= "\n" . $css;
        return $this;
    }

    /**
     * 添加自定义 JS
     * @param string $js
     * @return $this
     */
    public function addJs($js) {
        $this->extraJs .= "\n" . $js;
        return $this;
    }

    /**
     * 重置所有注册的页面
     */
    public static function reset() {
        self::$pages = [];
    }
}
