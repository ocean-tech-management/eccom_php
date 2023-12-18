<?php
// +----------------------------------------------------------------------
// |[ 文档说明: APP版本管理模块Controller]
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\AppVersion as AppVersionModel;


class AppVersion extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  APP版本列表
     * @param AppVersionModel $model
     * @return string
     * @throws \Exception
     */
    public function list(AppVersionModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  APP版本详情
     * @param AppVersionModel $model
     * @return string
     */
    public function info(AppVersionModel $model)
    {
        $info = $model->info($this->request->param('v_sn'));
        return returnData($info);
    }

    /**
     * @title  新增APP版本
     * @param AppVersionModel $model
     * @return string
     */
    public function create(AppVersionModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新APP版本
     * @param AppVersionModel $model
     * @return string
     */
    public function update(AppVersionModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  发布APP版本
     * @param AppVersionModel $model
     * @return string
     */
    public function release(AppVersionModel $model)
    {
        $res = $model->release($this->request->param('v_sn'));
        return returnMsg($res);
    }

    /**
     * @title  删除APP版本
     * @param AppVersionModel $model
     * @return string
     */
    public function delete(AppVersionModel $model)
    {
        $res = $model->del($this->request->param('v_sn'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架APP版本
     * @param AppVersionModel $model
     * @return string
     */
    public function upOrDown(AppVersionModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }
}