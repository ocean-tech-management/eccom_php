<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼专场模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\PpylArea as PplyAreaModel;

class PpylArea extends BaseValidate
{
    public $errorCode = 500118;

    protected $rule = [
        'activity_code' => 'require',
        'area_code' => 'require',
        'name' => 'require|checkNameC',
        'limit_type' => 'require|number',
        'start_time' => 'require|date',
        'end_time' => 'require|date|>:start_time',
        'join_number' => 'require|number|>=:1',
        'join_limit_type' => 'require|number',
        'lottery_delay_time' => 'number',
        'status' => 'number|in:-1'

    ];

    protected $message = [
        'activity_code.require' => '活动编码有误',
        'area_code.require' => '专场编码有误',
        'name.require' => '活动标题必填',
        'limit_type.require' => '活动时间类型必需',
        'limit_type.number' => '活动类型必须为数字',
//        'start_user_type.require' => '开团用户对象必需',
//        'start_user_type.number' => '开团用户对象必须为数字',
//        'join_user_type.require' => '参团用户对象必需',
//        'join_user_type.number' => '参团用户对象必须为数字',
        'start_time.require' => '活动开始时间必需',
        'start_time.date' => '活动开始时间必须为日期格式',
        'end_time.require' => '活动结束时间必需',
        'end_time.egt' => '活动结束时间必须大于活动开始时间',
        'join_number.require' => '每个人参加此团的次数必需',
        'join_number.number' => '每个人参加此团的次数必须为整数',
        'join_number.egt' => '每个人参加此团的次数必须为一次以上',
        'lottery_delay_time.number' => '抽奖时间必须为数字,以秒为单位',
        'status.in' => '非法状态字段',
    ];

    protected $scene = [
        'create' => ['activity_code', 'name', 'limit_type', 'start_time', 'end_time', 'join_limit_type', 'join_number','lottery_delay_time'],
        'edit' => ['activity_code', 'area_code', 'name', 'limit_type', 'start_time', 'end_time', 'join_limit_type', 'join_number','lottery_delay_time'],
        'delete' => ['activity_code', 'area_code', 'status'],
    ];

    public function sceneEdit()
    {
        return $this->remove('name', 'checkNameC')
            ->append('name', 'checkNameE');
    }

    public function checkNameC($value, $rule, $data, $fieldName)
    {
        $exist = (new PplyAreaModel())->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该活动专场名称已存在';
    }

    public function checkNameE($value, $rule, $data, $fieldName)
    {
        $map[] = ['activity_code', '<>', $data['activity_code']];
        $map[] = ['area_code', '<>', $data['area_code']];
        $exist = (new PplyAreaModel())->where($map)->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该活动专场名称已存在';
    }
}