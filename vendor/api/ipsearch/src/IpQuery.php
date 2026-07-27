<?php
/**
 * IP 混合查询：国内用 GeoCN + 行政区划，国外用纯真
 * 完全对应 index.js 的查询逻辑
 */

use MaxMind\Db\Reader as GeoIp2Reader;
use ipip\db\City as IpdbReader;

class IpQuery
{
    // 数据库文件路径
    private string $qqwryPath;
    private string $geocnPath;
    private string $divisionFullPath;
    private string $divisionShortPath;
    private string $configPath;

    // 数据库实例缓存
    private ?IpdbReader $qqwryDb = null;
    private ?GeoIp2Reader $geocnDb = null;
    private int $qqwryLoadTime = 0;
    private int $geocnLoadTime = 0;
    private int $cacheTTL = 86400; // 24小时

    // 运行时配置缓存
    private ?array $appConfig = null;
    private int $configLoadTime = 0;
    private int $configCacheTTL = 300; // 5分钟

    // 行政区划数据缓存
    private ?array $divisionFull = null;   // [code => name]
    private ?array $divisionShort = null;  // [code => name]
    private int $divisionLoadTime = 0;

    public function __construct(
        string $dataDir = __DIR__ . '/../data',
        string $configPath = __DIR__ . '/../config.json'
    ) {
        $this->qqwryPath = $dataDir . '/qqwry.ipdb';
        $this->geocnPath = $dataDir . '/GeoCN.mmdb';
        $this->divisionFullPath = $dataDir . '/full.txt';
        $this->divisionShortPath = $dataDir . '/short.txt';
        $this->configPath = $configPath;
    }

    // ==================== 核心查询 ====================

    /**
     * 查询 IP 地理位置
     * @return array 与 index.js 完全一致的返回格式
     */
    public function query(string $targetIp): array
    {
        // 加载配置，提前检查 API 模式
        $config = $this->loadConfig();

        // API 转发模式：不加载本地数据库，直接转发到远程 API
        if ($config['mode'] === 'api') {
            return $this->queryByApi($targetIp, $config);
        }

        // 1. 加载纯真数据库并查询
        $qqwry = $this->loadQQwryDatabase();
        if (!$qqwry) {
            return [
                'success' => false,
                'error'   => '纯真数据库加载失败',
                'ip'      => $targetIp,
            ];
        }

        $qqwryResult = $qqwry->find($targetIp, 'CN');
        $isChinese = false;
        $qqwryData = null;

        // ipip\db\City::find() 返回数字索引数组:
        // [0]国家 [1]省份 [2]城市 [3]区县 [4]? [5]运营商 [6]国家代码 [7]?
        if (is_array($qqwryResult) && !empty($qqwryResult)) {
            $qqwryData = [
                'country'      => $qqwryResult[0] ?: '未知',
                'country_code' => $qqwryResult[6] ?? '',
                'region'       => $qqwryResult[1] ?? '',
                'city'         => $qqwryResult[2] ?? '',
                'isp'          => $qqwryResult[5] ?? '',
            ];
            $isChinese = $this->isChineseIP($qqwryData['country_code'], $qqwryData['country']);
        }

        // 2. 根据模式选择数据源
        $finalData = null;
        $dataSource = '';

        if ($config['mode'] === 'qqwry_only') {
            // 模式 1：仅纯真
            $finalData = $qqwryData
                ? [
                    'country'  => $this->fixCountryName($qqwryData['country']),
                    'province' => $qqwryData['region'],
                    'city'     => $qqwryData['city'],
                    'isp'      => $qqwryData['isp'],
                ]
                : [
                    'country'  => '未知',
                    'province' => '',
                    'city'     => '',
                    'isp'      => '',
                ];
            $dataSource = $qqwryData ? '纯真数据库 (仅纯真模式)' : '无可用数据';

        } elseif ($isChinese) {
            // 模式 2：纯真 + GeoCN，国内 IP 用 GeoCN 高精度
            $divisionOk = $this->loadDivisionData();
            $geocn = $this->loadGeoCNDatabase();

            if ($geocn) {
                try {
                    $geocnResult = $geocn->get($targetIp);
                    if ($geocnResult) {
                        $rawCode = $geocnResult['division_code'] ?? null;
                        if ($rawCode !== null) {
                            $rawCode = intval($rawCode);
                        }
                        $division = $this->resolveDivision($rawCode);

                        $province = $qqwryData['region'] ?? '';
                        $city     = $qqwryData['city'] ?? '';
                        $district = '';
                        if ($division && !empty($division['short'])) {
                            $province = $division['short'][0] ?? $province;
                            $city     = $division['short'][1] ?? $city;
                            $district = $division['short'][2] ?? '';
                        }

                        $finalData = [
                            'country'  => '中国',
                            'province' => $province,
                            'city'     => $city,
                            'district' => $district,
                            'isp'      => $geocnResult['isp'] ?? $qqwryData['isp'] ?? '',
                        ];
                        $dataSource = $divisionOk
                            ? 'GeoCN + 行政区划'
                            : 'GeoCN (行政区划数据未加载)';
                    } else {
                        // GeoCN 未找到
                        $finalData = [
                            'country'  => $this->fixCountryName($qqwryData['country'] ?? '中国'),
                            'province' => $qqwryData['region'] ?? '',
                            'city'     => $qqwryData['city'] ?? '',
                            'isp'      => $qqwryData['isp'] ?? '',
                        ];
                        $dataSource = '纯真数据库 (GeoCN 未找到该 IP)';
                    }
                } catch (\Exception $e) {
                    $finalData = [
                        'country'  => $this->fixCountryName($qqwryData['country'] ?? '中国'),
                        'province' => $qqwryData['region'] ?? '',
                        'city'     => $qqwryData['city'] ?? '',
                        'isp'      => $qqwryData['isp'] ?? '',
                    ];
                    $dataSource = '纯真数据库 (GeoCN 查询异常: ' . $e->getMessage() . ')';
                }
            } else {
                $finalData = [
                    'country'  => $this->fixCountryName($qqwryData['country'] ?? '中国'),
                    'province' => $qqwryData['region'] ?? '',
                    'city'     => $qqwryData['city'] ?? '',
                    'isp'      => $qqwryData['isp'] ?? '',
                ];
                $dataSource = '纯真数据库 (GeoCN 未加载)';
            }
        } else {
            // 国外 IP：使用纯真
            $finalData = $qqwryData
                ? [
                    'country'  => $qqwryData['country'],
                    'province' => $qqwryData['region'],
                    'city'     => $qqwryData['city'],
                    'isp'      => $qqwryData['isp'],
                ]
                : [
                    'country'  => '未知',
                    'province' => '',
                    'city'     => '',
                    'isp'      => '',
                ];
            $dataSource = $qqwryData ? '纯真数据库 (国际版)' : '无可用数据';
        }

        // 3. 构建地理位置全路径
        $locationParts = array_filter([
            $finalData['country']  ?? '',
            $finalData['province'] ?? '',
            $finalData['city']     ?? '',
            $finalData['district'] ?? '',
        ]);
        $fullLocation = !empty($locationParts) ? implode('–', $locationParts) : '未知';

        // 4. 构建 location 对象
        $location = [];
        foreach (['country', 'province', 'city', 'district', 'isp'] as $key) {
            if (!empty($finalData[$key])) {
                $location[$key] = $finalData[$key];
            }
        }

        return [
            'success'       => true,
            'ip'            => $targetIp,
            'full_location' => $fullLocation,
            'location'      => $location,
            'meta'          => [
                'mode'          => $config['mode'],
                'data_source'   => $dataSource,
                'is_chinese_ip' => $isChinese,
                'timestamp'     => date('c'),
            ],
        ];
    }

    // ==================== API 转发模式 ====================

    /**
     * API 转发查询：不加载本地数据库，直接调用远程 API
     */
    private function queryByApi(string $targetIp, array $config): array
    {
        $apiUrl = rtrim($config['api_url'] ?? '', '/');
        if (empty($apiUrl)) {
            return [
                'success' => false,
                'error'   => 'config.json 中未配置 api_url',
                'ip'      => $targetIp,
            ];
        }

        $queryUrl = $apiUrl . '?ip=' . urlencode($targetIp);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $queryUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlError) {
            return [
                'success' => false,
                'error'   => 'API 请求失败: ' . ($curlError ?: '未知错误'),
                'ip'      => $targetIp,
            ];
        }

        $result = json_decode($body, true);
        if (!is_array($result)) {
            return [
                'success' => false,
                'error'   => 'API 返回格式错误 (HTTP ' . $httpCode . ')',
                'ip'      => $targetIp,
            ];
        }

        // 透传 API 结果，补充 meta
        $result['meta'] = array_merge($result['meta'] ?? [], [
            'mode'        => 'api',
            'data_source' => '远程 API 转发',
            'api_url'     => $queryUrl,
            'http_code'   => $httpCode,
            'timestamp'   => date('c'),
        ]);

        return $result;
    }

    // ==================== 健康检查 ====================

    /**
     * 健康检查端点数据
     */
    public function healthCheck(): array
    {
        $config = $this->loadConfig();

        $geocnMeta = null;
        if ($this->geocnDb) {
            $geocnMeta = [
                'database_type' => 'GeoIP2-City',
                'ip_version'    => 4,
            ];
        }

        $divisionStatus = [
            'loaded'      => !is_null($this->divisionFull) && !is_null($this->divisionShort),
            'full_count'  => is_array($this->divisionFull) ? count($this->divisionFull) : 0,
            'short_count' => is_array($this->divisionShort) ? count($this->divisionShort) : 0,
        ];

        $now = time();

        return [
            'status'         => 'ok',
            'mode'           => $config['mode'],
            'qqwry_loaded'   => !is_null($this->qqwryDb),
            'geocn_loaded'   => !is_null($this->geocnDb),
            'geocn_metadata' => $geocnMeta,
            'division'       => $divisionStatus,
            'caches'         => [
                'qqwry_age_ms'    => $this->qqwryDb ? ($now - $this->qqwryLoadTime) * 1000 : null,
                'geocn_age_ms'    => $this->geocnDb ? ($now - $this->geocnLoadTime) * 1000 : null,
                'division_age_ms' => $this->divisionFull ? ($now - $this->divisionLoadTime) * 1000 : null,
            ],
            'timestamp'      => date('c'),
        ];
    }

    // ==================== 辅助判断函数 ====================

    /**
     * 判断是否为国内 IP
     */
    private function isChineseIP(string $countryCode, string $countryName): bool
    {
        if (in_array(strtoupper($countryCode), ['CN'])) {
            return true;
        }
        if (in_array($countryName, ['中国', 'China'])) {
            return true;
        }
        $chineseRegions = ['台湾', '香港', '澳门', 'Taiwan', 'Hong Kong', 'Macau'];
        foreach ($chineseRegions as $region) {
            if (mb_strpos($countryName, $region) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 修正地区名称：港澳台前追加"中国"
     */
    private function fixCountryName(string $name): string
    {
        return match ($name) {
            '香港', '澳门', '台湾' => '中国' . $name,
            default => $name,
        };
    }

    // ==================== 行政区划解析 ====================

    /**
     * 解析行政区划代码为省/市/区名称
     * 完全对应 index.js 的 resolveDivision 逻辑
     */
    private function resolveDivision(?int $code): ?array
    {
        if (!$code || $code === 0) {
            return null;
        }

        $provinceCode = intdiv($code, 10000) * 10000;
        $cityCode     = intdiv($code, 100) * 100;

        $full  = [];
        $short = [];

        // 省级
        if (isset($this->divisionFull[$provinceCode])) {
            $full[] = $this->divisionFull[$provinceCode];
        }
        if (isset($this->divisionShort[$provinceCode])) {
            $short[] = $this->divisionShort[$provinceCode];
        }

        // 市级 (跳过与省级相同的情况, 即直辖市)
        if ($cityCode !== $provinceCode) {
            if (isset($this->divisionFull[$cityCode])) {
                $full[] = $this->divisionFull[$cityCode];
            }
            if (isset($this->divisionShort[$cityCode])) {
                $short[] = $this->divisionShort[$cityCode];
            }
        }

        // 区级 (跳过与市级相同的情况)
        if ($code !== $cityCode && $code !== $provinceCode) {
            if (isset($this->divisionFull[$code])) {
                $full[] = $this->divisionFull[$code];
            }
            if (isset($this->divisionShort[$code])) {
                $short[] = $this->divisionShort[$code];
            }
        }

        if (empty($full) && empty($short)) {
            return null;
        }

        // 直辖市：省级和市级名称相同时，去掉重复的第一项
        if (count($full) >= 2 && $full[0] === $full[1]) {
            $full = array_slice($full, 1);
        }
        if (count($short) >= 2 && $short[0] === $short[1]) {
            $short = array_slice($short, 1);
        }

        return ['full' => $full, 'short' => $short];
    }

    // ==================== 数据库加载 ====================

    /**
     * 加载纯真数据库 (ipdb)
     */
    private function loadQQwryDatabase(): ?IpdbReader
    {
        $now = time();
        if ($this->qqwryDb && ($now - $this->qqwryLoadTime) < $this->cacheTTL) {
            return $this->qqwryDb;
        }

        if (!file_exists($this->qqwryPath)) {
            error_log("纯真数据库文件不存在: {$this->qqwryPath}");
            return null;
        }

        try {
            error_log("正在加载纯真数据库...");
            $this->qqwryDb = new IpdbReader($this->qqwryPath);
            $this->qqwryLoadTime = $now;
            $size = filesize($this->qqwryPath);
            error_log("纯真数据库加载成功，大小: {$size} 字节");
            return $this->qqwryDb;
        } catch (\Exception $e) {
            error_log("纯真数据库加载失败: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 加载 GeoCN 数据库 (mmdb)
     */
    private function loadGeoCNDatabase(): ?GeoIp2Reader
    {
        $now = time();
        if ($this->geocnDb && ($now - $this->geocnLoadTime) < $this->cacheTTL) {
            return $this->geocnDb;
        }

        if (!file_exists($this->geocnPath)) {
            error_log("GeoCN 数据库文件不存在: {$this->geocnPath}");
            return null;
        }

        try {
            error_log("正在加载 GeoCN 数据库...");
            $size = filesize($this->geocnPath);
            error_log("GeoCN 文件大小: {$size} 字节");
            $this->geocnDb = new GeoIp2Reader($this->geocnPath);
            $this->geocnLoadTime = $now;
            error_log("GeoCN 数据库加载成功");
            return $this->geocnDb;
        } catch (\Exception $e) {
            error_log("加载 GeoCN 失败: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 加载行政区划数据 (full.txt / short.txt)
     */
    private function loadDivisionData(): bool
    {
        $now = time();
        if ($this->divisionFull && $this->divisionShort
            && ($now - $this->divisionLoadTime) < $this->cacheTTL
        ) {
            return true;
        }

        try {
            error_log("正在加载行政区划数据...");

            // 加载 full.txt (tab 分隔: code\t全名)
            if (!file_exists($this->divisionFullPath)) {
                error_log("找不到行政区划数据: {$this->divisionFullPath}");
                return false;
            }
            $this->divisionFull = [];
            $lines = file($this->divisionFullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $idx = strpos($line, "\t");
                if ($idx === false) continue;
                $code = intval(substr($line, 0, $idx));
                $name = trim(substr($line, $idx + 1));
                if ($code > 0 && $name !== '') {
                    $this->divisionFull[$code] = $name;
                }
            }

            // 加载 short.txt (双空格分隔: code  简称)
            if (!file_exists($this->divisionShortPath)) {
                error_log("找不到行政区划数据: {$this->divisionShortPath}");
                return false;
            }
            $this->divisionShort = [];
            $lines = file($this->divisionShortPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $idx = strpos($line, '  ');
                if ($idx === false) continue;
                $code = intval(substr($line, 0, $idx));
                $name = trim(substr($line, $idx + 2));
                if ($code > 0 && $name !== '') {
                    $this->divisionShort[$code] = $name;
                }
            }

            $this->divisionLoadTime = $now;
            error_log("行政区划数据加载成功: full=" . count($this->divisionFull)
                . " 条, short=" . count($this->divisionShort) . " 条");
            return true;

        } catch (\Exception $e) {
            error_log("行政区划数据加载失败: " . $e->getMessage());
            return false;
        }
    }

    // ==================== 配置加载 ====================

    /**
     * 从 config.json 加载运行时配置
     */
    private function loadConfig(): array
    {
        $now = time();
        if ($this->appConfig && ($now - $this->configLoadTime) < $this->configCacheTTL) {
            return $this->appConfig;
        }

        try {
            if (file_exists($this->configPath)) {
                $content = file_get_contents($this->configPath);
                $this->appConfig = json_decode($content, true);
                if (is_array($this->appConfig)) {
                    $this->configLoadTime = $now;
                    error_log("配置加载成功: mode={$this->appConfig['mode']}");
                    return $this->appConfig;
                }
            }
        } catch (\Exception $e) {
            error_log("加载配置失败: " . $e->getMessage());
        }

        error_log("使用默认模式 qqwry_geocn");
        $this->appConfig = ['mode' => 'qqwry_geocn'];
        $this->configLoadTime = $now;
        return $this->appConfig;
    }
}
