<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\MemberVdc as MemberVdcModel;

class MemberVdc extends BaseValidate
{
    public $errorCode = 500106;
    private $belong = 1;


    protected $rule = [
        'level' => 'require',
        'vdc_type' => 'in:1,2|number',
        'vdc_one' => 'require|checkOne',
        'vdc_two' => 'require'
    ];

    protected $message = [
        'level.require' => '会员等级必填',
        'vdc_type.in' => '分销等级不符合规则',
        'vdc_type.number' => '分销等级必须为数字',
        'vdc_one' => '一级分销比例必填',
        'vdc_two' => '二级分销比例必填'

    ];

    public function checkOne($value, $rule, $data, $fieldName)
    {
//        if($data['level'] == 1 && $data['vdc_type'] != 1){
//            return '一级会员只允许设置一级分销';
//        }
//        if($data['vdc_type'] == 2){
//            $upVdcOne = MemberVdcModel::where(['vdc_type'=>1,'belong'=>$this->belong,'status'=>1])->value('vdc_one');
//            if(empty($upVdcOne)){
//                $msg = '请先设置一级分销规则';
//                return $msg;
//            }
//            if($upVdcOne != $value){
//                $msg = '一级分销比例只能为'.$upVdcOne;
//            }
//        }
//        return !empty($msg) ? $msg : true;
        return true;
    }
}