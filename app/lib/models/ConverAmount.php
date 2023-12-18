<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 售后模块流程明细图片Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;

class ConverAmount extends BaseModel
{   
    /**
     * @description: 添加
     * @param {*} $data
     * @return {*}
     */    
    public function new($data)
    {
        return $this->baseCreate($data, true);
    }

    /**
     * @description: 查找转出额度
     * @param {*} $data
     * @return {*}
     */    
    public function amount()
    {
        $amount = $this->where(['status' => 1])->limit(1)->order('create_time desc')->value('all_amount');
        return $amount;
    }
}