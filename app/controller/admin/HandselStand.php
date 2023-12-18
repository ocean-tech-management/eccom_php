<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\controller\admin;


use app\BaseController;
use app\lib\models\HandselStandardAbnormal;

class HandselStand extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  异常列表
     * @param HandselStandardAbnormal $model
     * @return string
     * @throws \Exception
     */
    public function abnormalList(HandselStandardAbnormal $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  操作异常订单
     * @param HandselStandardAbnormal $model
     * @return string
     * @throws \Exception
     */
    public function abnormalOperate(HandselStandardAbnormal $model)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $res = $model->operate($data);
        return returnData($res);
    }

}