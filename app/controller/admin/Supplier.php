<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 供应商模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\OrderGoods;
use app\lib\models\Supplier as SupplierModel;


class Supplier extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  供应商列表
     * @param SupplierModel $model
     * @return string
     * @throws \Exception
     */
    public function list(SupplierModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  供应商详情
     * @param SupplierModel $model
     * @return string
     */
    public function info(SupplierModel $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }

    /**
     * @title  新增供应商
     * @param SupplierModel $model
     * @return string
     */
    public function create(SupplierModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新供应商
     * @param SupplierModel $model
     * @return string
     */
    public function update(SupplierModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除供应商
     * @param SupplierModel $model
     * @return string
     */
    public function delete(SupplierModel $model)
    {
        $res = $model->del($this->request->param('supplier_code'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架供应商
     * @param SupplierModel $model
     * @return string
     */
    public function upOrDown(SupplierModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  供应商对账商品列表
     * @param OrderGoods $model
     * @return string
     * @throws \Exception
     */
    public function payList(OrderGoods $model)
    {
        $data = $this->requestData;
        $data['not_pay_status'] = [1, 3];
        $list = $model->skuSaleList($data);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }


    /**
     * @title  供应商结账
     * @param OrderGoods $model
     * @return string
     * @throws \Exception
     */
    public function payGoods(OrderGoods $model)
    {
        $res = $model->payGoodsForSupplier($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  供应商结账后退款
     * @param OrderGoods $model
     * @return string
     * @throws \Exception
     */
    public function afterPayRefund(OrderGoods $model)
    {
        $res = $model->supplierRefundAfterPay($this->requestData);
        return returnMsg($res);
    }
}