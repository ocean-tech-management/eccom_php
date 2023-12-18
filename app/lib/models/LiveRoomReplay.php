<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;

class LiveRoomReplay extends BaseModel
{
    protected $validateFields = ['roomid'];

    public function DBNewOrEdit(array $data)
    {
        return $this->updateOrCreate(['roomid' => $data['roomid'], 'media_url' => $data['media_url']], $data);
    }

    public function getReplayCreateTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getExpireTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }
}