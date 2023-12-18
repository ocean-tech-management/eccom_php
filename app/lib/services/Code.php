<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 阿里云验证码服务类]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;

use app\lib\exceptions\ServiceException;
use think\Exception;
use think\facade\Cache;
use think\facade\Config;
use app\lib\exceptions\CodeException;

class Code
{
    private $config;
    private $singName;
    private $accessKeyId;
    private $templateCode;
    private $accessKeySecret;
    private $outID = '2019';
    private $SmsUpExtendCode = '181920';
    private static $instance;
    protected $defaultSmsMode = 'phoneRegister';        //默认模式,对应配置项中模板编码的key值
    protected $codeOftenTime = 60;                      //验证码获取时间间隔限制
    protected $codeExpire = 600;                        //验证码缓存失效时间
    protected $whiteCode = 666666;                      //白名单验证码
    protected $openWhite = -1;                          //是否开启白名单 1为是 -1为否
    protected $white = [];                              //手机白名单数组


    private function __construct()
    {
        if (!$this->accessKeyId) {
            $config = Config::get('system.sms');
            $this->config = $config;
            $this->accessKeySecret = $config['accessKeySecret'];
            $this->accessKeyId = $config['accessKeyId'];
            $this->singName = $config['singName'];
            $this->templateCode = $config[$this->defaultSmsMode];
        }
    }

    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new Code();
        }
        return self::$instance;
    }

    public function __clone()
    {
        $this->throwError('Clone is not allowed', 600105);
    }

    /**
     * @title  发送验证码
     * @param string $phone 手机号码
     * @return string
     * @throws ServiceException|CodeException
     * @author  Coder
     * @date   2019年11月13日 15:54
     */
    public function register($phone)
    {
        $this->validate($phone);
        $white = $this->filter($phone);
        if ($white) return $white;
        $record = cache($phone);
        if ($record && $record['create_time'] > time() - $this->codeOftenTime) {
            $this->throwError('请求验证码过于频繁', 600102, $phone);
        } else {
            cache($phone, null);
        }
        $code = $this->smsNotice($phone, $this->defaultSmsMode);

        return $code;
    }

    /**
     * @title  发送通知短信
     * @return array
     * @throws CodeException|ServiceException
     * @author  Coder
     * @date   2019年11月13日 15:54
     */
    public function alarm($phone, $data)
    {
        $this->validate($phone, null, $data);
        if (is_array($phone)) {
            $phone = implode(',', $phone);
        }
        $sendRes = $this->smsNotice($phone, $data['type'], $data['notify']);
        return $sendRes;

    }

    /**
     * @title  校验验证码
     * @param string $phone 手机号码
     * @param string $code 验证码
     * @return bool
     * @throws ServiceException|CodeException
     * @author  Coder
     * @date   2019年11月13日 15:54
     */
    public function goCheck($phone, string $code)
    {
        $this->validate($phone, $code);
        $res = true;
        if (!$this->checkWhite($phone)) {
            $exist = cache($phone);
            if ($exist['code'] != $code) {
                $this->throwError('手机验证码有误', 600103, ['phone' => $phone, 'code' => $code]);
            }
        }
        return $res;

    }

    /**
     * @title  发送短信
     * @param mixed $phone 手机号码
     * @param mixed $type 消息类型(默认短信验证码)
     * @param mixed $data 模板数组
     * @return mixed
     * @throws CodeException|ServiceException
     * @author  Coder
     * @date   2019年11月13日 15:54
     */
    private function smsNotice($phone, $type, $data = [])
    {
        $returnMsg = false;
        if ($type == $this->defaultSmsMode) {
            $code = $this->getCode();
            $info = ['create_time' => time(), 'code' => $code];
            $notify = ['code' => $code];
        } else {
            $notify = $data;
        }
        $sendNotify = ['type' => $type, 'notify' => $notify];
        $params = $this->refreshRequestData($phone, $sendNotify);
        $sendRes = $this->sendToAliCloud($params);
        if ($sendRes['status'] == 200) {
            if ($type == $this->defaultSmsMode) {
                Cache::set($phone, $info, $this->codeExpire);
                $returnMsg = $code;
            } else {
                $returnMsg = true;
            }
            $this->logRecord('发送短信成功', ['sendRes' => $sendRes, 'phone' => $phone, 'notify' => $sendNotify], 'info');
        } else {
            $this->throwError($sendRes['msg'], 600104, $sendRes);
        }
        return $returnMsg;

    }

    /**
     * @title  重组阿里云请求数组
     * @author  Coder
     * @date   2019年11月13日 11:15
     */
    private function refreshRequestData($phone, $data)
    {
        $config = $this->config;
        $params = [
            'PhoneNumbers' => $phone,
            'SignName' => $this->singName,
            'TemplateCode' => $config[$data['type']],
            'TemplateParam' => $data['notify'],
            'OutId' => $this->outID,
            'SmsUpExtendCode' => $this->SmsUpExtendCode,
        ];
        if (!empty($params["TemplateParam"]) && is_array($params["TemplateParam"])) {
            $params["TemplateParam"] = json_encode($params["TemplateParam"], JSON_UNESCAPED_UNICODE);
        }
        return $params;
    }

    /**
     * @title  检验参数
     * @param mixed $phone 手机号码
     * @param mixed $code 验证码
     * @param mixed $data 通知消息模版数组
     * @return bool
     * @throws CodeException|ServiceException
     * @author  Coder
     * @date   2019年11月12日 17:41
     */
    private function validate($phone = null, $code = null, $data = null)
    {
        $log['phone'] = $phone;
        $log['code'] = $code;
        $log['data'] = $data;
        $rule = '/^1[3456789]{1}\d{9}$/';
        $checkMsg = null;
        if (empty($phone)) {
            $checkMsg = '手机号码不允许为空';
        } else {
            if (!preg_match($rule, $phone)) $checkMsg = '手机号码有误';
        }
        if (!empty($code)) {
            if (!is_numeric($code)) $checkMsg = '验证码必须为数字';
        }
        if (!empty($data)) {
            if (empty($data['type'])) $checkMsg = '通知类型必填';
            if (empty($data['notify'])) $checkMsg = '通知模版必填';
        }
        if (!empty($checkMsg)) $this->throwError($checkMsg, 600101, $log);

        return true;
    }

    /**
     * @title  短信白名单生成验证码
     * @param mixed $phone 手机号码
     * @return string
     * @author  Coder
     * @date   2019年11月13日 15:54
     */
    public function filter($phone)
    {
        $res = false;
        if ($this->openWhite == 1) {
            if ($this->checkWhite($phone)) {
                $info = [
                    'create_time' => time(),
                    'code' => $this->whiteCode
                ];
                Cache::set($phone, $info, $this->codeExpire);
                $res = $this->whiteCode;
            }
        }
        return $res;

    }

    /**
     * @title  检查是否存在白名单
     * @param mixed $phone 手机号码
     * @return bool
     * @author  Coder
     * @date   2019年08月16日 11:06
     */
    public function checkWhite($phone): bool
    {
        $res = false;
        if ($this->openWhite == 1) {
            if (in_array($phone, $this->white)) {
                $res = true;
            }
        }
        return $res;
    }

    /**
     * @title  向阿里云服务发起请求
     * @param array $params 请求数据
     * @return array
     * @author  Coder
     * @date   2019年11月13日 15:55
     */
    private function sendToAliCloud(array $params)
    {
        //设置参数，签名以及发送请求
        try {
            // 此处可能会抛出异常，注意catch
            $content = $this->request(
                $this->accessKeyId,
                $this->accessKeySecret,
                "dysmsapi.aliyuncs.com",
                array_merge($params, array(
                    "RegionId" => "cn-hangzhou",
                    "Action" => "SendSms",
                    "Version" => "2017-05-25",
                ))
            );
            if ($content->Code == 'OK') {
                $returnMsg = ['status' => 200, 'msg' => '成功'];
            } else {
                throw new Exception($content->Message, 600106);
            }
            return $returnMsg;
        } catch (\Exception $e) {
            $returnMsg = ['status' => $e->getCode(), 'msg' => $e->getMessage()];
            return $returnMsg;
        }
    }

    /**
     * @title  设置签名
     * @param string $singName 签名
     * @return $this
     * @author  Coder
     * @date   2019年11月13日 11:36
     */
    public function setSingName(string $singName)
    {
        $this->singName = $singName;
        return $this;
    }

    /**
     * @title  设置模板code
     * @param string $templateCode 模板code
     * @return $this
     * @author  Coder
     * @date   2019年11月13日 11:36
     */
    public function setTemplateCode(string $templateCode)
    {
        $this->templateCode = $templateCode;
        return $this;
    }

    /**
     * @title  生成验证码
     * @param int $length 验证码长度
     * @return string
     * @author  Coder
     * @date   2019年11月13日 11:35
     */
    private function getCode(int $length = 4)
    {
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= rand(0, 9);
        }
        return $str;
    }


    /**
     * @title  异常抛出
     * @param string $errorMsg 错误消息
     * @param int $errorCode 错误码
     * @param mixed $data 错误数据
     * @throws CodeException|ServiceException
     * @author  Coder
     * @date   2019年11月13日 11:18
     */
    private function throwError(string $errorMsg, int $errorCode, $data = null)
    {
        $this->logRecord($errorMsg, $data);
        throw new CodeException(['msg' => $errorMsg, 'errorCode' => $errorCode]);
    }

    /**
     * @title  日志记录
     * @param mixed $errorMsg 记录信息
     * @param mixed $data 记录数据
     * @param string $level 日志等级
     * @return mixed
     * @throws ServiceException
     * @author  Coder
     * @date   2019年11月13日 11:18
     */
    private function logRecord($errorMsg, $data = null, string $level = 'error')
    {
        $logData['code_msg'] = $errorMsg;
        $logData['code_data'] = $data;
        $res = (new Log())->setChannel('code')->record($logData, $level);
        return $res;
    }

    // +----------------------------------------------------------------------
    // |[ 文档说明: 阿里云验证码请求Helper模块]
    // +----------------------------------------------------------------------

    private function request($accessKeyId, $accessKeySecret, $domain, $params, $security = false)
    {
        $apiParams = array_merge(array(
            "SignatureMethod" => "HMAC-SHA1",
            "SignatureNonce" => uniqid(mt_rand(0, 0xffff), true),
            "SignatureVersion" => "1.0",
            "AccessKeyId" => $accessKeyId,
            "Timestamp" => gmdate("Y-m-d\TH:i:s\Z"),
            "Format" => "JSON",
        ), $params);
        ksort($apiParams);

        $sortedQueryStringTmp = "";
        foreach ($apiParams as $key => $value) {
            $sortedQueryStringTmp .= "&" . $this->encode($key) . "=" . $this->encode($value);
        }

        $stringToSign = "GET&%2F&" . $this->encode(substr($sortedQueryStringTmp, 1));

        $sign = base64_encode(hash_hmac("sha1", $stringToSign, $accessKeySecret . "&", true));

        $signature = $this->encode($sign);

        $url = ($security ? 'https' : 'http') . "://{$domain}/?Signature={$signature}{$sortedQueryStringTmp}";

        try {
            $content = $this->fetchContent($url);
            return json_decode($content);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function encode($str)
    {
        $res = urlencode($str);
        $res = preg_replace("/\+/", "%20", $res);
        $res = preg_replace("/\*/", "%2A", $res);
        $res = preg_replace("/%7E/", "~", $res);
        return $res;
    }

    private function fetchContent($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "x-sdk-client" => "php/2.0.0"
        ));

        if (substr($url, 0, 5) == 'https') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        $rtn = curl_exec($ch);

        if ($rtn === false) {
            trigger_error("[CURL_" . curl_errno($ch) . "]: " . curl_error($ch), E_USER_ERROR);
        }
        curl_close($ch);

        return $rtn;
    }

}