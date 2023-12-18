<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 兑换模块Controller]
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\ActivityGoods;
use app\lib\models\ActivityGoodsSku;
use app\lib\models\ExchangeGoods;
use app\lib\models\ExchangeGoodsSku;
use app\lib\models\PtGoodsSku;

class Exchange extends BaseController
{
    /**
     * @title  新增/编辑商品
     * @param ExchangeGoods $model
     * @return string
     * @throws \Exception
     */
    public function createOrUpdateGoods(ExchangeGoods $model)
    {
        $res = $model->DBNewOrEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  活动商品SPU详情
     * @param ExchangeGoods $model
     * @return string
     * @throws \Exception
     */
    public function goodsList(ExchangeGoods $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }

    /**
     * @title  活动商品SKU详情
     * @param ExchangeGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function goodsSkuInfo(ExchangeGoodsSku $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }


    /**
     * @title  删除商品
     * @param ExchangeGoods $model
     * @return string
     * @throws \Exception
     */
    public function deleteGoods(ExchangeGoods $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  更新活动商品排序
     * @param ExchangeGoods $model
     * @return string
     */
    public function updateGoodsSort(ExchangeGoods $model)
    {
        $res = $model->updateSort($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除活动商品SKU
     * @param ExchangeGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function deleteGoodsSku(ExchangeGoodsSku $model)
    {
        $res = $model->del($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新商品库存
     * @param ExchangeGoodsSku $model
     * @return string
     */
    public function updateStock(ExchangeGoodsSku $model)
    {
        $res = $model->updateStock($this->requestData);
        return returnMsg($res);
    }

}