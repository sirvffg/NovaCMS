<?php
// IP Geo Update Service

class IpGeoUpdater {
    private $db;
    private $config;
    
    // Default configuration
    private $defaultConfig = [
        'max_ips_per_update' => 50,
        'batch_size' => 10,
        'timeout' => 3,
        'memory_limit' => '256M',
        'max_execution_time' => 60,
        'use_local_first' => true  // 默认优先使用本地 IpQuery
    ];

    // 远程 API 列表（作为备用）
    private $apiList = [
        [
            'url_pattern' => 'http://ip-api.com/json/{IP}?fields=status,country,regionName,city,isp,message&lang=zh-CN',
            'json_map' => [
                'status_key' => 'status',
                'status_success' => 'success',
                'country' => 'country',
                'province' => 'regionName',
                'city' => 'city',
                'isp' => 'isp'
            ],
            'weight' => 10
        ],
        [
            'url_pattern' => 'http://ipwho.is/{IP}?lang=zh-CN',
            'json_map' => [
                'status_key' => 'success',
                'status_success' => true,
                'country' => 'country',
                'province' => 'region',
                'city' => 'city',
                'isp' => 'isp'
            ],
            'weight' => 8
        ],
        [
            'url_pattern' => 'https://ipapi.co/{IP}/json/',
            'json_map' => [
                'status_key' => null,
                'country' => 'country_name',
                'province' => 'region',
                'city' => 'city',
                'isp' => 'org'
            ],
            'weight' => 5
        ]
    ];

    public function __construct($db, $config = []) {
        $this->db = $db;
        $this->config = array_merge($this->defaultConfig, $config);
        
        ini_set('memory_limit', $this->config['memory_limit']);
        set_time_limit($this->config['max_execution_time']);
    }

    /**
     * 主入口：更新待处理的 IP
     */
    public function updatePendingIps() {
        $ips = $this->getPendingIps();
        $remaining = $this->getRemainingCount();
        
        if (empty($ips)) {
            return [
                'success' => true, 
                'updated' => 0, 
                'total' => 0, 
                'remaining' => 0,
                'message' => '没有待更新的IP'
            ];
        }

        $totalUpdated = 0;
        $logs = [];

        foreach ($ips as $ip) {
            $result = $this->lookupIp($ip);
            $logs[] = $result['log'];
            
            if ($result['success']) {
                $totalUpdated++;
            }
        }

        return [
            'success' => true,
            'updated' => $totalUpdated,
            'total' => count($ips),
            'remaining' => max(0, $remaining - count($ips)),
            'logs' => $logs
        ];
    }

    /**
     * 查询单个 IP：优先本地 IpQuery，失败则用远程 API
     */
    private function lookupIp($ip) {
        // 优先使用本地 IpQuery
        if ($this->config['use_local_first']) {
            $result = $this->lookupLocal($ip);
            if ($result && $result['success']) {
                return $result;
            }
        }

        // 备用：远程 API
        return $this->lookupRemote($ip);
    }

    /**
     * 本地 IpQuery 查询（通过 index.php 端点，支持 config.json 模式切换）
     */
    private function lookupLocal($ip) {
        try {
            $url = $this->resolveUrl('/vendor/api/ipsearch/index.php') . '?ip=' . urlencode($ip);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $httpCode !== 200) {
                return [
                    'success' => false,
                    'log' => [
                        'ip' => $ip,
                        'status' => 'error',
                        'message' => "index.php 返回 HTTP {$httpCode}",
                        'api' => '本地IpQuery'
                    ]
                ];
            }

            $queryResult = json_decode($body, true);
            if (!is_array($queryResult) || empty($queryResult['success'])) {
                return [
                    'success' => false,
                    'log' => [
                        'ip' => $ip,
                        'status' => 'error',
                        'message' => $queryResult['error'] ?? 'IpQuery 无结果',
                        'api' => '本地IpQuery'
                    ]
                ];
            }

            $loc = $queryResult['location'] ?? [];
            $geoData = [
                'country'  => $loc['country'] ?? '',
                'province' => $loc['province'] ?? '',
                'city'     => $loc['city'] ?? '',
                'isp'      => $loc['isp'] ?? ''
            ];

            if (empty($geoData['country'])) {
                return [
                    'success' => false,
                    'log' => [
                        'ip' => $ip,
                        'status' => 'error',
                        'message' => 'IpQuery 无结果',
                        'api' => '本地IpQuery'
                    ]
                ];
            }

            // 中国 IP 但没有省市信息，回退到远程 API
            if ($geoData['country'] === '中国' && empty($geoData['province'])) {
                return [
                    'success' => false,
                    'log' => [
                        'ip' => $ip,
                        'status' => 'error',
                        'message' => 'IpQuery 仅返回国家，无省市信息，尝试远程API',
                        'api' => '本地IpQuery'
                    ]
                ];
            }

            $this->updateIpRecord($ip, $geoData);

            $locStr = $geoData['country']
                . ($geoData['province'] ? ' ' . $geoData['province'] : '')
                . ($geoData['city'] ? ' ' . $geoData['city'] : '');

            return [
                'success' => true,
                'log' => [
                    'ip' => $ip,
                    'status' => 'success',
                    'message' => $locStr . ($queryResult['meta']['data_source'] ? ' [' . $queryResult['meta']['data_source'] . ']' : ''),
                    'api' => '本地IpQuery'
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'log' => [
                    'ip' => $ip,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'api' => '本地IpQuery'
                ]
            ];
        }
    }

    /**
     * 将相对路径解析为可访问的完整 URL
     * @param string $relativePath 相对于站点根目录的路径，如 '/vendor/api/ipsearch/index.php'
     */
    private function resolveUrl($relativePath) {
        if (isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $_SERVER['HTTP_HOST'] . $relativePath;
        }
        
        // CLI 模式：尝试从配置获取站点 URL
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'http://localhost';
        return rtrim($siteUrl, '/') . $relativePath;
    }

    /**
     * 远程 API 查询（备用）
     */
    private function lookupRemote($ip) {
        $api = $this->selectRandomApi();
        $url = str_replace('{IP}', $ip, $api['url_pattern']);
        $map = $api['json_map'];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $apiName = parse_url($url, PHP_URL_HOST);

        if ($httpCode === 200 && $content) {
            $data = json_decode($content, true);
            $isValid = false;

            if ($map['status_key']) {
                $isValid = isset($data[$map['status_key']]) && $data[$map['status_key']] === $map['status_success'];
            } else {
                $isValid = isset($data[$map['country']]) && !isset($data['error']);
            }

            if ($isValid) {
                $geoData = [
                    'country' => $data[$map['country']] ?? '',
                    'province' => $data[$map['province']] ?? '',
                    'city' => $data[$map['city']] ?? '',
                    'isp' => $data[$map['isp']] ?? ''
                ];

                if ($geoData['country']) {
                    $this->updateIpRecord($ip, $geoData);
                    $locStr = $geoData['country'] 
                        . ($geoData['province'] ? ' ' . $geoData['province'] : '') 
                        . ($geoData['city'] ? ' ' . $geoData['city'] : '');
                    return [
                        'success' => true,
                        'log' => [
                            'ip' => $ip,
                            'status' => 'success',
                            'message' => $locStr,
                            'api' => $apiName
                        ]
                    ];
                }
            }
        }

        return [
            'success' => false,
            'log' => [
                'ip' => $ip,
                'status' => 'error',
                'message' => "HTTP {$httpCode}",
                'api' => $apiName
            ]
        ];
    }

    private function getRemainingCount() {
        return $this->db->query("
            SELECT COUNT(DISTINCT ip_address) 
            FROM visit_stats 
            WHERE (country IS NULL OR country = '' 
                OR (country = '中国' AND (province IS NULL OR province = '')))
            AND ip_address != 'unknown'
        ")->fetchColumn();
    }

    private function getPendingIps() {
        $stmt = $this->db->query("
            SELECT DISTINCT ip_address 
            FROM visit_stats 
            WHERE (country IS NULL OR country = '' 
                OR (country = '中国' AND (province IS NULL OR province = '')))
            AND ip_address != 'unknown'
            LIMIT {$this->config['max_ips_per_update']}
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function selectRandomApi() {
        $rand = rand(1, 23);
        if ($rand <= 10) return $this->apiList[0];
        return $this->apiList[1];
    }

    private function updateIpRecord($ip, $data) {
        $stmt = $this->db->prepare("
            UPDATE visit_stats 
            SET country = ?, province = ?, city = ?, isp = ?
            WHERE ip_address = ?
        ");
        
        return $stmt->execute([
            $data['country'],
            $data['province'],
            $data['city'],
            $data['isp'],
            $ip
        ]);
    }
}
