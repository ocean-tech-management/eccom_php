<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 优惠券模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;

class Coupon extends BaseValidate
{
    public $errorCode = 500103;

    protected $rule = [
        'code' => 'require',
        'name' => 'require',
        'type' => 'require|checkType',
        'used' => 'require|checkUsed',
        'number' => 'require',
        'belong_type' => 'require|number',
        'used_amount' => 'require|checkUserAmount',
        'start_time' => 'require',
        'end_time' => 'require',
        'with_discount' => 'checkDis',
        'valid_type' => 'checkValidType',
        'with_condition' => 'checkCondition',
        'with_amount' => 'between:0,999999'
    ];

    protected $message = [
        'code.require' => '编码必填',
        'name.require' => '名称必填',
        'type.require' => '类型必选',
        'used.require' => '场景必选',
        'number.require' => '发券数量必填',
        'belong_type.require' => '应用平台必填',
        'belong_type.number' => '应用平台类型只能为数字',
        'used_amount.require' => '金额或折扣上线必填',
        'start_time.require' => '领券开始时间必填',
        'end_time.require' => '领券结束时间必填',
        'with_amount.between' => '金额取值范围为0-999999元',
    ];

    protected $scene = [
        'create' => ['name', 'type', 'used', 'number', 'belong_type', 'used_amount', 'start_time', 'end_time', 'with_discount', 'valid_type', 'with_amount'],
        'edit' => ['code', 'name', 'type', 'used', 'number', 'belong_type', 'used_amount', 'start_time', 'end_time', 'with_discount', 'valid_type', 'with_amount']
    ];

    /**
     * @title  判断类型是否符合规则
     * @param  $value
     * @param  $rule
     * @param  $data
     * @param  $fieldName
     * @return bool|string
     */
    public function checkType($value, $rule, $data, $fieldName)
    {
        switch ($value) {
            case 3:
                if (!empty((double)$data['with_amount']) && $data['with_condition'] == 1) {
                    $msg = '无门槛券不能有使用条件,门槛金额只能为0.00哦~';
                }
                break;
            case 4:
                if (empty((double)$data['with_discount']) || empty((double)$data['used_amount'])) {
                    $msg = '折扣券类型优惠券折扣和金额上限必填';
                }
                break;
            default:
                break;
        }
        return !empty($msg) ? $msg : true;
    }

    /**
     * @title  判断应用场景是否符合规则
     * @param  $value
     * @param  $rule
     * @param  $data
     * @param  $fieldName
     * @return bool|string
     */
    public function checkUsed($value, $rule, $data, $fieldName)
    {
        switch ($value) {
            case 20:
                if (empty($data['with_category'])) {
                    $msg = '类目优惠券请选择指定类目';
                }
                break;
            case 30:
                if (empty($data['with_goods_sn'])) {
                    $msg = '商品优惠券请选择指定商品';
                }
                break;
            default:
                break;
        }

        return !empty($msg) ? $msg : true;
    }

    /**
     * @title  检查折扣额度是否合法
     * @param  $value
     * @param  $rule
     * @param  $data
     * @param  $fieldName
     * @return bool|string
     */
    public function checkDis($value, $rule, $data, $fieldName)
    {
        $dis = doubleval($value);
        if ($dis < 0.00 || $dis > 1.00) {
            $msg = '折扣额度非法';
        }
        return !empty($msg) ? $msg : true;
    }

    /**
     * @title  检查优惠券时效类型
     * @param  $value
     * @param  $rule
     * @param  $data
     * @param  $fieldName
     * @return bool|string
     */
    public function checkValidType($value, $rule, $data, $fieldName)
    {
        if ($value == 1) {
            if (empty($data['valid_start_time']) || empty($data['valid_end_time'])) {
                $msg = '绝对时效需要设置生效的开始和结束时间';
            }
        } elseif ($value == 2) {
            if (empty($data['valid_days'])) {
                $msg = '相对时效需要设置时效日期';
            }
        }
        return !empty($msg) ? $msg : true;
    }

    /**
     * @title  检查优惠券有无使用条件
     * @param  $value
     * @param  $rule
     * @param  $data
     * @param  $fieldName
     * @return bool|string
     */
    public function checkCondition($value, $rule, $data, $fieldName)
    {
        if ($value == 2) {
            if (empty((double)$data['with_amount'])) {
                $msg = '无使用门槛金额时系统应为无条件券';
            }
        }
        return !empty($msg) ? $msg : true;
    }

    /**
     * @title  检查优惠券金额不允许超过门槛金额
     * @param  $value
     * @param  $rule
     * @param  $data
     * @param  $fieldName
     * @return bool|string
     */
    public function checkUserAmount($value, $rule, $data, $fieldName)
    {
        $usedAmount = (double)$value;
        if (empty($usedAmount) || $usedAmount <= 0) {
            $msg = '优惠券金额不允许小于0';
        }
        if (!empty((double)$data['with_amount'])) {
            if ($value > (double)$data['with_amount']) {
                $msg = '优惠券金额不允许超过门槛金额';
            }
        }
        return !empty($msg) ? $msg : true;

    }
}