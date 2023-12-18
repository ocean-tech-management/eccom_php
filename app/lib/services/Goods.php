<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\models\GoodsSpu;

class Goods
{
    /**
     * @title  根据SPU全部属性解析获取所有SKU属性
     * @param string $goodsSn
     * @return string
     */
    public function getSkuAttrBySpuAttr(string $goodsSn)
    {
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
}