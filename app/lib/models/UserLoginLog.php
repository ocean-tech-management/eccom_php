<?php
// +----------------------------------------------------------------------
// |[ 文档说明: h5用户登录记录]
// +----------------------------------------------------------------------

namespace app\lib\models;

use app\BaseModel;
use think\facade\Request;

class UserLoginLog extends BaseModel
{
    public function new($data)
    {
        $log = [
            'uid' => $data['uid'],
            'share_id' => $data['share_id'],
            'phone' => $data['phone'],
            'name' => $data['name'],
            'avatar_url' => $data['avatarUrl'],
        ];
        $log['device_information'] = request()->server('HTTP_USER_AGENT') ?? null;
        $log['ip'] = request()->ip() ?? null;
        return $this->create($log);
    }
}