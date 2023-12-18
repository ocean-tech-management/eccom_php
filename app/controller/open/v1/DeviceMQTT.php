<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\controller\open\v1;


use app\BaseController;
use app\lib\services\DevicePower;
use think\facade\Db;

class DeviceMQTT extends BaseController
{
    public function record()
    {
        $data = $this->requestData;
//        $res = Db::name('device_power')->save(['content'=>json_encode($data,true)]);
        (new DevicePower())->recordMsg($data);
        return returnMsg(true);
    }
}