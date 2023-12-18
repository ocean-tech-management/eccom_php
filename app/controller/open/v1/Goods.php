<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品模块控制器]
// +----------------------------------------------------------------------



namespace app\controller\open\v1;


use app\BaseController;
use app\lib\models\GoodsSpu;

class Goods extends BaseController
{
    protected $middleware = [
        'checkOpenUser',
        'openRequestLog',
    ];

    /**
     * @title  商品列表
     * @param GoodsSpu $model
     * @return string
     * @throws \Exception
     */
    public function list(GoodsSpu $model)
    {
        $list = $model->openList($this->request->openParam);
        return returnData($list['list'], $list['pageTotal'],0,'成功',($list['total'] ?? 0));
    }

    /**
     * @title  商品详情
     * @param GoodsSpu $model
     * @return string
     * @throws \Exception
     */
    public function info(GoodsSpu $model)
    {
        $info = $model->openInfo($this->request->openParam);
        return returnData($info);
    }
}