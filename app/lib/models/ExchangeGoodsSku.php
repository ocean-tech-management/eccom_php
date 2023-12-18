<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 兑换商品SKU模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\PtException;
use app\lib\exceptions\ServiceException;
use think\facade\Cache;
use think\facade\Db;

class ExchangeGoodsSku extends BaseModel
{
    protected $validateFields = ['goods_sn', 'sku_sn'];

    /**
     * @title  活动商品sku详情
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }

        $map[] = ['type', '=', $sear['type']];
        $map[] = ['goods_sn', '=', $sear['goods_sn']];

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['sku'])->where($map)->withMax(['vdc' => 'max_purchase_price'], 'purchase_price')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();;

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  删除活动商品SKU
     * @param array $data
     * @return mixed
     */
    public function del(array $data)
    {
        $res = $this->where(['type' => $data['type'], 'goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn'], 'status' => [1, 2]])->save(['status' => -1]);
        $aGoods = $this->where(['type' => $data['type'], 'goods_sn' => $data['goods_sn'], 'status' => [1, 2]])->count();
        if (empty($aGoods)) {
            ExchangeGoods::update(['status' => -1], ['type' => $data['type'], 'goods_sn' => $data['goods_sn']]);
        }
        return $res;
    }

    /**
     * @title  更新库存
     * @param array $sear
     * @return mixed
     */
    public function updateStock(array $sear)
    {
        $goods = $sear['goods'] ?? [];
        $type = $sear['type'] ?? 1;
        if (empty($goods)) {
            return false;
        }
        $allGoodsSn = array_unique(array_filter(array_column($goods, 'goods_sn')));
        if (count($allGoodsSn) > 1) {
            throw new ServiceException(['msg' => '一次仅允许修改一个商品的库存哟']);
        }

        $goodsSn = current($allGoodsSn);

        foreach ($goods as $key => $value) {
            if (empty($value['stock_number'])) {
                unset($goodsSn[$key]);
                continue;
            }
            $stock[$value['sku_sn']] = $value['stock_number'];
        }

        if (empty($goods)) {
            throw new ServiceException(['msg' => '没有可以实际修改的库存哟~']);
        }
        $skuSn = array_unique(array_filter(array_column($goods, 'sku_sn')));

        $skuInfo = $this->where(['type' => $type, 'goods_sn' => $goodsSn, 'sku_sn' => $skuSn])->column('stock', 'sku_sn');

        $goodsSkuInfo = GoodsSku::where(['goods_sn' => $goodsSn, 'sku_sn' => $skuSn])->column('stock', 'sku_sn');
        if (empty($skuInfo)) {
            throw new ServiceException(['msg' => '没有可以实际修改的商品库存哟~']);
        }
        //是否需要清除全部库存
        $clearAllStock = false;

        $count = 0;
        foreach ($skuInfo as $key => $value) {
            if (!empty($stock[$key])) {
                $finally[$count]['goods_sn'] = $goodsSn;
                $finally[$count]['sku_sn'] = $key;
                //如果填写的数量是正数则表示添加库存,负数则需要判断是否超过当前库存,超过则当前库存清0
                if (!empty($goodsSkuInfo[$key]) && ($stock[$key] + $skuInfo[$key]) > (string)$goodsSkuInfo[$key]) {
                    throw new PtException(['msg' => '兑换商品库存不允许超过商品库库存哦,商品库当前库存剩余 ' . intval($goodsSkuInfo[$key])]);
                }

                if (intval($stock[$key]) > 0) {
                    $finally[$count]['type'] = 1;
                    $finally[$count]['number'] = intval($stock[$key]);
                } else {
                    if ($value + $stock[$key] <= 0) {
                        $finally[$count]['type'] = 2;
                        $finally[$count]['number'] = intval($value);
                        $clearAllStock = true;
                    } else {
                        $finally[$count]['type'] = 1;
                        $finally[$count]['number'] = intval($stock[$key]);
                    }
                }
                $count++;
            }
        }
        //数据库操作
        $DBRes = false;
        if (!empty($finally)) {
            $DBRes = Db::transaction(function () use ($finally, $clearAllStock, $type) {
                //$value['type']指的此次操作是增加还是减少 $type指的是操作的商品类型1为健康豆 2为积分
                foreach ($finally as $key => $value) {
                    if ($value['type'] == 1) {
                        $res = $this->where(['type' => $type, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->inc('stock', intval($value['number']))->update();
                    } elseif ($value['type'] == 2) {
                        if (empty($clearAllStock)) {
                            $res = $this->where(['type' => $type, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->dec('stock', intval($value['number']))->update();
                        } else {
                            $res = $this->where(['type' => $type, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->save(['stock' => 0]);
                        }
                    }

                }
                return $res;
            });
        }
        return $DBRes;

    }

    public function goodsSpu()
    {
        return $this->hasOne('ExchangeGoods', 'goods_sn', 'goods_sn')->where(['status' => 1])->withoutField('id,status,create_time,update_time');
    }


    public function sku()
    {
        return $this->hasOne('GoodsSku', 'sku_sn', 'sku_sn')->where(['status' => 1])->withoutField('id,status,create_time,update_time');
    }

    public function vdc()
    {
        return $this->hasMany('GoodsSkuVdc', 'sku_sn', 'sku_sn')->field('id,sku_sn,level,purchase_price,vdc_genre,vdc_type,belong,vdc_one,vdc_two')->where(['status' => [1, 2]]);
    }

    public function saleNumber()
    {
        return $this->hasOne('ExchangeGoods', 'goods_sn', 'goods_sn')->where(['status' => 1])->bind(['sale_number']);
    }

    public function saleGoodsSku()
    {
        return $this->hasMany('OrderGoods', 'sku_sn', 'sku_sn')->where(['pay_status' => 2, 'status' => 1]);
    }
}