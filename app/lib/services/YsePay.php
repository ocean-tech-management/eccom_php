<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 杉德支付模块Service,统一规范请继承IPay接口类]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------

// | 银盛支付文档地址:https://www.yuque.com/bgu2ty/eqt/gz7ei1
// +----------------------------------------------------------------------

namespace app\lib\services;

use app\lib\BaseException;
use app\lib\exceptions\YsePayException;
use app\lib\interfaces\IPay;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\Order;
use app\lib\models\Withdraw;
use think\Exception;
use think\facade\Request;

class YsePay implements IPay
{
    protected $customConfig;
    protected $weChatAppId;
    protected $MerchantNo;
    protected $secret;
    protected $uniPayType;
    protected $wxConfig;
    private static $_CAINFO;
    private $privateKeyPath;
    private $publicKeyPath;
    private $privateKeyPwd;
    private $yseDomain;

    public function __construct()
    {
        $config = config('system.ysePay');
        if (!empty($this->customConfig)) {
            $config = $this->customConfig;
        }
        $access_key = getAccessKey();
        $wxConfig = config("system.clientConfig.$access_key");
        $this->wxConfig = $wxConfig;
        // $wxConfig = config('system.weChat');
        if (empty($config)) throw new YsePayException();
        $this->MerchantNo = $config['MerchantNo'];
        $this->desKey = $config['des_key'];
        $this->weChatAppId = $wxConfig['app_id'];
        switch ($access_key) {
            case strstr($access_key, 'p'):
                $this->setUniPayType('28');
                break;
            case strstr($access_key, 'm'):
                $this->setUniPayType('29');
                break;
            default:
                $this->setUniPayType('notWxPay');
                break;
        }
        $this->privateKeyPath = $config['privateKeyPath'];
        $this->publicKeyPath = $config['publicKeyPath'];
        $this->privateKeyPwd = $config['privateKeyPwd'];
        self::$_CAINFO = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'ca' . DIRECTORY_SEPARATOR . 'cacert.pem';
//        $this->yseDomain = 'https://eqt.ysepay.com/api/merchant';
        $this->yseDomain = 'https://eqt.ysepay.com/api/trade';
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
     * @title  设置聚合支付交易类型
     * @param string $typeName
     * @return void
     */
    public function setUniPayType(string $typeName)
    {
        $this->uniPayType = $typeName;
    }

    /**
     * @title  下单
     * @param array $data
     * @return mixed|void
     */
    public function order(array $data)
    {
        //不同类型的支付参数有所不同,请参考银盛支付-微信小程序/公众号支付文档,现在默认是小程序支付
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'jsPay';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $parametersData["requestNo"] = $data['out_trade_no'];
        $parametersData["payeeMerchantNo"] = $this->MerchantNo;
        $parametersData["bankType"] = "1902000";
        $parametersData["payMode"] = $this->uniPayType;
        $parametersData["wxAppId"] = $this->weChatAppId;
        $parametersData["wxOpenId"] =  $data['openid'];
        $parametersData['notifyUrl'] = $data['notify_url'] ?? config('system.callback.ysePayCallBackUrl');
        $parametersData["amount"] = priceFormat($data['total_fee']);
        $parametersData["orderDesc"] = $data['attach'] ?? config('system.projectName') . '自营商城订单';
        $parametersData["remark"] = $data['body'] ?? '商城订单';
        if (!empty($data['map'])) {
            $parametersData['extendParams'] = trim($data['map']);  //公共回传参数,在回调的时候会返回
        }

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        //记录日志
        $this->log(['type' => 'orderPay', 'param' => $parameters, 'returnData' => $requestRes]);

        $payData = [];
        if (!empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004']) && in_array($requestResData['state'], ['SUCCESS','PROCESSING'])) {
                $payData = json_decode($requestResData['payData'], true);
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '创建支付单失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['msg'] ?? '创建支付单失败, 请稍后重试';
        }

        if (empty($payData ?? [])) {
            $msg = $errorMsg ?? '支付服务有误';
            $this->log(['type' => 'failOrderPay', 'msg' => $msg, 'param' => $parameters, 'returnData' => $requestRes], 'error');
            throw new YsePayException(['msg' => $msg]);
        }

        return $payData;
    }

    /**
     * @title  支付对账单
     * @param string $out_trade_no 商户订单号
     * @return mixed|void
     */
    public function orderQuery(string $out_trade_no)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'queryUnifyOrder';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $parametersData["requestNo"] = $out_trade_no;

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $message['status'] = 40010;
        $message['message'] = '支付对单失败';

        $res = false;
        if (!empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004']) && in_array($requestResData['state'], ['SUCCESS'])) {
                $res = true;
                $message['status'] = 100;
                $message['message'] = '支付对单成功';
                $message['result'] = $requestRes['bizResponseJson'];
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '订单校验失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['msg'] ?? '订单校验失败, 请稍后重试';
        }


        //记录日志
        $this->log(['type' => 'orderQuery', 'msg' => $message, 'param' => $parameters, 'returnData' => $requestRes]);

        return $message;
    }

    /**
     * @title  协议支付支付对账单
     * @param string $out_trade_no 商户订单号
     * @return mixed|void
     */
    public function agreementOrderQuery(string $out_trade_no)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'queryUnifyOrder';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $parametersData["requestNo"] = $out_trade_no;

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $message['status'] = 40010;
        $message['message'] = '支付对单失败';

        $res = false;
        if (!empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004']) && in_array($requestResData['state'], ['SUCCESS'])) {
                $res = true;
                $message['status'] = 100;
                $message['message'] = '支付对单成功';
                $message['result'] = $requestRes['bizResponseJson'];
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '订单校验失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['msg'] ?? '订单校验失败, 请稍后重试';
        }

        return $message;
    }

    /**
     * @title  退款
     * @param array $data
     * @return mixed|void
     */
    public function refund(array $data)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'refund';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $parametersData["requestNo"] =  trim($data['out_refund_no']);
        $parametersData["origRequestNo"] = trim($data['out_trade_no']);
        $parametersData["origTradeSn"] = trim($data['pay_no']);
        $parametersData["amount"] = priceFormat($data['refund_fee']);
        //退款资金来源，不传值则默认02 01-一般消费类账户 （原订单收款方） 02-待结算账户（原订单收款方当天收单金额）03-发起方一般消费类账户（发起方）
        $parametersData["refundSource"] = '02';
        //原交易订单是否分账 Y-是、N-否 默认没有分账
        $parametersData["isDivision"] = 'N';
        $parametersData["notifyUrl"] = $data['notify_url'] ?? config('system.callback.ysePayRefundCallBackUrl');
        $parametersData["reason"] = $data['refund_desc'] ?? '商城退款';

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $res = false;
        if (!empty($requestRes) && !empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004']) && in_array($requestResData['state'], ['PROCESSING', 'SUCCESS'])) {
                $res = true;
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '退款发起失败, 请稍后重试';
            }

        } else {
            $errorMsg = $requestRes['msg'] ?? '退款发起失败, 请稍后重试';
        }

        //记录日志
        $this->log(['type' => 'refund', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        if (!$res) {
            if (empty($data['notThrowError'] ?? false)) {
                throw new YsePayException(['msg' => $errorMsg ?? '服务有误']);
            }
        }

        return $res;
    }

    /**
     * @title  退款对账单
     * @param string $out_refund_no 商户退款单号
     * @return mixed|void
     */
    public function refundQuery(string $out_refund_no)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'queryTradeRefund';
        $headParam = $this->getPublicHeadParam($buildHeadParam);
        //退款单号
        $parametersData["requestNo"] = $out_refund_no;

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $message['status'] = 40010;
        $message['message'] = '退款对单失败';

        if (!empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004']) && in_array($requestResData['state'], ['SUCCESS'])) {
                $res = true;
                $message['status'] = 100;
                $message['message'] = '退款对单成功';
                $message['result'] = $requestRes['bizResponseJson'];
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '订单校验失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['msg'] ?? '订单校验失败, 请稍后重试';
        }

        //记录日志
        $this->log(['type' => 'refundQuery', 'msg' => $message, 'param' => $parameters, 'returnData' => $requestRes]);

        return $message;
    }

    /**
     * @title  协议(银行卡)支付退款对账单
     * @param string $out_refund_no 商户退款单号
     * @return mixed|void
     */
    public function agreementRefundQuery(string $out_refund_no)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'queryTradeRefund';
        $headParam = $this->getPublicHeadParam($buildHeadParam);
        //退款单号
        $parametersData["requestNo"] = $out_refund_no;

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $message['status'] = 40010;
        $message['message'] = '退款对单失败';

        $res = false;
        if (!empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004']) && in_array($requestResData['state'], ['SUCCESS'])) {
                $res = true;
                $message['status'] = 100;
                $message['message'] = '退款对单成功';
                $message['result'] = $requestRes['bizResponseJson'];
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '订单校验失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['msg'] ?? '订单校验失败, 请稍后重试';
        }

        return $message;
    }

    /**
     * @title  企业付款到用户银行帐号
     * @param array $data
     * @throws
     * @return mixed
     */
    public function enterprisePayment(array $data)
    {
        throw new YsePayException(['msg' => '功能暂无法使用']);
        //检测可用余额
        $accountInfo = $this->accountBalanceQuery();
//        $useAbleAmount = doubleval($accountInfo['useAbleSettAmount'] ?? 0);
//        if ($useAbleAmount <= 1 || $useAbleAmount < $data['amount']) {
//            throw new YsePayException(['errorCode' => 29004]);
//        }

        $requestUrl = 'https://caspay.sandpay.com.cn/agent-main/openapi/agentpay';

        $parametersData["version"] = '01';
        $parametersData["productId"] = '00000004';
//        $parametersData["tranTime"] = date('YmdHis',(Withdraw::where(['order_sn' => $data['partner_trade_no']])->value('check_time')));
        $parametersData["tranTime"] = date('YmdHis',time());
        $parametersData["orderCode"] = trim($data['partner_trade_no']);
        $parametersData["tranAmt"] = sprintf("%012d",($data['amount'] * 100));
        $parametersData["currencyCode"] =  "156";
        $parametersData["accAttr"] = "0";
        $parametersData["accType"] = "4";
        $parametersData["accNo"] = $data['bank_account'];
        $parametersData["accName"] = $data['re_user_name'];
        $parametersData["remark"] = $data['desc'] ?? '企业付款';


        cache('ysePayPaymentOrderInfo-' . $parametersData["orderCode"], $parametersData, 600);

        // step2: 生成AESKey并使用公钥加密
        $AESKey = getUid(16);
        $pubKey = $this->publicKey($this->publicKeyPath);
        $priKey = $this->privateKey($this->privateKeyPath, $this->privateKeyPwd);
        $encryptKey = $this->encryptRSA($AESKey, $pubKey);
        // step3: 使用AESKey加密报文
        $encryptData = $this->encryptECB(json_encode($parametersData,256), $AESKey);
        // step4: 使用私钥签名报文
        $sign = $this->sign($parametersData, $priKey);
        // step5: 拼接post数据

        $parameters['transCode'] = "RTPM";
        $parameters['accessType'] = "0";
        $parameters['merId'] = $this->MerchantNo;

        $parameters['sign'] = $sign;
        $parameters['encryptKey'] = $encryptKey ;
        $parameters['encryptData'] = $encryptData;
        $requestRes = $this->http_post_json($requestUrl, $parameters,true);

        //解析返回数据
        $requestRes = $this->parseResult($requestRes);
        //使用私钥解密AESKey
        $decryptAESKey = $this->decryptRSA($requestRes['encryptKey'], $priKey);
        //使用解密后的AESKey解密报文
        $decryptPlainText = $this->decryptECB($requestRes['encryptData'], $decryptAESKey);

        //使用公钥验签报文
        if (empty($this->verifyRSA($decryptPlainText, $requestRes['sign'], $pubKey))) {
            throw new YsePayException(['msg' => '验签失败!数据可能存在异常']);
        }
        $requestRes = json_decode($decryptPlainText,true);
        $res = false;
        if ($requestRes['respCode'] == "0000") {
            $msg = '受理成功';
            $res = true;
        } else {
            $msg = $requestRes['data']['respCode'] ?? ($requestRes['message'] ?? '支付服务有误,请间隔十分钟后重新尝试');
            throw new YsePayException(['msg' => $msg]);
        }
        //记录日志
        $this->log(['type' => 'payment', 'msg' => $msg, 'param' => $parameters, 'returnData' => $requestRes]);

        return $res;
    }

    /**
     * @title  代付对账单
     * @param string $out_trade_no 商户订单号
     * @return mixed
     */
    public function paymentQuery(string $out_trade_no)
    {
        $requestUrl = 'https://caspay.sandpay.com.cn/agent-main/openapi/queryOrder';

        $parametersData["version"] = '01';
        $parametersData["productId"] = '00000004';
        $parametersData["orderCode"] = trim($out_trade_no);
        $parametersData["tranTime"] = date('YmdHis',(Withdraw::where(['order_sn'=>$out_trade_no])->value('check_time')));

        // step2: 生成AESKey并使用公钥加密
        $AESKey = getUid(16);
        $pubKey = $this->publicKey($this->publicKeyPath);
        $priKey = $this->privateKey($this->privateKeyPath, $this->privateKeyPwd);
        $encryptKey = $this->encryptRSA($AESKey, $pubKey);
        // step3: 使用AESKey加密报文
        $encryptData = $this->encryptECB(json_encode($parametersData,256), $AESKey);
        // step4: 使用私钥签名报文
        $sign = $this->sign($parametersData, $priKey);
        // step5: 拼接post数据

        $parameters['transCode'] = "ODQU";
        $parameters['accessType'] = "0";
        $parameters['merId'] = $this->MerchantNo;

        $parameters['sign'] = $sign;
        $parameters['encryptKey'] = $encryptKey ;
        $parameters['encryptData'] = $encryptData;

        $requestRes = $this->httpRequest($requestUrl, $parameters,true);

        //解析返回数据
        $requestRes = $this->parseResult($requestRes);
        //使用私钥解密AESKey
        $decryptAESKey = $this->decryptRSA($requestRes['encryptKey'], $priKey);
        //使用解密后的AESKey解密报文
        $decryptPlainText = $this->decryptECB($requestRes['encryptData'], $decryptAESKey);

        //使用公钥验签报文
        if (empty($this->verifyRSA($decryptPlainText, $requestRes['sign'], $pubKey))) {
            throw new YsePayException(['msg' => '验签失败!数据可能存在异常']);
        }

        $requestRes = json_decode($decryptPlainText,true);

        if (empty($requestRes)) {
            return json(['statusCode' => 2002, 'message' => '对账单失败, 查无数据']);
        }
        $message['status'] = 40010;
        $message['message'] = '代付对单失败';

        if ($requestRes['respCode'] == "0000") {
            if ($requestRes['data']['resultFlag'] == 0) {
                $message['status'] = 100;
                $message['message'] = '代付对单成功';
                $message['result'] = $requestRes;
            }
        }

        //记录日志
        $this->log(['type' => 'paymentQuery', 'msg' => $message, 'param' => $parameters, 'returnData' => $requestRes]);

        return $message;
    }

    /**
     * @title  可取余额查询
     * @return array
     * @throws
     */
    public function accountBalanceQuery()
    {
        $requestUrl = 'https://caspay.sandpay.com.cn/agent-main/openapi/queryBalance';

        $parametersData["version"] = '01';
        $parametersData["productId"] = '00000004';
        $parametersData["tranTime"] = date('YmdHis',time());
        $parametersData["orderCode"] = (new CodeBuilder())->buildOrderNo();

        // step2: 生成AESKey并使用公钥加密
        $AESKey = getUid(16);
        $pubKey = $this->publicKey($this->publicKeyPath);
        $priKey = $this->privateKey($this->privateKeyPath, $this->privateKeyPwd);

        $encryptKey = $this->encryptRSA($AESKey, $pubKey);
        // step3: 使用AESKey加密报文
        $encryptData = $this->encryptECB(json_encode($parametersData,256), $AESKey);
        // step4: 使用私钥签名报文
        $sign = $this->sign($parametersData, $priKey);
        // step5: 拼接post数据

        $parameters['transCode'] = "MBQU";
        $parameters['accessType'] = "0";
        $parameters['merId'] = $this->MerchantNo;

        $parameters['sign'] = $sign;
        $parameters['encryptKey'] = $encryptKey ;
        $parameters['encryptData'] = $encryptData;

        $requestRes = $this->httpRequest($requestUrl, $parameters,true);

        //解析返回数据
        $requestRes = $this->parseResult($requestRes);
        //使用私钥解密AESKey
        $decryptAESKey = $this->decryptRSA($requestRes['encryptKey'], $priKey);
        //使用解密后的AESKey解密报文
        $decryptPlainText = $this->decryptECB($requestRes['encryptData'], $decryptAESKey);

        //使用公钥验签报文
        if (empty($this->verifyRSA($decryptPlainText, $requestRes['sign'], $pubKey))) {
            throw new YsePayException(['msg' => '验签失败!数据可能存在异常']);
        }

        $requestRes = json_decode($decryptPlainText,true);
        $data = [];
        if ($requestRes['respCode'] == "0000") {
            $msg = '查询成功';
            $requestResData = $requestRes;
            $data['useAbleSettAmount'] = priceFormat(intval($requestResData['creditAmt'] ?? 0) / 100);  //除去风控冻结金额剩余的可结算金额
            $data['totalAmount'] = priceFormat(intval($requestResData['balance'] ?? 0) / 100);  //总余额
        } else {
            $msg = $requestRes['respDesc'] ?? ($requestRes['message'] ?? '支付服务有误,请间隔十分钟后重新尝试');
            throw new YsePayException(['msg' => $msg]);
        }

        //记录日志
        $this->log(['type' => 'BalanceQuery', 'msg' => $msg, 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        return $data;
    }

    /**
     * @title  可用垫资余额查询
     * @return array
     */
    public function advanceAccountBalanceQuery()
    {
        $requestUrl = 'https://www.joinpay.com/payment/pay/advanceAccountBalanceResultQuery';
        $parameters['userNo'] = $this->MerchantNo;
        $parameters['hmac'] = $this->buildSign($parameters);
        $requestRes = $this->httpRequest($requestUrl, $parameters, true);
        $requestRes = json_decode($requestRes, 1);

        $data = [];
        if ($requestRes['statusCode'] == 2001) {
            $msg = '查询成功';
            $requestResData = $requestRes['data'];
            $data['currency'] = $requestResData['currency'];  //币种201为人民币
            $data['useAbleAdvanceAmount'] = $requestResData['useAbleAdvanceAmount'] ?? 0;  //商户可用垫资额度
            $data['currentAdvanceAmount'] = $requestResData['currentAdvanceAmount'];  //商户当日垫资额度
            $data['usedAdvanceAmount'] = $requestResData['usedAdvanceAmount'];        //商户已用垫资额度
            $data['advanceFrozenAmount'] = $requestResData['advanceFrozenAmount'];    //代付处理中的金额
        } else {
            $msg = $requestRes['data']['errorDesc'] ?? ($requestRes['message'] ?? '支付服务有误,请间隔十分钟后重新尝试');
            throw new YsePayException(['msg' => $msg]);
        }

        //记录日志
        $this->log(['type' => 'advanceBalanceQuery', 'msg' => $msg, 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        return $data;
    }

    /**
     * @title  关闭订单
     * @param array $data
     * @return mixed
     */
    public function closeOrder(array $data)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'closeOrder';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $parametersData['payeeMerchantNo'] = $this->MerchantNo;
        $parametersData['requestNo'] = trim($data['order_sn']);

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $res = false;
        $message['status'] = 40010;
        $message['message'] = '关闭订单失败';

        if (!empty($requestRes) && !empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004', '0000'])) {
                $res = true;
                $message['status'] = 100;
                $message['message'] = '关闭订单成功';
                $message['result'] = $requestRes;
            } else {
                //关单失败中也有一些情况是允许返回成功的,比如没有正确创建订单的状态
                if (!empty($requestResData['subCode'] ?? null)) {
                    switch ($requestResData['subCode']) {
                        case "B023035":
                            $message['status'] = 100;
                            $message['message'] = '非已创建状态或订单已关闭，无需关单操作';
                            $message['result'] = $requestRes;
                            break;
                        default:
                            break;
                    }
                }
            }
        }

        //记录日志
        $this->log(['type' => 'yse-closeOrder', 'msg' => $message, 'param' => $parameters, 'returnData' => $requestRes]);

        return $message;
    }

    /**
     * @title  生成签名
     * @param array $param
     * @param int $type 签名生成类型 1为md5,2为RAS, 3为根据私钥
     * @return string|null
     * @throws
     */
    public function buildSign(array $param, int $type = 1)
    {
        if ($type == 1) {
            $sign = null;
            foreach ($param as $key => $value) {
                if (!empty($value)) {
                    $sign .= $value;
                }
            }

            if (!empty($sign)) {
                $sign .= $this->secret;
                $sign = md5($sign);
            }

            if (empty($sign)) {
                throw new YsePayException(['errorCode' => 32002]);
            }
        } elseif ($type == 2) {
            ksort($param);

            //拼接字符串
            $str = '';
            $i = 0;

            foreach ($param as $key => $value) {
                //不参与签名、验签
                if ($key == "sign" || $key == "sec_key") {
                    continue;
                }

                if ($value === null) {
                    $value = '';
                }

                if ($i !== 0) {
                    $str .= '&';
                }
                $str .= $key . '=' . $value;
                $i++;
            }
            $sign = $this->signRSA($str, config('system.ysePay.RSA.private_key'));
        } elseif ($type == 3) {
            ksort($param);

            //拼接字符串
            $str = '';
            $i = 0;

            foreach ($param as $key => $value) {
                //不参与签名、验签
                if ($key == "sign" || $key == "sec_key") {
                    continue;
                }

                if ($value === null) {
                    $value = '';
                }

                if ($i !== 0) {
                    $str .= '&';
                }
                $str .= $key . '=' . $value;
                $i++;
            }

            $plainText = $str;

            $resource = openssl_pkey_get_private($this->privateKey());
            $result = openssl_sign($plainText, $sign, $resource);
            openssl_free_key($resource);
            $sign = base64_encode($sign);
        }
        return $sign;
    }

    /**
     * @title  获取请求接口公共参数
     * @param array $data
     * @return mixed
     */
    public function getPublicHeadParam(array $data)
    {
        $parametersData["requestId"] = getUUID();
        $parametersData["srcMerchantNo"] = $this->MerchantNo;
        $parametersData["version"] = "v2.0.0";
        $parametersData["charset"] = "UTF-8";
        $parametersData["serviceNo"] = $data['method'];
        $parametersData["signType"] = "RSA";
        return $parametersData;
    }

    /**
     * @title  协议支付短信签约
     * @param array $data
     * @return mixed
     */
    public function agreementSignSms(array $data)
    {
        if (!empty(cache('ysePayUserSignIngInfo-' . $data['uid']))) {
            throw new YsePayException(['msg' => '已存在待签约流程, 请十分钟后重试']);
        }
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'signProtocol';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $parametersData["requestNo"] = (new CodeBuilder())->buildUserCardSn();
        $parametersData["payeeMerchantNo"] = $this->MerchantNo;
        $parametersData["userId"] = $data['uid'];
        $parametersData["payerBankAccountNo"] = $this->DESEncrypt(trim($data['bank_card_no']));
        $parametersData["payerBankAccountName"] = $this->DESEncrypt(trim($data['real_name']));
        $parametersData["payerPhone"] = $this->DESEncrypt(trim($data['bank_phone']));
        $parametersData["payerCredentialNo"] = $this->DESEncrypt(trim($data['id_card']));
        //如果是信用卡需要加上有效期和CVV
        if (!empty($data['card_type'] ?? null) && $data['card_type'] == 2) {
            $parametersData["validTimeEnd"] = $this->DESEncrypt(trim($data['expire_date']));
            $parametersData["cvv"] = $this->DESEncrypt(trim($data['cvv']));
        }
        $parametersData['payerCredentialType'] = 'IDCARD';

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $res = false;
        if (!empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004'])) {
                $res = true;
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '短信下发失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['msg'] ?? '短信下发失败, 请稍后重试';
        }

        //记录日志
        $this->log(['type' => 'agreementSignSms', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        if (!empty($res)) {
            //为了兼容多个不同支付商的统一参数, 银盛支付部分多出的参数由缓存获取
            cache('ysePayUserSignIngInfo-' . $data['uid'], ['bank_card' => trim($data['bank_card_no']), 'bank_phone' => trim($data['bank_phone']), 'protocolNo' => $requestResData['protocolNo'] ?? null, 'requestNo' => $parametersData['requestNo'], 'cvv' => $parametersData['cvv'] ?? null, 'validTimeEnd' => $parametersData['validTimeEnd'] ?? null], 600);
        }

        return ['res' => $res, 'errorMsg' => $errorMsg ?? null, 'biz_code' => $requestResData['protocolNo'] ?? null, 'biz_msg' => $requestResData['subMsg'] ?? null, 'applyNo' => $parametersData['requestNo'], 'cvv' => trim($data['cvv'] ?? null), 'validTimeEnd' => trim($data['expire_date'] ?? null)];
    }

    /**
     * @title  协议支付签约
     * @param array $data
     * @return mixed
     */
    public function agreementContract(array $data)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'signConfirm';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $userSignInfoCache = cache('ysePayUserSignIngInfo-' . $data['uid']);
        if (empty($userSignInfoCache)) {
            throw new YsePayException(['msg' => '签约缓存有误, 请十分钟后重试']);
        }

        $parametersData["requestNo"] = $userSignInfoCache['requestNo'];
        $parametersData["smsCode"] = trim($data['sms_code']);
        if (!empty($userSignInfoCache['cvv'] ?? null)) {
            $parametersData["cvv"] = $this->DESEncrypt(trim($userSignInfoCache['cvv']));
        }
        if (!empty($userSignInfoCache['validTimeEnd'] ?? null)) {
            $parametersData["validTimeEnd"] = $this->DESEncrypt(trim($userSignInfoCache['validTimeEnd']));
        }

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $res = false;
        if (!empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004'])) {
                $res = true;
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '短信验证失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['msg'] ?? '短信验证失败, 请稍后重试';
        }

        //防止日志或其他业务系统出错, 正确请求返回后第一时间存在额外的日志信息中,日后丢失签约id可以从这个日志找
        if (!empty($requestRes['bizResponseJson'] ?? []) && !empty($requestResData)) {
            $filename = app()->getRootPath() . 'log' . DIRECTORY_SEPARATOR . 'agreementContract.log';
            $recordMsg = 'yse' . '-' . $data['uid'] . '-' . ($userSignInfoCache['requestNo'] ?? 'notRequestNo') . '-' . ($userSignInfoCache['bank_phone'] ?? 'notBankPhone') . '-' . ($requestResData['protocolNo'] ?? 'notProtocolNo') . '-' . timeToDateFormat(time());
            file_put_contents($filename, "$recordMsg" . PHP_EOL, FILE_APPEND);
        }

        //记录日志
        $this->log(['type' => 'agreementContract', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        $signNo = null;
        $bankCode = null;
        $bankName = null;
        if (!empty($res)) {
            $signNo = $requestResData['protocolNo'];
            //由于银盛没有返回银行卡的简码和名称, 自行获取银行卡简称和名称
            $bankInfo = [];
            try {
                if (!empty($userSignInfoCache['bank_card'] ?? null)) {
                    $bankInfo = (new BankCard())->getBankInfoByBankCard($userSignInfoCache['bank_card'] ?? null);
                }
            } catch (BaseException $be) {

            } catch (Exception $e) {

            }
            $bankCode = $bankInfo['bank'] ?? 'NOTBANK';
            $bankName = $bankInfo['bankName'] ?? '未知银行';
        }
        cache('ysePayUserSignIngInfo-' . $data['uid'], null);

        return ['res' => $res, 'errorMsg' => $errorMsg ?? null, 'sign_no' => $signNo, 'bankCode' => $bankCode, 'bankName' => $bankName, 'card_sn' => $parametersData["requestNo"]];
    }

    /**
     * @title  协议支付解约
     * @param array $data
     * @return mixed
     */
    public function agreementUnSign(array $data)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'cancelProtocol';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $parametersData["protocolNo"] = $data['sign_no'];

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $res = false;
        if (!empty($requestRes) && !empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004'])) {
                $res = true;
                cache('ysePayUserSignIngInfo-' . $data['uid'], null);
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '解约失败, 请稍后重试';
            }

        } else {
            $errorMsg = $requestRes['msg'] ?? '解约失败, 请稍后重试';
        }
        cache('ysePayUserSignIngInfo-' . $data['uid'], null);

        //记录日志
        $this->log(['type' => 'agreementUnSignSms', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        return ['res' => $res, 'errorMsg' => $errorMsg ?? null];
    }

    /**
     * @title  协议支付短信下发(生成订单)
     * @param array $data
     * @return mixed
     */
    public function agreementPaySms(array $data)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'protocolPay';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $parametersData["requestNo"] = $data['out_trade_no'];
        $parametersData["payeeMerchantNo"] = $this->MerchantNo;
        $parametersData["orderDesc"] = $data['attach'] ?? '商城订单';
        $parametersData["amount"] = priceFormat($data['total_fee'] ?? 0);
        $parametersData["userId"] = $data['uid'];
        $parametersData["protocolNo"] = $data['sign_no'];
        //如果是贷记卡必填cvv和有效期
        if (!empty($data['cvv'] ?? null)) {
            $parametersData["cvv"] = $this->DESEncrypt(trim($data['cvv']));
        }
        if (!empty($data['expire_date'] ?? null)) {
            $parametersData["validTimeEnd"] = $this->DESEncrypt(trim($data['expire_date']));
        }
        $parametersData["notifyUrl"] = $data['notify_url'] ?? config('system.callback.ysePayAgreementCallBackUrl');
        //订单有效期，单位：分钟 默认支付有效时间为7天
        if (!empty($data['order_expire']) && intval($data['order_expire'] ?? 0) > 1) {
            $parametersData["timeOut"] = $data['order_expire'];
        }

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $res = false;
        if (!empty($requestRes) && !empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004'])) {
                $res = true;
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '下发支付短信失败, 请稍后重试';
            }

        } else {
            $errorMsg = $requestRes['msg'] ?? '下发支付短信失败, 请稍后重试';
        }

        //银盛支付后续确认支付订单接口需要传网关流水号, 故在此做订单信息缓存
        $cacheInfo = $parametersData;
        $cacheInfo['paygateBizSn'] = $requestResData['paygateBizSn'] ?? null;
        cache('ysePayAgreementPaySmsOrderInfo-' . $data['out_trade_no'], $cacheInfo, 1000);

        //记录日志
        $this->log(['type' => 'paySms', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data, 'orderResData' => $requestResData ?? []]);

        return ['res' => $res, 'errorMsg' => $errorMsg ?? null, 'orderResData' => $requestRes['bizResponseJson'] ?? []];
    }

    /**
     * @title  协议支付短信验证(确认支付)
     * @param array $data
     * @return mixed
     */
    public function agreementSmsPay(array $data)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'protocolPayConfirm';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $userSignInfoCache = cache('ysePayAgreementPaySmsOrderInfo-' . $data['out_trade_no']);
        if (empty($userSignInfoCache) || empty($userSignInfoCache['paygateBizSn'] ?? null)) {
            throw new YsePayException(['msg' => '订单缓存信息有误, 请十分钟后重试']);
        }

        $parametersData["paygateBizSn"] = $userSignInfoCache['paygateBizSn'];
        $parametersData["smsCode"] = $data['sms_code'];

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $res = false;
        if (!empty($requestRes) && !empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004'])) {
                $res = true;
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '支付失败, 请稍后重试';
            }

        } else {
            $errorMsg = $requestRes['msg'] ?? '支付失败, 请稍后重试';
        }

        //记录日志
        $this->log(['type' => 'agreementSmsForPay', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data, 'orderResData' => $requestResData ?? []]);

        return ['res' => $res, 'errorMsg' => $errorMsg ?? null, 'orderResData' => $requestRes['bizResponseJson'] ?? []];
    }

    /**
     * @title  协议支付退款
     * @param array $data
     * @return mixed
     */
    public function agreementRefund(array $data)
    {
        $requestUrl = $this->yseDomain;
        $buildHeadParam['method'] = 'refund';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $parametersData["requestNo"] =  trim($data['out_refund_no']);
        $parametersData["origRequestNo"] = trim($data['out_trade_no']);
        $parametersData["origTradeSn"] = trim($data['pay_no']);
        $parametersData["amount"] = priceFormat($data['refund_fee']);
        //退款资金来源，不传值则默认02 01-一般消费类账户 （原订单收款方） 02-待结算账户（原订单收款方当天收单金额）03-发起方一般消费类账户（发起方）
        $parametersData["refundSource"] = '02';
        //原交易订单是否分账 Y-是、N-否 默认没有分账
        $parametersData["isDivision"] = 'N';
        $parametersData["notifyUrl"] =  $data['notify_url'] ?? config('system.callback.ysePayAgreementRefundCallBackUrl');
        $parametersData["reason"] = $data['refund_desc'] ?? '商城退款';

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $res = false;
        if (!empty($requestRes) && !empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000', 'COM004']) && in_array($requestResData['state'], ['PROCESSING', 'SUCCESS'])) {
                $res = true;
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '退款发起失败, 请稍后重试';
            }

        } else {
            $errorMsg = $requestRes['msg'] ?? '退款发起失败, 请稍后重试';
        }

        //记录日志
        $this->log(['type' => 'agreementRefund', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        if (!$res) {
            if (empty($data['notThrowError'] ?? false)) {
                throw new YsePayException(['msg' => $errorMsg ?? '服务有误']);
            }
        }

        return $res;
    }

    /**
     * @title  查询协议支付支持的银行列表
     * @param array $data
     * @return mixed|void
     */
    public function queryAgreementSupportBank(array $data)
    {
        $requestUrl = 'https://eqt.ysepay.com/api/merchant';
        $buildHeadParam['method'] = 'queryBankList';
        $headParam = $this->getPublicHeadParam($buildHeadParam);

        $parametersData["protocolType"] =  "01";

        $totalParam = $headParam;
        $totalParam['bizReqJson'] = json_encode($parametersData, 256);
        $totalParam['sign'] = $this->buildSign($totalParam, 3);
        $parameters = $totalParam;

        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, true);

        $res = [];
        if (!empty($requestRes) && !empty($requestRes['bizResponseJson'] ?? [])) {
            $requestResData = $requestRes['bizResponseJson'];
            if (in_array($requestResData['subCode'], ['COM000'])) {
                $res = $requestResData['bankList'] ?? [];
            } else {
                $errorMsg = $requestResData['subMsg'] ?? '查询协议支付银行卡失败, 请稍后重试';
            }

        } else {
            $errorMsg = $requestRes['msg'] ?? '查询协议支付银行卡失败, 请稍后重试';
        }

        //记录日志
        $this->log(['type' => 'queryAgreementSupportBank', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        if (!$res) {
            if (empty($data['notThrowError'] ?? false)) {
                throw new YsePayException(['msg' => $errorMsg ?? '服务有误']);
            }
        }

        return $res;
    }

    /**
     * @title  curl请求
     * @param string $url 请求地址
     * @param string $data
     * @return bool|string
     */
    private function httpRequest(string $url, $data = "", $contentType = false)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        if (!empty($data)) { //判断是否为POST请求
            curl_setopt($curl, CURLOPT_POST, 1);
            if ($contentType) {
                $headers = array(
                    "Content-type: application/x-www-form-urlencoded;charset='utf-8'",
                );
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
            } else {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }


    public function http_post_json($url, $param)
    {
        if (empty($url) || empty($param)) {
            return false;
        }
        $param = http_build_query($param);
        try {

            $ch = curl_init();//初始化curl
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //正式环境时解开注释
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $data = curl_exec($ch);//运行curl
            curl_close($ch);

            if (!$data) {
                throw new \Exception('请求出错');
            }

            return $data;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @title  读取私钥
     * @return mixed
     */
    private function privateKey()
    {
        try {
            $file = file_get_contents($this->privateKeyPath);
            if (!$file) {
                throw new \Exception('私钥文件读取有误');
            }
            if (!openssl_pkcs12_read($file, $cert, $this->privateKeyPwd)) {
                throw new \Exception('ERROR 私钥密码错误');
            }
            return $cert['pkey'];
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @title  生成签名
     * @param $plainText
     * @throws \Exception
     * @return string
     */
    protected function sign($plainText)
    {
        $plainText = json_encode($plainText);
        try {
            $resource = openssl_pkey_get_private($this->privateKey());
            $result   = openssl_sign($plainText, $sign, $resource);
            openssl_free_key($resource);
            if (!$result) throw new \Exception('sign error');
            return base64_encode($sign);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * AES加密，模式为：AES/ECB/PKCK7Padding
     * @param string $data
     * @param string $secKey
     * @return string
     */
    public function encryptECB(string $data, string $secKey)
    {
        $encrypted = openssl_encrypt($data, 'AES-128-ECB', $secKey, OPENSSL_RAW_DATA);
        if ($encrypted === false) {
            throw new YsePayException(['msg' => 'aes加密失败']);
        }
        return base64_encode($encrypted);
    }

    /**
     * AES解密，模式为：AES/ECB/PKCK7Padding
     * @param string $data
     * @param string $secKey
     * @return string
     */
    public function decryptECB(string $data, string $secKey)
    {
        $decrypted = openssl_decrypt(base64_decode($data), 'AES-128-ECB', $secKey, OPENSSL_RAW_DATA);
        if ($decrypted === false) {
            throw new YsePayException(['msg' => 'aes解密失败']);
        }
        return $decrypted;
    }

    /**
     * 使用公钥加密
     * @param string $data
     * @param string $pubKey
     * @return string
     */
    public function encryptRSA(string $data, string $pubKey){
        $pubKey = openssl_get_publickey($pubKey);
        if($pubKey === false){
            throw new YsePayException(['msg'=>"rsa解密公钥无效，修改建议：平台公钥代码中格式为：首行-----BEGIN PUBLIC KEY-----；第二行平台公钥；第三行-----END PUBLIC KEY-----"]);
            echo "<br>";
        }

        $crypted = '';
        $isSuccess = openssl_public_encrypt($data, $crypted, $pubKey, OPENSSL_PKCS1_PADDING);
        if($isSuccess == false){
            throw new YsePayException(['msg'=>"rsa加密失败"]);
        }
        return base64_encode($crypted);
    }

    /**
     * 使用私钥解密
     * @param string $data
     * @param string $priKey
     * @return string
     */
    public function decryptRSA(string $data, string $priKey){
        $priKey = openssl_get_privatekey($priKey);
        if($priKey === false){
            throw new YsePayException(['msg'=>"rsa解密私钥无效"]);
        }

        $decrypted = '';
        $isSuccess = openssl_private_decrypt(base64_decode($data), $decrypted, $priKey, OPENSSL_PKCS1_PADDING);
        if(!$isSuccess){
            throw new YsePayException(['msg'=>"rsa解密失败，请检查是否有遗漏加密敏感信息。"]);
        }
        return (string)$decrypted;
    }

    /**
     * 取得 待签名/待验签 的字符串
     * @param object $param
     * @return string
     * @throws \ReflectionException
     */
    public function getSortedString(object $param){
        $reflect = new \ReflectionClass($param);
        $props = $reflect->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_PROTECTED);

        //通过反射取得所有属性和属性的值
        $arr = [];
        foreach ($props as $prop) {
            $prop->setAccessible(true);

            $key = $prop->getName();
            $value = $prop->getValue($param);
            $arr[$key] = $value;
        }

        //按key的字典序升序排序，并保留key值
        ksort($arr);

        //拼接字符串
        $str = '';
        $i = 0;
        foreach($arr as $key => $value) {
            //不参与签名、验签
            if($key == "sign" || $key == "sec_key"){
                continue;
            }

            if($value === null){
                $value = '';
            }

            if($i !== 0){
                $str .= '&';
            }
            $str .= $key . '=' . $value;
            $i ++;
        }
        return $str;
    }


    /**
     * 使用私钥进行签名
     * @param string $data
     * @param string $priKey
     * @return string
     */
    public function signRSA(string $data, string $priKey){
        $priKey = openssl_get_privatekey($priKey);
        if($priKey === false){
            throw new YsePayException(['msg'=> "rsa签名私钥无效修改建议：私钥代码中格式为：首行-----BEGIN RSA PRIVATE KEY-----；第二行私钥；第三行-----END RSA PRIVATE KEY-----"]);
        }

        $binary_signature = '';
        $isSuccess = openssl_sign($data, $binary_signature, $priKey, OPENSSL_ALGO_MD5);
        openssl_free_key($priKey);
        if(! $isSuccess){
            throw new YsePayException(['msg'=>"rsa签名失败"]);
        }
        return base64_encode($binary_signature);
    }

    /**
     * @title  读取公钥
     * @return mixed
     */
    private function publicKey()
    {
        try {
            $file = file_get_contents($this->publicKeyPath);
            $cert   = chunk_split(base64_encode($file), 64, "\n");
            $cert   = "-----BEGIN CERTIFICATE-----\n" . $cert . "-----END CERTIFICATE-----\n";
            $res    = openssl_pkey_get_public($cert);
            $detail = openssl_pkey_get_details($res);
            openssl_free_key($res);
            return $detail['key'];
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @title  使用公钥进行验签
     * @param string $signData
     * @param $sign
     * @throws \Exception
     * @return bool
     */
    public function verifyRSA(string $signData, $sign)
    {
        $resource = openssl_pkey_get_public($this->publicKey());
        $result = openssl_verify($signData, base64_decode($sign), $resource);
        openssl_free_key($resource);

        if (!$result) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * @title  解析请求返回的数据
     * @param $result
     * @return array
     */
    protected function parseResult($result)
    {
        $arr      = array();
        $response = urldecode($result);
        $arrStr   = explode('&', $response);
        foreach ($arrStr as $str) {
            $p         = strpos($str, "=");
            $key       = substr($str, 0, $p);
            $value     = substr($str, $p + 1);
            $arr[$key] = $value;
        }

        return $arr;
    }


    /**
     * 以post方式发起http请求，请求参数为json格式
     * @param string $url
     * @param string $jsonData
     * @return bool|string
     */
    public static function postJsonSync(string $url, string $jsonData){
        $curl = curl_init();

        $headers=[];
        $headers[] = "Content-type: application/json;charset=UTF-8";//设置请求体类型
        $headers[] = 'Accept: application/json;charset=UTF-8';//设置预期响应类型
        $headers[] = 'Expect:';//禁用"Expect"头域

        $opts = [];
        $opts[CURLOPT_URL] = $url;//设置请求的url
        $opts[CURLOPT_POST] = 1;//设置post方式提交
        $opts[CURLOPT_RETURNTRANSFER] = true;//设置获取的信息以文件流的形式返回，而不是直接输出。
        $opts[CURLOPT_CONNECTTIMEOUT] = 5;//设置连接超时时间(秒)
        $opts[CURLOPT_TIMEOUT] = 20;//设置超时时间(秒)
        $opts[CURLOPT_HTTPHEADER] = $headers;//设置请求头
        $opts[CURLOPT_POSTFIELDS] = $jsonData;//设置需要提交的数据
        $opts[CURLOPT_HTTP_VERSION] = 3;
        $opts[CURLOPT_HEADER] = false;//头文件的信息不当做数据流输出
        if(strpos($url, "https") === 0){//https请求
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;// 从证书中检查SSL加密算法是否存在
            if(static::$_CAINFO){
                $opts[CURLOPT_CAINFO] = static::$_CAINFO; //设置证书路径
                $opts[CURLOPT_SSL_VERIFYPEER] = true; //需要执行证书检查
            }else{
                $opts[CURLOPT_SSL_VERIFYPEER] = false; //跳过证书检查（不建议）
            }
        }

        curl_setopt_array($curl, $opts);
        //执行命令
        $data = curl_exec($curl);
        curl_close($curl);
        return $data;
    }

    /**
     * @title  银行简码字典
     * @param string $bankCode
     * @return mixed|string
     */
    public function bankNameList(string $bankCode)
    {
        $banList = [
            'ICBC' => '工商银行',
            'BOC' => '中国银行',
            'CIB' => '兴业银行',
            'ECITIC' => '中信银行',
            'SHB' => '上海银行',
            'CEB' => '光大银行',
            'CMBC' => '民生银行',
            'BCCB' => '北京银行',
            'PINGANBANK' => '平安银行',
            'BOCO' => '交通银行',
            'CMBCHINA' => '招商银行',
            'CGB' => '广发银行',
            'HXB' => '华夏银行',
            'CCB' => '建设银行',
            'ABC' => '农业银行',
            'SPDB' => '浦发银行',
            'POST' => '邮储银行',
        ];
        return $banList[strtoupper($bankCode)] ?? '未知银行';

    }

    /**
     * DES加密
     *
     * @param $str
     * @return string
     */
    public function DESEncrypt($str)
    {
//        $str = $this->padding($str, 8);
//        $sign = openssl_encrypt($str, "DES-CBC", $desKey, DES, $this->iv);
//
//        if ($this->output == self::OUTPUT_BASE64) {
//            $sign = base64_encode($sign);
//        } else if ($this->output == self::OUTPUT_HEX) {
//            $sign = bin2hex($sign);
//        }
//        return $sign;
        $desKey = $this->pkcSecretKey($this->desKey);
        $data = mb_convert_encoding($str, 'UTF-8');
        $encry = openssl_encrypt($data, 'DES-ECB', $desKey, OPENSSL_RAW_DATA);
        return base64_encode($encry);

    }

    /**
     * 填充
     *
     * @param $str
     * @param $blockSize
     * @return string
     * @internal param $blocksize
     */
    private function padding($str, $blockSize)
    {
        $pad = $blockSize - (strlen($str) % $blockSize);
        return $str . str_repeat(chr($pad), $pad);
    }

    /**
     * DES解密
     *
     * @param $encrypted
     * @return string
     */
    public function DESDecrypt($string)
    {
//        if ($this->output == self::OUTPUT_BASE64) {
//            $encrypted = base64_decode($encrypted);
//        } else if ($this->output == self::OUTPUT_HEX) {
//            $encrypted = hex2bin($encrypted);
//        }
//
//        $sign = @openssl_decrypt($encrypted, $this->method, $this->key, $this->options, $this->iv);
//        $sign = $this->unPadding($sign);
//        $sign = rtrim($sign);
//        return $sign;
        $desKey = $this->pkcSecretKey($this->desKey);
        $decry = openssl_decrypt(base64_decode($string), 'DES-ECB', $desKey, OPENSSL_RAW_DATA);
        return $decry;

    }

    /**
     * 去填充
     *
     * @param $str
     * @return string
     */
    private function unPadding($str)
    {
        $pad = ord($str{strlen($str) - 1});
        if ($pad > strlen($str)) {
            return false;
        }
        return substr($str, 0, -1 * $pad);
    }

    /**
     * @title DES加解密时填充秘钥
     * @return mixed
     */
    private function pkcSecretKey($key) {
        $len = strlen($key);
        if (null == $key || $len < 8) {
            $key = str_pad($key, 8, '0', STR_PAD_RIGHT);
        }
        if ($len > 8) {
            $key = substr($key, 0, 8);
        }
        $key = mb_convert_encoding($key, 'UTF-8');
        return $key;
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