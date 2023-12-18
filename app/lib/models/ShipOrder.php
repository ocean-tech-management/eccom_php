<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 发货模块订单表]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ShipException;
use Exception;
use think\facade\Db;

class ShipOrder extends BaseModel
{
    protected $validateFields = ['order_sn'];

    /**
     * @title  查询发货订单
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function onlySelect(array $sear = []): array
    {
        $map = [];
        $list = [];
        $needSearGoods = false;
        $needSearUser = false;

        //查找订单编号
//        if (!empty($sear['searOrderSn'])) {
//            $map[] = ['a.order_sn', '=', $sear['searOrderSn']];
//        }
        if (!empty($sear['searUserName'])) {
            $map[] = ['b.name', '=', $sear['searUserName']];
            $needSearUser = true;
        }
        if (!empty($sear['searUserPhone'])) {
            $map[] = ['a.user_phone', '=', $sear['searUserPhone']];
        }

        if (empty($sear['notLimitOrderStatus'])) {
            //订单状态
            if (!empty($sear['searType'])) {
                $map[] = ['a.order_status', 'in', $sear['searType'] ?? [1, 2, 5, 6]];
            } else {
                $map[] = ['a.order_status', 'not in', [1, -1, -2, -3]];
            }
        }
        //订单类型
        if (!empty($sear['order_type'])) {
            $map[] = ['a.order_type', '=', $sear['order_type']];
        }
        //不查找的订单类型
        if (!empty($sear['not_order_type'])) {
            $map[] = ['a.order_type', 'not in', $sear['not_order_type']];
        }
        //支付类型
        if (!empty($sear['pay_type'])) {
            $map[] = ['a.pay_type', '=', $sear['pay_type']];
        }

        //售后状态
        if (!empty($sear['afterType'])) {
            $map[] = ['a.after_status', 'in', $sear['afterType'] ?? [1, -1, 2, 3, 5]];
        }

        //备货状态
        if (!empty($sear['shipping_status'])) {
            $map[] = ['a.shipping_status', '=', $sear['shipping_status'] ?? 1];
        }

        //订单标识
        if (!empty($sear['order_sign'])) {
            $map[] = ['a.order_sign', 'in', $sear['order_sign']];
        }
        //订单归属
        if (!empty($sear['order_belong'])) {
            $map[] = ['a.order_belong', '=', $sear['order_belong']];
        }
        //是否需要查询多商品的订单
        if (!empty($sear['searMoreGoodsOrder'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.goods_sku', ','))];
        }
        //查找商品
//        if(!empty($sear['searGoodsSpuSn'])){
//            if(is_array($sear['searGoodsSpuSn'])){
//                $map[] = ['d.goods_sn','in',$sear['searGoodsSpuSn']];
//            }else{
//                $map[] = ['d.goods_sn','=',$sear['searGoodsSpuSn']];
//            }
//            $needSearGoods = true;
//        }
//        if(!empty($sear['searGoodsSkuSn'])){
//            if(is_array($sear['searGoodsSkuSn'])){
//                $map[] = ['d.sku_sn','in',$sear['searGoodsSkuSn']];
//            }else{
//                $map[] = ['d.sku_sn','=',$sear['searGoodsSkuSn']];
//            }
//            $needSearGoods = true;
//        }
        $supplierSku = [];
        //供应商对应的商品
        if (!empty($sear['searSupplierCode'])) {
            $supplierSku = GoodsSku::where(['supplier_code' => trim($sear['searSupplierCode'])])->column('sku_sn');
        }
        //查询商品
        $allSku = [];
        if (!empty($sear['searGoodsSpuSn'])) {
            if (empty($sear['searGoodsSkuSn'])) {
                $allSku = GoodsSku::where(['goods_sn' => $sear['searGoodsSpuSn']])->column('sku_sn');
            } else {
                $allSku = $sear['searGoodsSkuSn'];
            }

            if (!empty($supplierSku) && !empty($allSku)) {
                $mergeAllSku = array_merge_recursive($supplierSku, $allSku);
            } elseif (empty($supplierSku) && !empty($allSku)) {
                $mergeAllSku = $allSku;
            } elseif (!empty($supplierSku) && empty($allSku)) {
                $mergeAllSku = $supplierSku;
            } else {
                $mergeAllSku = $allSku;
            }
            $allSku = $mergeAllSku;
        } else {
            //管理员帐号有供应商信息则默认查询对应供应商的商品,没有则判断是否为其他管理员查询供应商的商品信息
            if (!empty($sear['adminInfo'])) {
                $supplierCode = $sear['adminInfo']['supplier_code'] ?? null;
                if (!empty($supplierCode)) {
                    $allSku = GoodsSku::where(['supplier_code' => $supplierCode, 'status' => [1,2]])->column('sku_sn');
                    if (empty($allSku)) {
                        throw new ShipException(['msg' => '暂无属于您的供货商品']);
                    }
                }
            }
            if ((empty($sear['adminInfo']) || (!empty($sear['adminInfo']) && empty($sear['adminInfo']['supplier_code']))) && !empty($sear['searSupplierCode'])) {
                if (empty($supplierSku ?? [])) {
                    throw new ShipException(['msg' => '暂无属于该供应商的商品']);
                }
                $allSku = $supplierSku ?? [];
            }
        }


        if (!empty($allSku)) {
            $needSearGoods = true;
            $allSkuE = implode('|', $allSku);
            $map[] = ['', 'exp', Db::raw('a.goods_sku REGEXP ' . "'" . $allSkuE . "'")];
        }

        //查找物流信息
        if (!empty($sear['searShippingName'])) {
            $map[] = ['a.shipping_name', '=', $sear['searShippingName']];
        }
        if (!empty($sear['searShippingPhone'])) {
            $map[] = ['a.shipping_phone', '=', $sear['searShippingPhone']];
        }
        if (!empty($sear['searShippingCode'])) {
            $map[] = ['a.shipping_code', '=', $sear['searShippingCode']];
        }
        if (!empty($sear['searShippingAddress'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.shipping_address', $sear['searShippingAddress']))];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
        }

        $map[] = ['a.status', '=', 1];

        $page = intval($sear['page'] ?? 0) ?: null;
        $pageNumber = !empty($sear['pageNumber']) ? intval($sear['pageNumber']) : $this->pageNumber;
        if (!empty($sear['notLimit'])) {
            $page = null;
        }

        if (!empty($page)) {
            $aTotals = Db::name('ship_order')->alias('a')
                ->when($needSearUser, function ($query) {
                    $query->join('sp_user b', 'a.uid = b.uid', 'left');
                })
                ->where($map)
                ->where(function ($query) use ($sear) {
                    if (!empty($sear['searOrderSn'])) {
                        if (is_array($sear['searOrderSn'])) {
                            $mapAnd[] = ['a.order_sn', 'in', $sear['searOrderSn']];
                            $mapOr[] = ['a.parent_order_sn', 'in', $sear['searOrderSn']];
                        } else {
                            $mapAnd[] = ['a.order_sn', '=', $sear['searOrderSn']];
                            $mapOr[] = ['a.parent_order_sn', '=', $sear['searOrderSn']];
                        }
                        $query->whereOr([$mapAnd, $mapOr]);
                    }
                })
                ->where(function ($query) use ($sear) {
                    if (empty($sear['notCheckStatus'])) {
                        $normalMapOr[] = ['a.split_status', '=', 3];
                        $splitMapOr[] = ['a.split_status', '=', 1];
                        $mergeMapOr[] = ['a.split_status', '=', 2];
                        $mergeMapOr[] = ['', 'exp', Db::raw('a.parent_order_sn is null')];
                        $query->whereOr([$normalMapOr, $splitMapOr, $mergeMapOr]);
                    }
                })
                ->field('a.id')->buildSql();

            $aTotal = Db::table($aTotals . " a")->value('count(*)');

            $pageTotal = ceil($aTotal / $pageNumber);
        }

        $aList = Db::name('ship_order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
//            ->when($needSearGoods,function($query) use ($sear){
//                $query->join('sp_order_goods d','a.order_sn = d.order_sn','left');
//            })
            ->where($map)
            ->where(function ($query) use ($sear) {
                if (!empty($sear['searOrderSn'])) {
                    if (is_array($sear['searOrderSn'])) {
                        $mapAnd[] = ['a.order_sn', 'in', $sear['searOrderSn']];
                        $mapOr[] = ['a.parent_order_sn', 'in', $sear['searOrderSn']];
                    } else {
                        $mapAnd[] = ['a.order_sn', '=', $sear['searOrderSn']];
                        $mapOr[] = ['a.parent_order_sn', '=', $sear['searOrderSn']];
                    }
                    $query->whereOr([$mapAnd, $mapOr]);
                }
            })
            ->where(function ($query) use ($sear) {
                if (empty($sear['notCheckStatus'])) {
                    $normalMapOr[] = ['a.split_status', '=', 3];
                    $splitMapOr[] = ['a.split_status', '=', 1];
                    $mergeMapOr[] = ['a.split_status', '=', 2];
                    $mergeMapOr[] = ['', 'exp', Db::raw('a.parent_order_sn is null')];
                    $query->whereOr([$normalMapOr, $splitMapOr, $mergeMapOr]);
                }
            })
            ->field('a.id,b.name as user_name')
//            ->field('a.order_sn,a.order_belong,a.uid,a.user_phone,a.item_count,a.total_price,a.fare_price,a.discount_price,a.real_pay_price,a.pay_type,a.pay_status,a.order_status,a.create_time,a.pay_time,a.end_time,a.shipping_code,a.shipping_name,a.shipping_phone,a.shipping_address,a.split_status,a.parent_order_sn,a.order_update_time,a.sync_status,a.order_child_sort,a.order_sign,a.order_sort,a.split_status,a.update_time,a.order_update_time,a.order_remark,a.seller_remark,a.sync_status,a.goods_sku,a.order_is_exist,b.name as user_name,group_concat(c.order_sn) as child_order_sn,a.shipping_company_code,a.shipping_company')
//            ->group('a.order_sn')
            ->order('a.create_time desc,a.order_sort desc,a.order_child_sort asc')
            ->when($page, function ($query) use ($page, $pageNumber) {
                $query->page($page, $pageNumber);
            })->select()->toArray();

        if (!empty($aList)) {
            $aMap[] = ['id', 'in', array_column($aList, 'id')];
            $list = Db::name('ship_order')->alias('a')
                ->where($aMap)
                ->field('a.id,a.order_sn,a.order_belong,a.uid,a.user_phone,a.item_count,a.total_price,a.fare_price,a.discount_price,a.real_pay_price,a.pay_type,a.pay_status,a.order_status,a.create_time,a.pay_time,a.end_time,a.shipping_code,a.shipping_name,a.shipping_phone,a.shipping_address,a.split_status,a.parent_order_sn,a.order_update_time,a.sync_status,a.order_child_sort,a.order_sign,a.order_sort,a.split_status,a.update_time,a.order_update_time,a.order_remark,a.seller_remark,a.sync_status,a.goods_sku,a.order_is_exist,a.split_number,a.shipping_company_code,a.shipping_company,a.shipping_type,a.shipping_status,a.order_type,a.advance_buy,a.is_exchange')
                ->order('a.create_time desc,a.order_sort desc,a.order_child_sort asc')
                ->select()->each(function ($item, $key) use ($sear) {
                    if (empty($sear['notTimeFormat'])) {
                        $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                        $item['update_time'] = date('Y-m-d H:i:s', $item['update_time']);
                        $item['order_update_time'] = date('Y-m-d H:i:s', $item['order_update_time']);
                        if (!empty($item['pay_time'])) {
                            $item['pay_time'] = date('Y-m-d H:i:s', $item['pay_time']);
                        }
                        if (!empty($item['end_time'])) {
                            $item['end_time'] = date('Y-m-d H:i:s', $item['end_time']);
                        }
                    }
                    return $item;
                })->toArray();
        }

        if (!empty($list)) {
            //补回关联子类订单的信息
            $allOrderSn = array_column($list, 'order_sn');
            $cMap[] = ['parent_order_sn', 'in', $allOrderSn];
            $cMap[] = ['status', '=', 1];
            //2021-1-8新增,不理解原先为什么直接要group by查询,但实际业务该查询会将部分被合并订单合并成一单,是错误的,先将其注释
//            $childOrder = $this->where($cMap)->field('order_sn,group_concat(order_sn) as child_order_sn,parent_order_sn')->group('parent_order_sn')->select()->toArray();
            $childOrder = $this->where($cMap)->field('order_sn,parent_order_sn')->select()->toArray();
            $childOrderSn = [];

            if (!empty($childOrder)) {
                $childOrderSn = array_unique(array_filter(array_column($childOrder, 'order_sn')));
                foreach ($list as $key => $value) {
                    if (!isset($list[$key]['child_order_sn'])) {
//                        $list[$key]['child_order_sn'] = null;
                        $list[$key]['child_order_sn'] = [];
                    }

                    foreach ($childOrder as $cKey => $cValue) {
                        if ($value['order_sn'] == $cValue['parent_order_sn']) {
                            $list[$key]['child_order_sn'][] = $cValue['order_sn'];
                        }
                    }
                }
                //2021-1-8新增
                foreach ($list as $key => $value) {
                    $list[$key]['child_order_sn'] = implode(',', $value['child_order_sn']);
                }
            }
            //补回用户姓名
            foreach ($list as $key => $value) {
                foreach ($aList as $uList => $uValue) {
                    if ($value['id'] == $uValue['id']) {
                        $list[$key]['user_name'] = $uValue['user_name'];
                    }
                }
            }

            //补齐海外购附加条件
            if (!empty($childOrderSn)) {
                $attachOrderSn = array_merge_recursive($allOrderSn, $childOrderSn);
            } else {
                $attachOrderSn = $allOrderSn;
            }
            $attach = OrderAttach::where(['order_sn' => $attachOrderSn, 'status' => 1])->withoutField('id,update_time,create_time,status')->select()->toArray();
            if (!empty($attach)) {
                foreach ($list as $key => $value) {
                    $list[$key]['is_attach_order'] = false;
                    $list[$key]['attach'] = [];
                    foreach ($attach as $aKey => $aValue) {
                        if ($aValue['order_sn'] == $value['order_sn'] || $aValue['order_sn'] == $value['parent_order_sn']) {
                            $list[$key]['is_attach_order'] = true;
                            $list[$key]['attach'] = $aValue;
                        }
                    }
                }
            }

        }

        //展示参与分润的用户信息
        if (!empty($sear['needDivideDetail'])) {
            if (!empty($list)) {
                $aOrderSn = array_column($list, 'order_sn');
                $allDivideSql = Divide::with(['linkUser'])->where(['order_sn' => $aOrderSn])->field('link_uid,order_sn,order_uid,level,real_divide_price')->order('level asc,divide_type desc,vdc_genre desc')->select()->toArray();
                if (!empty($allDivideSql)) {
                    foreach ($allDivideSql as $key => $value) {
                        $allDivideSqls[$value['order_sn']][] = $value;
                    }
                    foreach ($allDivideSqls as $key => $value) {
                        $allDivide[] = current($value);
                    }
                }

//                $allDivide = Db::table($allDivideSql . " a")->join('sp_user b', 'a.link_uid = b.uid', 'left')->field('a.*,b.name as link_user_name,b.avatarUrl as link_user_avatarUrl,b.phone as link_user_phone')->group('a.order_sn')->select()->toArray();

                if (!empty($allDivide)) {
                    foreach ($list as $key => $value) {
                        if (!isset($list[$key]['divideTopUser'])) {
                            $list[$key]['divideTopUser'] = [];
                        }
                        foreach ($allDivide as $dKey => $vValue) {
                            if ($vValue['order_sn'] == $value['order_sn']) {
                                $list[$key]['divideTopUser'] = $vValue;
                            }
                        }

                    }
                }

            }
        }

        if (!empty($sear['needUserDetail'])) {
            if (!empty($list)) {
                $allUserList = User::where(['uid' => array_unique(array_column($list, 'uid'))])->field('uid,create_time,team_vip_level')->select()->toArray();
                $teamVipLeveName = TeamMemberVdc::where(['status' => 1])->column('name', 'level');
                foreach ($allUserList as $key => $value) {
                    $value['team_vip_name'] = $teamVipLeveName[$value['team_vip_level']] ?? '非团队会员';
                    $allUserInfo[$value['uid']] = $value;
                }
                foreach ($list as $key => $value) {
                    if (!empty($value['uid'])) {
                        $list[$key]['user_team_vip_name'] = $allUserInfo[$value['uid']]['team_vip_name'] ?? null;
                        $list[$key]['user_create_time'] = $allUserInfo[$value['uid']]['create_time'] ?? null;
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }

    /**
     * @title  发货订单列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        //查询数据
        $sqlList = $this->onlySelect($sear);

        //重组数据
        $list = $sqlList['list'];
        $pageTotal = $sqlList['pageTotal'];
        $total = $sqlList['total'] ?? 0;
        $mergeOrder = [];
        $splitOrder = [];
        $splitChildOrder = [];
        $splitParentOrder = [];
        $formatList = [];
//        dump('发货订单');
        //dump($list);die;
        if (!empty($list)) {
            //不仅获取当前的订单号,也获取父类的订单号,这样子订单也能查到对应的商品了
            $aGoodsSn = array_column($list, 'order_sn');
            $aParentGoodsSn = array_column($list, 'parent_order_sn');
            $allGoodsSn = array_unique(array_filter(array_merge_recursive($aGoodsSn, $aParentGoodsSn)));
            //补齐合并订单的子订单,此目的是来补全父类的商品等信息
            foreach ($list as $key => $value) {
                if ($value['split_status'] == 2 && empty($value['parent_order_sn'])) {
                    $child = explode(',', $value['child_order_sn']);
                    if (!empty($child)) {
                        foreach ($child as $cKey => $cValue) {
                            if (!in_array($cValue, $allGoodsSn)) {
                                $needReSelect[] = $cValue;
                            }
                        }
                    }
                }
            }

            if (!empty($needReSelect)) {
                $newReSqlList = $this->onlySelect(['searOrderSn' => $needReSelect, 'notCheckStatus' => true]);
                $newReList = $newReSqlList['list'];
                if (!empty($newReList)) {
                    $list = array_merge_recursive($list, $newReList);
                    $aGoodsSn = array_column($list, 'order_sn');
                    $aParentGoodsSn = array_column($list, 'parent_order_sn');
                    $allGoodsSn = array_unique(array_filter(array_merge_recursive($aGoodsSn, $aParentGoodsSn)));
                }
            }

            $aList = [];
            $allOrderGoods = (new OrderGoods())->with(['goodsCode'])->where(['order_sn' => $allGoodsSn, 'status' => 1])->withoutField('id,desc')->order('create_time desc')->select()->each(function ($item) {
                $item['supplier'] = [];
                return $item;
            })->toArray();

            $mergeParentKey = [];
            if (!empty($allOrderGoods)) {
                //获取供应商信息
                $nowAllGoodsSn = array_column($allOrderGoods, 'goods_sn');
                $GoodsSupplier = GoodsSpu::where(['goods_sn' => $nowAllGoodsSn])->column('supplier_code', 'goods_sn');
                if (!empty($GoodsSupplier)) {
                    $GoodsSupplierUnique = array_unique(array_filter($GoodsSupplier));
                    if (!empty($GoodsSupplierUnique)) {
                        $supplierList = Supplier::where(['supplier_code' => $GoodsSupplierUnique])->field('supplier_code,name,concat_user,concat_phone,level,address')->select()->toArray();
                        if (!empty($supplierList)) {
                            foreach ($supplierList as $key => $value) {
                                $supplierInfo[$value['supplier_code']] = $value;
                            }
                            foreach ($GoodsSupplier as $key => $value) {
                                $goodsForSupplier[$key] = $supplierInfo[$value] ?? [];
                            }
                            if (!empty($goodsForSupplier)) {
                                foreach ($allOrderGoods as $key => $value) {
                                    if (!empty($goodsForSupplier[$value['goods_sn']])) {
                                        $allOrderGoods[$key]['supplier'] = $goodsForSupplier[$value['goods_sn']] ?? [];
                                    }
                                }
                            }
                        }
                    }
                }

                //获取众筹活动区详情
                $nowAllCrowdCode = array_unique(array_column($allOrderGoods, 'crowd_code'));
                if (!empty($nowAllCrowdCode)) {
                    $goodsCrowdActivity = CrowdfundingActivity::where(['activity_code' => $nowAllCrowdCode])->column('title', 'activity_code');
                    foreach ($allOrderGoods as $key => $value) {
                        if (!empty($value['crowd_code'] ?? null) && !empty($goodsCrowdActivity[$value['crowd_code']] ?? null)) {
                            $allOrderGoods[$key]['crowd_activity_title'] = $goodsCrowdActivity[$value['crowd_code']];
                        }
                    }
                }

                foreach ($list as $key => $value) {
                    foreach ($allOrderGoods as $k => $v) {
                        if ($value['split_status'] == 3) {
                            if ($v['order_sn'] == $value['order_sn']) {
                                $list[$key]['goods'][$v['sku_sn']] = $v;
                            }
                            continue;
                        }
                        if ($value['split_status'] == 1) {
                            if (empty($value['parent_order_sn'])) {
                                if ($v['order_sn'] == $value['order_sn'] && in_array($v['sku_sn'], explode(',', $value['goods_sku']))) {
                                    $list[$key]['goods'][$v['sku_sn']] = $v;
                                }
                            } else {
                                if ($v['order_sn'] == trim($value['parent_order_sn']) && in_array($v['sku_sn'], explode(',', $value['goods_sku']))) {
                                    $list[$key]['goods'][$v['sku_sn']] = $v;
                                }
                            }
                            continue;
                        }
                        if ($value['split_status'] == 2) {
                            if (empty($value['parent_order_sn'])) {
                                if (!isset($mergeParentKey[$value['order_sn']])) {
                                    $mergeParentKey[$value['order_sn']] = $key;
                                }
                                if ($v['order_sn'] == $value['order_sn'] && in_array($v['sku_sn'], explode(',', $value['goods_sku']))) {
                                    $list[$key]['goods'][$v['sku_sn']] = $v;
                                }

                            } else {
                                if ($v['order_sn'] == $value['order_sn']) {
                                    //是自己的商品则添加到自己的goods数组里面去
                                    if (in_array($v['sku_sn'], explode(',', $value['goods_sku']))) {
                                        $list[$key]['goods'][$v['sku_sn']] = $v;
                                    }
                                    if (isset($mergeParentKey[$value['parent_order_sn']]) && ($mergeParentKey[$value['parent_order_sn']] >= 0) && !empty($list[$mergeParentKey[$value['parent_order_sn']]])) {
                                        //是自己的商品,如果父订单也有这个商品则需要累加给父订单显示用
                                        if (in_array($v['sku_sn'], explode(',', $list[$mergeParentKey[$value['parent_order_sn']]]['goods_sku']))) {
                                            if (!isset($list[$mergeParentKey[$value['parent_order_sn']]]['goods'][$v['sku_sn']])) {
                                                $list[$mergeParentKey[$value['parent_order_sn']]]['goods'][$v['sku_sn']] = $v;
                                            } else {
                                                $list[$mergeParentKey[$value['parent_order_sn']]]['goods'][$v['sku_sn']]['count'] += $v['count'];
                                                $list[$mergeParentKey[$value['parent_order_sn']]]['goods'][$v['sku_sn']]['price'] += $v['price'];
                                                $list[$mergeParentKey[$value['parent_order_sn']]]['goods'][$v['sku_sn']]['total_price'] += $v['total_price'];
                                                $list[$mergeParentKey[$value['parent_order_sn']]]['goods'][$v['sku_sn']]['total_fare_price'] += $v['total_fare_price'];
                                                $list[$mergeParentKey[$value['parent_order_sn']]]['goods'][$v['sku_sn']]['coupon_dis'] += $v['coupon_dis'];
                                                $list[$mergeParentKey[$value['parent_order_sn']]]['goods'][$v['sku_sn']]['all_dis'] += $v['all_dis'];
                                                $list[$mergeParentKey[$value['parent_order_sn']]]['goods'][$v['sku_sn']]['real_pay_price'] += $v['real_pay_price'];
                                                $list[$mergeParentKey[$value['parent_order_sn']]]['goods'][$v['sku_sn']]['supplier'][] = $v['supplier'];
                                            }
                                            //unset($list[$key]);
                                        }
                                    }

                                }

                            }
                        }
                    }
                }
//                dump($list);

                //修改因为拆单或合单导致的数据量不一致
                //如果只有一个SKU且数量不止1的拆单订单,并且拆单数量不为零,可判断为数量拆单,需要重新修改goods数组的数量
                foreach ($list as $key => $value) {
                    //失效的订单就不要在继续重新显示了,因为已经找不到goods数组了
                    if (!in_array($value['order_status'], [-3])) {
                        if ($value['split_status'] == 1 && !empty($list[$key]['goods'])) {
                            if (count(explode(',', $value['goods_sku'])) == 1 && (count($list[$key]['goods']) == 1) && ($list[$key]['goods'][$value['goods_sku']]['count'] != 1)) {
                                if (!empty($value['split_number'])) {
                                    $list[$key]['goods'][$value['goods_sku']]['count'] = $value['split_number'];
                                }
//                            $list[$key]['goods'][$value['goods_sku']]['count'] = $value['item_count'];
                            }
                        }
                    }
                    //合并订单父类的sku数量可能是不对的,重新根据goods_sku去显示
                    if ($value['split_status'] == 2 && empty($value['parent_order_sn'])) {
                        $list[$key]['item_count'] = count(explode(',', $value['goods_sku']));
                    }

                }

                foreach ($list as $key => $value) {
                    $aList[$value['order_sn']] = $value;
                }
                //dump($aList);
                foreach ($aList as $key => $value) {
                    if ($value['split_status'] == 1) {

                        if (empty($value['parent_order_sn'])) {
                            if (!isset($splitOrder[$value['order_sn']])) {
                                $splitOrder[$value['order_sn']] = [];
                            }
                            $splitOrder[$value['order_sn']] = explode(',', $value['goods_sku']);
                            $splitParentOrder[$value['order_sn']] = explode(',', $value['goods_sku']);
                        } else {
                            if (!isset($splitOrder[$value['parent_order_sn']])) {
                                $splitOrder[$value['parent_order_sn']] = [];
                            }
                            if (!isset($splitChildOrder[$value['order_sn']])) {
                                $splitChildOrder[$value['order_sn']] = [];
                            }
                            if (in_array($value['parent_order_sn'], $aGoodsSn)) {
                                $aList[$value['parent_order_sn']]['child'][] = $value;
                            }
                            $splitOrder[$value['parent_order_sn']] = array_merge_recursive($splitOrder[$value['parent_order_sn']], array_values(explode(',', $value['goods_sku'])));
                            $splitChildOrder[$value['order_sn']] = explode(',', $value['goods_sku']);
                        }

                    }
                    if ($value['split_status'] == 2) {
                        if (empty($value['parent_order_sn'])) {
                            $mergeParentOrder[$value['order_sn']] = explode(',', $value['goods_sku']);
                        } else {
                            if (isset($aList[$value['parent_order_sn']])) {
                                $aList[$value['parent_order_sn']]['child'][] = $value;
                                if (!isset($mergeOrder[$value['parent_order_sn']])) {
                                    $mergeOrder[$value['parent_order_sn']] = [];
                                }
                                $mergeOrder[$value['parent_order_sn']] = array_keys($value['goods']);
                            }
                            unset($aList[$key]);
                            unset($aList[$key]);
                        }
                    }
                }

                if (!empty($sear['needNormalKey'])) {
                    $aList = array_values($aList);
                }

            }
            //dump($aList);die;
            unset($list);
            $list = $aList;

            //可选择根据商品SPU重组返回格式 或 根据供应商SPU重组返回格式 一种方法重组返回的数组格式
            if (!empty($sear['needFormatList'])) {
                //1 根据商品SPU重组返回格式 2 根据供应商SPU重组返回格式
                switch ($sear['needFormatList']) {
                    case 1:
                        $listGoodGroup = [];
                        $groupGoodsSn = [];
                        foreach ($list as $key => $value) {
                            if (!empty($value['goods'])) {
                                foreach ($value['goods'] as $gKey => $gValue) {
                                    $groupGoodsSn[] = $gValue['goods_sn'];
                                }
                            }
                        }
                        $groupGoodsSn = array_unique(array_filter($groupGoodsSn));
                        $groupGoodsLists = GoodsSpu::where(['goods_sn' => $groupGoodsSn])->field('goods_sn,title,goods_code,supplier_code,main_image,sub_title')->select()->toArray();
                        if (!empty($groupGoodsLists)) {
                            foreach ($groupGoodsLists as $key => $value) {
                                $groupGoodsList[$value['goods_sn']] = $value;
                            }
                        }

                        foreach ($list as $key => $value) {
                            if (!empty($value['goods'])) {
                                foreach ($value['goods'] as $gKey => $gValue) {
                                    if (!empty($gValue['goods_sn'])) {
                                        $listGoodGroup[$gValue['goods_sn']]['info'] = $groupGoodsList[$gValue['goods_sn']] ?? [];
                                        $listGoodGroup[$gValue['goods_sn']]['orderList'][] = $value;
                                    }
                                }
                            }
                        }
                        if (!empty($listGoodGroup)) {
                            $list = array_values($listGoodGroup);
                        }
                        break;
                    case 2:
                        $listSupplierGroup = [];
                        foreach ($list as $key => $value) {
                            if (!empty($value['goods'])) {
                                foreach ($value['goods'] as $gKey => $gValue) {
                                    if (!empty($gValue['supplier'])) {
                                        $listSupplierGroup[$gValue['supplier']['supplier_code']]['info'] = $gValue['supplier'];
                                        $listSupplierGroup[$gValue['supplier']['supplier_code']]['orderList'][] = $value;
                                    }
                                }
                            }
                        }
                        if (!empty($listSupplierGroup)) {
                            $list = array_values($listSupplierGroup);
                        }
                        break;
                    default:
                        break;
                }
            }
        }


        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $total, 'mergeOrder' => $mergeOrder, 'splitOrder' => $splitOrder, 'splitParentOrder' => $splitParentOrder, 'splitChildOrder' => $splitChildOrder, 'formatList' => $formatList ?? []];
    }

    /**
     * @title  同步商品订单
     * @param array $sear
     * @return mixed
     * @throws Exception
     */
    public function sync(array $sear)
    {
        set_time_limit(0);
        //ini_set ("memory_limit","-1");
        //如果没有手动操作过同步,默认同步过去两天(包含今天),共计三天的待发货订单
        if (empty($sear['start_time'])) {
            //如果没有选择时间则默认同步开始时间为上一次同步的最晚时间(往前设置5s的偏移量)
            $lastSyncTime = OperationLog::where(['path' => 'ship/sync'])->order('create_time desc')->value('create_time');
            if (!empty($lastSyncTime)) {
                $orderSear['start_time'] = date('Y-m-d', $lastSyncTime) . ' 00:00:00';
            } else {
                $orderSear['start_time'] = date('Y-m-d', strtotime("-2 day")) . " 00:00:00";
            }
            $orderSear['end_time'] = date('Y-m-d', time()) . " 23:59:59";
        }else{
            $orderSear['start_time'] = $sear['start_time'];
            $orderSear['end_time'] = $sear['end_time'];
        }

        //查询正常订单(仅查找待发货状态且订单更新时间大于上次同步订单更新时间的订单)
        $orderSear['notLimit'] = true;
        $orderSear['needGoods'] = true;
        $orderSear['goodsNormalKey'] = true;
        $orderSear['searType'] = $sear['searType'] ?? [2, 3];
        $orderSear['notTimeFormat'] = true;
        $orderSear['columnSear'] = ['a.update_time', '>', 'a.sync_order_update_time'];
        $orderSear['orderField'] = 'a.create_time asc';
        $orderSear['after_status'] = [1];
        $orderSear['order_goods_status'] = [1];
        //可指定单个订单同步
        if (!empty($sear['searOrderSn'])) {
            $orderSear['searOrderSn'] = trim($sear['searOrderSn']);
        }
        //默认不自动同步众筹订单, 众筹订单需要额外的同步逻辑
        if(empty($sear['searCrowdFunding'] ?? null)){
            $orderSear['not_order_type'] = [6];
        }
        if (!empty($sear['searCrowdKey'] ?? null)) {
            $orderSear['crowd_key'] = $sear['searCrowdKey'];
        }
        if(!empty($sear['order_type'] ?? null)){
            $orderSear['order_type'] = $sear['order_type'];
        }
        //筛选剔除拼团订单未完成的订单
        $orderSear['checkNotCompletePtOrder'] = true;
        $orderLists = (new Order())->list($orderSear);
        $orderList = $orderLists['list'] ?? [];
        //剔除不可同步的订单
        if (!empty($orderList)) {
            foreach ($orderList as $key => $value) {
                if (!empty($value['can_sync'] ?? null) && $value['can_sync'] == 2) {
                    unset($orderList[$key]);
                }
            }
        }
        //无订单直接返回成功
        if (empty($orderList)) {
            return true;
        }
        $orderDbRes = Db::transaction(function () use ($orderList) {
            $orderModel = (new Order());
            //选出正常订单中未同步的订单,存入新增发货订单数组
            $needSyncCreateOrder = [];
            $needSyncCreateOrderSn = [];
            $needSyncCreateOrderTime = [];
            $count = 0;
            $nowShipOrderCount = ShipOrder::count();
            $startNumber = ($nowShipOrderCount + 1);
            foreach ($orderList as $key => $value) {
                if ($value['sync_status'] == 2) {
                    unset($value['id']);
                    $value['order_update_time'] = $value['update_time'];
                    $needSyncCreateOrder[$count] = $value;
                    $needSyncCreateOrder[$count]['goods_sku'] = $value['all_goods_sku'];
                    $needSyncCreateOrder[$count]['order_is_exist'] = 1;
                    $needSyncCreateOrder[$count]['split_status'] = 3;
                    $needSyncCreateOrder[$count]['sync_status'] = 1;
                    $needSyncCreateOrder[$count]['sync_first_time'] = time();
                    $needSyncCreateOrder[$count]['order_sort'] = sprintf('%010d', $startNumber);
                    $needSyncCreateOrder[$count]['order_child_sort'] = $needSyncCreateOrder[$count]['order_sort'];
                    $needSyncCreateOrderSn[] = $value['order_sn'];
                    $needSyncCreateOrderTime[$value['order_sn']] = $value['update_time'];
                    unset($needSyncCreateOrder[$count]['update_time']);
                    unset($needSyncCreateOrder[$count]['goods']);
                    //修改正常订单数据
                    $orderModel->isAutoWriteTimestamp(false)->where(['order_sn' => $value['order_sn'], 'sync_status' => 2])->save(['split_status' => 3, 'sync_status' => 1, 'sync_order_update_time' => $value['update_time']]);
                    unset($orderList[$key]);
                    $count++;
                    $startNumber++;
                }
                $orderList[$value['order_sn']] = $value;
                unset($orderList[$key]);
            }
            //新增同步订单
            $this->saveAll($needSyncCreateOrder);

            return ['orderList' => $orderList, 'needSyncCreateOrder' => $needSyncCreateOrder, 'needSyncCreateOrderSn' => $needSyncCreateOrderSn];
        });
        $orderList = $orderDbRes['orderList'] ?? [];
        $needSyncCreateOrder = $orderDbRes['needSyncCreateOrder'] ?? [];
        $needSyncCreateOrderSn = $orderDbRes['needSyncCreateOrderSn'] ?? [];


        //没有被同步过的订单直接新增
//        if(!empty($needSyncCreateOrder)){
//            $createRes = Db::transaction(function() use ($needSyncCreateOrder,$needSyncCreateOrderSn,$needSyncCreateOrderTime){
//                $cRes = $this->saveAll($needSyncCreateOrder);
//                Order::where(['order_sn'=>$needSyncCreateOrderSn])->update(['split_status'=>3,'sync_status'=>1,]);
//                return $cRes;
//            });
//        }

        if (empty($orderList)) {
            return true;
        }
        //获取全部正常订单编号
        $allOrderSn = array_column($orderList, 'order_sn');
        //获取相对应的已存在的发货订单列表
        $shipOrderLists = $this->list(['searOrderSn' => $allOrderSn, 'notTimeFormat' => true, 'notLimitOrderStatus' => true]);
        $shipOrderList = $shipOrderLists['list'];

        //发货订单的合并订单
        $shipMergeOrder = $shipOrderLists['mergeOrder'] ?? [];
        //发货订单的拆分总订单
        $shipSplitOrder = $shipOrderLists['splitOrder'] ?? [];
        //发货订单的合并子订单
        $shipSplitChildOrder = $shipOrderLists['splitChildOrder'] ?? [];
        //发货订单的合并父订单
        $shipSplitParentOrder = $shipOrderLists['splitParentOrder'] ?? [];
        //获取全部发货订单编号
        $shipOrderSn = array_column($shipOrderList, 'order_sn');

//        dump($orderList);
//        dump($shipSplitOrder);
//        dump($shipMergeOrder);
//        dump($shipOrderList);
//        dump($needSyncCreateOrder);
        //查看已存在的发货订单是否需要更新
        $needUpdateShipOrder = [];
        if (!empty($shipOrderList)) {
            $needCount = 0;
            foreach ($shipOrderList as $key => $sValue) {
                //如果是已经关闭的订单不需要再更新了,直接跳过本次循环
                if ($sValue['order_status'] == -3) {
                    unset($shipOrderList[$key]);
                    continue;
                }
                $value = $orderList[$sValue['order_sn']] ?? [];
                //如果当前的订单编号不存在正常订单里面,很有可能是子订单,直接拿发货订单的数据填充即可
                if (empty($value) && !empty($shipOrderList[$sValue['order_sn']])) {
                    $value = $sValue;
                }
                //判断订单更新时间是否一致,不一致则需要更新
                if ($value['sync_status'] == 1 && $value['update_time'] != $sValue['order_update_time']) {
                    //如果是正常订单则更新订单信息
                    if ($sValue['split_status'] == 3) {
                        $updateOrder = $value;
                        $updateOrder['order_update_time'] = $updateOrder['update_time'];
                        unset($updateOrder['update_time']);
                        unset($shipOrderList[$key]);
                        //添加更新数据和条件进去待更新数组
                        $needUpdateShipOrder[$needCount]['map'] = ['order_sn' => $updateOrder['order_sn']];
                        $needUpdateShipOrder[$needCount]['data'] = $updateOrder;

                    } elseif ($sValue['split_status'] == 1) {
                        //拆单的情况下先获取原先发货订单包含的商品内容,数量和订单信息

                        //$splitChildOrderCount = count($shipSplitOrder[$value['order_sn']] ?? []);
                        $allSkuNumber = count(array_column($value['goods'], 'sku_sn'));
                        if (empty($sValue['parent_order_sn'])) {
                            $splitParentOrder[$sValue['order_sn']] = $sValue;
                            $splitCount = count($shipSplitParentOrder[$value['order_sn']] ?? []);
                        } else {
                            $splitParentOrder[$sValue['parent_order_sn']] = $sValue;
                            $splitCount = count($shipSplitChildOrder[$value['order_sn']] ?? []);
                        }

                        //不需要更新
                        if ($sValue['order_update_time'] == $value['update_time']) {
                            continue;
                        }

                        $asOrder1 = $value;
                        //直接重置之前的子父订单的内容,重新开始计算
                        $asOrder1['item_count'] = 0;
                        $asOrder1['used_integral'] = 0;
                        $asOrder1['total_price'] = 0;
                        $asOrder1['fare_price'] = 0;
                        $asOrder1['discount_price'] = 0;
                        $asOrder1['real_pay_price'] = 0;
                        $asOrder1['goods_sku'] = [];
                        $asOrder1['skus'] = [];
                        //判断父子订单各自原本包含的商品,如果新更新的商品包含在原来老的就新增
                        if (empty($sValue['parent_order_sn'])) {
                            if (!empty($splitParentOrder[$sValue['order_sn']]) && !empty($splitParentOrder[$sValue['order_sn']]['goods'])) {
                                $goods = $splitParentOrder[$sValue['order_sn']]['goods'];
                            }
                            $oldGoods = $shipSplitParentOrder[$sValue['order_sn']] ?? [];
                        } else {
                            $goods = $goods ?? $splitParentOrder[$sValue['parent_order_sn']]['goods'];
                            $oldGoods = $shipSplitChildOrder[$value['order_sn']] ?? [];
                        }
                        //如果原先父类商品只有一件,则为拆分数量的订单,只累加件数,不累加金额
                        //如果是多件则按照正常的流程走,对应的SKU累加件数和金额
                        if (count($goods) > 1) {
                            foreach ($goods as $nKey => $nValue) {
                                if (in_array($nValue['sku_sn'], $oldGoods)) {
                                    $asOrder1['item_count'] += $nValue['count'];
                                    $asOrder1['total_price'] += $nValue['total_price'];
                                    $asOrder1['fare_price'] += $nValue['total_fare_price'];
                                    $asOrder1['discount_price'] += $nValue['all_dis'];
                                    $asOrder1['real_pay_price'] += $nValue['real_pay_price'];
                                    $asOrder1['skus'][] = $nValue['sku_sn'];
                                    $asOrder1['goods_sku'] = implode(',', $asOrder1['skus']);
                                    if ($asOrder1['item_count'] >= $nValue['count']) {
                                        unset($goods[$nKey]);
                                    }
                                }
                            }
                        } else {
                            $goods = $value['goods'];
                            foreach ($goods as $nKey => $nValue) {
                                if (in_array($nValue['sku_sn'], $oldGoods)) {
                                    $asOrder1['item_count'] += $sValue['item_count'];
                                    $asOrder1['total_price'] = $nValue['total_price'];
                                    $asOrder1['fare_price'] = $nValue['total_fare_price'];
                                    $asOrder1['discount_price'] = $nValue['all_dis'];
                                    $asOrder1['real_pay_price'] = $nValue['real_pay_price'];
                                    $asOrder1['skus'][] = $nValue['sku_sn'];
                                    $asOrder1['goods_sku'] = implode(',', $asOrder1['skus']);
                                    if ($asOrder1['item_count'] == $nValue['count']) {
                                        unset($goods[$nKey]);
                                    }
                                }
                            }

                        }
                        if (!empty($asOrder1)) {
                            unset($shipOrderList[$key]);
                        }
                        $asOrder1['order_update_time'] = $value['update_time'];
                        unset($asOrder1['update_time']);
                        //添加更新数据和条件进去待更新数组
                        $needUpdateShipOrder[$needCount]['map'] = ['order_sn' => $asOrder1['order_sn']];
                        $needUpdateShipOrder[$needCount]['data'] = $asOrder1;

                    } elseif ($value['split_status'] == 2) {
                        //合单的订单仅更新对应的收货信息和订单备注,如果发生了售后导致商品减少,在退款成功后会自动去除订单对应的商品,这里的更新不做商品的操作
                        if ($value['update_time'] != $sValue['order_update_time']) {
                            //仅更新收货信息和订单备注
                            $asOrder2['shipping_name'] = $value['shipping_name'];
                            $asOrder2['shipping_phone'] = $value['shipping_phone'];
                            $asOrder2['shipping_address'] = $value['shipping_address'];
                            $asOrder2['shipping_code'] = $value['shipping_code'];
                            $asOrder2['order_sign'] = $value['order_sign'];
                            $asOrder2['order_remark'] = $value['order_remark'];
                            $asOrder2['seller_remark'] = $value['seller_remark'];
                            $asOrder2['order_update_time'] = $value['update_time'];

                            //添加更新数据和条件进去待更新数组
                            $needUpdateShipOrder[$needCount]['map'] = ['order_sn' => $sValue['order_sn']];
                            $needUpdateShipOrder[$needCount]['data'] = $asOrder2;

                        }


                    }
                    $needCount++;
                }
                if (isset($shipOrderList[$key])) {
                    unset($shipOrderList[$key]);
                }
            }
        }
        if (!empty($needUpdateShipOrder)) {
            //更新订单
            $updateRes = Db::transaction(function () use ($needUpdateShipOrder) {
                $orderModel = (new Order());
                foreach ($needUpdateShipOrder as $key => $value) {
                    //修改发货订单需要更新的信息
                    $uRes = self::update($value['data'], $value['map']);
                    if (!empty($value['data']['order_update_time'])) {
                        //修改正常订单同步时间
                        $oRes = $orderModel->isAutoWriteTimestamp(false)->where($value['map'])->save(['sync_order_update_time' => $value['data']['order_update_time']]);
                    }

                }
                return $uRes;
            });
        }

        return true;

    }

    /**
     * @title  查询两次同步周期过程中新增的正常订单中多商品的订单(为自动拆单做准备)
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function checkSyncMoreGoodsOrder(array $data)
    {
        $orderSn = $data['order_sn'] ?? null;
        $timeStart = $data['start_time'] ?? null;
        $timeEnd = $data['end_time'] ?? null;
        if (!empty($timeStart) && !empty($timeEnd)) {
            $oMap[] = ['sync_first_time', '>=', strtotime($timeStart)];
            $oMap[] = ['sync_first_time', '<=', strtotime($timeEnd)];
        } else {
            //查询最后一次同步的时间差
            $timeBetween = OperationLog::where(['path' => 'ship/sync'])->order('create_time desc')->limit(2)->column('create_time');
            if (empty($timeBetween)) {
                throw new ShipException(['msg' => '请至少先同步一次订单~']);
            }
            $oMap[] = ['sync_first_time', '<=', current($timeBetween)];
            if (!empty($timeBetween[1])) {
                $oMap[] = ['sync_first_time', '>=', $timeBetween[1] + 10];
            }
        }
        $oMap[] = ['order_status', 'in', [2]];
        $oMap[] = ['after_status', 'in', [1, 5, -1]];
        $oMap[] = ['split_status', '=', 3];
        if (!empty($orderSn)) {
            if (is_array($orderSn)) {
                $oMap[] = ['order_sn', 'in', $orderSn];
            } else {
                $oMap[] = ['order_sn', '=', $orderSn];
            }
        }
        $oMap[] = ['status', '=', 1];
        //查询多商品的订单
        $oMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('goods_sku', ','))];

        $list = ShipOrder::where($oMap)->field('order_sn,parent_order_sn,split_status,goods_sku,sync_first_time,create_time,update_time,item_count,status,uid,user_phone,order_type,sync_status')->order('create_time desc')->select()->toArray();
        return $list ?? [];
    }

    public function newOrEdit(array $map, array $data)
    {
        return $this->validate(false)->updateOrCreate($map, $data);
    }

    public function parent()
    {
        return $this->hasOne(get_class($this), 'order_sn', 'parent_order_sn')->bind(['parent_order_sort' => 'order_sort']);
    }

    public function childOrderSn()
    {
        return $this->hasMany(get_class($this), 'parent_order_sn', 'order_sn')->where(['status' => 1])->field('order_sn,parent_order_sn');
    }

    public function childOrder()
    {
        return $this->hasMany(get_class($this), 'parent_order_sn', 'order_sn')->where(['status' => 1])->field('uid,order_sn,parent_order_sn,delivery_time,shipping_name,shipping_phone,shipping_address,shipping_code,split_status,order_status,after_status');
    }

    public function goods()
    {
        return $this->hasMany('OrderGoods', 'order_sn', 'order_sn');
    }

    public function attach()
    {
        return $this->hasOne('OrderAttach', 'order_sn', 'order_sn')->where(['status' => [1, 2]]);
    }

}