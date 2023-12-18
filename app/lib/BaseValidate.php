<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib;


use app\lib\exceptions\ParamException;
use think\facade\Request;
use think\Validate;

class BaseValidate extends Validate
{
    /**
     * 状态码
     * @var mixed|int
     */
    protected $errorCode;

    /**
     * 参数
     * @var mixed|string
     */
    protected $param;

    /**
     * 日志通道
     * @var mixed|string
     */
    protected $logChannel = 'file';

    /**
     * 默认状态字段
     * @var string
     */
    protected $statusField = 'status';

    /**
     * @Name   自定义校验方法
     * @param array $data 验证数据
     * @param string $scene 验证场景
     * @return mixed
     * @date   2019年04月08日 10:11
     * @throws ParamException
     * @author  Coder
     */
    public function goCheck(array $data = [], string $scene = '')
    {
        // 获取http传入的参数并做校验
        $params = !empty($data) ? $data : Request::param();
        $this->param = $params;
        if (!empty($scene)) {
            $result = $this->scene($scene)->check($params);
        } else {
            $result = $this->check($params);
        }

        if (!$result) {
            throw new ParamException([
                'msg' => $this->getError(),
                'errorCode' => $this->errorCode,
                'logChannel' => $this->logChannel
            ]);
        }
        return true;
    }
}