<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 订单支付配置]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------

namespace app\lib\models;


use app\BaseModel;
use think\facade\Request;

class OrderPayArguments extends BaseModel
{
    public function new($data)
    {
        $create_data = [
            'order_sn' => $data['order_sn'],
            'pay_type' => $data['pay_type'],
            'channel' => $data['pay_channel'],
            'openid' => (new User)->getOpenIdByUid($data['uid']),
            'app_id' => config('system.clientConfig.'.getAccessKey())['app_id'],
        ];
        return $this->create($create_data);
    }

}