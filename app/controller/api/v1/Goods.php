<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\ServiceException;
use app\lib\models\GoodsSpu;
use app\lib\models\OrderGoods;
use app\lib\models\Reputation;

class Goods extends BaseController
{
//    protected $middleware = [
//        'checkApiToken',
//    ];

    /**
     * @title  商品列表
     * @param GoodsSpu $model
     * @return string
     * @throws \Exception
     */
    public function list(GoodsSpu $model)
    {
        $list = $model->shopList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  商品详情
     * @param GoodsSpu $model
     * @return string
     * @throws \Exception
     */
    public function info(GoodsSpu $model)
    {
        $data = $this->requestData;
        $info = $model->shopGoodsInfo($data);
        return returnData($info);
    }

    /**
     * @title  通过SPU全部属性分类获取到每个SKU拥有的属性
     * @return string
     */
    public function skuAttr()
    {
        $goodsSn = $this->request->param('goods_sn');
        //获取该商品全部属性
        $spuAttrSpecs = GoodsSpu::where(['goods_sn' => $goodsSn, 'status' => 1])->value('attribute_list');
        $skuSpec = [];
        foreach ($spuAttrSpecs as $spec => $specArr) {
            foreach ($specArr as $attr => $skuArr) {
                // $spuSet = $spuSet + $skuArr; // 数组并集
                foreach ($skuArr as $sku) {
                    $skuSpec[$sku][] = $attr;
                }
            }
        }
        return returnData($skuSpec);
    }

    /**
     * @title  当用户选择了规格属性后,判断其他的属性是否可选
     * @return string
     */
    public function checkUserCanCheckAttr()
    {
        $goodsSn = $this->request->param('goods_sn');
        //获取该商品全部属性
        $spuAttrSpecs = GoodsSpu::where(['goods_sn' => $goodsSn, 'status' => 1])->value('attribute_list');
        //用户选择了的规格属性 一维数组['color' => 'red']
        $attrSelect = $this->requestData; // 用户选择了的规格属性
        $attrSku = []; // 拥有这些属性的 sku

        foreach ($attrSelect as $spec => $attr) {
            if (!$attrSku) {
                $attrSku = $spuAttrSpecs[$spec][$attr];
            } else {
                $attrSku = array_intersect($attrSku, $spuAttrSpecs[$spec][$attr]); // 交集
            }
        }
        // var_dump($attrSku);
        // 判断其他规格的属性是否可选
        foreach ($spuAttrSpecs as $spec => $specArr) {
            if (!isset($attrSelect[$spec])) { // 此规格下用户没有选择属性, 判断次规格下的属性是否可选
                foreach ($specArr as $attr => $skuArr) {
                    $spuAttrSpecs[$spec][$attr] = array_intersect($attrSku, $skuArr); // 交集, 如果为空, 则此属性不可选
                }
            }
        }
        return returnData($spuAttrSpecs);
    }

    /**
     * @title  商品详情其他商品列表(包含其他商品和热销榜)新版
     * @return string
     * @throws \Exception
     */
    public function otherGoodsList(GoodsSpu $model)
    {
        $data = $this->requestData;
        $list = $model->otherGoodsList($data);
        return returnData($list);
    }

    /**
     * @title  商品详情其他商品列表(包含其他商品和热销榜)新版
     * @param GoodsSpu $model
     * @return string
     * @throws \Exception
     */
    public function otherGoodsListInUserCenter(GoodsSpu $model)
    {
        $data = $this->requestData;
        $list = $model->otherGoodsListInUserCenter($data);
        return returnData($list);
    }

//    /**
//     * @title  商品详情其他商品列表(包含其他商品和热销榜)老版
//     * @throws \Exception
//     * @return string
//     */
//    public function otherGoodsList()
//    {
//        $data = $this->requestData;
//        $categoryCode = $data['category_code'] ?? null;
//        //其他产品
//        $other = (new GoodsSpu())->shopList(['category_code'=>$categoryCode,'page'=>1,'pageNumber'=>3,'cache'=>config('system.queueAbbr') . 'GoodsInfoOtherGoods'.$categoryCode,'cache_expire'=>180]);
//
//        //热销榜
//        $hot = (new OrderGoods())->ShopHotSaleList(['orderType'=>2,'page'=>1,'pageNumber'=>3,'order_belong'=>1,'cache'=>config('system.queueAbbr') . 'GoodsInfoHotSale','cache_expire'=>180]);
//
//        $list['other'] = $other['list'];
//        $list['hot'] = $hot['list'];
//        return returnData($list);
//    }


    /**
     * @title  商品口碑列表
     * @param Reputation $model
     * @return string
     * @throws \Exception
     */
    public function reputation(Reputation $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total']);
    }
}