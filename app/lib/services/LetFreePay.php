<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 乐小活模块Service]
// +----------------------------------------------------------------------



namespace app\lib\services;


use app\lib\exceptions\ServiceException;
use app\lib\models\LetfreeExemptUser;
use app\lib\models\LetfreeUser;
use app\lib\models\User;

class LetFreePay
{
    protected $appId;
    protected $corpId;
    protected $secret;
    protected $domain;
    protected $appCode;
    protected $contractTemplateCode;

    public function __construct()
    {
        $config = config('system.letfree');
        if (empty($config)) throw new ServiceException();
        $this->appId = $config['appId'];
        $this->secret = $config['secret'];
        $this->domain = $config['domain'];
        $this->contractTemplateCode = $config['contractTemplateCode'];
        $this->appCode = $config['appCode'];
    }

    /**
     * @title  获取签约地址
     * @param array $data
     * @return mixed
     */
    public function getContractUrl(array $data)
    {
        $uid = $data['uid'] ?? null;
        if (empty($uid)) {
            return false;
        }
        $existLetFreeUser = LetfreeUser::where(['uid' => $uid, 'user_phone' => $data['user_phone'], 'status' => 1])->findOrEmpty()->toArray();
        if (!empty($existLetFreeUser) && !empty($existLetFreeUser['letfree_contract_url'] ?? null) && !empty($existLetFreeUser['letfree_contract_code'] ?? null)) {
            $returnData['contract_url'] = $existLetFreeUser['letfree_contract_url'];
            $returnData['contract_code'] = $existLetFreeUser['letfree_contract_code'];
            return $returnData;
        }
        $requestModule = '/ti-open-api/staffCompany/contract/applySign';
        $requestData['contractTemplateCode'] = $this->contractTemplateCode;
        $requestData['freelancerName'] = $data['real_name'];
        $requestData['freelancerMobile'] = $data['user_phone'];
        //公共参数
        $requestData['timestamp'] = $this->getMillisecond();
        $requestData['appCode'] = $this->appCode;
        $requestData['version'] = 'v1';
        $requestData['token'] = $this->buildSign($requestData, $requestModule);
        $url = $this->domain . $requestModule;
        $res = $this->httpRequest($url, $requestData);

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
            throw new ServiceException(['msg' => ($resData['description'] ?? '三方服务有误'), 'errorCode' => $resData['code']]);
        } else {
            $log['requestUrl'] = $url;
            $log['requestData'] = $requestData;
            $log['resData'] = $resData;
            $this->log($log, 'info');
        }

        //记录用户签约数据
        $createNewUser['letfree_contract_code'] = $resData['data']['contractCode'];
        $createNewUser['letfree_contract_url'] = $resData['data']['contractSignUrl'];
        $createNewUser['uid'] = $uid;
        $createNewUser['real_name'] = $data['real_name'];
        $createNewUser['user_phone'] = $data['user_phone'];
        $createNewUser['bank_card'] = $resData['bank_card'] ?? null;
        LetfreeUser::create($createNewUser);

        $returnData['contract_url'] = $resData['data']['contractSignUrl'];
        $returnData['contract_code'] = $resData['data']['contractCode'];
        return $returnData;
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
            } else {
                throw new ServiceException(['msg' => '请您前往小程序签约~']);
            }
        }

        //查询用户是否为免签用户, 主要是为了兼容线下签约用户, 因为线下签约用户无法跟通过查询接口同步数据
        $checkExemptUser = LetfreeExemptUser::where(['uid' => $uid, 'status' => 1, 'type' => 2])->findOrEmpty()->toArray();
        if (!empty($checkExemptUser ?? [])) {
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
        $userContractInfo = LetfreeUser::where(['uid' => $uid, 'status' => 1])->field('letfree_contract_code,letfree_contract_url')->findOrEmpty()->toArray();
        $contractCode = $userContractInfo['letfree_contract_code'] ?? null;
        if (empty($contractCode)) {
            $finallyData['returnData'] = [];
            $finallyData['verifyRes'] = false;
            $finallyData['related_user'] = null;
            return $finallyData;
        }

        $requestModule = '/ti-open-api/staffCompany/contract/info';
        $requestData['contractCode'] = $contractCode;

        //公共参数
        $requestData['timestamp'] = $this->getMillisecond();
        $requestData['appCode'] = $this->appCode;
        $requestData['version'] = 'v1';
        $requestData['token'] = $this->buildSign($requestData, $requestModule);
        $url = $this->domain . $requestModule;
        $res = $this->httpRequest($url, $requestData);

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
        } else {
            $log['requestUrl'] = $url;
            $log['requestData'] = $requestData;
            $log['resData'] = $resData;
            $this->log($log, 'info');
        }

        $returnData = $resData['data'];
        $verifyRes = null;

        if (!empty($needVerify)) {
            if ($returnData['freelancerSignStatus'] == 40) {
                $verifyRes = true;
                //补充用户真实姓名信息及合同编码
                if (!empty($returnData['freelancerName'] ?? null)) {
                    $userRealName = User::where(['uid' => $data['uid'], 'status' => 1])->value('real_name');
                    if (empty($userRealName)) {
                        User::update(['real_name' => trim($returnData['freelancerName'])], ['uid' => $data['uid']]);
                        LetfreeUser::update(['real_name' => trim($returnData['freelancerName'])], ['uid' => $data['uid']]);
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

        $hideData['username'] = $returnData['freelancerName'] ?? null;
        if ($returnData['freelancerSignStatus'] != 40) {
            $hideData['status'] = 1;
        }
        if ($returnData['freelancerSignStatus'] == 40 && $returnData['contractStatus'] != 400) {
            $hideData['status'] = 3;
        }
        if ($returnData['freelancerSignStatus'] == 40 && $returnData['contractStatus'] == 400) {
            $hideData['status'] = 2;
        }

//        $hideData['status'] = $returnData['freelancerSignStatus'] ?? null;
        $hideData['updatedTime'] = $returnData['updatedTime'] ?? null;
        $hideData['contractStatus'] = $returnData['contractStatus'] ?? null;
        $hideData['contractUrl'] = $userContractInfo['letfree_contract_url'] ?? null;
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
     * @title  签名算法生成
     * @param $args
     * @param $ticket
     * @return string
     */
    private function buildSign($args, $ticket)
    {
        $arr = [];
        foreach ($args as $key => $value) {
            if (!is_null($value) || ($key != 'token')) {
                $arr[$key] = $value;
            }
        }
        $arr = $this->arrayKsort($arr);
        $sArr = '';
        foreach ($arr as $key => $value) {
            $sArr .= ($key) . '=' . $value;
        }
        $signature = sha1(strtolower(md5($sArr . $ticket)) . $this->secret);
        return $signature;
    }

    /**
     * @title  数组排序, 根据ASCII码表的顺序排序
     * @param $arr
     * @return array
     */
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
     * @title  获取毫秒时间戳
     * @return float
     */
    private function getMillisecond() {

        list($t1, $t2) = explode(' ', microtime());

        return (float)sprintf('%.0f',(floatval($t1)+floatval($t2))*1000);

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
     * @title  日志记录
     * @param array $data 记录信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'info')
    {
        $res = (new Log())->setChannel('letfree')->record($data, $level);
        return $res;
    }
}