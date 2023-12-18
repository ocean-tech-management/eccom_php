<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼团模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\Brand as BrandModel;
use app\lib\models\PtActivity;

class Pt extends BaseValidate
{
    public $errorCode = 500111;

    protected $rule = [
        'activity_code' => 'require',
        'activity_title' => 'require|checkNameC',
        'activity_cover' => 'require',
        'type' => 'require|number',
        'start_user_type' => 'require|number',
        'join_user_type' => 'require|number',
        'start_time' => 'require|date',
        'end_time' => 'require|date|>:start_time',
        'expire_time' => 'require|number|>=:1200',
//        'expire_time' => 'require',
        'group_number' => 'require|number|>=:2',
        'join_number' => 'require|number|>=:1',
        'status' => 'number|in:-1'

    ];

    protected $message = [
        'activity_code.require' => '活动编码有误',
        'activity_title.require' => '活动标题必填',
        'activity_cover.require' => '活动封面必需',
        'type.require' => '活动类型必需',
        'type.number' => '活动类型必须为数字',
        'start_user_type.require' => '开团用户对象必需',
        'start_user_type.number' => '开团用户对象必须为数字',
        'join_user_type.require' => '参团用户对象必需',
        'join_user_type.number' => '参团用户对象必须为数字',
        'start_time.require' => '活动开始时间必需',
        'start_time.date' => '活动开始时间必须为日期格式',
        'end_time.require' => '活动结束时间必需',
        'end_time.egt' => '活动结束时间必须大于活动开始时间',
        'group_number.require' => '每个团人数必需',
        'group_number.number' => '每个团人数必须为整数',
        'group_number.egt' => '每个团人数必须有两个人以上',
        'expire_time.require' => '成团后有效期必需',
        'expire_time.number' => '成团有效期必须为秒级数字',
        'expire_time.egt' => '成团有效期必须大于二十分钟以上',
        'join_number.require' => '每个人参加此团的次数必需',
        'join_number.number' => '每个人参加此团的次数必须为整数',
        'join_number.egt' => '每个人参加此团的次数必须为一次以上',
        'activity_number.require' => '活动次数必需',
        'activity_number.number' => '活动次数必须为整数',
        'activity_number.egt' => '活动次数必须为一次以上',
        'status.in' => '非法状态字段',
    ];

    protected $scene = [
        'create' => ['activity_title', 'activity_cover', 'expire_time', 'type', 'start_user_type', 'join_user_type', 'start_time', 'end_time', 'group_number', 'join_number'],
        'edit' => ['activity_code', 'activity_title', 'activity_cover', 'expire_time', 'type', 'start_user_type', 'join_user_type', 'start_time', 'end_time', 'group_number', 'join_number'],
        'delete' => ['activity_code', 'status'],
    ];

    public function sceneEdit()
    {
        return $this->remove('group_number', 'require')
            ->remove('activity_title', 'checkNameC')
            ->append('activity_title', 'checkNameE');
    }

    public function checkNameC($value, $rule, $data, $fieldName)
    {
        $exist = (new PtActivity())->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该活动名称已存在';
    }

    public function checkNameE($value, $rule, $data, $fieldName)
    {
        $map[] = ['activity_code', '<>', $data['activity_code']];
        $exist = (new PtActivity())->where($map)->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该活动名称已存在';
    }
}