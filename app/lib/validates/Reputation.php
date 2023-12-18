<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品系统评价(口碑)模块验证器]
// +----------------------------------------------------------------------



namespace app\lib\validates;


use app\lib\BaseValidate;

class Reputation extends BaseValidate
{
    public $errorCode = 500117;

    protected $rule = [
        'user_name' => 'require',
        'user_avatarUrl' => 'require',
//        'title' => 'require',
        'id' => 'require',
        'content' => 'require',

    ];

    protected $message = [
        'user_name.require' => '发布人姓名必填',
        'user_avatarUrl.require' => '发布人头像不能为空哦',
//        'title.require' => '标题必填哦',
        'content.require' => '评论内容必填',
        'id.require' => '缺少唯一标识',
    ];

    protected $scene = [
        'create' => ['user_name', 'user_avatarUrl', 'content'],
        'edit' => ['id', 'user_name', 'user_avatarUrl', 'content'],
    ];
}