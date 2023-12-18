<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 设备模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\AfterSaleException;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\PpylException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\services\CodeBuilder;
use app\lib\services\JoinPay;
use app\lib\services\Log;
use app\lib\services\Pay;
use app\lib\services\Order;
use app\lib\services\QrCode;
use app\lib\services\Wx;
use app\lib\services\WxPayService;
use Exception;
use think\facade\Db;
use think\facade\Queue;
use function GuzzleHttp\default_user_agent;

class Device extends BaseModel
{
    /**
     * @title  设备列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        $cacheKey = false;
        $cacheExpire = 0;

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('device_name|device_sn|power_imei|concact_name|concact_phone', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }


        $list = $this->with(['user'])->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->order('sort asc,create_time desc')->select()->each(function ($item) {
            if ($this->module == 'api') {
                if (!empty($item['device_image'])) {
//                    $item['image'] .= '?x-oss-process=image/resize,h_1170,m_lfit';
                    $item['image'] .= '?x-oss-process=image/format,webp';
                }
            }
            return $item;
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  C端设备列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function cList(array $sear = []): array
    {
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $list = $this->where($map)->field('device_sn,device_name,device_image,sort')->order('create_time desc')->select()->toArray();
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  设备详情
     * @param string $deviceSn 设备编码
     * @return mixed
     */
    public function info(string $deviceSn)
    {
        $info = $this->with(['combo' => function ($query) {
            $query->where(['status' => 1])->field('device_sn,combo_sn,combo_title,power_imei,oper_image,desc,continue_time,price,healthy_price,user_divide_scale');
        }])->where(['device_sn' => $deviceSn, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
        $info['pay_type'] = [7];
        return $info;
    }

    /**
     * @title  C端设备详情
     * @param string $deviceSn 设备编码
     * @return mixed
     */
    public function cInfo(string $deviceSn)
    {
        $info = $this->with(['combo' => function ($query) {
            $query->where(['status' => 1])->field('device_sn,combo_sn,combo_title,power_imei,oper_image,desc,continue_time,price,healthy_price');
        }])->where(['device_sn' => $deviceSn, 'status' => $this->getStatusByRequestModule()])->field('device_sn,device_name,device_image,device_show_sn,address,concact_name,concact_phone,price,healthy_price,device_status')->findOrEmpty()->toArray();
        $info['pay_type'] = [7];
        return $info;
    }

    /**
     * @title  新增设备
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $userInfo = User::where(['uid' => $data['uid'], 'status' => 1])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new UserException(['msg' => '绑定的用户无效']);
        }
        if (intval($userInfo['area_vip_level'] ?? 0) <= 0 && intval($userInfo['exp_level'] ?? 0) <= 0) {
            throw new UserException(['msg' => '绑定的用户非区代或体验中心']);
        }
        $existDevice = self::where(['device_sn' => $data['device_sn'], 'status' => [1, 2]])->count();
        if (!empty($existDevice)) {
            throw new UserException(['msg' => '已存在的设备']);
        }
        if (empty($data['address_detail'] ?? null)) {
            throw new ServiceException(['msg' => '请填写包含有效省市区的完整地址']);
        }
        $data['address_detail'] = json_encode($data['address_detail'], 256);
        $res = Db::transaction(function () use ($data) {
            $deviceRes = $this->baseCreate($data, true);
            if (!empty($data['combo'])) {
                (new DeviceCombo())->DBNewOrEdit(['device_sn' => $data['device_sn'], 'combo' => $data['combo']]);
            }
            return $deviceRes;
        });
        //清除回调信息中的设备列表缓存
        cache('callbackDeviceList', null);
        return $res;
    }

    /**
     * @title  编辑设备
     * @param array $data
     * @return Device
     */
    public function edit(array $data)
    {
        $userInfo = User::where(['uid' => $data['uid'], 'status' => 1])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new UserException(['msg' => '绑定的用户无效']);
        }
        if (intval($userInfo['area_vip_level'] ?? 0) <= 0 && intval($userInfo['exp_level'] ?? 0) <= 0) {
            throw new UserException(['msg' => '绑定的用户非区代或体验中心']);
        }
        $save = $data;
        unset($save['device_sn']);
        if (empty($data['address_detail'] ?? null)) {
            throw new ServiceException(['msg' => '请填写包含有效省市区的完整地址']);
        }
        $save['address_detail'] = json_encode($data['address_detail'], 256);
        $DBRes = Db::transaction(function () use ($data, $save) {
            $deviceRes = $this->baseUpdate(['device_sn' => $data['device_sn']], $save);
            if (!empty($data['combo'])) {
                (new DeviceCombo())->DBNewOrEdit(['device_sn' => $data['device_sn'], 'combo' => $data['combo']]);
            }
            return $deviceRes;
        });
        //清除回调信息中的设备列表缓存
        cache('callbackDeviceList', null);
        return $DBRes;
    }

    /**
     * @title  删除设备
     * @param string $deviceSn 设备编码
     * @return self
     */
    public function del(string $deviceSn)
    {
        //清除回调信息中的设备列表缓存
        cache('callbackDeviceList', null);
        return $this->baseDelete(['device_sn' => $deviceSn]);
    }

    /**
     * @title  上下架
     * @param array $data
     * @return mixed
     */
    public function upOrDown(array $data)
    {
        if ($data['status'] == 1) {
            $save['status'] = 2;
        } elseif ($data['status'] == 2) {
            $save['status'] = 1;
        } else {
            return false;
        }
        return $this->baseUpdate(['device_sn' => $data['device_sn']], $save);
    }

    /**
     * @title  设备上下架
     * @param array $data
     * @return mixed
     */
    public function deviceUpOrDown(array $data)
    {
        if ($data['status'] == 1) {
            $save['device_status'] = 2;
        } elseif ($data['status'] == 2) {
            $save['device_status'] = 1;
        } else {
            return false;
        }
        return $this->baseUpdate(['device_sn' => $data['device_sn']], $save);
    }

    /**
     * @title  生成机器订单
     * @param array $data
     * @throws \Exception
     * @return mixed
     */
    public function buildOrder(array $data)
    {
        $deviceSn = trim($data['device_sn']);
        $comboSn = trim($data['combo_sn'] ?? null);
        $deviceInfo = self::where(['device_sn' => $deviceSn, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($deviceInfo)) {
            throw new ServiceException(['msg' => '无该机器信息']);
        }
        if ($deviceInfo['device_status'] != 1) {
            throw new ServiceException(['msg' => '机器暂无法使用']);
        }
        $comboInfo = DeviceCombo::where(['device_sn' => $deviceSn, 'combo_sn' => $comboSn, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($comboInfo)) {
            throw new ServiceException(['msg' => '无有效套餐信息']);
        }
        $uid = $data['uid'] ?? null;
        $userInfo = User::where(['uid'=>$uid,'status'=>1])->field('uid,openid,phone,name,healthy_balance')->findOrEmpty()->toArray();
        $userInfo['openid'] = User::getOpenIdByUid($uid);
        if (empty($userInfo)) {
            throw new UserException(['msg' => '查无用户信息, 请先授权登陆']);
        }
        //查询机器是否有待完成的订单
        $deviceOrder = DeviceOrder::where(['device_sn' => $deviceSn, 'order_status' => [2]])->findOrEmpty()->toArray();
        if (!empty($deviceOrder)) {
            throw new UserException(['msg' => '机器正在使用中~请您稍后重试']);
        }

        switch ($data['pay_type'] ?? 7){
            case 7:
                $totalPrice = doubleval($comboInfo['healthy_price']);
                if((string)doubleval($userInfo['healthy_balance']) < (string)doubleval($totalPrice)){
                    throw new OrderException(['msg'=>'余额不足']);
                }
                break;
            default:
                $totalPrice = doubleval($comboInfo['price']);
                break;
        }

        //创建订单
        $DBRes = Db::transaction(function () use ($data, $deviceSn, $uid, $userInfo ,$totalPrice,$deviceInfo,$comboSn,$comboInfo) {
            $newOrder['order_sn'] = 'D' . (new CodeBuilder())->buildOrderNo();
            $newOrder['device_sn'] = $deviceSn;
            $newOrder['order_belong'] = 1;
            $newOrder['order_type'] = 1;
            $newOrder['uid'] = $uid;
            $newOrder['user_phone'] = $userInfo['phone'] ?? null;
            $newOrder['total_price'] = $totalPrice;
            $newOrder['real_pay_price'] = $totalPrice;
            $newOrder['pay_type'] = $data['pay_type'] ?? 7;
            $newOrder['vdc_allow'] = 1;
            $newOrder['link_superior_user'] = $userInfo['link_superior_user'] ?? null;
            $newOrder['user_level'] = $userInfo['vip_level'] ?? 0;
            $newOrder['device_link_uid'] = $deviceInfo['uid'] ?? null;
            $newOrder['divide_scale'] = $comboInfo['user_divide_scale'] ?? 0;
            $newOrder['device_price'] = $comboInfo['price'] ?? 0;
            $newOrder['device_combo_sn'] = $comboSn ?? null;
            $newOrder['device_power_imei'] = $deviceInfo['power_imei'] ?? null;
            $newOrder['device_power_number'] = $deviceInfo['power_number'] ?? null;
            $newOrder['device_address'] = $deviceInfo['address'] ?? null;
            $newOrder['device_address_detail'] = $deviceInfo['address_detail'] ?? null;
            $newOrder['device_cash_price'] = $comboInfo['price'] ?? null;
//            $orderRes = (new DeviceOrder())->save($newOrder);

            $newOrder['create_time'] = time();
            $newOrder['update_time'] = $newOrder['create_time'] ?? time();
            $res = Db::name('device_order')->insert($newOrder);

//            return ['aOrderRes' => $newOrder];
            return ['aOrderRes' => $newOrder];
        });

        if(empty($DBRes) || empty($DBRes['aOrderRes'] ?? [])){
            throw new OrderException(['msg'=>'生成订单有误']);
        }

        $newOrder = $DBRes['aOrderRes'];
        if (!empty(doubleval($newOrder['real_pay_price']))) {
            switch ($data['pay_type'] ?? 7){
                case 2:
                    //微信支付
                    $wxOrder['openid'] = $userInfo['openid'];
                    $wxOrder['out_trade_no'] = $newOrder['order_sn'];
                    $wxOrder['body'] = '商城购物订单D';
                    $wxOrder['attach'] = '商城订单D';
                    $wxOrder['total_fee'] = $newOrder['real_pay_price'];
                    //$wxOrder['total_fee'] = 0.01;
                    //微信商户号支付
//                            $wxOrder['notify_url'] = config('system.callback.wxPayCallBackUrl');
//                            $wxRes = (new WxPayService())->order($wxOrder);
                    //汇聚支付
                    $wxOrder['notify_url'] = config('system.callback.joinPayCallBackUrl');
                    $wxRes = (new JoinPay())->order($wxOrder);
                    $wxRes['need_pay'] = true;
                    $wxRes['complete_pay'] = false;
                    $cacheOrder = $wxRes;
                    $cacheOrder['order_sn'] = $newOrder['order_sn'];
                    cache($newOrder['order_sn'], $cacheOrder, (new Order())->orderCacheExpire);
                    break;
                case 7:
                    $accountPay['out_trade_no'] = $newOrder['order_sn'];
                    $accountPay['transaction_id'] = null;
                    $payRes = (new Pay())->completeDevicePay($accountPay);
                    $wxRes['need_pay'] = false;
                    $wxRes['complete_pay'] = !empty($payRes ?? false) ? true : false;
                    $wxRes['msg'] = '健康豆支付中,订单稍后完成';
                    break;
                default:
                    throw new OrderException(['msg'=>'暂不支持的付款方式']);
                    break;
            }
        }else{
            $freeOrder['out_trade_no'] = $newOrder['order_sn'];
            $freeOrder['transaction_id'] = null;
            (new Pay())->completeDevicePay($freeOrder);
            $wxRes['need_pay'] = false;
            $wxRes['complete_pay'] = true;
            $wxRes['msg'] = '无需支付,订单稍后完成';
        }
        $wxRes['order_sn'] = $newOrder['order_sn'];

        return $wxRes;
    }

    /**
     * @title  确认设备启动成功或完成运行, 修改订单状态
     * @param array $data
     * @return mixed
     */
    public function confirmDeviceComplete(array $data)
    {
        $deviceSn = $data['device_sn'];
        $orderInfo = DeviceOrder::where(['device_sn' => $deviceSn, 'order_sn'=>$data['order_sn'],'pay_status' => [2], 'order_status' => 2])->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            throw new OrderException(['msg' => '查无设备订单信息']);
        }
        //修改订单状态为已完成, 并分润
        $save['order_status'] = 8;
        $save['end_time'] = time();
        if (!empty($data['device_start_time'] ?? null)) {
            $save['device_start_time'] = strtotime($data['device_start_time']);
        }
        if (!empty($data['device_end_time'] ?? null)) {
            $save['device_end_time'] = strtotime($data['device_end_time']);
        }

//        $res = DeviceOrder::update($save, ['order_sn' => $data['order_sn'], 'device_sn' => $deviceSn, 'pay_status' => [2], 'order_status' => 2]);
        $res = Db::name('device_order')->strict(false)->where(['order_sn' => $data['order_sn'], 'device_sn' => $deviceSn, 'pay_status' => [2], 'order_status' => 2])->update($save);

        $divideQueue = Queue::push('app\lib\job\Auto', ['order_sn' => $orderInfo['order_sn'], 'device_sn' => $orderInfo['device_sn'], 'autoType' => 6], config('system.queueAbbr') . 'Auto');
        return judge($res);
    }

    /**
     * @title  设备分润
     * @param array $data
     * @return mixed
     */
    public function divideForDevice(array $data)
    {
        $orderSn = $data['order_sn'];
        $log['requestData'] = $data;
        $orderInfo = DeviceOrder::where(['order_sn' => $orderSn, 'pay_status' => [2], 'order_status' => 8])->findOrEmpty()->toArray();
        $log['orderInfo'] = $orderInfo;
        if (empty($orderInfo)) {
            return $this->recordError($log, ['msg' => '查无订单信息或订单状态未完结']);
        }
        if (!empty($orderInfo['device_link_uid'] ?? null)) {
            $divideUser = $orderInfo['device_link_uid'];
        } else {
            $divideUser = Device::where(['device_sn' => $orderInfo['device_sn'], 'status' => [1, 2]])->value('uid');
        }
        $log['divideUser'] = $divideUser;
        if (empty($divideUser)) {
            return $this->recordError($log, ['msg' => '该设备暂无可以分润的上级']);
        }
        $areaMember = AreaMember::where(['uid' => $divideUser, 'status' => 1])->value('level');
        $expMember = User::where(['uid' => $divideUser, 'status' => 1])->value('exp_level');
        $log['areaMember'] = $areaMember;
        $log['expMember'] = $expMember;

        if (intval($areaMember) <= 0 && intval($expMember) <= 0) {
            return $this->recordError($log, ['msg' => '该设备绑定的上级已不是区代或体验中心,无法分润']);
        }

        $existDivide = Divide::where(['order_sn' => $orderSn, 'status' => 1])->count();
        if (!empty($existDivide)) {
            return $this->recordError($log, ['msg' => '该设备订单已存在分润']);
        }
        $log['divideUser'] = $divideUser ?? [];
        $DBRes = false;
        if (doubleval($orderInfo['divide_scale']) > 0 && $orderInfo['vdc_allow'] == 1) {
            $DBRes = Db::transaction(function () use ($data, $orderInfo, $divideUser) {
                $key = 0;
                $divideData[$key]['order_sn'] = $orderInfo['order_sn'];
                $divideData[$key]['order_uid'] = $orderInfo['uid'];
                $divideData[$key]['type'] = 7;
                $divideData[$key]['vdc'] = $orderInfo['divide_scale'] ?? 0;
                $divideData[$key]['level'] = $orderInfo['user_level'] ?? 0;
                $divideData[$key]['link_uid'] = $divideUser;
                $divideData[$key]['divide_price'] = priceFormat(($orderInfo['device_price']) * $divideData[$key]['vdc']);
                $divideData[$key]['real_divide_price'] = $divideData[$key]['divide_price'];
                $divideData[$key]['arrival_status'] = 1;
                $divideData[$key]['arrival_time'] = time();
                $divideData[$key]['total_price'] = priceFormat(($orderInfo['real_pay_price']));
                $divideData[$key]['purchase_price'] = priceFormat(($orderInfo['real_pay_price']));
                $divideData[$key]['price'] = $orderInfo['real_pay_price'];
                $divideData[$key]['count'] = 1;
                $divideData[$key]['vdc_genre'] = 2;
                $divideData[$key]['divide_type'] = 2;
                $divideData[$key]['is_device'] = 1;
                $divideData[$key]['device_divide_type'] = 1;
                $divideData[$key]['device_sn'] = $orderInfo['device_sn'];
                $divideData[$key]['remark'] = '设备奖励(' . $divideData[$key]['device_sn'] . ')';
                $res = (new Divide())->saveAll($divideData);

                $balanceDetail[$key]['uid'] = $divideUser;
                $balanceDetail[$key]['order_sn'] = $orderInfo['order_sn'];
                $balanceDetail[$key]['belong'] = $orderInfo['belong'] ?? 1;
                $balanceDetail[$key]['type'] = 1;
                $balanceDetail[$key]['price'] = $divideData[$key]['real_divide_price'];
                $balanceDetail[$key]['change_type'] = 20;
                $balanceDetail[$key]['remark'] = '用户体验设备奖励-' . $orderInfo['order_sn'];
                $balanceDetail[$key]['pay_type'] = $orderInfo['pay_type'] ?? 7;

                $balanceRes = (new BalanceDetail())->saveAll($balanceDetail);

                //修改用户余额
                User::where(['uid' => $divideUser, 'status' => 1])->inc('area_balance', $divideData[$key]['real_divide_price'])->update();

                if (!empty($orderInfo['device_address_detail'] ?? null)) {
                    //发放区域代理奖励
                    $areaDivideQueue = Queue::push('app\lib\job\AreaDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1, 'dealType' => 2, 'grantNow' => true], config('system.queueAbbr') . 'AreaOrderDivide');
                }


                return ['divideRes' => $res ?? [], 'balanceRes' => $balanceRes ?? []];
            });
        }

        $log['DBRes'] = $DBRes;
        $log['msg'] = '以接受到设备订单' . $orderSn . '的分润处理';
        $this->log($log, 'info');
        return true;
    }

    /**
     * @title  设备订单退款
     * @param array $data
     * @return void
     */
    public function deviceOrderRefund(array $data)
    {
        $orderSn = $data['order_sn'];
        $cacheKey = 'deviceOrderRefund-' . $orderSn;
        if (!empty(cache($cacheKey))) {
            throw new OrderException(['msg' => '退款操作中, 请勿重复操作']);
        }
        $log['requestData'] = $data;
        $orderInfo = DeviceOrder::where(['order_sn' => $orderSn, 'pay_status' => [2], 'order_status' => 2])->findOrEmpty()->toArray();
        $log['orderInfo'] = $orderInfo;
        if (empty($orderInfo)) {
            throw new OrderException(['msg' => '查无订单信息或订单状态已完结']);
        }
        $existDivide = Divide::where(['order_sn'=>$orderSn,'status'=>1])->count();
        if (!empty($existDivide)) {
            throw new OrderException(['msg' => '订单已存在分润记录, 无法继续操作']);
        }
        switch ($orderInfo['pay_type'] ?? 7){
            case 1:
            case 7:
                //统一一个方法进行退款处理
                $refundSn = (new CodeBuilder())->buildRefundSn();
                $refundRes = (new Pay())->completeRefund(['out_trade_no' => $orderInfo['order_sn'], 'out_refund_no' => $refundSn]);
                break;
            case 2:
                $refundSn = (new CodeBuilder())->buildRefundSn();
                $refund['out_trade_no'] = $orderInfo['order_sn'];
                $refund['out_refund_no'] = $refundSn;
                $refund['total_fee'] = $orderInfo['real_pay_price'];
                $refund['refund_fee'] = $orderInfo['apply_price'];
//                $refund['total_fee'] = 0.01;
//                $refund['refund_fee'] = 0.01;
                $refund['refund_desc'] = 'D售后服务退款';
                $refund['notThrowError'] = $data['notThrowError'] ?? false;
                $refundTime = time();
                //订单创建时间在2022-06-11 14:30:00之前的订单用微信商户号退款,因为支付也是微信商户号,之后的时间用汇聚支付,2022-06-11 14:07:44修改
//                if (strtotime($orderInfo['create_time']) <= 1654929000) {
//                    $refund['notify_url'] = config('system.callback.refundCallBackUrl');
//                    $refundRes = (new WxPayService())->refund($refund);
//                } else {
//                    //汇聚退款
//                    $refund['notify_url'] = config('system.callback.joinPayRefundCallBackUrl');
//                    $refundRes = (new JoinPay())->refund($refund);
//                }
                //汇聚退款
                $refund['notify_url'] = config('system.callback.joinPayDeviceRefundCallBackUrl');
                $refundRes = (new JoinPay())->refund($refund);
                break;
            default:
                throw new AfterSaleException(['msg' => '系统暂不支持的退款方式']);
        }
        //加上操作缓存锁
        cache($cacheKey,600);
        return $refundRes;
    }

    /**
     * @title  生成设备二维码
     * @param array $data
     * @return array
     */
    public function buildDeviceQrCode(array $data)
    {
        $deviceInfo = self::where(['device_sn' => $data['device_sn'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($deviceInfo['qr_code_url'] ?? null)) {
            $qrCode = (new QrCode())->create($data['content']);
            $update['qr_code_url'] = $qrCode;
        } else {
            $update['qr_code_url'] = $deviceInfo['qr_code_url'];
            $qrCode = $deviceInfo['qr_code_url'];
        }
        //暂时注释关于生成短码的需求,因为小程序被封了-2022-12-12
//        if (empty($deviceInfo['wx_scheme']) || $deviceInfo['scheme_timeout'] <= (time() - 60)) {
//            $schemeParam['page'] = $data['scheme_page'];
//            $schemeParam['query'] = $data['scheme_param'];
//            $schemeParam['notThrowError'] = $data['notThrowError'] ?? false;
//            $newScheme = (new Wx())->wxScheme($schemeParam);
//            if (!empty($newScheme)) {
//                $update['wx_scheme'] = $newScheme['scheme'];
//                $update['scheme_timeout'] = $newScheme['expireTime'];
//                $update['scheme_page'] = $data['scheme_page'];
//                $update['scheme_param'] = $data['scheme_param'];
//            }
//        }

        $update['redirect_url'] = trim($data['redirect_url'] ?? null);

        if (!empty($update)) {
            self::update($update, ['device_sn' => $data['device_sn'], 'status' => [1, 2]]);
        }

        return $qrCode;
    }

    /**
     * @title  获取设备对应的小程序跳转短码
     * @param array $data
     * @return bool|mixed|null
     */
    public function geiDeviceRedirectUrl(array $data)
    {
        $deviceInfo = self::where(['device_sn' => $data['device_sn'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($deviceInfo)) {
            return false;
        }
        //默认跳转小程序, 如果还是没有则跳转H5
        if (empty($deviceInfo['wx_scheme']) || $deviceInfo['scheme_timeout'] <= (time() - 60)) {
            $schemeParam['page'] = $deviceInfo['scheme_page'];
            $schemeParam['query'] = $deviceInfo['scheme_param'];
            $schemeParam['notThrowError'] = true;
            $newScheme = (new Wx())->wxScheme($schemeParam);
            if (empty($newScheme)) {
                return false;
            }
            $update['wx_scheme'] = $newScheme['scheme'];
            $update['scheme_timeout'] = $newScheme['expireTime'];
            self::where($update, ['device_sn' => $data['device_sn'], 'status' => [1, 2]]);
            $url = $update['wx_scheme'];
        } else {
            $url = $deviceInfo['wx_scheme'];
        }

        if (empty($url)) {
            $url = $deviceInfo['redirect_url'] ?? null;
        }

        return $url;

    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'id,title,image,sort,type,status,sort,create_time';
                break;
            case 'api':
                $field = 'id,title,image,sort,type,content';
                break;
            default:
                $field = '*';
        }
        return $field;
    }

    public function user()
    {
        return $this->hasOne('User','uid','uid')->bind(['user_name'=>'name']);
    }

    /**
     * @title  记录错误并保存日志,删除该任务后终止
     * @param array $data 所有数据
     * @param array $error 错误内容
     * @return bool
     */
    public function recordError(array $data, array $error)
    {
        $allData['msg'] = '设备订单 ' . ($data['requestData']['order_sn'] ?? "<暂无订单编号>") . " [ 服务出错:" . ($error['msg'] ?? '原因未知') . " ] ";
        $allData['data'] = $data;
        $allData['error'] = $error;
        $this->log($allData, 'error');
        return false;
    }


    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'error')
    {
        return (new Log())->setChannel('device')->record($data, $level);
    }

    public function combo()
    {
        return $this->hasMany('DeviceCombo','device_sn','device_sn')->where(['status'=>[1]]);
    }

}