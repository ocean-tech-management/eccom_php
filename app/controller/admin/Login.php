<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\AdminUser;
use app\lib\models\Lecturer;
use app\lib\services\Token;
use think\facade\Log;

class Login extends BaseController
{
//    protected $middleware = [
//        'OperationLog',
//    ];

    /**
     * @title  后台管理员登录
     * @param AdminUser $model
     * @return string
     */
    public function login(AdminUser $model)
    {
        $log['device_information'] = !empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
        $log['ip'] = request()->ip() ?? null;
        Log::write('login_log', var_export($log, true));
        $res = $model->login($this->requestData);
        return returnData($res);
    }

    /**
     * @title  讲师登录
     * @param Lecturer $model
     * @return string
     */
    public function lecturerLogin(Lecturer $model)
    {
        $res = $model->login($this->requestData);
        return returnData($res);
    }

    /**
     * @title  刷新token
     * @param Token $service
     * @return string
     */
    public function refreshToken(Token $service)
    {
        $info = $service->refreshToken();
        return returnData($info);
    }

}