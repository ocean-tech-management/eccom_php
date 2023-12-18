<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼订单模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\PpylException;
use app\lib\services\CodeBuilder;
use app\lib\services\Ppyl;
use Exception;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Queue;

class PpylOrder extends BaseModel
{
    protected $validateFields = ['order_sn', 'activity_code', 'uid'];

    /**
     * @title  拼拼有礼订单列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('activity_title|activity_sn', $sear['keyword']))];
        }

        if ($this->module == 'api') {
            $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searOrderType'] ?? 2)];
            $map[] = ['activity_status', 'in', [1]];
            $map[] = ['pay_status', 'in', [2]];
        }

        if (!empty($sear['searOrderSn'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', trim($sear['searOrderSn'])))];
        }

        if (!empty($sear['searUserName'])) {
            $userMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', trim($sear['searUserName'])))];
            $userMap[] = ['status', '=', 1];
            $user = User::where($userMap)->column('uid');
            if (!empty($user)) {
                $map[] = ['uid', 'in', $user];
            }
        }
        if (!empty($sear['searUserPhone'])) {
            $userMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone', trim($sear['searUserPhone'])))];
            $userMap[] = ['status', '=', 1];
            $user = User::where($userMap)->column('uid');
            if (!empty($user)) {
                $map[] = ['uid', 'in', $user];
            }
        }

        if (!empty($sear['searPayNo'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('pay_no', trim($sear['searPayNo'])))];
        }

        if (!empty($sear['user_role'])) {
            $map[] = ['user_role', '=', $sear['user_role']];
        }

        if ($this->module == 'admin') {
            if (!empty($sear['searType'])) {
                $map[] = ['activity_status', 'in', $sear['searType']];
            }
            if (!empty($sear['pay_status'])) {
                $map[] = ['pay_status', '=', $sear['pay_status']];
            }
        }

        if (!empty($sear['activity_code'])) {
            $map[] = ['activity_code', '=', $sear['activity_code']];
        }
        if (!empty($sear['area_code'])) {
            $map[] = ['area_code', '=', $sear['area_code']];
        }

        if (!empty($sear['shipping_status'])) {
            $map[] = ['shipping_status', '=', $sear['shipping_status']];
        }


        //查找商品
        if (!empty($sear['searGoodsSpuSn'])) {
            if (is_array($sear['searGoodsSpuSn'])) {
                $map[] = ['goods_sn', 'in', $sear['searGoodsSpuSn']];
            } else {
                $map[] = ['goods_sn', '=', $sear['searGoodsSpuSn']];
            }
        }
        if (!empty($sear['searGoodsSkuSn'])) {
            if (is_array($sear['searGoodsSkuSn'])) {
                $map[] = ['sku_sn', 'in', $sear['searGoodsSkuSn']];
            } else {
                $map[] = ['sku_sn', '=', $sear['searGoodsSkuSn']];
            }
        }

        if ($this->module == 'api') {
            if (!empty($sear['uid'])) {
                $map[] = ['uid', '=', $sear['uid']];
            }
            $map[] = ['end_time', '>', time()];
        }


        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }

//        //后台只查开团的订单,参团的之后的查询补上
//        if($this->module == 'admin'){
//            $map[] = ['user_role', '=', 1];
//        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = self::where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = self::with(['activity', 'area', 'user', 'goods','refundPayNo','refundWaitPayNo'])->where($map)
            ->field('order_sn,activity_sn,activity_code,area_code,activity_title,uid,activity_type,join_user_type,start_time,end_time,group_number,activity_status,create_time,goods_sn,sku_sn,close_time,draw_time,group_time,lottery_time,reward_build_time,user_role,real_pay_price,pay_status,pay_type,pay_no,pay_order_sn,shipping_status,shipping_time,is_auto_order,win_status,refund_status')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->select()->each(function ($item) {
                if (!empty($item['refundPayNo'] ?? null)) {
                    $item['pay_status'] = -2;
                }
                //排队订单存在退款复用该流水号的全部拼拼订单也显示退款
                if (!empty($item['refundWaitPayNo'] ?? null)) {
                    $item['pay_status'] = -2;
                }
            })->toArray();

        if (!empty($list)) {
            $activitySn = array_column($list, 'activity_sn');
            $joinPtList = $this->with(['userBind','refundPayNo'])->where(['activity_sn' => $activitySn, 'pay_status' => [1, 2, -2, 3]])->field('activity_sn,uid,user_role,activity_status,pay_status,win_status,refund_price,refund_time,reward_price,reward_receive_status,reward_generate_time,reward_timeout_time,shipping_status,shipping_time,create_time,close_time,draw_time,group_time,lottery_time,reward_build_time,pay_no,pay_order_sn,shipping_status,shipping_time,is_auto_order,refund_status')->order('user_role asc')->select()->each(function ($item) {
                if (!empty($item['refundPayNo'] ?? null)) {
                    $item['pay_status'] = -2;
                }
            })->toArray();
            if (!empty($sear['needUserArray'] ?? false)) {
                foreach ($list as $key => $value) {
                    unset($list[$key]['user']);
                }
            }
            if (!empty($joinPtList)) {
                foreach ($list as $key => $value) {
                    foreach ($joinPtList as $cKey => $cValue) {
                        if ($value['activity_sn'] == $cValue['activity_sn']) {
                            $list[$key]['group'][] = $cValue;
                            if (!empty($sear['needUserArray'] ?? false)) {
                                $list[$key]['user'][] = $cValue;
                            }
                        }
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户拼团详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function ptInfo(array $data)
    {
        $areaCode = $data['area_code'];
        $activitySn = $data['activity_sn'];
//        $ptOrder = $this->with(['activity', 'goods', 'user'])->where(['activity_code' => $activityCode, 'activity_sn' => $activitySn,'activity_status'=>[1,2,3,-2,-1],'pay_status'=>[1,2,-2,-1]])->withoutField('id,update_time')->order('user_role asc,create_time asc')->select()->toArray();
        $ptOrder = $this->with(['activity', 'goods', 'user'])->where(['area_code' => $areaCode, 'activity_sn' => $activitySn, 'activity_status' => [1, 2, 3, -2, -3], 'pay_status' => [1, 2, -2]])->withoutField('id,update_time')->order('user_role asc,create_time asc')->select()->toArray();
        return $ptOrder;
    }

    /**
     * @title  用户拼团列表
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function userPtList(array $data)
    {
        if (!empty($data['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('activity_title|area_name', $data['keyword']))];
        }
        $map[] = ['uid', '=', $data['uid']];

        if (!empty($data['activity_status'])) {
            if (is_array($data['activity_status'])) {
                $map[] = ['activity_status', 'in', $data['activity_status']];
            } else {
                $map[] = ['activity_status', '=', $data['activity_status']];
            }
        }
        if (!empty($data['win_status'])) {
            $map[] = ['win_status', '=', $data['win_status']];
        }
        if (!empty($data['refund_status'])) {
            $map[] = ['refund_status', '=', $data['refund_status']];
        }
        if (!empty($data['can_operate_refund'])) {
            $map[] = ['can_operate_refund', '=', $data['can_operate_refund']];
        }

        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($data['start_time'])];
            $map[] = ['create_time', '<=', strtotime($data['end_time'])];
        }

        $page = intval($data['page'] ?? 0) ?: null;
        if (!empty($data['pageNumber'])) {
            $this->pageNumber = intval($data['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['goods'])->where($map)->withoutField('id,update_time')
            ->withCount(['joinNumber'])
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->select()->each(function ($item) {
//                if (!empty($item['goods']) && !empty($item['goods']['image'])) {
//                    $item['goods']['image'] .= '?x-oss-process=image/format,webp';
//                }
                //auto_original_order 1代表自动计划原始订单 2为不是
                $item['auto_original_order'] = 2;
                if (!empty($item['is_auto_order']) && $item['is_auto_order'] == 2 && in_array($item['activity_status'], [1])) {
                    $map[] = ['uid', '=', $item['uid']];
                    $map[] = ['status', '=', 1];
                    $map[] = ['order_sn', '=', $item['order_sn']];
                    $planOrder = PpylAuto::where($map)->count();
                    if (!empty($planOrder)) {
                        $item['auto_original_order'] = 1;
                    }
                }
                //auto_plan 1为显示取消自动计划按钮 2为不显示
                $item['auto_plan'] = 2;
                if (($item['auto_original_order'] == 1 || $item['is_auto_order'] == 1) && in_array($item['activity_status'], [1])) {
                    //检查自动计划是否正在进行,如果正在进行才可以取消
                    $mapOnline[] = ['uid', '=', $item['uid']];
                    $mapOnline[] = ['status', '=', 1];
                    $orderSn = $item['order_sn'];
                    $planOnline = PpylAuto::where($mapOnline)->where(function ($query) use ($orderSn) {
                        $map1[] = ['order_sn', '=', $orderSn];
                        $map2[] = ['', 'exp', Db::raw('FIND_IN_SET("' . $orderSn . '",`restart_order_sn`)')];
                        $query->whereOr([$map1, $map2]);
                    })->count();
                    if (!empty($planOnline)) {
                        $item['auto_plan'] = 1;
                    }

                }
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户拼拼有礼列表数据面板
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function userPpylSummary(array $data)
    {
        if (!empty($data['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('activity_title|area_name', $data['keyword']))];
        }
        $map[] = ['uid', '=', $data['uid']];

        if (!empty($data['activity_status'])) {
            if (is_array($data['activity_status'])) {
                $map[] = ['activity_status', 'in', $data['activity_status']];
            } else {
                $map[] = ['activity_status', '=', $data['activity_status']];
            }
        }
        if (!empty($data['win_status'])) {
            $map[] = ['win_status', '=', $data['win_status']];
        }

        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($data['start_time'])];
            $map[] = ['create_time', '<=', strtotime($data['end_time'])];
        }

        //,sum(1) as all_number
        $info = $this->where($map)->field('uid,sum(if(activity_status in (1) or (activity_status = 2 and win_status =  3),1,0)) as ing_number,sum(if(activity_status = 2 and win_status = 1,1,0)) as win_number,sum(if((activity_status in (-3)),1,0)) as fail_number,sum(if((pay_status in (-1)),1,0)) as no_pay_number')->findOrEmpty()->toArray();

        if (empty($info)) {
            $info['uid'] = $data['uid'];
            $info['all_number'] = 0;
            $info['ing_number'] = 0;
            $info['win_number'] = 0;
            $info['fail_number'] = 0;
            $info['no_pay_number'] = 0;
        }else{
            $info['all_number'] = ($info['ing_number'] ?? 0) +  ($info['win_number'] ?? 0) +  ($info['fail_number'] ?? 0);
        }
        return $info ?? [];
    }

    /**
     * @title  用户拼拼有礼中奖列表
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function userWinPpylOrderList(array $data)
    {
        if (!empty($data['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('activity_title|area_name', $data['keyword']))];
        }
        $map[] = ['uid', '=', $data['uid']];

        $map[] = ['activity_status', '=', 2];
        $map[] = ['win_status', '=', 1];
        $map[] = ['status', 'in', [1,-2]];
        if (!empty($data['shipping_status'])) {
            $map[] = ['shipping_status', '=', $data['shipping_status']];
        }
        if (!empty($data['area_code'])) {
            $map[] = ['area_code', '=', $data['area_code']];
        }

        $page = intval($data['page'] ?? 0) ?: null;
        if (!empty($data['pageNumber'])) {
            $this->pageNumber = intval($data['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->group('area_code')->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['winGoods' => function ($query) use ($map, $data) {
            $query->where($map)->field('activity_sn,area_code,order_sn,goods_sn,sku_sn,lottery_time,shipping_status')->order('lottery_time desc')->withLimit(6);
        }, 'area'])->where($map)->group('area_code')->field('area_name,area_code,activity_sn,activity_title,lottery_time,sum(if(shipping_status = 1 and status = 1,1,0)) as shipping_number,sum(if(shipping_status = 2,1,0)) as repurchase_number,sum(if(shipping_status = 3,1,0)) as wait_select_number,sum(1) as all_number,uid')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->select()->each(function ($item) {
//                $item['repurchase_number'] = 0;
//                $item['shipping_number'] = 0;
//                $item['wait_select_number'] = 0;
//                $item['all_number'] = 0;
                $item['repurchase_capacity'] = 0;
            })->toArray();

        if (!empty($list)) {
            $allArea = array_unique(array_column($list, 'area_code'));
            $allUserRepurchase = UserRepurchase::where(['uid' => $data['uid'], 'area_code' => $allArea, 'status' => 1])->select()->toArray();
            if (!empty($allUserRepurchase)) {
                foreach ($list as $key => $value) {
                    foreach ($allUserRepurchase as $aK => $aV) {
                        if ($value['area_code'] == $aV['area_code']) {
                            $list[$key]['repurchase_capacity'] = $aV['repurchase_capacity'] ?? 0;
                        }
                    }
                }
            }
            foreach ($list as $key => $value) {
                if (!empty($value['winGoods'] ?? [])) {
                    foreach ($value['winGoods'] as $skuKey => $skuValue) {
//                        $list[$key]['all_number'] += 1;
//                        switch ($skuValue['shipping_status']) {
//                            case 1:
//                                $list[$key]['shipping_number'] += 1;
//                                break;
//                            case 2:
//                                $list[$key]['repurchase_number'] += 1;
//                                break;
//                            case 3:
//                                $list[$key]['wait_select_number'] += 1;
//                                break;
//                        }
                        $allGoodsSku[] = $skuValue['sku_sn'];

                    }
                }
            }

            if (!empty($allGoodsSku) && (!empty($data['needGoodsInfo'] ?? false) && $data['needGoodsInfo'] == 1)) {
                $allGoodsInfo = GoodsSku::where(['sku_sn' => array_unique($allGoodsSku)])->field('goods_sn,sku_sn,title,specs,market_price,sale_price,image,status')->select()->toArray();
                if (!empty($allGoodsInfo)) {
                    foreach ($allGoodsInfo as $key => $value) {
                        $goodsInfo[$value['sku_sn']] = $value;
                    }
                    foreach ($list as $key => $value) {
                        if (!empty($value['winGoods'] ?? [])) {
                            foreach ($value['winGoods'] as $skuKey => $skuValue) {
                                $list[$key]['winGoods'][$skuKey]['goodsInfo'] = $goodsInfo[$skuValue['sku_sn']] ?? [];
                            }
                        }
                    }
                }
            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户拼拼有礼中奖专区详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function userWinPpylOrderInfo(array $data)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('activity_title|area_name', $sear['keyword']))];
        }
        $map[] = ['uid', '=', $data['uid']];
        $map[] = ['area_code', '=', $data['area_code']];
        $map[] = ['activity_status', '=', 2];
        $map[] = ['win_status', '=', 1];

        if (!empty($data['shipping_status'])) {
            $map[] = ['shipping_status', '=', $data['shipping_status']];
        }

        $page = intval($data['page'] ?? 0) ?: null;
        if (!empty($data['pageNumber'])) {
            $this->pageNumber = intval($data['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['orderGoods' => function ($query) use ($map, $data) {
            $query->field('order_sn,goods_sn,sku_sn,price,sale_price,total_price,title,images,specs,total_fare_price,shipping_status,real_pay_price');
        },'nowGoods'])->where($map)->field('area_name,area_code,activity_sn,activity_title,order_sn,goods_sn,sku_sn,lottery_time,shipping_status,group_time,lottery_time,reward_build_time,shipping_time')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户选择中奖订单寄售或者寄出(新方法,累计制,发几得退几的机会)
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function winOrderShipping(array $data)
    {
        $areaCode = $data['area_code'];
        $orders = $data['order'];
        //type 1为发货 2为寄售 3为混合
        $type = $data['type'] ?? 1;
        if (empty($areaCode) || empty($orders)) {
            return false;
        }
        $userInfo = [];
        $orderInfo = [];

        $areaInfo = PpylArea::where(['area_code' => $areaCode, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($areaInfo)) {
            throw new PpylException(['errorCode' => 2100101]);
        }
//        if (count($orders) < $areaInfo['repurchase_condition']) {
//            throw new PpylException(['msg' => '请至少选择' . $areaInfo['repurchase_condition'] . '条订单记录']);
//        }
        $orderSn = array_unique(array_column($orders, 'order_sn'));
        $orderInfos = PpylOrder::with(['orderGoods'])->where(['order_sn' => $orderSn, 'shipping_status' => 3, 'win_status' => 1, 'pay_status' => 2, 'area_code' => $areaCode])->select()->toArray();
        if (count($orderInfos) < count($orders)) {
            throw new PpylException(['msg' => '存在不可操作的记录!']);
        }
        if (empty($orderInfos)) {
            throw new PpylException(['msg' => '暂无可操作的记录']);
        }
        foreach ($orderInfos as $key => $value) {
            $orderInfo[$value['order_sn']] = $value;
        }
//        $maxShippingNumber = $areaInfo['repurchase_condition'] - $areaInfo['repurchase_number'];
        $minShippingNumber = $areaInfo['shipping_unit'];
        if ($type != 2) {
            if ($minShippingNumber <= 0) {
                throw new PpylException(['msg' => '该专区暂无法选择发货']);
            }
        }

        $nowShippingNumber = 0;
        $nowRepurchase = 0;
        foreach ($orders as $key => $value) {
            if ($value['shipping_status'] == 1) {
                $nowShippingNumber += 1;
            }
            if ($value['shipping_status'] == 2) {
                $nowRepurchase += 1;
            }
        }

        if ($type != 2) {
            if (empty($nowShippingNumber)) {
                throw new PpylException(['msg' => '发货的订单数量不允许为0']);
            }
            if ($nowShippingNumber < $minShippingNumber) {
                throw new PpylException(['msg' => '发货的订单数量不允许小于' . $minShippingNumber . '单']);
            }
            if (!empty($nowShippingNumber % $minShippingNumber)) {
                throw new PpylException(['msg' => '发货的订单数量必须为' . $minShippingNumber . '的倍数']);
            }

            //发货的情况下判断商品是否上架,如果存在没上架的商品需要拦下来
            $allSku = array_unique(array_column($orders, 'sku_sn'));
            if (!empty($allSku)) {
                $existSku = GoodsSku::where(['sku_sn' => $allSku, 'status' => 1])->column('sku_sn');
                if (empty($existSku)) {
                    throw new PpylException(['msg' => '选中的订单产品暂无法发货']);
                }
                foreach ($orders as $key => $value) {
                    if ($value['shipping_status'] == 1 && !in_array($value['sku_sn'], $existSku)) {
                        throw new PpylException(['msg' => '订单' . $value['order_sn'] . '的产品暂无法发货,请选择寄售操作']);
                    }
                }
            }
        }


//        $userInfos = User::where(['uid' => array_unique(array_column($orderInfos, 'uid'))])->select()->toArray();
        $userInfos = User::where(['uid' => $data['uid']])->select()->toArray();
        foreach ($userInfos as $key => $value) {
            $userInfo[$value['uid']] = $value;
        }

        if ($type != 1) {
            $userRepurchaseNumber = UserRepurchase::where(['uid' => $data['uid'], 'area_code' => $areaCode, 'status' => 1])->value('repurchase_capacity');
            if (empty($userRepurchaseNumber)) {
                $userRepurchaseNumber = 0;
            }
            if ((string)$nowRepurchase > (string)$userRepurchaseNumber || ($userRepurchaseNumber <= 0)) {
                throw new PpylException(['msg' => '剩余可寄售的订单数量为' . $userRepurchaseNumber . '单']);
            }
        }

        $DBRes = Db::transaction(function () use ($userInfo, $orders, $orderInfo, $data,$nowShippingNumber,$areaInfo,$areaCode) {
            foreach ($orders as $key => $value) {
                //shipping_status 1为寄出  2为平台回购
                if ($value['shipping_status'] == 1) {
                    $shippingOrderInfo = $orderInfo[$value['order_sn']];
                    $shippingOrderGoods = current($shippingOrderInfo['orderGoods']);
                    //同步订单到主订单表
                    $syncOrder['order_sn'] = $shippingOrderInfo['order_sn'];
                    $syncOrder['order_belong'] = 1;
                    $syncOrder['order_type'] = 4;
                    $syncOrder['pay_no'] = $shippingOrderInfo['pay_no'] ?? null;
                    $syncOrder['uid'] = $shippingOrderInfo['uid'];
                    $syncOrder['user_phone'] = $userInfo[$shippingOrderInfo['uid']]['phone'] ?? null;
                    $syncOrder['item_count'] = 1;
                    $syncOrder['used_integral'] = 0;
                    $syncOrder['total_price'] = $shippingOrderGoods['total_price'];
                    $syncOrder['fare_price'] = $shippingOrderGoods['total_fare_price'];
                    $syncOrder['discount_price'] = 0;
                    $syncOrder['real_pay_price'] = $shippingOrderInfo['real_pay_price'];
                    $syncOrder['pay_type'] = $shippingOrderInfo['pay_type'];
                    $syncOrder['pay_status'] = $shippingOrderInfo['pay_status'];
                    $syncOrder['order_status'] = 2;
                    $syncOrder['vdc_allow'] = 2;
                    $syncOrder['pay_time'] = strtotime($shippingOrderInfo['pay_time']);
                    $syncOrder['link_superior_user'] = $userInfo[$shippingOrderInfo['uid']]['link_superior_user'] ?? null;
                    $syncOrder['address_id'] = $value['address_id'];
                    $syncOrder['shipping_address'] = $value['shipping_address'];
                    $syncOrder['shipping_name'] = $value['shipping_name'];
                    $syncOrder['shipping_phone'] = $value['shipping_phone'];
                    $syncOrder['order_remark'] = $value['order_remark'] ?? null;
                    $syncOrder['user_level'] = $userInfo[$shippingOrderInfo['uid']]['vip_level'] ?? null;
                    $syncOrder['allow_after_sale'] = 2;

                    //添加活动编码
                    $shippingOrderGoods['activity_sign'] = $shippingOrderInfo['activity_code'] ?? null;
                    $syncOrderGoods = $shippingOrderGoods;

                    if (!empty($syncOrder)) {
                        $orderRes = Order::create($syncOrder)->getData();
                        $ppylOrderRes = self::update(['shipping_status' => 1, 'shipping_time' => time()], ['order_sn' => $value['order_sn']]);
                    }

                    if (!empty($syncOrderGoods)) {
                        unset($syncOrderGoods['id']);
                        unset($syncOrderGoods['create_time']);
                        unset($syncOrderGoods['update_time']);
                        $orderGoodsRes = OrderGoods::create($syncOrderGoods)->getData();
                    }
                    //给用户新增寄售的次数
                    if (!empty($nowShippingNumber) && empty(($key + 1) % $areaInfo['shipping_unit'])) {
                        $repurchaseCapacity = $areaInfo['repurchase_increment'];
                        if (intval($repurchaseCapacity) > 0) {
                            $existRepurchase = UserRepurchase::where(['uid' => $data['uid'], 'area_code' => $areaCode, 'status' => 1])->findOrEmpty()->toArray();
                            if (!empty($existRepurchase)) {
                                UserRepurchase::where(['uid' => $data['uid'], 'area_code' => $areaCode, 'status' => 1])->inc('repurchase_capacity', $repurchaseCapacity)->update();
                            } else {
                                $newRepurchase['uid'] = $data['uid'];
                                $newRepurchase['activity_code'] = $areaInfo['activity_code'];
                                $newRepurchase['area_code'] = $areaInfo['area_code'];
                                $newRepurchase['repurchase_capacity'] = $repurchaseCapacity;
                                UserRepurchase::create($newRepurchase);
                            }

                        }

                    }
                } else {
                    $refundArray[] = $value;
                }
            }

            $codeBuilder = (new CodeBuilder());
            if (!empty($refundArray)) {
                foreach ($refundArray as $key => $value) {
                    //先修改为寄售, 然后后续队列处理退款
                    $ppylFefundRes = PpylOrder::update(['shipping_status' => 2, 'shipping_time' => time()], ['order_sn' => $value['order_sn'], 'uid' => $data['uid']]);

                    $refundSn = $codeBuilder->buildRefundSn();
                    $refund['out_trade_no'] = $value['order_sn'];
                    $refund['out_refund_no'] = $refundSn;
                    $refund['type'] = 3;
                    $queueRes[$value['order_sn']] = Queue::push('app\lib\job\TimerForPpyl', $refund, config('system.queueAbbr') . 'TimeOutPpyl');
                }
                //扣除剩余寄售次数
                UserRepurchase::where(['uid' => $data['uid'], 'area_code' => $areaCode, 'status' => 1])->dec('repurchase_capacity', count($refundArray))->update();
            }

            return ['orderRes' => $orderRes ?? [], 'orderGoodsRes' => $orderGoodsRes ?? [], 'refundQueueRes' => $queueRes ?? []];

        });

        return judge($DBRes);
    }

    /**
     * @title  用户选择中奖订单寄售或者寄出(老方法,门槛制,满几退几)
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function winOrderShippingOld(array $data)
    {
        $areaCode = $data['area_code'];
        $orders = $data['order'];
        if (empty($areaCode) || empty($orders)) {
            return false;
        }
        $areaInfo = PpylArea::where(['area_code' => $areaCode, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($areaInfo)) {
            throw new PpylException(['errorCode' => 2100101]);
        }
//        if (count($orders) < $areaInfo['repurchase_condition']) {
//            throw new PpylException(['msg' => '请至少选择' . $areaInfo['repurchase_condition'] . '条订单记录']);
//        }
        $orderSn = array_unique(array_column($orders, 'order_sn'));
        $orderInfos = PpylOrder::with(['orderGoods'])->where(['order_sn' => $orderSn, 'shipping_status' => 3, 'win_status' => 1, 'pay_status' => 2, 'area_code' => $areaCode])->select()->toArray();
        if (count($orderInfos) < count($orders)) {
            throw new PpylException(['msg' => '存在不可操作的记录!']);
        }
        foreach ($orderInfos as $key => $value) {
            $orderInfo[$value['order_sn']] = $value;
        }
//        $maxShippingNumber = $areaInfo['repurchase_condition'] - $areaInfo['repurchase_number'];
        $maxShippingNumber = $areaInfo['repurchase_number'];
        $nowShippingNumber = 0;
        $nowRepurchase = 0;
        foreach ($orders as $key => $value) {
            if ($value['shipping_status'] == 1) {
                $nowShippingNumber += 1;
            }
            if ($value['shipping_status'] == 2) {
                $nowRepurchase += 1;
            }
        }
        if (empty($nowShippingNumber)) {
            throw new PpylException(['msg' => '寄出的订单数量不允许为0']);
        }
        if ($nowRepurchase > $maxShippingNumber) {
            throw new PpylException(['msg' => '寄售的订单数量仅允许为' . $maxShippingNumber . '单']);
        }
//        $userInfos = User::where(['uid' => array_unique(array_column($orderInfos, 'uid'))])->select()->toArray();
        $userInfos = User::where(['uid' => $data['uid']])->select()->toArray();
        foreach ($userInfos as $key => $value) {
            $userInfo[$value['uid']] = $value;
        }
        $DBRes = Db::transaction(function () use ($userInfo, $orders, $orderInfo, $data) {

            foreach ($orders as $key => $value) {
                //shipping_status 1为寄出  2为平台回购
                if ($value['shipping_status'] == 1) {
                    $shippingOrderInfo = $orderInfo[$value['order_sn']];
                    $shippingOrderGoods = current($shippingOrderInfo['orderGoods']);
                    //同步订单到主订单表
                    $syncOrder['order_sn'] = $shippingOrderInfo['order_sn'];
                    $syncOrder['order_belong'] = 1;
                    $syncOrder['order_type'] = 4;
                    $syncOrder['pay_no'] = $shippingOrderInfo['pay_no'] ?? null;
                    $syncOrder['uid'] = $shippingOrderInfo['uid'];
                    $syncOrder['user_phone'] = $userInfo[$shippingOrderInfo['uid']]['phone'] ?? null;
                    $syncOrder['item_count'] = 1;
                    $syncOrder['used_integral'] = 0;
                    $syncOrder['total_price'] = $shippingOrderGoods['total_price'];
                    $syncOrder['fare_price'] = $shippingOrderGoods['total_fare_price'];
                    $syncOrder['discount_price'] = 0;
                    $syncOrder['real_pay_price'] = $shippingOrderInfo['real_pay_price'];
                    $syncOrder['pay_type'] = $shippingOrderInfo['pay_type'];
                    $syncOrder['pay_status'] = $shippingOrderInfo['pay_status'];
                    $syncOrder['order_status'] = 2;
                    $syncOrder['vdc_allow'] = 2;
                    $syncOrder['pay_time'] = strtotime($shippingOrderInfo['pay_time']);
                    $syncOrder['link_superior_user'] = $userInfo[$shippingOrderInfo['uid']]['link_superior_user'] ?? null;
                    $syncOrder['address_id'] = $value['address_id'];
                    $syncOrder['shipping_address'] = $value['shipping_address'];
                    $syncOrder['shipping_name'] = $value['shipping_name'];
                    $syncOrder['shipping_phone'] = $value['shipping_phone'];
                    $syncOrder['order_remark'] = $value['order_remark'] ?? null;
                    $syncOrder['user_level'] = $userInfo[$shippingOrderInfo['uid']]['vip_level'] ?? null;
                    $syncOrder['allow_after_sale'] = 2;

                    $syncOrderGoods = $shippingOrderGoods;

                    if (!empty($syncOrder)) {
                        $orderRes = Order::create($syncOrder)->getData();
                        $ppylOrderRes = self::update(['shipping_status' => 1, 'shipping_time' => time()], ['order_sn' => $value['order_sn']]);
                    }

                    if (!empty($syncOrderGoods)) {
                        unset($syncOrderGoods['id']);
                        unset($syncOrderGoods['create_time']);
                        unset($syncOrderGoods['update_time']);
                        $orderGoodsRes = OrderGoods::create($syncOrderGoods)->getData();
                    }

                } else {
                    $refundArray[] = $value;
                }
            }

            $codeBuilder = (new CodeBuilder());
            if (!empty($refundArray)) {
                foreach ($refundArray as $key => $value) {
                    //先修改为寄售, 然后后续队列处理退款
                    $ppylFefundRes = PpylOrder::update(['shipping_status' => 2, 'shipping_time' => time()], ['order_sn' => $value['order_sn'], 'uid' => $data['uid']]);

                    $refundSn = $codeBuilder->buildRefundSn();
                    $refund['out_trade_no'] = $value['order_sn'];
                    $refund['out_refund_no'] = $refundSn;
                    $refund['type'] = 3;
                    $queueRes[$value['order_sn']] = Queue::push('app\lib\job\TimerForPpyl', $refund, config('system.queueAbbr') . 'TimeOutPpyl');
                }
            }

            return ['orderRes' => $orderRes ?? [], 'orderGoodsRes' => $orderGoodsRes ?? [], 'refundQueueRes' => $queueRes ?? []];

        });

        return judge($DBRes);
    }

    /**
     * @title  订单提交退款
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function submitRefund(array $data)
    {
        $orderSn = $data['order_sn'];
        $orderInfo = self::where(['order_sn' => $orderSn, 'pay_status' => [2], 'refund_status' => [2], 'activity_status' => [3, -3]])->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            throw new PpylException(['msg' => '查无可退款订单']);
        }
        if ($orderInfo['can_operate_refund'] != 1) {
            throw new PpylException(['msg' => '不可操作的订单']);
        }
        $pay_no = $orderInfo['pay_no'];
        if (!empty(cache('orderCreate-' . $pay_no))) {
            throw new PpylException(['msg' => '流水号支付中,请稍后重新操作']);
        }

        if (!empty($orderInfo['auto_plan_sn'] ?? null) && !empty(cache($orderInfo['auto_plan_sn']))) {
            throw new PpylException(['msg' => '订单正在自动重开,此单暂无法操作']);
        }

        if ($orderInfo['pay_type'] == 4) {
            $payOrderNo = self::where(['pay_no' => $orderInfo['pay_no'], 'pay_type' => 2])->findOrEmpty()->toArray();
        }

        //查看利用该流水号的订单是否有中奖或退款的记录,有则不允许退款
        $usePayNoOrder = (new PpylOrder())->where(['pay_no' => $pay_no])->order('create_time asc')->select()->toArray();
        $usePayNoWaitOrder = PpylWaitOrder::where(['pay_no' => $pay_no])->order('create_time asc')->select()->toArray();

        if (!empty($usePayNoOrder)) {
            $allRes['usePayNoOrder'] = $usePayNoOrder;
            foreach ($usePayNoOrder as $key => $value) {
                if ($value['win_status'] == 1 || in_array($value['pay_status'], [3, -2])) {
                    $allRes['errorMsg'] = '使用过原支付流水号的订单' . $value['order_sn'] . '已中奖或已退款,无法继续退款';
                    throw new PpylException(['msg'=>$allRes['errorMsg']]);
                }
            }
        }

        if (!empty($usePayNoWaitOrder)) {
            $allRes['usePayNoWaitOrder'] = $usePayNoWaitOrder;
            foreach ($usePayNoWaitOrder as $key => $value) {
                if ($value['wait_status'] == -2 || in_array($value['pay_status'], [3, -2])) {
                    $allRes['errorMsg'] = '使用过原支付流水号的排队订单' . $value['order_sn'] . '已退款,无法继续退款';
                    throw new PpylException(['msg' => $allRes['errorMsg']]);
                }
            }
        }
        //上缓存锁
        cache('userSubmitRefund-' . $pay_no, $data, 15);

        //提交退款
        $refund['out_trade_no'] = $orderSn;
        $refund['out_refund_no'] = (new CodeBuilder())->buildRefundSn();
        $res = (new Ppyl())->submitPpylRefund($refund);

        //解除缓存锁
        cache('userSubmitRefund-' . $pay_no, null);
        return true;

    }


    /**
     * @title  拼团订单汇总
     * @param array $data
     * @return mixed
     */
    public function orderSummary(array $data)
    {
        if (!empty($data['activity_code'])) {
            $map[] = ['activity_code', '=', $data['activity_code']];
        }
        if (!empty($data['area_code'])) {
            if (is_array($data['area_code'])) {
                $map[] = ['area_code', 'in', $data['area_code']];
            } else {
                $map[] = ['area_code', '=', $data['area_code']];
            }
        }
        $map[] = ['activity_status', 'in', [1, 2]];
        $map[] = ['pay_status', 'in', [2]];
        $order = self::where($map)->count();

        //排队中的人数
        $redis = Cache::store('redis')->handler();
        $queueList = $redis->lRange('{queues:mhppMemberChain}', 0, -1);
        $randNumber = mt_rand(1800,2000);
        $all['joinAllNumber'] = $order + count($queueList ?? []) + ($randNumber ?? 0);

        return $all;
    }


    public function getStartTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getEndTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getCloseTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getPayTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getWinTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getGroupTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getLotteryTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getRewardBuildTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getDrawTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }


    public function activity()
    {
        return $this->hasOne('PpylActivity', 'activity_code', 'activity_code')->withoutField('id,create_time,update_time');
    }

    public function area()
    {
        return $this->hasOne('PpylArea', 'area_code', 'area_code')->withoutField('id,create_time,update_time');
    }

    public function goods()
    {
        return $this->hasOne('GoodsSku', 'sku_sn', 'sku_sn')->field('goods_sn,sku_sn,title,image,sub_title,specs,market_price,status,cost_price');
    }

    public function nowGoods()
    {
        return $this->hasMany('GoodsSku', 'sku_sn', 'sku_sn')->field('goods_sn,sku_sn,title,image,sub_title,specs,market_price,status');
    }

    public function joinNumber()
    {
        return $this->hasMany(get_class($this), 'activity_sn', 'activity_sn')->where(['activity_status' => [1, 2, 3], 'status' => [1]]);
    }

    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid')->field('uid,name,phone,avatarUrl');
    }

    public function userBind()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['userName' => 'name', 'avatarUrl','userPhone'=>'phone']);
    }

    public function orders()
    {
        return $this->hasOne('Order', 'order_sn', 'order_sn');
    }

    public function success()
    {
        return $this->hasMany(get_class($this), 'activity_code', 'activity_code')->where(['pay_status' => 2]);
    }

    public function joinSuccessNumber()
    {
        return $this->hasMany(get_class($this), 'activity_sn', 'activity_sn')->where(['activity_status' => [1, 2], 'status' => [1]]);
    }

    public function winGoods()
    {
        return $this->hasMany(get_class($this), 'area_code', 'area_code')->where(['activity_status' => [1, 2], 'win_status' => 1, 'status' => [1]]);
    }

    public function orderGoods()
    {
        return $this->hasMany('PpylOrderGoods', 'order_sn', 'order_sn');
    }

    public function repurchaseNumber()
    {
        return $this->hasOne('User','uid','uid')->bind(['repurchase_capacity']);
    }

    public function refundPayNo()
    {
        return $this->hasOne(get_class($this), 'pay_no', 'pay_no')->where(['pay_status' => -2])->field('id,order_sn,pay_no');
    }

    public function refundWaitPayNo()
    {
        return $this->hasOne('PpylWaitOrder', 'pay_no', 'pay_no')->where(['pay_status' => -2])->field('id,order_sn,pay_no');
    }

}