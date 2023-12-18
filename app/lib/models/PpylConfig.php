<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼全局配置模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;

class PpylConfig extends BaseModel
{
    /**
     * @title  配置详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
        $info = $this->where(['status'=>1])->findOrEmpty()->toArray();
        return $info;
    }

    public function DBEdit(array $data)
    {
        $res = self::update($data,['status'=>1]);
        return $res->getData();
    }
}