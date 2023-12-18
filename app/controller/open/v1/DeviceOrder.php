<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 开放平台设备订单模块Controller]
// +----------------------------------------------------------------------



namespace app\controller\open\v1;


use app\BaseController;
use app\lib\BaseException;
use app\lib\exceptions\OpenException;
use app\lib\exceptions\ServiceException;
use app\lib\models\Device;
use app\lib\models\DeviceOrder as DeviceOrderModel;
use app\lib\services\Log;
use think\Exception;
use think\facade\Request;

class DeviceOrder extends BaseController
{
    protected $middleware = [
        'checkOpenUser',
        'openRequestLog',
    ];

    /**
     * @title  检测参数是否完整传参
     * @param array $data
     * @return bool
     */
    public function checkParamFull(array $data)
    {
        $requestParam = Request::param();
        foreach ($data as $key => $value) {
            if (!in_array($value, $requestParam)) {
                throw new OpenException(['errorCode' => 2600110]);
            }
        }
        return true;
    }

    /**
     * @title  查询订单状态
     * @return mixed
     */
    public function orderCheck()
    {
        try {
            $this->checkParamFull(['orderSn', 'deviceSn']);
            $requestParam = Request::param();

            $orderInfo = DeviceOrderModel::where(['order_sn' => $requestParam['orderSn'], 'device_sn' => $requestParam['deviceSn']])->field('order_sn,device_sn,pay_status')->findOrEmpty()->toArray();
            $res['orderPayStatus'] = 100;
            $res['orderPayMsg'] = '已支付成功';
            $res['orderSn'] = $orderInfo['order_sn'];
            $res['deviceSn'] = $orderInfo['device_sn'];
            if (empty($orderInfo)) {
                $res['orderPayStatus'] = 400;
                $res['orderPayMsg'] = '查无订单数据';
            } else {
                if ($orderInfo['pay_status'] == 1) {
                    $res['orderPayStatus'] = 200;
                    $res['orderPayMsg'] = '未支付成功';
                }
            }
        } catch (BaseException $baseException) {
            return json(['error_code' => 30010, 'msg' => '业务处理异常']);
        } catch (\Exception $thinkException) {
            return json(['error_code' => 30010, 'msg' => '接口异常, 请联系运维']);
        }


        return returnData($res);
    }

    /**
     * @Name   接受订单支付异步通知
     * @author  Coder
     * @date   2019年05月22日 15:26
     */
    public function payCallBack()
    {
        $res = false;
        try {
            $this->checkParamFull(['orderSn', 'deviceSn','oStatus']);
            $callbackParam = Request::param();
            if (!empty($callbackParam['oStatus'] ?? null) && $callbackParam['oStatus'] == 100) {
                $callbackParamInfo['device_sn'] = $callbackParam['deviceSn'];
                $callbackParamInfo['order_sn'] = $callbackParam['orderSn'];
                (new Device())->confirmDeviceComplete($callbackParamInfo);
                $res = true;
            }
        } catch (BaseException $baseException) {
            return json(['error_code' => 30010, 'msg' => '业务处理异常']);
        } catch (\Exception $thinkException) {
            return json(['error_code' => 30010, 'msg' => '接口异常, 请联系运维']);
        }


        return returnMsg(true);
    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志信息
     * @param string $channel 日志通道
     * @return mixed
     */
    public function log(array $data, string $level = 'error', string $channel = 'open')
    {
        return (new Log())->setChannel($channel)->record($data, $level);
    }
}