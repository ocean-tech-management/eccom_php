<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 活动模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\ActivityGoods;
use app\lib\models\ActivityGoodsSku;
use app\lib\models\GoodsSku;
use app\lib\models\PtActivity;
use app\lib\models\Activity as ActivityModel;
use app\lib\models\PtGoods;
use app\lib\models\PtGoodsSku;

class Activity extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  拼团活动列表
     * @param PtActivity $model
     * @return string
     * @throws \Exception
     */
    public function ptList(PtActivity $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼团活动列表
     * @param PtActivity $model
     * @return string
     * @throws \Exception
     */
    public function ptInfo(PtActivity $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增拼团活动
     * @param PtActivity $model
     * @return string
     */
    public function ptCreate(PtActivity $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑拼团活动
     * @param PtActivity $model
     * @return string
     */
    public function ptUpdate(PtActivity $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除拼团活动
     * @param PtActivity $model
     * @return string
     */
    public function ptDelete(PtActivity $model)
    {
        $res = $model->DBDelete($this->requestData);
        return returnMsg($res);
    }


    /**
     * @title  上下架活动
     * @param PtActivity $model
     * @return string
     */
    public function ptUpOrDown(PtActivity $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  活动列表
     * @param ActivityModel $model
     * @return string
     * @throws \Exception
     */
    public function list(ActivityModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }


    /**
     * @title  活动详情
     * @param ActivityModel $model
     * @return string
     * @throws \Exception
     */
    public function info(ActivityModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  编辑活动
     * @param ActivityModel $model
     * @return string
     */
    public function update(ActivityModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }


    /**
     * @title  上下架活动
     * @param ActivityModel $model
     * @return string
     */
    public function upOrDown(ActivityModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除活动
     * @param ActivityModel $model
     * @return string
     */
    public function delete(ActivityModel $model)
    {
        $res = $model->del($this->request->param('activity_id'));
        return returnMsg($res);
    }

    /**
     * @title  新增/编辑商品
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function createOrUpdateGoods(ActivityGoods $model)
    {
        $res = $model->DBNewOrEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  修改活动的进度条展示
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function updateActivityGoodsProgressBar(ActivityGoods $model)
    {
        $res = $model->computeProgressBarVirtualSaleNumber($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新活动商品SPU的虚拟销量
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function updateActivityGoodsSpuSaleNumber(ActivityGoods $model)
    {
        $res = $model->updateActivityGoodsSpuSaleNumber($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  活动商品SPU详情
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function goodsInfo(ActivityGoods $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }

    /**
     * @title  活动商品SKU详情
     * @param ActivityGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function goodsSkuInfo(ActivityGoodsSku $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }


    /**
     * @title  删除商品
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function deleteGoods(ActivityGoods $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  更新活动商品排序
     * @param ActivityGoods $model
     * @return string
     */
    public function updateGoodsSort(ActivityGoods $model)
    {
        $res = $model->updateSort($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除活动商品SKU
     * @param ActivityGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function deleteGoodsSku(ActivityGoodsSku $model)
    {
        $res = $model->del($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  新增/编辑拼团商品
     * @param PtGoods $model
     * @return string
     * @throws \Exception
     */
    public function createOrUpdatePtGoods(PtGoods $model)
    {
        $res = $model->DBNewOrEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  拼团商品SPU详情
     * @param PtGoods $model
     * @return string
     * @throws \Exception
     */
    public function ptGoodsInfo(PtGoods $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }

    /**
     * @title  拼团商品SKU详情
     * @param PtGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function ptGoodsSkuInfo(PtGoodsSku $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }


    /**
     * @title  删除拼团商品
     * @param PtGoods $model
     * @return string
     * @throws \Exception
     */
    public function deletePtGoods(PtGoods $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  删除拼团商品SKU
     * @param PtGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function deletePtGoodsSku(PtGoodsSku $model)
    {
        $res = $model->del($this->requestData);
        return returnMsg($res);

    }

    /**
     * @title  更新拼团活动商品排序
     * @param PtGoods $model
     * @return string
     */
    public function updatePtGoodsSort(PtGoods $model)
    {
        $res = $model->updateSort($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  商品销售情况
     * @param PtGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function ptGoodsSkuSaleInfo(PtGoodsSku $model)
    {
        $list = $model->goodsSkuSale($this->requestData);
        return returnData($list);
    }

    /**
     * @title  更新拼团商品库存
     * @param PtGoodsSku $model
     * @return string
     */
    public function updatePtStock(PtGoodsSku $model)
    {
        $res = $model->updateStock($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  所有活动名称列表
     * @param ActivityModel $model
     * @return string
     * @throws \Exception
     */
    public function allActivity(ActivityModel $model)
    {
        $list = $model->allActivity($this->requestData);
        return returnData($list);
    }

    /**
     * @title 特殊支付活动列表
     * @param ActivityModel $model
     * @return string
     * @throws \Exception
     */
    public function specialPayActivityList(ActivityModel $model)
    {
        $data = $this->requestData;
        $data['show_position'] = 6;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }
}