<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 设备模块Service]
// +----------------------------------------------------------------------



namespace app\lib\services;


use app\lib\exceptions\ServiceException;
use app\lib\models\DeviceCombo;
use app\lib\models\DeviceOrder;
use app\lib\models\Device as DeviceModel;
use think\facade\Db;
use think\facade\Queue;

class Device
{
    /**
     * @title  开启开关
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function startPower(array $data)
    {
        $orderSn = $data['order_sn'];
        $log['requestData'] = $data;
        if (empty($orderSn)) {
            return $this->recordError($log, ['msg' => '无有效信息']);
        }
        $orderInfo = DeviceOrder::where(['order_sn' => $orderSn, 'order_status' => 2])->findOrEmpty()->toArray();
        $log['orderInfo'] = $orderInfo;
        if (empty($orderInfo)) {
            return $this->recordError($log, ['msg' => '无有效订单信息']);
        }
        if (empty($orderInfo['device_combo_sn'] ?? null)) {
            return $this->recordError($log, ['msg' => '无有效设备套餐信息']);
        }
        $comboInfo = DeviceCombo::where(['combo_sn'=>$orderInfo['device_combo_sn'],'status'=>[1,2]])->findOrEmpty()->toArray();
        if (empty($comboInfo)) {
            return $this->recordError($log, ['msg' => '设备套餐已失效']);
        }
        $deviceInfo = DeviceModel::where(['device_sn'=>$orderInfo['device_sn'],'status'=>[1,2]])->findOrEmpty()->toArray();

        //开启机器
        $startPower = (new Mqtt())->publish(['imei' => $deviceInfo['power_imei'], 'type' => 1, 'power_number' => [$deviceInfo['power_number'] => 1]]);

        //修改套餐订单的开始时间
        $saveOrder['device_start_time'] = time();
        $saveOrder['device_end_time'] = time() + ($comboInfo['continue_time']);
//        DeviceOrder::update($saveOrder, ['order_sn' => $orderSn]);
        Db::name('device_order')->strict(false)->where(['order_sn' => $orderSn])->update($saveOrder);

        //修改对应的设备智能开关状态为开机
        DeviceModel::update(['power_status' => 1], ['power_imei' => $deviceInfo['power_imei'], 'power_number' => $deviceInfo['power_number'], 'status' => [1, 2]]);

        //存入缓存判断已经下发开启指令
        cache('startPower-' . $deviceInfo['power_imei'], 'online', 300);
        cache('powerStatus-' . $deviceInfo['power_imei'] . '-' . $deviceInfo['power_number'], 1, 300);
        //定时关闭--等设备回调开启开关成功再定时关闭
        return true;
    }

    /**
     * @title  关闭开关
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function closePower(array $data)
    {
        if (!empty($data['power_number'] ?? null)) {
            $powerNumber = key($data['power_number']);

            //查询是否到时间可以关闭机器了, 如果是因为特殊原因或bug导致的提前关机, 将取消此次关机操作, 延迟到应该关机的时间关机
            $orderInfo = DeviceOrder::where(['device_power_imei' => $data['imei'], 'device_power_number' => $powerNumber, 'order_status' => 2])->findOrEmpty()->toArray();
            if (empty($orderInfo)) {
                return false;
            }
            if (time() < $orderInfo['device_end_time']) {
                Queue::later((($orderInfo['device_end_time'] - time()) + 2), 'app\lib\job\Auto', ['imei' => $orderInfo['device_power_imei'], 'type' => 0, 'power_number' => [$orderInfo['device_power_number'] => 0], 'autoType' => 8], config('system.queueAbbr') . 'Auto');
                return true;
            }
        }
        (new Mqtt())->publish(['imei' => $data['imei'], 'type' => 0, 'power_number' => $data['power_number'] ?? [1 => 0]]);
        if (!empty($data['power_number'])) {
            $powerNumber = key($data['power_number']);

            //关闭成功去除对应的开启缓存标识
            cache('startPower-' . $data['imei'], null);
            cache('powerStatus-' . $data['imei'] . '-' . $powerNumber, null);
            //修改对应的设备智能开关状态为待机
            DeviceModel::update(['power_status' => 2], ['power_imei' => $data['imei'], 'power_number' => $powerNumber, 'status' => [1, 2]]);

            //查询是否有订单, 有则分润订单
            $orderInfo = DeviceOrder::where(['device_power_imei' => $data['imei'], 'device_power_number' => $powerNumber, 'order_status' => 2])->order('create_time asc')->findOrEmpty()->toArray();
            if (!empty($orderInfo)) {
                //完成订单,并分润
                (new DeviceModel())->confirmDeviceComplete(['device_sn' => $orderInfo['device_sn'], 'order_sn' => $orderInfo['order_sn']]);
            }
        }
        return true;
    }

    /**
     * @title  直接操作智能开关
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function directChangePower(array $data)
    {
        $type = $data['type'] ?? 1;
        if ($type == 1) {
            $powerStatus = 1;
        } else {
            $powerStatus = 0;
        }
        $imei = $data['imei'] ?? null;
        $powerNumber = $data['power_number'] ?? null;
        if (empty($imei) || empty($powerNumber)) {
            throw new ServiceException(['msg' => '请选择对应的智能开关']);
        }
        $operPower = [$data['power_number'] => intval($powerStatus)];
        (new Mqtt())->publish(['imei' => $data['imei'], 'type' => $powerStatus, 'power_number' => $operPower]);
        if ($type == 1) {
            $save['power_status'] = 1;
        } else {
            $save['power_status'] = 2;
        }
        DeviceModel::update($save, ['power_imei' => $data['imei'], 'power_number' => $data['power_number'], 'status' => [1, 2]]);
        return true;
    }

    /**
     * @title  记录错误并保存日志,删除该任务后终止
     * @param array $data 所有数据
     * @param array $error 错误内容
     * @return bool
     */
    public function recordError(array $data, array $error)
    {
        $allData['msg'] = '设备订单 ' . ($data['requestData']['order_sn'] ?? "<暂无订单编号>") . " [ 服务出错:" . ($error['msg'] ?? '原因未知') . " ] ";
        $allData['data'] = $data;
        $allData['error'] = $error;
        $this->log($allData, 'error');
        return false;
    }


    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'error')
    {
        return (new Log())->setChannel('device')->record($data, $level);
    }
}