<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 运费模版模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;

class UserPrivacy extends BaseValidate
{


    protected $rule = [
        'id' => 'checkExist',
        'uid' => 'checkUid',
        'name' => 'require|chsAlphaNum|length:2,15',
        'mobile' => 'require|number|length:11',
        'no' => 'require|user_no_check',
        'bank_account' => 'require'

    ];

    protected $message = [
        'name.require' => '真实姓名必填',
        'name.chsAlphaNum' => '真实姓名不能含有特殊符号',
        'name.length' => '真实姓名必须在2-15个字之间',
        'mobile.length' => '手机号码异常',
        'no.length' => '身份证号异常',
        'bank_account.length' => '银行卡号异常',
        'mobile.require' => '手机号必填',
        'no.require' => '身份证号必填',
        'bank_account.require' => '银行卡号必填',
        'mobile.number' => '手机号必须为数字',
    ];

    protected $scene = [
        'create' => ['uid', 'name', 'mobile', 'no', 'bank_account'],
        'edit' => ['id', 'bank_account'],
        'del' => ['id'],
    ];

    /**
     * @title 校验是否签约
     * @param $value
     * @return bool|string
     * @throws \think\db\exception\DbException
     */
    public function checkUid($value)
    {
        $map[] = ['uid', '=', $value];
        $exist = (new \app\lib\models\UserPrivacy())->where($map)->where(['status' => 1])->count();
        return empty($exist) ? true : '您已签约';
    }

    /**
     * @title 校验银行卡
     * @param $value
     * @return bool|string
     */
    public function user_no_check($value){
        $res = false;
        $value = str_replace(' ', '', trim($value));
        // 内地身份证号校验
        $reg = "/^\d{6}(18|19|20)?\d{2}(0[1-9]|1[012])(0[1-9]|[12]\d|3[01])\d{3}(\d|X)$/i";
        if($res == false && preg_match($reg,$value)){
            $res = true;
        }
        // 中国香港
//        $reg2 = "/[A-Z][0-9]{6}\([0-9A]\)/";
        $reg2 = "/^[HMhm]{1}([0-9]{10}|[0-9]{8}|[0-9]{6}\([0-9A]\))$/";
        if($res == false && preg_match($reg2,$value)){
            $res = true;
        }
        // 中国澳门
        $reg3 = "/[157][0-9]{6}\([0-9]\)/";
//        $reg3 = "/^[HMhm]{1}([0-9]{10}|[0-9]{8})$/";
        if($res == false && preg_match($reg3,$value)){
            $res = true;
        }
        // 通行证
        $reg5 = "/[A-Z][0-9]{8}/";
        if($res == false && preg_match($reg5,$value)){
            $res = true;
        }
        // 中国台湾
        $reg4 = "/^[a-zA-Z][0-9]{9}$/";
        if($res == false && preg_match($reg4,$value)){
            $res = true;
        }
        return $res ? true : "身份证号码异常";
    }

    public function checkExist($value)
    {
        $map[] = ['id', '=', $value];
        $exist = (new \app\lib\models\UserPrivacy())->where($map)->where(['status' => [1, 2]])->count();
        return empty($exist) ? '未签约' : true;
    }
}