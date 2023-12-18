<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 杉德支付结果通知回调Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\callback\v1;

use app\lib\exceptions\JoinPayException;
use app\lib\exceptions\SandPayException;
use app\lib\models\AfterSale;
use app\lib\models\Withdraw;
use app\lib\services\Finance;
use app\lib\services\JoinPay;
use app\lib\services\Log;
use app\lib\services\Pay;
use app\lib\services\Ppyl;
use app\lib\services\SandPay;
use app\lib\services\WxPayService;
use think\facade\Request;

class SandPayCallback
{
    /**
     * @Name   接受订单支付异步通知
     * @author  Coder
     * @date   2019年05月22日 15:26
     */
    public function payCallBack()
    {
        $callbackParam = Request::param();
        $joinPay = (new SandPay());

        //校验签名参数
        $checkSignRes = $this->checkSign($callbackParam);

        if (!$checkSignRes) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new JoinPayException(['errorCode' => 29003]);
        }

        //对账单
        $orderQuery = $joinPay->orderQuery($callbackParam['r2_OrderNo']);

        //记录日志
        $log['orderQuery'] = $orderQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($orderQuery['status'] == 100 && $callbackParam['r6_Status'] == 100) {
            $callbackParam['out_trade_no'] = $callbackParam['r2_OrderNo'];
            $callbackParam['transaction_id'] = $callbackParam['r7_TrxNo'];
            $callbackParam['otherMap'] = $callbackParam['r5_Mp'] ?? null;
            (new Pay())->completePay($callbackParam);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }

    }

    /**
     * @Name   接受订单协议(银行卡)支付异步通知
     * @author  Coder
     */
    public function agreementPayCallBack()
    {
        $callbackParam = Request::param();
        $sandPay = (new SandPay());
        unset($callbackParam['version']);

        //校验签名参数
        $checkSignRes = $this->checkSignRAS($callbackParam);
        $log['type'] = 'sandPayAgreementPay';

        if (!$checkSignRes || empty($callbackParam['data'] ?? null) || (!empty($callbackParam['data'] ?? null) && empty(json_decode($callbackParam['data'], true)))) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new SandPayException(['errorCode' => 29003]);
        }

        $callbackData = json_decode($callbackParam['data'], true)['body'];

        //对账单
        $orderQuery = $sandPay->agreementOrderQuery($callbackData['orderCode']);

        //记录日志
        $log['orderQuery'] = $orderQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($orderQuery['status'] == 100 && $callbackData['orderStatus'] == 1) {
            $callbackParamData['out_trade_no'] = $callbackData['orderCode'];
            $callbackParamData['transaction_id'] = $callbackData['payOrderCode'] ?? $callbackData['tradeNo'];
            $callbackParamData['otherMap'] = $callbackData['extend'] ?? null;
            $callbackParamData['bank_trx_no'] = $callbackData['cardNo'] ?? null;
            $callbackParamData['pay_success_time'] = $callbackData['payTime'] ?? null;
            (new Pay())->completePay($callbackParamData);
            $returnMsg = 'respCode=000000';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }

    }


    /**
     * @Name   接受退款异步通知
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function refundCallback()
    {
        $callbackParam = Request::param();
        $joinPay = (new JoinPay());
        $log['type'] = 'normalRefund';

        //校验签名参数
        $checkSignRes = $this->checkSignRAS($callbackParam);

        if (!$checkSignRes || empty($callbackParam['data'] ?? null) || (!empty($callbackParam['data'] ?? null) && empty(json_decode($callbackParam['data'], true)))) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new JoinPayException(['errorCode' => 29003]);
        }
        $callbackData = json_decode($callbackParam['data'], true)['body'];

        //对账单
        $refundQuery = $joinPay->refundQuery($callbackData['orderCode']);

        //记录日志
        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackData['ra_Status'] == 100) {
            $callbackParam['out_trade_no'] = $callbackData['orderCode'];
            $callbackParam['out_refund_no'] = $callbackData['r3_RefundOrderNo'];
            (new Pay())->completeRefund($callbackParam);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }
    }

    /**
     * @Name   接受退款异步通知<设备订单专用>
     * @author  Coder
     * @date   2022年08月18日 21:18
     */
    public function deviceRefundCallback()
    {
        $callbackParam = Request::param();
        $joinPay = (new JoinPay());
        $log['type'] = 'deviceRefund';

        //校验签名参数
        $checkSignRes = $this->checkSign($callbackParam);

        if (!$checkSignRes) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new JoinPayException(['errorCode' => 29003]);
        }

        //对账单
        $refundQuery = $joinPay->refundQuery($callbackParam['r3_RefundOrderNo']);

        //记录日志

        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackParam['ra_Status'] == 100) {
            $callbackParam['out_trade_no'] = $callbackParam['r2_OrderNo'];
            $callbackParam['out_refund_no'] = $callbackParam['r3_RefundOrderNo'];
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
     * @Name   接受退款异步通知<协议(银行卡)支付专用>
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function agreementRefundCallback()
    {
        $callbackParam = Request::param();
        $sandPay = (new SandPay());
        $log['type'] = 'agreementRefund';
        unset($callbackParam['version']);
        //校验签名参数
        $checkSignRes = $this->checkSignRAS($callbackParam);

        if (!$checkSignRes || empty($callbackParam['data'] ?? null) || (!empty($callbackParam['data'] ?? null) && empty(json_decode($callbackParam['data'], true)))) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new JoinPayException(['errorCode' => 29003]);
        }

        $callbackData = json_decode($callbackParam['data'], true)['body'];


        //对账单
        $refundQuery = $sandPay->agreementRefundQuery($callbackData['oriOrderCode']);

        //记录日志
        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackData['orderStatus'] == 1) {
            $callbackParam['out_trade_no'] = $callbackData['oriOrderCode'];
            $callbackParam['out_refund_no'] = $callbackData['orderCode'];
            (new Pay())->completeRefund($callbackParam);
            $returnMsg = 'respCode=000000';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }
    }

    /**
     * @Name   接受退款异步通知<拼团超时专用>
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function ptRefundCallback()
    {
        $callbackParam = Request::param();
        $joinPay = (new JoinPay());
        $log['type'] = 'ptRefund';

        //校验签名参数
        $checkSignRes = $this->checkSign($callbackParam);

        if (!$checkSignRes) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new JoinPayException(['errorCode' => 29003]);
        }

        //对账单
        $refundQuery = $joinPay->refundQuery($callbackParam['r3_RefundOrderNo']);

        //记录日志
        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackParam['ra_Status'] == 100) {
            $callbackParam['out_trade_no'] = $callbackParam['r2_OrderNo'];
            $callbackParam['out_refund_no'] = $callbackParam['r3_RefundOrderNo'];
            (new Pay())->completePtRefund($callbackParam);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }
    }

    /**
     * @Name   接受退款异步通知<拼拼有礼超时专用>
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function ppylRefundCallback()
    {
        $callbackParam = Request::param();

        $log['type'] = 'ppylRefund';
        $log['rawData'] = $callbackParam ?? [];
        $otherParam = null;
        if (!empty($callbackParam['now']) && empty($callbackParam['r1_MerchantNo'] ?? null)) {
            $otherParam = substr($callbackParam['now'], 0, strpos($callbackParam['now'], '?r1_MerchantNo'));
            $r1_MerchantNo = explode('=', substr($callbackParam['now'], strpos($callbackParam['now'], "r1_MerchantNo")));
            $MerchantNo['r1_MerchantNo'] = $r1_MerchantNo[1];
            unset($callbackParam['now']);
            $callbackParam = array_merge_recursive($MerchantNo, $callbackParam);
        }
        $joinPay = (new JoinPay());


        //校验签名参数
        $checkSignRes = $this->checkSign($callbackParam);

        if (!$checkSignRes) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new JoinPayException(['errorCode' => 29003]);
        }

        //对账单
        $refundQuery = $joinPay->refundQuery($callbackParam['r3_RefundOrderNo']);

        //记录日志

        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackParam['ra_Status'] == 100) {
            $callbackParam['out_trade_no'] = $callbackParam['r2_OrderNo'];
            $callbackParam['out_refund_no'] = $callbackParam['r3_RefundOrderNo'];
            $callbackParam['now'] = $otherParam ?? null;
            (new Ppyl())->completePpylRefund($callbackParam);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }
    }

    /**
     * @Name   接受退款异步通知<拼拼有礼排队超时专用>
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function ppylWaitRefundCallback()
    {
        $callbackParam = Request::param();
        $log['type'] = 'ppylWaitRefund';
        $log['rawData'] = $callbackParam ?? [];

        $otherParam = null;
        if (!empty($callbackParam['now']) && empty($callbackParam['r1_MerchantNo'] ?? null)) {
            $otherParam = substr($callbackParam['now'], 0, strpos($callbackParam['now'], '?r1_MerchantNo'));
            $r1_MerchantNo = explode('=', substr($callbackParam['now'], strpos($callbackParam['now'], "r1_MerchantNo")));
            $MerchantNo['r1_MerchantNo'] = $r1_MerchantNo[1];
            unset($callbackParam['now']);
            $callbackParam = array_merge_recursive($MerchantNo, $callbackParam);
        }

        $joinPay = (new JoinPay());


        //校验签名参数
        $checkSignRes = $this->checkSign($callbackParam);

        if (!$checkSignRes) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new JoinPayException(['errorCode' => 29003]);
        }

        //对账单
        $refundQuery = $joinPay->refundQuery($callbackParam['r3_RefundOrderNo']);

        //记录日志

        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackParam['ra_Status'] == 100) {
            $callbackParam['out_trade_no'] = $callbackParam['r2_OrderNo'];
            $callbackParam['out_refund_no'] = $callbackParam['r3_RefundOrderNo'];
            $callbackParam['now'] = $otherParam ?? null;
            (new Ppyl())->completePpylWaitRefund($callbackParam);
            $returnMsg = 'success';
            echo $returnMsg;
        } else {
            return returnMsg(false);
        }
    }

    /**
     * @Name   接受退款异步通知<拼拼有礼寄售退款>
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function ppylWinRefundCallback()
    {
        $callbackParam = Request::param();

        $log['type'] = 'ppylWinRefund';
        $log['rawData'] = $callbackParam ?? [];
        $otherParam = null;
        if (!empty($callbackParam['now']) && empty($callbackParam['r1_MerchantNo'] ?? null)) {
            $otherParam = substr($callbackParam['now'], 0, strpos($callbackParam['now'], '?r1_MerchantNo'));
            $r1_MerchantNo = explode('=', substr($callbackParam['now'], strpos($callbackParam['now'], "r1_MerchantNo")));
            $MerchantNo['r1_MerchantNo'] = $r1_MerchantNo[1];
            unset($callbackParam['now']);
            $callbackParam = array_merge_recursive($MerchantNo, $callbackParam);
        }
        $joinPay = (new JoinPay());


        //校验签名参数
        $checkSignRes = $this->checkSign($callbackParam);

        if (!$checkSignRes) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new JoinPayException(['errorCode' => 29003]);
        }

        //对账单
        $refundQuery = $joinPay->refundQuery($callbackParam['r3_RefundOrderNo']);

        //记录日志

        $log['refundQuery'] = $refundQuery;
        $log['callbackParam'] = $callbackParam;
        $this->log($log, 'info');

        if ($refundQuery['status'] == 100 && $callbackParam['ra_Status'] == 100) {
            $callbackParam['out_trade_no'] = $callbackParam['r2_OrderNo'];
            $callbackParam['out_refund_no'] = $callbackParam['r3_RefundOrderNo'];
            $callbackParam['now'] = $otherParam ?? null;
            (new Ppyl())->completePpylRepurchaseRefund($callbackParam);
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
        $sandPay = (new SandPay());
        $log['type'] = 'payment';


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
        $paymentQuery = $sandPay->paymentQuery($callbackParam['orderCode']);


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

        $sign = (new JoinPay())->buildSign($signParam);

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
        $res = false;
        $res = (new SandPay())->verifyRSA($callbackParam['data'], $callbackParam['sign']);
        return $res;
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