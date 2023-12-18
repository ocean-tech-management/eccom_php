<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use think\facade\Db;

class OrderGoods extends BaseModel
{
    private $belong = 1;

    /**
     * @title  热销排行榜
     * @param array $sear
     * @return array
     * @remark orderType 1为搜索订单(单个订单商品数量排序) 2为搜索课程(或商品SPU) 3为搜索章节
     * @throws \Exception
     */
    public function hotSaleList(array $sear)
    {
        $cacheKey = false;
        $cacheExpire = 0;
        $orderType = $sear['orderType'] ?? 2;
        switch ($orderType) {
            case 1:
                $groupField = 'a.order_sn';
                $field = 'a.order_sn,';
                break;
            case 2:
                $groupField = 'a.goods_sn';
                $field = 'a.goods_sn,';
                break;
            case 3:
                $groupField = 'a.sku_sn';
                $field = 'a.sku_sn,d.content as chapter_id,d.sale_price';
                break;
        }
        //排序字段
        switch ($sear['sortField'] ?? 2) {
            case 1:
                $sortField = 'sale_number';
                break;
            case 2:
                $sortField = 'sale_price';
                break;
        }
        //排序方式
        switch ($sear['sortType'] ?? 1) {
            case 1:
                $sortType = 'desc';
                break;
            case 2:
                $sortType = 'asc';
                break;
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
        }

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('c.title', $sear['keyword']))];
        }

        $map[] = ['a.status', '=', 1];
        //$map[] = ['b.order_belong','=',$sear['order_belong'] ?? $this->belong];
        $map[] = ['b.pay_status', '=', 2];

        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }

        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = Db::name('order_goods')->alias('a')
                ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
                ->join('sp_goods_spu c', 'a.goods_sn = c.goods_sn', 'left')
                ->join('sp_goods_sku d', 'a.sku_sn = d.sku_sn', 'left')
                ->where($map)
                ->group($groupField)
                ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                    $query->cache($cacheKey . 'Num', $cacheExpire);
                })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = Db::name('order_goods')->alias('a')
            ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
            ->join('sp_goods_spu c', 'a.goods_sn = c.goods_sn', 'left')
            ->join('sp_goods_sku d', 'a.sku_sn = d.sku_sn', 'left')
            ->where($map)
            ->field($field . 'sum(a.count) as sale_number,sum(a.real_pay_price) as sale_price,d.title,c.main_image,c.supplier_code')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->group($groupField)
            ->order($sortField . ' ' . $sortType)
            ->select()->toArray();
//        $list = Db::table($aList . ' a')->when($cacheKey,function($query) use ($cacheKey,$cacheExpire){
//                $query->cache($cacheKey,$cacheExpire);
//            })->order("'" . $sortField . ' ' . $sortType . "'")->select()->toArray();
        if (!empty($list)) {
            $supplierCodeList = array_unique(array_filter(array_column($list, 'supplier_code')));
            $supplierList = Supplier::where(['supplier_code' => $supplierCodeList])->column('name', 'supplier_code');
            foreach ($list as $key => $value) {
                $list[$key]['supplier_name'] = null;
                if (!empty($value['supplier_code']) && !empty($supplierList[$value['supplier_code']])) {
                    $list[$key]['supplier_name'] = $supplierList[$value['supplier_code']];
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  商城热销排行榜
     * @param array $sear
     * @return array
     * @remark orderType 1为搜索订单(单个订单商品数量排序) 2为搜索商品SPU 3为搜索SKU
     * @throws \Exception
     */
    public function ShopHotSaleList(array $sear)
    {
        $cacheKey = false;
        $cacheExpire = 0;
        $orderType = $sear['orderType'] ?? 2;
        switch ($orderType) {
            case 1:
                $groupField = 'a.order_sn';
                $field = 'a.order_sn,';
                break;
            case 2:
                $groupField = 'a.goods_sn';
                $field = 'a.goods_sn,';
                break;
            case 3:
                $groupField = 'a.sku_sn';
                $field = 'a.sku_sn,d.content as chapter_id,d.sale_price';
                break;
        }

        //仅查上架的产品
        $map[] = ['a.status', '=', 1];
        $map[] = ['c.status', '=', 1];
        $map[] = ['d.status', '=', 1];
        $map[] = ['c.show_status', 'in', [1, 2]];

        $map[] = ['b.order_belong', '=', $sear['order_belong'] ?? $this->belong];
        $map[] = ['b.pay_status', '=', 2];

        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }

        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = Db::name('order_goods')->alias('a')
                ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
                ->join('sp_goods_spu c', 'a.goods_sn = c.goods_sn', 'left')
                ->join('sp_goods_sku d', 'a.sku_sn = d.sku_sn', 'left')
                ->where($map)
                ->group($groupField)
                ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                    $query->cache($cacheKey . 'Num', $cacheExpire);
                })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = Db::name('order_goods')->alias('a')
            ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
            ->join('sp_goods_spu c', 'a.goods_sn = c.goods_sn', 'left')
            ->join('sp_goods_sku d', 'a.sku_sn = d.sku_sn', 'left')
            ->where($map)
            ->field($field . 'sum(a.count) as sale_number,d.sku_sn,d.image as main_image,d.title,d.sale_price,d.member_price,d.market_price')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey, $cacheExpire);
            })
            ->group($groupField)
            ->order('sale_number desc')
            ->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户已购订单
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function userBuyGoods(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.title', $sear['keyword']))];
        }

        $map[] = ['b.uid', '=', $sear['uid']];
        $map[] = ['a.status', '=', 1];
        $map[] = ['b.pay_status', '=', 2];
        $map[] = ['b.order_belong', '=', $this->belong];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = Db::name('order_goods')->alias('a')
                ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
                ->join('sp_goods_spu c', 'a.goods_sn = c.goods_sn', 'left')
                ->join('sp_goods_sku d', 'a.goods_sn = d.goods_sn and d.status = 1', 'left')
                ->where($map)
                ->group('b.order_sn')->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $aList = Db::name('order_goods')->alias('a')
            ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
            ->join('sp_goods_spu c', 'a.goods_sn = c.goods_sn', 'left')
            ->join('sp_goods_sku d', 'a.goods_sn = d.goods_sn and d.status = 1', 'left')
            ->field('a.goods_sn,a.order_sn,a.count,a.price,a.total_price,a.title as name,a.images,b.uid,b.pay_status,b.create_time,c.main_image as cover_path,c.link_product_id as subject_id,count(distinct a.sku_sn) as chapter_number,GROUP_CONCAT(distinct d.`sku_sn`,":",d.`sort`) as all_sku,GROUP_CONCAT(distinct a.sku_sn) as order_sku')
            ->group('b.order_sn')
            ->where($map)
            ->buildSql();

        $list = Db::table($aList . ' a')
            ->join('sp_subject_progress b', 'a.subject_id = b.subject_id and a .uid = b.uid and b.status IN (1,2)', 'left')
            ->field('a.*,sum(b.progress) as subject_progress')
            ->group('a.order_sn')
            ->order('a.create_time desc')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->select()->each(function ($item, $key) {
                //判断如果购买的SKU包含整套课程的SKU,则购买的chapter_number章节数量为全部章节数量
                $maxSort = (explode(',', $item['all_sku']));
                foreach ($maxSort as $k => $v) {
                    $behind = substr($v, strripos($v, ":") + 1);
                    $front = substr($v, 0, strrpos($v, ":"));
                    $sort[$front] = $behind;
                }
                $maxSortNum = 999;
                if (intval(max($sort)) == $maxSortNum) {
                    $maxSortSku = array_search(max($sort), $sort);
                    if (in_array($maxSortSku, explode(',', $item['order_sku']))) {
                        $item['chapter_number'] = count($sort) - 1;
                    };
                }
                $item['subject_progress2'] = $item['subject_progress'];
                if (!empty($item['subject_progress'])) {
                    $item['subject_progress'] = (priceFormat($item['subject_progress'] / ($item['chapter_number'] * 100)) * 100);
                    if ($item['subject_progress'] >= 100) {
                        $item['subject_progress'] = 100;
                    }
                    $item['subject_progress'] .= "%";
                } else {
                    $item['subject_progress'] = "0%";
                }
                unset($item['all_sku']);
                unset($item['order_sku']);
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  SPU商品销售情况列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function spuSaleList(array $sear)
    {
        $list = [];
        $map = [];
        $searSpuGoods = false;

        $cacheKey = md5(json_encode($sear, 256));
        if (!empty($sear['onlyNeedAllSummary'])) {
            $cacheKey .= 'onlyAllSummary';
        }
        $cacheExpire = 3600;
        if (empty($sear['clearCache'])) {
            if (!empty(cache($cacheKey))) {
                return cache($cacheKey);
            }
        }

        //区分默认商品的筛选 searGoodsType=1筛选最新销售情况的商品 (默认) =2筛选SPU表的商品
        if (!empty($sear['searGoodsType']) && $sear['searGoodsType'] == 2) {
            $searSpuGoods = true;
        }

        if (!empty($sear['keyword'])) {
            if (!empty($searSpuGoods)) {
                $keyWordField = 'goods_code|title';
            } else {
                $keyWordField = 'title|order_sn|supplier_pay_no';
            }
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql($keyWordField, trim($sear['keyword'])))];
        }

        if (empty($searSpuGoods) && !empty($sear['supplier_pay_status'])) {
            $map[] = ['supplier_pay_status', '=', $sear['supplier_pay_status']];
        }

        if (empty($searSpuGoods) && !empty($sear['shipping_status'])) {
            $map[] = ['shipping_status', '=', $sear['shipping_status']];
        }

        if (empty($searSpuGoods) && !empty($sear['after_status'])) {
            $map[] = ['after_status', '=', $sear['after_status']];
        }

        //按照搜索商品顺序搜的时候剔除对于时间的判断,后续查询订单的时候再加上
        if (empty($searSpuGoods) && !empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        if (!empty($sear['supplier_code'])) {
            $supplierGoodsSn = GoodsSpu::where(['supplier_code' => trim($sear['supplier_code'])])->column('goods_sn');
            if (empty($supplierGoodsSn)) {
                throw new ServiceException(['msg' => '该供应商暂无有效商品~']);
            }
        }

        //发货时间
        if (!empty($sear['delivery_start_time']) && !empty($sear['delivery_end_time'])) {
            $map[] = ['delivery_time', 'between', [strtotime($sear['delivery_start_time']), strtotime($sear['delivery_end_time'])]];
        }

        if (!empty($sear['goods_sn'])) {
            if (is_array($sear['goods_sn'])) {
                $searGoodsSn = $sear['goods_sn'];
            } else {
                $searGoodsSn = [trim($sear['goods_sn'])];
            }
        }

        if (!empty($supplierGoodsSn) && !empty($searGoodsSn)) {
            $allSearGoodsSn = array_unique(array_filter(array_merge_recursive($supplierGoodsSn, $searGoodsSn)));
        } elseif (!empty($supplierGoodsSn) && empty($searGoodsSn)) {
            $allSearGoodsSn = $supplierGoodsSn;
        } elseif (empty($supplierGoodsSn) && !empty($searGoodsSn)) {
            $allSearGoodsSn = $searGoodsSn;
        }

        if (!empty($allSearGoodsSn)) {
            $allSearGoodsSn = array_unique(array_filter($allSearGoodsSn));
            $map[] = ['goods_sn', 'in', $allSearGoodsSn];
        }

        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval(trim($sear['pageNumber']));
        }
        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($searSpuGoods)) {
            $model = (new GoodsSpu());
        } else {
            $model = $this;
        }
        if (!empty($page)) {
            $aTotal = $model->where($map)->group('goods_sn')->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        if (empty($page)) {
            $goodsSn = $model->where($map)->group('goods_sn')->order('create_time desc')->column('goods_sn');
        } else {
            $goodsSnSql = $model->where($map)->field('goods_sn')->group('goods_sn')->order('create_time desc')->buildSql();
            $goodsSn = Db::table($goodsSnSql . ' a')->page($page, $this->pageNumber)->column('goods_sn');
        }

        if (!empty($searSpuGoods) && (!empty($sear['start_time']) && !empty($sear['end_time']))) {
            $map[] = ['create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        if (!empty($goodsSn)) {
            $map[] = ['goods_sn', 'in', $goodsSn];
        }

        $list = self::where($map)->field('order_sn,goods_sn,title,SUM( IF ( pay_status = 2 and after_status in (1,-1,5), 1, 0 ) ) AS sell_order_number,SUM( IF ( pay_status = 2 and after_status in (1,-1,5), real_pay_price, 0 ) ) AS sell_price,SUM( IF ( pay_status = 2 and after_status in (1,-1,5), count, 0 ) ) AS sku_sell_order_number,SUM( IF ( pay_status = 2 and after_status in (1,-1,5), 1, 0 ) ) AS sell_number,sum(real_pay_price) as all_real_pay_price,sum(1) as all_sell_number,SUM( IF ( pay_status = 2 and after_status in (2,3), 1, 0 ) ) AS all_order_after_sale_ing_number,SUM( IF ( pay_status = 2 and refund_price <> 0 and after_status in (4), 1, 0 ) ) AS all_order_after_sale_number,SUM( IF ( pay_status = 2 and refund_price <> 0 and after_status in (4), 1, 0 ) ) AS all_order_refund_number,SUM( IF ( pay_status = 2 and refund_price <> 0 and after_status in (4), refund_price, 0 ) ) AS all_order_refund_price,SUM( IF ( supplier_pay_status = 1, 1, 0 ) ) AS all_supplier_pay_number,SUM( IF ( pay_status = 2, total_price, 0 ) ) AS total_price,SUM( IF ( pay_status = 2, 1, 0 ) ) AS total_sell_order_number,SUM( IF ( pay_status = 2, count, 0 ) ) AS total_sku_sell_order_number')->group('goods_sn')->order('create_time desc')->select()->each(function ($item) {
            $item['all_after_sale_ing_price'] = '0.00';
            $item['goodsInfo'] = [];
            $item['skuInfo'] = [];
            $item['skuSnList'] = [];
        })->toArray();

        //重新统计正确的订单数
        $rMap = $map;
        $rMap[] = ['pay_status', '=', 2];
        $realOrders = self::where($rMap)->field('order_sn,goods_sn,after_status,pay_status,status')->order('after_status asc')->select()->toArray();

        foreach ($realOrders as $key => $value) {
            $realOrder[$value['goods_sn']][$value['order_sn']] = $value;
            if (in_array($value['after_status'], [1, -1])) {
                $normalOrder[] = $value['order_sn'];
            }
            //同个订单同个SPU,如果有一个存在正常状态的订单商品信息,此笔算订单为正常订单
            if (in_array($value['after_status'], [4])) {
                if (!empty($normalOrder) && in_array($value['order_sn'], array_unique($normalOrder))) {
                    continue;
                }
                $realAfterOrder[$value['goods_sn']][$value['order_sn']] = $value;
                $realRefundOrder[$value['goods_sn']][$value['order_sn']] = $value;
            }
            //售后中的订单,需要剔除正常订单的数量
            if (in_array($value['after_status'], [2, 3])) {
//                if(in_array($value['order_sn'],array_unique($normalOrder))){
//                    continue;
//                }
                unset($realOrder[$value['goods_sn']][$value['order_sn']]);
                $realAfterIngOrder[$value['goods_sn']][$value['order_sn']] = $value;
            }
        }

        foreach ($list as $key => $value) {
            $list[$key]['total_sell_order_number'] = count($realOrder[$value['goods_sn']] ?? []);
            $list[$key]['all_order_after_sale_number'] = count($realAfterOrder[$value['goods_sn']] ?? []);
            $list[$key]['all_order_after_sale_ing_number'] = count($realAfterIngOrder[$value['goods_sn']] ?? []);
            $list[$key]['all_order_refund_number'] = count($realRefundOrder[$value['goods_sn']] ?? []);
        }

        //如果是按照商品搜索需要补齐部分没有销售的商品的数据
        if (!empty($searSpuGoods)) {
            $goodsList = [];
            if (!empty($list)) {
                foreach ($goodsSn as $key => $value) {
                    foreach ($list as $lKey => $lValue) {
                        if ($value == $lValue['goods_sn']) {
                            $goodsList[$key] = $lValue;
                        }
                    }
                }
            }

            foreach ($goodsSn as $key => $value) {
                if (empty($goodsList[$key])) {
                    $goodsList[$key]['goods_sn'] = $value;
                    $goodsList[$key]['sell_order_number'] = 0;
                    $goodsList[$key]['sell_price'] = '0.00';
                    $goodsList[$key]['sku_sell_order_number'] = 0;
                    $goodsList[$key]['sell_number'] = 0;
                    $goodsList[$key]['all_real_pay_price'] = '0.00';
                    $goodsList[$key]['all_sell_number'] = 0;
                    $goodsList[$key]['all_order_after_sale_ing_number'] = 0;
                    $goodsList[$key]['all_order_after_sale_number'] = 0;
                    $goodsList[$key]['all_order_refund_number'] = 0;
                    $goodsList[$key]['all_order_refund_price'] = '0.00';
                    $goodsList[$key]['all_supplier_pay_number'] = 0;
                    $goodsList[$key]['total_price'] = '0.00';
                    $goodsList[$key]['total_sell_order_number'] = 0;
                    $goodsList[$key]['total_sku_sell_order_number'] = 0;
                    $goodsList[$key]['all_after_sale_ing_price'] = '0.00';
                    $goodsList[$key]['goodsInfo'] = [];
                    $goodsList[$key]['skuInfo'] = [];
                    $goodsList[$key]['skuSnList'] = [];
                }
            }

            ksort($goodsList);
            $list = $goodsList;
        }

        if (empty($list)) {
            if (!empty($sear['onlyNeedAllSummary'])) {
                return [];
            } else {
                return ['list' => [], 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
            }
        }

        $allGoodsSn = array_unique(array_column($list, 'goods_sn'));

        //补齐商品信息
        $goodsSpuInfo = GoodsSpu::with(['supplier' => function ($query) {
            $query->field('supplier_code,name,concat_user,concat_phone,address');
        }, 'category'])->where(['goods_sn' => $allGoodsSn])->field('goods_sn,title,supplier_code,category_code')->select()->toArray();
        if (!empty($goodsSpuInfo)) {
            $aCategory = array_column($goodsSpuInfo, 'category_code');
            $pCategory = Category::with(['parent'])->where(['code' => $aCategory, 'status' => 1])->select()->toArray();
            if (!empty($pCategory)) {
                foreach ($goodsSpuInfo as $key => $value) {
                    foreach ($pCategory as $pcKey => $pcValue) {
                        if ($value['category_code'] == $pcValue['code']) {
                            if (!empty($pcValue['parent'])) {
                                $goodsSpuInfo[$key]['p_category_code'] = $pcValue['parent']['code'];
                                $goodsSpuInfo[$key]['p_category_name'] = $pcValue['parent']['name'];
                            }
                        }
                    }
                }
            }
            foreach ($goodsSpuInfo as $key => $value) {
                $goodsSpuInfos[$value['goods_sn']] = $value;
            }
            if (!empty($goodsSpuInfos)) {
                foreach ($list as $key => $value) {
                    if (isset($goodsSpuInfos[$value['goods_sn']]) && !empty($goodsSpuInfos[$value['goods_sn']])) {
                        if (empty($value['title'])) {
                            $list[$key]['title'] = $goodsSpuInfos[$value['goods_sn']]['title'];
                        }
                        $list[$key]['goodsInfo'] = $goodsSpuInfos[$value['goods_sn']];
                    }
                }
            }
        }

        //减少未成团的拼团商品销售数量
        $ptGoods = PtOrder::alias('a')->join('sp_order_goods b', 'a.order_sn = b.order_sn and a.goods_sn = b.goods_sn and a.sku_sn = b.sku_sn')->where(['a.goods_sn' => $allGoodsSn])->where(['a.pay_status' => 2, 'a.activity_status' => 1, 'a.status' => 1])->field('a.order_sn,a.goods_sn,a.sku_sn,a.activity_status,a.real_pay_price,b.total_price')->select()->toArray();
        $ptNumberCount = [];
        $ptOrderCount = [];
        $ptGoodsPrice = [];
        $ptGoodsTotalPrice = [];
        if (!empty($ptGoods)) {
            foreach ($ptGoods as $key => $value) {
                if (!isset($ptNumberCount[$value['goods_sn']])) {
                    $ptNumberCount[$value['goods_sn']] = 0;
                }
                if (!isset($ptGoodsPrice[$value['goods_sn']])) {
                    $ptGoodsPrice[$value['goods_sn']] = 0;
                }
                if (!isset($ptGoodsTotalPrice[$value['goods_sn']])) {
                    $ptGoodsTotalPrice[$value['goods_sn']] = 0;
                }
                $ptOrderCount[$value['goods_sn']][] = $value['order_sn'];
                $ptNumberCount[$value['goods_sn']] += 1;
                $ptGoodsPrice[$value['goods_sn']] += $value['real_pay_price'];
                $ptGoodsTotalPrice[$value['goods_sn']] += $value['total_price'] ?? 0;
            }
            if (!empty($ptOrderCount)) {
                foreach ($list as $key => $value) {
                    if (!empty($ptOrderCount[$value['goods_sn']])) {
                        $list[$key]['sell_order_number'] -= count(array_unique(array_filter($ptOrderCount[$value['goods_sn']])));
                        $list[$key]['sell_number'] -= count(array_unique(array_filter($ptOrderCount[$value['goods_sn']])));
                        if (!empty($sear['needAllSkuOrderNumber'] ?? null)) {
                            $list[$key]['sku_sell_order_number'] -= count(array_filter($ptOrderCount[$value['goods_sn']]));
                        } else {
                            $list[$key]['sku_sell_order_number'] = $list[$key]['sell_order_number'];
                        }
                        if (!empty($ptGoodsPrice[$value['goods_sn']])) {
                            $list[$key]['sell_price'] -= $ptGoodsPrice[$value['goods_sn']];
                        }
                        if (!empty($ptGoodsTotalPrice[$value['goods_sn']])) {
                            $list[$key]['total_price'] -= $ptGoodsTotalPrice[$value['goods_sn']];
                        }
                    }
                }
            }
        }

        //恢复换货申请的订单及商品销售数量
        $afMap[] = ['type', '=', 3];
        $afMap[] = ['after_status', 'not in', [3, -1, -2, -3]];
        $changeGoods = AfterSale::where(['goods_sn' => $allGoodsSn])->where($afMap)->field('order_sn,goods_sn,sku_sn')->select()->toArray();
        $changeNumberCount = [];
        $changeOrderCount = [];
        $changePrice = [];
        $changeTotalPrice = [];
        if (!empty($changeGoods)) {
            $changeGoodsSn = array_unique(array_column($changeGoods, 'goods_sn'));
            $changeGoodsSkuSn = array_unique(array_column($changeGoods, 'sku_sn'));
            $changeOrderSn = array_unique(array_column($changeGoods, 'order_sn'));
            $goodsInfo = OrderGoods::where(['order_sn' => $changeOrderSn, 'goods_sn' => $changeGoodsSn, 'sku_sn' => $changeGoodsSkuSn])->field('order_sn,count,goods_sn,sku_sn,real_pay_price,total_price')->select()->toArray();
            if (!empty($goodsInfo)) {
                foreach ($goodsInfo as $key => $value) {
                    $goodsCountInfo[$value['order_sn']][$value['sku_sn']] = $value['count'];
                    $goodsPriceInfo[$value['order_sn']][$value['sku_sn']] = $value['real_pay_price'];
//                    $goodsToTalPriceInfo[$value['order_sn']][$value['sku_sn']] = $value['total_price'];
                }
            }
            foreach ($changeGoods as $key => $value) {
                if (!isset($changeNumberCount[$value['goods_sn']])) {
                    $changeNumberCount[$value['goods_sn']] = 0;
                }
                if (!isset($changePrice[$value['goods_sn']])) {
                    $changePrice[$value['goods_sn']] = 0;
                }
//                if (!isset($changeTotalPrice[$value['goods_sn']])) {
//                    $changeTotalPrice[$value['goods_sn']] = 0;
//                }
                $changeOrderCount[$value['goods_sn']][] = $value['order_sn'];
                if (!empty($goodsCountInfo[$value['order_sn']])) {
                    $changeNumberCount[$value['goods_sn']] += $goodsCountInfo[$value['order_sn']][$value['sku_sn']] ?? 0;
                }
                if (!empty($goodsPriceInfo[$value['order_sn']])) {
                    $changePrice[$value['goods_sn']] += $goodsPriceInfo[$value['order_sn']][$value['sku_sn']] ?? 0;
                }
//                if (!empty($goodsToTalPriceInfo[$value['order_sn']])) {
//                    $changeTotalPrice[$value['goods_sn']] += $goodsToTalPriceInfo[$value['order_sn']][$value['sku_sn']] ?? 0;
//                }

            }

            if (!empty($changeOrderCount)) {
                foreach ($list as $key => $value) {
                    if (!empty($changeOrderCount[$value['goods_sn']])) {
                        $list[$key]['sell_order_number'] += count(array_unique(array_filter($changeOrderCount[$value['goods_sn']])));
                        if (!empty($changeNumberCount[$value['goods_sn']])) {
                            $list[$key]['sell_number'] += $changeNumberCount[$value['goods_sn']];
                            if (!empty($list[$key]['after_sale_number'])) {
                                $list[$key]['after_sale_number'] -= $changeNumberCount[$value['goods_sn']];
                            }
                        }
                        if (!empty($changePrice[$value['goods_sn']])) {
                            $list[$key]['sell_price'] += $changePrice[$value['goods_sn']];
                        }
                        if (!empty($changeTotalPrice[$value['goods_sn']])) {
                            $list[$key]['total_price'] += $changeTotalPrice[$value['goods_sn']];
                        }
                        if (!empty($sear['needAllSkuOrderNumber'] ?? null)) {
                            $list[$key]['sku_sell_order_number'] += count(array_filter($changeOrderCount[$value['goods_sn']]));
                        } else {
                            $list[$key]['sku_sell_order_number'] = $list[$key]['sell_order_number'];
                        }
                    }
                }
            }
        }

        foreach ($list as $key => $value) {
            $list[$key]['sell_price'] = priceFormat($value['sell_price']);
        }

        //查询补齐退售后中的订单以用于显示
        $aMap[] = $map;
        $aMap[] = ['pay_status', '=', 2];
        $aMap[] = ['after_status', 'in', [2, 3]];
        $afterGoodsSql = self::where($aMap)->buildSql();
        $afterGoods = Db::table($afterGoodsSql . ' a')->join('sp_after_sale b', 'a.order_sn = b.order_sn and a.goods_sn = b.goods_sn')->field('SUM(IF(b.after_status in (1,2,4,5,6,7) and type in (1,2),b.apply_price,0)) as all_apply_price,a.goods_sn')->group('a.goods_sn')->select()->toArray();
        if (!empty($afterGoods)) {
            $allAfterGoods = [];
            foreach ($afterGoods as $key => $value) {
                $allAfterGoods[$value['goods_sn']] = $value['all_apply_price'];
            }

            if (!empty($allAfterGoods)) {
                foreach ($list as $key => $value) {
                    if (isset($allAfterGoods[$value['goods_sn']]) && !empty($allAfterGoods[$value['goods_sn']])) {
                        $list[$key]['all_after_sale_ing_price'] = priceFormat($allAfterGoods[$value['goods_sn']]);
                    }
                }
            }
        }

        //补齐SPU对应的数据以便后续展示和前端开发使用
        $goodsSku = GoodsSku::where(['goods_sn' => $allGoodsSn])->field('goods_sn,sku_sn,title,specs,image,sub_title')->select()->toArray();
        if (!empty($goodsSku)) {
            foreach ($goodsSku as $key => $value) {
                $goodsSkus[$value['goods_sn']][] = $value;
            }
            if (!empty($goodsSkus)) {
                foreach ($list as $key => $value) {
                    if (isset($goodsSkus[$value['goods_sn']]) && !empty($goodsSkus[$value['goods_sn']])) {
                        $list[$key]['skuInfo'] = $goodsSkus[$value['goods_sn']];
                        $list[$key]['skuSnList'] = array_unique(array_filter(array_column($goodsSkus[$value['goods_sn']], 'sku_sn')));
                    }
                }
            }
        }
        $allSellOrderNumber = 0;
        $allRealOrderNumber = 0;
        $allSellPrice = 0;
        $allRealPrice = 0;
        $allSkuSellOrderNumber = 0;
        $allRealSkuSellOrderNumber = 0;
        $allRealSellNumber = 0;
        $allOrderAfterSaleIngNumber = 0;
        $allOrderAfterSaleNumber = 0;
        $allOrderRefundNumber = 0;
        $allOrderRefundPrice = 0;
        $allSupplierPayNumber = 0;
        $allAfterSaleIngPrice = 0;
        $allSellNumber = 0;
        //汇总全部信息
        foreach ($list as $key => $value) {
            $allSellOrderNumber += ($value['total_sell_order_number'] ?? 0);
            $allRealOrderNumber += ($value['sell_order_number'] ?? 0);
            $allSellPrice += ($value['total_price'] ?? 0);
            $allRealPrice += ($value['sell_price'] ?? 0);
            $allSkuSellOrderNumber += ($value['total_sku_sell_order_number'] ?? 0);
            $allRealSkuSellOrderNumber += ($value['sku_sell_order_number'] ?? 0);
            $allRealSellNumber += ($value['sell_number'] ?? 0);
            $allOrderAfterSaleIngNumber += ($value['all_order_after_sale_ing_number'] ?? 0);
            $allOrderAfterSaleNumber += ($value['all_order_after_sale_number'] ?? 0);
            $allOrderRefundNumber += ($value['all_order_refund_number'] ?? 0);
            $allOrderRefundPrice += ($value['all_order_refund_price'] ?? 0);
            $allSupplierPayNumber += ($value['all_supplier_pay_number'] ?? 0);
            $allAfterSaleIngPrice += ($value['all_after_sale_ing_price'] ?? 0);
            $allSellNumber += ($value['all_sell_number'] ?? 0);
        }

        $summary = [
            'allSellOrderNumber' => intval($allSellOrderNumber),
            'allRealOrderNumber' => intval($allRealOrderNumber),
            'allSellPrice' => priceFormat($allSellPrice),
            'allRealPrice' => priceFormat($allRealPrice),
            'allSkuSellOrderNumber' => intval($allSkuSellOrderNumber),
            'allRealSkuSellOrderNumber' => intval($allRealSkuSellOrderNumber),
            'allRealSellNumber' => intval($allRealSellNumber),
            'allSellNumber' => intval($allSellNumber),
            'allOrderAfterSaleIngNumber' => intval($allOrderAfterSaleIngNumber),
            'allOrderAfterSaleNumber' => intval($allOrderAfterSaleNumber),
            'allOrderRefundNumber' => intval($allOrderRefundNumber),
            'allOrderRefundPrice' => priceFormat($allOrderRefundPrice),
            'allSupplierPayNumber' => intval($allSupplierPayNumber),
            'allAfterSaleIngPrice' => priceFormat($allAfterSaleIngPrice),
        ];

        $returnDataList = returnData($list ?? [], $pageTotal ?? 0, 0, '成功', $aTotal ?? 0)->getData()['data'] ?? [];

        //如果只是汇总就只返回汇总数据
        if (!empty($sear['onlyNeedAllSummary'])) {
            cache($cacheKey, $summary, $cacheExpire);
            return $summary;
        } else {
            $finally = $returnDataList;
            if (!empty($page ?? null)) {
                $finally['summary'] = $summary ?? [];
            }
            cache($cacheKey, $finally, $cacheExpire);
        }

        return $finally ?? [];
    }


    /**
     * @title  SKU商品销售情况列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function skuSaleList(array $sear)
    {
        $list = [];
        $map = [];
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.title|a.order_sn|a.supplier_pay_no', trim($sear['keyword'])))];
        }

        if (!empty($sear['supplier_pay_status'])) {
            $map[] = ['a.supplier_pay_status', '=', $sear['supplier_pay_status']];
        }

        if (!empty($sear['shipping_status'])) {
            $map[] = ['a.shipping_status', '=', $sear['shipping_status']];
        }

        if (!empty($sear['after_status'])) {
            $map[] = ['a.after_status', '=', $sear['after_status']];
        }

        if (!empty($sear['pay_status'])) {
            $map[] = ['a.pay_status', 'in', $sear['pay_status']];
        }

        if (!empty($sear['not_pay_status'])) {
            $map[] = ['a.pay_status', 'not in', $sear['not_pay_status']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }
        //发货时间
        if (!empty($sear['delivery_start_time']) && !empty($sear['delivery_end_time'])) {
            $map[] = ['a.delivery_time', 'between', [strtotime($sear['delivery_start_time']), strtotime($sear['delivery_end_time'])]];
        }

        if (!empty($sear['supplier_code'])) {
            $supplierSkuSn = GoodsSku::where(['supplier_code' => trim($sear['supplier_code'])])->column('sku_sn');
            if (empty($supplierSkuSn)) {
                throw new ServiceException(['msg' => '该供应商暂无有效商品~']);
            }
        }

        if (!empty($sear['goods_sku'])) {
            if (is_array($sear['goods_sku'])) {
                $searSkuSn = $sear['goods_sku'];
            } else {
                $searSkuSn = [trim($sear['goods_sku'])];
            }
        }

        if (!empty($supplierSkuSn) && !empty($searSkuSn)) {
            $skuSn = array_unique(array_filter(array_merge_recursive($supplierSkuSn, $searSkuSn)));
        } elseif (!empty($supplierSkuSn) && empty($searSkuSn)) {
            $skuSn = $supplierSkuSn;
        } elseif (empty($supplierSkuSn) && !empty($searSkuSn)) {
            $skuSn = $searSkuSn;
        }
        if (!empty($skuSn)) {
            $map[] = ['a.sku_sn', 'in', $skuSn];
        }

        //只查找已发货的订单
//        $map[] = ['a.shipping_status', 'in', [3]];

        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval(trim($sear['pageNumber']));
        }
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->alias('a')->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        if (!empty($sear['onlyNeedId'])) {
            $field = 'a.id';
        } else {
            $aField = 'a.id,a.order_sn,a.order_type,a.goods_sn,a.sku_sn,a.count,a.price,a.sale_price,a.total_price,a.title,a.images,a.specs,a.total_fare_price,a.member_dis,a.coupon_dis,a.integral_dis,a.all_dis,a.real_pay_price,a.refund_price,a.cost_price,a.user_level,a.goods_level_vdc,a.coupon_divide,a.vdc_allow,a.team_code,a.team_child_code,a.pay_status,a.status,a.after_status,a.shipping_status,a.create_time,a.supplier_code,a.supplier_pay_status,a.supplier_pay_no,a.supplier_pay_remark,a.supplier_pay_time,a.withdraw_time,a.refund_arrive_time,a.supplier_refund_after_pay_no,a.supplier_refund_after_pay_remark,a.supplier_refund_after_pay_price,a.supplier_refund_after_pay_time,a.delivery_time';
            $field = $aField . ',b.create_time as after_sale_create_time,c.name as supplier_name,d.cost_price as now_cost_price,(a.cost_price * a.count) as total_cost_price,b.apply_price as after_sale_ing_price';
        }

        $list = self::alias('a')
            ->join('sp_after_sale b', 'a.order_sn = b.order_sn and a.goods_sn = b.goods_sn and a.sku_sn = b.sku_sn and b.after_status not in (-1,-2,-3,3)', 'left')
            ->join('sp_goods_sku d', 'a.sku_sn = d.sku_sn', 'left')
            ->join('sp_supplier c', 'a.supplier_code = c.supplier_code COLLATE utf8mb4_unicode_ci ', 'left')
            ->where($map)->field($field)
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.create_time desc')->select()->each(function ($item) {
                if (!empty($item['after_sale_create_time'])) {
                    $item['after_sale_create_time'] = timeToDateFormat($item['after_sale_create_time']);
                }
                if (!empty($item['supplier_pay_time'])) {
                    $item['supplier_pay_time'] = timeToDateFormat($item['supplier_pay_time']);
                }
                if (!empty($item['supplier_refund_after_pay_time'])) {
                    $item['supplier_refund_after_pay_time'] = timeToDateFormat($item['supplier_refund_after_pay_time']);
                }
                if (!empty($item['delivery_time'])) {
                    $item['delivery_time'] = timeToDateFormat($item['delivery_time']);
                }
                if (empty(doubleval($item['after_sale_ing_price']))) {
                    $item['after_sale_ing_price'] = '0.00';
                }
                if (!empty($item['after_status']) && !in_array($item['after_status'], [2, 3])) {
                    $item['after_sale_ing_price'] = '0.00';
                }
                //获取发货物流
                $orderSn = $item['order_sn'];
                $shippingMap[] = ['', 'exp', Db::raw('FIND_IN_SET("' . $item['sku_sn'] . '",`goods_sku`)')];
                $shippingMap[] = ['status', '=', 1];
                $item['shipping_code'] = ShipOrder::where(function ($query) use ($orderSn) {
                    $aMap[] = ['order_sn', '=', $orderSn];
                    $oMap[] = ['parent_order_sn', '=', $orderSn];
                    $query->whereOr([$aMap, $oMap]);
                })->where($shippingMap)->value('shipping_code');

                //获取金额校正字段
                $item['correction_fare'] = 0;
                $item['correction_supplier'] = 0;
                $item['correction_cost'] = 0;
                $orderCorrectionSql = OrderCorrection::where(['order_sn'=>$item['order_sn'],'sku_sn'=>$item['sku_sn'],'status'=>1])->order('create_time desc,id desc')->limit(0,1000)->buildSql();
                $orderCorrectionList = Db::table($orderCorrectionSql . ' a')->group('order_sn,sku_sn,type')->select()->toArray();
                if(!empty($orderCorrectionList)){
                    foreach ($orderCorrectionList as $key => $value) {
                        switch ($value['type']){
                            case 1:
                                $item['correction_fare'] = $value['price'];
                                break;
                            case 2:
                                $item['correction_supplier'] = $value['price'];
                                break;
                            case 3:
                                $item['correction_cost'] = $value['price'];
                                break;
                            default:
                                break;
                        }
                    }
                }
                if (!empty($item['end_time'] ?? null) && is_numeric($item['end_time'])) {
                    $item['end_time'] = timeToDateFormat($item['end_time']);
                }
                $item['activity_name'] = null;
                $item['order_user_top_info'] = [];

                if(empty($item['correction_fare'] ?? 0) && !empty($item['total_fare_price'] ?? 0)){
                    $item['correction_fare'] = $item['total_fare_price'] ?? 0;
                }

                $item['total_payable'] = round($item['total_cost_price'] + ($item['correction_fare'] ?? 0) - ($item['correction_supplier'] ?? 0) + ($item['correction_cost'] ?? 0),2);

                return $item;
            })->toArray();


        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }

    /**
     * @title  供应商结账
     * @param array $sear
     * @return mixed
     * @throws \Exception
     */
    public function payGoodsForSupplier(array $sear)
    {
        $DBRes = [];
        //$searType = 1 自己勾选订单(统一一个对账流水号) 2为根据筛选条件筛选订单 3为导入订单对账(每个订单有各自的对账流水号)
        $searType = $sear['type'];
        switch ($searType ?? 2){
            case 1:
            case 3:
                $allOrder = $sear['allOrder'];
                break;
            case 2:
                $sear['searOrder']['onlyNeedId'] = true;
                $allOrder = $this->skuSaleList($sear['searOrder'])['list'] ?? [];
                $allOrder = array_unique(array_filter(array_column($allOrder, 'id')));
                break;
        }

        if (!empty($allOrder)) {
            $DBRes = Db::transaction(function () use ($allOrder, $sear, $searType) {
                switch ($searType){
                    case 1:
                        $saveData['supplier_pay_status'] = 1;
                        $saveData['supplier_pay_no'] = trim($sear['supplier_pay_no']);
                        if (empty($saveData['supplier_pay_no'])) {
                            throw new ServiceException(['msg' => '请填写支付流水号哟']);
                        }
                        $saveData['supplier_pay_remark'] = trim($sear['supplier_pay_remark'] ?? null);
                        $saveData['supplier_pay_time'] = time();
                        foreach ($allOrder as $key => $value) {
                            $res[] = $this->where(['order_sn' => trim($value['order_sn']), 'goods_sn' => trim($value['goods_sn']), 'sku_sn' => trim($value['sku_sn'])])->update($saveData);
                        }
                        if (!empty($res) && empty(array_sum($res))) {
                            throw new ServiceException(['msg' => '暂无可修改的订单']);
                        }
                        break;
                    case 2:
                        $saveData['supplier_pay_status'] = 1;
                        $saveData['supplier_pay_no'] = trim($sear['supplier_pay_no']);
                        $saveData['supplier_pay_remark'] = trim($sear['supplier_pay_remark'] ?? null);
                        $saveData['supplier_pay_time'] = time();
                        $res = $this->where(['id' => $allOrder])->update($saveData);
                        if (empty($res)) {
                            throw new ServiceException(['msg' => '暂无可修改的订单']);
                        }
                        break;
                    case 3:
                        $existOrder = Order::with(['goods'=>function($query) use ($allOrder){
                        $query->where(['goods_sn'=>array_unique(array_column($allOrder, 'goods_sn')),'sku_sn'=>array_unique(array_column($allOrder, 'sku_sn'))]);
                    }])->where(['order_sn' => array_unique(array_column($allOrder, 'order_sn'))])->select()->toArray();
                        if (empty($existOrder)) {
                            throw new ServiceException(['msg' => '查无有效订单']);
                        }
                        if (!empty($existOrder)) {
                            foreach ($existOrder as $key => $value) {
                                $existOrder[$key]['all_sku'] = array_column($value['goods'], 'sku_sn');
                                $existOrder[$key]['all_goods'] = array_column($value['goods'], 'goods_sn');
                            }
                            foreach ($existOrder as $key => $value) {
                                foreach ($allOrder as $dKey => $dValue) {
                                    if ($value['order_sn'] == $dValue['order_sn']) {
                                        if (!in_array($dValue['sku_sn'], $value['all_sku']) || !in_array($dValue['goods_sn'], $value['all_goods'])) {
                                            throw new ServiceException(['msg' => $value['order_sn'] . '对应的商品编码有误,请勿修改导出的原始数据']);
                                        }
                                    }
                                }
                            }
                        }
                        foreach ($allOrder as $key => $value) {
                            $saveData = [];
                            $saveData['supplier_pay_status'] = 1;
                            $saveData['supplier_pay_no'] = trim($value['supplier_pay_no']);
                            if (empty($saveData['supplier_pay_no'])) {
                                throw new ServiceException(['msg' => $value['order_sn'] . '缺少支付流水号']);
                            }
                            $saveData['supplier_pay_remark'] = trim($value['supplier_pay_remark'] ?? null);
                            $saveData['supplier_pay_time'] = time();
                            $res[] = self::update($saveData, ['order_sn' => trim($value['order_sn']), 'goods_sn' => trim($value['goods_sn']), 'sku_sn' => trim($value['sku_sn'])]);
                        }
                        break;
                }


                return $res ?? [];
            });

        }

        return $DBRes ?? [];
    }

    /**
     * @title  供应商结账后退款
     * @param array $sear
     * @return mixed
     * @throws \Exception
     */
    public function supplierRefundAfterPay(array $sear)
    {
        $DBRes = [];
        //$searType = 1 自己勾选订单(统一一个对账流水号) 2为根据筛选条件筛选订单 3为导入订单对账(每个订单有各自的对账流水号)
        $searType = $sear['type'];
        switch ($searType ?? 2){
            case 1:
            case 3:
                $allOrder = $sear['allOrder'];
                break;
            case 2:
                $sear['searOrder']['onlyNeedId'] = true;
                $allOrder = $this->skuSaleList($sear['searOrder'])['list'] ?? [];
                $allOrder = array_unique(array_filter(array_column($allOrder, 'id')));
                break;
        }

        if (!empty($allOrder)) {
            $DBRes = Db::transaction(function () use ($allOrder, $sear, $searType) {
                switch ($searType){
                    case 1:
                        $saveData['supplier_refund_after_pay_no'] = trim($sear['supplier_refund_after_pay_no']);
                        $saveData['supplier_refund_after_pay_price'] = $sear['supplier_refund_after_pay_price'] ?? 0;
                        if (empty($saveData['supplier_refund_after_pay_no']) || empty($saveData['supplier_refund_after_pay_price'])) {
                            throw new ServiceException(['msg' => '请填写退款支付流水号或退款金额']);
                        }
                        $saveData['supplier_refund_after_pay_remark'] = trim($sear['supplier_refund_after_pay_remark'] ?? null);

                        $saveData['supplier_refund_after_pay_time'] = time();
                        foreach ($allOrder as $key => $value) {
                            $res[] = $this->where(['order_sn' => trim($value['order_sn']), 'goods_sn' => trim($value['goods_sn']), 'sku_sn' => trim($value['sku_sn']), 'supplier_pay_status' => 1])->update($saveData);
                        }
                        if (!empty($res) && empty(array_sum($res))) {
                            throw new ServiceException(['msg' => '暂无可修改的订单']);
                        }
                        break;
                    case 2:
                        $saveData['supplier_refund_after_pay_no'] = trim($sear['supplier_refund_after_pay_no']);
                        $saveData['supplier_refund_after_pay_price'] = trim($sear['supplier_refund_after_pay_price']);
                        $saveData['supplier_refund_after_pay_remark'] = trim($sear['supplier_refund_after_pay_remark'] ?? null);

                        $saveData['supplier_refund_after_pay_time'] = time();
//                        $res = self::update($saveData, ['id' => $allOrder, 'supplier_pay_status' => 1]);
                        $res = $this->where(['id' => $allOrder, 'supplier_pay_status' => 1])->update($saveData);
                        if (empty($res)) {
                            throw new ServiceException(['msg' => '暂无可修改的订单']);
                        }
                        break;
                    case 3:
                        $existOrder = Order::with(['goods'=>function($query) use ($allOrder){
                            $query->where(['goods_sn'=>array_unique(array_column($allOrder, 'goods_sn')),'sku_sn'=>array_unique(array_column($allOrder, 'sku_sn'))]);
                        }])->where(['order_sn' => array_unique(array_column($allOrder, 'order_sn'))])->select()->toArray();
                        if (empty($existOrder)) {
                            throw new ServiceException(['msg' => '查无有效订单']);
                        }
                        if (!empty($existOrder)) {
                            foreach ($existOrder as $key => $value) {
                                $existOrder[$key]['all_sku'] = array_column($value['goods'], 'sku_sn');
                                $existOrder[$key]['all_goods'] = array_column($value['goods'], 'goods_sn');
                            }
                            foreach ($existOrder as $key => $value) {
                                if (!empty($value['goods'])) {
                                    foreach ($value['goods'] as $gKey => $gValue) {
                                        if (!empty($gValue['supplier_pay_status'] ?? null) && $gValue['supplier_pay_status'] != 1 && (in_array($gValue['sku_sn'], $value['all_sku']) || in_array($gValue['goods_sn'], $value['all_goods']))) {
                                            throw new ServiceException(['msg' => $value['order_sn'] . '对应的商品' . $gValue['goods_sn'] . '尚未存在对账信息,无法继续填写对账后退款信息']);
                                        }

                                    }
                                }
                            }
                            foreach ($existOrder as $key => $value) {
                                $existOrder[$key]['all_sku'] = array_column($value['goods'], 'sku_sn');
                                $existOrder[$key]['all_goods'] = array_column($value['goods'], 'goods_sn');
                            }
                            foreach ($existOrder as $key => $value) {
                                foreach ($allOrder as $dKey => $dValue) {
                                    if ($value['order_sn'] == $dValue['order_sn']) {
                                        if (!in_array($dValue['sku_sn'], $value['all_sku']) || !in_array($dValue['goods_sn'], $value['all_goods'])) {
                                            throw new ServiceException(['msg' => $value['order_sn'] . '对应的商品编码有误,请勿修改导出的原始数据']);
                                        }
                                    }
                                }
                            }
                        }
                        foreach ($allOrder as $key => $value) {
                            $saveData = [];
                            $saveData['supplier_refund_after_pay_no'] = trim($value['supplier_refund_after_pay_no'] ?? 0);
                            $saveData['supplier_refund_after_pay_price'] = trim($value['supplier_refund_after_pay_price'] ?? 0);
                            if (empty($saveData['supplier_refund_after_pay_no']) || empty($saveData['supplier_refund_after_pay_price'])) {
                                throw new ServiceException(['msg' => $value['order_sn'] . '缺少退款支付流水号或退款金额']);
                            }
                            $saveData['supplier_refund_after_pay_remark'] = trim($value['supplier_refund_after_pay_remark'] ?? null);
                            $saveData['supplier_refund_after_pay_time'] = time();
                            $res[] = self::update($saveData, ['order_sn' => trim($value['order_sn']), 'goods_sn' => trim($value['goods_sn']), 'sku_sn' => trim($value['sku_sn']), 'supplier_pay_status' => 1]);
                        }
                        break;
                }


                return $res ?? [];
            });

        }

        return $DBRes ?? [];
    }


    public function orders()
    {
        return $this->hasOne('Order', 'order_sn', 'order_sn')->bind(['order_belong']);
    }

    public function goods()
    {
        return $this->hasOne('GoodsSpu', 'goods_sn', 'goods_sn')->bind(['main_image', 'title', 'link_product_id']);
    }

    public function allGoods()
    {
        return $this->hasOne('GoodsSpu', 'goods_sn', 'goods_sn');
    }

    public function vdc()
    {
        return $this->hasMany('GoodsSkuVdc', 'sku_sn', 'sku_sn')->field('goods_sn,sku_sn,level,belong,purchase_price,vdc_genre,vdc_type,vdc_one,vdc_two')->order('level desc');
    }

    public function sku()
    {
        return $this->hasOne('GoodsSku', 'sku_sn', 'sku_sn')->bind(['market_price']);
    }

    public function goodsSku()
    {
        return $this->hasOne('GoodsSku', 'sku_sn', 'sku_sn');
    }

    public function supplier()
    {
        return $this->hasOne('GoodsSpu', 'goods_sn', 'goods_sn');
    }

    public function goodsCode()
    {
        return $this->hasOne('GoodsSpu', 'goods_sn', 'goods_sn')->bind(['goods_code']);
    }

    public function supplierInfo()
    {
        return $this->hasOne('Supplier', 'supplier_code', 'supplier_code');
    }

    public function orderInfo()
    {
        return $this->hasOne('Order', 'order_sn', 'order_sn');
    }

    public function fuseDetail()
    {
        return $this->hasMany('CrowdfundingFuseRecordDetail', 'order_sn', 'order_sn COLLATE utf8mb4_unicode_ci');
    }

}