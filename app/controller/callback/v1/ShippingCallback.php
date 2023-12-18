<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 快递100模块快递订阅回调]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\callback\v1;


use app\BaseController;
use app\lib\models\ShippingAbort;
use app\lib\services\Log;
use app\lib\services\Shipping;
use think\facade\Queue;
use think\facade\Request;
use app\lib\models\ShippingDetail as ShippingDetailModel;

class ShippingCallback extends BaseController
{
    public function callback()
    {
        $data = Request::param() ?? [];
        $shippingModel = new ShippingDetailModel();
        $res = false;
        $errorMsg = '接收失败,内容为空或非正确物流信息';

        if (!empty($data['param'])) {
            $body = json_decode($data['param'], true);
            $status = $body['status'] ?? '';
            $result = $body['lastResult'] ?? [];

            if (!empty($result)) {
                //如果遇到了中止查询的情况选择重新订阅,并记录数据库,仅重新订阅一次
                if ($status == 'abort') {
                    $abortNumber = ShippingAbort::where(['shipping_code' => trim($result['nu']), 'company' => trim($result['com'])])->count();
                    if ($abortNumber < 1) {
                        $abort['shipping_code'] = trim($result['nu']);
                        $abort['company'] = trim($result['com']);
                        $abort['abort_msg'] = $body['message'] ?? null;
                        ShippingAbort::create($abort);

                        unset($abort['abort_msg']);
                        (new Shipping())->subscribe($abort);
                        $errorMsg = '接收失败,物流中止,已重新订阅';
                    }
                }

                if (!empty($result['data'])) {
//                    $res = $shippingModel->subscribeNewOrEdit($result);
                    $result['autoType'] = 1;
                    $res = Queue::push('app\lib\job\Shipping', $result, config('system.queueAbbr') . 'Shipping');
                }
            }
        }
        if (!empty($res)) {
            $return = ['result' => true, 'returnCode' => 200, 'message' => '接收成功'];
        } else {
            $return = ['result' => false, 'returnCode' => 400, 'message' => $errorMsg];
        }
        //记录日志
        $log['data'] = $data;
        $log['receiveRes'] = $return;
        (new Log())->setChannel('shipping')->record($log, 'info');

        return json($return);
    }
}