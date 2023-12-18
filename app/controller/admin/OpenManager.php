<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\controller\admin;


use app\BaseController;
use app\lib\models\OpenAccount;

class OpenManager extends BaseController
{
    /**
     * @title  开发者列表
     * @param OpenAccount $model
     * @return string
     * @throws \Exception
     */
    public function developerList(OpenAccount $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  开发者详情
     * @param OpenAccount $model
     * @return string
     */
    public function developerInfo(OpenAccount $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }

    /**
     * @title  新增开发者
     * @param OpenAccount $model
     * @return string
     */
    public function developerCreate(OpenAccount $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新开发者
     * @param OpenAccount $model
     * @return string
     */
    public function developerUpdate(OpenAccount $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除开发者
     * @param OpenAccount $model
     * @return string
     */
    public function developerDelete(OpenAccount $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  禁/启用开发者
     * @param OpenAccount $model
     * @return string
     */
    public function developerUpOrDown(OpenAccount $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    public function groupList()
    {}

}