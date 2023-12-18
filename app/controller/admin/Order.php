<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\GoodsSku;
use app\lib\models\GoodsSpu;
use app\lib\models\MemberOrder;
use app\lib\models\Order as OrderModel;
use app\lib\models\OrderCorrection;

class Order extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  订单列表
     * @param OrderModel $model
     * @return string
     * @throws \Exception
     */
    public function list(OrderModel $model)
    {
        $data = $this->requestData;
        $data['needDivideDetail'] = true;
        $data['adminInfo'] = $this->request->adminInfo;
        $data['needDivideChain'] = true;
        $data['not_order_type'] = 6;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  订单详情
     * @param OrderModel $model
     * @return string
     * @throws \Exception
     */
    public function info(OrderModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  会员订单列表
     * @param MemberOrder $model
     * @return string
     * @throws \Exception
     */
    public function memberList(MemberOrder $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  订单备注
     * @param OrderModel $model
     * @return string
     */
    public function orderRemark(OrderModel $model)
    {
        $info = $model->orderRemark($this->requestData);
        return returnMsg($info);
    }

    /**
     * @title  订单筛选框-商品SPU列表
     * @param GoodsSpu $model
     * @return string
     * @throws \Exception
     */
    public function goodsSpu(GoodsSpu $model)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $data['searType'] = 1;
        $list = $model->listForOrderSear($data);
        return returnData($list);
    }

    /**
     * @title  订单筛选框-商品SKU列表
     * @param GoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function goodsSku(GoodsSku $model)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $data['searType'] = 1;
        $list = $model->listForOrderSear($data);
        return returnData($list);
    }

    /**
     * @title  用户列表(包含用户自购订单数据)
     * @param OrderModel $model
     * @return string
     * @throws \Exception
     */
    public function userSelfBuyOrder(OrderModel $model)
    {
        $list = $model->userSelfBuyOrder($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  订单金额校正明细列表(SKU级)
     * @param OrderCorrection $model
     * @return string
     * @throws \Exception
     */
    public function correctionList(OrderCorrection $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  新增订单金额校正
     * @param OrderCorrection $model
     * @return string
     */
    public function correctionCreate(OrderCorrection $model)
    {
        $data = $this->requestData;
        $adminInfo = $this->request->adminInfo;;
        $data['admin_id'] = $adminInfo['id'] ?? '0';
        $data['admin_name'] = $adminInfo['name'] ?? '未知管理员';
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }


}