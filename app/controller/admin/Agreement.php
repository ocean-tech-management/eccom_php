<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 协议模块Controller]
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Agreement as AgreementModel;


class Agreement extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  协议列表
     * @param AgreementModel $model
     * @return string
     * @throws \Exception
     */
    public function list(AgreementModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  协议详情
     * @param AgreementModel $model
     * @return string
     */
    public function info(AgreementModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增协议
     * @param AgreementModel $model
     * @return string
     */
    public function create(AgreementModel $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新协议
     * @param AgreementModel $model
     * @return string
     */
    public function update(AgreementModel $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除协议
     * @param AgreementModel $model
     * @return string
     */
    public function delete(AgreementModel $model)
    {
        $res = $model->DBDel($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  上/下架协议
     * @param AgreementModel $model
     * @return string
     */
    public function upOrDown(AgreementModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }
}