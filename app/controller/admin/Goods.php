<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\AttributeKey;
use app\lib\models\AttributeValue;
use app\lib\models\GoodsImages;
use app\lib\models\GoodsParam;
use app\lib\models\GoodsSku;
use app\lib\models\GoodsSpu;
use app\lib\models\OrderGoods;
use app\lib\models\PostageTemplate;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Request;

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
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $data['needActivity'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
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
        //清除首页商品列表标签的缓存
        Cache::tag('apiHomeGoodsList')->clear();
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
            $sku['spu_status'] = $newSpu['status'] ?? 1;
            $newSku = (new GoodsSku())->newOrEdit($sku);
            return $newSku;
        });
        //清除首页商品列表标签的缓存
        Cache::tag(['apiHomeGoodsList', 'HomeApiActivityList'])->clear();
        //清除C端商品详情缓存
        $this->clearApiGoodsCache($this->request->param('goods_sn'));
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
        //清除C端商品详情缓存
        $this->clearApiGoodsCache($this->request->param('goods_sn'));
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
        //清除C端商品详情缓存
        $this->clearApiGoodsCache($this->request->param('goods_sn'));
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
        //清除C端商品详情缓存
        $this->clearApiGoodsCache($this->request->param('goods_sn'));
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

    /**
     * @title  商品销售情况
     * @param GoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function goodsSkuSaleInfo(GoodsSku $model)
    {
        $list = $model->goodsSkuSale($this->requestData);
        return returnData($list);
    }

    /**
     * @title  更新商品库存
     * @param GoodsSku $model
     * @return string
     */
    public function updateStock(GoodsSku $model)
    {
        $res = $model->updateStock($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  复制商品
     * @param GoodsSpu $model
     * @return string
     * @throws \Exception
     */
    public function copyGoods(GoodsSpu $model)
    {
        $newGoods = $model->copyGoods($this->requestData);
        $res = false;
        if (!empty($newGoods)) {
            $res = Db::transaction(function () use ($newGoods) {
                $data = $newGoods;
                $data['belong'] = 1;
                $newSpu = (new GoodsSpu())->new($data);
                $sku['goods_sn'] = $newSpu;
                $sku['sku'] = $data['sku'];
                $sku['belong'] = 1;
                $newSku = (new GoodsSku())->newOrEdit($sku);
                return $newSku;
            });
        }
        return returnMsg($res);
    }

    /**
     * @title  SPU销售列表
     * @param OrderGoods $model
     * @return string
     * @throws \Exception
     */
    public function spuSaleList(OrderGoods $model)
    {
        $data = $this->requestData;
        $data['clearCache'] = true;
        $list = $model->spuSaleList($data);
        return returnData($list);
    }

    /**
     * @title  SPU销售汇总
     * @param OrderGoods $model
     * @return string
     * @throws \Exception
     */
    public function spuSaleSummary(OrderGoods $model)
    {
        $data = $this->requestData;
        $data['onlyNeedAllSummary'] = true;
        $data['clearCache'] = $data['clearCache'] ?? false;
        unset($data['page']);
        $list = $model->spuSaleList($data);
        return returnData($list);
    }

    /**
     * @title  清除C端商品详情缓存
     * @param string|array|null $goodsSn
     * @return void
     */
    public function clearApiGoodsCache($goodsSn = null)
    {
        if (!empty($goodsSn)) {
            if (is_array($goodsSn)) {
                foreach ($goodsSn as $key => $value) {
                    Cache::tag([config('cache.systemCacheKey.apiGoodsInfo.key') . $value])->clear();
                    Cache::tag(['apiWarmUpActivityInfo' . $value])->clear();
                    Cache::tag(['apiWarmUpPtActivityInfo' . $value])->clear();
                }
            } else {
                Cache::tag([config('cache.systemCacheKey.apiGoodsInfo.key') . $goodsSn])->clear();
                Cache::tag(['apiWarmUpActivityInfo' . $goodsSn])->clear();
                Cache::tag(['apiWarmUpPtActivityInfo' . $goodsSn])->clear();
            }
        }
    }

    /**
     * @title  商品参数模版列表
     * @param GoodsParam $model
     * @return string
     * @throws \Exception
     */
    public function paramList(GoodsParam $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  商品参数模版详情
     * @param GoodsParam $model
     * @return string
     */
    public function paramInfo(GoodsParam $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }

    /**
     * @title  新增商品参数模版
     * @param GoodsParam $model
     * @return string
     */
    public function paramCreate(GoodsParam $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新商品参数模版
     * @param GoodsParam $model
     * @return string
     */
    public function paramUpdate(GoodsParam $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除商品参数模版
     * @param GoodsParam $model
     * @return string
     */
    public function paramDelete(GoodsParam $model)
    {
        $res = $model->del($this->request->param('param_code'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架商品参数模版
     * @param GoodsParam $model
     * @return string
     */
    public function paramUpOrDown(GoodsParam $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑商品价格锁定状态
     * @param GoodsSpu $model
     * @return string
     */
    public function updateGoodsPriceMaintain(GoodsSpu $model)
    {
        $res = $model->updateGoodsPriceMaintain($this->requestData);
        //清除首页商品列表标签的缓存
        Cache::tag(['apiHomeGoodsList', 'HomeApiActivityList'])->clear();
        //清除C端商品详情缓存
        $this->clearApiGoodsCache($this->request->param('goods_sn'));
        return returnMsg($res);
    }


}