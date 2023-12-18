<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 售后模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ActivityException;
use app\lib\exceptions\AfterSaleException;
use app\lib\exceptions\FinanceException;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\exceptions\YsePayException;
use app\lib\services\CodeBuilder;
use app\lib\services\JoinPay;
use app\lib\services\Pay;
use app\lib\services\SandPay;
use app\lib\services\Shipping;
use app\lib\services\WxPayService;
use app\lib\services\Divide;
use app\lib\services\YsePay;
use think\facade\Db;
use think\facade\Queue;

class AfterSale extends BaseModel
{
    //protected $field = ['order_sn','uid','goods_sn','sku_sn','type','apply_reason','apply_status','apply_price','buyer_received_goods','verify_status','verify_reason','apply_time','verify_time','shipping_address','shipping_name','shipping_phone','shipping_code','after_status'];
    protected $validateFields = ['order_sn', 'uid', 'type'];

    /**
     * @title  售后列表
     * @param array $sear 搜索条件
     * @return array
     * @throws \Exception
     */
    public function list(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn|b.name|c.title', $sear['keyword']))];
        }

        if (!empty($sear['type'])) {
            $map[] = ['a.type', '=', $sear['type']];
        }

        if (!empty($sear['after_status'])) {
            $map[] = ['a.after_status', '=', $sear['after_status']];
        }
        if ($this->module == 'api') {
            $map[] = ['a.uid', '=', $sear['uid']];
        }

        if (in_array($this->module, ['admin', 'manager'])) {
            if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
                $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
                $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
            }
            if (!empty($sear['withdraw_start_time']) && !empty($sear['withdraw_end_time'])) {
                $map[] = ['a.withdraw_time', '>=', strtotime($sear['withdraw_start_time'])];
                $map[] = ['a.withdraw_time', '<=', strtotime($sear['withdraw_end_time'])];
            }
            if (!empty($sear['refund_start_time']) && !empty($sear['refund_end_time'])) {
                $map[] = ['a.user_arrive_price_time', '>=', strtotime($sear['refund_start_time'])];
                $map[] = ['a.user_arrive_price_time', '<=', strtotime($sear['refund_end_time'])];
            }
        }

        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        $field = $this->getListFieldByModule();

        if (!empty($page)) {
            $aTotal = Db::name('after_sale')->alias('a')
                ->join('sp_user b', 'a.uid = b.uid', 'left')
                ->join('sp_order_goods c', 'a.order_sn = c.order_sn and a.sku_sn = c.sku_sn', 'left')
                ->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = Db::name('after_sale')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_order_goods c', 'a.order_sn = c.order_sn and a.sku_sn = c.sku_sn', 'left')
            ->where($map)
            ->field($field)
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.create_time desc')->select()->each(function ($item, $key) {
                if (!empty($item)) {
                    if (!empty($item['create_time'])) {
                        $item['create_time'] = timeToDateFormat($item['create_time']);
                    }
                    if (!empty($item['apply_time'])) {
                        $item['apply_time'] = timeToDateFormat($item['apply_time']);
                    }
                    if (!empty($item['verify_time'])) {
                        $item['verify_time'] = timeToDateFormat($item['verify_time']);
                    }
                    if (!empty($item['user_arrive_price_time'])) {
                        $item['user_arrive_price_time'] = timeToDateFormat($item['user_arrive_price_time']);
                    }
                    $item['linkUserInfo'] = [];
                    $item['orderInfo'] = [];
                }

                $item['correction_supplier'] = "0.00";
                $item['correction_fare'] = "0.00";
                $item['correction_cost'] = "0.00";
                $orderCorrectionSql = OrderCorrection::where(['order_sn'=>$item['order_sn'],'sku_sn'=>$item['sku_sn'],'status'=>1,'type'=>2])->order('create_time desc,id desc')->limit(0,1000)->buildSql();
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
                $item['supplier_name'] = null;
                return $item;
            })->toArray();

        if (!empty($list)) {
            //补全上级用户信息
            $allLinkUid = array_unique(array_filter(array_column($list, 'link_superior_user')));

            if (!empty($allLinkUid)) {
                $linkUsers = User::where(['uid' => $allLinkUid])->field('uid,name,phone,avatarUrl,vip_level')->select()->toArray();
                foreach ($linkUsers as $key => $value) {
                    $linkUser[$value['uid']] = $value;
                }
                if (!empty($linkUser)) {
                    foreach ($list as $key => $value) {
                        if (!empty($value['link_superior_user'])) {
                            $list[$key]['linkUserInfo'] = $linkUser[$value['link_superior_user']] ?? [];
                        }
                    }
                }
            }

            //补全订单信息
            $allOrderSn = array_unique(array_filter(array_column($list, 'order_sn')));
            if (!empty($allOrderSn)) {
                $orders = Order::where(['order_sn' => $allOrderSn])->field('order_sn,uid,user_phone,real_pay_price,create_time,pay_time,delivery_time,shipping_status')->select()->toArray();
                foreach ($orders as $key => $value) {
                    $order[$value['order_sn']] = $value;
                }

                if (!empty($order)) {
                    foreach ($list as $key => $value) {
                        if (!empty($value['order_sn'])) {
                            $list[$key]['orderInfo'] = $order[$value['order_sn']] ?? [];
                        }
                    }
                }
            }

            //补全供应商信息
            $allSupplierCode = array_unique(array_filter(array_column($list, 'supplier_code')));
            if (!empty($allSupplierCode)) {
                $suppliers = Supplier::where(['supplier_code' => $allSupplierCode])->column('name','supplier_code');

                if (!empty($suppliers)) {
                    foreach ($list as $key => $value) {
                        if (!empty($value['supplier_code'])) {
                            $list[$key]['supplier_name'] = $suppliers[$value['supplier_code']] ?? [];
                        }
                    }
                }
            }


        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }

    /**
     * @title  售后详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function info(array $data)
    {
        $asSn = $data['after_sale_sn'];
        $info = $this->with(['user', 'detail', 'orderInfo'])->where(['after_sale_sn' => $asSn])->findOrEmpty()->toArray();
        if (!empty($info)) {
            $info['goods'] = OrderGoods::where(['order_sn' => $info['order_sn'], 'goods_sn' => $info['goods_sn'], 'sku_sn' => $info['sku_sn']])->withoutField('id')->findOrEmpty()->toArray();
            if (!empty($info['detail'])) {
                $detailId = array_column($info['detail'], 'id');
                $detailImages = AfterSaleImages::where(['after_sale_sn' => $asSn, 'after_sale_detail_id' => $detailId, 'status' => 1])->select()->toArray();
                foreach ($info['detail'] as $key => $value) {
                    $info['detail'][$key]['images'] = [];
                    foreach ($detailImages as $cKey => $cValue) {
                        if ($value['id'] == $cValue['after_sale_detail_id']) {
                            $info['detail'][$key]['images'][] = $cValue['image_path'];
                        }
                    }
                }
            }
        }
        return $info;
    }


    /**
     * @title  审核售后申请
     * @param array $data
     * @return mixed
     */
    public function verify(array $data)
    {
        $afterSaleSn = $data['after_sale_sn'];
        $save['verify_status'] = $data['verify_status'];
        $notThrowError = $data['notThrowError'] ?? false;
        if ($save['verify_status'] == 3) {
            $save['verify_reason'] = $data['verify_reason'];
            $save['close_time'] = time();
        }
        $save['after_status'] = $data['verify_status'];
        $save['verify_time'] = time();
        $afterSaleInfo = $this->with(['goods'])->where(['after_sale_sn' => $afterSaleSn, 'verify_status' => 1])->findOrEmpty()->toArray();
        if (empty($afterSaleInfo)) {
            if (empty($notThrowError)) {
                throw new AfterSaleException(['errorCode' => 2000101]);
            } else {
                return false;
            }
        }
        $needSellerAddress = false;
        if (in_array($afterSaleInfo['type'], [2, 3]) && $save['verify_status'] == 2) {
            $needSellerAddress = true;
            $save['seller_shipping_address'] = $data['seller_shipping_address'];
            $save['seller_shipping_name'] = $data['seller_shipping_name'];
            $save['seller_shipping_phone'] = $data['seller_shipping_phone'];
            $save['seller_remark'] = $data['seller_remark'] ?? '暂无其他说明';
        }
        $orderInfo = Order::with(['user', 'goods'])->where(['order_sn' => $afterSaleInfo['order_sn']])->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            if (empty($notThrowError)) {
                throw new OrderException(['errorCode' => 1500109]);
            } else {
                return false;
            }
        }

        $dbRes = Db::transaction(function () use ($afterSaleSn, $save, $afterSaleInfo, $needSellerAddress, $orderInfo) {
            //修改售后信息
            if ($needSellerAddress) {
                $save['after_status'] = 4;
            }
            if ($afterSaleInfo['type'] == 3 && $save['verify_status'] == 2) {
                $save['change_shipping_address'] = $orderInfo['shipping_address'];
                $save['change_shipping_name'] = $orderInfo['shipping_name'];
                $save['change_shipping_phone'] = $orderInfo['shipping_phone'];
            }
            $res = $this->validate(false)->baseUpdate(['after_sale_sn' => $afterSaleSn], $save);
            $afDetailModel = (new AfterSaleDetail());
            //添加售后流程明细
            $detail['after_sale_sn'] = $afterSaleSn;
            $detail['operate_user'] = '商家';
            $detail['after_type'] = $save['verify_status'];
            $afDetailModel->DBNew($detail);
            if ($needSellerAddress) {
                $detail['after_sale_sn'] = $afterSaleSn;
                $detail['operate_user'] = '商家';
                $detail['after_type'] = 4;
                $afDetailModel->DBNew($detail);
            }
            if ($save['verify_status'] == 3) {
                $detail['after_sale_sn'] = $afterSaleSn;
                $detail['operate_user'] = '系统';
                $detail['after_type'] = 10;
                $afDetailModel->DBNew($detail);
            }
            //修改正常订单状态
            if ($save['verify_status'] == 3) {
                $saveOrder['after_status'] = 5;
                $autoTime = false;
            } elseif ($save['verify_status'] == 2) {
                $saveOrder['after_status'] = 3;
                $saveOrder['order_status'] = 6;
                $autoTime = true;
            }
            $orderRes = $this->changeOrderAfterStatus($afterSaleInfo, $orderInfo, $saveOrder, $autoTime);

            //消息模板通知
            $template['uid'] = $afterSaleInfo['uid'];
            $template['type'] = 'afterSaleStatus';
            $verifyStatus = $save['verify_status'] == 2 ? '审核通过' : '审核不通过';
            $template['access_key'] = getAccessKey();
            $template['template'] = ['thing4' => $afterSaleInfo['goods']['title'], 'character_string3' => $afterSaleInfo['order_sn'], 'thing1' => $afterSaleInfo['apply_reason'], 'phrase2' => $verifyStatus, 'time5' => $afterSaleInfo['create_time']];
            $templateQueue = Queue::push('app\lib\job\Template', $template, config('system.queueAbbr') . 'TemplateList');
            return $res;
        });

        return judge($dbRes);
    }


    /**
     * @title  用户发起售后
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function initiateAfterSale(array $data)
    {
        $orderSn = $data['order_sn'];
        $goods = $data['goods'] ?? [];
        $images = $data['images'] ?? [];
        $sku = array_column($goods, 'sku_sn');
        //判断缓存锁
        if (!empty(cache('orderExchangeIng-' . $orderSn))) {
            throw new ServiceException(['msg' => '订单正在执行业务逻辑, 请稍晚些再操作退售后~']);
        }
        $afterSaleHis = AfterSale::where(['order_sn' => $orderSn, 'sku_sn' => $sku])->select()->toArray();
        //查看售后历史中的有多少个商品
        $afMap[] = ['order_sn', '=', $orderSn];
        $afMap[] = ['after_status', '<>', -1];
        $afterSaleHisGoodsNumber = AfterSale::where($afMap)->count();

        $allOrderGoods = OrderGoods::where(['order_sn' => $orderSn, 'sku_sn' => $sku, 'status' => 1])->field('order_sn,title,goods_sn,sku_sn,pay_status,after_status,apply_after_sale_number,allow_after,allow_after_type')->select()->toArray();
        if (!empty($allOrderGoods)) {
            foreach ($allOrderGoods as $key => $value) {
                $thisOrderGoods[$value['sku_sn']] = $value;
            }
        }

        if (!empty($afterSaleHis)) {
            foreach ($afterSaleHis as $key => $value) {
                $allowApply = false;
                switch ($value['verify_status']) {
                    case 1:
                        throw new AfterSaleException(['errorCode' => 2000103]);
                        break;
                    case 2:
                        throw new AfterSaleException(['errorCode' => 2000104]);
                        break;
                    case 3:
                        //如果被拒之后还有多余的退售后次数可以继续申请
                        if (!empty($thisOrderGoods) && !empty($thisOrderGoods[$value['sku_sn']]['apply_after_sale_number'] ?? 0) && (($thisOrderGoods[$value['sku_sn']]['apply_after_sale_number'] ?? 0) > 0)) {
                            $allowApply = true;
                        }
                        if (empty($allowApply)) {
                            throw new AfterSaleException(['errorCode' => 2000104]);
                        }
                        break;
                }
            }
        }

        $map[] = ['order_sn', '=', $orderSn];
        $map[] = ['order_status', 'in', [2, 3, 4]];
//        $map[] = ['after_status','in',[1,-1]];
        $orderInfo = Order::with(['user', 'goods', 'pt'])->where($map)->findOrEmpty()->toArray();
        if ($this->module != 'admin') {
            //美丽金支付的订单及其他兑换订单不允许仅退款和退货退款
            if (in_array($orderInfo['order_type'], [7, 8]) || ($orderInfo['order_type'] == 1 && $orderInfo['pay_type'] == 5)) {
                if (in_array($data['type'], [1, 2])) {
                    throw new OrderException(['errorCode' => 1500132]);
                }
            }
        } else {
            if (in_array($orderInfo['order_type'], [8])) {
                throw new OrderException(['errorCode' => 1500133]);
            }
        }

        //获取全部订单商品总数
        $orderGoods = OrderGoods::where(['order_sn' => $orderSn, 'pay_status' => 2])->count();

        if (empty($orderInfo)) {
            throw new OrderException(['errorCode' => 1500109]);
        }
        //众筹订单不允许仅退款和退货退款
        if ($orderInfo['order_type'] == 6 && in_array($data['type'], [1, 2])) {
            throw new AfterSaleException(['errorCode' => 2000113]);
        }
        //限制申请售后时间不能大于订单创建时间15秒内
        if (time() < (strtotime($orderInfo['create_time']) + 15)) {
            throw new AfterSaleException(['errorCode' => 2000111]);
        }

        if (empty($orderInfo['goods'])) {
            throw new OrderException(['errorCode' => 1500109]);
        }
        if (!empty($data['uid']) && ($orderInfo['uid'] != $data['uid'])) {
            throw new OrderException(['errorCode' => 1500112]);
        }
        if ($orderInfo['order_type'] == 2 && !empty($orderInfo['pt'])) {
            $ptInfo = $orderInfo['pt'];
            if ($ptInfo['activity_status'] == 1) {
                throw new OrderException(['errorCode' => 1500122]);
            }
        }
        //如果有被重新打开退售后的不判断售后条数一定为商品数量
        if (empty($allowApply ?? false)) {
            if ($afterSaleHisGoodsNumber > $orderGoods) {
                throw new OrderException(['errorCode' => 1500110]);
            }
        }


        switch ($data['type']) {
            case 1:
                if ($orderInfo['order_status'] == 1) {
                    throw new AfterSaleException(['msg' => '商品还未付款,无法以此类原因申请售后~']);
                }
                break;
            case 2:
            case 3:
                if ($orderInfo['order_status'] != 3) {
                    throw new AfterSaleException(['msg' => '商品暂未发货,无法以此类原因申请售后~']);
                }
                break;
        }

        $codeBuilderService = (new CodeBuilder());
        $newAfter = [];

        $needReturnFare = false;
        //如果申请的商品数量跟订单商品数量相同则退回邮费
        $orderGoodsNumber = $orderGoods;
        if ($orderGoodsNumber == count($goods) + ($afterSaleHisGoodsNumber ?? 0)) {
            $needReturnFare = true;
        }
        $lastGoods = count($orderInfo['goods']) - 1;

        foreach ($orderInfo['goods'] as $key => $value) {
            $goodsInfo[$value['sku_sn']] = $value;
            //允许后台直接操作售后
            if ($this->module != 'admin') {
                //判断商品是否支持售后和允许的售后类型
                if ($value['allow_after'] == 2) {
                    throw new ServiceException(['msg' => '商品' . $value['title'] . '不支持退售后操作哦']);
                }
                if (!in_array($data['type'], explode(',', $value['allow_after_type']))) {
                    throw new ServiceException(['msg' => '商品' . $value['title'] . '不支持当前售后申请类型']);
                }
            }
        }

        //查找该订单申请的商品是否存在拆数量发货订单,如果存在,其中有一个订单没有发货物流,即没有发货,不允许申请售后,需要全部拆数量的发货订单全部发货了才能申请退售后
        foreach ($goods as $key => $value) {
            $shipMap = [];
            $shipMap[] = ['status', '=', 1];
            $shipMap[] = ['order_status', 'in', [2]];
            $shipMap[] = ['split_status', '=', 1];
            $shipMap[] = ['split_number', '<>', 0];
            $shipMap[] = ['goods_sku', '=', $value['sku_sn']];
            $shipOrder = ShipOrder::where($shipMap)->where(function ($query) use ($orderSn) {
                $mapOr[] = ['parent_order_sn', 'in', $orderSn];
                $mapAnd[] = ['order_sn', 'in', $orderSn];
                $query->where($mapAnd)->whereOr([$mapOr]);
            })->field('order_sn,parent_order_sn,shipping_status,shipping_code,order_status,shipping_type')->order('order_sort desc,order_child_sort asc')->select()->toArray();

            //统计已发货的发货订单
            $shipNumber = 0;
            if (!empty($shipOrder)) {
                foreach ($shipOrder as $cKey => $cValue) {
                    if (!empty($cValue['shipping_code']) || (!empty($cValue['shipping_type']) && $cValue['shipping_type'] == 2)) {
                        $shipNumber += 1;
                    }
                }
                if ($shipNumber < count($shipOrder)) {
                    if (!empty($goodsInfo[$value['sku_sn']])) {
                        $goodsTitle = '商品 <' . $goodsInfo[$value['sku_sn']]['title'] . '> ';
                    } else {
                        $goodsTitle = '该商品';
                    }
                    throw new OrderException(['msg' => $goodsTitle . '已拆分成多个包裹备货,请您在全部包裹发出后再申请售后']);
                }
            }
        }

        foreach ($orderInfo['goods'] as $key => $value) {
            foreach ($goods as $cKey => $cValue) {
                if ($value['sku_sn'] == $cValue['sku_sn']) {
                    if (!is_numeric($cValue['apply_price'])) {
                        throw new AfterSaleException(['errorCode' => 2000109]);
                    }
                    if ((string)$cValue['apply_price'] > (string)$value['real_pay_price']) {
                        throw new AfterSaleException(['errorCode' => 2000106]);
                    }
                    if (((string)$cValue['apply_price'] != (string)$value['real_pay_price']) && (!empty($value['shipping_status']) && $value['shipping_status'] != 3)) {
                        throw new AfterSaleException(['errorCode' => 2000110]);
                    }
                    if (($value['total_price'] - ($value['all_dis'] ?? 0) > 0) && empty(doubleval($cValue['apply_price'])) && (in_array($data['type'], [1, 2]))) {
                        throw new OrderException(['msg' => '请填写商品' . $value['title'] . '的有效退售后金额']);
                    }
//                    if (empty(doubleval($value['real_pay_price']))) {
//                        throw new OrderException(['msg' => '商品' . $value['title'] . '实付金额为0,不可申请售后']);
//                    }
                    $newAfter[$cKey]['after_sale_sn'] = $codeBuilderService->buildAfterSaleSn();
                    $newAfter[$cKey]['order_sn'] = $orderSn;
                    $newAfter[$cKey]['goods_sn'] = $value['goods_sn'];
                    $newAfter[$cKey]['sku_sn'] = $value['sku_sn'];
                    $newAfter[$cKey]['order_real_price'] = $orderInfo['real_pay_price'];
                    $newAfter[$cKey]['uid'] = $orderInfo['uid'];
                    $newAfter[$cKey]['type'] = $data['type'];
                    $newAfter[$cKey]['apply_reason'] = $data['apply_reason'];
//                    if(!empty($needReturnFare)){
//                        if($orderGoodsNumber == 1){
//                            $newAfter[$cKey]['apply_price'] = $orderInfo['real_pay_price'];
//                        }else{
//                            $newAfter[$cKey]['apply_price'] = $value['real_pay_price'];
//                            if(empty($afterSaleHisGoodsNumber)){
//                                if($key == $lastGoods){
//                                    $newAfter[$cKey]['apply_price'] = $value['real_pay_price'] + $orderInfo['fare_price'];
//                                }
//                            }else{
//                                if(($cKey+1) == ($orderGoodsNumber - $afterSaleHisGoodsNumber)){
//                                    $newAfter[$cKey]['apply_price'] = $value['real_pay_price'] + $orderInfo['fare_price'];
//                                }
//                            }
//                        }
//                    }else{
//                        $newAfter[$cKey]['apply_price'] = $cValue['apply_price'];
//                    }
                    if ((string)$value['real_pay_price'] < (string)$cValue['apply_price']) {
                        throw new AfterSaleException(['errorCode' => 2000108]);
                    }
                    $newAfter[$cKey]['apply_price'] = $cValue['apply_price'];
                    //换货申请金额强制修改为0, 换货无需退款
                    if ((!empty(doubleval($cValue['apply_price']))) && $data['type'] == 3) {
                        $newAfter[$cKey]['apply_price'] = 0;
                    }
                    $newAfter[$cKey]['buyer_received_goods'] = $data['received_goods_status'];
                    $newAfter[$cKey]['apply_time'] = time();
                    $newAfter[$cKey]['apply_status'] = 1;
                    $newAfter[$cKey]['after_status'] = 1;
                }
            }
        }

        $dbRes = Db::transaction(function () use ($newAfter, $orderSn, $data, $orderInfo, $images) {
            $afDetailModel = new AfterSaleDetail();
            $orderGoodsModel = (new OrderGoods());
            //新增售后信息
            $res = $this->saveAll($newAfter);
            $newAf = $res->toArray();
            $allAfterGoodsNumber = count($newAfter);

            $number = 0;
            foreach ($newAfter as $key => $value) {
                //添加售后流程明细
                $detail['after_sale_sn'] = $value['after_sale_sn'];
                $detail['operate_user'] = $orderInfo['user']['name'];
                $detail['images'] = $images;
                $afDetailModel->DBNew($detail);
                $afterInfo = $value;

                //修改正常订单状态
                $orderMap['after_status'] = 2;
                $orderMap['order_status'] = 5;
                $orderMap['after_change_type'] = 1;
                $orderRes = $this->changeOrderAfterStatus($afterInfo, $orderInfo, $orderMap, true);

                //减少一次申请退售后的机会
                $orderGoodsModel->where(['order_sn' => $orderSn, 'sku_sn' => $value['sku_sn'], 'goods_sn' => $value['goods_sn'], 'status' => 1])->dec('apply_after_sale_number', 1)->update();

                //根据订单商品备货状态判断时候进入自动售后状态,目前只有待备货才能进入
                foreach ($orderInfo['goods'] as $gKey => $gValue) {
                    if ($gValue['goods_sn'] == $value['goods_sn'] && $gValue['sku_sn'] == $value['sku_sn'] && $gValue['shipping_status'] == 1 && in_array($orderInfo['order_status'], [2, 3, 5, -1, 6])) {
                        $queueData['order_sn'] = $orderInfo['order_sn'];
                        $queueData['uid'] = $orderInfo['uid'];
                        $queueData['after_type'] = $value['type'];
                        $queueData['goods_sn'] = $value['goods_sn'];
                        $queueData['sku_sn'] = $value['sku_sn'];
                        $queueData['after_sale_sn'] = $value['after_sale_sn'];
                        $queueData['autoType'] = 1;
//                            $queue = Queue::push('app\lib\job\Auto', $queueData, config('system.queueAbbr') . 'Auto');
                        if ($allAfterGoodsNumber == 1) {
                            $queue = Queue::push('app\lib\job\Auto', $queueData, config('system.queueAbbr') . 'Auto');
                        } else {
                            if ($number < 1) {
                                $queue = Queue::push('app\lib\job\Auto', $queueData, config('system.queueAbbr') . 'Auto');
                            } else {
                                $queue = Queue::later((intval($number * 5)), 'app\lib\job\Auto', $queueData, config('system.queueAbbr') . 'Auto');
                            }
                        }
                        $number++;
                    }
                }
            }

            return $res;
        });


        return judge($dbRes);
    }

    /**
     * @title  商家确认退款
     * @param array $data
     * @return mixed
     */
    public function sellerConfirmWithdrawPrice(array $data)
    {
        $afSn = $data['after_sale_sn'];
        $map[] = ['after_status', 'in', [2, 6]];
        $map[] = ['after_sale_sn', '=', $afSn];
        $map[] = ['status', '=', 1];
        $afInfo = $this->with(['user'])->where($map)->findOrEmpty()->toArray();

        if (empty($afInfo)) {
            throw new AfterSaleException(['errorCode' => 2000102]);
        }
        //此时的售后状态
        $afStatus = $afInfo['after_status'] ?? 2;
        $aOrder = Order::with(['goods'])->where(['order_sn' => $afInfo['order_sn'], 'pay_status' => 2])->field('order_belong,order_sn,uid,pay_no,real_pay_price,pay_type,split_status,sync_status,create_time,fare_price,pay_channel')->findOrEmpty()->toArray();
        $orderGoodsPrice = OrderGoods::where(['order_sn' => $afInfo['order_sn'], 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn']])->value('real_pay_price');

        $afterSaleHisGoodsNumber = AfterSale::where(['order_sn' => $afInfo['order_sn']])->count();
        //获取全部订单商品总数
        $orderGoods = OrderGoods::where(['order_sn' => $afInfo['order_sn'], 'pay_status' => 2])->count();
        if ($orderGoods == 1 || ($orderGoods != 1 && $orderGoods == 1 + ($afterSaleHisGoodsNumber ?? 0))) {
            $needReturnFare = true;
        }

        //查询该订单的已完成的全部退款售后订单金额
        $oMap[] = ['order_sn', '=', $afInfo['order_sn']];
        $oMap[] = ['status', '=', 1];
        $oMap[] = ['type', 'in', [1, 2]];
        $oMap[] = ['', 'exp', Db::raw('user_arrive_price_time is not null')];
        $oMap[] = ['after_status', '=', 10];
        $orderAllAfHis = AfterSale::where($oMap)->sum('apply_price');

        if (empty($aOrder)) {
            throw new OrderException(['errorCode' => 1500109]);
        }
        //最后一个售后的商品加上运费一起退
//        if(!empty($needReturnFare)){
//            $orderGoodsPrice += $aOrder['fare_price'] ?? 0;
//        }

        if ((string)$orderGoodsPrice < (string)$afInfo['apply_price']) {
            throw new AfterSaleException(['errorCode' => 2000105]);
        }
        //如果是没有付款的产品则直接进入退款流程,不需要走第三方服务退款
        if (empty(doubleval($afInfo['apply_price']))) {
//            throw new AfterSaleException(['errorCode'=>2000107]);
            //统一一个方法进行退款处理
            if (!empty($afInfo['refund_sn'])) {
                $refundSn = $afInfo['refund_sn'];
            } else {
                $refundSn = (new CodeBuilder())->buildRefundSn();
                $afterSaleRes = AfterSale::update(['after_status' => 7, 'withdraw_time' => time(), 'refund_sn' => $refundSn], ['after_sale_sn' => $afInfo['after_sale_sn']]);
            }
            $refundRes = (new Pay())->completeRefund(['out_trade_no' => $aOrder['order_sn'], 'out_refund_no' => $refundSn]);
            return true;
        }

        switch ($aOrder['pay_type']) {
            case 1:
            case 5:
            case 7:
//                $userBalance['uid'] = $aOrder['uid'];
//                $userBalance['order_sn'] = $aOrder['order_sn'];
//                $userBalance['belong'] = 1;
//                $userBalance['type'] = 1;
//                $userBalance['price'] = $afInfo['apply_price'];
//                $userBalance['change_type'] = 5;
//                $userBalance['remark'] = '售后服务退款';
//                $refundDetail['refund_sn'] = (new CodeBuilder())->buildRefundSn();
//                $refundDetail['uid'] = $aOrder['uid'];
//                $refundDetail['order_sn'] = $aOrder['order_sn'];
//                $refundDetail['after_sale_sn'] = $afInfo['after_sale_sn'];
//                $refundDetail['refund_price'] = $afInfo['apply_price'];
//                $refundDetail['all_pay_price'] = $aOrder['real_pay_price'];
//                $refundDetail['refund_desc'] = $userBalance['remark'];
//                $refundDetail['refund_account'] = 2;
//                $refundDetail['pay_status'] = 1;
//                $refundRes = Db::transaction(function() use ($userBalance,$refundDetail,$afInfo,$aOrder,$orderAllAfHis){
//                    //退款金额不为0是修改账户明细
//                    if(!empty(doubleval($afInfo['apply_price']))){
//                        $afDetailModel = new AfterSaleDetail();
//                        //添加退款明细
//                        $refundRes = RefundDetail::create($refundDetail);
//                        //添加用户账户明细
//                        $balanceRes = BalanceDetail::create($userBalance);
//                        //修改用户余额
//                        $userRes = (new User())->where(['uid'=>$userBalance['uid'],'status'=>1])->inc('total_balance',$userBalance['price'])->update();
//                    }
//
//                    //修改售后订单状态
//                    $afterSaleRes = AfterSale::update(['real_withdraw_price'=>$userBalance['price'],'after_status'=>10,'close_time'=>time(),'user_arrive_price_time'=>time(),'withdraw_time'=>time(),'refund_sn'=>$refundDetail['refund_sn']],['after_sale_sn'=>$afInfo['after_sale_sn']]);
//                    //添加售后流程明细
//                    //添加退款流程
//                    $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
//                    $detail['operate_user'] = '商家';
//                    $afDetailModel->DBNew($detail);
//                    //添加售后完结流程
//                    $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
//                    $detail['operate_user'] = '系统';
//                    $detail['after_type'] = 10;
//                    $afDetailModel->DBNew($detail);
//
//                    //修改订单状态
//                    if((string)($afInfo['apply_price'] + $orderAllAfHis) >= (string)$aOrder['real_pay_price']){
//                        $saveOrder['order_status'] = -3;
//                    }
//                    $saveOrder['after_status'] = 4;
//                    $orderRes = $this->changeOrderAfterStatus($afInfo,$aOrder,$saveOrder,true);
//
//                    if(!empty(doubleval($afInfo['apply_price']))){
//                        //取消分润
//                        $divideRes = (new Divide())->deductMoneyForDivideByOrderSn($aOrder['order_sn'],$afInfo['sku_sn'],$aOrder);
//                    }
//                    return $userRes;
//                });
                //统一一个方法进行退款处理
                if (!empty($afInfo['refund_sn'])) {
                    $refundSn = $afInfo['refund_sn'];
                } else {
                    $refundSn = (new CodeBuilder())->buildRefundSn();
                    $afterSaleRes = AfterSale::update(['after_status' => 7, 'withdraw_time' => time(), 'refund_sn' => $refundSn], ['after_sale_sn' => $afInfo['after_sale_sn']]);
                }
                $refundRes = (new Pay())->completeRefund(['out_trade_no' => $aOrder['order_sn'], 'out_refund_no' => $refundSn]);
                $refundRes = true;
                break;
            case 2:
                $afDetailModel = new AfterSaleDetail();
                $refundSn = (new CodeBuilder())->buildRefundSn();
//                //修改售后订单状态
//                $afterSaleRes = AfterSale::update(['after_status' => 7, 'withdraw_time' => time(), 'refund_sn' => $refundSn], ['after_sale_sn' => $afInfo['after_sale_sn']]);
                //添加售后流程明细
                //添加退款流程
//                $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
//                $detail['operate_user'] = '商家';
//                $afDetailModel->DBNew($detail);

                $refund['out_trade_no'] = $afInfo['order_sn'];
                $refund['out_refund_no'] = $refundSn;
                $refund['total_fee'] = $aOrder['real_pay_price'];
                $refund['refund_fee'] = $afInfo['apply_price'];
//                $refund['total_fee'] = 0.01;
//                $refund['refund_fee'] = 0.01;
                $refund['refund_desc'] = '售后服务退款';
                $refund['notThrowError'] = $data['notThrowError'] ?? false;
                $refund['app_id'] = OrderPayArguments::where(['order_sn' => $afInfo['order_sn']])->value('app_id');
                $refundTime = time();
                //订单创建时间在2022-06-11 14:30:00之前的订单用微信商户号退款,因为支付也是微信商户号,之后的时间用汇聚支付,2022-06-11 14:07:44修改
                if (strtotime($aOrder['create_time']) <= 1654929000) {
                    $refund['notify_url'] = config('system.callback.refundCallBackUrl');
                    $refundRes = (new WxPayService())->refund($refund);
                } else {
                    switch ($aOrder['pay_channel'] ?? 2) {
                        case 1:
                            $refund['notify_url'] = config('system.callback.refundCallBackUrl');
                            $refundRes = (new WxPayService())->refund($refund);
                            break;
                        case 2:
                            //汇聚退款
                            $refund['notify_url'] = config('system.callback.joinPayRefundCallBackUrl');
                            $refundRes = (new JoinPay())->refund($refund);
                            break;
                        case 3:
                            //杉德退款
                            $refund['notify_url'] = config('system.callback.sandPayRefundCallBackUrl');
                            $refundRes = (new SandPay())->refund($refund);
                            break;
                        case 4:
                            //银盛退款
                            $refund['notify_url'] = config('system.callback.ysePayRefundCallBackUrl');
                            //银盛支付退款需要原支付流水号
                            $payNo = Order::where(['order_sn' => $afInfo['order_sn']])->value('pay_no');
                            if (empty($payNo)) {
                                throw new YsePayException(['msg' => '无支付流水号的订单暂无法完成银盛协议支付退款']);
                            }
                            $refund['pay_no'] = trim($payNo);
                            $refundRes = (new YsePay())->refund($refund);
                            break;
                        default:
                            throw new FinanceException(['msg' => '未知支付商通道']);
                    }

                }

//                //如果退款失败,将该退售后订单的状态打回跟之前一样
//                if(empty($refundRes)){
//                    AfterSale::update(['after_status' => $afStatus, 'withdraw_time' => null, 'refund_sn' => null], ['after_sale_sn' => $afInfo['after_sale_sn']]);
//                }

                //只有第三方服务申请退款成功了才修改售后订单状态为退款中
                if (!empty($refundRes)) {
                    //修改售后订单状态
                    $afterSaleRes = AfterSale::update(['after_status' => 7, 'withdraw_time' => $refundTime ?? time(), 'refund_sn' => $refundSn], ['after_sale_sn' => $afInfo['after_sale_sn']]);
                }

                break;
            case 3:
                throw new AfterSaleException(['msg' => '暂不支持支付宝退款']);
                break;
            case 6:
                //协议支付退款
                $refundSn = (new CodeBuilder())->buildRefundSn();
                $refund['out_trade_no'] = $afInfo['order_sn'];
                $refund['out_refund_no'] = $refundSn;
                $refund['total_fee'] = $aOrder['real_pay_price'];
                $refund['refund_fee'] = $afInfo['apply_price'];
//                $refund['total_fee'] = 0.01;
//                $refund['refund_fee'] = 0.01;
                $refund['refund_desc'] = '售后服务退款';
                $refund['order_create_time'] = strtotime($aOrder['create_time']);
                $refund['notThrowError'] = $data['notThrowError'] ?? false;
                $refundTime = time();
                switch ($aOrder['pay_channel'] ?? 2) {
                    case 1:
                        throw new FinanceException(['msg'=>'暂不支持的支付通道']);
                        break;
                    case 2:
                        $refund['notify_url'] = config('system.callback.joinPayAgreementRefundCallBackUrl');
                        $refundRes = (new JoinPay())->agreementRefund($refund);
                        break;
                    case 3:
                        $refund['uid'] = $afInfo['uid'];
                        $refund['notify_url'] = config('system.callback.sandPayAgreementRefundCallBackUrl');
                        $refundRes = (new SandPay())->agreementRefund($refund);
                        break;
                    case 4:
                        $refund['uid'] = $afInfo['uid'];
                        $refund['notify_url'] = config('system.callback.ysePayAgreementRefundCallBackUrl');
                        //银盛支付退款需要原支付流水号
                        $payNo = Order::where(['order_sn' => $afInfo['order_sn']])->value('pay_no');
                        if (empty($payNo)) {
                            throw new YsePayException(['msg' => '无支付流水号的订单暂无法完成银盛协议支付退款']);
                        }
                        $refund['pay_no'] = trim($payNo);
                        $refundRes = (new YsePay())->agreementRefund($refund);
                        break;
                    default:
                        throw new FinanceException(['msg' => '未知支付商通道']);
                }

                //只有第三方服务申请退款成功了才修改售后订单状态为退款中
                if (!empty($refundRes)) {
                    //修改售后订单状态
                    $afterSaleRes = AfterSale::update(['after_status' => 7, 'withdraw_time' => $refundTime ?? time(), 'refund_sn' => $refundSn], ['after_sale_sn' => $afInfo['after_sale_sn']]);
                }
                break;
            default:
                throw new AfterSaleException(['msg' => '系统暂不支持的退款方式']);
        }
        return $refundRes;

    }


    /**
     * @title  商家填写换货物流
     * @param array $data
     * @return mixed
     */
    public function sellerFillInShip(array $data)
    {
        $afSn = $data['after_sale_sn'];
        $afInfo = $this->with(['user'])->where(['after_sale_sn' => $afSn, 'type' => 3, 'after_status' => 6, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($afInfo)) {
            throw new AfterSaleException(['errorCode' => 2000102]);
        }
        $auShip['change_shipping_code'] = $data['shipping_code'];
        $auShip['change_shipping_company'] = $data['shipping_company'];
        $auShip['seller_ship_time'] = time();
        $auShip['after_status'] = 8;
        $dbRes = Db::transaction(function () use ($auShip, $afInfo, $data) {
            $afDetailModel = new AfterSaleDetail();
            //新增售后信息
            $res = $this->baseUpdate(['after_sale_sn' => $afInfo['after_sale_sn'], 'type' => 3, 'after_status' => 6, 'status' => 1], $auShip);
            //添加售后流程明细
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '商家';
            $afDetailModel->DBNew($detail);
            //订阅物流
            $ship['company_name'] = $data['shipping_company'];
            $ship['company'] = $data['shipping_company_code'];
            $ship['shipping_code'] = $data['shipping_code'];
            $subRes = (new Shipping())->subscribe($ship);
            return $res;
        });
        return judge($dbRes);
    }

    /**
     * @title  用户填写退/换货物流
     * @param array $data
     * @return bool
     */
    public function userFillInShip(array $data)
    {
        $afSn = $data['after_sale_sn'];
        $afInfo = $this->with(['user'])->where(['after_sale_sn' => $afSn, 'after_status' => 4, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($afInfo)) {
            throw new AfterSaleException(['errorCode' => 2000102]);
        }
        $auShip['buyer_shipping_code'] = $data['shipping_code'];
        $auShip['buyer_shipping_company'] = $data['shipping_company'];
        $auShip['buyer_ship_time'] = time();
        $auShip['after_status'] = 5;
        $dbRes = Db::transaction(function () use ($auShip, $afInfo, $data) {
            $afDetailModel = new AfterSaleDetail();
            //新增售后信息
            $res = $this->baseUpdate(['after_sale_sn' => $afInfo['after_sale_sn'], 'after_status' => 4], $auShip);
            //添加售后流程明细
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = $afInfo['user']['name'];
            $afDetailModel->DBNew($detail);

            if (!empty($data['shipping_company_code'] ?? null)) {
                //订阅物流
                $ship['company_name'] = $data['shipping_company'];
                $ship['company'] = $data['shipping_company_code'];
                $ship['shipping_code'] = $data['shipping_code'];
                $subRes = (new Shipping())->subscribe($ship);
            }

            return $res;
        });
        return judge($dbRes);
    }

    /**
     * @title  取消售后
     * @param array $data
     * @return bool
     */
    public function userCancelAfterSale(array $data)
    {
        $afSn = $data['after_sale_sn'];
        $afInfo = $this->with(['user'])->where(['after_sale_sn' => $afSn, 'after_status' => [1, 4], 'status' => 1])->findOrEmpty()->toArray();
        if (empty($afInfo)) {
            throw new AfterSaleException(['msg' => '仅允许待审核或待您退货的订单取消售后哦~']);
        }
        $aOrder = Order::with(['user', 'goods'])->where(['order_sn' => $afInfo['order_sn']])->findOrEmpty()->toArray();
        $auShip['after_status'] = -1;
        $auShip['verify_status'] = -1;
        $auShip['apply_status'] = -1;
        $dbRes = Db::transaction(function () use ($auShip, $afInfo, $data, $aOrder) {
            $afDetailModel = new AfterSaleDetail();
            //新增售后信息
            $res = $this->baseUpdate(['after_sale_sn' => $afInfo['after_sale_sn'], 'after_status' => [1, 4]], $auShip);
            //添加售后流程明细
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = $afInfo['user']['name'];
            $afDetailModel->DBNew($detail);
            //完结售后流程
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '系统';
            $detail['after_type'] = 10;
            $afDetailModel->DBNew($detail);

            //修改订单状态
            $saveOrder['after_status'] = -1;
            $orderRes = $this->changeOrderAfterStatus($afInfo, $aOrder, $saveOrder, true);

            //增加一次申请退售后的机会
            (new OrderGoods())->where(['order_sn' => $afInfo['order_sn'], 'sku_sn' => $afInfo['sku_sn'], 'goods_sn' => $afInfo['goods_sn'], 'status' => 1])->inc('apply_after_sale_number', 1)->update();
            return $res;
        });
        return judge($dbRes);
    }

    /**
     * @title  系统或后台管理员取消售后
     * @param array $data
     * @return bool
     */
    public function systemCancelAfterSale(array $data)
    {
        $adminInfo = $data['adminInfo'] ?? [];
        $afSn = $data['after_sale_sn'];
        $allowAfterStatus = [1, 2, 4, 5, 6, 8];
        $afInfo = $this->with(['user'])->where(['after_sale_sn' => $afSn, 'apply_status' => 1])->findOrEmpty()->toArray();
        if (empty($afInfo)) {
            throw new AfterSaleException(['msg' => '查无有效退售后信息']);
        }
        if (!in_array($afInfo['after_status'], $allowAfterStatus)) {
            throw new AfterSaleException(['msg' => '仅允许 <待审核、已同意退售后但未退款、待用户退货、待商家确认收货、商家已确认收货、等待用户确认换货> 状态的订单取消售后哦~']);
        }
        $aOrder = Order::with(['user', 'goods'])->where(['order_sn' => $afInfo['order_sn']])->findOrEmpty()->toArray();
        $auShip['after_status'] = -1;
        $auShip['verify_status'] = -1;
        $auShip['apply_status'] = -1;
        $dbRes = Db::transaction(function () use ($auShip, $afInfo, $data, $aOrder, $adminInfo, $allowAfterStatus) {
            $afDetailModel = new AfterSaleDetail();
            //新增售后信息
            $res = $this->baseUpdate(['after_sale_sn' => $afInfo['after_sale_sn'], 'after_status' => $allowAfterStatus], $auShip);
            //添加售后流程明细
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['detailUserName'] = '系统管理员';
            $detail['operate_user'] = $adminInfo['name'] ?? '系统管理员';
            $afDetailModel->DBNew($detail);
            //完结售后流程
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '系统';
            $detail['after_type'] = 10;
            $afDetailModel->DBNew($detail);

            //修改订单状态
            $saveOrder['after_status'] = -1;
            $orderRes = $this->changeOrderAfterStatus($afInfo, $aOrder, $saveOrder, true);

            //增加一次申请退售后的机会
            (new OrderGoods())->where(['order_sn' => $afInfo['order_sn'], 'sku_sn' => $afInfo['sku_sn'], 'goods_sn' => $afInfo['goods_sn'], 'status' => 1])->inc('apply_after_sale_number', 1)->update();

            return $res;
        });
        return judge($dbRes);
    }

    /**
     * @title  商家确认收货
     * @param array $data
     * @return bool
     */
    public function SellerConfirmReceiveGoods(array $data)
    {
        $afSn = $data['after_sale_sn'];
        $afInfo = $this->with(['user'])->where(['after_sale_sn' => $afSn, 'after_status' => 5, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($afInfo)) {
            throw new AfterSaleException(['errorCode' => 2000102]);
        }
        $auShip['after_status'] = 6;
        $auShip['seller_received_time'] = time();
        $dbRes = Db::transaction(function () use ($auShip, $afInfo, $data) {
            $afDetailModel = new AfterSaleDetail();
            //新增售后信息
            $res = $this->baseUpdate(['after_sale_sn' => $afInfo['after_sale_sn'], 'after_status' => 5], $auShip);
            //添加售后流程明细
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '商家';
            $afDetailModel->DBNew($detail);
            return $res;
        });
        return judge($dbRes);
    }

    /**
     * @title  商家拒绝确认收货
     * @param array $data
     * @return bool
     */
    public function SellerRefuseReceiveGoods(array $data)
    {
        $afSn = $data['after_sale_sn'];
        $images = $data['images'] ?? [];
        $afInfo = $this->with(['user'])->where(['after_sale_sn' => $afSn, 'after_status' => 5, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($afInfo)) {
            throw new AfterSaleException(['errorCode' => 2000102]);
        }
        $aOrder = Order::with(['user', 'goods'])->where(['order_sn' => $afInfo['order_sn']])->findOrEmpty()->toArray();
        $auShip['after_status'] = -2;
        $auShip['refuse_reason'] = $data['refuse_reason'];
        $auShip['seller_received_time'] = time();
        $auShip['seller_refuse_time'] = time();
        $dbRes = Db::transaction(function () use ($auShip, $afInfo, $data, $aOrder, $images) {
            $afDetailModel = new AfterSaleDetail();
            //新增售后信息
            $res = $this->baseUpdate(['after_sale_sn' => $afInfo['after_sale_sn'], 'after_status' => 5], $auShip);
            //添加售后流程明细
            //添加拒绝收货
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '商家';
            $detail['images'] = $images ?? [];
            $afDetailModel->DBNew($detail);
            unset($detail['images']);

            //添加拒绝退款
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '商家';
            $detail['after_type'] = -3;
            $afDetailModel->DBNew($detail);

            //添加完结
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '系统';
            $detail['after_type'] = 10;
            $afDetailModel->DBNew($detail);

            //修改订单售后状态
            $orderUpdate['after_status'] = 6;
            $orderRes = $this->changeOrderAfterStatus($afInfo, $aOrder, $orderUpdate, true);
            return $res;
        });
        return judge($dbRes);
    }

    /**
     * @title  用户确认收到换货
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function userConfirmReceiveChangeGoods(array $data)
    {
        $notChangeOrderStatus = false;
        $notChangeParentOrderStatus = false;
        $afSn = $data['after_sale_sn'];
        $afInfo = $this->with(['user'])->where(['after_sale_sn' => $afSn, 'after_status' => 8, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($afInfo)) {
            throw new AfterSaleException(['errorCode' => 2000102]);
        }
        $auShip['after_status'] = 10;
        $orderGoods = OrderGoods::where(['order_sn' => $afInfo['order_sn']])->select()->toArray();
        $orderInfo = Order::where(['order_sn' => $afInfo['order_sn']])->findOrEmpty()->toArray();
        //如果订单中有其他的商品正处于退售后中则不修改订单状态
        foreach ($orderGoods as $key => $value) {
            if (in_array($value['after_status'], [2, 3]) && $value['sku_sn'] != $afInfo['sku_sn']) {
                $notChangeOrderStatus = true;
            }
        }
        $shipOrderModel = (new ShipOrder());
        $shipOrderInfo = $shipOrderModel->with(['childOrder'])->where(['order_sn' => $orderInfo['order_sn']])->field('order_sn,parent_order_sn')->findOrEmpty()->toArray();

        if (empty($notChangeOrderStatus)) {
            //如果是合并的订单,合并订单里面(除了本订单)所有的商品如果存在一个售后申请或售后中的商品,则不修改订单状态
            if ($orderInfo['split_status'] == 2) {
                if (!empty($shipOrderInfo['parent_order_sn'])) {
                    $parentShipOrder = $shipOrderModel->list(['searOrderSn' => $orderInfo['parent_order_sn']]);
                    if (!empty($parentShipOrder['list'])) {
                        foreach ($parentShipOrder['list'] as $key => $value) {
                            if ($value['order_sn'] != $orderInfo['order_sn']) {
                                if (!empty($value['goods'])) {
                                    foreach ($value['goods'] as $gKey => $gValue) {
                                        if (in_array($gValue['after_status'], [2, 3])) {
                                            $notChangeParentOrderStatus = true;
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    foreach ($shipOrderInfo['childOrder'] as $key => $value) {
                        if ($value['order_sn'] != $orderInfo['order_sn']) {
                            if (in_array($value['after_status'], [2, 3])) {
                                $notChangeParentOrderStatus = true;
                            }
                        }
                    }
                }
            }
        }

        $dbRes = Db::transaction(function () use ($auShip, $afInfo, $data, $orderInfo, $shipOrderModel, $notChangeOrderStatus, $notChangeParentOrderStatus, $shipOrderInfo) {
            $afDetailModel = new AfterSaleDetail();

            //新增售后信息
            $auShip['close_time'] = time();
            $res = $this->baseUpdate(['after_sale_sn' => $afInfo['after_sale_sn'], 'after_status' => 8], $auShip);
            //添加售后流程明细
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = $afInfo['user']['name'];
            $detail['after_type'] = 9;
            $afDetailModel->DBNew($detail);

            //添加售后完结流程
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '系统';
            $detail['after_type'] = 10;
            $afDetailModel->DBNew($detail);

            if (empty($notChangeOrderStatus)) {
                //修改订单状态
                Order::update(['order_status' => 3, 'after_status' => 4], ['order_sn' => $afInfo['order_sn']]);

                //修改订单商品状态
                OrderGoods::update(['after_status' => 4], ['sku_sn' => $afInfo['sku_sn'], 'order_sn' => $afInfo['order_sn'], 'status' => [1]]);

                //修改发货订单状态
                $shipOrderModel::update(['order_status' => 3, 'after_status' => 4], ['order_sn' => $orderInfo['order_sn'], 'status' => 1]);

                //发货子订单或合并父订单状态
                if (in_array($orderInfo['split_status'], [1, 3])) {
                    $shipOrderList = $shipOrderModel->list(['searOrderSn' => $orderInfo['order_sn'], 'searGoodsSkuSn' => [$afInfo['sku_sn']], 'searGoodsSpuSn' => [$afInfo['goods_sn']] ?? []]);
                    if (!empty($shipOrderList['list'])) {
                        foreach ($shipOrderList['list'] as $key => $value) {
                            if (in_array($afInfo['sku_sn'], explode(',', $value['goods_sku']))) {
                                $shipOrderModel::update(['order_status' => 3, 'after_status' => 4], ['order_sn' => $value['order_sn'], 'status' => 1]);
                            }
                        }
                    }
                } else {
                    if (!empty($shipOrderInfo['parent_order_sn']) && empty($notChangeParentOrderStatus)) {
                        $shipOrderModel::update(['order_status' => 3, 'after_status' => 4], ['order_sn' => $shipOrderInfo['parent_order_sn'], 'status' => 1]);
                    }
                }
            }

            return $res;
        });
        return judge($dbRes);
    }

    /**
     * @title  售后留言
     * @param array $data
     * @return mixed
     */
    public function afterSaleMessage(array $data)
    {
        $afSn = $data['after_sale_sn'];
        $images = $data['images'] ?? [];
        $afInfo = AfterSale::where(['after_sale_sn' => $afSn, 'status' => 1])->findOrEmpty()->toArray();
        $goodsTitle = GoodsSku::where(['goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn']])->value('title');

        $message = $data['message'] ?? '';
        if (empty(trim($message))) {
            throw new AfterSaleException(['msg' => '请填写有效留言~']);
        }
        $userType = $data['userType'] ?? 1;
        switch ($userType) {
            case 1:
                $userName = '买家';
                break;
            case 2:
                $userName = '商家';
                break;
            default:
                $userName = '系统';
        }
        if ($userType == 1 && ($data['uid'] != $afInfo['uid'])) {
            throw new AfterSaleException(['msg' => '仅限本人操作!']);
        }
        //新增退售后详情
        $aDetail['after_sale_sn'] = $data['after_sale_sn'];
        $aDetail['order_sn'] = $data['order_sn'];
        $aDetail['uid'] = $afInfo['uid'];
        $aDetail['type'] = $afInfo['type'];
        $aDetail['msg_code'] = (new CodeBuilder())->buildAfterSaleMsgCode();
        $aDetail['after_status'] = 88;
        $aDetail['operate_user'] = $userName;
        $aDetail['content'] = $userName . '提交留言: ' . $message;
        $aDetail['close_time'] = time();
        if ($userType == 2) {
            $aDetail['is_reply'] = 2;
        }
        $newDetailID = (new AfterSaleDetail())->baseCreate($aDetail, true);
        if (!empty($images)) {
            foreach ($images as $key => $value) {
                $auImages['after_sale_detail_id'] = $newDetailID;
                $auImages['after_sale_sn'] = $afSn;
                $auImages['order_sn'] = $aDetail['order_sn'];
                $auImages['image_path'] = $value;
                $imgRes = AfterSaleImages::create($auImages);
            }
        }

        //买家留言 修改为已回复商家留言
        if ($userType == 1) {
            AfterSaleDetail::update(['is_reply' => 1], ['msg_code' => $data['msg_code'], 'after_sale_sn' => $afInfo['after_sale_sn'], 'after_status' => 88]);
        }

        $afterType = [1 => '仅退款', 2 => '退货退款', 3 => '换货'];
        $length = mb_strlen($goodsTitle);
        if ($length >= 17) {
            $goodsTitle = mb_substr($goodsTitle, 0, 17) . '...';
        }


        $returnData['remarkRes'] = judge($newDetailID);
        switch ($userType) {
            case 1:
                $returnData['templateId'] = [config('system.templateId.afterSaleRemark')];
                break;
            case 2:
                //发送模版消息
                $template['uid'] = $afInfo['uid'];
                $template['type'] = 'afterSaleRemark';
                if (empty($aDetail['msg_code'] ?? null)) {
                    $template['page'] = 'pages/index/index';
                } else {
                    $template['page'] = 'pages/return-message/return-message?code=' . $aDetail['msg_code'];
                }
                $template['access_key'] = getAccessKey();
                $template['template'] = ['character_string3' => $afInfo['order_sn'], 'thing7' => $goodsTitle, 'thing1' => $afterType[$afInfo['type']], 'thing2' => '售后有新留言待您回复', 'thing10' => '点击可查看详细信息'];
                $templateQueue = Queue::push('app\lib\job\Template', $template, config('system.queueAbbr') . 'TemplateList');
                break;
        }

        return $returnData;
    }

    /**
     * @title  重新打开退售后
     * @param array $data
     * @return mixed
     */
    public function openAfterSaleAgain(array $data)
    {
        //每打开一次退售后允许用户被拒几次,默认1次
        $addNumber = 1;
        $orderSn = $data['order_sn'];
        $afterSn = $data['after_sale_sn'];
        $adminInfo = $data['adminInfo'] ?? [];
        $afInfo = $this->with(['user', 'detail', 'orderInfo'])->where(['after_sale_sn' => $afterSn, 'order_sn' => $orderSn])->findOrEmpty()->toArray();
        if (empty($afInfo)) {
            throw new AfterSaleException(['errorCode' => 2000101]);
        }

        if (!in_array($afInfo['orderInfo']['order_status'], [2, 3, 6])) {
            throw new AfterSaleException(['msg' => '仅限待发货,已发货,售后中的订单才可以重新打开退售后哟']);
        }
        $goodsInfo = OrderGoods::where(['order_sn' => $afInfo['order_sn'], 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn']])->withoutField('id')->findOrEmpty()->toArray();
        if ($goodsInfo['after_status'] != 5) {
            throw new AfterSaleException(['msg' => '仅限商品售后被拒的状态下才可以重新打开退售后哟']);
        }

        $goodsChange['after_status'] = 1;
        if (intval($goodsInfo['apply_after_sale_number']) <= 0) {
            $goodsChange['apply_after_sale_number'] = 1;
        } else {
            $goodsChange['apply_after_sale_number'] = intval($goodsInfo['apply_after_sale_number']) + $addNumber;
        }

        //数据库操作
        $DBRes = Db::transaction(function () use ($goodsChange, $afInfo, $adminInfo) {
            $goodsRes = OrderGoods::update($goodsChange, ['order_sn' => $afInfo['order_sn'], 'goods_sn' => $afInfo['goods_sn'], 'after_status' => 5]);

            //追加售后明细
            $userName = !empty($adminInfo) ? ($adminInfo['name'] ?? '管理员') : '管理员';
            $aDetail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $aDetail['order_sn'] = $afInfo['order_sn'];
            $aDetail['uid'] = $afInfo['uid'];
            $aDetail['type'] = $afInfo['type'];
            $aDetail['after_status'] = 99;
            $aDetail['operate_user'] = $userName;
            $aDetail['content'] = $userName . '重新打开了退售后入口';
            $aDetail['close_time'] = time();
            $newDetailID = (new AfterSaleDetail())->baseCreate($aDetail, true);
            return $goodsRes;
        });

        return judge($DBRes);
    }

    /**
     * @title  修改订单对应的状态
     * @param array $aOrder
     * @param array $afInfo
     * @param array $updateData
     * @param bool $AutoWriteTimestamp
     * @return bool
     * @throws \Exception
     */
    public function changeOrderAfterStatus(array $afInfo, array $aOrder, array $updateData, bool $AutoWriteTimestamp = false)
    {
        $uOrderStatus = $updateData['order_status'] ?? null;
        $uAfterStatus = $updateData['after_status'];
        //售后发起类型 1为用户申请售后,判断售后数量时需要自加1,2为后续的审核,退款等状态,判断不+1,默认为2
        $afterType = $updateData['after_change_type'] ?? 2;
        //检查当前订单的所有商品的售后状态 如果全部商品都在售后状态当前订单的售后状态为全部售后,否则为部分售后
        $OrderGoods = OrderGoods::where(['order_sn' => $aOrder['order_sn']])->withoutField('id,create_time,update_time')->select()->toArray();
        $aOrder['goods'] = $OrderGoods;
        foreach ($OrderGoods as $key => $value) {
            $goodsInfo[$value['sku_sn']] = $value;
        }

        $sfNumber = 2;
        $afOrderGoodsNumber = 0;
        if (!empty($aOrder['goods'])) {
            foreach ($aOrder['goods'] as $key => $value) {
                if (in_array($value['after_status'], [2, 3, 4])) {
                    $afOrderGoodsNumber++;
                }
            }
            $allOrderGoods = count($aOrder['goods']);
            $selfAddNumber = $afterType == 1 ? 1 : 0;
            if ($afOrderGoodsNumber + $selfAddNumber >= $allOrderGoods) {
                $sfNumber = 1;
            }
        }

        $orderModel = (new Order());
        $orderGoodsModel = (new OrderGoods());
        $shipOrderModel = (new ShipOrder());

        //如果是售后被拒或者取消的情况应该把主订单和发货订单的状态修改回来,其他的情况为正常情况
        if ($uAfterStatus == 5 || $uAfterStatus == -1) {
            //订单状态需要修改回原来的状态,如果有物流(获取免物流单号发货)则代表已发货,无物流单号则表示待发货
            if (!empty($aOrder['shipping_code']) || (!empty($aOrder['shipping_type']) && $aOrder['shipping_type'] == 2)) {
                $orderSave['order_status'] = 3;
            } else {
                $orderSave['order_status'] = 2;
            }
//            $uOrderStatus = $orderSave['order_status'];
            $orderSave['after_status'] = 1;
            $orderSave['after_number'] = !empty($afOrderGoodsNumber) ? 2 : 0;
        } else {
            //只有全部售后的情况下才修改主订单的订单状态和售后状态,否则不修改订单状态
            if ($sfNumber == 1) {
                $orderSave['after_status'] = $uAfterStatus;
            }
            if (!empty($uOrderStatus)) {
                if ($sfNumber == 1) {
                    $orderSave['order_status'] = $uOrderStatus;
                }
            }
            $orderSave['after_number'] = $sfNumber;
        }


        //是否需要修改传入的$aOrder对应订单的状态,如若售后的商品是拆单的子订单的商品则不需要修改主订单的状态
        $noChangeParentOrder = false;
        //如果同步过则需要先修改发货订单的状态
        if ($aOrder['sync_status'] == 1) {
            $shipOrderSn = [];
            //如果该订单为拆单的订单,查询的是父类或子类包含了该产品的订单,如果查到多个包含子父的订单,(有可能是对多商品拆单的订单,(若是拆商品的订单则只修改对应的父或子订单),也有可能是拆数量的订单,若是拆数量的订单,但是因为单个SKU申请售后不允许申请不同数量,购买多个数量的单个SKU只能全部退或者退金额,所以查出来的子父订单无论如何都是全部售后状态
            //如果该订单为正常的订单,则根据全部商品售后的数量来判断是否是全部售后或部分售后状态
            if (in_array($aOrder['split_status'], [1, 3])) {
                $shipOrderSave = $orderSave;
                $shipOrderList = $shipOrderModel->list(['searOrderSn' => $aOrder['order_sn'], 'searGoodsSkuSn' => [$afInfo['sku_sn']], 'searGoodsSpuSn' => [$afInfo['goods_sn']] ?? []]);

                $aShipOrder = $shipOrderList['list'] ?? [];
                if (!empty($aShipOrder)) {
                    foreach ($aShipOrder as $key => $value) {
                        if (!isset($shipOrderAfterGoodsNumber[$value['order_sn']])) {
                            $shipOrderAfterGoodsNumber[$value['order_sn']] = 0;
                        }
                        if ($value['split_status'] == 1) {
//                            if($value['goods_sku'] == $afInfo['sku_sn']){
                            if (in_array($afInfo['sku_sn'], explode(',', $value['goods_sku']))) {
                                $shipOrderSave = $orderSave;
                                //如果不是被拒或者取消则正常修改状态,如果是被拒或取消则强行修改订单状态为正常
                                if ($uAfterStatus != 5 && $uAfterStatus != -1) {
                                    //售后成功的子订单的订单状态必须为关闭,如果是拆数量的订单跟随传入的order_status,因为可能会是部分退款
//                                    $orderSave['after_number'] = 1;
//                                    $orderSave['order_status'] = $uAfterStatus != 4 ? $uOrderStatus : -3;
//                                    $orderSave['after_status'] = $uAfterStatus;
                                    $shipOrderSave['after_number'] = 1;
//                                    if (!empty($value['split_number']) && count(explode(',',$value['goods_sku'])) == 1) {
                                    //如果主订单需要打回发货的状态,拆数量的子订单的订单状态需要重新判断: 如果已经是退完商品款了,直接关闭订单; 如果不是则根据之前的发货状态来修改回具体的订单状态,因为有可能出现订单主状态是发货的(因为只要发了一件整单状态就算发货了),但是拆数量的子订单仍有部分未发货
                                    foreach (explode(',', $value['goods_sku']) as $gKey => $gValue) {
                                        if ((!empty($goodsInfo[$gValue]))) {
                                            if ($gValue == $afInfo['sku_sn']) {
                                                $price = $afInfo['apply_price'];
                                            } else {
                                                $price = $goodsInfo[$gValue]['refund_price'] ?? 0;
                                            }
                                            if (((string)$price >= (string)($goodsInfo[$gValue]['total_price'] - ($goodsInfo[$gValue]['all_dis'] ?? 0)))) {
                                                $shipOrderAfterGoodsNumber[$value['order_sn']] += 1;
                                            }
                                        }
                                    }

//                                        if((!empty($goodsInfo[$afInfo['sku_sn']])) && (string)($afInfo['apply_price'] >= (string)($goodsInfo[$afInfo['sku_sn']]['total_price'] - ($goodsInfo[$afInfo['sku_sn']]['all_dis'] ?? 0)))){
//                                            $shipOrderSave['order_status'] = $uAfterStatus != 4 ? $uOrderStatus : -3;
//                                        }

                                    if ((string)$shipOrderAfterGoodsNumber[$value['order_sn']] >= count(explode(',', $value['goods_sku']))) {
                                        $shipOrderSave['order_status'] = $uAfterStatus != 4 ? $uOrderStatus : -3;
                                    } else {
                                        if (!empty($shipOrderSave['order_status']) && $shipOrderSave['order_status'] == 3) {
                                            if (!empty($value['shipping_code']) || (!empty($value['shipping_type']) && $value['shipping_type'] == 2)) {
                                                $shipOrderSave['order_status'] = 3;
                                            } else {
                                                $shipOrderSave['order_status'] = 2;
                                            }
                                        }
                                    }
//                                    }else{
//                                        $shipOrderSave['order_status'] = $uAfterStatus != 4 ? $uOrderStatus : -3;
//                                    }

                                    $shipOrderSave['after_status'] = $uAfterStatus;
                                }
                                $shipRes = $shipOrderModel->isAutoWriteTimestamp($AutoWriteTimestamp)->where(['order_sn' => $value['order_sn']])->save($shipOrderSave);
                                if ($sfNumber == 2) {
                                    $noChangeParentOrder = true;
                                }
                            }
                        } elseif ($value['split_status'] == 3) {
                            $shipRes = $shipOrderModel->isAutoWriteTimestamp($AutoWriteTimestamp)->where(['order_sn' => $value['order_sn']])->save($shipOrderSave);
                        }
                    }
                }
            } else {
                //如果该订单为合单的订单,判断当前订单是否为合单父订单,如果是,则统计子订单中所有处于售后状态订单的数量,在结合当前自己的订单的全部商品售后状态判断是否为全部售后; 如果当前订单为合单子订单,则找到父订单后继续统计全部子订单的售后状态订单,以判断父订单的售后状态是否为全部售后
                $shipOrderInfo = $shipOrderModel->with(['childOrder'])->where(['order_sn' => $aOrder['order_sn']])->field('order_sn,parent_order_sn')->findOrEmpty()->toArray();
                if (!empty($shipOrderInfo['parent_order_sn'])) {
                    $shipParentOrder = $shipOrderModel->with(['childOrder'])->where(['searOrderSn' => $aOrder['parent_order_sn']])->field('order_sn,parent_order_sn,order_status,after_status')->findOrEmpty()->toArray();
                    $mergeAfNumber = 0;
                    if (in_array($shipParentOrder['after_status'], [2, 3, 4])) {
                        $mergeAfNumber = 1;
                    };
                    if (!empty($shipParentOrder)) {
                        foreach ($shipParentOrder['childOrder'] as $key => $value) {
                            if (($value['order_sn'] != $shipOrderInfo['order_sn']) && (in_array($value['after_status'], [2, 3, 4]))) {
                                $mergeAfNumber++;
                            }
                        }
                        if ($mergeAfNumber >= (count($shipParentOrder['childOrder']) + 1)) {
                            $parentAfNumber = 1;
                        } else {
                            $parentAfNumber = 2;
                        }
                        $shipOrderSn[$shipOrderInfo['parent_order_sn']] = $parentAfNumber;
                    }
                } else {
                    $mergeCAfNumber = 0;
                    if (!empty($shipOrderInfo['childOrder'])) {
                        foreach ($shipOrderInfo['childOrder'] as $key => $value) {
                            if (in_array($value['after_status'], [2, 3, 4])) {
                                $mergeCAfNumber++;
                            }
                        }
                        if ($mergeCAfNumber >= count($shipOrderInfo['childOrder'])) {
                            $parentAfNumber = 1;
                        } else {
                            $parentAfNumber = 2;
                        }
                    } else {
                        $parentAfNumber = 1;
                    }

                    if ($sfNumber == 1) {
                        $shipOrderSn[$shipOrderInfo['order_sn']] = $parentAfNumber;
                    } else {
                        $shipOrderSn[$shipOrderInfo['order_sn']] = $sfNumber;
                    }

                }
                if (!empty($shipOrderSn)) {
                    $mergerOrderSave = $orderSave;
                    foreach ($shipOrderSn as $key => $value) {
                        $mergerOrderSave['after_number'] = $value;
                        $shipRes = $shipOrderModel->isAutoWriteTimestamp($AutoWriteTimestamp)->where(['order_sn' => $key])->save($mergerOrderSave);
                    }

                }
            }

        }
        $orderRes = false;
        if (empty($noChangeParentOrder)) {
            //修改订单状态
            $orderRes = $orderModel->isAutoWriteTimestamp($AutoWriteTimestamp)->where(['order_sn' => $aOrder['order_sn']])->save($orderSave);
        }

        $goodsSave['after_status'] = $uAfterStatus;
        //售后处理成功订单关闭对应商品也关闭(为全款退才让状态变为-2)
        if ((!empty($orderSave['order_status']) && $orderSave['order_status'] == -3) || $goodsSave['after_status'] == 4 && (!empty($goodsInfo[$afInfo['sku_sn']])) && (string)($afInfo['apply_price'] >= (string)($goodsInfo[$afInfo['sku_sn']]['total_price'] - ($goodsInfo[$afInfo['sku_sn']]['all_dis'] ?? 0)))) {
            $goodsSave['status'] = -2;
        }
//        if(!empty($orderSave['order_status'])){
//            $goodsSave['status'] = -2;
//        }

        //修改订单商品状态
        $goodsRes = $orderGoodsModel->isAutoWriteTimestamp($AutoWriteTimestamp)->where(['order_sn' => $aOrder['order_sn'], 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn']])->save($goodsSave);

        return $orderRes;
    }

    /**
     * @title  修改正常订单的状态(老版本)
     * @param array $afterSaleInfo 售后详情
     * @param array $map 需要判断的条件
     * @param array $update 需要修改的订单状态
     * @return mixed
     * @throws \Exception
     */
    public function changeOrderAfterStatusOld(array $afterSaleInfo, array $map, array $update)
    {
        $afterStatusMap = $map['after_status'];
        $orderRes = (new Order())->isAutoWriteTimestamp(false)->where(['order_sn' => $afterSaleInfo['order_sn'], 'after_status' => $afterStatusMap])->save($update);
        $goodsRes = (new OrderGoods())->isAutoWriteTimestamp(false)->where(['order_sn' => $afterSaleInfo['order_sn'], 'after_status' => $afterStatusMap, 'goods_sn' => $afterSaleInfo['goods_sn'], 'sku_sn' => $afterSaleInfo['sku_sn']])->save($update);
        $shipOrderList = (new ShipOrder())->list(['searOrderSn' => $afterSaleInfo['order_sn'], 'afterType' => [$afterStatusMap], 'order_sku' => $afterSaleInfo['sku_sn']]);
        $aShipOrder = $shipOrderList['list'] ?? [];
        if (!empty($aShipOrder)) {
            $shipOrderSn = array_column($aShipOrder, 'order_sn');
            $shipRes = (new ShipOrder())->isAutoWriteTimestamp(false)->where(['order_sn' => $shipOrderSn, 'after_status' => $afterStatusMap])->save($update);
        }
        return true;
    }


    /**
     * @title  售后处理完成
     * @param array $data
     * @return mixed
     */
    public function shutDownAfterSale(array $data)
    {
        //仅 7为退款成功 9用户确认收到换货 -1为用户取消售后申请 -2为商家拒绝确认收货 -3为商家拒绝退款 可以完结售后
        $afSn = $data['after_sale_sn'];
        $map[] = ['after_status', 'in', [7, 9, -1, -2, -3]];
        $map[] = ['after_sale_sn', '=', $afSn];
        $map[] = ['status', '=', 1];
        $afInfo = $this->with(['user'])->where($map)->findOrEmpty()->toArray();
        if (empty($afInfo)) {
            throw new AfterSaleException(['errorCode' => 2000102]);
        }
        $auShip['after_status'] = 10;
        $dbRes = Db::transaction(function () use ($auShip, $afInfo, $data, $map) {
            $afDetailModel = new AfterSaleDetail();
            //新增售后信息
            $res = $this->baseUpdate($map, $auShip);
            //添加售后流程明细
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '系统';
            $afDetailModel->DBNew($detail);
            return $res;
        });
        return judge($dbRes);
    }

    /**
     * @title  售后消息详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function afterSaleMsgInfo(array $data)
    {
        $info = [];
        $msgInfo = [];
        $msgCode = $data['msg_code'] ?? null;
        if (empty($msgCode)) {
            throw new AfterSaleException(['msg' => '缺少消息唯一标识']);
        }
        $uid = $data['uid'] ?? null;
        if (empty($uid)) {
            throw new AfterSaleException(['msg' => '请先登录~']);
        }
        $msgInfo = AfterSaleDetail::where(['msg_code' => $msgCode, 'status' => $this->getStatusByRequestModule(1)])->field('after_sale_sn,order_sn,type,operate_user,uid,msg_code,content,create_time')->findOrEmpty()->toArray();

        if (empty($msgInfo)) {
            throw new AfterSaleException(['msg' => '查无消息~']);
        }
        if ($msgInfo['uid'] != $uid) {
            throw new AfterSaleException(['msg' => '仅允许查看自己的售后信息哈~']);
        }
        $msgInfo['content'] = str_replace('商家提交留言: ', '', $msgInfo['content']);

        //查找退售后订单详情
        $asSn = $msgInfo['after_sale_sn'];
        $info = $this->with(['user', 'orderInfo'])->where(['after_sale_sn' => $asSn])->findOrEmpty()->toArray();
        if (!empty($info)) {
            $info['goods'] = OrderGoods::where(['order_sn' => $info['order_sn'], 'goods_sn' => $info['goods_sn'], 'sku_sn' => $info['sku_sn']])->withoutField('id')->findOrEmpty()->toArray();
        }

        return ['afterInfo' => $info ?? [], 'msgInfo' => $msgInfo ?? []];
    }


    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid')->field('uid,name,phone,avatarUrl');
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'a.after_sale_sn,a.order_sn,a.order_real_price,a.uid,b.name as user_name,b.link_superior_user,a.type,a.apply_reason,a.apply_status,a.apply_price,a.buyer_received_goods,a.verify_status,a.verify_reason,a.apply_time,a.verify_time,a.create_time,a.after_status,a.status,c.title as goods_title,c.images as goods_images,c.specs as goods_specs,c.count as goods_count,c.shipping_status,a.withdraw_time,a.user_arrive_price_time,a.sku_sn,a.goods_sn,c.refund_price,c.total_price,c.supplier_code';
                break;
            case 'api':
                $field = 'a.after_sale_sn,a.sku_sn,a.order_sn,a.order_real_price,a.uid,b.name as user_name,a.type,a.apply_reason,a.apply_status,a.apply_price,a.buyer_received_goods,a.verify_status,a.verify_reason,a.apply_time,a.verify_time,a.create_time,a.after_status,a.status,c.title as goods_title,c.images as goods_images,c.specs as goods_specs,c.count as goods_count';
                break;
            default:
                $field = 'a.order_sn,a.uid,b.name as user_name,a.goods_sn,a.sku_sn,c.title as goods_title,c.image as goods_images,a.type,a.apply_reason,a.apply_status,a.apply_price,a.received_goods,a.verify_status,a.verify_reason,a.apply_time,a.verify_time,a.create_time,a.status';
        }
        return $field;
    }

    public function detail()
    {
        return $this->hasMany('AfterSaleDetail', 'after_sale_sn', 'after_sale_sn')->order('id desc,create_time desc')->where(['status' => 1]);
    }

    public function goods()
    {
        return $this->hasOne('GoodsSku', 'sku_sn', 'sku_sn')->where(['status' => 1]);
    }

    public function orderInfo()
    {
        return $this->hasOne('Order', 'order_sn', 'order_sn');
    }


    public function getApplyTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    public function getVerifyTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }
}