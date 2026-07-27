<?php
/**
 * 爱发电 API 调用类
 */
class AfdianAPI
{
    private $userId;
    private $token;
    private $baseUrl = 'https://afdian.com/api';

    public function __construct($userId, $token)
    {
        $this->userId = $userId;
        $this->token = $token;
    }

    /**
     * 生成签名
     * @param string $params 参数JSON字符串
     * @return string 签名
     */
    private function generateSign($params)
    {
        $ts = time();
        $signStr = $this->token . 'params' . $params . 'ts' . $ts . 'user_id' . $this->userId;
        return [
            'sign' => md5($signStr),
            'ts' => $ts
        ];
    }

    /**
     * 发送请求
     * @param string $url 接口地址
     * @param array $params 请求参数
     * @return array 响应数据
     */
    private function request($url, $params = [])
    {
        $paramsJson = json_encode($params);
        $signData = $this->generateSign($paramsJson);

        $postData = [
            'user_id' => $this->userId,
            'params' => $paramsJson,
            'ts' => $signData['ts'],
            'sign' => $signData['sign']
        ];

        $ch = curl_init($this->baseUrl . $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($result['ec'] !== 200) {
            throw new Exception("爱发电 API 错误: " . $result['em'] ?? '未知错误');
        }

        return $result['data'] ?? [];
    }

    /**
     * Ping 测试
     * @return array
     */
    public function ping($testData = [])
    {
        return $this->request('/open/ping', $testData);
    }

    /**
     * 查询订单
     * @param int $page 页码
     * @param string $outTradeNo 订单号（多个用逗号分隔）
     * @param int $perPage 每页数量
     * @return array
     */
    public function queryOrder($page = 1, $outTradeNo = '', $perPage = 50)
    {
        $params = ['page' => $page, 'per_page' => $perPage];
        if (!empty($outTradeNo)) {
            $params['out_trade_no'] = $outTradeNo;
        }
        return $this->request('/open/query-order', $params);
    }

    /**
     * 查询赞助者
     * @param int $page 页码
     * @param string $userId 用户ID（多个用逗号分隔）
     * @param int $perPage 每页数量
     * @return array
     */
    public function querySponsor($page = 1, $userId = '', $perPage = 20)
    {
        $params = ['page' => $page, 'per_page' => $perPage];
        if (!empty($userId)) {
            $params['user_id'] = $userId;
        }
        return $this->request('/open/query-sponsor', $params);
    }

    /**
     * 查询方案
     * @param string $planId 方案ID
     * @return array
     */
    public function queryPlan($planId)
    {
        return $this->request('/open/query-plan', ['plan_id' => $planId]);
    }

    /**
     * 查询随机自动回复
     * @param string $outTradeNo 订单号（多个用逗号分隔）
     * @return array
     */
    public function queryRandomReply($outTradeNo)
    {
        return $this->request('/open/query-random-reply', ['out_trade_no' => $outTradeNo]);
    }

    /**
     * 更新自动回复
     * @param string $planId 方案ID
     * @param string $skuId 型号ID
     * @param string $autoReply 自动回复内容
     * @param string $autoRandomReply 自动随机回复内容
     * @param int $updateRandomReplyType 更新类型：1-追加，2-覆盖
     * @return array
     */
    public function updatePlanReply($planId = '', $skuId = '', $autoReply = '', $autoRandomReply = '', $updateRandomReplyType = 0)
    {
        $params = [];
        if (!empty($planId)) {
            $params['plan_id'] = $planId;
        }
        if (!empty($skuId)) {
            $params['sku_id'] = $skuId;
        }
        if (!empty($autoReply)) {
            $params['auto_reply'] = $autoReply;
        }
        if (!empty($autoRandomReply)) {
            $params['auto_random_reply'] = $autoRandomReply;
            $params['update_random_reply_type'] = $updateRandomReplyType;
        }
        return $this->request('/open/update-plan-reply', $params);
    }

    /**
     * 发送私信
     * @param string $recipient 接收用户
     * @param string $content 私信内容
     * @return array
     */
    public function sendMsg($recipient, $content)
    {
        return $this->request('/open/send-msg', [
            'recipient' => $recipient,
            'content' => $content
        ]);
    }

    /**
     * 获取用户主页动态列表 (需要Cookie)
     * @param int $page 页码
     * @param string $cookie 爱发电 Cookie
     * @return array
     */
    public function getUserPostList($page = 1, $cookie = '')
    {
        $url = 'https://afdian.com/api/post/get-list?user_id=' . $this->userId . '&type=old&publish_sn=&per_page=10&group_id=&all=1&is_public=&plan_id=&title=&name=&page=' . $page;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        // 模拟浏览器 User-Agent
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        
        if (!empty($cookie)) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP 请求失败: " . $httpCode);
        }

        $result = json_decode($response, true);
        
        if (!isset($result['ec']) || $result['ec'] !== 200) {
            throw new Exception("API 错误: " . ($result['em'] ?? '未知错误'));
        }

        return $result['data'] ?? [];
    }
}
