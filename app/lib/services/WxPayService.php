<?php
// +----------------------------------------------------------------------
// | [ 文档说明: 微信支付service,统一规范请继承IPay接口类]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------

namespace app\lib\services;


use app\lib\exceptions\WxException;
use app\lib\interfaces\IPay;
use app\lib\models\OrderPayArguments;
use think\facade\Request;

class WxPayService implements IPay
{
    protected $appId;
    protected $mchId;
    protected $mchKey;
    private $certDis;
    public $timeStart;
    public $expireTime;
    public $type = 'JSAPI';
    protected $customConfig;

    /**
     * 初始化关键参数
     * @throws WxException
     */
    public function __construct()
    {
        //请注意, 本类所有接口均为v2接口,商户号配置中秘钥请申请APIv2秘钥;
        //微信支付v2接口文档地址 https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=7_1
        // $config = config('system.weChat');
        $config = getConfigByAppid();
        if (!empty($this->customConfig)) {
            $config = $this->customConfig;
        }
        if (empty($config)) throw new WxException();
        $this->appId = $config['app_id'];
        $this->mchId = $config['mch_id'];
        $this->mchKey = $config['mch_key'];
        $this->timeStart = date("YmdHis");
        $this->expireTime = date("YmdHis", (time() + 3600 * 24));
        // $this->certDis = $config['cert_path'];
    }

    /**
     * @title 单独设置第三方支付商配置
     * @param array $data
     * @return $this
     */
    public function setConfig(array $data)
    {
        $this->customConfig = $data;
        return $this;
    }

    /**
     * @title  统一下单
     * @param array $data
     * @return mixed
     */
    public function order(array $data)
    {
        //获取配置
        $app_id = OrderPayArguments::where(['order_sn' => $data['out_trade_no']])->value('app_id');
        $config = getConfigByAppid($app_id);
        $this->appId = $config['app_id'];
        $this->mchId = $config['mch_id'];
        $this->mchKey = $config['mch_key'];
        $parameters['appid'] = $this->appId;
        $parameters['mch_id'] = $this->mchId;
        $parameters['nonce_str'] = $this->getNonceStr();
        $parameters['body'] = $data['body'];
        $parameters['out_trade_no'] = $data['out_trade_no'];
        $parameters['total_fee'] = $data['total_fee'] * 100;
        //$parameters['total_fee']        = 1;
        $parameters['spbill_create_ip'] = Request::ip();
        $parameters['notify_url'] = $data['notify_url'];
        //$parameters['attach']           = $data['attach'];//附加数据
        $parameters['attach'] = '自营商城订单'; //附加数据
        if (!empty($data['trade_type'] ?? null)) {
            $this->type = $data['trade_type'];
        }
        $parameters['trade_type'] = $this->type; //支付类型
        if ($this->type == 'JSAPI') {
            $parameters['openid'] = $data['openid']; //微信用户的openid
        }
        if (!empty($data['map'])) {
            $parameters['attach'] = trim($data['map']);  //公共回传参数,在回调的时候会返回
        }
        $parameters['time_start'] = $this->timeStart; //订单生成时间
        $parameters['time_expire'] = $this->expireTime; //订单失效时间

        $parameters['sign'] = $this->getSign($parameters);
        $queryXml = $this->arrayToXml($parameters);
        $url = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
        $returnData = $this->httpRequest($url, $queryXml);
        $result = $this->xmlToArray($returnData);
        //记录日志
        $this->log(['type' => 'orderPay', 'param' => $parameters, 'queryXml' => $queryXml, 'returnXml' => $returnData, 'returnData' => $result]);

        if ($result['return_code'] == 'FAIL') {
            $msg = !empty($result['return_msg']) ? $result['return_msg'] : $result['err_code_des'];
            throw new WxException(['msg' => $msg]);
        }
        return $this->isType($result);
    }

    /**
     * @title  判断扫码支付或调起支付
     * @param mixed $result
     * @return mixed
     */
    public function isType($result)
    {
        if (in_array($this->type, ['JSAPI', 'APP'])) {
            switch ($this->type) {
                case 'JSAPI':
                default:
                    $jsApiData = $this->getJsApiParameters($result);
                    break;
                case 'APP':
                    $jsApiData = $this->getAppParameters($result);
                    break;
            }
//            $jsApiData = $this->getJsApiParameters($result);
            return $jsApiData;
        } else {
            return $result;
        }
    }

    /**
     * @title  支付成功回调函数
     * @param string $callbackXml 回调xml
     * @param bool $checkSign 是否需要校验签名
     * @return bool|mixed
     */
    public function callback(string $callbackXml, bool $checkSign = true)
    {
        $data = json_decode(json_encode(simplexml_load_string($callbackXml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        if (!empty($data)) {
            if ($checkSign) {
                //验证签名
                $callbackSign = $data['sign'];
                unset($data['sign']);
                $sign = $this->getSign($data);
                if ($sign == $callbackSign) {
                    return $data;
                }
            } else {
                return $data;
            }
        }
        return false;
    }


    /**
     * @title  微信支付生成签名(MD5)
     * @param mixed $parameters 签名
     * @return string
     */
    public function getSign($parameters)
    {
        ksort($parameters);
        $parametersUrl = urldecode(http_build_query($parameters)) . '&key=' . $this->mchKey;
        $sign = strtoupper(md5($parametersUrl));
        return $sign;
    }

    /**
     * @title  数组转xml
     * @param mixed $arr
     * @return string
     */
    private function arrayToXml($arr)
    {
        if (!is_array($arr) || count($arr) <= 0) {
            throw new WxException(['msg' => '数组数据异常!']);
        }

        $xml = "<xml>";
        foreach ($arr as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
            } else {
                $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
            }
        }
        $xml .= "</xml>";
        return $xml;
    }

    /**
     * @title  curl请求
     * @param string $url 请求地址
     * @param string $data
     * @return bool|string
     */
    private function httpRequest(string $url, string $data = "")
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        if (!empty($data)) { //判断是否为POST请求
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }

    /**
     * @title  XML转数组
     * @param mixed $xml
     * @return mixed
     */
    private function xmlToArray($xml)
    {
        if (!$xml) throw new WxException(['msg' => 'XML数据异常!']);
        libxml_disable_entity_loader(true);
        $arr = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $arr;
    }

    /**
     * @title  获取JSAPI支付参数
     * @param array $data
     * @return mixed
     */
    private function getJsApiParameters(array $data): array
    {
        $timeStamp = time();
        $jsapi["appId"] = $this->appId;
        $jsapi["nonceStr"] = $this->getNonceStr();
        $jsapi["timeStamp"] = "$timeStamp";
        $jsapi["signType"] = "MD5";
        $jsapi["package"] = "prepay_id=" . $data["prepay_id"];
        $jsapi["paySign"] = $this->getSign($jsapi);
        $jsapi["return_msg"] = 'OK';
        return $jsapi;
    }

    /**
     * @title  获取App支付参数
     * @param array $data
     * @return mixed
     */
    private function getAppParameters(array $data): array
    {
        $timeStamp = time();
        $app["appid"] = $this->appId;
        $app["partnerid"] = $this->mchId;
        $app["prepayid"] = $data["prepay_id"];
        $app["package"] = "Sign=WXPay";
        $app["noncestr"] = $this->getNonceStr();
        $app["timestamp"] = "$timeStamp";
        $app["sign"] = $this->getSign($app);
        $app["return_msg"] = 'OK';
        return $app;
    }


    /**
     * @title  生成随机数
     * @param int $length 长度
     * @return string
     */
    private function getNonceStr(int $length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

    /**
     * @title  查询订单是否支付成功
     * @param string $out_trade_no 订单号
     * @return mixed
     */
    public function orderQuery(string $out_trade_no)
    {
        //获取配置
        $app_id = OrderPayArguments::where(['order_sn' => $out_trade_no])->value('app_id');
        $config = getConfigByAppid($app_id);
        $this->appId = $config['app_id'];
        $this->mchId = $config['mch_id'];
        $this->mchKey = $config['mch_key'];
        $parameters['appid'] = $this->appId;
        $parameters['mch_id'] = $this->mchId;
        $parameters['nonce_str'] = $this->getNonceStr();
        $parameters['out_trade_no'] = $out_trade_no;
        $parameters['sign'] = $this->getSign($parameters);
        $queryXml = $this->arrayToXml($parameters);
        $url = 'https://api.mch.weixin.qq.com/pay/orderquery';
        $returnData = $this->httpRequest($url, $queryXml);
        $result = $this->xmlToArray($returnData);
        if ($result['trade_state'] == 'SUCCESS') {
            $message['status'] = 100;
            $message['message'] = '支付对单成功';
            $message['result'] = $result;
        } else {
            $message['status'] = 40010;
            $message['message'] = '支付对单失败';
        }
        return $message;
    }

    /**
     * @title  企业付款到零钱
     * @param array $data
     * @return mixed
     */
    public function enterprisePayment(array $data)
    {
        $parameters['mch_appid'] = $this->appId;
        $parameters['mchid'] = $this->mchId;
        $parameters['nonce_str'] = $this->getNonceStr();
        $parameters['partner_trade_no'] = $data['partner_trade_no']; //商户订单号
        $parameters['amount'] = $data['amount'] * 100; //企业付款金额
        $parameters['spbill_create_ip'] = Request::ip();
        $parameters['openid'] = $data['openid'];  //微信用户的openid
        $parameters['check_name'] = 'FORCE_CHECK';  //是否校验用户真实姓名
        $parameters['re_user_name'] = $data['re_user_name'];  //用户真实姓名(如果check_name=FORCE_CHECK必传)
        $parameters['desc'] = $data['desc']; //企业付款备注
        $parameters['sign'] = $this->getSign($parameters);
        $queryXml = $this->arrayToXml($parameters);
        $url = 'https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers';
        $returnData = $this->curl_post_ssl($url, $queryXml);
        $result = $this->xmlToArray($returnData);
        //记录日志
        $this->log(['type' => 'enterprisePayment', 'param' => $parameters, 'queryXml' => $queryXml, 'returnXml' => $returnData, 'returnData' => $result]);
        if ($result['result_code'] == 'FAIL') {
            $msg = !empty($result['err_code_des']) ? $result['err_code_des'] : $result['err_code'];
            throw new WxException(['msg' => $msg]);
        }
        return $result;
    }

    /**
     * @title  退款信息对账单
     * @param string $out_refund_no 商户退款单号
     * @return mixed
     */
    public function refundQuery(string $out_refund_no, string $out_trade_no = null)
    {
        if ($out_trade_no) {
            $app_id = OrderPayArguments::where(['order_sn' => $out_trade_no])->value('app_id');
            $config = getConfigByAppid($app_id);
            $this->appId = $config['app_id'];
            $this->mchId = $config['mch_id'];
            $this->mchKey = $config['mch_key'];
        }
        $parameters['appid'] = $this->appId;
        $parameters['mch_id'] = $this->mchId;
        $parameters['nonce_str'] = $this->getNonceStr();
        $parameters['out_refund_no'] = $out_refund_no;  //商户退款单号
        $parameters['offset'] = 0;
        $parameters['sign'] = $this->getSign($parameters);
        $queryXml = $this->arrayToXml($parameters);
        $url = 'https://api.mch.weixin.qq.com/pay/refundquery';
        $returnData = $this->httpRequest($url, $queryXml);
        $result = $this->xmlToArray($returnData);
        $message['status'] = 40010;
        if ($result['return_code'] == 'SUCCESS') {
            if ($result['result_code'] == 'SUCCESS') {
                $message['status'] = 100;
                $message['message'] = '退款对单成功';
                $message['result'] = $result;
            }
        } else {
            $message['message'] = !empty($result['return_msg']) ? $result['return_msg'] : '退款对单失败';
        }
        //记录日志
        $this->log(['type' => 'refundQuery', 'param' => $parameters, 'queryXml' => $queryXml, 'returnXml' => $returnData, 'returnData' => $result, 'returnMsg' => $message]);
        return $message;
    }

    /**
     * @title  退款
     * @param array $data
     * @return mixed
     */
    public function refund(array $data)
    {
        if ($data['app_id']) {
            $config = getConfigByAppid($data['app_id']);
            $this->appId = $config['app_id'];
            $this->mchId = $config['mch_id'];
            $this->mchKey = $config['mch_key'];
        }
        $parameters['appid'] = $this->appId;
        $parameters['mch_id'] = $this->mchId;
        $parameters['nonce_str'] = $this->getNonceStr();
        $parameters['transaction_id'] = $data['transaction_id'] ?? null;  //微信支付订单号
        $parameters['out_trade_no'] = $data['out_trade_no'];     //商户订单号
        $parameters['out_refund_no'] = $data['out_refund_no'];    //商户退款订单号
        $parameters['total_fee'] = $data['total_fee'] * 100;  //订单金额
        $parameters['refund_fee'] = $data['refund_fee'] * 100; //退款金额
        $parameters['refund_desc'] = $data['refund_desc'];      //退款原因
        $parameters['notify_url'] = $data['notify_url'];       //回调地址
        $parameters['sign'] = $this->getSign($parameters);
        $queryXml = $this->arrayToXml($parameters);
        $url = 'https://api.mch.weixin.qq.com/secapi/pay/refund';
        $returnData = $this->curl_post_ssl($url, $queryXml);
        $result = $this->xmlToArray($returnData);
        //记录日志
        $this->log(['type' => 'refund', 'param' => $parameters, 'queryXml' => $queryXml, 'returnXml' => $returnData, 'returnData' => $result]);
        if ($result['return_code'] == 'FAIL') {
            $msg = $result['return_msg'];
            if (empty($data['notThrowError'] ?? false)) {
                throw new WxException(['msg' => $msg]);
            } else {
                return false;
            }
        }
        return $result;
    }

    /**
     * @title  解密微信退款回调信息关键参数
     * @param  $data
     * @return array|string
     */
    public function decryptRefundCallbackData($data)
    {
        if (empty($data)) return false;
        if ($data['return_code'] == 'SUCCESS') {
            $reqInfo = $data['req_info'];
            $decodeBase64 = base64_decode($reqInfo);
            $keyMd5 = md5($this->mchKey);
            $decryptXml = openssl_decrypt($decodeBase64, 'aes-256-ecb', $keyMd5, OPENSSL_RAW_DATA);
            $data['decrypt_req_info'] = $this->xmlToArray($decryptXml);
            return $data;
        } else {
            return $data;
        }
    }

    /**
     * @title  带证书的请求
     * @param string $url
     * @param string $xmlData
     * @param int $second
     * @param array $aHeader
     * @return array|bool|string
     */
    public function curl_post_ssl(string $url, string $xmlData, int $second = 30, array $aHeader = [])
    {
        $isdir = $this->certDis; //证书位置

        $ch = curl_init(); //初始化curl
        curl_setopt($ch, CURLOPT_TIMEOUT, $second); //设置执行最长秒数
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_URL, $url); //抓取指定网页
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 终止从服务端进行验证
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); //
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM'); //证书类型
        curl_setopt($ch, CURLOPT_SSLCERT, $isdir . 'apiclient_cert.pem'); //证书位置
        curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM'); //CURLOPT_SSLKEY中规定的私钥的加密类型
        curl_setopt($ch, CURLOPT_SSLKEY, $isdir . 'apiclient_key.pem'); //证书位置
        if (count($aHeader) >= 1) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $aHeader); //设置头部
        }
        curl_setopt($ch, CURLOPT_POST, 1); //post提交方式
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData); //全部数据使用HTTP协议中的"POST"操作来发送

        $data = curl_exec($ch); //执行回话
        if ($data) {
            curl_close($ch);
            return $data;
        } else {
            $error = curl_errno($ch);
            echo "call faild, errorCode:$error\n";
            curl_close($ch);
            return false;
        }
    }

    /**
     * @title  日志记录
     * @param array $data 记录信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'info')
    {
        $res = (new Log())->setChannel('pay')->record($data, $level);
        return $res;
    }
}
