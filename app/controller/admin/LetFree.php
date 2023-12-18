<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\controller\admin;


use app\BaseController;
use app\lib\models\LetfreeExemptUser;

class LetFree extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  乐小活免签约用户列表
     * @param LetfreeExemptUser $model
     * @return string
     * @throws \Exception
     */
    public function exemptUserList(LetfreeExemptUser $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  乐小活新增免签用户
     * @param LetfreeExemptUser $model
     * @return string
     * @throws \Exception
     */
    public function exemptUserCreate(LetfreeExemptUser $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  乐小活删除免签用户
     * @param LetfreeExemptUser $model
     * @return string
     */
    public function exemptUserDelete(LetfreeExemptUser $model)
    {
        $res = $model->del($this->request->param('uid'));
        return returnMsg($res);
    }
}