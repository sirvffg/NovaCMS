<?php
class EpayCore {
    private $pid;
    private $key;
    private $epay_url;

    public function __construct($config) {
        $this->pid = $config['pid'];
        $this->key = $config['key'];
        $this->epay_url = rtrim(trim($config['url']), '/') . '/';
    }

    public function buildRequestPara($para_temp) {
        $para_temp['pid'] = $this->pid;
        $para_filter = $this->paraFilter($para_temp);
        $para_sort = $this->argSort($para_filter);
        $mysign = $this->buildRequestMysign($para_sort);
        $para_sort['sign'] = $mysign;
        $para_sort['sign_type'] = "MD5";
        return $para_sort;
    }

    public function buildRequestForm($para_temp, $method = 'POST', $button_name = '正在跳转...') {
        $para = $this->buildRequestPara($para_temp);
        
        $sHtml = "<form id='epaysubmit' name='epaysubmit' action='".$this->epay_url."submit.php' method='".$method."'>";
        foreach ($para as $key => $val) {
            $sHtml.= "<input type='hidden' name='".$key."' value='".htmlspecialchars($val, ENT_QUOTES)."'/>";
        }
        $sHtml = $sHtml."<input type='submit' value='".$button_name."' style='display:none;'></form>";
        $sHtml = $sHtml."<script>document.forms['epaysubmit'].submit();</script>";
        return $sHtml;
    }

    public function verifyReturn($data) {
        if(empty($data) || empty($data['sign'])) {
            return false;
        }
        $sign = $data['sign'];
        $signParams = $this->paraFilter($data);
        $para_sort = $this->argSort($signParams);
        $mysign = $this->buildRequestMysign($para_sort);
        return $mysign === $sign;
    }

    public function verifyNotify($data) {
        return $this->verifyReturn($data);
    }

    private function buildRequestMysign($para_sort) {
        $prestr = $this->createLinkstring($para_sort);
        return md5($prestr . $this->key);
    }

    private function paraFilter($para) {
        $para_filter = array();
        foreach ($para as $key => $val) {
            if($key == "sign" || $key == "sign_type" || $val === "") {
                continue;
            } else {
                $para_filter[$key] = $para[$key];
            }
        }
        return $para_filter;
    }

    private function argSort($para) {
        ksort($para);
        reset($para);
        return $para;
    }

    private function createLinkstring($para) {
        $arg  = "";
        foreach ($para as $key => $val) {
            $arg .= $key."=".$val."&";
        }
        $arg = substr($arg, 0, -1);
        if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc()) {
            $arg = stripslashes($arg);
        }
        return $arg;
    }
}
?>