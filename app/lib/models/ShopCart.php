<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 购物车模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use Exception;
use think\facade\Db;

class ShopCart extends BaseModel
{
    /**
     * @title  购物车列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {

        $map[] = ['uid', '=', $sear['uid']];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;

        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = null;
                $cacheExpire = $sear['cache_expire'];
            }
        }

        if (!empty($page)) {
            $aTotal = $this->where($map)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey, $cacheExpire);
            })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['goods', 'goodsVdc', 'user', 'activity' => function ($query) {
            $query->field('id,title,a_type,icon,desc,limit_type,attach_type,status');
        }])->where($map)->withoutField('id,update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->order('create_time desc,sort asc')->select()->each(function ($item) {
            if (empty($item['activity'])) {
                $item['activity'] = [];
            }
            $item['activity_goods'] = [];
            return $item;
        })->toArray();

        //查找购物车商品活动详情
//        if(!empty($sear['needActivity'])){
        if (!empty($list)) {
            foreach ($list as $key => $value) {
                if (!empty($value['activity'])) {
                    $allActivityId[] = $value['activity']['id'];
                }
                $allSku[] = $value['sku_sn'];
                $allGoods[] = $value['goods_sn'];
            }
            if (!empty($allActivityId)) {
                $allActivityId = array_unique(array_filter($allActivityId));
            }
            if (!empty($allSku)) {
                $allSku = array_unique(array_filter($allSku));
            }
            if (!empty($allGoods)) {
                $allGoods = array_unique(array_filter($allGoods));
            }
            if (!empty($allActivityId) && !empty($allSku) && !empty($allGoods)) {
                $aGoods = ActivityGoodsSku::with(['goodsSpu'])->where(['activity_id' => $allActivityId, 'goods_sn' => $allGoods, 'sku_sn' => $allSku, 'status' => [1, 2]])->field('id,activity_id,goods_sn,sku_sn,status,vip_level,activity_price')->select()->toArray();
                if (!empty($aGoods)) {
                    foreach ($aGoods as $key => $value) {
                        foreach ($list as $lKey => $lValue) {
                            if (!empty($lValue['activity']) && $lValue['activity']['id'] == $value['activity_id'] && $lValue['goods_sn'] == $value['goods_sn'] && $lValue['sku_sn'] == $value['sku_sn']) {
                                $list[$lKey]['activity_goods'] = $value;
                            }
                        }
                    }
                }
            }
        }
//        }
        if (!empty($list)) {
            foreach ($list as $key => $value) {
                //图片展示缩放,OSS自带功能
                if (!empty($value['goods']['image'])) {
                    $list[$key]['goods']['image'] .= '?x-oss-process=image/resize,h_400,m_lfit';
                }

                $spu = $value['goods'];
                $userInfo = $value['user'];
                //修改不同会员不同成本价
                if (!empty($userInfo['vip_level'])) {
                    foreach ($value['goodsVdc'] as $vKey => $vValue) {
                        if ($vValue['sku_sn'] == $spu['sku_sn']) {
                            if ($vValue['level'] == $userInfo['vip_level']) {
//                                $list[$key]['goods']['sale_price'] = $vValue['purchase_price'];
                                $list[$key]['goods']['member_price'] = $vValue['purchase_price'];
                            }
                        }
                    }

                }

            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  添加购物车
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        $goodsSn = $data['goods_sn'];
        $skuSn = $data['sku_sn'];
        $number = intval($data['number'] ?? 1);
        $goodsInfo = GoodsSku::where(['goods_sn' => $goodsSn, 'sku_sn' => $skuSn, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($goodsInfo)) {
            throw new ServiceException(['msg' => '该商品已下架,不可继续操作']);
        }
        if (empty($goodsInfo['stock']) || ($number > $goodsInfo['stock'])) {
            throw new ServiceException(['msg' => '加购数量超过库存,请减少数量~']);
        }
        $cMap[] = ['goods_sn', '=', $goodsSn];
        $cMap[] = ['sku_sn', '=', $skuSn];
        $cMap[] = ['uid', '=', $data['uid']];
        $cMap[] = ['status', '=', 1];
        if (!empty($data['activity_sign'])) {
            $cMap[] = ['activity_sign', '=', trim($data['activity_sign'])];
        } else {
            $cMap[] = ['', 'exp', Db::raw('activity_sign is null')];
        }

        $cartInfo = $this->where($cMap)->findOrEmpty()->toArray();

        $dbData['sale_price'] = $goodsInfo['sale_price'];
        $dbData['specs'] = $goodsInfo['specs'];
        $dbData['update_time'] = time();
        if (!empty($cartInfo)) {
            $dbData['number'] = $cartInfo['number'] + $number;
            if (!empty($data['activity_sign'])) {
                $dbData['activity_sign'] = $data['activity_sign'];
            }
            $dbRes = $this->cache('userShopCart' . $data['uid'])->where(['cart_sn' => $cartInfo['cart_sn']])->save($dbData);
        } else {
            $dbData['cart_sn'] = (new CodeBuilder())->buildShipCartSn();
            $dbData['number'] = $number;
            $dbData['goods_sn'] = $goodsInfo['goods_sn'];
            $dbData['sku_sn'] = $goodsInfo['sku_sn'];
            $dbData['sort'] = $data['sort'] ?? 1;
            $dbData['uid'] = $data['uid'];
            if (!empty($data['activity_sign'])) {
                $dbData['activity_sign'] = $data['activity_sign'];
            }
            $dbData['create_time'] = time();
            $dbRes = $this->cache('userShopCart' . $data['uid'])->save($dbData);
        }
        //cache('userShopCart'.$data['uid'],null);
        return judge($dbRes);
    }

    /**
     * @title  编辑购物车记录
     * @param array $data
     * @return bool
     */
    public function DBEdit(array $data)
    {
        $cartSn = $data['cart_sn'];
        $cartInfo = $this->where(['cart_sn' => $cartSn, 'uid' => $data['uid'], 'status' => 1])->findOrEmpty()->toArray();
        if (empty($cartInfo)) {
            throw new ServiceException(['msg' => '该项购物车记录不存在!']);
        }

        $goodsSn = $data['goods_sn'];
        $skuSn = $data['sku_sn'];
        $number = intval($data['number'] ?? 1);
        $goodsInfo = GoodsSku::where(['goods_sn' => $goodsSn, 'sku_sn' => $skuSn, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($goodsInfo)) {
            throw new ServiceException(['msg' => '该商品已下架,不可继续操作']);
        }
        if (empty($goodsInfo['stock']) || ($number > $goodsInfo['stock'])) {
            throw new ServiceException(['msg' => '加购数量超过库存,请减少数量~']);
        }

        //完全一样的数据不修改
        if (($cartInfo['number'] == $number) && ($cartInfo['goods_sn'] == $goodsSn) && ($cartInfo['sku_sn'] == $skuSn) && (doubleval($cartInfo['sale_price']) == doubleval($goodsInfo['sale_price']))) {
            if (empty($cartInfo['activity_sign'])) {
                return true;
            }
            //判断多一个活动标识是否完全一致
            if (!empty($data['activity_sign']) && $cartInfo['activity_sign'] == $data['activity_sign']) {
                return true;
            }
        }

        $dbData['number'] = $number;
        $dbData['goods_sn'] = $goodsInfo['goods_sn'];
        $dbData['sku_sn'] = $goodsInfo['sku_sn'];
        $dbData['sort'] = $data['sort'] ?? 1;
        $dbData['uid'] = $data['uid'];
        $dbData['sale_price'] = $goodsInfo['sale_price'];
        $dbData['specs'] = $goodsInfo['specs'];
        if (!empty($data['activity_sign'])) {
            $dbData['activity_sign'] = $data['activity_sign'];
        }
        $dbData['update_time'] = time();
        $dbRes = $this->cache('userShopCart' . $data['uid'])->where(['cart_sn' => $cartInfo['cart_sn']])->save($dbData);

        // cache('userShopCart'.$data['uid'],null);
        return judge($dbRes);
    }

    /**
     * @title  删除购物车记录
     * @param array $cartSn
     * @return mixed
     * @throws \Exception
     */
    public function DBDelete(array $cartSn)
    {
        $map[] = ['cart_sn', 'in', $cartSn];
        $map[] = ['status', '=', 1];
        $cartInfo = $this->where($map)->field('cart_sn,uid,goods_sn,sku_sn')->select()->toArray();

        if (empty($cartInfo)) {
            throw new ServiceException(['msg' => '该项购物车记录不存在!']);
        }
        $uids = array_unique(array_column($cartInfo, 'uid'));
        $allCartSn = array_unique(array_column($cartInfo, 'cart_sn'));
        if (count($uids) != 1) {
            throw new ServiceException(['msg' => '非法用户购物车记录']);
        }
        $uid = current($uids);
        $dbRes = $this->cache('userShopCart' . $uid)->where(['cart_sn' => $allCartSn])->save(['status' => -1]);

        // cache('userShopCart'.$cartInfo['uid'],null);
        return judge($dbRes);
    }


    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid')->field('uid,name,integral,vip_level');
    }

    public function goods()
    {
        return $this->hasOne('GoodsSku', 'sku_sn', 'sku_sn')->field('goods_sn,sku_sn,title,image,sub_title,specs,sale_price,fare,status,attach_type,stock');
    }

    public function goodsVdc()
    {
        return $this->hasMany('GoodsSkuVdc', 'sku_sn', 'sku_sn')->where(['status' => 1])->field('goods_sn,sku_sn,level,purchase_price,vdc_genre,vdc_type,vdc_one,vdc_two')->order('level asc');
    }

    public function activity()
    {
        return $this->hasOne('Activity', 'id', 'activity_sign')->where(['status' => [1, 2]]);
    }


}