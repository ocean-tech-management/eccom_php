<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 订单模块]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\MemberException;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\ShipException;
use app\lib\job\TimerForOrder;
use app\lib\services\CodeBuilder;
use app\lib\services\Shipping;
use app\lib\services\Divide as DivideService;
use app\lib\services\Order as OrderService;
use Exception;
use think\facade\Db;
use think\facade\Queue;

class Order extends BaseModel
{
    protected $field = ['order_sn', 'order_belong', 'order_type', 'pay_no', 'uid', 'user_phone', 'item_count', 'used_integral', 'total_price', 'fare_price', 'discount_price', 'real_pay_price', 'pay_type', 'pay_status', 'order_status', 'pay_time', 'delivery_time', 'close_time', 'end_time', 'shipping_name', 'address_id', 'order_remark', 'seller_remark', 'shipping_address', 'shipping_phone', 'shipping_address', 'shipping_code', 'vdc_allow', 'order_sign', 'split_status', 'sync_status', 'sync_order_update_time', 'after_status', 'after_number', 'link_superior_user', 'link_child_team_code', 'link_team_code', 'user_level', 'coder_remark', 'divide_chain', 'team_top_user', 'handsel_sn','can_sync','crowd_key','shipping_address_detail','advance_buy','is_exchange','crowd_fuse_status','crowd_fuse_type','crowd_fuse_time','pay_channel'];
    protected $validateFields = ['order_sn', 'order_belong', 'user_phone', 'total_price', 'real_pay_price'];
    private $belong = 1;

    /**
     * @title  订单列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        $map = [];
        $list = [];
        $needSearGoods = false;
        $needSearUser = false;
        $needSearSupplierGoods = false;
        $supplierAllSku = [];

        //查找订单编号
        if (!empty($sear['searOrderSn'])) {
            $map[] = ['a.order_sn', '=', $sear['searOrderSn']];
        }
        if (!empty($sear['searUserName'])) {
            $map[] = ['b.name', '=', $sear['searUserName']];
            $needSearUser = true;
        }
        if (!empty($sear['searUserPhone'])) {
            $map[] = ['a.user_phone', '=', $sear['searUserPhone']];
        }
        //订单类型
        if (!empty($sear['order_type'])) {
            $map[] = ['a.order_type', '=', $sear['order_type']];
        }
        //不查找的订单类型
        if (!empty($sear['not_order_type'])) {
            $map[] = ['a.order_type', 'not in', $sear['not_order_type']];
        }
        //查找指定众筹订单
        if (!empty($sear['crowd_key'])) {
            $map[] = ['a.crowd_key', '=', $sear['crowd_key']];
        }
        //订单状态
        if (!empty($sear['searType'])) {
            if (!is_array($sear['searType'])) {
                $sear['searType'] = [$sear['searType']];
            }
            $map[] = ['a.order_status', 'in', $sear['searType'] ?? [1, 2, 5, 6]];
        }

        //售后状态
        if (!empty($sear['afterType'])) {
            $map[] = ['a.after_status', 'in', $sear['afterType'] ?? [1, 2, 3, 5, -1]];
        }
        //订单标识
        if (!empty($sear['order_sign'])) {
            $map[] = ['a.order_sign', 'in', $sear['order_sign']];
        }
        //订单归属
        if (!empty($sear['order_belong'])) {
            $map[] = ['a.order_belong', '=', $sear['order_belong']];
        }
        //支付类型
        if (!empty($sear['pay_type'])) {
            $map[] = ['a.pay_type', '=', $sear['pay_type']];
        }
        //支付商通道类型
        if (!empty($sear['pay_channel'])) {
            $map[] = ['a.pay_channel', '=', $sear['pay_channel']];
        }
        //查找商品
        if (!empty($sear['searGoodsSpuSn'])) {
            if (is_array($sear['searGoodsSpuSn'])) {
                $map[] = ['c.goods_sn', 'in', $sear['searGoodsSpuSn']];
            } else {
                $map[] = ['c.goods_sn', '=', $sear['searGoodsSpuSn']];
            }
            $needSearGoods = true;
        }
        if (!empty($sear['searGoodsSkuSn'])) {
            if (is_array($sear['searGoodsSkuSn'])) {
                $map[] = ['c.sku_sn', 'in', $sear['searGoodsSkuSn']];
            } else {
                $map[] = ['c.sku_sn', '=', $sear['searGoodsSkuSn']];
            }
            $needSearGoods = true;
        }

        //通过上级信息查找订单
        if (!empty($sear['topUserPhone'])) {
            if (empty($sear['searType'] ?? null)) {
                $sear['searType'] = 1;
            }
            //topUserType 1为查找直属上级 2为团队顶级用户 3为查询分润第一人冗余结构
            $topUser = User::where(['phone' => trim($sear['topUserPhone']), 'status' => $this->getStatusByRequestModule($sear['searType'] ?? 1)])->order('create_time desc')->column('uid');
            if (empty($topUser)) {
                throw new ServiceException(['msg' => '查无该上级用户']);
            }
            switch ($sear['topUserType'] ?? 2) {
                case 1:
                    $map[] = ['a.link_superior_user', 'in', $topUser];
                    break;
                case 2:
                    $topUser = current($topUser);
                    $map[] = ['', 'exp', Db::raw("find_in_set('$topUser',a.team_top_user)")];
                    break;
                case 3:
                    $topUser = implode('|', $topUser);
                    //正则查询,不用find_in_set是因为divide_chain字段不是用逗号分隔的
                    //支持多个人,只要$topUser用|分割开就可以了
                    $map[] = ['', 'exp', Db::raw('a.divide_chain REGEXP ' . "'" . $topUser . "'")];
                    break;

            }
        }

        //如果都没有搜索商品,供应商只查对应的商品信息
        if (empty($sear['searGoodsSkuSn']) && empty($sear['searGoodsSpuSn'])) {
            if (!empty($sear['adminInfo'])) {
                $supplierCode = $sear['adminInfo']['supplier_code'] ?? null;
                if (!empty($supplierCode)) {
                    $supplierAllSku = GoodsSku::where(['supplier_code' => $supplierCode, 'status' => 1])->column('sku_sn');
                    if (empty($supplierAllSku)) {
                        throw new ShipException(['msg' => '暂无属于您的供货商品']);
                    }
                    $map[] = ['c.sku_sn', 'in', $supplierAllSku];
                    $needSearGoods = true;
                    $needSearSupplierGoods = true;
                }
            }
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
        //众筹订单的筛选
        if (!empty($sear['order_type'] ?? null) && $sear['order_type'] == 6) {
            //筛选众筹订单的区轮期
            if (!empty($sear['searCrowdKey'] ?? null)) {
                $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.crowd_key', $sear['searCrowdKey']))];
            }
            //查询是否为熔断订单
            if (!empty($sear['searCrowdFuse'] ?? null)) {
                $map[] = ['crowd_fuse_status', '=', $sear['searCrowdFuse']];
            }
            //查询熔断选择方案订单
            if (!empty($sear['searCrowdFuseType'] ?? null)) {
                $map[] = ['crowd_fuse_type', '=', $sear['searCrowdFuseType']];
            }
        }



        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            if ((strtotime($sear['end_time']) - strtotime($sear['end_time'])) > (3600 * 24 * 30)) {
                throw new OrderException(['msg' => '查询订单时间请按照月查询']);
            }
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
        }

        if (!empty($sear['delivery_start_time']) && !empty($sear['delivery_end_time'])) {
            $map[] = ['a.end_time', '>=', strtotime($sear['delivery_start_time'])];
            $map[] = ['a.end_time', '<=', strtotime($sear['delivery_end_time'])];
        }

        if (!empty($sear['orderField'])) {
            $orderField = $sear['orderField'];
        } else {
            $orderField = 'a.create_time desc';
        }
        $nextSqlOrderField = str_replace('a.', '', $orderField) ?? 'create_time desc';

        $columnSear = false;
        //字段对比
        if (!empty($sear['columnSear'])) {
            $columnSear = true;
        }
        $page = intval($sear['page'] ?? 0) ?: null;
        $pageNumber = !empty($sear['pageNumber']) ? intval($sear['pageNumber']) : $this->pageNumber;
        if (!empty($sear['notLimit'])) {
            $page = null;
        }
        if (!empty($page)) {
            if ($needSearGoods) {
                if (!empty($sear['searGoodsSpuSn'])) {
                    if (is_array($sear['searGoodsSpuSn'])) {
                        $goodsMap[] = ['goods_sn', 'in', $sear['searGoodsSpuSn']];
                    } else {
                        $goodsMap[] = ['goods_sn', '=', $sear['searGoodsSpuSn']];
                    }
                }
                if (!empty($sear['searGoodsSkuSn'])) {
                    if (is_array($sear['searGoodsSkuSn'])) {
                        $goodsMap[] = ['sku_sn', 'in', $sear['searGoodsSkuSn']];
                    } else {
                        $goodsMap[] = ['sku_sn', '=', $sear['searGoodsSkuSn']];
                    }
                }
                if ((empty($sear['searGoodsSpuSn']) && empty($sear['searGoodsSkuSn'])) && !empty($needSearSupplierGoods)) {
                    if (is_array($supplierAllSku)) {
                        $goodsMap[] = ['sku_sn', 'in', $supplierAllSku];
                    } else {
                        $goodsMap[] = ['sku_sn', '=', $supplierAllSku];
                    }
                }

//                $goodsMap[] = ['status', '=', 1];
                if (!empty($sear['order_goods_status'])) {
                    if (is_array($sear['order_goods_status'])) {
                        $goodsMap[] = ['status', 'in', $sear['order_goods_status']];
                    } else {
                        $goodsMap[] = ['status', '=', $sear['order_goods_status']];
                    }
                }
//                $aTotals = Db::name('order_goods')->where($goodsMap)->field('id')->group('order_sn')->buildSql();
                $goodsOrder = OrderGoods::where($goodsMap)->group('order_sn')->column('order_sn');
                if (!empty($goodsOrder)) {
                    $tMap = $map;
                    $searOrder = array_unique(array_filter($goodsOrder));
                    foreach ($tMap as $key => $value) {
                        //剔除原来map条件的联表的c表
                        if (strpos(current($value), 'c.') !== false) {
                            unset($tMap[$key]);
                        }
                    }
                    //如果没有筛选订单号才默认筛选对应商品的订单号
                    if (empty($sear['searOrderSn'])) {
                        foreach ($tMap as $key => $value) {
                            //提出联表的c表和原本的订单号搜索
                            if (current($value) == 'a.order_sn') {
                                unset($tMap[$key]);
                            }
                        }
                        $tMap = array_values($tMap);
                        $searOrder[] = $sear['searOrderSn'];
                        $tMap[] = ['a.order_sn', 'in', $searOrder];
                    }
                } else {
                    throw new ServiceException(['msg' => '无法查找到对应商品']);
                }
                $aTotals = Db::name('order')->alias('a')
                    ->when($needSearUser, function ($query) {
                        $query->join('sp_user b', 'a.uid = b.uid', 'left');
                    })
                    ->where($tMap)
                    ->when($columnSear, function ($query) use ($sear) {
                        $columnSear = $sear['columnSear'];
                        $query->whereColumn($columnSear[0], $columnSear[1], $columnSear[2]);
                    })
                    ->field('a.id')
                    ->buildSql();
            } else {
                $aTotals = Db::name('order')->alias('a')
                    ->when($needSearUser, function ($query) {
                        $query->join('sp_user b', 'a.uid = b.uid', 'left');
                    })
                    ->where($map)
                    ->when($columnSear, function ($query) use ($sear) {
                        $columnSear = $sear['columnSear'];
                        $query->whereColumn($columnSear[0], $columnSear[1], $columnSear[2]);
                    })
                    ->field('a.id')
                    ->buildSql();
            }

            $aTotal = Db::table($aTotals . " a")->value('count(*)');

            $pageTotal = ceil($aTotal / $pageNumber);
        }

        $memberTitle = MemberVdc::where(['status' => [1, 2]])->column('name', 'level');
        $memberTitle[0] = '普通用户';
        if(!empty($needSearUser ?? false)){
            $aListField = "a.id,a.uid,b.name as user_name";
        }else{
            $aListField = "a.id,a.uid";
        }
        //先找id,因为id有索引比较快,然后再根据id查找具体的数据
        $aList = Db::name('order')->alias('a')
            ->when($needSearUser, function ($query) {
                $query->join('sp_user b', 'a.uid = b.uid', 'left');
            })
            ->when($needSearGoods, function ($query) {
                $query->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left');
            })
            ->where($map)
            ->field($aListField)
            ->order($orderField)
            ->when($page, function ($query) use ($page, $pageNumber) {
                $query->page($page, $pageNumber);
            })
            ->when($columnSear, function ($query) use ($sear) {
                $columnSear = $sear['columnSear'];
                $query->whereColumn($columnSear[0], $columnSear[1], $columnSear[2]);
            })
//            ->buildSql();
            ->select()->toArray();

        if (!empty($aList)) {
            $aMap[] = ['id', 'in', array_column($aList, 'id')];
            $selectField = "distinct(order_sn),id,order_belong,order_type,uid,user_phone,pay_no,item_count,total_price,fare_price,discount_price,real_pay_price,pay_type,pay_status,order_status,create_time,pay_time,end_time,shipping_code,shipping_name,shipping_address,shipping_phone,shipping_type,link_team_code,link_child_team_code,sync_status,after_status,split_status,order_remark,seller_remark,order_sign,update_time,sync_order_update_time,shipping_status,divide_chain,team_top_user,user_level,link_superior_user,handsel_sn,end_time as delivery_time,can_sync,crowd_key,advance_buy,is_exchange,pay_channel";
            if (!empty($sear['order_type'] ?? null) && $sear['order_type'] == 6) {
                $selectField .= ',crowd_fuse_status,crowd_fuse_type,crowd_fuse_time';
            }
            $list = Db::name('order')->where($aMap)->field($selectField)->order($nextSqlOrderField)->select()->each(function ($item, $key) use ($sear, $memberTitle) {
                if (empty($sear['notTimeFormat'])) {
                    $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                    if (!empty($item['pay_time'])) {
                        $item['pay_time'] = date('Y-m-d H:i:s', $item['pay_time']);
                    }
                    if (!empty($item['end_time'])) {
                        $item['end_time'] = date('Y-m-d H:i:s', $item['end_time']);
                    }
                    if (!empty($item['delivery_time'])) {
                        $item['delivery_time'] = date('Y-m-d H:i:s', $item['delivery_time']);
                    }
                    if (!empty($item['crowd_fuse_time'])) {
                        $item['crowd_fuse_time'] = date('Y-m-d H:i:s', $item['crowd_fuse_time']);
                    }
                }
                if (!empty($item['user_level'])) {
                    $item['user_level_name'] = $memberTitle[$item['user_level']] ?? '未知身份';
                }
                return $item;
            })->toArray();

            if (!empty($list)) {
                $allUserName = User::where(['uid'=>array_column($aList, 'uid')])->column('name','uid');
                foreach ($list as $key => $value) {
                    foreach ($aList as $uList => $uValue) {
                        if ($value['id'] == $uValue['id']) {
//                            $list[$key]['user_name'] = $uValue['user_name'];
                            $list[$key]['user_name'] = $allUserName[$uValue['uid']] ?? '未知用户';
                        }
                    }
                }
            }
        }

        if (!empty($list)) {
            $orderSns = array_column($list, 'order_sn');
            foreach ($list as $key => $value) {
                $list[$key]['pt_activity_status'] = null;
                $list[$key]['activity_sn'] = null;
                $list[$key]['activity_type'] = null;
                $list[$key]['pt_user_role'] = null;
                $list[$key]['pt_group_number'] = null;
                $list[$key]['pt_start_time'] = null;
                $list[$key]['pt_end_time'] = null;
                $list[$key]['pt_success_number'] = 0;
            }

            //补齐拼团订单信息
            $ptList = PtOrder::with(['user'])->where(['order_sn' => $orderSns])->order('user_role asc,create_time asc')->select()->toArray();
            $allActSn = array_unique(array_column($ptList, 'activity_sn'));
//            $successNumber = PtOrder::where(['activity_sn' => $allActSn])->field('activity_sn,count(id) as number')->group('activity_sn')->select()->toArray();
            $successNumber = PtOrder::with(['user'])->where(['activity_sn' => $allActSn])->order('user_role asc,create_time asc')->select()->toArray();
            $success = [];
            if (!empty($successNumber)) {
                foreach ($successNumber as $key => $value) {
                    if ($value['activity_status'] == 2) {
                        $successActivity[$value['activity_sn']][] = $value['order_sn'];
                    }
                }
                if (!empty($successActivity)) {
                    foreach ($successActivity as $key => $value) {
                        $success[$key] = count($value);
                    }
                }
            }
            $ptGroupList = [];
            if (!empty($ptList)) {
                if (!empty($successNumber)) {
                    foreach ($successNumber as $key => $value) {
                        $ptGroupList[$value['activity_sn']][] = $value;
                    }
                }

                foreach ($list as $key => $value) {
                    foreach ($ptList as $ptKey => $ptValue) {
                        if ($ptValue['order_sn'] == $value['order_sn']) {
                            $list[$key]['pt_activity_status'] = $ptValue['activity_status'];
                            $list[$key]['activity_sn'] = $ptValue['activity_sn'];
                            $list[$key]['activity_type'] = $ptValue['activity_type'];
                            $list[$key]['pt_user_role'] = $ptValue['user_role'];
                            $list[$key]['pt_group_number'] = $ptValue['group_number'];
                            $list[$key]['pt_start_time'] = $ptValue['start_time'];
                            $list[$key]['pt_end_time'] = $ptValue['end_time'];
                            if (!empty($ptGroupList) && !empty($ptGroupList[$ptValue['activity_sn']])) {
                                $list[$key]['pt_group'] = $ptGroupList[$ptValue['activity_sn']];
                            }
                            $list[$key]['pt_success'] = false;
                            if (!empty($success) && !empty($success[$ptValue['activity_sn']])) {
                                $list[$key]['pt_success_number'] = $success[$ptValue['activity_sn']] ?? 0;
                                if ($success[$ptValue['activity_sn']] == $list[$key]['pt_group_number']) {
                                    $list[$key]['pt_success'] = true;
                                }
                            }
                        }
                    }
                }
            }

            //补齐商品
            if (!empty($sear['order_goods_status'])) {
                if (is_array($sear['order_goods_status'])) {
                    $gMap[] = ['status', 'in', $sear['order_goods_status']];
                } else {
                    $gMap[] = ['status', '=', $sear['order_goods_status']];
                }
            }
            $gMap[] = ['order_sn', 'in', $orderSns];
            $orderGoods = OrderGoods::where($gMap)->field('order_sn,group_concat(title) as all_goods_title,group_concat(sku_sn) as all_goods_sku,group_concat(distinct goods_sn) as all_goods_spu,sum(refund_price) as all_refund_price,sum(total_price) as all_total_price')->group('order_sn')->select()->toArray();
            if (!empty($orderGoods)) {
                foreach ($list as $key => $value) {
                    foreach ($orderGoods as $goodsKey => $goodsValue) {
                        //AfterSaleType = 0表示暂无退款 1表示全部退款 2为部分退款
                        if ($value['order_sn'] == $goodsValue['order_sn']) {
                            $list[$key]['all_goods_title'] = $goodsValue['all_goods_title'];
                            $list[$key]['all_goods_sku'] = $goodsValue['all_goods_sku'];
                            $list[$key]['all_goods_spu'] = $goodsValue['all_goods_spu'];
                            $list[$key]['all_refund_price'] = $goodsValue['all_refund_price'] ?? 0;
                            $list[$key]['all_total_price'] = $goodsValue['all_total_price'] ?? 0;
                            $list[$key]['AfterSaleType'] = 0;
                            //判断退款类型
                            if (!empty(doubleval($list[$key]['all_refund_price']))) {
                                $list[$key]['AfterSaleType'] = 1;
                                if ((string)$list[$key]['all_refund_price'] < (string)$list[$key]['all_total_price']) {
                                    $list[$key]['AfterSaleType'] = 2;
                                }
                            }
                        }
                    }
                }
            }
            //补齐海外购附加条件
            $attach = OrderAttach::where(['order_sn' => $orderSns, 'status' => 1])->withoutField('id,update_time,create_time,status')->select()->toArray();
            if (!empty($attach)) {
                foreach ($list as $key => $value) {
                    $list[$key]['is_attach_order'] = false;
                    $list[$key]['attach'] = [];
                    foreach ($attach as $aKey => $aValue) {
                        if ($aValue['order_sn'] == $value['order_sn']) {
                            $list[$key]['is_attach_order'] = true;
                            $list[$key]['attach'] = $aValue;
                        }
                    }
                }
            }

            //补齐顶级团队长信息
            $teamTopUid = array_unique(array_filter(array_column($list, 'team_top_user')));

            if (!empty($teamTopUid)) {
                $teamTopUserList = User::where(['uid' => $teamTopUid, 'status' => [1, 2]])->field('uid,name,phone,vip_level')->select()->each(function ($item) use ($memberTitle) {
                    $item['vip_name'] = $memberTitle[$item['vip_level']] ?? '未知身份';
                })->toArray();

                if (!empty($teamTopUserList)) {
                    foreach ($teamTopUserList as $key => $value) {
                        $teamTopUserInfo[$value['uid']] = $value;
                    }
                }
                foreach ($list as $key => $value) {
                    $list[$key]['team_top_user_info'] = [];
                    if (!empty($value['team_top_user'])) {
                        $list[$key]['team_top_user_info'] = $teamTopUserInfo[$value['team_top_user']] ?? [];
                    }
                }
            }

            //补齐订单关联人
            $linkUid = array_unique(array_filter(array_column($list, 'link_superior_user')));

            if (!empty($linkUid)) {
                $linkUserList = User::where(['uid' => $linkUid, 'status' => [1, 2]])->field('uid,name,phone,vip_level')->select()->each(function ($item) use ($memberTitle) {
                    $item['vip_name'] = $memberTitle[$item['vip_level']] ?? '未知身份';
                })->toArray();

                if (!empty($linkUserList)) {
                    foreach ($linkUserList as $key => $value) {
                        $linkUserInfo[$value['uid']] = $value;
                    }
                }
                foreach ($list as $key => $value) {
                    $list[$key]['link_user_info'] = [];
                    if (!empty($value['link_superior_user'])) {
                        $list[$key]['link_user_info'] = $linkUserInfo[$value['link_superior_user']] ?? [];
                    }
                }
            }

            //补齐众筹专区的信息
            $allCrowd = [];
            foreach ($list as $key => $value) {
                if (!empty($value['crowd_key'] ?? null)) {
                    $Crowd = null;
                    $Crowd = explode('-', $value['crowd_key']);
                    $allCrowd[] = $Crowd[0];
                    $list[$key]['crowd_code'] = $Crowd[0];
                    $list[$key]['crowd_round_number'] = $Crowd[1];
                    $list[$key]['crowd_period_number'] = $Crowd[2];
                }
            }
            if (!empty($allCrowd)) {
                $allCrowdActivityInfo = CrowdfundingActivity::where(['activity_code' => array_unique($allCrowd)])->column('title', 'activity_code');
                if (!empty($allCrowdActivityInfo)) {
                    foreach ($list as $key => $value) {
                        if (!empty($value['crowd_code'] ?? null)) {
                            $list[$key]['crowd_activity_title'] = $allCrowdActivityInfo[$value['crowd_code']] ?? '未知区';
                        }
                    }
                }
            }
        }

        //筛选剔除订单中拼团类型未完成拼团的订单
        if (!empty($sear['checkNotCompletePtOrder'])) {
            if (!empty($list)) {
                foreach ($list as $key => $value) {
                    if ($value['order_type'] == 2 && (intval($value['pt_activity_status']) != 2)) {
                        unset($list[$key]);
                    }
                }
            }
        }

        //获取订单商品数组
        if (!empty($sear['needGoods'])) {
            if (!empty($list)) {
                //补齐商品
                if (!empty($sear['order_goods_status'] ?? null)) {
                    if (is_array($sear['order_goods_status'])) {
                        $gtMap[] = ['status', 'in', $sear['order_goods_status']];
                    } else {
                        $gtMap[] = ['status', '=', $sear['order_goods_status']];
                    }
                }
                $gtMap[] = ['order_sn', 'in', array_column($list, 'order_sn')];
                $allOrderGoods = (new OrderGoods())->where($gtMap)->withoutField('id,desc')->order('create_time desc')->select()->toArray();
                if (!empty($allOrderGoods)) {
                    foreach ($list as $key => $value) {
                        foreach ($allOrderGoods as $k => $v) {
                            if ($v['order_sn'] == $value['order_sn']) {
                                if (empty($sear['goodsNormalKey'])) {
                                    $list[$key]['goods'][] = $v;
                                } else {
                                    $list[$key]['goods'][$v['sku_sn']] = $v;
                                }
                            }
                        }
                    }
                }

            }
        }

        //展示参与分润的用户信息
        if (!empty($sear['needDivideDetail'])) {
            if (!empty($list)) {
                //供应商不允许看到分润信息
                if (!empty($sear['adminInfo']) && !empty($sear['adminInfo']['supplier_code'])) {
                    foreach ($list as $key => $value) {
                        $list[$key]['divideTopUser'] = [];
                    }
                } else {
                    $aOrderSn = array_column($list, 'order_sn');
                    $allDivideSql = Divide::with(['linkUser'])->where(['order_sn' => $aOrderSn, 'status' => [1, 2]])->field('link_uid,order_sn,order_uid,level,real_divide_price')->order('level asc,divide_type desc,vdc_genre desc,real_divide_price asc')->select()->toArray();

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
        }

        //展示分润第一人的冗余信息
        if (!empty($sear['needDivideChain'] ?? null)) {
            if (!empty($list)) {
                foreach ($list as $key => $value) {
                    $list[$key]['divide_chain_info'] = [];
                    $list[$key]['divide_chain_user_info'] = [];
                    $aDivideChain = [];
                    if (!empty($value['divide_chain'])) {
                        $aDivideChain = json_decode($value['divide_chain'], true);;
                        $list[$key]['divide_chain_info'] = $aDivideChain;
                        if (empty($divideChain ?? [])) {
                            $divideChain = array_keys($aDivideChain);
                        } else {
                            $divideChain = array_merge_recursive($divideChain, array_keys($aDivideChain));
                        }
                    }
                }
                if (!empty($divideChain)) {
                    $aUid = $divideChain;
                    $allDivideChainUser = (new User())->where(['uid' => $aUid, 'status' => [1, 2]])->field('uid,name,phone,vip_level')->order('create_time desc')->select()->each(function ($item) use ($memberTitle) {
                        $item['vip_name'] = $memberTitle[$item['vip_level']] ?? '未知身份';
                    })->toArray();
                    if (!empty($allDivideChainUser)) {
                        foreach ($allDivideChainUser as $key => $value) {
                            $allDivideChainUserInfo[$value['uid']] = $value;
                        }
                        foreach ($list as $key => $value) {
                            if (!empty($value['divide_chain_info'])) {
                                foreach ($value['divide_chain_info'] as $k => $v) {
                                    $list[$key]['divide_chain_user_info'][$k] = $allDivideChainUserInfo[$k] ?? [];
                                }
                                $list[$key]['divide_chain_user_info'] = array_values($list[$key]['divide_chain_user_info']);
                            }
                        }
                    }
                }
            }
        }

        //展示商品SPU自定义编码
        if (!empty($sear['needGoodsCode'] ?? null)) {
            if (!empty($list)) {
                foreach ($list as $key => $value) {
                    $aGoods = [];
                    if (!empty($value['goods'])) {
                        $aaGoods = array_column($value['goods'], 'goods_sn');
                        if (empty($divideChain ?? [])) {
                            $aGoods = $aaGoods;
                        } else {
                            $aGoods = array_merge_recursive($aGoods, $aaGoods);
                        }
                    }
                }
                if (!empty($aGoods)) {
                    $spuInfo = GoodsSpu::where(['goods_sn' => array_unique($aGoods)])->column('goods_code', 'goods_sn');
                    if (!empty($spuInfo)) {
                        foreach ($list as $key => $value) {
                            if (!empty($value['goods'])) {
                                foreach ($value['goods'] as $k => $v) {
                                    $list[$key]['goods'][$k]['goods_code'] = $spuInfo[$v['goods_sn']] ?? null;
                                }
                            }
                        }
                    }

                }
            }
        }

        //展示众筹订单超前提前购(预售), 奖励延迟发放的信息
        if (!empty($sear['needCrowdDelayRewardOrder'] ?? null) && !empty($list)) {
            $drWhere[] = ['order_sn', 'in', array_unique(array_column($list, 'order_sn'))];
            $drWhere[] = ['status', '=', 1];
            $delayRewardOrder = CrowdfundingDelayRewardOrder::where($drWhere)->withoutField('id')->select()->toArray();
            $periodInfo = [];
            if (!empty($delayRewardOrder ?? [])) {
                foreach ($delayRewardOrder as $key => $value) {
                    $delayRewardOrderInfo[$value['order_sn']] = $value;
                    $delayRewardOrderSn[] = $value['order_sn'];
                    $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                    $delayRewardCrowd[$crowdKey]['activity_code'] = $value['crowd_code'];
                    $delayRewardCrowd[$crowdKey]['round_number'] = $value['crowd_round_number'];
                    $delayRewardCrowd[$crowdKey]['period_number'] = $value['crowd_period_number'];
                }

                //查询对应的期详情, 如果期没有完全成功, 不展示奖励预计发放时间
                if (!empty($delayRewardCrowd ?? [])) {
                    $successPeriod = array_values($delayRewardCrowd);
                    $oWhere[] = ['status', '=', 1];
                    $oWhere[] = ['result_status', '=', 1];
                    $periodList = CrowdfundingPeriod::where(function ($query) use ($successPeriod) {
                        $successPeriod = array_values($successPeriod);
                        foreach ($successPeriod as $key => $value) {
                            ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_code']];
                            ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                            ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                        }
                        for ($i = 0; $i < count($successPeriod); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->where($oWhere)->field('activity_code,round_number,period_number,result_status,buy_status')->select()->toArray();
                    if (!empty($periodList ?? [])) {
                        foreach ($periodList as $key => $value) {
                            $pCrowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                            $periodInfo[$pCrowdKey] = $pCrowdKey;
                        }
                    }
                }

                foreach ($list as $key => $value) {
                    $list[$key]['delay_reward_status'] = -1;
                    $list[$key]['delay_arrival_time'] = null;
                    if ($value['order_type'] == 6 && in_array($value['order_sn'], $delayRewardOrderSn)) {
                        $list[$key]['delay_reward_status'] = $delayRewardOrderInfo[$value['order_sn']]['arrival_status'];
                        $lCrowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                        if (!empty($periodInfo[$lCrowdKey] ?? null)) {
                            $list[$key]['delay_arrival_time'] = timeToDateFormat($delayRewardOrderInfo[$value['order_sn']]['arrival_time'] ?? null);
                        }
                    }
                }
            }
        }

        //展示商品对应的正确物流---未完成
        if (!empty($sear['needGoodsShippingCode'] ?? null)) {
//            if(!empty($list)){
//                $allOrder = array_unique(array_column($list,'order_sn'));
//                $shipOrderList = ShipOrder::where(function($query) use ($allOrder){
//                    $whereOne[] = ['order_sn','in',$allOrder];
//                    $whereTwo[] = ['parent_order_sn','in',$allOrder];
//                    $query->whereOr([$whereOne,$whereTwo]);
//                })->where(['status'=>[1]])->field('order_sn,parent_order_sn,goods_sku,shipping_code,shipping_type,shipping_company_code,shipping_company')->buildSql();
//                dump($shipOrderList);die;
//            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];

    }

    /**
     * @title  用户端订单列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function userList(array $sear = []): array
    {
        $map = [];
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn|b.name|e.title', $sear['keyword']))];
        }
        if (!empty($sear['searType'])) {
            $map[] = ['a.order_status', 'in', $sear['searType'] ?? [1, 2]];
        }
        if (!empty($sear['order_belong'])) {
            $map[] = ['a.order_belong', '=', $sear['order_belong']];
        }
        if (!empty($sear['split_status'])) {
            $map[] = ['a.split_status', '=', $sear['split_status']];
        }
        if ($this->module == 'api') {
            $map[] = ['a.uid', '=', $sear['uid']];
            //默认显示支付成功的订单
            //$map[] = ['a.order_status','=',2];
            if (!empty($sear['searType'])) {
                $map[] = ['a.order_status', '=', $sear['searType']];
            }
        }
        //默认不查询众筹订单
        $map[] = ['a.order_type', 'not in', [6]];

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time']) . ' :00:00:00'];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time']) . ' 23:59:59'];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = Db::name('order')->alias('a')
                ->join('sp_user b', 'a.uid = b.uid', 'left')
                ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left')
                ->when($sear['keyword'] ?? false, function ($query) {
                    $query->join('sp_goods_spu e', 'c.goods_sn = e.goods_sn', 'left');
                })
                ->where($map)
                ->group('a.order_sn')->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = Db::name('order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left')
            ->when($sear['keyword'] ?? false, function ($query) {
                $query->join('sp_goods_spu e', 'c.goods_sn = e.goods_sn', 'left');
            })
            ->where($map)
            ->field('a.order_sn,a.order_type,a.order_belong,a.uid,a.item_count,a.total_price,a.fare_price,a.discount_price,a.real_pay_price,a.pay_type,a.pay_status,a.order_status,a.create_time,a.pay_time,a.end_time,a.shipping_code,b.name as user_name,group_concat(c.title) as all_goods_title,group_concat(c.sku_sn) as all_goods_sku,group_concat(distinct c.goods_sn) as all_goods_spu,a.shipping_code,a.shipping_status')
            ->group('a.order_sn')
            ->order('a.create_time desc')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->select()->each(function ($item, $key) {
                $item['create_time'] = timeToDateFormat($item['create_time']);
                if (!empty($item['pay_time'])) {
                    $item['pay_time'] = timeToDateFormat($item['pay_time']);
                }
                if (!empty($item['end_time'])) {
                    $item['end_time'] = timeToDateFormat($item['end_time']);
                }
                $item['pt'] = [];
                return $item;
            })->toArray();

        //C端用户列表会返回商品信息
        if ($this->module == 'api') {
            if (!empty($list)) {
                $aGoodsSn = array_column($list, 'order_sn');
                $allOrderGoods = (new OrderGoods())->with(['goods'])->where(['order_sn' => $aGoodsSn, 'status' => [1, -2, -3]])->field('goods_sn,order_sn,sku_sn,specs,price,count,after_status,total_price,real_pay_price,refund_price,all_dis,shipping_status')->order('create_time desc')->select()->toArray();
                if (!empty($allOrderGoods)) {
                    foreach ($list as $key => $value) {
                        foreach ($allOrderGoods as $k => $v) {
                            $v['partAfterSale'] = false;
                            if ($v['order_sn'] == $value['order_sn']) {
                                //oss图片缩放
                                if (!empty($v['main_image'])) {
//                                    $v['main_image'] .= '?x-oss-process=image/resize,h_360,m_lfit';
                                    $v['main_image'] .= '?x-oss-process=image/format,webp';
                                }
                                //判断是否为部分售后
                                if (!empty(doubleval($v['refund_price'])) && (string)$v['refund_price'] < (string)($v['total_price'] - ($v['all_dis'] ?? 0))) {
                                    $v['partAfterSale'] = true;
                                }
                                $list[$key]['goods'][] = $v;
                            }
                        }
                    }
                }

                //拼团订单详情
                $ptOrder = PtOrder::where(['order_sn' => $aGoodsSn])->withoutField('id,update_time')->select()->toArray();
                if (!empty($ptOrder)) {
                    foreach ($list as $lKey => $lValue) {
                        foreach ($ptOrder as $key => $value) {
                            if ($value['order_sn'] == $lValue['order_sn']) {
                                $list[$lKey]['pt'] = $value;
                            }
                        }
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }

    /**
     * @title  用户端订单列表汇总信息
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function userListSummary(array $sear = []): array
    {
        $map = [];
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn|b.name|e.title', $sear['keyword']))];
        }
        if (!empty($sear['searType'])) {
            $map[] = ['a.order_status', 'in', $sear['searType'] ?? [1, 2]];
        }
        if (!empty($sear['order_belong'])) {
            $map[] = ['a.order_belong', '=', $sear['order_belong']];
        }
        if (!empty($sear['split_status'])) {
            $map[] = ['a.split_status', '=', $sear['split_status']];
        }
        if ($this->module == 'api') {
            $map[] = ['a.uid', '=', $sear['uid']];
            //默认显示支付成功的订单
            //$map[] = ['a.order_status','=',2];
            if (!empty($sear['searType'])) {
                $map[] = ['a.order_status', '=', $sear['searType']];
            }
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time']) . ' :00:00:00'];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time']) . ' 23:59:59'];
        }

        $page = intval($sear['page'] ?? 0) ?: null;

        $list = Db::name('order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left')
            ->when($sear['keyword'] ?? false, function ($query) {
                $query->join('sp_goods_spu e', 'c.goods_sn = e.goods_sn', 'left');
            })
            ->where($map)
            ->field('a.order_sn,a.order_type,a.order_belong,a.uid,a.item_count,a.total_price,a.fare_price,a.discount_price,a.real_pay_price,a.pay_type,a.pay_status,a.order_status,a.create_time,a.pay_time,a.end_time,a.shipping_code,b.name as user_name,group_concat(c.title) as all_goods_title,group_concat(c.sku_sn) as all_goods_sku,group_concat(distinct c.goods_sn) as all_goods_spu,a.shipping_code,a.shipping_status,sum(if(c.pay_status = 2 and c.status in (1,2),(c.price * c.count),0)) as all_goods_price,sum(if(c.pay_status = 2 and c.status in (1,2),(c.sale_price * c.count),0)) as all_goods_sale_price')
            ->group('a.order_sn')
            ->order('a.create_time desc')
            ->select()->each(function ($item, $key) {
                $item['create_time'] = timeToDateFormat($item['create_time']);
                if (!empty($item['pay_time'])) {
                    $item['pay_time'] = timeToDateFormat($item['pay_time']);
                }
                if (!empty($item['end_time'])) {
                    $item['end_time'] = timeToDateFormat($item['end_time']);
                }
                $item['pt'] = [];
                return $item;
            })->toArray();

        $allRealPayPrice = 0;
        $allSavePrice = 0;
        $allUnPaidNumber = 0;
        $allPayNumber = 0;
        $allShippingNumber = 0;

        //C端用户列表会返回商品信息
        if (!empty($list)) {
            foreach ($list as $key => $value) {
                if (!in_array($value['order_status'], [1, -1, -2, -3, -4])) {
                    $allRealPayPrice += ($value['real_pay_price'] ?? 0);
                    //如果会员价还高于普通价则不累加已省金额
                    $savePrice = ((string)($value['all_goods_sale_price'] ?? 0) - (string)($value['all_goods_price'] ?? 0));
                    $allSavePrice += ($savePrice >= 0) ? $savePrice : 0;
                }

                if ($value['order_status'] == 1) {
                    $allUnPaidNumber += 1;
                }
                if ($value['order_status'] == 2) {
                    $allPayNumber += 1;
                }
                if ($value['order_status'] == 3) {
                    $allShippingNumber += 1;
                }
            }
        }

        $finally['allRealPayPrice'] = !empty($allRealPayPrice) ? priceFormat($allRealPayPrice) : 0;
        $finally['allSavePrice'] = !empty($allSavePrice) ? priceFormat($allSavePrice) : 0;
        $finally['allOrderNumber'] = !empty($list) ? count($list) : 0;
        $finally['allUnPaidNumber'] = !empty($allUnPaidNumber) ? $allUnPaidNumber : 0;
        $finally['allPayNumber'] = !empty($allPayNumber) ? $allPayNumber : 0;
        $finally['allShippingNumber'] = !empty($allShippingNumber) ? $allShippingNumber : 0;

        return $finally;
    }

    /**
     * @title  用户端用户相关分润订单汇总
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function userDivideOrderCount(array $sear = []): array
    {
        $finally['total'] = 0;
        $finally['direct'] = 0;
        $finally['all'] = 0;
        $finally['team'] = 0;

        $cacheKey = 'userTeamOrderCount' . md5(json_encode($sear, 256));
        $cacheExpire = 60;
        $cacheTag = 'userTeamOrderCount';

        if (empty($sear['clearCache'])) {
            $cacheList = cache($cacheKey);
            if (!empty($cacheList)) {
                return $cacheList;
            }
        }


        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn|b.name|e.title|a.user_phone', trim($sear['keyword'])))];
        }
        if (!empty($sear['searType'])) {
            $map[] = ['a.order_status', 'in', $sear['searType'] ?? [1, 2]];
        }
        if (!empty($sear['order_belong'])) {
            $map[] = ['a.order_belong', '=', $sear['order_belong']];
        }
        if (!empty($sear['split_status'])) {
            $map[] = ['a.split_status', '=', $sear['split_status']];
        }
        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
        }
        //仅展示已经支付的订单
        $map[] = ['a.pay_status', '=', 2];
        //$map[] = ['a.uid','=',$sear['uid']];
        //默认显示支付成功的订单
        //$map[] = ['a.order_status','=',2];
        if (!empty($sear['searType'])) {
            $map[] = ['a.order_status', '=', $sear['searType']];
        }

        //查看直推相关的分润订单
        $linkUserInfo = Member::where(['uid' => $sear['uid']])->findOrEmpty()->toArray();
        if (empty($linkUserInfo)) {
            throw new ServiceException(['msg' => '非会员无法查看哦~~']);
        }

        $directTeam = (new DivideService())->getNextDirectLinkUserGroupByLevel($linkUserInfo['uid']);
        if (empty($directTeam)) {
            $finally['team'] = (new TeamPerformance())->userTeamAllOrderCount($sear)['total'];
            return $finally;
        }

//        // 直推产生的订单
        if (!empty($directTeam['allUser']['onlyUidList'])) {
            $dMap = $map ?? [];
            $dMap[] = ['a.uid', 'in', $directTeam['allUser']['onlyUidList']];
            $dOrderList = Db::name('order')->alias('a')
                ->when($sear['keyword'] ?? false, function ($query) {
                    $query->join('sp_user b', 'a.uid = b.uid', 'left')
                        ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left');
                })
//                ->join('sp_user b', 'a.uid = b.uid', 'left')
//                ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left')
                ->join('sp_divide d', 'a.order_sn = d.order_sn', 'left')
                ->when($sear['keyword'] ?? false, function ($query) {
                    $query->join('sp_goods_spu e', 'c.goods_sn = e.goods_sn', 'left');
                })
                ->where($dMap)
                ->group('a.order_sn')
                ->order('a.create_time desc')->column('a.order_sn');

            if (!empty($dOrderList)) {
                $finally['direct'] = count(array_unique($dOrderList));
            }
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $diMap[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $diMap[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }
        $diMap[] = ['link_uid', '=', $sear['uid']];
        $linkOrder = Divide::where($diMap)->column('order_sn');
        if (empty($linkOrder)) {
            $finally['team'] = (new TeamPerformance())->userTeamAllOrderCount($sear)['total'];
            $finally['all'] = $finally['direct'] ?? 0;
            return $finally;
        }

        $map[] = ['a.order_sn', 'in', $linkOrder];

        $map[] = ['d.link_uid', '=', $sear['uid']];
        //默认不展示分润金额为0的订单
//        $map[] = ['d.real_divide_price', '<>', 0];
        $map[] = ['d.arrival_status', 'in', [1, 2, 4]];

        $allOrder = Db::name('order')->alias('a')
            ->when($sear['keyword'] ?? false, function ($query) {
                $query->join('sp_user b', 'a.uid = b.uid', 'left')
                    ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left');
            })
//            ->join('sp_user b', 'a.uid = b.uid', 'left')
//            ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left')
            ->join('sp_divide d', 'a.order_sn = d.order_sn', 'left')
            ->when($sear['keyword'] ?? false, function ($query) {
                $query->join('sp_goods_spu e', 'c.goods_sn = e.goods_sn', 'left');
            })
            ->where($map)
            ->group('a.order_sn')
            ->order('a.create_time desc')->column('a.order_sn');

        if (empty($allOrder)) {
            $finally['team'] = (new TeamPerformance())->userTeamAllOrderCount($sear)['total'];
            return $finally;
        }
//        // 直推产生的订单
//        if (!empty($directTeam['allUser']['onlyUidList'])) {
//            $dMap[] = ['order_uid', 'in', $directTeam['allUser']['onlyUidList']];
//            $dMap[] = ['order_sn', 'in', $allOrder];
//            $finally['direct'] = Divide::where($dMap)->group('order_sn')->count();
//        }

        // 间推产生的订单
        if (!empty($directTeam['allUser']['onlyUidList'])) {
            $aMap[] = ['order_uid', 'not in', $directTeam['allUser']['onlyUidList']];
            $aMap[] = ['order_sn', 'in', $allOrder];
//            $aMap[] = ['link_uid', '=', $sear['uid']];
//            //默认不展示分润金额为0的订单
////        $map[] = ['real_divide_price', '<>', 0];
//            $aMap[] = ['arrival_status', 'in', [1, 2, 4]];
            $finally['all'] = Divide::where($aMap)->group('order_sn')->count();
        }

        $finally['total'] = ($finally['direct'] ?? 0) + ($finally['all'] ?? 0);
        //团队订单总数
        $finally['team'] = (new TeamPerformance())->userTeamAllOrderCount($sear)['total'];
        if (!empty($finally)) {
            cache($cacheKey, $finally, $cacheExpire, $cacheTag);
        }

        return $finally;
    }


    /**
     * @title  用户端用户相关分润订单列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function userDivideOrderList(array $sear = []): array
    {
        $map = [];
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn|b.name|e.title|a.user_phone', $sear['keyword']))];
        }
        if (!empty($sear['searType'])) {
            $map[] = ['a.order_status', 'in', $sear['searType'] ?? [1, 2]];
        }
        if (!empty($sear['order_belong'])) {
            $map[] = ['a.order_belong', '=', $sear['order_belong']];
        }
        if (!empty($sear['split_status'])) {
            $map[] = ['a.split_status', '=', $sear['split_status']];
        }
        //仅展示已经支付的订单
        $map[] = ['a.pay_status', '=', 2];
        if ($this->module == 'api') {
            if (!empty($sear['orderUserType'])) {
                //直推展示所有直推下级的订单,间推只相关的分润订单
                $linkUserInfo = Member::where(['uid' => $sear['uid']])->findOrEmpty()->toArray();
                if (empty($linkUserInfo)) {
                    throw new ServiceException(['msg' => '非会员无法查看哦~~']);
                }
                //查询直推下级的会员用户
                $directTeam = (new DivideService())->getNextDirectLinkUserGroupByLevel($linkUserInfo['uid']);
                if (empty($directTeam['allUser']['onlyUidList'])) {
                    return ['list' => [], 'pageTotal' => 0, 'total' => 0];
                }
                //查询直推下级的普通用户
                $directTeamNormalUser = User::where(['link_superior_user' => $linkUserInfo['uid'], 'status' => 1, 'vip_level' => 0])->column('uid');
                //合并两种直推的用户群体
                if (!empty($directTeamNormalUser ?? [])) {
                    if (!empty($directTeam['allUser']['onlyUidList'])) {
                        $directTeam['allUser']['onlyUidList'] = array_unique(array_merge_recursive($directTeam['allUser']['onlyUidList'], $directTeamNormalUser));
                    } else {
                        $directTeam['allUser']['onlyUidList'] = $directTeamNormalUser;
                    }
                }

                // 1为直推产生的订单 2为间推产生的订单
                if ($sear['orderUserType'] == 1) {
                    if (!empty($directTeam['allUser']['onlyUidList'])) {
//                        $map[] = ['d.order_uid', 'in', $directTeam['allUser']['onlyUidList']];
                        $map[] = ['a.uid', 'in', $directTeam['allUser']['onlyUidList']];
                    }
                } elseif ($sear['orderUserType'] == 2) {
                    //间推只展示有分润的订单
                    if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
                        $diMap[] = ['create_time', '>=', strtotime($sear['start_time'])];
                        $diMap[] = ['create_time', '<=', strtotime($sear['end_time'])];
                    }
                    $diMap[] = ['link_uid', '=', $sear['uid']];
                    $linkOrder = Divide::where($diMap)->column('order_sn');
//                    $linkOrder = Divide::where(['link_uid' => $sear['uid']])->column('order_sn');
                    if (empty($linkOrder)) {
                        return ['list' => [], 'pageTotal' => 0, 'total' => 0];
                    }
                    $map[] = ['a.order_sn', 'in', $linkOrder];
                    $map[] = ['d.link_uid', '=', $sear['uid']];
                    //默认不展示分润金额为0的订单
//                    $map[] = ['d.real_divide_price', '<>', 0];

                    if (!empty($directTeam['allUser']['onlyUidList'])) {
                        $map[] = ['d.order_uid', 'not in', $directTeam['allUser']['onlyUidList']];
                    }
                }
            } else {
                if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
                    $diiMap[] = ['create_time', '>=', strtotime($sear['start_time'])];
                    $diiMap[] = ['create_time', '<=', strtotime($sear['end_time'])];
                }
                $diiMap[] = ['link_uid', '=', $sear['uid']];
                $linkOrder = Divide::where($diiMap)->column('order_sn');
//                $linkOrder = Divide::where(['link_uid' => $sear['uid']])->column('order_sn');
                if (empty($linkOrder)) {
                    return ['list' => [], 'pageTotal' => 0, 'total' => 0];
                }
                $map[] = ['a.order_sn', 'in', $linkOrder];
                $map[] = ['d.link_uid', '=', $sear['uid']];
                //默认不展示分润金额为0的订单
//                    $map[] = ['d.real_divide_price', '<>', 0];
            }

            //$map[] = ['a.uid','=',$sear['uid']];
            //默认显示支付成功的订单
            //$map[] = ['a.order_status','=',2];
            if (!empty($sear['searType'])) {
                $map[] = ['a.order_status', '=', $sear['searType']];
            }
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = Db::name('order')->alias('a')
                ->join('sp_user b', 'a.uid = b.uid', 'left')
                ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left')
                ->join('sp_divide d', 'a.order_sn = d.order_sn', 'left')
                ->when($sear['keyword'] ?? false, function ($query) {
                    $query->join('sp_goods_spu e', 'c.goods_sn = e.goods_sn', 'left');
                })
                ->where($map)
                ->group('a.order_sn')->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = Db::name('order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left')
            ->join('sp_divide d', 'a.order_sn = d.order_sn', 'left')
            ->when($sear['keyword'] ?? false, function ($query) {
                $query->join('sp_goods_spu e', 'c.goods_sn = e.goods_sn', 'left');
            })
            ->where($map)
            ->field('a.order_sn,a.order_type,a.order_belong,a.uid,a.item_count,a.total_price,a.fare_price,a.discount_price,a.real_pay_price,a.pay_type,a.pay_status,a.order_status,a.after_status,a.create_time,a.pay_time,a.end_time,a.shipping_code,b.name as user_name,group_concat(c.title) as all_goods_title,group_concat(c.sku_sn) as all_goods_sku,group_concat(distinct c.goods_sn) as all_goods_spu,a.shipping_code,d.real_divide_price,b.vip_level')
            ->group('a.order_sn')
            ->order('a.create_time desc')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->select()->each(function ($item, $key) {
                $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                if (!empty($item['pay_time'])) {
                    $item['pay_time'] = date('Y-m-d H:i:s', $item['pay_time']);
                }
                if (!empty($item['end_time'])) {
                    $item['end_time'] = date('Y-m-d H:i:s', $item['end_time']);
                }
                $item['pt'] = [];
                return $item;
            })->toArray();

        $divideList = [];
        $divideTopUser = [];
        //C端用户列表会返回商品信息
        if ($this->module == 'api') {
            $memberVdc = MemberVdc::where(['status' => 1])->column('name', 'level');
            if (!empty($list)) {
                $aGoodsSn = array_column($list, 'order_sn');
                $allOrderGoods = (new OrderGoods())->with(['goods'])->where(['order_sn' => $aGoodsSn, 'status' => [1, -2, -3]])->field('goods_sn,order_sn,sku_sn,count,price,total_price,specs,after_status,status')->order('create_time desc')->select()->toArray();
                if (!empty($allOrderGoods)) {
                    foreach ($list as $key => $value) {
                        foreach ($allOrderGoods as $k => $v) {
                            if ($v['order_sn'] == $value['order_sn']) {
                                if (!empty($v['main_image'])) {
                                    $v['main_image'] .= '?x-oss-process=image/format,webp';
                                }
                                $list[$key]['goods'][] = $v;
                            }
                        }
                    }
                }
                //仅在团队订单的情况下查找分润起始的用户是谁
                if (!empty($sear['orderUserType']) && $sear['orderUserType'] == 2) {
                    $divideList = Divide::with(['link'])->where(['order_sn' => array_unique($aGoodsSn)])->order('vdc_genre asc,divide_type asc,id asc')->group('order_sn')->select()->toArray();
                    if (!empty($divideList)) {
                        foreach ($divideList as $key => $value) {
                            $divideTopUser[$value['order_sn']]['first_link_user_uid'] = $value['link_uid'];
                            $divideTopUser[$value['order_sn']]['link_user_name'] = $value['link_user_name'] ?? '游客';
                            $divideTopUser[$value['order_sn']]['link_user_phone'] = $value['link_user_phone'] ?? null;
                            $divideTopUser[$value['order_sn']]['link_user_level'] = $value['link_user_level'] ?? 0;
                        }
                    }
                }
                //查找上级信息
                $aAllUser = array_column($list, 'uid');
                $allUserTop = User::with(['link'])->where(['uid' => $aAllUser])->field('uid,name,vip_level,phone,link_superior_user')->select()->toArray();
                foreach ($list as $key => $value) {
                    $list[$key]['vip_name'] = '普通用户';
                    $list[$key]['link_user_vip_name'] = '普通用户';
                }
                $needChangeTopUser = [];
                if (!empty($allUserTop)) {
                    //仅在团队订单的情况下查找分润起始的用户是谁,如果出现了订单购买人的上级uid跟分润起始uid不同的情况,上级信息替换为分润起始人的用户信息
                    if (!empty($sear['orderUserType']) && $sear['orderUserType'] == 2 && !empty($divideList)) {
                        foreach ($list as $key => $value) {

                            foreach ($allUserTop as $uKey => $uValue) {
                                if ($uValue['uid'] == $value['uid']) {
                                    if (!empty($divideTopUser) && !empty($divideTopUser[$value['order_sn']])) {
                                        $firstDivideUserInfo = $divideTopUser[$value['order_sn']];
                                        if (!empty($uValue['link_superior_user']) && $firstDivideUserInfo['first_link_user_uid'] != $uValue['link_superior_user']) {

//                                            $allUserTop[$uKey]['link_user_level'] = $firstDivideUserInfo['link_user_level'];
//                                            $allUserTop[$uKey]['link_user_name'] = $firstDivideUserInfo['link_user_name'];
//                                            $allUserTop[$uKey]['link_user_phone'] = $firstDivideUserInfo['link_user_phone'];
                                            $needChangeTopUser[$value['order_sn']] = $uValue;
                                            $needChangeTopUser[$value['order_sn']]['link_user_level'] = $firstDivideUserInfo['link_user_level'];
                                            $needChangeTopUser[$value['order_sn']]['link_user_name'] = $firstDivideUserInfo['link_user_name'];
                                            $needChangeTopUser[$value['order_sn']]['link_user_phone'] = $firstDivideUserInfo['link_user_phone'];
                                        }
                                    }
                                }
                            }
                        }
                    }
                    foreach ($allUserTop as $key => $value) {
                        foreach ($list as $lKey => $lValue) {
                            if (!empty($memberVdc)) {
                                $list[$lKey]['vip_name'] = $memberVdc[$lValue['vip_level']] ?? '普通用户';
                            }
                            if ($value['uid'] == $lValue['uid']) {
                                //仅在团队订单的情况下查找分润起始的用户是谁,如果出现了订单购买人的上级uid跟分润起始uid不同的情况,上级信息替换为分润起始人的用户信息
                                if (!empty($sear['orderUserType']) && $sear['orderUserType'] == 2 && !empty($divideList)) {
                                    if (!empty($value['link_superior_user']) && $divideTopUser[$lValue['order_sn']]['first_link_user_uid'] != $value['link_superior_user']) {
                                        if (!empty($needChangeTopUser[$lValue['order_sn']] ?? null)) {
                                            $value['link_user_name'] = $needChangeTopUser[$lValue['order_sn']]['link_user_name'] ?? '未知上级';
                                            $value['link_user_phone'] = $needChangeTopUser[$lValue['order_sn']]['link_user_phone'] ?? null;
                                            $value['link_user_level'] = $needChangeTopUser[$lValue['order_sn']]['link_user_level'] ?? 0;
                                        }
                                    }
                                }


                                $list[$lKey]['link_user_name'] = $value['link_user_name'] ?? null;
                                if (!empty($value['link_user_phone'])) {
                                    if (!empty($sear['orderUserType']) && $sear['orderUserType'] == 1) {
                                        $list[$lKey]['link_user_phone'] = $value['link_user_phone'];
                                    } else {
                                        $list[$lKey]['link_user_phone'] = encryptPhone($value['link_user_phone']);
                                    }

                                } else {
                                    $list[$lKey]['link_user_phone'] = '用户暂未绑定手机号码';
                                }
                                $list[$lKey]['link_user_level'] = $value['link_user_level'] ?? 0;
                                if (!empty($list[$lKey]['link_user_level']) && !empty($memberVdc)) {
                                    $list[$lKey]['link_user_vip_name'] = $memberVdc[$list[$lKey]['link_user_level']] ?? '普通用户';
                                }
                            }
                        }
                    }
                }

                //拼团订单详情
                $ptOrder = PtOrder::where(['order_sn' => $aGoodsSn])->withoutField('id,update_time')->select()->toArray();
                if (!empty($ptOrder)) {
                    foreach ($list as $lKey => $lValue) {
                        foreach ($ptOrder as $key => $value) {
                            if ($lValue['order_sn'] == $value['order_sn']) {
                                $list[$lKey]['pt'] = $value;
                            }
                        }
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }

    /**
     * @title  生成订单
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function new(array $data)
    {
//        $res = Db::transaction(function() use ($data){
        //查看商品详情
        $aSku = array_unique(array_filter(array_column($data['goods'], 'sku_sn')));
        if (empty($aSku)) {
            throw new OrderException(['errorCode' => 1500106]);
        }
        //添加商品缓存
//            $goodsSkuMd5 = md5Encrypt(implode(',',$aSku),16);
//            $goodsCache = config('cache.systemCacheKey.orderGoodsSkuList');
//            $goodsCacheKey = $goodsCache['key'] . $goodsSkuMd5;
//            $cacheGoodsList = cache($goodsCacheKey);
        $cacheGoodsList = [];
        if (!empty($cacheGoodsList)) {
            $Goods = $cacheGoodsList;
        }
        if (empty($cacheGoodsList)) {
            //锁行
            $skuId = GoodsSku::where(['sku_sn' => $aSku, 'status' => 1])->column('id');
            if (empty($skuId)) {
                throw new OrderException(['errorCode' => 1500106]);
            }
            $Goods = GoodsSku::with(['spu', 'vdc'])->where(['id' => $skuId])->field('goods_sn,sku_sn,sale_price,title,content,image,vdc_allow,specs,supplier_code,attach_type,cost_price')->lock(true)->select()->each(function ($item, $key) {
                $item['content'] = $item['spu']['desc'];
                return $item;
            })->toArray();
//                if(!empty($Goods)){
//                    cache($goodsCacheKey,$Goods,$goodsCache['expire']);
//                }
        }

        if (empty($Goods)) {
            throw new OrderException(['errorCode' => 1500106]);
        }

        $needAddAttach = false;
        //校验活动类型是否需要附加条件
//        if ($data['order_type'] == 1 && !empty($data['activity_id'])) {
//            $needAddAttach = (new OrderService())->activityAttach($data, $data['activity_id'], $data['order_type']);
//        }
        $notCreatData = false;
        //判断是否为拼拼有礼类型订单,如果是则先不加入数据库,只有后续用户选择了发货才同步到订单表中
        if(!empty($data['order_type'] ?? null) && $data['order_type'] == 4){
            $notCreatData = true;
            $updateGoodsStock = false;
            if($data['ppyl_join_type'] == 1){
                $updateGoodsStock = true;
            }
        }

        //检查全部商品中是否有需要附加条件的
        $skuAttach = $data;
        $skuAttach['sku'] = $Goods;
        $needAddAttach = (new OrderService())->goodsSkuAttach($skuAttach);

        //获取用户等级等级
        $aMemberLevel = (new Member())->getUserLevel($data['uid'], false);

        //判断商品订单中包含可分润的商品情况
        $vdcAllowNumber = 0;
        foreach ($Goods as $key => $value) {
            if ($value['vdc_allow'] == 1) {
                $vdcAllowNumber++;
            }
        }
        $goodsNumber = count($Goods);
        if (!empty($vdcAllowNumber) && $vdcAllowNumber <= $goodsNumber) {
            $order['vdc_allow'] = 1;
        } elseif (!empty($vdcAllowNumber) && $vdcAllowNumber < $goodsNumber) {
            $order['vdc_allow'] = 3;
        } else {
            $order['vdc_allow'] = 2;
        }


        $order['order_sn'] = (new CodeBuilder())->buildOrderNo();
        $order['order_belong'] = $data['belong'] ?? $this->belong;
        $order['order_type'] = $data['order_type'] ?? 1;
        //积分支付和美丽券支付的订单为积分订单或美丽券订单
        if (in_array(($data['pay_type'] ?? 1), [7, 8])) {
            $order['order_type'] = $data['pay_type'];
        }
        $order['item_count'] = count($data['goods']);
        $order['uid'] = $data['uid'];
        $order['user_level'] = $aMemberLevel ?? 0;
        $order['user_phone'] = $data['user_phone'] ?? null;
        $order['used_integral'] = intval($data['used_integral']);
        $order['total_price'] = priceFormat($data['total_price']);
        $order['fare_price'] = priceFormat($data['fare_price']);
        $order['discount_price'] = priceFormat($data['discount_price']);
        $order['real_pay_price'] = priceFormat($data['real_pay_price']);
        if (empty($order['real_pay_price'])) {
            $order['pay_type'] = 1;
        } else {
            $order['pay_type'] = $data['pay_type'];
        }
        $order['pay_channel'] = config('system.thirdPayType') ?? 2;
        //微信支付的情况下选择微信服务商通道
        if ($data['pay_type'] == 2) {
            $order['pay_channel'] = config('system.thirdPayTypeForWxPay') ?? 2;
        }
        $order['order_remark'] = $data['order_remark'] ?? null;
        //福利专区订单不需要记录发货信息, 因为不需要发货
        if ($data['order_type'] != 6) {
            if ($order['order_belong'] == 1) {
                $order['address_id'] = $data['address_id'];
                $order['shipping_address'] = $data['shipping_address'];
                $order['shipping_name'] = $data['shipping_name'];
                $order['shipping_phone'] = $data['shipping_phone'];
                if (empty($data['shipping_address_detail'] ?? null)) {
                    throw new OrderException(['msg' => '您的收货地址信息已过期, 请重新编辑或联系平台客服']);
                }
                if (!empty($data['shipping_address_detail'] ?? null)) {
                    if (empty($data['shipping_address_detail']['AreaId'] ?? null)) {
                        throw new OrderException(['msg' => '您的收货地址信息已过期, 请重新编辑']);
                    }
                    $order['shipping_address_detail'] = json_encode($data['shipping_address_detail'], 256);
                }
            }
        } else {
            $order['shipping_name'] = '福利专区无需发货';
            $order['shipping_type'] = 2;
        }

        $order['create_time'] = time();
        $order['handsel_sn'] = $data['handsel_sn'] ?? null;
        //拼拼有礼订单不允许售后和分润
        if($order['order_type'] == 4){
            $order['allow_after_sale'] = 2;
            $order['vdc_allow'] = 2;
        }
        //如果是众筹类型的订单默认不允许同步订单, 只有等真的成功或真的失败才可以同步到发货订单
        if($order['order_type'] == 6){
            $order['can_sync'] = 2;
            //判断订单是否为提前购订单
            $advanceBuy = false;
            foreach ($data['goods'] as $key => $value) {
                if (!empty($value['advance_buy'] ?? false)) {
                    $advanceBuy = true;
                }
            }
            $order['advance_buy'] = !empty($advanceBuy ?? false) ? 1 : 2;
        }

        $isExchangeOrder = false;
        $exchangeGoodsInfo = [];
        //判断是否存在兑换商品
        $exchangeAllSku = array_unique(array_column($Goods, 'sku_sn'));
        $exchangeGoods = ExchangeGoodsSku::where(['status' => 1, 'sku_sn' => $exchangeAllSku])->select()->toArray();
        if (!empty($exchangeGoods)) {
            $isExchangeOrder = true;
            $order['is_exchange'] = 1;
            foreach ($exchangeGoods as $key => $value) {
                $exchangeGoodsInfo[$value['sku_sn']] = $value;
            }
        }

//            //购买团长大礼包必须有上级且上级为会员才能购买
//            if($data['order_type'] == 3 && empty($data['order_link_user'])){
//                throw new OrderException(['msg'=>'缺失上级会员,无法购买团长大礼包']);
//            }

        //获取关联上级的用户信息,保存到订单信息中
        if (!empty($data['order_link_user'])) {
            $linkUser = Member::where(['uid' => $data['order_link_user'], 'status' => 1])->field('uid,level,team_code,child_team_code')->findOrEmpty()->toArray();
            //暂时取消上级一定要是会员的限制
//            if ($data['order_type'] == 3 && empty($linkUser['level'])) {
//                throw new OrderException(['msg' => '所选上级非会员,无法购买团长大礼包']);
//            }
            $order['link_superior_user'] = $data['order_link_user'] ?? null;
            if (!empty($linkUser)) {
                $order['link_team_code'] = $linkUser['team_code'] ?? null;
                $order['link_child_team_code'] = $linkUser['child_team_code'] ?? null;
            }
        }

        //如果是转售订单判断是否为直属下级,不是则不允许下单
        if ($data['order_type'] == 5) {
            $handselSn = $data['handsel_sn'] ?? null;
            if (empty($handselSn)) {
                throw new OrderException(['msg' => '暂无法下单']);
            }
            $handselId = Handsel::where(['handsel_sn' => $handselSn, 'operate_status' => 2])->lock(true)->value('id');
            $handselInfo = Handsel::where(['handsel_sn' => $handselSn, 'operate_status' => 2])->findOrEmpty()->toArray();
            if (empty($handselId) || empty($handselInfo)) {
                throw new OrderException(['msg' => '暂不支持下单']);
            }
            $aUserInfo = (new User())->getUserProtectionInfo($data['uid']);
            if (empty($aUserInfo['link_superior_user']) || (!empty($aUserInfo['link_superior_user']) && $aUserInfo['link_superior_user'] != $handselInfo['uid']) || !empty($aUserInfo['vip_level'] ?? 0)) {
                throw new OrderException(['msg' => '您不是此订单的下单对象,暂不支持下单']);
            }
            //修改转售原纪录为已操作, 操作类型写死为转售
            Handsel::update(['order_sn' => $order['order_sn'], 'order_uid' => $order['uid'], 'operate_status' => 1, 'operate_time' => time(), 'select_type' => 2], ['handsel_sn' => $handselSn, 'operate_status' => 2]);
        }
        if ($order['order_type'] == 6) {
            foreach ($data['goods'] as $key => $value) {
                if (!empty($value['activity_id']) && !empty($value['round_number']) && $value['period_number'])
                    $crowdKey[] = $value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            }
            if (!empty($crowdKey)) {
                $crowdKeyText = implode(',', array_unique($crowdKey));
                $order['crowd_key'] = $crowdKeyText;
            }
        }

        //新增订单信息
        if(empty($notCreatData)){
            $orderRes = $this->validate()->baseCreate($order);
            if (in_array($order['pay_type'], config('system.PayType'))) {
                $oap_res = (new OrderPayArguments)->new($orderRes);
            }
        }else{
            $orderRes = true;
            $returnData['orderRes'] = $order;
        }


        //添加订单对应的商品

        //根据用户当前等级获取当前订单的分销抽成
        foreach ($Goods as $key => $value) {
            $aGoods[$value['sku_sn']] = $value;
            $aGoods[$value['sku_sn']]['user_level'] = 0;
            $aGoods[$value['sku_sn']]['vdc_one'] = 0.00;
            $aGoods[$value['sku_sn']]['vdc_two'] = 0.00;
            $aGoods[$value['sku_sn']]['allVdc'] = [];
            if (!empty($isExchangeOrder ?? false) && !empty($exchangeGoodsInfo[$value['sku_sn']] ?? null)) {
                $aGoods[$value['sku_sn']]['is_exchange'] = 1;
            }
            foreach ($value['vdc'] as $k => $v) {
                $aGoods[$value['sku_sn']]['allVdc'][$v['level']] = $v['purchase_price'] ?? 0;
            }

            if (!empty($aMemberLevel)) {
                foreach ($value['vdc'] as $k => $v) {
                    if ($v['level'] == $aMemberLevel) {
                        $aGoods[$value['sku_sn']]['user_level'] = $v['level'];
                        $aGoods[$value['sku_sn']]['vdc_one'] = $v['vdc_one'];
                        $aGoods[$value['sku_sn']]['vdc_two'] = $v['vdc_two'];
                    }
                }
            }
        }

        if ($orderRes) {
            $presaleAdvanceGoods = [];
            //添加订单商品
            foreach ($data['goods'] as $key => $value) {
                $skuSn = $value['sku_sn'];
                $addGoods[$key]['order_sn'] = $order['order_sn'];
                $addGoods[$key]['goods_sn'] = $value['goods_sn'];
                $addGoods[$key]['sku_sn'] = $value['sku_sn'];
                $addGoods[$key]['order_type'] = $order['order_type'] ?? 1;
                $addGoods[$key]['count'] = intval($value['number']);
                $addGoods[$key]['price'] = priceFormat($value['price']);
                $addGoods[$key]['total_price'] = ($addGoods[$key]['count'] * $addGoods[$key]['price']);
                $addGoods[$key]['vdc_allow'] = $aGoods[$skuSn]['vdc_allow'] ?? 1;
                $addGoods[$key]['sale_price'] = $aGoods[$skuSn]['sale_price'] ?? 0;
                $addGoods[$key]['title'] = $aGoods[$skuSn]['title'];
                $addGoods[$key]['images'] = $aGoods[$skuSn]['image'];
                $addGoods[$key]['specs'] = $aGoods[$skuSn]['specs'];
                $addGoods[$key]['desc'] = $aGoods[$skuSn]['content'];
                $addGoods[$key]['supplier_code'] = $aGoods[$skuSn]['supplier_code'] ?? null;
                $addGoods[$key]['total_fare_price'] = $value['total_fare_price'] ?? 0;
                $addGoods[$key]['user_level'] = $aGoods[$skuSn]['user_level'];
                $addGoods[$key]['vdc_one'] = $aGoods[$skuSn]['vdc_one'];
                $addGoods[$key]['vdc_two'] = $aGoods[$skuSn]['vdc_two'];
                $addGoods[$key]['member_dis'] = $value['memberDis'] ?? 0;
                $addGoods[$key]['coupon_dis'] = $value['couponDis'] ?? 0;;
                $addGoods[$key]['integral_dis'] = $value['integralDis'] ?? 0;;
                $addGoods[$key]['all_dis'] = $value['allDisPrice'] ?? 0;;
                $addGoods[$key]['real_pay_price'] = $value['realPayPrice'] ?? $value['price'];
                $addGoods[$key]['cost_price'] = $aGoods[$skuSn]['cost_price'] ?? 0;
                $addGoods[$key]['activity_sign'] = $value['activity_id'] ?? null;

                if ($order['order_type'] == 6) {
                    $addGoods[$key]['crowd_code'] = $value['activity_id'] ?? null;
                    $addGoods[$key]['crowd_round_number'] = $value['round_number'] ?? null;
                    $addGoods[$key]['crowd_period_number'] = $value['period_number'] ?? null;
                }
                $addGoods[$key]['gift_type'] = $value['gift_type'] ?? -1;
                $addGoods[$key]['gift_number'] = $value['gift_number'] ?? 0;
                //如果消费不够本次不送礼品
                if ($order['order_type'] == 6) {
                    $userGiftCrowdBalance = (new CrowdfundingBalanceDetail())->getUserNormalShoppingSendCrowdBalance(['uid' => $data['uid']]);
                    if (empty($userGiftCrowdBalance['res'] ?? false)) {
                        //如果加上本次刚好超过则允许超过的部分赠送
                        $morePrice = $userGiftCrowdBalance['gift_price'] - ($userGiftCrowdBalance['crowd_price'] + $data['total_price']);
                        if ($morePrice < 0) {
                            $addGoods[$key]['gift_number'] = priceFormat(abs($morePrice));
                        } else {
                            $addGoods[$key]['gift_number'] = 0;
                        }
                    }
                }


                if (!empty($aGoods[$skuSn]['allVdc'])) {
                    $addGoods[$key]['goods_level_vdc'] = json_encode($aGoods[$skuSn]['allVdc'], 256);
                }

                //判断订单商品后续是否允许退售后或允许的退售后类型
                $notAllowAfter = false;
                $allowAfterType = [1, 2, 3];
                if ($order['order_type'] == 6) {
                    $notAllowAfter = true;
                }
                //如果是特殊兑换订单或赠送订单仅允许换货, 不允许退款或退货退款
                if (in_array($order['order_type'], [3, 7, 8]) || ($order['order_type'] == 1 && $order['pay_type'] == 5)) {
                    $allowAfterType = [3];
                }
                //如果存在购物送东西的订单也不允许退售后
                if (!empty($addGoods[$key]['gift_type']) && $addGoods[$key]['gift_type'] > -1) {
                    $allowAfterType = [3];
                }
                $addGoods[$key]['allow_after'] = !empty($notAllowAfter) ? 2 : 1;
                $addGoods[$key]['allow_after_type'] = implode(',', $allowAfterType);

                //判断该订单该商品是否需要进入延迟发放奖励表
                if ($order['order_type'] == 6) {
                    if (!empty($value['presale'] ?? false) && doubleval($value['advance_buy_reward_send_time'] ?? 0) > 0) {
                        $presaleAdvanceGoods[$key]['uid'] = $order['uid'];
                        $presaleAdvanceGoods[$key]['order_sn'] = $order['order_sn'];
                        $presaleAdvanceGoods[$key]['goods_sn'] = $value['goods_sn'];
                        $presaleAdvanceGoods[$key]['sku_sn'] = $value['sku_sn'];
                        $presaleAdvanceGoods[$key]['crowd_code'] = $value['activity_id'] ?? null;
                        $presaleAdvanceGoods[$key]['crowd_round_number'] = $value['round_number'] ?? null;
                        $presaleAdvanceGoods[$key]['crowd_period_number'] = $value['period_number'] ?? null;
                        $presaleAdvanceGoods[$key]['arrival_status'] = 3;
                        //时间略微延迟1秒,保证时间晚于订单创建时间
                        $presaleAdvanceGoods[$key]['arrival_time'] = intval((time() + 1) + ($value['advance_buy_reward_send_time'] ?? 0));
                    }
                }

            }

            if (!empty($addGoods)) {
                if (empty($notCreatData)) {
                    $goodsRes = (new OrderGoods())->saveAll($addGoods);
                } else {
                    $returnData['goodsRes'] = $addGoods;
                }
            }
            //添加订单优惠券
            if (!empty($data['uc_code'])) {
                $aOrderCoupon['order_sn'] = $order['order_sn'];
                $aOrderCoupon['order_data'] = $data;
                $couponRes = (new OrderCoupon())->new($aOrderCoupon);
                //修改用户优惠券状态为订单占用,暂不可使用
                $userCouponRes = UserCoupon::update(['valid_status' => 4], ['uc_code' => $data['uc_code'], 'valid_status' => 1]);
            }
            //删减库存(锁定库存,如果取消支付则恢复库存)
            //同时扣除缓存中的库存
            $skuMd5 = md5Encrypt(implode(',', $aSku), 16);
            $cache = config('cache.systemCacheKey.orderSku');
            $cacheKey = $cache['key'] . $skuMd5;
            $cacheList = cache($cacheKey);

            $GoodsSku = (new GoodsSku());

            //只有正常订单或者拼拼有礼开团情况下才更新库存
            if (empty($notCreatData) || (!empty($notCreatData) && !empty($updateGoodsStock))) {
                foreach ($data['goods'] as $key => $value) {
                    $map = ['sku_sn' => $value['sku_sn'], 'status' => 1];
                    $stockRes = $GoodsSku->where($map)->dec('stock', $value['number'])->update();
                    if (!empty($cacheList)) {
                        foreach ($cacheList as $k => $v) {
                            if ($v['sku_sn'] == $value['sku_sn']) {
                                $cacheList[$k]['stock'] -= $value['number'];
                            }
                        }
                        cache($cacheKey, $cacheList, $cache['expire']);
                    }
                }
                if ($order['order_type'] == 6) {
                    //如果是众筹订单则需要同时更新剩余销售额
                    $CrowdfundingPeriod = (new CrowdfundingPeriod());
                    foreach ($data['goods'] as $key => $value) {
                        $cMap = ['buy_status' => 2, 'status' => 1, 'activity_code' => $value['activity_id'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number']];
                        $lastSalesPriceRes = $CrowdfundingPeriod->where($cMap)->dec('last_sales_price', ($value['realPayPrice'] - $value['total_fare_price']))->update();
                        //如果有提前购则锁定提前购卡
                        if (!empty($advanceBuy ?? false)) {
                            $advanceGoods[$value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number']] = true;
                        }
                    }
                    if (!empty($advanceGoods ?? [])) {
                        cache((new AdvanceCardDetail())->lockAdvanceBuyKey . $order['uid'], $advanceGoods, 180);
                    }
                }
            }

            //添加订单附加条件
            if (!empty($needAddAttach)) {
                $attach = $data['attach'];
                if (empty($attach)) {
                    throw new OrderException(['errorCode' => 1500116]);
                }
                $addAttach['order_sn'] = $order['order_sn'];
                $addAttach['uid'] = $order['uid'];
                $addAttach['id_card'] = $attach['id_card'] ?? null;
                $addAttach['id_card_front'] = $attach['id_card_front'] ?? null;
                $addAttach['id_card_back'] = $attach['id_card_back'] ?? null;
                $addAttach['real_name'] = $attach['real_name'] ?? null;
                (new OrderAttach())->DBNew($addAttach);

                //添加身份证记录
                $addCertificate['uid'] = $order['uid'];
                $addCertificate['user_phone'] = $order['user_phone'];
                $addCertificate['id_card'] = $attach['id_card'] ?? null;
                $addCertificate['id_card_front'] = $attach['id_card_front'] ?? null;
                $addCertificate['id_card_back'] = $attach['id_card_back'] ?? null;
                $addCertificate['real_name'] = $attach['real_name'] ?? null;
                $addCertificate['is_default'] = 2;
                (new UserCertificate())->DBNew($addCertificate);
            }

            //众筹订单添加超级提前购(预售)预计奖励订单
            if (!empty($presaleAdvanceGoods ?? [])) {
                (new CrowdfundingDelayRewardOrder())->DBNew(['list'=>$presaleAdvanceGoods]);
            }

        }
        if(empty($notCreatData)){
            return $orderRes->getData();
        }else{
            return $returnData ?? [];
        }

//        });

//        return $res;
    }

    /**
     * @title  订单详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function info(array $data)
    {
        $orderSn = $data['order_sn'];
        $withoutFiledString = 'id';
        if ($this->module == 'api') {
            $withoutFiledString .= ',seller_remark';
        }

        $info = $this->with(['goods', 'user' => function ($query) {
            $query->field('uid,name as user_name,link_superior_user');
        }, 'pt'])->where(['order_sn' => $orderSn])->withoutField($withoutFiledString)->findOrEmpty()->toArray();
        $hideShipping = true;
        $hideAddress = true;
        $needEncryptPhone = $data['needEncrypt'] ?? 2;
        if (!empty($info)) {
            $nowUid = $data['uid'] ?? null;
            $orderUid = $info['uid'];
            if (!empty($info['user'])) {
                $orderUserLinkUid = $info['user']['link_superior_user'] ?? null;
                if (!empty($data['needChangeLinkUser'] ?? null)) {
                    $info['link_superior_user'] = $orderUserLinkUid;
                }
            }

            if (!empty($nowUid) && ($orderUid != $nowUid) && !empty($orderUserLinkUid) && ($orderUserLinkUid == $nowUid) || (!empty($nowUid) && $orderUid == $nowUid)) {
                $hideShipping = false;
                $hideAddress = false;
            }
//            if(!empty($data['uid']) && ($info['uid'] != $data['uid'])){
//                throw new OrderException(['errorCode'=>1500112]);
//            }
            if ($needEncryptPhone == 1) {
                if (!empty($info['shipping_phone'])) {
                    $info['shipping_phone'] = encryptPhone($info['shipping_phone']);
                }
            }
            $partAfterSale = false;
            if (!empty($info['goods'])) {
                $allRefundPrice = 0;
                $allGoodsPrice = 0;
                $allGoodsSku = array_column($info['goods'], 'sku_sn');
                $goodsSku = GoodsSku::where(['sku_sn' => $allGoodsSku])->column('market_price', 'sku_sn');
                foreach ($info['goods'] as $key => $value) {
                    $allGoodsPrice += $value['total_price'] ?? 0;
                    $info['goods'][$key]['partAfterSale'] = false;

                    if (!in_array($value['after_status'], [1, -1])) {
                        $afterGoods[] = $value['sku_sn'];
                        $allRefundPrice += $value['refund_price'] ?? 0;
                        //判断是否为部分售后
                        if (!empty(doubleval($value['refund_price'])) && (string)$value['refund_price'] < (string)($value['total_price'] - ($value['all_dis'] ?? 0))) {
                            $info['goods'][$key]['partAfterSale'] = true;
                        }
                    }
                    if (!empty($goodsSku[$value['sku_sn']])) {
                        $info['goods'][$key]['market_price'] = priceFormat($goodsSku[$value['sku_sn']] * $value['count']);
                    }

                }
                if (!empty($allRefundPrice) && (string)$allRefundPrice < (string)$allGoodsPrice) {
                    $partAfterSale = true;
                }
                if (!empty($afterGoods)) {
                    $afOrder = AfterSale::where(['order_sn' => $orderSn, 'sku_sn' => $afterGoods, 'status' => [1, -2]])->field('after_sale_sn,order_sn,sku_sn,after_status')->select()->toArray();
                    if (!empty($afOrder)) {
                        foreach ($info['goods'] as $key => $value) {
                            $info['goods'][$key]['after_sale_sn'] = null;
                            $info['goods'][$key]['after_sale_status'] = null;
                            foreach ($afOrder as $sKey => $sValue) {
                                if ($value['sku_sn'] == $sValue['sku_sn']) {
                                    $info['goods'][$key]['after_sale_sn'] = $sValue['after_sale_sn'];
                                    $info['goods'][$key]['after_sale_status'] = $sValue['after_status'];
                                }
                            }
                        }
                    }
                }
                $shipDetail = (new ShippingDetail())->list(['shipping_code' => $info['shipping_code']]);
                $info['shipDetail'] = $shipDetail['list'] ?? [];

            }

            $timeForOrderJob = (new TimerForOrder());
            $info['pay_time_out_time'] = 0;
            $info['receive_time_out_time'] = 0;

            if ($info['order_status'] == 1) {
                $info['pay_time_out_time'] = timeToDateFormat((strtotime($info['create_time'])) + $timeForOrderJob->timeOutSecond);
            }
            if (!empty($info['delivery_time'])) {
                $info['receive_time_out_time'] = timeToDateFormat((strtotime($info['delivery_time'])) + $timeForOrderJob->receiveTimeOutSecond);
            }
            $linkUser = User::with(['link'])->where(['uid' => $info['uid'], 'status' => [1, 2]])->findOrEmpty()->toArray();
            $memberTitle = MemberVdc::where(['status' => 1])->order('level')->column('name', 'level');
            $memberTitle[0] = '普通用户';
            $info['link_user_phone'] = null;
            $info['link_user_name'] = null;
            $info['link_user_level'] = 0;
            $info['link_user_level_name'] = '普通用户';
            if (!empty($linkUser)) {
                if ($needEncryptPhone == 1) {
                    if (!empty($linkUser['link_user_phone'])) {
                        $linkUser['link_user_phone'] = encryptPhone($linkUser['link_user_phone']);
                    }
                }
                $info['link_user_phone'] = $linkUser['link_user_phone'] ?? null;
                $info['link_user_name'] = $linkUser['link_user_name'] ?? null;
                $info['link_user_level'] = $linkUser['link_user_level'] ?? 0;
                $info['link_user_level_name'] = $memberTitle[$info['link_user_level']] ?? '普通用户';
            }
            if ($this->module == 'api') {
                if (!empty($hideAddress)) {
                    $info['shipping_address'] = $this->subAddress($info['shipping_address']);
                }
            }

            $info['hideAddress'] = $hideAddress;
            $info['hideShipping'] = $hideShipping;
        }
        return $info;
    }


    /**
     * @title  检查用户是否有过订单记录
     * @param string $uid 用户uid
     * @param bool $needCache 是否需要缓存信息
     * @return int
     */
    public function checkUserOrder(string $uid, bool $needCache = false)
    {
        return $this->where(['uid' => $uid, 'pay_status' => [1, 2]])->when($needCache, function ($query) use ($uid) {
            $cache = config('cache.systemCacheKey.userOrderCount');
            $cacheKey = $cache['key'] . $uid;
            $query->cache($cacheKey, $cache['expire']);
        })->count();
    }

    /**
     * @title  查看用户某个商品是否购买过
     * @param string $uid 用户uid
     * @param array $goods 商品数组
     * @param array $payType 支付状态
     * @return array
     * @throws \Exception
     */
    public function checkBuyHistory(string $uid, array $goods, array $payType = [1, 2])
    {
        $skuSn = array_column($goods, 'sku_sn');
        $map[] = ['a.uid', '=', $uid];
        $map[] = ['b.sku_sn', 'in', $skuSn];
        $map[] = ['a.pay_status', 'in', $payType];
        $list = Db::name('order')->alias('a')
            ->join('sp_order_goods b', 'a.order_sn = b.order_sn', 'left')
            ->where($map)
            ->field('a.order_sn,b.goods_sn,b.sku_sn,b.title')
            ->order('a.create_time desc')
            ->select()
            ->toArray();
        return $list;
    }

    /**
     * @title  热销订单列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function hotSaleList(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 2)];
        $map[] = ['order_belong', '=', $sear['belong'] ?? $this->belong];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['goods'])->where(['pay_status' => 2])->order('create_time desc')->select()->toArray();
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    public function getPayTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    public function getDeliveryTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    public function getCloseTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    public function getEndTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    /**
     * @title  订单数量汇总
     * @param array $sear
     * @return int
     */
    public function total(array $sear = []): int
    {
        if (!empty($sear['start_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'] . ' 00:00:00')];
        }
        if (!empty($sear['end_time'])) {
            $map[] = ['create_time', '<=', strtotime($sear['end_time'] . ' 23:59:59')];
        }
        $map[] = ['pay_status', 'in', $sear['pay_status'] ?? [2]];
        $map[] = ['order_status', 'in', $sear['order_status'] ?? [2]];
        $info = $this->where($map)->count();
        return $info;
    }

    /**
     * @title  C端用户订单状态数字角标汇总
     * @param array $sear
     * @return array
     */
    public function cOrderStatusSummary(array $sear = [])
    {
        $uid = $sear['uid'];
        if (!empty($sear['order_status'])) {
            if (is_string($sear['order_status'])) {
                $searStatus = [$sear['order_status']];
            } else {
                $searStatus = $sear['order_status'];
            }

        } else {
            $searStatus = [1, 2, 3];
        }
        $map[] = ['order_status', 'in', $searStatus];
        $map[] = ['uid', '=', $uid];
        $map[] = ['order_type', 'not in', [6]];

        //订单状态列表
        $orderList = $this->where($map)->field('count(order_sn) as number,order_status')->group('order_status')->order('order_status asc')->select()->toArray();

        if (!empty($orderList)) {
            //补齐没有数量的状态
            $existOrderStatus = array_column($orderList, 'order_status');
            $existCount = count($orderList);
            foreach ($searStatus as $key => $value) {
                if (!in_array($value, $existOrderStatus)) {
                    $orderList[$existCount]['order_status'] = $value;
                    $orderList[$existCount]['number'] = 0;
                    $existCount++;
                }
            }
            $orders = [];
            foreach ($orderList as $key => $value) {
                $orders[$value['order_status']] = $value['number'];
                unset($orderList[$key]);
            }
            $orderList = $orders;
        }

        //售后状态列表
        $aMap[] = ['uid', '=', $uid];
        $aMap[] = ['after_status', 'in', [1]];
        $afterSaleList = AfterSale::where($aMap)->count();

        $finally = ['order' => $orderList, 'after' => $afterSaleList];
        return $finally;
    }

    /**
     * @title  金额统计
     * @param array $sear
     * @return float
     */
    public function amountTotal(array $sear = [])
    {
        if (!empty($sear['start_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'] . ' 00:00:00')];
        }
        if (!empty($sear['end_time'])) {
            $map[] = ['create_time', '<=', strtotime($sear['end_time'] . ' 23:59:59')];
        }
        $map[] = ['pay_status', 'in', $sear['pay_status'] ?? [2]];
        $map[] = ['order_status', 'in', $sear['order_status'] ?? [2]];
        $sumField = $sear['sumField'] ?? 'real_pay_price';
        $info = $this->where($map)->sum($sumField);
        return $info;
    }

    /**
     * @title  更新的订单备注
     * @param array $data
     * @return Order
     */
    public function orderRemark(array $data)
    {
        $orderSn = $data['order_sn'];
        $save['order_sign'] = $data['order_sign'] ?? null;
        $save['seller_remark'] = $data['seller_remark'] ?? null;
        return $this->baseUpdate(['order_sn' => $orderSn], $save);
    }

    /**
     * @title  订单物流对应的商品列表
     * @param array $data
     * @return array|bool
     * @throws \Exception
     */
    public function shippingCodeAndGoodsInfo(array $data)
    {
        $orderSn = $data['order_sn'] ?? null;
        $shippingCode = $data['shipping_code'] ?? null;
        if (empty($orderSn)) {
            return [];
        }
        if (!empty($shippingCode) && !is_array($shippingCode)) {
            throw new ShipException(['msg' => '物流单号参数有误']);
        }
        $orderInfo = $this->with(['goods'])->where(['order_sn' => $orderSn])->field('order_sn,shipping_code')->findOrEmpty()->toArray();
        if (empty($orderInfo) || empty($orderInfo['shipping_code'])) {
            return [];
        }
        $orderShip = explode(',', $orderInfo['shipping_code']);
        if (empty($shippingCode)) {
            $shippingCode = $orderShip;
        } else {
            foreach ($shippingCode as $key => $value) {
                if (!in_array($value, $orderShip)) {
                    unset($shippingCode[$key]);
//                    throw new ShipException(['msg' => '物流单号 ' . $value . ' 不属于此订单!']);
                }
            }
        }
        if (empty($shippingCode)) {
            throw new ShipException(['msg' => '物流单号参数有误']);
        }

        $shipping = ShipOrder::where(function ($query) use ($orderSn) {
            $aMap[] = ['order_sn', '=', $orderSn];
            $oMap[] = ['parent_order_sn', '=', $orderSn];
            $query->whereOr([$aMap, $oMap]);
        })->where(['shipping_code' => $shippingCode, 'status' => 1])->field('order_sn,parent_order_sn,goods_sku,shipping_code,split_number,split_status,status')->order('create_time desc')->select()->each(function ($item) {
            if (!empty($item['goods_sku'])) {
                $item['goods_sku'] = explode(',', $item['goods_sku']);
            }
            $item['goods'] = [];
            return $item;
        })->toArray();
        if (empty($shipping)) {
            return [];
        }
        foreach ($shipping as $key => $value) {
            foreach ($value['goods_sku'] as $gKey => $gValue) {
                $allSku[] = $gValue;
            }
        }
        if (empty($allSku)) {
            return [];
        }

        $allSku = array_unique(array_filter($allSku));
        $allSkuList = OrderGoods::where(['sku_sn' => $allSku, 'order_sn' => $orderSn, 'status' => 1, 'pay_status' => 2])->field('order_sn,goods_sn,sku_sn,count,title,images,specs,status,pay_status')->order('create_time desc')->select()->toArray();

        if (empty($allSkuList)) {
            return [];
        }
        foreach ($shipping as $key => $value) {
            foreach ($allSkuList as $gKey => $gValue) {
                if (in_array($gValue['sku_sn'], $value['goods_sku'])) {
                    //如果是拆数量的商品发货订单只会剩一个SKU了,所有只要拆数量的标识不为空就可以把商品信息数组中的数量修改为拆数量的数字以用于前端统一展示
                    if ($value['split_status'] == 1 && !empty($value['split_number']) && $value['split_number'] > 0) {
                        $gValue['count'] = $value['split_number'];
                    }
                    $shipping[$key]['goods'][] = $gValue;
                }
            }
        }
        if (!empty($shipping)) {
            $delShipping = $shipping;
            unset($shipping);
            foreach ($delShipping as $key => $value) {
                $shipping[$value['shipping_code']] = $value;
            }
        }
        return $shipping ?? [];
    }

    /**
     * @title  用户列表(包含用户自购订单数据)
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function userSelfBuyOrder(array $sear = []): array
    {
        $sear['needAllLevel'] = true;
        $sear['needOrderSummary'] = true;
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name|b.phone', trim($sear['keyword'])))];
        }
        if (!empty($sear['order_start_time']) && !empty($sear['order_end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['order_start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['order_end_time'])];
        }
        if (!empty($sear['topUserPhone'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone', trim($sear['topUserPhone'])))];
            $uMap[] = ['status', '=', 1];
            $topUserUid = User::where($uMap)->column('uid');
            if (!empty($topUserUid)) {
                $map[] = ['b.link_superior_user', 'in', $topUserUid];
            }
        }

        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        $map[] = ['a.order_status', 'in', [2, 3, 4, 8]];
        $map[] = ['a.after_status', 'in', [1, 5, -1]];
        $map[] = ['a.pay_status', '=', 2];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            if (($sear['sortType'] ?? 1) == 1) {
                if (!empty(trim($sear['keyword'] ?? null))) {
                    $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', trim($sear['keyword'])))];
                }
                $uMap[] = ['status', '=', 1];
                $aTotal = User::where($uMap)->count();
            } else {
                $aTotal = $this->alias('a')->when(!empty($sear['keyword']), function ($query) {
                    $query->join('sp_user b', 'a.uid = b.uid', 'left');
                })->where($map)->group('a.uid')->count();
            }

            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $vipTitle = MemberVdc::where(['status' => [1, 2]])->column('name', 'level');
        switch ($sear['sortType'] ?? 1) {
            case 1:
                $sortField = 'b.create_time desc';
                $oneSortField = 'b.create_time desc';
                break;
            case 2:
                $sortField = 'order_summary_sum desc';
                $oneSortField = 'a.create_time desc';
                break;
            case 3:
                $sortField = 'order_summary_count desc';
                $oneSortField = 'a.create_time desc';
                break;
        }

        //先按照订单汇总查询前十人,然后再根据条件统计订单
        if (!empty($page)) {
            if (($sear['sortType'] ?? 1) == 1) {
                if (!empty(trim($sear['keyword'] ?? null))) {
                    $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', trim($sear['keyword'])))];
                }
                $uMap[] = ['status', '=', 1];
                $uidLists = User::where($uMap)->order('create_time desc')->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->field('uid,name,phone,vip_level,avatarUrl,growth_value,create_time,link_superior_user')->select()->toArray();
                if (!empty($uidLists)) {
                    $uidList = array_column($uidLists, 'uid');
                }
                if (empty($uidList)) {
                    return ['list' => [], 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
                }
                $map[] = ['a.uid', 'in', $uidList];
            } else {
                //8.0的先排序后分组 子查询不仅需要limit,外层不仅要group还要order
//                $uidListSql = $this->alias('a')->join('sp_user b', 'a.uid = b.uid', 'left')->field('a.uid,a.order_sn,a.create_time')->where($map)->order($oneSortField)->limit(9999)->buildSql();
//                $twoSortField = str_replace('a.', 'temp.', $oneSortField);
//                $uidList = Db::table($uidListSql . " temp")->group('temp.uid')->order($twoSortField)
//                    ->when($page, function ($query) use ($page) {
//                        $query->page($page, $this->pageNumber);
//                    })->column('temp.uid');
            }


        }

        $list = $this->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_user c', ' b.link_superior_user = c.uid', 'left')
            ->field('a.uid,b.name,b.phone,b.vip_level,b.avatarUrl,b.growth_value,b.create_time,sum(if(a.order_status not in (1,-1,-2,-3,-4),a.real_pay_price,0)) as order_summary_sum,sum(if(a.order_status not in (1,-1,-2,-3,-4),1,0)) as order_summary_count,b.link_superior_user,c.uid as link_user_uid,c.name as link_user_name,c.phone as link_user_phone,c.vip_level as link_user_level')
            ->where($map)
            ->when(($page && !empty($sear['sortType'] ?? null) && (in_array($sear['sortType'], [2, 3]))), function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->group('a.uid')->order($sortField)
//            ->buildSql();
//            dump($list);die;
            ->select()->each(function ($item) use ($vipTitle) {
                $item['vip_name'] = $vipTitle[$item['vip_level']] ?? '普通用户';
                if (empty($item['link_user_level'])) {
                    $item['link_user_level'] = 0;
                }
                $item['link_user_vip_name'] = $vipTitle[$item['link_user_level']] ?? '普通用户';
            })->toArray();

        //如果查出来的用户名单跟实际的名单不同,则补齐
        if (count($list ?? []) != count($uidList ?? [])) {
            if ($sear['sortType'] == 1) {
                $allExistOrderList = array_column($list, 'uid');
                foreach ($uidLists as $key => $value) {
                    if (!in_array($value['uid'], $allExistOrderList)) {
                        $notOrderUser[] = $value;
                        $notOrderUid[] = $value['uid'];
                    }
                }
                if (!empty($notOrderUser)) {
                    $notOrderUserList = User::with(['link'])->where(['uid' => $notOrderUid])->field('uid,name,phone,vip_level,avatarUrl,growth_value,create_time,link_superior_user')->order('create_time desc')->select()->each(function ($item) use ($vipTitle) {
                        $item['order_summary_sum'] = "0.00";
                        $item['order_summary_count'] = 0;
                        $item['vip_name'] = $vipTitle[$item['vip_level']] ?? '普通用户';
                        if (empty($item['link_user_level'])) {
                            $item['link_user_level'] = 0;
                        }
                        $item['link_user_vip_name'] = $vipTitle[$item['link_user_level']] ?? '普通用户';
                    })->toArray();
                    if (!empty($notOrderUserList)) {
                        $list = array_merge_recursive($list, $notOrderUserList);
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }


    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid');
    }

    public function goods()
    {
        return $this->hasMany('OrderGoods', 'order_sn', 'order_sn')->field('order_sn,goods_sn,sku_sn,count,price,total_price,real_pay_price,all_dis,title,images,user_level,specs,after_status,total_fare_price,sale_price,status,shipping_status,refund_price,supplier_pay_status,crowd_code,crowd_round_number,crowd_period_number,pay_status,crowd_code,crowd_round_number,crowd_period_number,allow_after,allow_after_type');
    }


    public function coupon()
    {
        return $this->hasMany('OrderCoupon', 'order_sn', 'order_sn');
    }

    public function attach()
    {
        return $this->hasOne('OrderAttach', 'order_sn', 'order_sn')->where(['status' => [1, 2]]);
    }

    public function pt()
    {
        return $this->hasOne('PtOrder', 'order_sn', 'order_sn');
    }

    /**
     * 隐藏地址部分信息
     * @param $address
     * @return bool|string
     */
    public function subAddress($address)
    {
        $endSign = strpos($address, '区');
        if (!$endSign) $endSign = strpos($address, '县');
        if (!$endSign) $endSign = strpos($address, '岛');
        if (!$endSign) $endSign = strpos($address, '市');
        if (!$endSign) $endSign = strpos($address, '省');

        return substr($address, 0, $endSign + 3) . '*****';
    }

    /**
     * @title  众筹订单列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function crowdOrderList(array $sear)
    {
        $map = [];
        $list = [];
        $needSearGoods = false;
        $needSearUser = false;
        $needSearSupplierGoods = false;
        $supplierAllSku = [];

        //查找订单编号
        if (!empty($sear['searOrderSn'])) {
            $map[] = ['a.order_sn', '=', $sear['searOrderSn']];
        }
        if (!empty($sear['searUserName'])) {
            $map[] = ['b.name', '=', $sear['searUserName']];
            $needSearUser = true;
        }
        if (!empty($sear['searUserPhone'])) {
            $map[] = ['a.user_phone', '=', $sear['searUserPhone']];
        }
        //订单类型
        if (!empty($sear['order_type'])) {
            $map[] = ['a.order_type', '=', $sear['order_type']];
        }
        //不查找的订单类型
        if (!empty($sear['not_order_type'])) {
            $map[] = ['a.order_type', 'not in', $sear['order_type']];
        }
        //订单状态
        if (!empty($sear['searType'] ?? null)) {
            if (!is_array($sear['searType'])) {
                $sear['searType'] = [$sear['searType']];
            }
            $map[] = ['a.order_status', 'in', $sear['searType'] ?? [1, 2, 5, 6]];
        }

        //众筹活动状态
        if (!empty($sear['crowd_result_status'] ?? null)) {
            if (is_array($sear['crowd_result_status'])) {
                $map[] = ['c.result_status', 'in', $sear['crowd_result_status']];
            } else {
                $map[] = ['c.result_status', '=', $sear['crowd_result_status']];
            }
        }
        //众筹区
        if (!empty($sear['crowd_activity_code'])) {
            $map[] = ['c.activity_code', '=', $sear['crowd_activity_code']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
        }

        if (!empty($sear['delivery_start_time']) && !empty($sear['delivery_end_time'])) {
            $map[] = ['a.end_time', '>=', strtotime($sear['delivery_start_time'])];
            $map[] = ['a.end_time', '<=', strtotime($sear['delivery_end_time'])];
        }
        $map[] = ['a.order_type', 'in', [6]];
        $map[] = ['a.uid', '=', $sear['uid']];
        $map[] = ['a.order_status', 'not in', [-1, -2]];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = Db::name('order')->alias('a')
                ->join('sp_order_goods b', 'a.order_sn = b.order_sn', 'left')
                ->join('sp_crowdfunding_period c', 'c.activity_code = b.crowd_code and b.crowd_round_number = c.round_number and b.crowd_period_number = c.period_number', 'left')->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $successPeriodNumber = CrowdfundingSystemConfig::where(['id' => 1])->value('success_period_number');

        $list = Db::name('order')->alias('a')
            ->join('sp_order_goods b', 'a.order_sn = b.order_sn', 'left')
            ->join('sp_crowdfunding_period c', 'c.activity_code = b.crowd_code and b.crowd_round_number = c.round_number and b.crowd_period_number = c.period_number', 'left')
            ->join('sp_crowdfunding_activity d', 'd.activity_code = b.crowd_code', 'left')
            ->where($map)
            ->field('a.id,a.order_sn,a.order_belong,a.order_type,a.uid,a.user_phone,a.pay_no,a.item_count,a.total_price,a.fare_price,a.discount_price,a.real_pay_price,a.pay_type,a.pay_status,a.order_status,a.create_time,a.pay_time,a.end_time,a.shipping_code,a.shipping_name,a.shipping_address,a.shipping_phone,a.shipping_type,a.sync_status,a.after_status,a.split_status,a.order_remark,a.update_time,a.sync_order_update_time,a.shipping_status,a.user_level,a.link_superior_user,a.handsel_sn,a.end_time as delivery_time,a.can_sync,b.goods_sn,b.sku_sn,b.title,b.count,b.images,b.total_fare_price,b.real_pay_price,b.shipping_status,b.crowd_code,b.crowd_round_number,b.crowd_period_number,c.title as period_title,d.title as activity_title,c.result_status,c.buy_status,a.crowd_fuse_status,a.crowd_fuse_type,a.crowd_fuse_time')
            ->order('a.create_time desc,a.id asc')
            ->group('a.order_sn')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->select()->each(function ($item) use ($successPeriodNumber) {
                if (!empty($item['create_time'])) {
                    $item['create_time'] = timeToDateFormat($item['create_time']);
                }
                if (!empty($item['pay_time'])) {
                    $item['pay_time'] = timeToDateFormat($item['pay_time']);
                }
                $item['will_success_period'] = $item['crowd_period_number'] + $successPeriodNumber;
                $item['exist_lottery'] = false;
                $item['lottery_plan_sn'] = null;
                $item['user_exist_lottery'] = false;
                $item['lottery_status'] = null;
                $item['user_lottery_status'] = false;
                return $item;
            })->toArray();

//        $listSql = Db::name('order')->alias('a')
//            ->join('sp_order_goods b', 'a.order_sn = b.order_sn', 'left')
//            ->join('sp_crowdfunding_period c', 'c.activity_code = b.crowd_code and b.crowd_round_number = c.round_number and b.crowd_period_number = c.period_number', 'left')
//            ->join('sp_crowdfunding_activity d', 'd.activity_code = b.crowd_code', 'left')
//            ->where($map)
//            ->field('a.id,a.order_sn,a.order_belong,a.order_type,a.uid,a.user_phone,a.pay_no,a.item_count,a.total_price,a.fare_price,a.discount_price,a.real_pay_price,a.pay_type,a.pay_status,a.order_status,a.create_time,a.pay_time,a.end_time,a.shipping_code,a.shipping_name,a.shipping_address,a.shipping_phone,a.shipping_type,a.sync_status,a.after_status,a.split_status,a.order_remark,a.update_time,a.sync_order_update_time,a.shipping_status,a.user_level,a.link_superior_user,a.handsel_sn,a.end_time as delivery_time,a.can_sync,b.goods_sn,b.sku_sn,b.title,b.count,b.images,b.total_fare_price,b.crowd_code,b.crowd_round_number,b.crowd_period_number,c.title as period_title,d.title as activity_title,c.result_status,c.buy_status,a.crowd_fuse_status,a.crowd_fuse_type,a.crowd_fuse_time')
//            ->group('a.order_sn')
//            ->buildSql();
//        $list = Db::table($listSql . ' a')->order('a.create_time desc,a.id asc')->when($page, function ($query) use ($page) {
//            $query->page($page, $this->pageNumber);
//        })
//            ->select()->each(function ($item) use ($successPeriodNumber) {
//            if (!empty($item['create_time'])) {
//                $item['create_time'] = timeToDateFormat($item['create_time']);
//            }
//            if (!empty($item['pay_time'])) {
//                $item['pay_time'] = timeToDateFormat($item['pay_time']);
//            }
//            $item['will_success_period'] = $item['crowd_period_number'] + $successPeriodNumber;
//            $item['exist_lottery'] = false;
//            $item['lottery_plan_sn'] = null;
//            $item['user_exist_lottery'] = false;
//            $item['lottery_status'] = null;
//            $item['user_lottery_status'] = false;
//            return $item;
//        })->toArray();

        if (!empty($list)) {
            $period = [];
            foreach ($list as $key => $value) {
                if ($value['result_status'] == 1) {
                    $period[$key]['activity_code'] = $value['crowd_code'];
                    $period[$key]['round_number'] = $value['crowd_round_number'];
                    $period[$key]['period_number'] = $value['crowd_period_number'];
                }
            }
            if (!empty($period)) {
                $gWhere[] = ['status', '=', 1];
                $periodLottery = CrowdfundingLottery::where(function ($query) use ($period) {
                    $number = 0;
                    foreach ($period as $key => $value) {
                        ${'where' . ($number + 1)}[] = ['activity_code', '=', $value['activity_code']];
                        ${'where' . ($number + 1)}[] = ['round_number', '=', $value['round_number']];
                        ${'where' . ($number + 1)}[] = ['period_number', '=', $value['period_number']];
                        $number++;
                    }

                    for ($i = 0; $i < count($period); $i++) {
                        $allWhereOr[] = ${'where' . ($i + 1)};
                    }
                    $query->whereOr($allWhereOr);
                })->where($gWhere)->select()->toArray();
                if (!empty($periodLottery)) {
                    //剔除未开始的申请抽奖的计划
                    foreach ($periodLottery as $key => $value) {
                        if ($value['lottery_status'] == 3 && $value['apply_start_time'] > time()) {
                            unset($periodLottery[$key]);
                        }
                    }
                    if (!empty($periodLottery)) {
                        foreach ($list as $key => $value) {
                            foreach ($periodLottery as $lKey => $lValue) {
                                if ($value['crowd_code'] == $lValue['activity_code'] && $value['crowd_round_number'] == $lValue['round_number'] && $value['crowd_period_number'] == $lValue['period_number']) {
                                    $list[$key]['exist_lottery'] = true;
                                    $list[$key]['lottery_status'] = $lValue['lottery_status'];
                                    $list[$key]['lottery_plan_sn'] = $lValue['plan_sn'];
                                    $list[$key]['lottery_start_time'] = timeToDateFormat($lValue['lottery_start_time']);
                                    $checkJoinLotteryPlan[] = $lValue['plan_sn'];
                                }
                            }
                        }
                    }
                    //查询用户是否报名抽奖活动
                    if (!empty($checkJoinLotteryPlan ?? [])) {
                        $userApplyList = CrowdfundingLotteryApply::where(['plan_sn' => array_unique($checkJoinLotteryPlan), 'uid' => $sear['uid'], 'status' => 1])->column('win_status', 'plan_sn');
                        if (!empty($userApplyList)) {
                            foreach ($list as $key => $value) {
                                if (!empty($value['lottery_plan_sn'] ?? null) && !empty($userApplyList[$value['lottery_plan_sn']] ?? null)) {
                                    $list[$key]['user_exist_lottery'] = true;
                                    $list[$key]['user_lottery_status'] = $userApplyList[$value['lottery_plan_sn']];
                                }
                            }
                        }
                    }
                }
            }

        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  协议支付中查询订单状态
     * @param array $data
     * @return mixed
     */
    public function agreementCheckOrderStatus(array $data)
    {
        //订单类型 1为普通订单 2为充值订单
        $orderChannel = $data['order_channel']  ?? 1;
        //优先查询缓存, 没有再查询数据库
        if ($orderChannel == 1) {
            $cacheInfo = cache((new OrderService())->agreementOrderCacheHeader . $data['order_sn']);
            if (!empty($cacheInfo)) {
                return $cacheInfo;
            }
            $orderInfo = self::where(['order_sn' => $data['order_sn']])->field('order_status,pay_status,order_sn,uid')->findOrEmpty()->toArray();
        } else {
            $cacheInfo = cache((new OrderService())->agreementCrowdOrderCacheHeader . $data['order_sn']);
            if (!empty($cacheInfo)) {
                return $cacheInfo;
            }
            $orderInfo = CrowdfundingBalanceDetail::where(['order_sn' => $data['order_sn']])->field('status,order_sn,uid')->findOrEmpty()->toArray();
            if ($orderInfo['status'] == 1) {
                $orderInfo['order_status'] = 2;
                $orderInfo['pay_status'] = 2;
            } else {
                $orderInfo['order_status'] = 1;
                $orderInfo['pay_status'] = 1;
            }
        }

        return $orderInfo;
    }
}