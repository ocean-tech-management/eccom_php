<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 汇聚支付模块Service,统一规范请继承IPay接口类]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 
// | 汇聚支付文档地址:https://www.joinpay.com/open-platform/pages/document.html
// +----------------------------------------------------------------------

namespace app\lib\services;


use app\lib\exceptions\JoinPayException;
use app\lib\interfaces\IPay;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\Order;
use joinpay\SDKException;
use think\facade\Request;

class JoinPay implements IPay
{
    protected $customConfig;
    protected $weChatAppId;
    protected $MerchantNo;
    protected $secret;
    protected $uniPayType;
    protected $TradeMerchantNo;
    private static $_CAINFO;

    public function __construct()
    {
        $config = config('system.joinPay');
        if (!empty($this->customConfig)) {
            $config = $this->customConfig;
        }
        // $wxConfig = config('system.weChat');
        $access_key = getAccessKey();
        $wxConfig = config("system.clientConfig.$access_key");
        if (empty($config)) throw new JoinPayException();
        $this->MerchantNo = $config['MerchantNo'];
        $this->secret = $config['secret'];
        $this->weChatAppId = $wxConfig['app_id'];
        $this->setUniPayType('WEIXIN_XCX');
        $this->TradeMerchantNo = $config['TradeMerchantNo'];
        self::$_CAINFO = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'ca' . DIRECTORY_SEPARATOR . 'cacert.pem';
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
        //不同类型的支付参数有所不同,请参考汇聚支付-聚合支付文档,现在默认是小程序支付
        $requestUrl = 'https://www.joinpay.com/trade/uniPayApi.action';
        $parameters['p0_Version'] = '2.1';
        $parameters['p1_MerchantNo'] = $this->MerchantNo;
        $parameters['p2_OrderNo'] = $data['out_trade_no'];
        $parameters['p3_Amount'] = $data['total_fee'];
        $parameters['p4_Cur'] = 1;
        $parameters['p5_ProductName'] = $data['body'] ?? '商城订单';
        $parameters['p6_ProductDesc'] = $data['attach'] ?? config('system.projectName') . '自营商城订单';
        if (!empty($data['map'])) {
            $parameters['p7_Mp'] = trim($data['map']);  //公共回传参数,在回调的时候会返回
        }
        $parameters['p8_ReturnUrl'] = '';  //处理结果页面跳转到商户网站里指定的http地址。（微信H5、支付宝收银台可选填，本接口其他业务留空）
        $parameters['p9_NotifyUrl'] = $data['notify_url'] ?? config('system.callback.joinPayCallBackUrl');

        $parameters['q1_FrpCode'] = $this->uniPayType;
        $parameters['q5_OpenId'] = $data['openid'];
        $parameters['q7_AppId'] = $this->weChatAppId;
        $parameters['qa_TradeMerchantNo'] = $this->TradeMerchantNo;
        $parameters['hmac'] = $this->buildSign($parameters);
        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, 1);
        //记录日志
        $this->log(['type' => 'orderPay', 'param' => $parameters, 'returnData' => $requestRes]);

        if ($requestRes['ra_Code'] != 100) {
            $msg = $requestRes['rb_CodeMsg'] ?? '支付服务有误';
            $this->log(['type' => 'failOrderPay', 'msg' => $msg, 'param' => $parameters, 'returnData' => $requestRes], 'error');
            throw new JoinPayException(['msg' => $msg]);
        } else {
            $payData = json_decode($requestRes['rc_Result'], true);
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
        $requestUrl = 'https://www.joinpay.com/trade/queryOrder.action';
        $parameters['p1_MerchantNo'] = $this->MerchantNo;
        $parameters['p2_OrderNo'] = trim($out_trade_no);
        $parameters['hmac'] = $this->buildSign($parameters);
        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, 1);

        $message['status'] = 40010;
        $message['message'] = '支付对单失败';

        if ($requestRes['rb_Code'] == 100) {
            if ($requestRes['ra_Status'] == 100) {
                $message['status'] = 100;
                $message['message'] = '支付对单成功';
                $message['result'] = $requestRes;
            }
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
        $requestUrl = 'https://api.joinpay.com/query';
        $parametersData["mch_order_no"] = $out_trade_no;
        $message['status'] = 40010;
        $message['message'] = '支付对单失败';

        //充值订单和普通订单区分不同的表查询
        if (strstr($out_trade_no, 'CZ')) {
            $orderCreateTime = CrowdfundingBalanceDetail::where(['order_sn' => $out_trade_no])->value('create_time');
        } else {
            //查询订单的下单时间
            $orderCreateTime = Order::where(['order_sn' => $out_trade_no])->value('create_time');
        }

        if (empty($orderCreateTime)) {
            return $message;
        }
        $parametersData["org_mch_req_time"] = date('Y-m-d H:i:s', $orderCreateTime);
        $secKey = getUid(16);

        $parameters['method'] = 'fastPay.query';
        $parameters['version'] = '1.0';
        $parameters['data'] = json_encode($parametersData,256);
        $parameters['rand_str'] = getUid(32);
        $parameters['sign_type'] = 2;
        $parameters['mch_no'] = $this->MerchantNo;
        $parameters['sign'] = $this->buildSign($parameters,2);
        //ARS加密
        $parameters['sec_key'] = $this->encryptRSA($secKey, config('system.RSA.public_key'));//sec_key加密：使用平台公钥
        $requestRes = $this->postJsonSync($requestUrl, json_encode($parameters,256));
        $requestRes = json_decode($requestRes, 1);
        $res = false;
        if (!empty($requestRes) && !empty($requestRes['data'] ?? [])) {
            $requestResData = json_decode($requestRes['data'], 1);
            if ($requestResData['order_status'] == 'P1000') {
                $res = true;
                $message['status'] = 100;
                $message['message'] = '支付对单成功';
                $message['result'] = $requestResData;
            } else {
                $errorMsg = $requestResData['err_msg'] ?? '订单校验失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestResData['biz_msg'] ?? '订单校验失败, 请稍后重试';
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
        $requestUrl = 'https://www.joinpay.com/trade/refund.action';
        $parameters['p1_MerchantNo'] = $this->MerchantNo;
        $parameters['p2_OrderNo'] = trim($data['out_trade_no']);
        $parameters['p3_RefundOrderNo'] = trim($data['out_refund_no']);
        $parameters['p4_RefundAmount'] = $data['refund_fee'];
        $parameters['p5_RefundReason'] = $data['refund_desc'] ?? '商城退款';
        $parameters['p6_NotifyUrl'] = $data['notify_url'] ?? config('system.callback.joinPayRefundCallBackUrl');
        $parameters['hmac'] = $this->buildSign($parameters);
        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, 1);
        //记录日志
        $this->log(['type' => 'refund', 'param' => $parameters, 'returnData' => $requestRes]);

        $res = false;
        if ($requestRes['rb_Code'] == 100) {
            if ($requestRes['ra_Status'] == 100) {
                $res = true;
            }
        }

        if (!$res) {
            if (empty($data['notThrowError'] ?? false)) {
                throw new JoinPayException(['msg' => $requestRes['rc_CodeMsg'] ?? '服务有误']);
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
        $requestUrl = 'https://www.joinpay.com/trade/queryRefund.action';
        $parameters['p1_MerchantNo'] = $this->MerchantNo;
        $parameters['p2_RefundOrderNo'] = trim($out_refund_no);
        $parameters['hmac'] = $this->buildSign($parameters);
        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, 1);

        $message['status'] = 40010;
        $message['message'] = '退款对单失败';

        if ($requestRes['rb_Code'] == 100) {
            if ($requestRes['ra_Status'] == 100) {
                $message['status'] = 100;
                $message['message'] = '退款对单成功';
                $message['result'] = $requestRes;
            }
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
        $requestUrl = 'https://api.joinpay.com/refund';
        $parametersData["refund_order_no"] = $out_refund_no;
        $message['status'] = 40010;
        $message['message'] = '支付对单失败';

        $secKey = getUid(16);

        $parameters['method'] = 'refund.query';
        $parameters['version'] = '1.0';
        $parameters['data'] = json_encode($parametersData,256);
        $parameters['rand_str'] = getUid(32);
        $parameters['sign_type'] = 2;
        $parameters['mch_no'] = $this->MerchantNo;
        $parameters['sign'] = $this->buildSign($parameters,2);
        //ARS加密
        $parameters['sec_key'] = $this->encryptRSA($secKey, config('system.RSA.public_key'));//sec_key加密：使用平台公钥
        $requestRes = $this->postJsonSync($requestUrl, json_encode($parameters,256));
        $requestRes = json_decode($requestRes, 1);
        $res = false;
        if (!empty($requestRes) && !empty($requestRes['data'] ?? [])) {
            $requestResData = json_decode($requestRes['data'], 1);
            if (in_array($requestResData['refund_status'], ['100', '102'])) {
                $res = true;
                $message['status'] = 100;
                $message['message'] = '退款对单成功';
                $message['result'] = $requestResData;
            } else {
                $errorMsg = $requestResData['err_msg'] ?? '订单退款校验失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestResData['biz_msg'] ?? '订单退款校验失败, 请稍后重试';
        }

        return $message;
    }

    /**
     * @title  企业付款到用户银行帐号
     * @param array $data
     * @return mixed
     */
    public function enterprisePayment(array $data)
    {
        //检测可用余额
        $accountInfo = $this->accountBalanceQuery();
        $useAbleAmount = doubleval($accountInfo['useAbleSettAmount'] ?? 0);
        if ($useAbleAmount <= 1 || $useAbleAmount < $data['amount']) {
            throw new JoinPayException(['errorCode' => 22004]);
        }

        $requestUrl = 'https://www.joinpay.com/payment/pay/singlePay';
        $parameters['userNo'] = $this->MerchantNo;
        $parameters['productCode'] = 'BANK_PAY_DAILY_ORDER';
        $parameters['requestTime'] = timeToDateFormat(time());
        $parameters['merchantOrderNo'] = trim($data['partner_trade_no']);
        $parameters['receiverAccountNoEnc'] = $data['bank_account'];
        $parameters['receiverNameEnc'] = $data['re_user_name'];
        $parameters['receiverAccountType'] = $data['account_type'] ?? '201'; //账户类型 对私账户：201    对公账户：204
        $parameters['receiverBankChannelNo'] = $data['bank_channel'] ?? ''; //收款账户联行号 对公账户必须填写此字段
        $parameters['paidAmount'] = (string)$data['amount'];
        $parameters['currency'] = '201';
        $parameters['isChecked'] = '202';  //是否复核 复核：201，不复核：202 需要去汇聚商户后台重新审核
        $parameters['paidDesc'] = $data['desc'] ?? '企业付款';
        $parameters['paidUse'] = '201';  //代付用途类型 209表示其他
        $parameters['callbackUrl'] = config('system.callback.joinPayPaymentCallBackUrl');
        $parameters['firstProductCode'] = '';
        $parameters['hmac'] = $this->buildSign($parameters);
        $requestRes = $this->httpRequest($requestUrl, $parameters, true);
        $requestRes = json_decode($requestRes, 1);

        $res = false;
        if ($requestRes['statusCode'] == 2001) {
            $msg = '受理成功';
            $res = true;
        } else {
            $msg = $requestRes['data']['errorDesc'] ?? ($requestRes['message'] ?? '支付服务有误,请间隔十分钟后重新尝试');
            throw new JoinPayException(['msg' => $msg]);
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
        $requestUrl = 'https://www.joinpay.com/payment/pay/singlePayQuery';
        $parameters['userNo'] = $this->MerchantNo;
        $parameters['merchantOrderNo'] = trim($out_trade_no);
        $parameters['hmac'] = $this->buildSign($parameters);
        $requestRes = $this->httpRequest($requestUrl, $parameters, true);
        $requestRes = json_decode($requestRes, 1);
        if (empty($requestRes)) {
            return json(['statusCode' => 2002, 'message' => '对账单失败, 查无数据']);
        }
        $message['status'] = 40010;
        $message['message'] = '代付对单失败';

        if ($requestRes['statusCode'] == 2001) {
            if ($requestRes['data']['status'] == 205) {
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
     */
    public function accountBalanceQuery()
    {
        $requestUrl = 'https://www.joinpay.com/payment/pay/accountBalanceQuery';
        $parameters['userNo'] = $this->MerchantNo;
        $parameters['hmac'] = $this->buildSign($parameters);
        $requestRes = $this->httpRequest($requestUrl, $parameters, true);
        $requestRes = json_decode($requestRes, 1);

        $data = [];
        if ($requestRes['statusCode'] == 2001) {
            $msg = '查询成功';
            $requestResData = $requestRes['data'];
            $data['currency'] = $requestResData['currency'];  //币种201为人民币
            $data['useAbleSettAmount'] = $requestResData['useAbleSettAmount'] ?? 0;  //除去风控冻结金额剩余的可结算金额
            $data['availableSettAmountFrozen'] = $requestResData['availableSettAmountFrozen'];  //代付处理中的金额
        } else {
            $msg = $requestRes['data']['errorDesc'] ?? ($requestRes['message'] ?? '支付服务有误,请间隔十分钟后重新尝试');
            throw new JoinPayException(['msg' => $msg]);
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
            throw new JoinPayException(['msg' => $msg]);
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
        $requestUrl = 'https://www.joinpay.com/trade/closeOrder.action';
        $parameters['p1_MerchantNo'] = $this->MerchantNo;
        $parameters['p2_OrderNo'] = trim($data['order_sn']);
        $parameters['p3_FrpCode'] = trim($data['type'] ?? $this->uniPayType);
        $parameters['hmac'] = $this->buildSign($parameters);
        $requestRes = $this->httpRequest($requestUrl, $parameters);
        $requestRes = json_decode($requestRes, 1);

        $message['status'] = 40010;
        $message['message'] = '关闭订单失败';

        if ($requestRes['ra_Status'] == 100) {
            $message['status'] = 100;
            $message['message'] = '关闭订单成功';
            $message['result'] = $requestRes;
        } elseif ($requestRes['ra_Status'] == 101) {
            //关单失败中也有一些情况是允许返回成功的,比如没有正确创建订单的状态
            if (!empty($requestRes['rb_Code'] ?? null)) {
                switch ($requestRes['rb_Code']) {
                    case 10083003:
                        $message['status'] = 100;
                        $message['message'] = '非已创建状态或订单已关闭，无需关单操作';
                        $message['result'] = $requestRes;
                        break;
                    case 10080003:
                        $message['status'] = 100;
                        $message['message'] = '订单号不正确,可能是没有创建订单哦';
                        $message['result'] = $requestRes;
                        break;
                }
            }
        }

        //记录日志
        $this->log(['type' => 'closeOrder', 'msg' => $message, 'param' => $parameters, 'returnData' => $requestRes]);

        return $message;
    }

    /**
     * @title  生成签名
     * @param array $param
     * @param int $type 签名生成类型 1为md5,2为RAS
     * @return string|null
     */
    public function buildSign(array $param,int $type =1)
    {
        if($type == 1){
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
                throw new JoinPayException(['errorCode' => 23002]);
            }
        }elseif ($type == 2){
            ksort($param);

            //拼接字符串
            $str = '';
            $i = 0;

            foreach($param as $key => $value) {
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
            $sign = $this->signRSA($str,config('system.RSA.private_key'));
        }
        return $sign;
    }

    /**
     * @title  协议支付短信签约
     * @param array $data
     * @return mixed
     */
    public function agreementSignSms(array $data)
    {
        $requestUrl = 'https://api.joinpay.com/fastpay';
        $parametersData["mch_order_no"] = $data['out_trade_no'];
        $parametersData["order_amount"] = "0.01";
        $parametersData["mch_req_time"] = date('Y-m-d H:i:s', $data['order_create_time']);
        $secKey = getUid(16);
        $parametersData["payer_name"] = $this->encryptECB($data['real_name'], $secKey);//加密
        $parametersData["id_type"] = "1";
        $parametersData["id_no"] = $this->encryptECB($data['id_card'], $secKey);//加密
        $parametersData["bank_card_no"] =$this->encryptECB($data['bank_card_no'], $secKey);//加密
        $parametersData["mobile_no"] = $this->encryptECB($data['bank_phone'], $secKey);//加密
        //如果是信用卡需要加上有效期和CVV
        if(!empty($data['card_type'] ?? null) && $data['card_type'] == 2){
            $parametersData["expire_date"] = $this->encryptECB($data['expire_date'], $secKey);
            $parametersData["cvv"] = $this->encryptECB($data['cvv'], $secKey);
        }
        $parameters['method'] = 'fastPay.agreement.signSms';
        $parameters['version'] = '1.0';
        $parameters['data'] = json_encode($parametersData,256);
        $parameters['rand_str'] = getUid(32);
        $parameters['sign_type'] = 2;
        $parameters['mch_no'] = $this->MerchantNo;
        $parameters['sign'] = $this->buildSign($parameters,2);
        //ARS加密
        $parameters['sec_key'] = $this->encryptRSA($secKey, config('system.RSA.public_key'));//sec_key加密：使用平台公钥
        $requestRes = $this->postJsonSync($requestUrl, json_encode($parameters,256));
        $requestRes = json_decode($requestRes, 1);
        $res = false;
        if (!empty($requestRes) && !empty($requestRes['data'] ?? [])) {
            $requestResData = json_decode($requestRes['data'], 1);
            if (in_array($requestResData['order_status'], ['P3000', 'P1000'])) {
                $res = true;
            } else {
                $errorMsg = $requestResData['err_msg'] ?? '短信下发失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['biz_msg'] ?? '短信下发失败, 请稍后重试';
        }
        //记录日志
        $this->log(['type' => 'agreementSignSms', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        return ['res' => $res, 'errorMsg' => $errorMsg ?? null, 'biz_code' => $requestRes['biz_code'] ?? null, 'biz_msg' => $requestRes['biz_msg'] ?? null];
    }

    /**
     * @title  协议支付签约
     * @param array $data
     * @return mixed
     */
    public function agreementContract(array $data)
    {
        $requestUrl = 'https://api.joinpay.com/fastpay';
        $parametersData["mch_order_no"] = $data['out_trade_no'];
        $parametersData["sms_code"] = $data['sms_code'];
        $secKey = getUid(16);

        $parameters['method'] = 'fastPay.agreement.smsSign';
        $parameters['version'] = '1.0';
        $parameters['data'] = json_encode($parametersData,256);
        $parameters['rand_str'] = getUid(32);
        $parameters['sign_type'] = 2;
        $parameters['mch_no'] = $this->MerchantNo;
        $parameters['sign'] = $this->buildSign($parameters,2);
        //ARS加密
        $parameters['sec_key'] = $this->encryptRSA($secKey, config('system.RSA.public_key'));//sec_key加密：使用平台公钥
        $requestRes = $this->postJsonSync($requestUrl, json_encode($parameters,256));
        $requestRes = json_decode($requestRes, 1);
        $res = false;
        $signNo = null;
        $bankCode = null;
        $bankName = null;
        if (!empty($requestRes) && !empty($requestRes['data'] ?? [])) {
            $requestResData = json_decode($requestRes['data'], 1);
            if ($requestResData['order_status'] == 'P1000') {
                $res = true;
                $signNo = $requestResData['sign_no'];
                $bankCode = $requestResData['bank_code'];
                $bankName = $this->bankNameList($requestResData['bank_code']);
            } else {
                $errorMsg = $requestResData['err_msg'] ?? '短信验证失败, 请稍后重试';
            }

        } else {
            $errorMsg = $requestRes['biz_msg'] ?? '短信验证失败, 请稍后重试';
        }


        //记录日志
        $this->log(['type' => 'agreementSignSms', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        return ['res' => $res, 'errorMsg' => $errorMsg ?? null, 'sign_no' => $signNo, 'bankCode' => $bankCode, 'bankName' => $bankName];
    }

    /**
     * @title  协议支付解约
     * @param array $data
     * @return mixed
     */
    public function agreementUnSign(array $data)
    {
        $requestUrl = 'https://api.joinpay.com/fastpay';

        $secKey = getUid(16);
        $parametersData["mch_order_no"] = $data['out_trade_no'];
        $parametersData["mch_req_time"] = date('Y-m-d H:i:s', time());
        //签约ID和银行卡号二选一必填，都填时以签约ID为准，上送时参与签名处理, 必须加密传输
        $parametersData["sign_no"] = $this->encryptECB($data['sign_no'], $secKey);//加密
//        $parametersData["bank_card_no"] = $this->encryptECB($data['bank_card_no'], $secKey);//加密

        $parameters['method'] = 'fastPay.agreement.unSign';
        $parameters['version'] = '1.0';
        $parameters['data'] = json_encode($parametersData,256);
        $parameters['rand_str'] = getUid(32);
        $parameters['sign_type'] = 2;
        $parameters['mch_no'] = $this->MerchantNo;
        $parameters['sign'] = $this->buildSign($parameters,2);
        //ARS加密
        $parameters['sec_key'] = $this->encryptRSA($secKey, config('system.RSA.public_key'));//sec_key加密：使用平台公钥
        $requestRes = $this->postJsonSync($requestUrl, json_encode($parameters,256));
        $requestRes = json_decode($requestRes, 1);
        $res = false;
        if (!empty($requestRes) && !empty($requestRes['data'] ?? [])) {
            $requestResData = json_decode($requestRes['data'], 1);
            if ($requestResData['order_status'] == 'P1000') {
                $res = true;
            } else {
                $errorMsg = $requestResData['err_msg'] ?? '解约失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['biz_msg'] ?? '解约失败, 请稍后重试';
        }


        //记录日志
        $this->log(['type' => 'agreementSignSms', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        return ['res' => $res, 'errorMsg' => $errorMsg ?? null];
    }

    /**
     * @title  协议支付短信下发(生成订单)
     * @param array $data
     * @return mixed
     */
    public function agreementPaySms(array $data)
    {
        $requestUrl = 'https://api.joinpay.com/fastpay';

        $secKey = getUid(16);
        $parametersData["mch_order_no"] = $data['out_trade_no'];
        $parametersData["order_amount"] = $data['total_fee'];
        $parametersData["mch_req_time"] = date('Y-m-d H:i:s', $data['order_create_time']);
        $parametersData["order_desc"] = $data['body'] ?? '商城订单';
        $parametersData["callback_url"] = $data['notify_url'] ?? config('system.callback.joinPayAgreementCallBackUrl');
        if (!empty($data['map'])) {
            $parameters['callback_param'] = trim($data['map']);  //公共回传参数,在回调的时候会返回,最大长度为50位字符串
        }
        //签约ID和银行卡号二选一必填，都填时以签约ID为准，上送时参与签名处理, 必须加密传输
        $parametersData["sign_no"] =$this->encryptECB($data['sign_no'], $secKey);//加密
//        $parametersData["bank_card_no"] = $this->encryptECB($data['bank_card_no'], $secKey);//加密

        $parameters['method'] = 'fastPay.agreement.paySms';
        $parameters['version'] = '1.0';
        $parameters['data'] = json_encode($parametersData,256);
        $parameters['rand_str'] = getUid(32);
        $parameters['sign_type'] = 2;
        $parameters['mch_no'] = $this->MerchantNo;
        $parameters['sign'] = $this->buildSign($parameters,2);
        //ARS加密
        $parameters['sec_key'] = $this->encryptRSA($secKey, config('system.RSA.public_key'));//sec_key加密：使用平台公钥
        $requestRes = $this->postJsonSync($requestUrl, json_encode($parameters,256));
        $requestRes = json_decode($requestRes, 1);
        $res = false;
        if (!empty($requestRes) && !empty($requestRes['data'] ?? [])) {
            $requestResData = json_decode($requestRes['data'], 1);
            if (in_array($requestResData['order_status'], ['P1000', 'P3000'])) {
                $res = true;
            } else {
                $errorMsg = $requestResData['err_msg'] ?? '支付短信下发失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['biz_msg'] ?? '支付短信下发失败, 请稍后重试';
        }


        //记录日志
        $this->log(['type' => 'agreementSignSms', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data, 'orderResData' => $requestResData ?? []]);

        return ['res' => $res, 'errorMsg' => $errorMsg ?? null, 'biz_msg' => $requestRes['biz_msg'] ?? null, 'biz_code' => $requestRes['biz_code'] ?? null, 'orderResData' => $requestResData ?? []];
    }

    /**
     * @title  协议支付短信验证(确认支付)
     * @param array $data
     * @return mixed
     */
    public function agreementSmsPay(array $data)
    {
        $requestUrl = 'https://api.joinpay.com/fastpay';

        $secKey = getUid(16);
        $parametersData["mch_order_no"] = $data['out_trade_no'];
        $parametersData["mch_req_time"] = date('Y-m-d H:i:s', $data['order_create_time']);
        $parametersData["sms_code"] = $data['sms_code'];

        $parameters['method'] = 'fastPay.agreement.smsPay';
        $parameters['version'] = '1.0';
        $parameters['data'] = json_encode($parametersData,256);
        $parameters['rand_str'] = getUid(32);
        $parameters['sign_type'] = 2;
        $parameters['mch_no'] = $this->MerchantNo;
        $parameters['sign'] = $this->buildSign($parameters,2);
        //ARS加密
        $parameters['sec_key'] = $this->encryptRSA($secKey, config('system.RSA.public_key'));//sec_key加密：使用平台公钥
        $requestRes = $this->postJsonSync($requestUrl, json_encode($parameters,256));
        $requestRes = json_decode($requestRes, 1);
        $res = false;
        if (!empty($requestRes) && !empty($requestRes['data'] ?? [])) {
            $requestResData = json_decode($requestRes['data'], 1);
            if (in_array($requestResData['order_status'], ['P1000', 'P3000'])) {
                $res = true;
            } else {
                $errorMsg = $requestResData['err_msg'] ?? '支付短信下发失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['biz_msg'] ?? '支付短信下发失败, 请稍后重试';
        }


        //记录日志
        $this->log(['type' => 'agreementSmsForPay', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data, 'orderResData' => $requestResData ?? []]);

        return ['res' => $res, 'errorMsg' => $errorMsg ?? null, 'biz_msg' => $requestRes['biz_msg'] ?? null, 'biz_code' => $requestRes['biz_code'] ?? null, 'orderResData' => $requestResData ?? []];
    }

    /**
     * @title  协议支付退款
     * @param array $data
     * @return mixed
     */
    public function agreementRefund(array $data)
    {
        $requestUrl = 'https://api.joinpay.com/refund';

        $secKey = getUid(16);
        $parametersData["org_mch_order_no"] = trim($data['out_trade_no']);
        $parametersData["refund_order_no"] = trim($data['out_refund_no']);
        $parametersData['refund_amount'] = $data['refund_fee'];
        $parametersData['refund_reason'] = $data['refund_desc'] ?? '商城退款';
        $parametersData['callback_url'] = $data['notify_url'] ?? config('system.callback.joinPayAgreementRefundCallBackUrl');
        $parametersData["org_mch_req_time"] = date('Y-m-d H:i:s', $data['order_create_time']);

        $parameters['method'] = 'fastPay.refund';
        $parameters['version'] = '1.0';
        $parameters['data'] = json_encode($parametersData,256);
        $parameters['rand_str'] = getUid(32);
        $parameters['sign_type'] = 2;
        $parameters['mch_no'] = $this->MerchantNo;
        $parameters['sign'] = $this->buildSign($parameters,2);
        //ARS加密
        $parameters['sec_key'] = $this->encryptRSA($secKey, config('system.RSA.public_key'));//sec_key加密：使用平台公钥
        $requestRes = $this->postJsonSync($requestUrl, json_encode($parameters,256));
        $requestRes = json_decode($requestRes, 1);
        $res = false;
        if (!empty($requestRes) && !empty($requestRes['data'] ?? [])) {
            $requestResData = json_decode($requestRes['data'], 1);
            if (in_array($requestResData['refund_status'], ['100', '102'])) {
                $res = true;
            } else {
                $errorMsg = $requestResData['err_msg'] ?? '退款发起失败, 请稍后重试';
            }
        } else {
            $errorMsg = $requestRes['biz_msg'] ?? '退款发起失败, 请稍后重试';
        }


        //记录日志
        $this->log(['type' => 'agreementRefund', 'param' => $parameters, 'returnData' => $requestRes, 'data' => $data]);

        if (!$res) {
            if (empty($data['notThrowError'] ?? false)) {
                throw new JoinPayException(['msg' => $errorMsg ?? '服务有误']);
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
                    "Content-type: application/json;charset='utf-8'",
                );
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data,256));
            } else {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
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
            throw new JoinPayException(['msg' => 'aes加密失败']);
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
            throw new JoinPayException(['msg' => 'aes解密失败']);
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
            throw new JoinPayException(['msg'=>"rsa解密公钥无效，修改建议：平台公钥代码中格式为：首行-----BEGIN PUBLIC KEY-----；第二行平台公钥；第三行-----END PUBLIC KEY-----"]);
            echo "<br>";
        }

        $crypted = '';
        $isSuccess = openssl_public_encrypt($data, $crypted, $pubKey);
        openssl_free_key($pubKey);
        if($isSuccess == false){
            throw new JoinPayException(['msg'=>"rsa加密失败"]);
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
            throw new JoinPayException(['msg'=>"rsa解密私钥无效"]);
        }

        $decrypted = '';
        $isSuccess = openssl_private_decrypt(base64_decode($data), $decrypted, $priKey);
        openssl_free_key($priKey);
        if(! $isSuccess){
            throw new JoinPayException(['msg'=>"rsa解密失败，请检查是否有遗漏加密敏感信息。"]);
        }
        return $decrypted;
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
            throw new JoinPayException(['msg'=> "rsa签名私钥无效修改建议：私钥代码中格式为：首行-----BEGIN RSA PRIVATE KEY-----；第二行私钥；第三行-----END RSA PRIVATE KEY-----"]);
        }

        $binary_signature = '';
        $isSuccess = openssl_sign($data, $binary_signature, $priKey, OPENSSL_ALGO_MD5);
        openssl_free_key($priKey);
        if(! $isSuccess){
            throw new JoinPayException(['msg'=>"rsa签名失败"]);
        }
        return base64_encode($binary_signature);
    }

    /**
     * 使用公钥进行验签
     * @param string $signData
     * @param string $signParam
     * @param string $pubKey
     * @return bool
     */
    public function verifyRSA(string $signData, string $signParam, string $pubKey){
        $pubKey = openssl_get_publickey($pubKey);
        if($pubKey === false){
            throw new JoinPayException(['msg'=>"rsa验签公钥无效"]);
        }

        $signParam = base64_decode($signParam);
        $isMatch = openssl_verify($signData, $signParam, $pubKey, OPENSSL_ALGO_MD5);
        openssl_free_key($pubKey);
        return $isMatch;
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