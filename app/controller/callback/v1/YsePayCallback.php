<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 银盛支付结果通知回调Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\callback\v1;

use app\lib\exceptions\YsePayException;
use app\lib\models\AfterSale;
use app\lib\models\Withdraw;
use app\lib\services\Finance;
use app\lib\services\Log;
use app\lib\services\Pay;
use app\lib\services\Ppyl;
use app\lib\services\YsePay;
use think\facade\Request;

class YsePayCallback
{

    private $apiVersion = 'v2.0.0';

    /**
     * @titel   接受订单支付异步通知
     * @author  Coder
     * @date   2019年05月22日 15:26
     */
    public function payCallBack()
    {
        $callbackParam = Request::param();
        $yesPay = (new YsePay());
        unset($callbackParam['version']);

        //校验签名参数
        $checkSignRes = $this->checkSignRAS($callbackParam);
        $log['type'] = 'ysePayNormalPay';

        if (!$checkSignRes || empty($callbackParam['bizResponseJson'] ?? null) || (!empty($callbackParam['bizResponseJson'] ?? null) && empty(json_decode($callbackParam['bizResponseJson'], true)))) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new YsePayException(['errorCode' => 32003]);
        }

        $callbackData = json_decode($callbackParam['bizResponseJson'], true);

        //对账单
        $orderQuery = $yesPay->orderQuery($callbackData['requestNo']);

        //记录日志
        $log['orderQuery'] = $orderQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($orderQuery['status'] == 100 && $callbackData['state'] == 'SUCCESS') {
            $callbackParamData['out_trade_no'] = $callbackData['requestNo'];
            $callbackParamData['transaction_id'] = $callbackData['tradeSn'] ?? $callbackData['requestNo'];
            $callbackParamData['otherMap'] = $callbackData['extendParams'] ?? null;
            $callbackParamData['pay_success_time'] = $callbackData['payTime'] ?? null;
            (new Pay())->completePay($callbackParamData);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }

    }

    /**
     * @title   接受订单协议(银行卡)支付异步通知
     * @author  Coder
     */
    public function agreementPayCallBack()
    {
        $callbackParam = Request::param();
        $ysePay = (new YsePay());
        unset($callbackParam['version']);

        //校验签名参数
        $checkSignRes = $this->checkSignRAS($callbackParam);
        $log['type'] = 'ysePayAgreementPay';

        if (!$checkSignRes || empty($callbackParam['bizResponseJson'] ?? null) || (!empty($callbackParam['bizResponseJson'] ?? null) && empty(json_decode($callbackParam['bizResponseJson'], true)))) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new YsePayException(['errorCode' => 32003]);
        }

        $callbackData = json_decode($callbackParam['bizResponseJson'], true);

        //对账单
        $orderQuery = $ysePay->agreementOrderQuery($callbackData['requestNo']);

        //记录日志
        $log['orderQuery'] = $orderQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($orderQuery['status'] == 100 && $callbackData['state'] == 'SUCCESS') {
            $callbackParamData['out_trade_no'] = $callbackData['requestNo'];
            $callbackParamData['transaction_id'] = $callbackData['tradeSn'] ?? $callbackData['requestNo'];
            $callbackParamData['otherMap'] = $callbackData['extendParams'] ?? null;
            $callbackParamData['pay_success_time'] = $callbackData['payTime'] ?? null;
            (new Pay())->completePay($callbackParamData);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }

    }


    /**
     * @title   接受退款异步通知
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function refundCallback()
    {
        $callbackParam = Request::param();
        $yesPay = (new YsePay());
        $log['type'] = 'ysePayNormalRefund';

        //校验签名参数
        $checkSignRes = $this->checkSignRAS($callbackParam);

        if (!$checkSignRes || empty($callbackParam['bizResponseJson'] ?? null) || (!empty($callbackParam['bizResponseJson'] ?? null) && empty(json_decode($callbackParam['bizResponseJson'], true)))) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new YsePayException(['errorCode' => 32003]);
        }

        $callbackData = json_decode($callbackParam['bizResponseJson'], true);

        //对账单
        $refundQuery = $yesPay->refundQuery($callbackData['requestNo']);

        //记录日志
        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackData['state'] == 'SUCCESS') {
            $callbackParam['out_trade_no'] = $callbackData['origRequestNo'];
            $callbackParam['out_refund_no'] = $callbackData['requestNo'];
            (new Pay())->completeRefund($callbackParam);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }
    }

    /**
     * @title   接受退款异步通知<设备订单专用>
     * @author  Coder
     * @date   2022年08月18日 21:18
     */
    public function deviceRefundCallback()
    {
        $callbackParam = Request::param();
        $yesPay = (new YsePay());
        $log['type'] = 'ysePayDeviceRefund';

        //校验签名参数
        $checkSignRes = $this->checkSignRAS($callbackParam);

        if (!$checkSignRes || empty($callbackParam['bizResponseJson'] ?? null) || (!empty($callbackParam['bizResponseJson'] ?? null) && empty(json_decode($callbackParam['bizResponseJson'], true)))) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new YsePayException(['errorCode' => 32003]);
        }

        $callbackData = json_decode($callbackParam['bizResponseJson'], true);

        //对账单
        $refundQuery = $yesPay->refundQuery($callbackData['requestNo']);

        //记录日志

        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackData['state'] == 'SUCCESS') {
            $callbackParam['out_trade_no'] = $callbackData['origRequestNo'];
            $callbackParam['out_refund_no'] = $callbackData['requestNo'];
            (new Pay())->completeDeviceRefund($callbackParam);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }
        //解除操作缓存锁
        cache('deviceOrderRefund-' . $callbackParam['out_trade_no'], null);
    }

    /**
     * @title   接受退款异步通知<协议(银行卡)支付专用>
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function agreementRefundCallback()
    {
        $callbackParam = Request::param();
        $yesPay = (new YsePay());
        $log['type'] = 'ysePayAgreementRefund';
        unset($callbackParam['version']);

        //校验签名参数
        $checkSignRes = $this->checkSignRAS($callbackParam);

        if (!$checkSignRes || empty($callbackParam['bizResponseJson'] ?? null) || (!empty($callbackParam['bizResponseJson'] ?? null) && empty(json_decode($callbackParam['bizResponseJson'], true)))) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new YsePayException(['errorCode' => 32003]);
        }

        $callbackData = json_decode($callbackParam['bizResponseJson'], true);

        //对账单
        $refundQuery = $yesPay->agreementRefundQuery($callbackData['requestNo']);

        //记录日志
        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackData['state'] == 'SUCCESS') {
            $callbackParam['out_trade_no'] = $callbackData['origRequestNo'];
            $callbackParam['out_refund_no'] = $callbackData['requestNo'];
            (new Pay())->completeRefund($callbackParam);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }
    }

    /**
     * @title   接受退款异步通知<拼团超时专用>
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function ptRefundCallback()
    {
        $callbackParam = Request::param();
        $yesPay = (new YsePay());
        $log['type'] = 'ysePayPtRefund';
        unset($callbackParam['version']);

        //校验签名参数
        $checkSignRes = $this->checkSignRAS($callbackParam);

        if (!$checkSignRes || empty($callbackParam['bizResponseJson'] ?? null) || (!empty($callbackParam['bizResponseJson'] ?? null) && empty(json_decode($callbackParam['bizResponseJson'], true)))) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new YsePayException(['errorCode' => 32003]);
        }

        $callbackData = json_decode($callbackParam['bizResponseJson'], true);

        //对账单
        $refundQuery = $yesPay->refundQuery($callbackData['requestNo']);

        //记录日志
        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackData['state'] == 'SUCCESS') {
            $callbackParam['out_trade_no'] = $callbackData['origRequestNo'];
            $callbackParam['out_refund_no'] = $callbackData['requestNo'];
            (new Pay())->completePtRefund($callbackParam);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }
    }


    /**
     * @title  接受代付异步通知
     * @return string
     */
    public function payment()
    {
        $callbackParam = Request::param();
        $ysePay = (new YsePay());
        $log['type'] = 'ysePayPayment';


        //校验签名参数
//        $checkSignRes = $this->checkSign($callbackParam);

//        if(!$checkSignRes){
//            $log['callbackParam'] = $callbackParam;
//            $log['message'] = '签名校验失败';
//            $this->log($log,'error');
//            return json(['statusCode'=>2002,'message'=>'签名校验失败'],404);
//        }
        $callbackParam = $callbackParam['data']['body'] ?? [];
        if(empty($callbackParam)){
            return json(['statusCode'=>2002,'message'=>'失败'],404);
        }
        //对账单
        $paymentQuery = $ysePay->paymentQuery($callbackParam['orderCode']);


        if ($paymentQuery['status'] == 100 && $callbackParam['status'] == 205) {
            $orderSn = $callbackParam['merchantOrderNo'];
            //加缓存锁防止多次提交
            if (!empty(cache('withdrawDeal-' . $orderSn))) {
                $msg = ['statusCode' => 2001, 'message' => '提交失败, 已存在处理中的数据'];
            } else {
                cache('withdrawDeal-' . $orderSn, $callbackParam, 60);
                //修改提现到账时间和流水号
                $savePayment['payment_no'] = $callbackParam['platformSerialNo'] ?? null;
                $savePayment['payment_time'] = timeToDateFormat(time());
                $withdrawInfo = Withdraw::where(['order_sn' => $orderSn])->findOrEmpty()->toArray();
                //如果已处理成功不需要重复处理
                if (!empty($withdrawInfo['payment_time'] ?? null) && $withdrawInfo['payment_status'] == 1) {
                    $msg = ['statusCode' => 2001, 'message' => '已存在处理成功记录, 无需重复处理'];
                } else {
                    (new Finance())->completeWithdraw($withdrawInfo, $savePayment);
                    $msg = ['statusCode' => 2001, 'message' => '成功'];
                }

            }
        } else {
            $orderSn = $callbackParam['merchantOrderNo'];
            //修改提现为失败
            $savePayment['payment_status'] = -1;
            $savePayment['payment_remark'] = $callbackParam['errorCodeDesc'] ?? '帐号异常,系统拦截';
            Withdraw::update($savePayment, ['order_sn' => $orderSn]);
            (new Finance())->withdrawFailure($orderSn);
            $msg = ['statusCode' => 2002, 'message' => '对账单失败'];
        }

        //记录日志
        $log['paymentQuery'] = $paymentQuery;
        $log['callbackParam'] = $callbackParam;
        $log['msg'] = $msg;

        $returnMsg = 'respCode=000000';
        if ($msg['statusCode'] == 2001) {
            $this->log($log, 'info');
            return $returnMsg;
        } else {
            $this->log($log, 'error');
            return json($msg, 404);
        }
    }


    /**
     * @title  支付回调校验签名
     * @param array $callbackParam
     * @return bool
     */
    public function checkSign(array $callbackParam)
    {
        if (empty($callbackParam) || empty($callbackParam['hmac'])) {
            return false;
        }
        if (!empty($callbackParam['ra_PayTime'] ?? null)) {
            $callbackParam['ra_PayTime'] = urldecode(urldecode($callbackParam['ra_PayTime']));
        }
        if (!empty($callbackParam['rb_DealTime'] ?? null)) {
            $callbackParam['rb_DealTime'] = urldecode(urldecode($callbackParam['rb_DealTime']));
        }

        $signParam = $callbackParam;
        unset($signParam['hmac']);
        unset($signParam['version']);

        $sign = (new YsePay())->buildSign($signParam);

        if ($sign != $callbackParam['hmac']) {
            return false;
        }

        return true;
    }

    /**
     * @title  支付回调校验签名-RAS版本
     * @param array $callbackParam
     * @return bool
     * @throws
     */
    public function checkSignRAS(array $callbackParam)
    {
        if (empty($callbackParam) || empty($callbackParam['sign'])) {
            return false;
        }
        //由于银盛支付接口参数中接口版本跟本系统版本参数冲突, 故此处验签固定版本为统一
        $callbackParam['version'] = $this->apiVersion;
        $buildSingParam = $callbackParam;
        unset($buildSingParam['sign']);

        $res = false;
        ksort($buildSingParam);
        $signStr = "";
        foreach ($buildSingParam as $key => $val) {
            $signStr .= $key . '=' . $val . '&';
        }

        $plainText = rtrim($signStr, '&');

        $res = (new YsePay())->verifyRSA($plainText, $callbackParam['sign']);
        return $res;
    }

    /**
     * @title 解析回调参数
     * @param $callbackParam
     * @return mixed
     */
    public function analysisParam($callbackParam)
    {
        if (empty($callbackParam) || !is_string($callbackParam)) {
            throw new YsePayException(['errorCode' => 32006]);
        }
        $callbackParam = explode('&', $callbackParam);
        return $callbackParam;
    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志信息
     * @param string $channel 日志通道
     * @return mixed
     */
    public function log(array $data, string $level = 'error', string $channel = 'callback')
    {
        return (new Log())->setChannel($channel)->record($data, $level);
    }
}