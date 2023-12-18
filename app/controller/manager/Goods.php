<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商户移动端-商品列表]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\manager;


use app\BaseController;
use app\lib\models\AttributeKey;
use app\lib\models\AttributeValue;
use app\lib\models\GoodsImages;
use app\lib\models\GoodsSku;
use app\lib\models\GoodsSpu;
use app\lib\models\PostageTemplate;
use think\facade\Db;

class Goods extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  商品SPU列表
     * @param GoodsSpu $model
     * @return string
     * @throws \Exception
     */
    public function spuList(GoodsSpu $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total']);
    }

    /**
     * @title  商品SKU列表
     * @param GoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function skuList(GoodsSku $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  新增商品
     * @return string
     */
    public function create()
    {
        $res = Db::transaction(function () {
            $data = $this->requestData;
            $data['belong'] = 1;
            $newSpu = (new GoodsSpu())->new($data);
            $sku['goods_sn'] = $newSpu;
            $sku['sku'] = $this->request->param('sku');
            $sku['belong'] = 1;
            $newSku = (new GoodsSku())->newOrEdit($sku);
            return $newSku;
        });
        return returnMsg($res);
    }

    /**
     * @title  商品详情
     * @param GoodsSpu $model
     * @return string
     * @throws \Exception
     */
    public function info(GoodsSpu $model)
    {
        $info = $model->info($this->request->param('goods_sn'));
        return returnData($info);
    }

    /**
     * @title  编辑商品
     * @return string
     */
    public function update()
    {
        $res = Db::transaction(function () {
            $data = $this->requestData;
            $data['belong'] = 1;
            $newSpu = (new GoodsSpu())->edit($data);
            $sku['goods_sn'] = $this->request->param('goods_sn');
            $sku['sku'] = $this->request->param('sku');
            $sku['belong'] = 1;
            $newSku = (new GoodsSku())->newOrEdit($sku);
            return $newSku;
        });
        return returnMsg($res);
    }

    /**
     * @title  删除商品
     * @param GoodsSpu $model
     * @return string
     */
    public function delete(GoodsSpu $model)
    {
        $res = $model->del($this->request->param('goods_sn'));
        return returnMsg($res);
    }

    /**
     * @title  删除Sku
     * @param GoodsSku $model
     * @return string
     */
    public function deleteSku(GoodsSku $model)
    {
        $res = $model->del($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  上架/下架商品
     * @param GoodsSpu $model
     * @return string
     */
    public function status(GoodsSpu $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  商品属性Key值
     * @param AttributeKey $model
     * @return string
     * @throws \Exception
     */
    public function attrKey(AttributeKey $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  商品属性Value值
     * @param AttributeValue $model
     * @return string
     * @throws \Exception
     */
    public function attrValue(AttributeValue $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  删除图片
     * @param GoodsImages $model
     * @return string
     */
    public function deleteImages(GoodsImages $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  邮费模板列表
     * @param PostageTemplate $model
     * @return string
     * @throws \Exception
     */
    public function postageTemplate(PostageTemplate $model)
    {
        $data = $this->requestData;
        $data['notFormat'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  更新商品排序
     * @param GoodsSpu $model
     * @return string
     */
    public function updateSort(GoodsSpu $model)
    {
        $res = $model->updateSort($this->requestData);
        return returnMsg($res);
    }

}