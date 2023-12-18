<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;

use app\lib\BaseValidate;

class Subject extends BaseValidate
{
    public $errorCode = 500102;

    protected $rule = [
        'id' => 'require',
        'name' => 'require',
        'lecturer_id' => 'require',
        'category_code' => 'require',
        'type' => 'require|checkType',
        'price' => 'checkPrice'
    ];

    protected $message = [
        'id.require' => '索引异常',
        'name.require' => '课程名称必填',
        'lecturer_id.require' => '讲师必选',
        'category_code.require' => '分类必选',
        'type.require' => '课程类型必选'
    ];

    protected $scene = [
        'create' => ['name', 'lecturer_id', 'category_code', 'price', 'type'],
        'edit' => ['id', 'name', 'lecturer_id', 'category_code', 'price', 'type'],
    ];

    /**
     * @title  限制金额
     * @param  $value
     * @param  $rule
     * @param  $data
     * @param  $fieldName
     * @return bool|string
     */
    public function checkPrice($value, $rule, $data, $fieldName)
    {
        if ($data['type'] != 2) {
            if (empty((double)$value) || (double)$value < 1) {
                $msg = '课程价格不允许低于1元';
            }
        }
        return !empty($msg) ? $msg : true;
    }

    /**
     * @title  检查课程类型
     * @param  $value
     * @param  $rule
     * @param  $data
     * @param  $fieldName
     * @return bool|string
     */
    public function checkType($value, $rule, $data, $fieldName)
    {
        if ($data['type'] == 2) {
            if (empty($data['valid_start_time']) || empty($data['valid_end_time'])) {
                $msg = '限时免费课程请选择时效';
            }
            if (!empty(doubleval($data['price']))) {
                $msg = '限时免费课程不允许设置价格哦';
            }
        }
        return !empty($msg) ? $msg : true;
    }

}