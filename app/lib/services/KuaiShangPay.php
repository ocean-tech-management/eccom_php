<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\services;


use app\lib\exceptions\ServiceException;
use app\lib\models\KsConfig;
use app\lib\models\LetfreeExemptUser;
use app\lib\models\User;
use app\lib\models\WxConfig;

class KuaiShangPay
{
    protected $appId;
    protected $corpId;
    protected $secret;
    protected $domain;
    protected $tokenUrl;
    protected $ticketUrl;

    public function __construct()
    {
        $config = config('system.kuaishang');
        if (empty($config)) throw new ServiceException();
        $this->appId = $config['appId'];
        $this->secret = $config['secret'];
        $this->corpId = $config['corpId'];
        $this->domain = $config['domain'];
        $this->tokenUrl = $config['tokenUrl'];
        $this->ticketUrl = $config['ticketUrl'];
    }

    /**
     * @title  用户签约详情
     * @param array $data
     * @return mixed
     */
    public function contractInfo(array $data)
    {
        $uid = $data['uid'] ?? null;
        //是否需要判断用户是否签约
        $needVerify = $data['needVerify'] ?? false;
        if (empty($uid)) {
            return false;
        }
        //是否需要优先判断用户的关联帐号信息
        $needCheckRelatedUser = $data['needCheckRelatedUser'] ?? false;
        $uid = $data['uid'];
        if (!empty($needCheckRelatedUser)) {
            $relatedUser = User::where(['uid' => $data['uid'], 'status' => 1])->value('related_user');
            if (!empty($relatedUser)) {
                $uid = $relatedUser;
            }else{
                throw new ServiceException(['msg' => '请您前往小程序签约~']);
            }
        }
        //查询用户是否为用户, 主要是为了兼容线下签约用户, 因为线下签约用户无法跟通过查询接口同步数据
        $checkExemptUser = LetfreeExemptUser::where(['uid' => $uid, 'status' => 1, 'type' => 1])->findOrEmpty()->toArray();
        if (!empty($checkExemptUser)) {
            $userInfo = User::where(['uid' => $data['uid'], 'status' => 1])->field('real_name,withdraw_bank_card')->findOrEmpty()->toArray();
            $hideData['username'] = $checkExemptUser['real_name'] ?? ($userInfo['real_name'] ?? null);
            $hideData['status'] = 2;
            $hideData['updatedTime'] = null;
            $hideData['bankcard'] = '';
            if (!empty($userInfo['withdraw_bank_card'] ?? null)) {
                $hideData['bankcard'] = $userInfo['withdraw_bank_card'];
            }
            $finallyData['returnData'] = $hideData;
            $finallyData['verifyRes'] = true;
            $finallyData['related_user'] = null;
            return $finallyData;
        }

        $accessToken = $this->getAccessToken();
        $ticket = $this->getTicket();
        $requestData['thirdId'] = $uid;
        $requestData['corpId'] = $this->corpId;
        $requestData['sign'] = $this->buildSign($requestData, $ticket);
        $urlData = http_build_query($requestData);
        $url = $this->domain . '/api/v1/contract-api/check?' . $urlData;
        //请求头
        $header[] = "hp-token:" . $accessToken;
        $res = $this->httpRequest($url, "", $header);

        if (empty($res)) {
            $log['data'] = $data;
            $log['requestData'] = $requestData;
            $log['returnRes'] = $res ?? [];
            $this->log($log, 'error');
            throw new ServiceException(['msg' => '三方服务有误']);
        }

        $resData = json_decode($res, true);
        if (empty($resData) || $resData['code'] != 0) {
            $log['data'] = $data;
            $log['requestData'] = $requestData;
            $log['returnRes'] = $res ?? [];
            $log['returnData'] = $resData ?? [];
            $this->log($log, 'error');
            //若出现报错找不到该用户id的情况尝试查找关联用户
            if (!empty($resData) && !empty($resData['code']) && $resData['code'] == 400100 && empty($needCheckRelatedUser)) {
                $data['needCheckRelatedUser'] = true;
                return $this->contractInfo($data);
            }
            throw new ServiceException(['msg' => ($resData['message'] ?? '三方服务有误')]);
        }

        $returnData = $resData['data'];
        $verifyRes = null;
        if (!empty($needVerify)) {
            if ($returnData['status'] == 2) {
                $verifyRes = true;
                //补充用户真实姓名信息
                if (!empty($returnData['username'] ?? null)) {
                    $userRealName = User::where(['uid' => $data['uid'],'status'=>1])->value('real_name');
                    if (empty($userRealName)) {
                        User::update(['real_name' => trim($returnData['username'])], ['uid' => $data['uid']]);
                    }
                }
            } else {
                $verifyRes = false;
            }
        }
        //隐藏用户个人身份关键信息, 防止信息泄露
        $userWithdrawBankCard = User::where(['uid' => $data['uid'], 'status' => 1])->value('withdraw_bank_card');
        if (!empty($userWithdrawBankCard)) {
            $hideData['bankcard'] = $userWithdrawBankCard;
        } else {
            $hideData['bankcard'] = $returnData['bankcard'] ?? null;
        }

        $hideData['username'] = $returnData['username'] ?? null;
        $hideData['status'] = $returnData['status'] ?? null;
        $hideData['updatedTime'] = $returnData['updatedTime'] ?? null;

        if (!empty($returnData)) {
            $returnData['idcard'] = '用户隐私禁止查看';
            $returnData['mobile'] = '用户隐私禁止查看';
//            $returnData['username'] = '用户隐私禁止查看';
            $returnData['idface'] = '用户隐私禁止查看';
            $returnData['idreverse'] = '用户隐私禁止查看';
        }
        $returnData = $hideData;

        $finallyData['returnData'] = $returnData;
        $finallyData['verifyRes'] = $verifyRes;
        $finallyData['related_user'] = null;
        if (!empty($needCheckRelatedUser)) {
            $finallyData['related_user'] = $relatedUser ?? null;
        }
        return $finallyData;
    }

    /**
     * @title  获取AccessToken
     * @return mixed
     */
    public function getAccessToken()
    {
        $aAccessInfo = KsConfig::where(['type' => 1])->findOrEmpty()->toArray();
        $timestamp = $this->getMillisecond();
        $url = $this->domain . sprintf($this->tokenUrl, $timestamp);

        $requestData['app_id'] = $this->appId;
        $requestData['secret'] = $this->secret;
        $requestData['grant_type'] = 'client_credential';

        if (empty($aAccessInfo)) {
            $res = $this->httpRequest($url, $requestData);
            $data = json_decode($res, true);
            if (empty($data) || $data['code'] != 0) {
                throw new ServiceException(['msg' => '三方服务有误']);
            }
            $returnData = $data['data'];
            $aInfo['config'] = $returnData['access_token'];
            $aInfo['type'] = 1;
            $aInfo['timeout_time'] = substr($returnData['expire_time'], 0, 10);
            KsConfig::create($aInfo);
            $accessToken = $returnData['access_token'];
        } else {
            if ($aAccessInfo['timeout_time'] < time()) {
                $res = $this->httpRequest($url,$requestData);
                $data = json_decode($res, true);
                if (empty($data) || $data['code'] != 0) {
                    throw new ServiceException(['msg' => '三方服务有误']);
                }
                $returnData = $data['data'];
                $aInfo['config'] = $returnData['access_token'];
                $aInfo['type'] = 1;
                $aInfo['timeout_time'] = substr($returnData['expire_time'], 0, 10);
                KsConfig::update($aInfo, ['type' => 1]);
                $accessToken = $returnData['access_token'];
            } else {
                $accessToken = $aAccessInfo['config'];
            }
        }

        return $accessToken;
    }

    /**
     * @title  清空签约信息
     * @param array $data
     * @return bool
     */
    public function removeThirdId(array $data)
    {
//        $data['needVerify'] = true;
//        $check = $this->contractInfo($data);
//        if (empty($check) || (!empty($check) && empty($check['verifyRes'] ?? null))) {
//            throw new ServiceException(['msg' => '该用户尚未签约或三房服务查询有误']);
//        }
        $accessToken = $this->getAccessToken();
        $ticket = $this->getTicket();
        $requestData['thirdId'] = $data['uid'];
        $requestData['corpId'] = $this->corpId;
        $requestData['sign'] = $this->buildSign($requestData, $ticket);
        $urlData = http_build_query($requestData);
        $url = $this->domain . '/api/v2/signApi/setThirdIdNull?' . $urlData;
        //请求头
        $header[] = "hp-token:" . $accessToken;
        $res = $this->httpRequest($url, "", $header);

        if (empty($res)) {
            $log['data'] = $data;
            $log['requestData'] = $requestData;
            $log['returnRes'] = $res ?? [];
            $this->log($log, 'error');
            throw new ServiceException(['msg' => '三方服务有误']);
        }

        $resData = json_decode($res, true);
        if (empty($resData) || $resData['code'] != 0) {
            $log['data'] = $data;
            $log['requestData'] = $requestData;
            $log['returnRes'] = $res ?? [];
            $log['returnData'] = $resData ?? [];
            $this->log($log, 'error');
            throw new ServiceException(['msg' => ($resData['message'] ?? '三方服务有误')]);
        }

        return true;
    }

    /**
     * @title  获取Ticket
     * @return string
     */
    public function getTicket()
    {
        $accessToken = $this->getAccessToken();
        $aTicketInfo = KsConfig::where(['type' => 2])->findOrEmpty()->toArray();
        $timestamp = $this->getMillisecond();
        $url = $this->domain . sprintf($this->ticketUrl, $timestamp);


        //请求头
        $header[] = "hp-token:" . $accessToken;

        if (empty($aTicketInfo)) {
            $res = $this->httpRequest($url, "", $header);
            $data = json_decode($res, true);
            if (empty($data) || $data['code'] != 0) {
                throw new ServiceException(['msg' => '三方服务有误']);
            }
            $returnData = $data['data'];
            $aInfo['config'] = $returnData['ticket'];
            $aInfo['type'] = 2;
            $aInfo['timeout_time'] = substr($returnData['expire_time'], 0, 10);
            KsConfig::create($aInfo);
            $ticket = $returnData['ticket'];
        } else {
            if ($aTicketInfo['timeout_time'] < time()) {
                $res = $this->httpRequest($url, "", $header);
                $data = json_decode($res, true);
                if (empty($data) || $data['code'] != 0) {
                    throw new ServiceException(['msg' => '三方服务有误']);
                }
                $returnData = $data['data'];
                $aInfo['config'] = $returnData['ticket'];
                $aInfo['type'] = 2;
                $aInfo['timeout_time'] = substr($returnData['expire_time'], 0, 10);
                KsConfig::update($aInfo, ['type' => 2]);
                $ticket = $returnData['ticket'];
            } else {
                $ticket = $aTicketInfo['config'];
            }
        }
        return $ticket;
    }

    /**
     * @title  curl请求
     * @param string $url 请求地址
     * @param string $data
     * @return bool|string
     */
    private function httpRequest(string $url, $data = "", $header = false)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        $headers = array(
            "Content-type: application/json;charset='utf-8'",
        );
        if(!empty($header)){
            $headers = array_merge_recursive($headers,$header);
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        if (!empty($data)) { //判断是否为POST请求
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }

    /**
     * @title  获取毫秒时间戳
     * @return float
     */
    private function getMillisecond() {

        list($t1, $t2) = explode(' ', microtime());

        return (float)sprintf('%.0f',(floatval($t1)+floatval($t2))*1000);

    }

    private function arrayKsort($arr){
        $newarr = [];
        foreach($arr as $key=>$item){
            if(is_array($item)){
                $item = $this->arrayKsort($item);
            }
            $newarr[$key] = $item;
        }
        ksort($newarr);
        return $newarr;
    }

    /**
     * @title  签名算法生成
     * @param $args
     * @param $ticket
     * @return string
     */
    private function buildSign($args, $ticket){
        $arr = [];
        foreach ($args as $key => $value) {
            if (!is_null($value)) {
                $arr[$key] = $value;
            }
        }
        $arr = $this->arrayKsort($arr);
        $arr = json_encode($arr, 320);
        $signature = strtoupper(sha1($ticket . $arr));
        return $signature;
    }

    /**
     * @title  日志记录
     * @param array $data 记录信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'info')
    {
        $res = (new Log())->setChannel('kuaishang')->record($data, $level);
        return $res;
    }
}