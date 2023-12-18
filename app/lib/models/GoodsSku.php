<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\CrowdFundingActivityException;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\ParamException;
use app\lib\exceptions\PtException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\ShipException;
use app\lib\services\CodeBuilder;
use Complex\Exception;
use think\facade\Cache;
use think\facade\Db;

class GoodsSku extends BaseModel
{
    private $belong = 1;
    protected $validateFields = ['sku_sn'];

    /**
     * @title  商品SKU列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['goods_sn', '=', $sear['goods_sn']];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('goods_sn,sku_sn,title,sort,image,sale_price,member_price,fare,market_price,specs,stock,content,create_time')->withMax(['vdc' => 'max_purchase_price'], 'purchase_price')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->each(function ($item) {
            $item['is_all'] = 2;
            if (!empty($item['content']) && !is_numeric($item['content'])) {
                $item['is_all'] = 1;
            }
            unset($item['content']);
            return $item;
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    public function shopSkuInfo(string $goodsSn, string $skuSn)
    {
        $info = $this->with(['spu'])->where(['goods_sn' => $goodsSn, 'sku_sn' => $skuSn, 'status' => 1])->withoutField('id,update_time')->findOrEmpty()->toArray();
        return $info;
    }


    /**
     * @title  商品SKU详情
     * @param string $goodsSn 商品编号
     * @param string $skuSn sku编号
     * @return mixed
     */
    public function info(string $goodsSn, string $skuSn)
    {
        $info = $this->with(['spu'])->where(['goods_sn' => $goodsSn, 'sku_sn' => $skuSn, 'status' => 1])->withoutField('id,update_time')->findOrEmpty()->toArray();
        return $info;
    }

    /**
     * @title  新建SKU,完善SPU,添加SKU-VDC
     * @param array $data
     * @return mixed
     */
    public function newOrEdit(array $data)
    {
        $res = Db::transaction(function () use ($data) {
            $count = 0;
            $add = [];
            $addVdc = [];
            $skuVdc = [];
            $existSkuList = [];
            $skuRes = false;
            $entireSubjectSku = null;
            $skuMap = ['goods_sn' => $data['goods_sn'], 'status' => [1, 2]];

            //查询SKU表中包含的全部已存在的SKU
            $allOnlineSku = $this->where($skuMap)->column('content', 'sku_sn');
            //统计SKU中该SPU对应的所有SKU的数量,包含已删除的,为了获取不重复的SKU顺序码
            $allSkuCount = $this->where(['goods_sn' => $data['goods_sn']])->count();

            //生成SKU基础码
            $baseSkuCode = (new CodeBuilder())->buildSkuCode($data['goods_sn']);

            $aSku = $data['sku'];
            $spuAttrSpecs = [];
            $spuAttr = '';
            foreach ($aSku as $key => $value) {
                $skuAttrSpecs = [];
                $skuAttr = '';
                $add[$count]['goods_sn'] = $data['goods_sn'];
                //筛选合适的SKU
                $filter['value'] = $value;
                $filter['count'] = $count;
                $filter['entireSubjectSku'] = $entireSubjectSku;
                $filter['allOnlineSku'] = $allOnlineSku;
                $filter['allSkuCount'] = $allSkuCount;
                $filter['baseSkuCode'] = $baseSkuCode;

                $filterSku = $this->filterSku($filter);

                $allSkuCount = $filterSku['allSkuCount'];
                $add[$count]['sku_sn'] = $filterSku['sku_sn'];
                $add[$count]['title'] = $value['title'];
                $add[$count]['image'] = $value['image'];
                $add[$count]['sub_title'] = $value['sub_title'] ?? null;
                $add[$count]['sort'] = $value['sort'];
                //仅新增才保存库存
                //price_maintain 价格维护状态 1为锁定中(正常使用,不允许修改价格) 2为维护中(C端下单库存为0,允许修改价格)
                $existSku = $this->where(['goods_sn' => $add[$count]['goods_sn'], 'sku_sn' => $add[$count]['sku_sn']])->field('price_maintain,stock,sku_sn,goods_sn,market_price,sale_price,cost_price')->findOrEmpty()->toArray();
                if (empty($existSku)) {
                    $add[$count]['stock'] = $value['stock'];
                } else {
                    if ($existSku['price_maintain'] == 1) {
                        $existSkuList[$add[$count]['sku_sn']] = $existSku;
                        if ($value['market_price'] != $existSku['market_price'] || $value['sale_price'] != $existSku['sale_price'] || $value['cost_price'] != $existSku['cost_price']) {
                            throw new ServiceException(['msg' => 'sku' . $add[$count]['title'] . ' 价格锁定中,无法修改']);
                        }
                    }
                }
//                $add[$count]['fare']     = $value['fare'];
                $add[$count]['content'] = $value['content'] ?? null;
                $add[$count]['market_price'] = $value['market_price'];
                $add[$count]['sale_price'] = $value['sale_price'];
                $add[$count]['member_price'] = $value['sale_price'];
                $add[$count]['cost_price'] = $value['cost_price'] ?? 0;
//                $add[$count]['free_shipping'] = $value['free_shipping'];
                $add[$count]['postage_code'] = $value['postage_code'];
                $add[$count]['supplier_code'] = $value['supplier_code'] ?? null;
                $add[$count]['attach_type'] = $value['attach_type'] ?? -1;
                $add[$count]['virtual_stock'] = $value['virtual_stock'] ?? 0;
                //将SKU属性值转化成json对象存储,并生成对应的SPU所有属性值
                //sku属性值会生成如"{"subject":"class1","color":"red"}"
                //spu全部属性值会生成如"{"subject":{"class1":["0001"],"class2":["0002"]},"color":{"red":["0001","0002"]}}"
                foreach ($value['attr'] as $attrKey => $attrValue) {
                    if (!isset($spuAttrSpecs[$attrKey])) {
                        $spuAttrSpecs[$attrKey] = [];
                    }
                    if (!in_array($attrValue, $spuAttrSpecs[$attrKey])) {
                        $spuAttrSpecs[$attrKey][$attrValue][] = $add[$count]['sku_sn'];
                    }
                    $skuAttrSpecs[$attrKey] = $attrValue;
                }
                $skuAttr = json_encode($skuAttrSpecs, JSON_UNESCAPED_UNICODE);
                $add[$count]['specs'] = $skuAttr;

                //专门为了对应生成属性编码,临时措施
                if (!empty($value['attr_sn'])) {
                    $add[$count]['attr_sn'] = json_encode($value['attr_sn'], JSON_UNESCAPED_UNICODE);
                }
                $skuVdc[$count]['vdc'] = $aSku[$key]['vdc'];
                $skuVdc[$count]['sku_sn'] = $add[$count]['sku_sn'];
                $skuVdc[$count]['belong'] = $data['belong'] ?? $this->belong;

                $skuRes = $this->updateOrCreate(['goods_sn' => $add[$count]['goods_sn'], 'sku_sn' => $add[$count]['sku_sn']], $add[$count]);

                if (!empty($add[$count]['image'])) {
                    //修改SKU图片和其他信息
                    ActivityGoodsSku::where(['sku_sn' => $add[$count]['sku_sn'], 'status' => [1, 2]])->update(['image' => $add[$count]['image'], 'sub_title' => $add[$count]['sub_title'] ?? null, 'title' => $add[$count]['title'] ?? null, 'specs' => $add[$count]['specs'] ?? null, 'sale_price' => $add[$count]['sale_price']]);
                    PtGoodsSku::where(['sku_sn' => $add[$count]['sku_sn'], 'status' => [1, 2]])->update(['image' => $add[$count]['image'], 'sku_title' => $add[$count]['title'], 'specs' => $add[$count]['specs'] ?? null, 'sale_price' => $add[$count]['sale_price']]);
                }

                //同步修改活动商品SKU的所有状态
                if (!empty($data['spu_status'])) {
                    if ($data['spu_status'] == 1) {
                        $otherStatus = 2;
                    } elseif ($data['spu_status'] == 2) {
                        $otherStatus = 1;
                    }
                    $allMap['goods_sn'] = $data['goods_sn'];
                    $allMap['status'] = $otherStatus ?? [1, 2];
                    $saveStatus['status'] = $data['spu_status'];
                    (new GoodsSku())->baseUpdate($allMap, $saveStatus);
                    (new GoodsSkuVdc())->baseUpdate($allMap, $saveStatus);
                    //修改活动商品
                    (new ActivityGoods())->baseUpdate($allMap, $saveStatus);
                    (new ActivityGoodsSku())->baseUpdate($allMap, $saveStatus);
                    cache('HomeApiActivityList', null);

                    //修改拼团活动商品
                    (new PtGoods())->baseUpdate($allMap, $saveStatus);
                    (new PtGoodsSku())->baseUpdate($allMap, $saveStatus);
                    cache('ApiHomePtList', null);

                    //修改拼团活动商品
                    (new PpylGoods())->baseUpdate($allMap, $saveStatus);
                    (new PpylGoodsSku())->baseUpdate($allMap, $saveStatus);
                    cache('ApiHomePpylList', null);

                    cache('ApiHomeAllList', null);
                    Cache::tag(['apiHomeGoodsList', 'HomeApiActivityList'])->clear();
                }

                $count++;
            }

            $spuAttr = json_encode($spuAttrSpecs, JSON_UNESCAPED_UNICODE);
            //修改商品SPU全部属性和活动SPU的最低销售价
            if ($skuRes) {
                (new GoodsSpu())->baseUpdate(['goods_sn' => $data['goods_sn']], ['attribute_list' => $spuAttr, 'saleable' => 1]);
                //同步修改商品SPU的最低价
                if (!empty(min(array_unique(array_filter(array_column($aSku, 'sale_price')))))) {
                    $minSalePrice = min(array_unique(array_filter(array_column($aSku, 'sale_price'))));
                    ActivityGoods::update(['sale_price' => $minSalePrice], ['goods_sn' => $data['goods_sn'], 'status' => [1, 2]]);
                    PtGoods::update(['sale_price' => $minSalePrice], ['goods_sn' => $data['goods_sn'], 'status' => [1, 2]]);
                    PpylGoods::update(['sale_price' => $minSalePrice], ['goods_sn' => $data['goods_sn'], 'status' => [1, 2]]);
                }

            }
            //添加SKU对应的分销规则
            if ($skuRes) {
                $vCount = 0;
                $goodsSkuVdc = (new GoodsSkuVdc());
                foreach ($skuVdc as $key => $value) {
                    $addVdc = [];
                    $addVdc['goods_sn'] = $data['goods_sn'];
                    $addVdc['sku_sn'] = $value['sku_sn'];
                    $addVdc['belong'] = $value['belong'] ?? 1;
                    foreach ($value['vdc'] as $k => $v) {
                        $addVdc['vdc_type'] = $v['vdc_type'] ?? 1;
                        $addVdc['level'] = intval($v['level']);
                        $addVdc['vdc_one'] = $v['vdc_one'];
                        $addVdc['vdc_two'] = $v['vdc_two'];
                        $addVdc['vdc_genre'] = $v['vdc_genre'] ?? 1;
                        $addVdc['purchase_price'] = doubleval($v['purchase_price']);
                        if(empty($existSkuList[$value['sku_sn']] ?? [])){
                            $skuVdcRes = $goodsSkuVdc->updateOrCreate(['goods_sn' => $data['goods_sn'], 'sku_sn' => $value['sku_sn'], 'level' => $addVdc['level'], 'status' => [1, 2]], $addVdc);
                        }

                    }
                }
            }
            return $skuRes;
        });

        return $res;
    }

    /**
     * @title  查看订单中商品的关键属性
     * @param array $data
     * @param bool $needCache 是否需要缓存信息
     * @return array
     * @throws \Exception
     */
    public function getInfoByOrderGoods(array $data, $needCache = false)
    {
        $aSku = array_column($data, 'sku_sn');
        if (!empty($needCache)) {
            $skuMd5 = md5Encrypt(implode(',', $aSku), 16);
            $cache = config('cache.systemCacheKey.orderGoodsSkuInfo');
            $cacheKey = $cache['key'] . $skuMd5;
            $cacheList = cache($cacheKey);
            if (!empty($cacheList)) {
                $aSkuList = $cacheList;
            }
        }

        if (empty($aSkuList)) {
            $aSkuList = $this->alias('a')
                ->join('sp_goods_spu b', 'a.goods_sn = b.goods_sn', 'left')
                ->where(['a.sku_sn' => $aSku, 'a.status' => 1])
                ->field('a.goods_sn,a.sku_sn,a.title,b.category_code')
                ->select()
                ->each(function ($item, $key) {
                    $item['category_code'] = explode(',', $item['category_code']);
                    return $item;
                })->toArray();

            if (!empty($needCache) && !empty($aSkuList)) {
                cache($cacheKey, $aSkuList, $cache['expire']);
            }
        }


        return $aSkuList;
    }

    /**
     * @title  仅根据sku返回sku部分详情(下订单专属)
     * @param array $goods 商品SKU编码数组
     * @param bool $needCache 是否需要缓存
     * @param int $orderType 订单类型
     * @param mixed $activityId 活动id或拼团编号
     * @param int $userLevel 用户等级
     * @param string $orderLinkUser 关联用户uid
     * @param string $orderUid 订单用户uid
     * @param array $otherParam 其他额外参数
     * @return array
     * @throws \Exception
     */
    public function getInfoBySkuSn(array $goods, bool $needCache = false, int $orderType = 1, $activityId = null, int $userLevel = 0, string $orderLinkUser = null, string $orderUid = null,array $otherParam= []): array
    {
        $skuSn = array_column($goods, 'sku_sn');
        $activityId = array_unique(array_filter(array_column($goods, 'activity_id')));
        $activityGoodsNumber = 0;
        foreach ($goods as $key => $value) {
            if (!empty($value['activity_id'])) {
                $activityGoodsNumber += 1;
            }
        }
        $roundNumber = $otherParam['round_number'] ?? null;
        $periodNumber = $otherParam['period_number'] ?? null;
        //先查找sku的id,再通过id索引去查找详情,配合lock+事务才能完成行锁的锁定
        $skuId = $this->where(['sku_sn' => $skuSn, 'status' => 1])->column('id');
        if (empty($skuId)) {
            throw new OrderException(['errorCode' => 1500106]);
        }
        $list = $this->with(['shopSkuVdc'])->where(['id' => $skuId])->field('goods_sn,sku_sn,title,sale_price,member_price,fare,stock,specs,postage_code,attach_type,price_maintain')->when($needCache, function ($query) use ($skuSn) {
            $skuMd5 = md5Encrypt(implode(',', $skuSn), 16);
            $cache = config('cache.systemCacheKey.orderSku');
            $cacheKey = $cache['key'] . $skuMd5;
            $query->cache($cacheKey, $cache['expire']);
        })->lock(true)->select()->toArray();

        if (!empty($list)) {
            //若现在商品价格正处于维护中时,不允许下单,库存默认全部为0,只有重新锁定价格后才能正常售卖
            foreach ($list as $key => $value) {
                if ($value['price_maintain'] == 2) {
                    $list[$key]['stock'] = 0;
                }
            }
            if (!empty($activityId)) {
                if (in_array($orderType, [1, 7, 8])) {
                    //正常下单情况查找当前活动是否有优惠价格
                    $activityGoods = ActivityGoodsSku::with(['goodsSpu'])->where(['activity_id' => $activityId, 'sku_sn' => $skuSn, 'status' => 1])->field('title,goods_sn,sku_sn,specs,sale_price,activity_price,specs,sale_number,activity_id,limit_buy_number,gift_type,gift_number')->select()->toArray();
                    $existGoods = [];
                    $existGoodsCount = 0;
                    foreach ($activityGoods as $key => $value) {
                        foreach ($goods as $gKey => $gValue) {
                            if ($value['activity_id'] == $gValue['activity_id'] && $value['sku_sn'] == $gValue['sku_sn']) {
                                //写死活动id为3的产品只能会员身份才能购买
                                if (empty($userLevel) && $value['activity_id'] == 3) {
                                    throw new OrderException(['msg' => '商品' . $value['title'] . '仅允许会员购买~']);
                                }
                                $existGoods[$existGoodsCount] = $value;
                                $existGoodsCount += 1;
                            }
                            //限购
                            if (!empty($orderUid) && !empty($value['limit_buy_number'] ?? 0)) {
                                $existBuyNumber = OrderGoods::alias('a')
                                    ->join('sp_order b', 'b.order_sn = a.order_sn', 'left')
                                    ->where(['a.goods_sn' => $value['goods_sn'], 'a.pay_status' => [1, 2], 'a.status' => 1, 'b.order_status' => [1, 2, 3, 5, 6, 8], 'b.uid' => $orderUid])
                                    ->sum('a.count');
                                if (intval($existBuyNumber ?? 0) + intval($gValue['number']) > $value['limit_buy_number']) {
                                    throw new OrderException(['msg' => '商品<' . $value['title'] . '>限购' . $value['limit_buy_number'] . '件哟~']);
                                }
                            }
                        }
                    }

                    if (count($existGoods) != $activityGoodsNumber) {
                        throw new OrderException(['msg' => '某些商品不在活动有效期内哦~请检查一下']);
                    }
                    if (!empty($existGoods)) {
                        foreach ($existGoods as $key => $value) {
                            $activityInfo = $value['goodsSpu'];
                            if (empty($activityInfo)) {
                                throw new OrderException(['msg' => '订单中存在不可参与的活动商品']);
                            }
                            $nowTime = date('Y-m-d H:i:s', time());
                            if ($activityInfo['limit_type'] == 1) {
                                if ($activityInfo['start_time'] > $nowTime || $activityInfo['end_time'] <= $nowTime) {
                                    throw new OrderException(['msg' => '订单中的活动商品 ' . $value['title'] . ' 不在活动期内,无法购买']);
                                }
                            }
                        }
                    }

                } elseif ($orderType == 2) {
                    if (count($goods) > 1) {
                        throw new PtException(['msg' => '每次拼团仅允许购买一个商品哦']);
                    }
                    //先查找sku的id,再通过id索引去查找详情,配合lock+事务才能完成行锁的锁定
                    $ptGoodsSkuId = PtGoodsSku::where(['activity_code' => $activityId, 'sku_sn' => $skuSn, 'status' => 1])->column('id');
                    if (empty($ptGoodsSkuId)) {
                        throw new OrderException(['errorCode' => 1500106]);
                    }
                    //拼团情况
                    $activityGoods = PtGoodsSku::with(['goodsSpu'])->where(['id' => $ptGoodsSkuId])->field('activity_code,goods_sn,sku_sn,specs,stock,sale_price,activity_price,specs,title,start_number')->lock(true)->select()->toArray();
                    //拼团详情
                    $ptInfo = PtActivity::where(['activity_code' => $activityId, 'status' => [1]])->field('activity_code,activity_title,type')->findOrEmpty()->toArray();

                    $existGoodsCount = 0;
                    $existGoods = [];
                    foreach ($activityGoods as $key => $value) {
                        foreach ($goods as $gKey => $gValue) {
                            if ($value['activity_code'] == $gValue['activity_id'] && $value['sku_sn'] == $gValue['sku_sn']) {
                                $existGoods[$existGoodsCount] = $value;
                                $existGoodsCount += 1;
                            }
                        }
                    }
                    if (count($existGoods) != $activityGoodsNumber) {
                        throw new OrderException(['msg' => '某些商品不在活动有效期内哦~请检查一下']);
                    }
                    if (!empty($existGoods)) {
                        foreach ($existGoods as $key => $value) {
                            $activityInfo = $value['goodsSpu'];
                            if (empty($activityInfo)) {
                                throw new OrderException(['msg' => '订单中存在不可参与的拼团活动商品']);
                            }
                            $nowTime = date('Y-m-d H:i:s', time());
                            if ($activityInfo['start_time'] > $nowTime || $activityInfo['end_time'] <= $nowTime) {
                                throw new OrderException(['msg' => '订单中的活动商品 ' . $value['title'] . ' 不在拼团活动期内,无法购买']);
                            }
                        }
                    }

                } elseif ($orderType == 3) {
                    //团长大礼包情况
//                    if(!empty($userLevel)){
//                        throw new OrderException(['msg'=>'您已经是会员啦~不需要再购买团长大礼包了~']);
//                    }
                    $activityGoods = ActivityGoodsSku::with(['goodsSpu'])->where(['activity_id' => $activityId, 'sku_sn' => $skuSn, 'status' => 1])->field('activity_id,goods_sn,sku_sn,title,specs,sale_price,activity_price,vip_level,specs,limit_buy_number')->select()->toArray();
                    if (!empty($activityGoods)) {
                        foreach ($activityGoods as $key => $value) {
                            //限制指定等级购买的礼包没有上级关联人不允许购买
                            if (!empty($value['vip_level']) && $value['vip_level'] > 0) {
                                if (empty($orderLinkUser)) {
                                    throw new OrderException(['errorCode' => 1500119]);
                                }
                            }
                            //判断礼包的购买所需的等级
                            if (empty($userLevel)) {
                                if ($value['vip_level'] < 0) {
                                    throw new OrderException(['errorCode' => 1500118]);
                                }
                            } else {
//                                if ($value['vip_level'] < $userLevel) {
//                                    throw new OrderException(['errorCode' => 1500118]);
//                                }
                                if (intval($value['vip_level']) <= 0) {
                                    if ($value['vip_level'] < $userLevel) {
                                        throw new OrderException(['errorCode' => 1500118]);
                                    }
                                } else {
                                    if ($value['vip_level'] > $userLevel) {
                                        throw new OrderException(['errorCode' => 1500118]);
                                    }
                                }
                            }
                            //判断开售时间
                            $activityInfo = $value['goodsSpu'] ?? [];
                            if (empty($activityInfo)) {
                                throw new OrderException(['msg' => '订单中存在不可参与的礼包商品']);
                            }
                            $nowTime = date('Y-m-d H:i:s', time());
                            if ($activityInfo['limit_type'] == 1) {
                                if ($activityInfo['start_time'] > $nowTime || $activityInfo['end_time'] <= $nowTime) {
                                    throw new OrderException(['msg' => '订单中的礼包商品 ' . $value['title'] . ' 不在活动期内,无法购买']);
                                }
                            }
                            //暂时写死商品只允许购买一件 2021/3/8 12:46
//                            foreach ($goods as $gKey => $gValue) {
//                                if(!empty(doubleval($gValue['number'])) && $gValue['number'] > 1){
//                                    throw new OrderException(['msg'=>'礼包商品限购一件哟~']);
//                                }
//                            }
                            //限购
                            if (!empty($orderUid) && !empty($value['limit_buy_number'] ?? 0)) {
                                foreach ($goods as $gKey => $gValue) {
                                    $existBuyNumber = OrderGoods::alias('a')
                                        ->join('sp_order b', 'b.order_sn = a.order_sn', 'left')
                                        ->where(['a.goods_sn' => $value['goods_sn'], 'a.pay_status' => [1, 2], 'a.status' => 1, 'b.order_status' => [1, 2, 3, 5, 6, 8], 'b.uid' => $orderUid])
                                        ->sum('a.count');
                                    if (intval($existBuyNumber ?? 0) + intval($gValue['number']) > $value['limit_buy_number']) {
                                        throw new OrderException(['msg' => '商品<' . $value['title'] . '>限购' . $value['limit_buy_number'] . '件哟~']);
                                    }
                                }
                            }

                        }
                    }
                } elseif ($orderType == 4) {
                    if (count($goods) > 1) {
                        throw new PtException(['msg' => '每次拼团仅允许购买一个商品哦']);
                    }
                    //先查找sku的id,再通过id索引去查找详情,配合lock+事务才能完成行锁的锁定
                    $ptGoodsSkuId = PpylGoodsSku::where(['area_code' => $activityId, 'sku_sn' => $skuSn, 'status' => 1])->column('id');
                    if (empty($ptGoodsSkuId)) {
                        throw new OrderException(['errorCode' => 1500106]);
                    }

                    //拼拼有礼情况
                    $activityGoods = PpylGoodsSku::with(['goodsSpu'])->where(['id' => $ptGoodsSkuId, 'status' => 1])->field('activity_code,area_code,goods_sn,sku_sn,stock,specs,activity_price,leader_price,title,sku_title,share_title,share_desc,share_cover,growth_value')->select()->toArray();
                    if (!empty($activityGoods)) {
                        $activityCode = current(array_unique(array_column($activityGoods, 'activity_code')));
                        //拼团详情
                        $ptInfo = PpylActivity::where(['activity_code' => $activityCode, 'status' => [1]])->field('activity_code,activity_title,type')->findOrEmpty()->toArray();
                    }

                    $existGoodsCount = 0;
                    $existGoods = [];
                    foreach ($activityGoods as $key => $value) {
                        foreach ($goods as $gKey => $gValue) {
                            if ($value['area_code'] == $gValue['activity_id'] && $value['sku_sn'] == $gValue['sku_sn']) {
                                $existGoods[$existGoodsCount] = $value;
                                $existGoodsCount += 1;
                            }
                        }
                    }
                    if (count($existGoods) != $activityGoodsNumber) {
                        throw new OrderException(['msg' => '某些商品不在活动有效期内哦~请检查一下']);
                    }
                    if (!empty($existGoods)) {
                        foreach ($existGoods as $key => $value) {
                            $activityInfo = $value['goodsSpu'];
                            if (empty($activityInfo)) {
                                throw new OrderException(['msg' => '订单中存在不可参与的美好拼拼活动商品']);
                            }
                            $nowTime = date('Y-m-d H:i:s', time());
                            if ($activityInfo['start_time'] > $nowTime || $activityInfo['end_time'] <= $nowTime) {
                                throw new OrderException(['msg' => '订单中的活动商品 ' . $value['title'] . ' 不在活动期内,无法购买']);
                            }
                        }
                    }

                }elseif ($orderType == 6){
                    $activityGoodsIds = CrowdfundingActivityGoodsSku::where(function ($query) use ($goods) {
                        foreach ($goods as $key => $value) {
                            ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_id']];
                            ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                            ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                        }
                        for ($i = 0; $i < count($goods); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->where(['sku_sn' => $skuSn, 'status' => 1])->column('id');
                    //查找期详情
                    $activityPeriod = CrowdfundingPeriod::where(function ($query) use ($goods) {
                        foreach ($goods as $key => $value) {
                            ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_id']];
                            ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                            ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                        }
                        for ($i = 0; $i < count($goods); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->where(['status' => 1])->select()->toArray();
                    $activityPeriodInfo = [];
                    if(!empty($activityPeriod)){
                        foreach ($activityPeriod as $key => $value) {
                            $crowdKey = $value['activity_code'].'-'.$value['round_number'].'-'.$value['period_number'];
                            $activityPeriodInfo[$crowdKey] = $value;
                            //写入判断缓存, 如果有支付成功的则会判断是否需要判断期的成功, 以此来避免额外的性能开销
                            Cache::set('crowdFundPeriodJudgePrice-' . $crowdKey, priceFormat($value['last_sales_price'] * 0.9), (3600 * 3));
                        }
                    }

                    //查找期的开放时间段
                    $activityPeriodDuration = CrowdfundingPeriodSaleDuration::where(function ($query) use ($activityPeriod) {
                        foreach ($activityPeriod as $key => $value) {
                            ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_code']];
                            ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                            ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                        }
                        for ($i = 0; $i < count($activityPeriod); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->where(['status' => 1])->select()->toArray();

                    $activityPeriodDurationInfo = [];
                    if(!empty($activityPeriodDuration)){
                        foreach ($activityPeriodDuration as $key => $value) {
                            $crowdKey = $value['activity_code'].'-'.$value['round_number'].'-'.$value['period_number'];
                            $activityPeriodDurationInfo[$crowdKey][] = $value;
                        }
                    }

                    if(!empty($activityGoodsIds)){
                        $activityGoods = CrowdfundingActivityGoodsSku::with(['goodsSpu'])->where(['id' => $activityGoodsIds])->field('title,goods_sn,sku_sn,specs,sale_price,activity_price,specs,sale_number,activity_code,round_number,period_number')->lock(true)->select()->toArray();
                        if (empty($activityGoods ?? [])) {
                            throw new CrowdFundingActivityException(['msg' => '查无有效活动商品哦']);
                        }
                        foreach ($activityGoods as $key => $value) {
                            $activityGoodsInfo[$value['sku_sn']] = $value;
                        }
                        //获取所有有效的商品SPU查询是否在活动期内
                        $allGoodsSpu = array_unique(array_column($activityGoods,'goods_sn'));
                        $activityGoodsSpu = CrowdfundingActivityGoods::where(function ($query) use ($goods) {
                            foreach ($goods as $key => $value) {
                                ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_id']];
                                ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                                ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                            }
                            for ($i = 0; $i < count($goods); $i++) {
                                $allWhereOr[] = ${'where' . ($i + 1)};
                            }
                            $query->whereOr($allWhereOr);
                        })->where(['goods_sn' => $allGoodsSpu, 'status' => 1])->select()->toArray();
                        if(empty($activityGoodsSpu)){
                            throw new CrowdFundingActivityException(['msg'=>'查无有效活动商品']);
                        }
                        $jumpTimeJudge = false;
                        //判断用户是否有提前购资格, 有则直接可以购买, 跳过时间判断, 同时跳过期开始时间和开放时间段判断
                        $checkUserAdvanceBuy = (new AdvanceCardDetail())->checkUserAdvanceBuy(['checkType' => 1, 'uid' => $orderUid, 'goods' => $goods]);
                        if (!empty($checkUserAdvanceBuy) && !empty($checkUserAdvanceBuy['res'])) {
                            $jumpTimeJudge = true;
                        }
                        if(empty($jumpTimeJudge ?? false)){
                            //判断活动时间
                            foreach ($activityGoodsSpu as $key => $value) {
                                foreach ($goods as $gKey => $gValue) {
                                    if ($value['activity_code'] == $gValue['activity_id'] && $value['goods_sn'] == $gValue['goods_sn'] && $value['round_number'] == $gValue['round_number'] && $value['period_number'] == $gValue['period_number']) {
                                        if ($value['limit_type'] == 1 && ($value['start_time'] > time() || $value['end_time'] <= time())) {
                                            throw new OrderException(['msg' => '订单中的活动商品 ' . $value['title'] . ' 不在活动期内,无法购买']);
                                        }
                                    }
                                }
                            }

                            //判断活动开放时间段
                            foreach ($goods as $key => $value) {
                                if (!empty($activityPeriodDurationInfo[$value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number']] ?? null)) {
                                    $canBuyGoods[$value['sku_sn']] = false;
                                    foreach ($activityPeriodDurationInfo[$value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number']] as $dKey => $dValue) {
                                        if (time() >= $dValue['start_time'] && time() < $dValue['end_time']) {
                                            $canBuyGoods[$value['sku_sn']] = true;
                                        }
                                    }
                                    if (empty($canBuyGoods[$value['sku_sn']] ?? null)) {
                                        throw new OrderException(['msg' => '订单中的活动商品<' . $activityGoodsInfo[$value['sku_sn']]['title'] . '> 不在购买开放时间段内, 暂无法购买, 请耐心等待']);
                                    }
                                }
                            }
                        }

                        $existGoods = [];
                        $existGoodsCount = 0;
                        foreach ($activityGoods as $key => $value) {
                            foreach ($goods as $gKey => $gValue) {
                                if ($value['activity_code'] == $gValue['activity_id'] && $value['sku_sn'] == $gValue['sku_sn'] && $value['round_number'] == $gValue['round_number'] && $value['period_number'] == $gValue['period_number'] ) {
                                    $existGoods[$existGoodsCount] = $value;
                                    $existGoodsCount += 1;

                                    //查看是否有正在处理中的缓存锁, 有则不允许继续操作
                                    $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                                    if (!empty(cache('CrowdFundingPeriodSuccess-' . $crowdKey)) || !empty(cache('CrowdFundingPeriodFail-' . $crowdKey))) {
                                        throw new OrderException(['msg' => '前方拥挤, 暂无法下单~']);
                                    }
                                }
                            }
                        }

                        if (count($existGoods) != $activityGoodsNumber) {
                            throw new OrderException(['msg' => '某些商品不在活动有效期内哦~请检查一下']);
                        }
                        if (!empty($existGoods)) {
                            //限购
                            $userNotLimitAmount = false;
                            //查找限购白名单, 如果在白名单内不做限购判断
                            $notLimitBuyAmount = CrowdfundingSystemConfig::where(['id' => 1])->value('period_not_limit_amount_user');
                            if (!empty($notLimitBuyAmount) && !empty(explode(',', $notLimitBuyAmount)) && in_array($orderUid, explode(',', $notLimitBuyAmount))) {
                                $userNotLimitAmount = true;
                            }

                            $existBuyList = [];
                            if(empty($userNotLimitAmount ?? false)){
                                //查找历史购买记录
                                $existBuyList = OrderGoods::alias('a')
                                    ->join('sp_order b', 'b.order_sn = a.order_sn', 'left')
                                    ->where(function ($query) use ($goods) {
                                        foreach ($goods as $key => $value) {
                                            ${'where' . ($key + 1)}[] = ['a.crowd_code', '=', $value['activity_id']];
                                            ${'where' . ($key + 1)}[] = ['a.crowd_round_number', '=', $value['round_number']];
                                            ${'where' . ($key + 1)}[] = ['a.crowd_period_number', '=', $value['period_number']];
                                        }
                                        for ($i = 0; $i < count($goods); $i++) {
                                            $allWhereOr[] = ${'where' . ($i + 1)};
                                        }
                                        $query->whereOr($allWhereOr);
                                    })->where(['a.pay_status' => [1, 2], 'a.status' => 1, 'b.order_status' => [1, 2, 3, 5, 6, 8], 'b.order_type' => 6, 'b.uid' => $orderUid])
                                    ->field('b.order_sn,a.count,a.real_pay_price,a.total_fare_price,a.goods_sn,a.sku_sn,a.crowd_code,a.crowd_round_number,a.crowd_period_number')
                                    ->select()->toArray();
                            }

                            $existBuyGoods = [];
                            $existJoinNumber = [];
                            if (!empty($existBuyList)) {
                                $goodsCrowdKey = null;
                                foreach ($existBuyList as $key => $value) {
                                    $goodsCrowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                                    if (!isset($existBuyGoods[$goodsCrowdKey])) {
                                        $existBuyGoods[$goodsCrowdKey] = 0;
                                    }
                                    if (!isset($existJoinNumber[$goodsCrowdKey])) {
                                        $existJoinNumber[$goodsCrowdKey] = 0;
                                    }
                                    $existBuyGoods[$goodsCrowdKey] += ($value['real_pay_price'] - $value['total_fare_price']);
                                    $existJoinNumber[$goodsCrowdKey] += 1;
                                }
                            }
                            foreach ($list as $key => $value) {
                                $goodsInfo[$value['sku_sn']] = $value;
                            }
                            foreach ($goods as $gKey => $gValue) {
                                $crowdKey = $gValue['activity_id'] . '-' . $gValue['round_number'] . '-' . $gValue['period_number'];

                                if ((intval($existJoinNumber[$crowdKey] ?? 0) + 1) > ($activityPeriodInfo[$crowdKey]['join_limit_number'] ?? 1)) {
                                    throw new OrderException(['msg' => '商品<' . $goodsInfo[$gValue['sku_sn']]['title'] . '>限参与' . ($activityPeriodInfo[$crowdKey]['join_limit_number'] ?? 1) . '次哟~']);
                                }
                                if (empty($userNotLimitAmount ?? false)) {
                                    if (doubleval($existBuyGoods[$crowdKey] ?? 0) > ($activityPeriodInfo[$crowdKey]['join_limit_amount'] ?? 100)) {
                                        throw new OrderException(['msg' => '本期<' . $activityPeriodInfo[$crowdKey]['title'] . '>限购总额为' . ($activityPeriodInfo[$crowdKey]['join_limit_amount'] ?? 100) . '元哟~']);
                                    }
                                }
                            }
                        }
                    }
                }

                if (!empty($activityGoods)) {
                    foreach ($list as $key => $value) {
                        foreach ($activityGoods as $aKey => $aValue) {
                            if ($aValue['sku_sn'] == $value['sku_sn']) {
                                if (doubleval($aValue['activity_price']) <= doubleval($value['sale_price'])) {
                                    $list[$key]['sale_price'] = $aValue['activity_price'];
                                    //会员价暂时和最后销售价一样
                                    $list[$key]['member_price'] = $aValue['activity_price'];
                                }
                                if ($orderType == 2) {
                                    if (empty($aValue['stock']) || $aValue['stock'] < 0) {
                                        $list[$key]['stock'] = 0;
                                    } else {
                                        $list[$key]['stock'] = ($value['stock'] >= $aValue['stock']) ? $aValue['stock'] : $value['stock'];
                                    }
                                    //如果是邀新团,并且开团人的价格比活动价还低的话,直接用开团人的价格作为支付价格
                                    $list[$key]['use_leader_price'] = false;
                                    if (!empty($ptInfo) && in_array($ptInfo['type'], [4]) && !empty($aValue['leader_price']) && (string)$aValue['leader_price'] <= (string)$list[$key]['sale_price']) {
                                        $list[$key]['leader_price'] = $aValue['leader_price'];
                                        $list[$key]['use_leader_price'] = true;
                                    }
                                }

                                if ($orderType == 6) {
                                    $list[$key]['activity_id'] = $aValue['activity_code'] ?? null;
                                    $list[$key]['activity_sn'] = $aValue['activity_code'] ?? null;
                                    $list[$key]['activity_code'] = $aValue['activity_code'] ?? null;
                                    $list[$key]['round_number'] = $aValue['round_number'] ?? null;
                                    $list[$key]['period_number'] = $aValue['period_number'] ?? null;
                                }
                                $list[$key]['gift_type'] = $aValue['gift_type'] ?? -1;
                                $list[$key]['gift_number'] = $aValue['gift_number'] ?? 0;
//                                //扣去活动的虚拟销量
//                                if($orderType == 1){
//                                    if(!empty($aValue['sale_number'])){
//                                        $stock = $value['stock'] - $aValue['sale_number'];
//                                        $list[$key]['stock'] = (intval($stock) > 0) ? $stock : 0;
//                                    }
//                                }
                            }
                        }
                        $list[$key]['stock'] = intval($list[$key]['stock']) > 0 ? $list[$key]['stock'] : 0;
                    }
                }
            }

            foreach ($list as $key => $value) {
                //修改不同会员不同成本价
                if (!empty($userLevel) && $orderType != 4) {
                    foreach ($value['shopSkuVdc'] as $vKey => $vValue) {
                        if ($vValue['sku_sn'] == $value['sku_sn']) {
                            if ($vValue['level'] == $userLevel) {
                                $list[$key]['sale_price'] = $vValue['purchase_price'];
                                //会员价暂时和最后销售价一样
                                $list[$key]['member_price'] = $vValue['purchase_price'];

                                //如果是邀新团,并且开团人的价格比活动价还低的话,直接用开团人的价格作为支付价格
                                if ($orderType == 2 && !empty($value['use_leader_price'])) {
                                    $list[$key]['sale_price'] = ($value['leader_price'] > 0) ? $value['leader_price'] : $vValue['purchase_price'];
                                    //会员价暂时和最后销售价一样
                                    $list[$key]['member_price'] = $list[$key]['sale_price'];
                                }
                            }
                        }
                    }
                }
            }

            //众筹模式检测是否超过设定的销售额
            if ($orderType == 6) {
                //根据调整后的销售价判断是否限购
                foreach ($goods as $key => $value) {
                    $aGoodsInfo[$value['sku_sn']] = $value;
                }
                foreach ($list as $key => $value) {
                    $crowdKey = $value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    if (empty($userNotLimitAmount ?? false)) {
                        if ((string)(($value['sale_price'] * $aGoodsInfo[$value['sku_sn']]['number'] ?? 1) + ($existBuyGoods[$crowdKey] ?? 0)) > $activityPeriodInfo[$crowdKey]['join_limit_amount'] ?? 100) {
                            throw new OrderException(['msg' => '本期<' . $activityPeriodInfo[$crowdKey]['title'] . '>限购总额为' . ($activityPeriodInfo[$crowdKey]['join_limit_amount'] ?? 100) . '元哟~']);
                        }
                    }
                }
                $allSalesPrice = [];
                $crowdFundingActivityInfo = CrowdfundingPeriod::where(function ($query) use ($goods) {
                    foreach ($goods as $key => $value) {
                        ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_id']];
                        ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                        ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                    }
                    for ($i = 0; $i < count($goods); $i++) {
                        $allWhereOr[] = ${'where' . ($i + 1)};
                    }
                    $query->whereOr($allWhereOr);
                })->where(['status' => 1, 'buy_status' => 2])->select()->each(function($item){
                    //如果剩余销售额小于等于0则表示本期已经认购完成, 不允许继续超卖, 若还没满,则允许此订单超卖
                    $item['complete_buy'] = false;
                    if(doubleval($item['last_sales_price']) <= 0){
                        $item['complete_buy'] = true;
                    }
                })->toArray();
                if (empty($crowdFundingActivityInfo)) {
                    throw new CrowdFundingActivityException(['errorCode' => 2800103]);
                }

                //判断当前期的参与门槛
                foreach ($crowdFundingActivityInfo as $key => $value) {
                    $aKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    if (!isset($value['join_condition_type'])) {
                        continue;
                    }
                    if ($value['join_condition_type'] != -1 && (doubleval($value['condition_price'] ?? 0) > 0)) {
                        $userOrderPrice = 0;
                        $jcMap[$aKey] = [];
                        $jcMap[$aKey][] = ['', 'exp', Db::raw("order_sn in (select order_sn from sp_order where uid = '" . $orderUid . "' and order_type = 6 and pay_status = 2)")];
                        $jcMap[$aKey][] = ['pay_status', '=', 2];
                        switch ($value['join_condition_type']) {
                            case 2:
                                $jcMap[$aKey][] = ['crowd_code', '=', $value['activity_code']];
                                $jcMap[$aKey][] = ['crowd_round_number', '=', $value['round_number']];
//                                $jcMap[$aKey][] = ['crowd_period_number', '=', $value['period_number']];
                                break;
                        }
                        if ($value['price_compute_time_type'] == 2) {
                            $jcMap[$aKey][] = ['create_time', '>=', $value['condition_price_start_time']];
                            $jcMap[$aKey][] = ['create_time', '<=', $value['condition_price_end_time']];
                        }
                        $jcMap[$aKey][] = ['status', '=', 1];
                        $userOrderPrice = OrderGoods::where($jcMap[$aKey])->field('sum(real_pay_price - refund_price) as total_price')->findOrEmpty()->toArray()['total_price'] ?? 0;
                        switch ($value['price_compute_type']) {
                            case 1:
                            case 3:
                                if ((string)$userOrderPrice < (string)$value['condition_price']) {
                                    throw new CrowdFundingActivityException(['msg' => '您暂不满足本期参与条件, 感谢您的支持', 'vars' => [$value['condition_price']]]);
                                }
                                break;
                            case 2:
                                if ((string)$userOrderPrice > (string)$value['condition_price']) {
                                    throw new CrowdFundingActivityException(['msg' => '您不符合本期参与条件, 感谢您的支持']);
                                }
                                break;
                        }
                    }
                }

                foreach ($list as $key => $value) {
                    foreach ($goods as $gKey => $gValue) {
                        if ($value['sku_sn'] == $gValue['sku_sn'] && $value['activity_id'] == $gValue['activity_id'] && $value['round_number'] == $gValue['round_number'] && $value['period_number'] == $gValue['period_number']) {
                            $aKey = $value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                            if (!isset($allSalesPrice[$aKey])) {
                                $allSalesPrice[$aKey] = 0;
                            }
                            $allSalesPrice[$aKey] += ($gValue['number'] * $value['sale_price']);
                        }
                    }
                }
                //允许最后一个超卖
                foreach ($crowdFundingActivityInfo as $key => $value) {
                    //如果有提前购, 需要判断是否超过设置的提前购额度, 如果超过了则不允许提前购
                    if (!empty($jumpTimeJudge ?? false) && (doubleval($value['advance_buy_scale'] ?? 0) < 1)) {
                        if ((($value['last_sales_price'] - $allSalesPrice[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']]) / $value['sales_price']) < (1 - ($value['advance_buy_scale'] ?? 0.6))) {
                            throw new CrowdFundingActivityException(['errorCode' => 2800105]);
                        }
                    }
                    if ($allSalesPrice[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']] > $value['last_sales_price'] && !empty($value['complete_buy'] ?? false)) {
                        throw new CrowdFundingActivityException(['errorCode' => 2800104]);
                    }
                }

                //判断当前活动开放时间段允许认购的销售总额比例
                foreach ($crowdFundingActivityInfo as $key => $value) {
                    if (!empty($activityPeriodDurationInfo[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']] ?? null)) {
                        foreach ($activityPeriodDurationInfo[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']] as $dKey => $dValue) {
                            if (time() >= $dValue['start_time'] && time() < $dValue['end_time']) {
                                if (!empty($dValue['target_sum_scale'] ?? null) && (doubleval($dValue['target_sum_scale']) > 0 && doubleval($dValue['target_sum_scale']) < 1)) {
                                    if ((1 - ((($value['last_sales_price'] - $allSalesPrice[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']]) / $value['sales_price']) ?? 0)) >= $dValue['target_sum_scale']) {
                                        throw new CrowdFundingActivityException(['errorCode' => 2800106]);
                                    }
                                }

                            }
                        }
                    }
                }

                //判断此单购买是否属于超前提前购,即参与时间小于期开始和结束时间, 则给此商品添加预计发放的分润时间
                //认购成功后多久判断为正式的成功,秒为单位
                $successPeriodNumber = CrowdfundingSystemConfig::where(['id' => 1])->value('success_period_number');
                foreach ($list as $key => $value) {
                    //如果属于提前购, 再判断是否属于超前提前购
                    if (!empty($value['advance_buy'] ?? false)) {
                        $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                        if (!empty($crowdFundingActivityInfoArray[$crowdKey] ?? [])) {
                            if (time() < $crowdFundingActivityInfoArray[$crowdKey]['start_time'] && time() < $crowdFundingActivityInfoArray[$crowdKey]['end_time'] && ($crowdFundingActivityInfoArray[$crowdKey]['advance_buy_scale'] ?? 0.6) >= 1) {
                                $list[$key]['presale'] = true;
                                $presaleCheck = true;
                                //如果设置的提前购发放奖励时间小于本期实际判断成功的时间
                                if (doubleval($crowdFundingActivityInfoArray[$crowdKey]['advance_buy_reward_send_time'] ?? 0) >= (intval(($crowdFundingActivityInfoArray[$crowdKey]['end_time'] - $crowdFundingActivityInfoArray[$crowdKey]['start_time']) * ($successPeriodNumber ?? 3)) + (3600 * 24))) {
                                    //直接返回间隔, 后续在正式下单的时候用下单时间添加此间隔算出奖励发放时间
                                    $list[$key]['advance_buy_reward_send_time'] = intval($crowdFundingActivityInfoArray[$crowdKey]['advance_buy_reward_send_time']);
                                }
                            }
                        }
                    }
                }

                //判断用户参与超前提前购的期数, 如果超过限制则不允许继续超前提前购
                if (!empty($presaleCheck ?? false) && !empty($orderUid ?? false)) {
                    $allCrowdActivityList = CrowdfundingActivity::where(['activity_code' => array_unique(array_column($list, 'activity_code'))])->field('activity_code,title,user_advance_limit,auto_create_advance_limit')->select()->toArray();
                    if (!empty($allCrowdActivityList)) {
                        foreach ($allCrowdActivityList as $key => $value) {
                            $allCrowdActivityInfo[$value['activity_code']] = $value;
                        }
                        //汇总查询用户超前提前购详情
                        $userAdvanceDelayOrderGroupCount = CrowdfundingDelayRewardOrder::where(['uid' => $orderUid, 'arrival_status' => 3, 'status' => 1])->field('uid,crowd_code,crowd_period_number,count(id) as number')->group('crowd_code,crowd_period_number')->select()->toArray();
                        if (!empty($userAdvanceDelayOrderGroupCount)) {
                            foreach ($userAdvanceDelayOrderGroupCount as $key => $value) {
                                if (!isset($userAdvanceCount[$value['crowd_code']])) {
                                    $userAdvanceCount[$value['crowd_code']] = 0;
                                }
                                //每个区总超前提前购次数, 同一期多次参与算一次
                                $userAdvanceCount[$value['crowd_code']] += 1;
                            }
                        }
                        //该用户全部区总超前提前购次数
                        $userAdvanceCountTotal = array_sum($userAdvanceCount ?? []);

                        foreach ($list as $key => $value) {
                            if (!empty($value['presale'] ?? false) && !empty($allCrowdActivityInfo[$value['activity_code']] ?? null)) {
                                if (doubleval($allCrowdActivityInfo[$value['activity_code']]['user_advance_limit'] ?? 0) > 0 && ((string)($userAdvanceCount[$value['activity_code']] ?? 0) >= (string)($allCrowdActivityInfo[$value['activity_code']]['user_advance_limit'] ?? 0))) {
                                    throw new CrowdFundingActivityException(['msg' => '您参与的提前购期数已达到上限, 请耐心等待前置活动完成后再参与, 感谢您的理解和支持']);
                                }
                            }
                        }

                        //汇总查询所有期的超前提前购期数
                        $advanceDelayOrderGroupCount = CrowdfundingDelayRewardOrder::where(['crowd_code' => array_unique(array_column($allCrowdActivityList, 'activity_code')), 'arrival_status' => 3, 'status' => 1])->field('crowd_code,crowd_round_number,crowd_period_number,count(id) as number')->group('crowd_code,crowd_round_number,crowd_period_number')->select()->toArray();

                        if (!empty($advanceDelayOrderGroupCount)) {
                            foreach ($advanceDelayOrderGroupCount as $key => $value) {
                                if (!isset($advanceCount[$value['crowd_code']])) {
                                    $advanceCount[$value['crowd_code']] = 0;
                                }
                                //每个区总超前提前购次数, 同一期多次参与算一次
                                $advanceCount[$value['crowd_code']] += 1;
                            }
                            foreach ($allCrowdActivityList as $key => $value) {
                                if (doubleval($value['auto_create_advance_limit'] ?? 0) > 0 && ((string)($advanceCount[$value['activity_code']] ?? 0) >= (string)($value['auto_create_advance_limit'] ?? 0))) {
                                    throw new CrowdFundingActivityException(['msg' => '当前区提前购期数已达到上限, 请耐心等待前置活动完成后再参与, 感谢您的理解和支持']);
                                }
                            }
                        }
                    }
                }

            }
        }

        return $list ?? [];
    }

    /**
     * @title  预订单专属-仅根据sku返回sku部分详情
     * @param array $goods 商品SKU数组
     * @param int $orderType 订单类型
     * @param string|array $activityId 活动id或拼团编号
     * @param int $userLevel 用户等级
     * @param string $orderLinkUser 关联用户uid
     * @param string $orderUid 订单用户uid
     * @param array $otherParam 其他额外参数
     * @return array
     * @throws \Exception
     */
    public function getInfoBySkuSnForReadyOrder(array $goods, int $orderType = 1, $activityId = [], int $userLevel = 0, string $orderLinkUser = null,string $orderUid= null,array $otherParam= []): array
    {
        $skuSn = array_column($goods, 'sku_sn');
        $activityId = array_unique(array_filter(array_column($goods, 'activity_id')));
        $activityGoodsNumber = 0;
        foreach ($goods as $key => $value) {
            if (!empty($value['activity_id'])) {
                $activityGoodsNumber += 1;
            }
        }
        $roundNumber = $otherParam['round_number'] ?? null;
        $periodNumber = $otherParam['period_number'] ?? null;
        $existGoods = [];
        $list = $this->with(['category', 'shopSkuVdc'])->where(['sku_sn' => $skuSn, 'status' => 1])->field('goods_sn,sku_sn,title,sale_price,member_price,fare,stock,title,image,specs,postage_code,price_maintain')->select()->toArray();
        if (!empty($list)) {
            //若现在商品价格正处于维护中时,不允许下单,库存默认全部为0,只有重新锁定价格后才能正常售卖
            foreach ($list as $key => $value) {
                if ($value['price_maintain'] == 2) {
                    $list[$key]['stock'] = 0;
                }
            }
            if (!empty($activityId)) {
                if ($orderType == 1) {
                    //正常下单情况查找当前活动是否有优惠价格
                    $activityGoods = ActivityGoodsSku::with(['goodsSpu'])->where(['activity_id' => $activityId, 'sku_sn' => $skuSn, 'status' => 1])->field('activity_id,title,goods_sn,sku_sn,specs,sale_number,activity_price,gift_type,gift_number')->select()->toArray();

                    $existGoodsCount = 0;
                    foreach ($activityGoods as $key => $value) {
                        foreach ($goods as $gKey => $gValue) {
                            if ($value['activity_id'] == $gValue['activity_id'] && $value['sku_sn'] == $gValue['sku_sn']) {
                                //写死活动id为3的产品只能会员身份才能购买
                                if (empty($userLevel) && $value['activity_id'] == 3) {
                                    throw new OrderException(['msg' => '商品' . $value['title'] . '仅允许会员购买~']);
                                }
                                $existGoods[$existGoodsCount] = $value;
                                $existGoodsCount += 1;
                            }
                        }

                    }
                    if (count($existGoods) != $activityGoodsNumber) {
                        throw new OrderException(['msg' => '某些商品不在活动有效期内哦~请检查一下']);
                    }
                    if (!empty($existGoods)) {
                        unset($activityGoods);
                        $activityGoods = $existGoods;
                        foreach ($existGoods as $key => $value) {
                            $activityInfo = $value['goodsSpu'];
                            if (empty($activityInfo)) {
                                throw new OrderException(['msg' => '订单中存在不可参与的活动商品']);
                            }
                            $nowTime = date('Y-m-d H:i:s', time());
                            if ($activityInfo['limit_type'] == 1) {
                                if ($activityInfo['start_time'] > $nowTime || $activityInfo['end_time'] <= $nowTime) {
                                    throw new OrderException(['msg' => '订单中的活动商品 ' . $value['title'] . ' 不在活动期内,无法购买']);
                                }
                            }
                        }
                    }
                } elseif ($orderType == 2) {
                    //拼团情况
                    $activityGoods = PtGoodsSku::with(['goodsSpu'])->where(['activity_code' => $activityId, 'sku_sn' => $skuSn, 'status' => 1])->field('activity_code,goods_sn,sku_sn,stock,specs,activity_price,leader_price,title,sku_title,share_title,share_desc,share_cover,growth_value')->select()->toArray();
                    //拼团详情
                    $ptInfo = PtActivity::where(['activity_code' => $activityId, 'status' => [1]])->field('activity_code,activity_title,type')->findOrEmpty()->toArray();

                    $existGoodsCount = 0;
                    foreach ($activityGoods as $key => $value) {
                        foreach ($goods as $gKey => $gValue) {
                            if ($value['activity_code'] == $gValue['activity_id'] && $value['sku_sn'] == $gValue['sku_sn']) {
                                $value['activity_id'] = $value['activity_code'];
                                $existGoods[$existGoodsCount] = $value;
                                $existGoodsCount += 1;
                            }
                        }
                    }

                    if (count($existGoods) != $activityGoodsNumber) {
                        throw new OrderException(['msg' => '某些商品不在活动有效期内哦~请检查一下']);
                    }
                    if (!empty($existGoods)) {
                        unset($activityGoods);
                        $activityGoods = $existGoods;
                        foreach ($activityGoods as $key => $value) {
                            $activityInfo = $value['goodsSpu'];
                            if (empty($activityInfo)) {
                                throw new OrderException(['msg' => '订单中存在不可参与的拼团活动商品']);
                            }
                            $nowTime = date('Y-m-d H:i:s', time());
                            if ($activityInfo['start_time'] > $nowTime || $activityInfo['end_time'] <= $nowTime) {
                                throw new OrderException(['msg' => '订单中的活动商品 ' . $value['title'] . ' 不在拼团活动期内,无法购买']);
                            }
                        }
                    }

                } elseif ($orderType == 3) {
//                    if(!empty($userLevel)){
//                        throw new OrderException(['msg'=>'您已经是会员啦~不需要再购买团长大礼包了~']);
//                    }
                    //团长大礼包情况
                    $activityGoods = ActivityGoodsSku::with(['goodsSpu'])->where(['activity_id' => $activityId, 'sku_sn' => $skuSn, 'status' => 1])->field('activity_id,goods_sn,sku_sn,title,specs,activity_price,vip_level,limit_buy_number')->select()->toArray();
                    $existGoodsCount = 0;
                    foreach ($activityGoods as $key => $value) {
                        foreach ($goods as $gKey => $gValue) {
                            if ($value['activity_id'] == $gValue['activity_id'] && $value['sku_sn'] == $gValue['sku_sn']) {
                                $existGoods[$existGoodsCount] = $value;
                                $existGoodsCount += 1;
                                //临时写死团长大礼包仅允许限购一件 2021/3/8 12:41
//                                if (!empty(doubleval($gValue['number'])) && intval($gValue['number']) > 1) {
//                                    throw new OrderException(['msg' => '礼包商品限购一件哟~']);
//                                }
                                if (!empty($value['limit_buy_number'] ?? 0) && !empty($orderUid)) {
                                    //限购,查询历史购买记录
                                    $existBuyNumber = OrderGoods::alias('a')
                                        ->join('sp_order b', 'b.order_sn = a.order_sn', 'left')
                                        ->where(['a.goods_sn' => $value['goods_sn'], 'a.pay_status' => [1, 2], 'a.status' => 1, 'b.order_status' => [1, 2, 3, 5, 6, 8], 'b.uid' => $orderUid])
                                        ->sum('a.count');
                                    if (intval($existBuyNumber ?? 0) + intval($gValue['number']) > $value['limit_buy_number']) {
                                        throw new OrderException(['msg' => '商品<' . $value['title'] . '>限购' . $value['limit_buy_number'] . '件哟~']);
                                    }
                                }

                            }
                        }
                    }

                    if (count($existGoods) != $activityGoodsNumber) {
                        throw new OrderException(['msg' => '某些商品不在活动有效期内哦~请检查一下']);
                    }

                    if (!empty($existGoods)) {
                        unset($activityGoods);
                        $activityGoods = $existGoods;
                        foreach ($existGoods as $key => $value) {
                            //限制等级购买的礼包没有上级关联人不允许购买
                            if (!empty($value['vip_level']) && $value['vip_level'] > 0) {
//                                if(empty($orderLinkUser)){
//                                    throw new OrderException(['errorCode'=>1500119]);
//                                }
                            }
                            //判断礼包的购买所需的等级
                            if (empty($userLevel)) {
                                if ($value['vip_level'] < 0) {
                                    throw new OrderException(['errorCode' => 1500118]);
                                }
                            } else {
//                                if ($value['vip_level'] < $userLevel) {
//                                    throw new OrderException(['errorCode' => 1500118]);
//                                }
                                if (intval($value['vip_level']) <= 0) {
                                    if ($value['vip_level'] < $userLevel) {
                                        throw new OrderException(['errorCode' => 1500118]);
                                    }
                                } else {
                                    if ($value['vip_level'] > $userLevel) {
                                        throw new OrderException(['errorCode' => 1500118]);
                                    }
                                }
                            }
//                            //判断开售时间
                            $activityInfo = $value['goodsSpu'] ?? [];
                            if (empty($activityInfo)) {
                                throw new OrderException(['msg' => '订单中存在不可参与的礼包商品']);
                            }
                            $nowTime = date('Y-m-d H:i:s', time());
                            if ($activityInfo['limit_type'] == 1) {
                                if ($activityInfo['start_time'] > $nowTime || $activityInfo['end_time'] <= $nowTime) {
                                    throw new OrderException(['msg' => '订单中的礼包商品 ' . $value['title'] . ' 不在活动期内,无法购买']);
                                }
                            }

                        }
                    }
                } elseif ($orderType == 4) {
                    //拼拼有礼情况
                    $activityGoods = PpylGoodsSku::with(['goodsSpu'])->where(['area_code' => $activityId, 'sku_sn' => $skuSn, 'status' => 1])->field('activity_code,area_code,goods_sn,sku_sn,stock,specs,activity_price,leader_price,title,sku_title,share_title,share_desc,share_cover,growth_value')->select()->toArray();
                    if (!empty($activityGoods)) {
                        $activityCode = current(array_unique(array_column($activityGoods, 'activity_code')));
                        //拼团详情
                        $ptInfo = PpylActivity::where(['activity_code' => $activityCode, 'status' => [1]])->field('activity_code,activity_title,type')->findOrEmpty()->toArray();
                    }


                    $existGoodsCount = 0;
                    foreach ($activityGoods as $key => $value) {
                        foreach ($goods as $gKey => $gValue) {
                            if ($value['area_code'] == $gValue['activity_id'] && $value['sku_sn'] == $gValue['sku_sn']) {
                                $value['activity_id'] = $value['area_code'];
                                $existGoods[$existGoodsCount] = $value;
                                $existGoodsCount += 1;
                            }
                        }
                    }

                    if (count($existGoods) != $activityGoodsNumber) {
                        throw new OrderException(['msg' => '某些商品不在活动有效期内哦~请检查一下']);
                    }
                    if (!empty($existGoods)) {
                        unset($activityGoods);
                        $activityGoods = $existGoods;
                        foreach ($activityGoods as $key => $value) {
                            $activityInfo = $value['goodsSpu'];
                            if (empty($activityInfo)) {
                                throw new OrderException(['msg' => '订单中存在不可参与的拼拼有礼活动商品']);
                            }
                            $nowTime = date('Y-m-d H:i:s', time());
                            if ($activityInfo['start_time'] > $nowTime || $activityInfo['end_time'] <= $nowTime) {
                                throw new OrderException(['msg' => '订单中的活动商品 ' . $value['title'] . ' 不在拼团活动期内,无法购买']);
                            }
                        }
                    }
                } elseif ($orderType == 6) {
                    //查找期详情
                    $activityPeriod = CrowdfundingPeriod::where(function ($query) use ($goods) {
                        foreach ($goods as $key => $value) {
                            ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_id']];
                            ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                            ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                        }
                        for ($i = 0; $i < count($goods); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->where(['status' => 1])->select()->toArray();
                    $activityPeriodInfo = [];
                    if(!empty($activityPeriod)){
                        foreach ($activityPeriod as $key => $value) {
                            $crowdKey = $value['activity_code'].'-'.$value['round_number'].'-'.$value['period_number'];
                            $activityPeriodInfo[$crowdKey] = $value;
                        }
                    }
                    //查找期的开放时间段
                    $activityPeriodDuration = CrowdfundingPeriodSaleDuration::where(function ($query) use ($activityPeriod) {
                        foreach ($activityPeriod as $key => $value) {
                            ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_code']];
                            ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                            ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                        }
                        for ($i = 0; $i < count($activityPeriod); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->where(['status' => 1])->select()->toArray();

                    $activityPeriodDurationInfo = [];
                    if(!empty($activityPeriodDuration)){
                        foreach ($activityPeriodDuration as $key => $value) {
                            $crowdKey = $value['activity_code'].'-'.$value['round_number'].'-'.$value['period_number'];
                            $activityPeriodDurationInfo[$crowdKey][] = $value;
                        }
                    }

                    //众筹模式下查找当前活动(区轮期)是否有优惠价格
                    $activityGoods = CrowdfundingActivityGoodsSku::with(['goodsSpu'])->where(function ($query) use ($goods) {
                        foreach ($goods as $key => $value) {
                            ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_id']];
                            ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                            ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                        }
                        for ($i = 0; $i < count($goods); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->where(['sku_sn' => $skuSn, 'status' => 1])->field('activity_code,title,goods_sn,sku_sn,specs,sale_number,activity_price,round_number,period_number,gift_type,gift_number')->select()->toArray();
                    if (empty($activityGoods)) {
                        throw new CrowdFundingActivityException(['msg' => '查无有效活动商品哦']);
                    }
                    foreach ($activityGoods as $key => $value) {
                        $activityGoodsInfo[$value['sku_sn']] = $value;
                    }
                    //获取所有有效的商品SPU查询是否在活动期内
                    $allGoodsSpu = array_unique(array_column($activityGoods,'goods_sn'));
                    $activityGoodsSpu = CrowdfundingActivityGoods::where(function ($query) use ($goods) {
                        foreach ($goods as $key => $value) {
                            ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_id']];
                            ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                            ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                        }
                        for ($i = 0; $i < count($goods); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->where(['goods_sn' => $allGoodsSpu, 'status' => 1])->select()->toArray();
                    if(empty($activityGoodsSpu)){
                        throw new CrowdFundingActivityException(['msg'=>'查无有效活动商品']);
                    }
                    $jumpTimeJudge = false;
                    //判断用户是否有提前购资格, 有则直接可以购买, 跳过时间判断, 同时跳过期开始时间和开放时间段判断
                    $checkUserAdvanceBuy = (new AdvanceCardDetail())->checkUserAdvanceBuy(['checkType' => 1, 'uid' => $orderUid, 'goods' => $goods]);
                    if (!empty($checkUserAdvanceBuy) && !empty($checkUserAdvanceBuy['res'])) {
                        $jumpTimeJudge = true;
                    }

                    if(empty($jumpTimeJudge ?? false)){
                        //判断活动时间
                        foreach ($activityGoodsSpu as $key => $value) {
                            foreach ($goods as $gKey => $gValue) {
                                if ($value['activity_code'] == $gValue['activity_id'] && $value['goods_sn'] == $gValue['goods_sn'] && $value['round_number'] == $gValue['round_number'] && $value['period_number'] == $gValue['period_number']) {
                                    if ($value['limit_type'] == 1 && ($value['start_time'] > time() || $value['end_time'] <= time())) {
                                        throw new OrderException(['msg' => '订单中的活动商品 ' . $value['title'] . ' 不在活动期内,无法购买']);
                                    }
                                }
                            }
                        }

                        //判断活动开放时间段
                        foreach ($goods as $key => $value) {
                            if (!empty($activityPeriodDurationInfo[$value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number']] ?? null)) {
                                $canBuyGoods[$value['sku_sn']] = false;
                                foreach ($activityPeriodDurationInfo[$value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number']] as $dKey => $dValue) {
                                    if (time() >= $dValue['start_time'] && time() < $dValue['end_time']) {
                                        $canBuyGoods[$value['sku_sn']] = true;
                                    }
                                }
                                if (empty($canBuyGoods[$value['sku_sn']] ?? null)) {
                                    throw new OrderException(['msg' => '订单中的活动商品<' . $activityGoodsInfo[$value['sku_sn']]['title'] . '> 不在购买开放时间段内, 暂无法购买, 请耐心等待']);
                                }
                            }
                        }

                        foreach ($activityGoodsSpu as $key => $value) {
                            foreach ($goods as $gKey => $gValue) {
                                if ($value['activity_code'] == $gValue['activity_id'] && $value['goods_sn'] == $gValue['goods_sn'] && $value['round_number'] == $gValue['round_number'] && $value['period_number'] == $gValue['period_number']) {
                                    if ($value['limit_type'] == 1 && ($value['start_time'] > time() || $value['end_time'] <= time())) {
                                        throw new OrderException(['msg' => '订单中的活动商品 ' . $value['title'] . ' 不在活动期内,无法购买']);
                                    }
                                }
                            }
                        }
                    }

                    if (!empty($activityGoods)) {
                        $existGoodsCount = 0;
                        foreach ($activityGoods as $key => $value) {
                            foreach ($goods as $gKey => $gValue) {
                                if ($value['activity_code'] == $gValue['activity_id'] && $value['sku_sn'] == $gValue['sku_sn'] && $value['round_number'] == $gValue['round_number'] && $value['period_number'] == $gValue['period_number']) {
                                    $existGoods[$existGoodsCount] = $value;
                                    $existGoodsCount += 1;

                                    //查看是否有正在处理中的缓存锁, 有则不允许继续操作
                                    $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                                    if (!empty(cache('CrowdFundingPeriodSuccess-' . $crowdKey)) || !empty(cache('CrowdFundingPeriodFail-' . $crowdKey))) {
                                        throw new OrderException(['msg' => '前方拥挤, 暂无法下单~']);
                                    }
                                }
                            }

                        }
                        if (count($existGoods) != $activityGoodsNumber) {
                            throw new OrderException(['msg' => '某些商品不在活动有效期内哦~请检查一下']);
                        }
                        if (!empty($existGoods)) {
                            unset($activityGoods);
                            $activityGoods = $existGoods;
                            $userNotLimitAmount = false;
                            //查找限购白名单, 如果在白名单内不做限购判断
                            $notLimitBuyAmount = CrowdfundingSystemConfig::where(['id' => 1])->value('period_not_limit_amount_user');
                            if (!empty($notLimitBuyAmount) && !empty(explode(',', $notLimitBuyAmount)) && in_array($orderUid, explode(',', $notLimitBuyAmount))) {
                                $userNotLimitAmount = true;
                            }
                            $existBuyList = [];
                            if (empty($userNotLimitAmount ?? false)) {
                                //限购
                                //查找历史购买记录
                                $existBuyList = OrderGoods::alias('a')
                                    ->join('sp_order b', 'b.order_sn = a.order_sn', 'left')
                                    ->where(function ($query) use ($goods) {
                                        foreach ($goods as $key => $value) {
                                            ${'where' . ($key + 1)}[] = ['a.crowd_code', '=', $value['activity_id']];
                                            ${'where' . ($key + 1)}[] = ['a.crowd_round_number', '=', $value['round_number']];
                                            ${'where' . ($key + 1)}[] = ['a.crowd_period_number', '=', $value['period_number']];
                                        }
                                        for ($i = 0; $i < count($goods); $i++) {
                                            $allWhereOr[] = ${'where' . ($i + 1)};
                                        }
                                        $query->whereOr($allWhereOr);
                                    })->where(['a.pay_status' => [1, 2], 'a.status' => 1, 'b.order_status' => [1, 2, 3, 5, 6, 8], 'b.order_type' => 6, 'b.uid' => $orderUid])
                                    ->field('b.order_sn,a.count,a.real_pay_price,a.total_fare_price,a.goods_sn,a.sku_sn,a.crowd_code,a.crowd_round_number,a.crowd_period_number')
                                    ->select()->toArray();
                            }


                            $existBuyGoods = [];
                            $existJoinNumber = [];
                            if (!empty($existBuyList)) {
                                $goodsCrowdKey = null;
                                foreach ($existBuyList as $key => $value) {
                                    $goodsCrowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                                    if (!isset($existBuyGoods[$goodsCrowdKey])) {
                                        $existBuyGoods[$goodsCrowdKey] = 0;
                                    }
                                    if (!isset($existJoinNumber[$goodsCrowdKey])) {
                                        $existJoinNumber[$goodsCrowdKey] = 0;
                                    }
                                    $existBuyGoods[$goodsCrowdKey] += ($value['real_pay_price'] - $value['total_fare_price']);
                                    $existJoinNumber[$goodsCrowdKey] += 1;
                                }
                            }
                            foreach ($list as $key => $value) {
                                $goodsInfo[$value['sku_sn']] = $value;
                            }
                            foreach ($goods as $gKey => $gValue) {
                                $crowdKey = $gValue['activity_id'] . '-' . $gValue['round_number'] . '-' . $gValue['period_number'];

                                if ((intval($existJoinNumber[$crowdKey] ?? 0) + 1) > ($activityPeriodInfo[$crowdKey]['join_limit_number'] ?? 1)) {
                                    throw new OrderException(['msg' => '商品<' . $goodsInfo[$gValue['sku_sn']]['title'] . '>限参与' . ($activityPeriodInfo[$crowdKey]['join_limit_number'] ?? 1) . '次哟~']);
                                }
                                if (empty($userNotLimitAmount ?? false)) {
                                    if (intval($existBuyGoods[$crowdKey] ?? 0) > ($activityPeriodInfo[$crowdKey]['join_limit_amount'] ?? 100)) {
                                        throw new OrderException(['msg' => '本期<' . $activityPeriodInfo[$crowdKey]['title'] . '>限购总额为' . ($activityPeriodInfo[$crowdKey]['join_limit_amount'] ?? 100) . '元哟~']);
                                    }
                                }
                            }
                        }
                    }
                }

                if (!empty($activityGoods)) {
                    foreach ($goods as $key => $value) {
                        $aGoodsInfo[$value['sku_sn']] = $value;
                    }
                    foreach ($list as $key => $value) {
                        foreach ($activityGoods as $aKey => $aValue) {
                            if ($aValue['sku_sn'] == $value['sku_sn']) {
                                $list[$key]['activity_id'] = $aValue['activity_id'] ?? null;
                                if ((string)$aValue['activity_price'] <= (string)$value['sale_price']) {
                                    $list[$key]['sale_price'] = $aValue['activity_price'];
                                    //会员价暂时和最后销售价一样
                                    $list[$key]['member_price'] = $aValue['activity_price'];
                                }
                                if ($orderType == 2) {
                                    if (empty($aValue['stock']) || $aValue['stock'] < 0) {
                                        $list[$key]['stock'] = 0;
                                    } else {
                                        $list[$key]['stock'] = ($value['stock'] >= $aValue['stock']) ? $aValue['stock'] : $value['stock'];
                                    }

                                    $list[$key]['activity_sn'] = $aValue['activity_sn'] ?? null;
                                    $list[$key]['activity_code'] = $aValue['activity_code'] ?? null;
                                    $list[$key]['use_leader_price'] = false;
                                    //如果是邀新团,并且开团人的价格比活动价还低的话,直接用开团人的价格作为支付价格
                                    if (!empty($ptInfo) && in_array($ptInfo['type'], [2]) && !empty($aValue['leader_price']) && (string)$aValue['leader_price'] <= (string)$list[$key]['activity_price']) {
                                        $list[$key]['leader_price'] = $aValue['leader_price'];
                                        $list[$key]['use_leader_price'] = true;
                                    }
                                }

                                if ($orderType == 4) {
                                    if (empty($aValue['stock']) || $aValue['stock'] < 0) {
                                        $list[$key]['stock'] = 0;
                                    } else {
                                        $list[$key]['stock'] = ($value['stock'] >= $aValue['stock']) ? $aValue['stock'] : $value['stock'];
                                    }

                                    $list[$key]['activity_sn'] = $aValue['activity_sn'] ?? null;
                                    $list[$key]['activity_code'] = $aValue['activity_code'] ?? null;
                                    $list[$key]['area_code'] = $aValue['area_code'] ?? null;
                                }
                                if ($orderType == 6) {
                                    $list[$key]['activity_id'] = $aValue['activity_code'] ?? null;
                                    $list[$key]['activity_sn'] = $aValue['activity_code'] ?? null;
                                    $list[$key]['activity_code'] = $aValue['activity_code'] ?? null;
                                    $list[$key]['round_number'] = $aValue['round_number'] ?? null;
                                    $list[$key]['period_number'] = $aValue['period_number'] ?? null;
                                    $list[$key]['advance_buy'] = $jumpTimeJudge ?? false;
                                }
                                $list[$key]['gift_type'] = $aValue['gift_type'] ?? -1;
                                $list[$key]['gift_number'] = priceFormat(($aValue['gift_number'] ?? 0) * ($aGoodsInfo[$value['sku_sn']]['number'] ?? 1));
//                                //扣去活动的虚拟销量
//                                if($orderType == 1){
//                                    if(!empty($aValue['sale_number'])){
//                                        $stock = $value['stock'] - $aValue['sale_number'];
//                                        $list[$key]['stock'] = (intval($stock) > 0) ? $stock : 0;
//                                    }
//                                }
                            }
                        }
                        $list[$key]['stock'] = intval($list[$key]['stock']) > 0 ? $list[$key]['stock'] : 0;
                    }
                }
                $aGoodsInfo = [];
            }
            foreach ($list as $key => $value) {
                //修改不同会员不同成本价--拼拼有礼不允许按照成本价生成价格
                if (!empty($userLevel) && $orderType != 4) {
                    foreach ($value['shopSkuVdc'] as $vKey => $vValue) {
                        if ($vValue['sku_sn'] == $value['sku_sn']) {
                            if ($vValue['level'] == $userLevel) {
                                $list[$key]['sale_price'] = $vValue['purchase_price'];
                                //会员价暂时和最后销售价一样
                                $list[$key]['member_price'] = $vValue['purchase_price'];

                                //如果是邀新团,并且开团人的价格比活动价还低的话,直接用开团人的价格作为支付价格
                                if ($orderType == 2 && !empty($value['use_leader_price'])) {
                                    $list[$key]['sale_price'] = $value['leader_price'] ?? $vValue['purchase_price'];
                                    //会员价暂时和最后销售价一样
                                    $list[$key]['member_price'] = $value['leader_price'] ?? $vValue['purchase_price'];
                                }
                            }
                        }
                    }
                }
            }

            //众筹模式检测是否超过设定的销售额
            if ($orderType == 6) {
                //根据调整后的销售价判断是否限购
                foreach ($goods as $key => $value) {
                    $aGoodsInfo[$value['sku_sn']] = $value;
                }
                foreach ($list as $key => $value) {
                    $crowdKey = $value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    if (empty($userNotLimitAmount ?? false)) {
                        if ((string)(($value['sale_price'] * $aGoodsInfo[$value['sku_sn']]['number'] ?? 1) + ($existBuyGoods[$crowdKey] ?? 0)) > (string)($activityPeriodInfo[$crowdKey]['join_limit_amount'] ?? 100)) {
                            throw new OrderException(['msg' => '本期<' . $activityPeriodInfo[$crowdKey]['title'] . '>限购总额为' . ($activityPeriodInfo[$crowdKey]['join_limit_amount'] ?? 100) . '元哟~']);
                        }
                    }
                }

                $allSalesPrice = [];
                $crowdFundingActivityInfo = CrowdfundingPeriod::where(function ($query) use ($goods) {
                    foreach ($goods as $key => $value) {
                        ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_id']];
                        ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                        ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                    }
                    for ($i = 0; $i < count($goods); $i++) {
                        $allWhereOr[] = ${'where' . ($i + 1)};
                    }
                    $query->whereOr($allWhereOr);
                })->where(['status' => 1, 'buy_status' => 2])->select()->each(function($item){
                    //如果剩余销售额小于等于0则表示本期已经认购完成, 不允许继续超卖, 若还没满,则允许此订单超卖
                    $item['complete_buy'] = false;
                    if(doubleval($item['last_sales_price']) <= 0){
                        $item['complete_buy'] = true;
                    }
                })->toArray();
                if (empty($crowdFundingActivityInfo)) {
                    throw new CrowdFundingActivityException(['errorCode' => 2800103]);
                }

                foreach ($crowdFundingActivityInfo as $key => $value) {
                    $aKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    $crowdFundingActivityInfoArray[$aKey] = $value;
                }


                //判断当前期的参与门槛
                foreach ($crowdFundingActivityInfo as $key => $value) {
                    $aKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    if (!isset($value['join_condition_type'])) {
                        continue;
                    }
                    if ($value['join_condition_type'] != -1 && (doubleval($value['condition_price'] ?? 0) > 0)) {
                        $userOrderPrice = 0;
                        $jcMap[$aKey] = [];
                        $jcMap[$aKey][] = ['', 'exp', Db::raw("order_sn in (select order_sn from sp_order where uid = '" . $orderUid . "' and order_type = 6 and pay_status = 2)")];
                        $jcMap[$aKey][] = ['pay_status', '=', 2];
                        switch ($value['join_condition_type']) {
                            case 2:
                                $jcMap[$aKey][] = ['crowd_code', '=', $value['activity_code']];
                                $jcMap[$aKey][] = ['crowd_round_number', '=', $value['round_number']];
//                                $jcMap[$aKey][] = ['crowd_period_number', '=', $value['period_number']];
                                break;
                        }
                        if ($value['price_compute_time_type'] == 2) {
                            $jcMap[$aKey][] = ['create_time', '>=', $value['condition_price_start_time']];
                            $jcMap[$aKey][] = ['create_time', '<=', $value['condition_price_end_time']];
                        }
                        $jcMap[$aKey][] = ['status', '=', 1];
                        $userOrderPrice = OrderGoods::where($jcMap[$aKey])->field('sum(real_pay_price - refund_price) as total_price')->findOrEmpty()->toArray()['total_price'] ?? 0;
                        switch ($value['price_compute_type']) {
                            case 1:
                            case 3:
                                if ((string)$userOrderPrice < (string)$value['condition_price']) {
                                    throw new CrowdFundingActivityException(['msg' => '参与本期的门槛流水为 %s , 您暂不满足条件, 谢谢您的支持', 'vars' => [$value['condition_price']]]);
                                }
                                break;
                            case 2:
                                if ((string)$userOrderPrice > (string)$value['condition_price']) {
                                    throw new CrowdFundingActivityException(['msg' => '您不符合本期参与条件, 谢谢您的支持']);
                                }
                                break;
                        }
                    }
                }

                foreach ($list as $key => $value) {
                    foreach ($goods as $gKey => $gValue) {
                        if ($value['sku_sn'] == $gValue['sku_sn'] && $value['activity_id'] == $gValue['activity_id'] && $value['round_number'] == $gValue['round_number'] && $value['period_number'] == $gValue['period_number']) {
                            $aKey = $value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                            if (!isset($allSalesPrice[$aKey])) {
                                $allSalesPrice[$aKey] = 0;
                            }
                            $allSalesPrice[$aKey] += ($gValue['number'] * $value['sale_price']);
                        }
                    }
                }

                //如果当前期认购额度还没满, 允许此单超卖
                foreach ($crowdFundingActivityInfo as $key => $value) {
                    //如果有提前购, 需要判断是否超过设置的提前购额度, 如果超过了则不允许提前购
                    if (!empty($jumpTimeJudge ?? false) && (doubleval($value['advance_buy_scale'] ?? 0) < 1)) {
                        if ((($value['last_sales_price'] - $allSalesPrice[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']]) / $value['sales_price']) < (1-  ($value['advance_buy_scale'] ?? 0.6))) {
                            throw new CrowdFundingActivityException(['errorCode' => 2800105]);
                        }
                    }
                    if ($allSalesPrice[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']] > $value['last_sales_price'] && !empty($value['complete_buy'] ?? false)) {
                        throw new CrowdFundingActivityException(['errorCode' => 2800104]);
                    }
                }

                //判断当前活动开放时间段允许认购的销售总额比例
                foreach ($crowdFundingActivityInfo as $key => $value) {
                    if (!empty($activityPeriodDurationInfo[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']] ?? null)) {
                        foreach ($activityPeriodDurationInfo[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']] as $dKey => $dValue) {
                            if (time() >= $dValue['start_time'] && time() < $dValue['end_time']) {
                                if (!empty($dValue['target_sum_scale'] ?? null) && (doubleval($dValue['target_sum_scale']) > 0 && doubleval($dValue['target_sum_scale']) < 1)) {
                                    if ((1 - ((($value['last_sales_price'] - $allSalesPrice[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']]) / $value['sales_price']) ?? 0)) >= $dValue['target_sum_scale']) {
                                        throw new CrowdFundingActivityException(['errorCode' => 2800106]);
                                    }
                                }

                            }
                        }
                    }
                }

                //判断此单购买是否属于超前提前购,即参与时间小于期开始和结束时间, 则给此商品添加预计发放的分润时间
                //认购成功后多久判断为正式的成功,秒为单位
                $successPeriodNumber = CrowdfundingSystemConfig::where(['id' => 1])->value('success_period_number');
                foreach ($list as $key => $value) {
                    //如果属于提前购, 再判断是否属于超前提前购
                    if (!empty($value['advance_buy'] ?? false)) {
                        $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                        if (!empty($crowdFundingActivityInfoArray[$crowdKey] ?? [])) {
                            if (time() < $crowdFundingActivityInfoArray[$crowdKey]['start_time'] && time() < $crowdFundingActivityInfoArray[$crowdKey]['end_time'] && ($crowdFundingActivityInfoArray[$crowdKey]['advance_buy_scale'] ?? 0.6) >= 1) {
                                $list[$key]['presale'] = true;
                                $presaleCheck = true;
                                //如果设置的提前购发放奖励时间小于本期实际判断成功的时间
                                if (doubleval($crowdFundingActivityInfoArray[$crowdKey]['advance_buy_reward_send_time'] ?? 0) >= (intval(($crowdFundingActivityInfoArray[$crowdKey]['end_time'] - $crowdFundingActivityInfoArray[$crowdKey]['start_time']) * ($successPeriodNumber ?? 3)) + (3600 * 24))) {
                                    //直接返回间隔, 后续在正式下单的时候用下单时间添加此间隔算出奖励发放时间
                                    $list[$key]['advance_buy_reward_send_time'] = intval($crowdFundingActivityInfoArray[$crowdKey]['advance_buy_reward_send_time']);
                                }
                            }
                        }
                    }
                }

                //判断用户参与超前提前购的期数, 如果超过限制则不允许继续超前提前购
                if (!empty($presaleCheck ?? false) && !empty($orderUid ?? false)) {
                    $allCrowdActivityList = CrowdfundingActivity::where(['activity_code' => array_unique(array_column($list, 'activity_code'))])->field('activity_code,title,user_advance_limit,auto_create_advance_limit')->select()->toArray();
                    if (!empty($allCrowdActivityList)) {
                        foreach ($allCrowdActivityList as $key => $value) {
                            $allCrowdActivityInfo[$value['activity_code']] = $value;
                        }
                        //汇总查询用户超前提前购详情
                        $userAdvanceDelayOrderGroupCount = CrowdfundingDelayRewardOrder::where(['uid' => $orderUid, 'arrival_status' => 3, 'status' => 1])->field('uid,crowd_code,crowd_period_number,count(id) as number')->group('crowd_code,crowd_period_number')->select()->toArray();
                        if (!empty($userAdvanceDelayOrderGroupCount)) {
                            foreach ($userAdvanceDelayOrderGroupCount as $key => $value) {
                                if (!isset($userAdvanceCount[$value['crowd_code']])) {
                                    $userAdvanceCount[$value['crowd_code']] = 0;
                                }
                                //每个区总超前提前购次数, 同一期多次参与算一次
                                $userAdvanceCount[$value['crowd_code']] += 1;
                            }
                        }
                        //该用户全部区总超前提前购次数
                        $userAdvanceCountTotal = array_sum($userAdvanceCount ?? []);

                        foreach ($list as $key => $value) {
                            if (!empty($value['presale'] ?? false) && !empty($allCrowdActivityInfo[$value['activity_code']] ?? null)) {
                                if (doubleval($allCrowdActivityInfo[$value['activity_code']]['user_advance_limit'] ?? 0) > 0 && ((string)($userAdvanceCount[$value['activity_code']] ?? 0) >= (string)($allCrowdActivityInfo[$value['activity_code']]['user_advance_limit'] ?? 0))) {
                                    throw new CrowdFundingActivityException(['msg' => '您参与的提前购期数已达到上限, 请耐心等待前置活动完成后再参与, 感谢您的理解和支持']);
                                }
                            }
                        }

                        //汇总查询所有期的超前提前购期数
                        $advanceDelayOrderGroupCount = CrowdfundingDelayRewardOrder::where(['crowd_code' => array_unique(array_column($allCrowdActivityList, 'activity_code')), 'arrival_status' => 3, 'status' => 1])->field('crowd_code,crowd_round_number,crowd_period_number,count(id) as number')->group('crowd_code,crowd_round_number,crowd_period_number')->select()->toArray();

                        if (!empty($advanceDelayOrderGroupCount)) {
                            foreach ($advanceDelayOrderGroupCount as $key => $value) {
                                if (!isset($advanceCount[$value['crowd_code']])) {
                                    $advanceCount[$value['crowd_code']] = 0;
                                }
                                //每个区总超前提前购次数, 同一期多次参与算一次
                                $advanceCount[$value['crowd_code']] += 1;
                            }
                            foreach ($allCrowdActivityList as $key => $value) {
                                if (doubleval($value['auto_create_advance_limit'] ?? 0) > 0 && ((string)($advanceCount[$value['activity_code']] ?? 0) >= (string)($value['auto_create_advance_limit'] ?? 0))) {
                                    throw new CrowdFundingActivityException(['msg' => '当前区提前购期数已达到上限, 请耐心等待前置活动完成后再参与, 感谢您的理解和支持']);
                                }
                            }
                        }
                    }
                }

            }
        }

        return $list ?? [];
    }

    /**
     * @title  筛选Sku
     * @param  $data
     * @return array
     */
    public function filterSku($data)
    {
        $value = $data['value'];
        $count = $data['count'];
        $baseSkuCode = $data['baseSkuCode'];
        $allOnlineSku = $data['allOnlineSku'];
        $allSkuCount = $data['allSkuCount'];
        $entireSubjectSku = $data['entireSubjectSku'];
        //如果数组中有SKU则用之前的SKU
        if (!empty($value['sku_sn'])) {
            $skuSn = $value['sku_sn'];
        } elseif (!empty($allOnlineSku)) {
            //如还是没有SKU则根据原有的SKU基础码(8位)+现有的SKU数量加一得到顺序码(2位)
            if (empty($skuSn)) {
                $skuSn = substr(key($allOnlineSku), 0, 8) . sprintf("%02d", $allSkuCount + 1);
                $allSkuCount++;
            }
        }
        //如果此商品SPU原本就没有任何SKU,则新生成SKU基础码(8位)+当前的数组数字键名加一得到顺序码(2位)
        if (empty($skuSn)) {
            $skuSn = $baseSkuCode . sprintf("%02d", $count + 1);
        }

        return ['sku_sn' => $skuSn, 'allSkuCount' => $allSkuCount];
    }

    /**
     * @title  根据课程id修改对应的整课SKU属性和SPU的属性值
     * @param array $data
     * @return mixed
     */
    public function updateSkuBySubjectId(array $data)
    {
        $res = Db::transaction(function () use ($data) {
            $subjectId = $data['id'];
            $goodsSn = GoodsSpu::where(['link_product_id' => $subjectId, 'status' => [1, 2]])->value('goods_sn');
            $entireSubjectSku = false;
            if (!empty($goodsSn)) {
                $map[] = ['status', '=', 1];
                $map[] = ['goods_sn', '=', $goodsSn];
                $allGoodsSku = $this->where($map)->field('goods_sn,sku_sn,title,specs,content')->select()->toArray();
                foreach ($allGoodsSku as $key => $value) {
                    if (!is_numeric($value['content'])) {
                        $subjectSku = $value['sku_sn'];
                        $allGoodsSku[$key]['specs'] = json_encode(['全部课程' => $data['name']], JSON_UNESCAPED_UNICODE);
                        $allGoodsSku[$key]['attr'] = ['全部课程' => $data['name']];
                    } else {
                        $allGoodsSku[$key]['attr'] = json_decode($value['specs'], true);
                    }
                }

                //先修改整门课程的SKU属性值
                if (!empty($subjectSku)) {
                    $memberDis = (new MemberVdc())->where(['belong' => $this->belong, 'level' => 1, 'status' => 1])->value('discount');
                    $save['title'] = $data['name'];
                    $save['image'] = $data['cover_path'];
                    $save['market_price'] = $data['price'];
                    $save['sale_price'] = $data['price'];
                    $save['member_price'] = priceFormat($data['price'] * $memberDis);
                    $save['cost_price'] = $data['price'];
                    $save['content'] = $data['desc'];
                    $entireSubjectSku = $this->baseUpdate(['goods_sn' => $goodsSn, 'sku_sn' => $subjectSku], $save);
                }
                //再修改SPU的全部属性值
                if (!empty($allGoodsSku)) {
                    //将SKU属性值转化成json对象存储,并生成对应的SPU所有属性值
                    //sku属性值会生成如"{"subject":"class1","color":"red"}"
                    //spu全部属性值会生成如"{"subject":{"class1":["0001"],"class2":["0002"]},"color":{"red":["0001","0002"]}}"
                    $skuAttrSpecs = [];
                    $spuAttrSpecs = [];
                    foreach ($allGoodsSku as $key => $value) {
                        foreach ($value['attr'] as $attrKey => $attrValue) {
                            if (!isset($spuAttrSpecs[$attrKey])) {
                                $spuAttrSpecs[$attrKey] = [];
                            }
                            if (!in_array($attrValue, $spuAttrSpecs[$attrKey])) {
                                $spuAttrSpecs[$attrKey][$attrValue][] = $value['sku_sn'];
                            }
                            $skuAttrSpecs[$attrKey] = $attrValue;
                        }
                        $skuAttr = json_encode($skuAttrSpecs, JSON_UNESCAPED_UNICODE);
                    }
                    $spuAttr = json_encode($spuAttrSpecs, JSON_UNESCAPED_UNICODE);

                    if (!empty($spuAttr)) {
                        GoodsSpu::update(['attribute_list' => $spuAttr], ['goods_sn' => $goodsSn]);
                    }
                }

            }
            return $entireSubjectSku;
        });

        return $res;

    }

    /**
     * @title  删除SKU
     * @param array $data
     * @return mixed
     */
    public function del(array $data)
    {
        if (empty($data['goods_sn']) || empty($data['sku_sn'])) {
            throw new ParamException();
        }
        if (is_array($data['goods_sn']) && count($data['goods_sn']) > 1) {
            throw new ParamException(['msg' => '一次只允许删除同一个SPU下的SKU哦']);
        }
        $res = Db::transaction(function () use ($data) {
            $delSku = $this->baseDelete(['goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn']]);
            $delSkuVdc = (new GoodsSkuVdc())->baseDelete(['goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn']]);
            //删完最后一个SKU需要把商品库的SPU修改为下架状态
            $existSkuNumber = self::where(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]])->count();
            if (empty($existSkuNumber)) {
                (new GoodsSpu())->where(['goods_sn' => $data['goods_sn']])->save(['status' => 2]);
            }

            //删除活动商品SKU
            (new ActivityGoodsSku())->baseDelete(['goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn']]);
            $existActivitySkuNumber = ActivityGoodsSku::where(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]])->count();
            if (empty($existActivitySkuNumber)) {
                (new ActivityGoods())->baseDelete(['goods_sn' => $data['goods_sn']]);
            }
            cache('HomeApiActivityList', null);

            //删除拼团活动商品SKU
            (new PtGoodsSku())->baseDelete(['goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn']]);
            $existPtSkuNumber = PtGoodsSku::where(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]])->count();
            if (empty($existPtSkuNumber)) {
                (new PtGoods())->baseDelete(['goods_sn' => $data['goods_sn']]);
            }
            cache('ApiHomePtList', null);

            //删除拼拼活动活动商品SKU
            (new PpylGoodsSku())->baseDelete(['goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn']]);
            $existPtSkuNumber = PpylGoodsSku::where(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]])->count();
            if (empty($existPtSkuNumber)) {
                (new PpylGoods())->baseDelete(['goods_sn' => $data['goods_sn']]);
            }
            cache('ApiHomePpylList', null);

            cache('ApiHomeAllList', null);
            Cache::tag(['apiHomeGoodsList', 'HomeApiActivityList'])->clear();
            return $delSku;
        });
        return $res;
    }

    /**
     * @title  商品销售情况
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function goodsSkuSale(array $sear)
    {
        $goodsSn = $sear['goods_sn'];
        if (empty($goodsSn)) {
            return [];
        }
        $allGoodsSku = $this->with(['activitySku' => function ($query) {
            $query->field('activity_id,sku_sn,goods_sn,activity_price,sale_price,growth_value,vip_level,status,sale_number');
        }, 'activityGoods' => function ($query) {
            $query->field('activity_id,goods_sn,limit_type,start_time,end_time,status');
        }])->where(['goods_sn' => $goodsSn, 'status' => [1, 2]])->field('goods_sn,sku_sn,title,specs,stock,virtual_stock')
            ->withSum(['payOrder' => 'sell_number'], 'count')
            ->withSum(['payOrder' => 'sell_price'], 'real_pay_price')
//            ->withCount(['payOrder' => 'sell_order_number'])
            ->withSum(['waitPayOrder' => 'wait_pay_number'], 'count')
            ->withSum(['afterSaleOrder' => 'after_sale_number'], 'count')
            ->select()->each(function ($item) {
                $item['sell_order_number'] = 0;
                $item['sku_sell_order_number'] = 0;
                if (empty($item['sell_number'])) {
                    $item['sell_number'] = 0;
                }
                if (empty($item['sell_price'])) {
                    $item['sell_price'] = 0.00;
                }
                if (empty($item['wait_pay_number'])) {
                    $item['wait_pay_number'] = 0;
                }
                if (empty($item['after_sale_number'])) {
                    $item['after_sale_number'] = 0;
                }
                return $item;
            })->toArray();
        if (!empty($allGoodsSku)) {
            //获取所有活动id
            foreach ($allGoodsSku as $key => $value) {
                if (!empty($value['activitySku'])) {
                    $allActivityId[] = $value['activitySku']['activity_id'];
                }
            }
            if (!empty($allActivityId)) {
                $allActivityId = array_unique(array_filter($allActivityId));
                $allActivityList = Activity::where(['id' => $allActivityId])->column('title', 'id');
                if (!empty($allActivityList)) {
                    foreach ($allGoodsSku as $key => $value) {
                        if (!empty($value['activitySku'])) {
                            $allGoodsSku[$key]['activitySku']['activity_title'] = $allActivityList[$value['activitySku']['activity_id']] ?? null;
                        }
                    }
                }
            }
            //统计正确的订单数量
            $allGoodsSku = (new GoodsSpu())->realSalesInfoForSku(['list' => $allGoodsSku, 'sear' => $sear ?? []]);
        }
        return $allGoodsSku ?? [];
    }

    /**
     * @title  更新库存
     * @param array $sear
     * @return mixed
     */
    public function updateStock(array $sear)
    {
        $goods = $sear['goods'] ?? [];

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

        $skuInfo = $this->where(['goods_sn' => $goodsSn, 'sku_sn' => $skuSn])->column('stock', 'sku_sn');

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
            $DBRes = Db::transaction(function () use ($finally, $clearAllStock) {
                foreach ($finally as $key => $value) {
                    if ($value['type'] == 1) {
                        $res = $this->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->inc('stock', intval($value['number']))->update();
                    } elseif ($value['type'] == 2) {
                        if (empty($clearAllStock)) {
                            $res = $this->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->dec('stock', intval($value['number']))->update();
                        } else {
                            $res = $this->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->save(['stock' => 0]);
                        }
                    }
                    //清除C端商品详情缓存
                    Cache::tag([config('cache.systemCacheKey.apiGoodsInfo.key') . $value['goods_sn']])->clear();
                    Cache::tag(['apiWarmUpActivityInfo' . $value['goods_sn']])->clear();
                    Cache::tag(['apiWarmUpPtActivityInfo' . $value['goods_sn']])->clear();
                }
                return $res;
            });
        }
        return $DBRes;

    }

    /**
     * @title  商品SKU列表-订单筛选条件专属
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function listForOrderSear(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
//        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['goods_sn', '=', $sear['goods_sn']];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        //供应商只能筛选属于自己的商品
        if (!empty($sear['adminInfo'])) {
            $supplierCode = $sear['adminInfo']['supplier_code'] ?? null;
            if (!empty($supplierCode)) {
                $allSku = self::where(['supplier_code' => $supplierCode, 'status' => 1])->column('sku_sn');
                if (empty($allSku)) {
                    throw new ShipException(['msg' => '暂无属于您的供货商品']);
                }
                $map[] = ['sku_sn', 'in', $allSku];
            }
        }

        $list = $this->where($map)->field('goods_sn,sku_sn,title,sort,image,specs')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    public function spu()
    {
        return $this->hasOne('GoodsSpu', 'goods_sn', 'goods_sn')->withoutField('id,attribute_list,main_image,create_time,update_time')->where(['status' => [1, 2]]);
    }

    public function vdc()
    {
        return $this->hasMany('GoodsSkuVdc', 'sku_sn', 'sku_sn')->field('id,sku_sn,level,purchase_price,vdc_genre,vdc_type,belong,vdc_one,vdc_two')->where(['status' => [1, 2]]);
    }

    public function category()
    {
        return $this->hasOne('GoodsSpu', 'goods_sn', 'goods_sn')->where(['status' => [1, 2]])->bind(['category_code']);
    }

    public function shopSkuVdc()
    {
        return $this->hasMany('GoodsSkuVdc', 'sku_sn', 'sku_sn')->field('goods_sn,sku_sn,level,purchase_price,vdc_genre,vdc_type,vdc_one,vdc_two')->where(['status' => 1]);
    }

    public function payOrder()
    {
        return $this->hasMany('OrderGoods', 'sku_sn', 'sku_sn')->where(['pay_status' => 2, 'after_status' => [1, -1]]);
    }

    public function waitPayOrder()
    {
        return $this->hasMany('OrderGoods', 'sku_sn', 'sku_sn')->where(['pay_status' => 1, 'after_status' => [1]]);
    }

    public function afterSaleOrder()
    {
        return $this->hasMany('OrderGoods', 'sku_sn', 'sku_sn')->where(['pay_status' => 2, 'after_status' => [2, 3, 4]]);
    }

    public function activityGoods()
    {
        return $this->hasOne('ActivityGoods', 'goods_sn', 'goods_sn')->where(['status' => [1, 2]]);
    }

    public function activitySku()
    {
        return $this->hasOne('ActivityGoodsSku', 'sku_sn', 'sku_sn')->where(['status' => [1, 2]]);
    }
}