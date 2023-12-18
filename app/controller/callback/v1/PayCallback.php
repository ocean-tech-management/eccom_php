<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 微信支付结果通知回调Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\callback\v1;


use app\lib\exceptions\JoinPayException;
use app\lib\services\JoinPay;
use app\lib\services\Log;
use app\lib\services\Pay;
use app\lib\services\Ppyl;
use app\lib\services\WxPayService;
use think\facade\Request;

class PayCallback
{
    /**
     * @Name   接受订单支付微信异步通知
     * @author  Coder
     * @date   2019年05月22日 15:26
     */
    public function payCallBack()
    {
        $WxPay = new WxPayService();
        //用TP Request类的input方法代替 file_get_contents('php://input');
        $callbackXml = Request::getInput();
        if ($callbackXml) {
            $wxRes = $WxPay->callback($callbackXml);
            if ($wxRes) {
                $orderQuery = $WxPay->orderQuery($wxRes['out_trade_no']);
                if ($orderQuery['status'] == 100) {
                    $wxRes['otherMap'] = $wxRes['attach'] ?? null;
                    (new Pay())->completePay($wxRes);
                }
                $this->log($wxRes, 'info');
            }
            $returnXml = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            echo $returnXml;
        } else {
            return returnMsg(false);
        }
    }

    /**
     * @Name   接受会员支付微信异步通知
     * @author  Coder
     * @date   2019年05月22日 15:26
     */
    public function memberCallBack()
    {
        $WxPay = new WxPayService();
        //用TP Request类的input方法代替 file_get_contents('php://input');
        $callbackXml = Request::getInput();
        if ($callbackXml) {
            $wxRes = $WxPay->callback($callbackXml);
            if ($wxRes) {
                $orderQuery = $WxPay->orderQuery($wxRes['out_trade_no']);
                if ($orderQuery['status'] == 100) {
                    (new Pay())->completeMember($wxRes);
                }
                $this->log($wxRes, 'info');
            }
            $returnXml = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            echo $returnXml;
        } else {
            return returnMsg(false);
        }
    }

    /**
     * @Name   接受退款微信异步通知<拼团超时专用>
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function ptRefundCallback()
    {
        $WxPay = new WxPayService();
        //用TP Request类的input方法代替 file_get_contents('php://input');
        $callbackXml = Request::getInput();
        if ($callbackXml) {
            //接受参数
            $wxRes = $WxPay->callback($callbackXml, false);
            $this->log($wxRes, 'info');
            if (!empty($wxRes)) {
                //解密数据
                $wxRes = $WxPay->decryptRefundCallbackData($wxRes);
                if ($wxRes) {
                    //退款对账单
                    $orderQuery = $WxPay->refundQuery($wxRes['decrypt_req_info']['out_refund_no'], $wxRes['decrypt_req_info']['out_trade_no']);
                    //对账单成功才进入业务处理
                    if ($orderQuery['status'] == 100) {
                        $aRefund = $wxRes['decrypt_req_info'];
                        (new Pay())->completePtRefund($aRefund);
                    }
                }
            }

            $this->log($wxRes, 'info');
            $returnXml = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            echo $returnXml;
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
        $WxPay = new WxPayService();
        //用TP Request类的input方法代替 file_get_contents('php://input');
        $callbackXml = Request::getInput();
        if ($callbackXml) {
            //接受参数
            $wxRes = $WxPay->callback($callbackXml, false);
            $log['logType'] = 'ppylRefund';
            $log['rawData'] = $wxRes ?? [];
            $log['otherParam'] = Request::param();
            $this->log($log, 'info');

            if (!empty($wxRes)) {
                //解密数据
                $wxRes = $WxPay->decryptRefundCallbackData($wxRes);
                if ($wxRes) {
                    $otherParam = Request::param('now');

                    //退款对账单
                    $orderQuery = $WxPay->refundQuery($wxRes['decrypt_req_info']['out_refund_no']);
                    //对账单成功才进入业务处理
                    if ($orderQuery['status'] == 100) {
                        $aRefund = $wxRes['decrypt_req_info'];
                        $aRefund['now'] = $otherParam ?? null;
                        (new Ppyl())->completePpylRefund($aRefund);
                    }
                }
            }

            $this->log($wxRes, 'info');
            $returnXml = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            echo $returnXml;
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
        $WxPay = new WxPayService();
        //用TP Request类的input方法代替 file_get_contents('php://input');
        $callbackXml = Request::getInput();
        if ($callbackXml) {
            //接受参数
            $wxRes = $WxPay->callback($callbackXml, false);
            $log['logType'] = 'ppylWaitRefund';
            $log['rawData'] = $wxRes ?? [];
            $log['otherParam'] = Request::param();
            $this->log($log, 'info');

            if (!empty($wxRes)) {
                //解密数据
                $wxRes = $WxPay->decryptRefundCallbackData($wxRes);
                if ($wxRes) {
                    $otherParam = Request::param('now');

                    //退款对账单
                    $orderQuery = $WxPay->refundQuery($wxRes['decrypt_req_info']['out_refund_no']);
                    //对账单成功才进入业务处理
                    if ($orderQuery['status'] == 100) {
                        $aRefund = $wxRes['decrypt_req_info'];
                        $aRefund['now'] = $otherParam ?? null;
                        (new Ppyl())->completePpylWaitRefund($aRefund);
                    }
                }
            }

            $this->log($wxRes, 'info');
            $returnXml = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            echo $returnXml;
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
        $WxPay = new WxPayService();
        //用TP Request类的input方法代替 file_get_contents('php://input');
        $callbackXml = Request::getInput();
        if ($callbackXml) {
            //接受参数
            $wxRes = $WxPay->callback($callbackXml, false);
            $log['logType'] = 'ppylWinRefund';
            $log['rawData'] = $wxRes ?? [];
            $log['otherParam'] = Request::param();
            $this->log($log, 'info');

            if (!empty($wxRes)) {
                //解密数据
                $wxRes = $WxPay->decryptRefundCallbackData($wxRes);
                if ($wxRes) {
                    $otherParam = Request::param('now');

                    //退款对账单
                    $orderQuery = $WxPay->refundQuery($wxRes['decrypt_req_info']['out_refund_no']);
                    //对账单成功才进入业务处理
                    if ($orderQuery['status'] == 100) {
                        $aRefund = $wxRes['decrypt_req_info'];
                        $aRefund['now'] = $otherParam ?? null;
                        (new Ppyl())->completePpylRepurchaseRefund($aRefund);
                    }
                }
            }

            $this->log($wxRes, 'info');
            $returnXml = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            echo $returnXml;
        } else {
            return returnMsg(false);
        }
    }


    /**
     * @Name   接受退款微信异步通知
     * @author  Coder
     * @date   2020年07月22日 21:18
     */
    public function refundCallback()
    {
        $WxPay = new WxPayService();
        //用TP Request类的input方法代替 file_get_contents('php://input');
        $callbackXml = Request::getInput();
        if ($callbackXml) {
            //接受参数
            $wxRes = $WxPay->callback($callbackXml, false);
            $this->log($wxRes, 'info');
            if (!empty($wxRes)) {
                //解密数据
                $wxRes = $WxPay->decryptRefundCallbackData($wxRes);
                if ($wxRes) {
                    //退款对账单
                    $orderQuery = $WxPay->refundQuery($wxRes['decrypt_req_info']['out_refund_no']);
                    //对账单成功才进入业务处理
                    if ($orderQuery['status'] == 100) {
                        $aRefund = $wxRes['decrypt_req_info'];
                        (new Pay())->completeRefund($aRefund);
                    }
                }
            }

            $this->log($wxRes, 'info');
            $returnXml = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            echo $returnXml;
        } else {
            return returnMsg(false);
        }
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