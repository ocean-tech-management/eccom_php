<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 设备智能开关模块Service]
// +----------------------------------------------------------------------



namespace app\lib\services;
use app\lib\models\Device;
use app\lib\models\DeviceOrder;
use app\lib\models\DevicePower as DevicePowerModel;
use think\facade\Queue;

class DevicePower
{
    /**
     * @title  记录消息体
     * @param array $data
     * @return bool
     */
    public function recordMsg(array $data)
    {
        if (empty($data)) {
            return false;
        }

        $save['username'] = $data['username'] ?? 'notfoundUser';
        $save['topic'] = $data['topic'] ?? 'notfoundUser';
        $save['time'] = $data['timestamp'] ? substr($data['timestamp'], 0, 10) : time();
        $save['qos'] = $data['qos'] ?? 0;
        $save['payload'] = $data['payload'] ?? null;
        $save['event'] = $data['event'] ?? null;
        $save['device_id'] = $data['topic'] ? (explode('/', $data['topic'])[2] ?? null) : null;
        $save['client_id'] = $data['clientid'] ?? null;
        $save['raw_content'] = json_encode($data, 256);
        //临时措施, 非平台设备信息不接受消息, 后续需要考虑做一个设备消息数据中岛
        if (!empty($save['device_id'] ?? null)) {
            if (!empty(cache('callbackDeviceList'))) {
                $deviceList = cache('callbackDeviceList');
            } else {
                $deviceList = Device::where(['status' => [1, 2]])->column('power_imei');
                if (!empty($deviceList)) {
                    cache('callbackDeviceList', $deviceList);
                }
            }
            if (!in_array($save['device_id'], $deviceList)) {
                return false;
            }
        }
        if (empty($data['checkAgain'] ?? null)) {
            DevicePowerModel::create($save);
        }

        //可能速度太快开启部分的数据库还没操作好, 2秒后重试
        if (empty(cache('startPower-' . $save['device_id'])) && empty($data['checkAgain'] ?? null)) {
            $queueData = $data;
            $queueData['autoType'] = 9;
            $queueData['checkAgain'] = true;
            Queue::later(2, 'app\lib\job\Auto', $queueData, config('system.queueAbbr') . 'Auto');
        }

        //检查是否有对应订单信息
        if(!empty($save['device_id'] ?? null) && !empty(cache('startPower-'.$save['device_id'])) && !empty($save['payload'] ?? null)){
            $powerNumberStatus = json_decode($save['payload'],true)['params'] ?? [];

            if(!empty($powerNumberStatus)){
                foreach ($powerNumberStatus as $key => $value) {
                    $powerNumber = 0;
                    if(strstr($key,'swstats')){
                        $powerNumber = substr($key,-1);
                        if(intval($powerNumber) > 0){
                            if (!empty(cache('powerStatus-' . $save['device_id']. '-'.$powerNumber))) {
                                //值不相同等于就是确定变更状态了(已经开启了)
                                if($value == cache('powerStatus-'.$save['device_id']. '-'.$powerNumber)){
                                    $changeStatusPowerNumber[] = $powerNumber;
                                }
                            }
                        }
                    }

                }
            }

            if (!empty($changeStatusPowerNumber)) {
                $existDeviceOrder = DeviceOrder::where(['device_power_imei' => $save['device_id'], 'device_power_number' => $changeStatusPowerNumber, 'order_status' => 2])->select()->toArray();
                if(!empty($existDeviceOrder)){
                    foreach ($existDeviceOrder as $key => $value) {
                        Queue::later((($value['device_end_time'] - $value['device_start_time']) + 2), 'app\lib\job\Auto', ['imei' => $value['device_power_imei'], 'type' => 0, 'power_number' => [$value['device_power_number'] => 0], 'autoType' => 8], config('system.queueAbbr') . 'Auto');
//                        Queue::push('app\lib\job\Auto', ['imei' => $value['device_power_imei'], 'type' => 0, 'power_number' => [$value['device_power_number'] => 0], 'autoType' => 8], config('system.queueAbbr') . 'Auto');
                    }
                }
            }

        }



        return true;
    }
}