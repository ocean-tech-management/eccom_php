<?php

namespace app\controller\admin;

use app\BaseController;
use app\lib\exceptions\ServiceException;
use app\lib\models\CommonModel;
use app\lib\models\IntegralDetail;
use app\lib\models\User;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class Integral extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title 美丽豆后台操作明细列表
     * @param IntegralDetail $model
     * @return string
     */
    public function rechargeIntegralList(IntegralDetail $model)
    {
        $data = $this->requestData;
        if (empty($data['change_type'])) {
            $data['change_type'] = [7, 8];
        }
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  后台系统充值美丽豆(积分)
     * @param array $data
     * @return mixed
     */
    public function rechargeIntegralBalance(IntegralDetail $model)
    {
        $data = $this->requestData;
        $res = $model->newBatch($data);
        return returnMsg($res);
    }
}