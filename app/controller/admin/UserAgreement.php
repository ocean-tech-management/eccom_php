<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户签约协议模块Controller]
// +----------------------------------------------------------------------


namespace app\controller\admin;

use app\BaseController;
use app\lib\models\UserAgreement as UserAgreementModel;

class UserAgreement extends BaseController
{

    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  用户签约列表
     * @param UserAgreementModel $model
     * @return string
     * @throws \Exception
     */
    public function list(UserAgreementModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户签约详情
     * @param UserAgreementModel $model
     * @return string
     */
    public function info(UserAgreementModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }
}