<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 团队业绩销售额Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;

use app\lib\exceptions\GrowthValueException;
use app\lib\models\GrowthValueDetail;
use app\lib\models\MemberVdc;
use app\lib\models\Order;
use app\lib\models\OrderGoods;
use app\lib\models\PpylOrder;
use app\lib\models\PtActivity;
use app\lib\models\PtOrder;
use app\lib\models\User;
use app\lib\models\Member;
use app\lib\models\Activity;
use think\facade\Db;
use app\lib\services\Member as MemberService;
use think\facade\Queue;

class TeamSalesPrice
{
    private $normalUserGrowthValueScale = 1;
    private $normalUserShareGrowthValueScale = 0;

    /**
     * @title  新增订单销售额
     * @param string $orderSn
     * @return mixed
     * @throws \Exception
     */
    public function buildOrderGrowthValue(string $orderSn)
    {
        $orderInfo = Order::where(['order_sn' => $orderSn, 'pay_status' => [2], 'after_status' => [1], 'order_type' => [1, 3]])->field('order_sn,order_type,uid,user_phone,fare_price,real_pay_price,pay_type,link_superior_user')->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            return false;
        }
        $existGrowthValue = GrowthValueDetail::where(['order_sn' => $orderSn])->count();
        if (!empty($existGrowthValue)) {
            return false;
        }
        $userInfo = User::with(['topLink'])->where(['uid' => $orderInfo['uid']])->field('uid,phone,vip_level,team_vip_level,link_superior_user,growth_value')->findOrEmpty()->toArray();

        if (empty($orderInfo)) {
            throw new GrowthValueException(['errorCode' => 2300101]);
        }
        if ($orderInfo['order_type'] == 3) {
            $activityId = Activity::where(['a_type' => 2])->value('id');
            if (!empty($activityId)) {
                $orderGoodsGrowthValue = Db::name('order_goods')->alias('a')
                    ->join('sp_activity_goods_sku b', 'a.sku_sn = b.sku_sn and b.status = 1 and b.activity_id = ' . $activityId)
                    ->where(['a.order_sn' => $orderSn, 'a.pay_status' => [2], 'a.after_status' => [1]])
                    ->field('b.growth_value,a.count')->findOrEmpty();
            }
        } elseif ($orderInfo['order_type'] == 2) {
            $ptSn = PtOrder::where(['order_sn' => $orderSn])->field('activity_code,activity_sn')->findOrEmpty()->toArray();
            if (!empty($ptSn)) {
                $activityCode = $ptSn['activity_code'];
                $activitySn = $ptSn['activity_sn'];
                //拼团活动信息
                $ptInfo = PtActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();
                if ($ptInfo['type'] == 3) {
                    //全部参团已支付人的数量
                    $allPt = PtOrder::where(['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'pay_status' => 2, 'status' => 1])->count();
                    if ($allPt == intval($ptInfo['group_number']) && $ptInfo['type'] == 3) {
                        $orderGoodsGrowthValue = Db::name('order_goods')->alias('a')
                            ->join('sp_pt_goods_sku b', 'a.sku_sn = b.sku_sn and b.status = 1 and b.activity_code = ' . $activityCode)
                            ->where(['a.order_sn' => $orderSn, 'a.pay_status' => [2], 'a.after_status' => [1]])
                            ->field('b.growth_value,a.count')->findOrEmpty();
                    }
                }

            }

        } elseif ($orderInfo['order_type'] == 4) {
            $orderGoodsGrowthValue['growth_value'] = 0;
            $orderGoodsGrowthValue['count'] = 1;
        }

        $orderTopUserGrowth = null;
        //成长值按商品实付金额(扣除运费)算
        $orderPrice = intval($orderInfo['real_pay_price'] - ($orderInfo['fare_price'] ?? 0));
        $DBRes = false;
        if ($orderPrice >= 1) {
            $memberVdc = MemberVdc::where(['status' => 1])->field('level,growth_scale,share_growth_scale,growth_value')->select()->toArray();
            $vdc[0]['level'] = 0;
            $vdc[0]['growth_scale'] = $this->normalUserGrowthValueScale;
            $vdc[0]['share_growth_scale'] = $this->normalUserShareGrowthValueScale;
            $vdc[0]['growth_value'] = 0;
            foreach ($memberVdc as $key => $value) {
                $vdc[$value['level']] = $value;
            }
            //如果有指定的成长值则使用指定的,如果没有则按照订单金额来计算
            if (!empty($orderGoodsGrowthValue['growth_value'])) {
                $orderUserGrowth = $orderGoodsGrowthValue['growth_value'] * ($orderGoodsGrowthValue['count'] ?? 1);

                //本人和上级都同享指定成长值
                if (!empty($userInfo['topLink'])) {
                    $orderTopUserGrowth = $orderUserGrowth;
                }

//                //本人才可以享受指定成长值,上级按照订单金额计算成长值后乘以分享倍数
//                if (!empty($userInfo['topLink'])) {
//                    $orderTopUserGrowth = priceFormat($orderPrice * ($vdc[$userInfo['vip_level']]['growth_scale'] * $vdc[$userInfo['topLink']['vip_level']]['share_growth_scale']));
//                }
            } else {

                $orderUserGrowth = priceFormat($orderPrice * $vdc[$userInfo['vip_level']]['growth_scale']);

                if (!empty($userInfo['topLink'])) {
                    $orderTopUserGrowth = priceFormat($orderPrice * ($vdc[$userInfo['vip_level']]['growth_scale'] * $vdc[$userInfo['topLink']['vip_level']]['share_growth_scale']));
                }

            }

            $detail = [];
            if (!empty($orderUserGrowth)) {
                //订单用户的成长值记录
                $detail[0]['order_sn'] = $orderSn;
                $detail[0]['order_uid'] = $orderInfo['uid'];
                $detail[0]['order_user_phone'] = $orderInfo['user_phone'];
                $detail[0]['order_real_pay_price'] = $orderInfo['real_pay_price'];
                $detail[0]['type'] = 1;
                $detail[0]['uid'] = $userInfo['uid'];
                $detail[0]['user_phone'] = $userInfo['phone'];
                $detail[0]['growth_value'] = $orderUserGrowth;
                $detail[0]['surplus_growth_value'] = $detail[0]['growth_value'];
                $detail[0]['arrival_status'] = 1;
                $detail[0]['user_level'] = $userInfo['vip_level'];
                $detail[0]['growth_scale'] = $vdc[$userInfo['vip_level']]['growth_scale'];
                $detail[0]['share_growth_scale'] = 0;
            }

            if (!empty($orderTopUserGrowth) && !empty($detail[0])) {
                //订单用户关联上级的成长值记录,没有订单原始用户的成长值记录也不记录关联上级的成长值记录
                $detail[1]['order_sn'] = $orderSn;
                $detail[1]['order_uid'] = $orderInfo['uid'];
                $detail[1]['order_user_phone'] = $orderInfo['user_phone'];
                $detail[1]['order_real_pay_price'] = $orderInfo['real_pay_price'];
                $detail[1]['type'] = 2;
                $detail[1]['uid'] = $userInfo['topLink']['uid'];
                $detail[1]['user_phone'] = $userInfo['topLink']['phone'];
                $detail[1]['growth_value'] = $orderTopUserGrowth;
                $detail[1]['surplus_growth_value'] = $detail[1]['growth_value'];
                $detail[1]['arrival_status'] = 1;
                $detail[1]['user_level'] = $userInfo['vip_level'];
                $detail[1]['growth_scale'] = $detail[0]['growth_scale'] ?? 0;
                $detail[1]['share_growth_scale'] = doubleval($vdc[$userInfo['topLink']['vip_level']]['share_growth_scale']);
            }

            if (!empty($detail)) {
                $DBRes = Db::transaction(function () use ($detail) {
                    //记录详情
                    $DBRes = (new GrowthValueDetail())->saveAll($detail);
                    //添加用户成长值
                    $memberModel = new Member();
                    $userModel = new User();
                    foreach ($detail as $key => $value) {
                        $mRes = $memberModel->where(['uid' => $value['uid'], 'status' => [1, 2]])->inc('growth_value', $value['growth_value'])->update();
                        $uRes = $userModel->where(['uid' => $value['uid'], 'status' => [1, 2]])->inc('growth_value', $value['growth_value'])->update();
                    }
                    return $uRes;
                });

                //普通用户升级为VIP
                if ($userInfo['vip_level'] == 0) {
                    $memberVdc = MemberVdc::where(['level' => 3, 'status' => 1])->value('growth_value');
                    $userGrowthValue = User::where(['uid' => $userInfo['uid']])->value('growth_value');
                    if ((string)$userGrowthValue >= (string)$memberVdc) {
                        $orderInfo['growth_value'] = $detail[0]['growth_value'] ?? 0;
                        (new MemberService())->becomeMember($orderInfo);
                    }
                }

                //上级用户 普通用户升级为VIP
                if (!empty($detail[1]) && !empty($userInfo['topLink'] ?? []) && $userInfo['topLink']['vip_level'] == 0) {
                    $memberVdc = MemberVdc::where(['level' => 3, 'status' => 1])->value('growth_value');
                    $userGrowthValue = User::where(['uid' => $userInfo['topLink']['uid']])->value('growth_value');
                    if ((string)$userGrowthValue >= (string)$memberVdc) {
                        $topUserOrderInfo['uid'] = $userInfo['topLink']['uid'];
                        $topUserOrderInfo['growth_value'] = $detail[1]['growth_value'] ?? 0;
                        (new MemberService())->becomeMember($orderInfo);
                    }
                }
            }

        }

        return judge($DBRes);
    }

    /**
     * @title  新增转售订单成长值
     * @param string $orderSn
     * @return mixed
     * @throws \Exception
     */
    public function buildResaleOrderGrowthValue(string $orderSn)
    {
        $orderInfo = Order::where(['order_sn' => $orderSn, 'pay_status' => [2], 'after_status' => [1]])->field('order_sn,order_type,uid,user_phone,fare_price,real_pay_price,pay_type,link_superior_user')->findOrEmpty()->toArray();
        if (empty($orderInfo) || $orderInfo['order_type'] != 5) {
            return false;
        }
        $existGrowthValue = GrowthValueDetail::where(['order_sn' => $orderSn])->count();
        if (!empty($existGrowthValue)) {
            return false;
        }
        $userInfo = User::where(['uid' => $orderInfo['uid']])->field('uid,phone,vip_level,link_superior_user,growth_value')->findOrEmpty()->toArray();

        if (empty($userInfo)) {
            throw new GrowthValueException(['errorCode' => 2300101]);
        }
        if ($orderInfo['order_type'] == 5) {
            $activityId = Activity::where(['a_type' => 2])->value('id');
            if (!empty($activityId)) {
                $orderGoodsGrowthValue = Db::name('order_goods')->alias('a')
                    ->join('sp_activity_goods_sku b', 'a.sku_sn = b.sku_sn and b.status = 1 and b.activity_id = ' . $activityId)
                    ->where(['a.order_sn' => $orderSn, 'a.pay_status' => [2], 'a.after_status' => [1]])
                    ->field('b.growth_value,a.count')->findOrEmpty();
            }
        }
        if (empty($orderGoodsGrowthValue)) {
            $orderGoodsGrowthValue['growth_value'] = 125;
            $orderGoodsGrowthValue['count'] = 1;
        }
        $orderTopUserGrowth = null;
        //成长值按商品实付金额(扣除运费)算
        $orderPrice = intval($orderInfo['real_pay_price'] - ($orderInfo['fare_price'] ?? 0));
        $DBRes = false;
        if ($orderPrice >= 1) {
            $memberVdc = MemberVdc::where(['status' => 1])->field('level,growth_scale,share_growth_scale,growth_value')->select()->toArray();
            $vdc[0]['level'] = 0;
            $vdc[0]['growth_scale'] = $this->normalUserGrowthValueScale;
            $vdc[0]['share_growth_scale'] = $this->normalUserShareGrowthValueScale;
            $vdc[0]['growth_value'] = 0;
            foreach ($memberVdc as $key => $value) {
                $vdc[$value['level']] = $value;
            }
            //如果有指定的成长值则使用指定的,如果没有则按照订单金额来计算
            if (!empty($orderGoodsGrowthValue['growth_value'])) {
                $orderUserGrowth = $orderGoodsGrowthValue['growth_value'] * ($orderGoodsGrowthValue['count'] ?? 1);
            }

            $detail = [];
            if (!empty($orderUserGrowth)) {
                //订单用户的成长值记录
                $detail[0]['order_sn'] = $orderSn;
                $detail[0]['order_uid'] = $orderInfo['uid'];
                $detail[0]['order_user_phone'] = $orderInfo['user_phone'];
                $detail[0]['order_real_pay_price'] = $orderInfo['real_pay_price'];
                $detail[0]['type'] = 1;
                $detail[0]['uid'] = $userInfo['uid'];
                $detail[0]['user_phone'] = $userInfo['phone'];
                $detail[0]['growth_value'] = $orderUserGrowth;
                $detail[0]['surplus_growth_value'] = $detail[0]['growth_value'];
                $detail[0]['arrival_status'] = 1;
                $detail[0]['user_level'] = $userInfo['vip_level'];
                $detail[0]['growth_scale'] = $vdc[$userInfo['vip_level']]['growth_scale'];
                $detail[0]['share_growth_scale'] = 0;
            }

            if (!empty($detail)) {
                $DBRes = Db::transaction(function () use ($detail) {
                    //记录详情
                    $DBRes = (new GrowthValueDetail())->saveAll($detail);
                    //添加用户成长值
                    $memberModel = new Member();
                    $userModel = new User();
                    foreach ($detail as $key => $value) {
                        $mRes = $memberModel->where(['uid' => $value['uid'], 'status' => [1, 2]])->inc('growth_value', $value['growth_value'])->update();
                        $uRes = $userModel->where(['uid' => $value['uid'], 'status' => [1, 2]])->inc('growth_value', $value['growth_value'])->update();
                    }
                    return $uRes;
                });

                //普通用户升级为VIP
                if ($userInfo['vip_level'] == 0) {
                    $memberVdc = MemberVdc::where(['level' => 3, 'status' => 1])->value('growth_value');
                    $userGrowthValue = User::where(['uid' => $userInfo['uid']])->value('growth_value');
                    if ((string)$userGrowthValue >= (string)$memberVdc) {
                        $orderInfo['growth_value'] = $detail[0]['growth_value'] ?? 0;
                        (new MemberService())->becomeMember($orderInfo);
                    }
                }
            }
        }

        return judge($DBRes);
    }

    /**
     * @title  发放成长值
     * @param int $type
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function grantGrowthValue(int $type = 1, array $data = [])
    {
        //type=1为后台修改成长值
        switch ($type) {
            case 1:
                if ($data['growth_value'] <= 0) {
                    throw new GrowthValueException(['msg' => '成长值必须为正数']);
                }
                //订单用户的成长值记录
                $detail['type'] = 3;
                $detail['uid'] = $data['uid'];
                $detail['user_phone'] = $data['phone'];
                $detail['growth_value'] = $data['growth_value'];
                $detail['surplus_growth_value'] = $data['growth_value'];
                $detail['remark'] = $data['remark'] ?? '系统发放';
                $detail['arrival_status'] = 1;
                $userInfo = User::with(['topLink'])->where(['uid' => $data['uid']])->field('uid,phone,vip_level,link_superior_user,growth_value')->findOrEmpty()->toArray();

                $DBRes = Db::transaction(function () use ($detail, $data, $userInfo) {
                    $dRes = (new GrowthValueDetail())->save($detail);
                    if (!empty($userInfo['vip_level'])) {
                        $mRes = Member::where(['uid' => $data['uid'], 'status' => [1, 2]])->inc('growth_value', $data['growth_value'])->update();
                    }
                    $uRes = User::where(['uid' => $data['uid'], 'status' => [1, 2]])->inc('growth_value', $data['growth_value'])->update();
                    return $uRes;
                });

                //普通用户升级为VIP
                if ($userInfo['vip_level'] == 0) {
                    $memberVdc = MemberVdc::where(['level' => 3, 'status' => 1])->value('growth_value');
                    $userGrowthValue = User::where(['uid' => $userInfo['uid']])->value('growth_value');
                    if ((string)$userGrowthValue >= (string)$memberVdc) {
                        (new MemberService())->becomeMember(['uid' => $userInfo['uid'], 'link_superior_user' => $userInfo['link_superior_user'], 'user_phone' => $userInfo['phone']]);
//                        $mRes = Member::where(['uid' => $data['uid'], 'status' => [1, 2]])->inc('growth_value', $data['growth_value'])->update();
                        //再判断是否可以升级
                        $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $data['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                    }
                } else {
                    //立马判断是否可以升级
                    $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $data['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                }

                break;
            default:
                throw new GrowthValueException(['msg' => '未知的发放类型']);
        }
        return judge($DBRes);
    }

    /**
     * @title  取消成长值
     * @param int $type
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function cancelGrowthValue(int $type = 2, array $data = [])
    {
        $log['data'] = $data;
        $log['type'] = $type;
        $log['typeName'] = $type == 1 ? '后台修改成长值' : '取消订单成长值';
        //type=1为后台修改成长值 2为取消订单成长值
        switch ($type) {
            case 1:
                if (doubleval($data['growth_value']) <= 0) {
                    throw new GrowthValueException(['msg' => '成长值必须为正数']);
                }
                //订单用户的成长值记录
                $detail['type'] = 3;
                $detail['uid'] = $data['uid'];
                $detail['user_phone'] = $data['phone'];
                $detail['growth_value'] = '-' . doubleval($data['growth_value']);
                $detail['surplus_growth_value'] = 0;
                $detail['remark'] = $data['remark'];
                $detail['arrival_status'] = 1;
                $log['detail'] = $detail;

                //用户信息
                $userInfo = User::where(['uid' => $data['uid']])->field('uid,phone as user_phone,vip_level as level,growth_value')->findOrEmpty()->toArray();

                $DBRes = Db::transaction(function () use ($detail, $data, $userInfo) {
                    if (intval($userInfo['growth_value']) <= 0 || ((string)$userInfo['growth_value'] < (string)$data['growth_value'])) {
                        throw new GrowthValueException(['errorCode' => 2400105]);
                    }

                    $dRes = (new GrowthValueDetail())->save($detail);
                    $mRes = Member::where(['uid' => $data['uid'], 'status' => [1, 2]])->dec('growth_value', $data['growth_value'])->update();
                    $uRes = User::where(['uid' => $data['uid'], 'status' => [1, 2]])->dec('growth_value', $data['growth_value'])->update();

                    //用户信息
                    $userInfo = User::with(['memberDemotionGrowthValue'])->where(['uid' => $data['uid']])->field('uid,phone as user_phone,vip_level as level,growth_value')->findOrEmpty()->toArray();
                    //用户会员信息
                    $memberInfo = Member::where(['uid' => $data['uid'], 'status' => 1])->field('uid,level,growth_value,demotion_growth_value,ppyl_channel,ppyl_max_level')->findOrEmpty()->toArray();
                    if (!empty($memberInfo)) {
                        $userInfo['ppyl_channel'] = $memberInfo['ppyl_channel'];
                        $userInfo['ppyl_max_level'] = $memberInfo['ppyl_max_level'];
                    }

                    //判断用户等级是否需要降级
                    $userNowLevel = $this->checkMemberGrowthValueLevel($userInfo, $userInfo['level']);

                    if ($userNowLevel != $userInfo['level']) {
                        if (!empty($userNowLevel)) {
                            $demotionGrowthValue = MemberVdc::where(['level' => $userNowLevel, 'status' => 1])->value('demotion_growth_value');
                        } else {
                            $demotionGrowthValue = 0;
                        }
                        $this->recordLog(['msg' => '用户成长值不足,需要降级 ' . '现在等级为 ' . $userInfo['level'] . '修改为' . $userNowLevel], 'error');
                        User::where(['uid' => $userInfo['uid']])->save(['vip_level' => $userNowLevel]);
                        Member::where(['uid' => $userInfo['uid']])->save(['level' => $userNowLevel, 'demotion_growth_value' => $demotionGrowthValue]);

                        //修改分润第一人冗余结构
                        //先修改自己的,然后修改自己整个团队下级的
                        $dRes = (new MemberService())->refreshDivideChain(['uid' => $userInfo['uid']]);
                        //团队的用队列形式,避免等待时间太长
                        $dtRes = Queue::later(10, 'app\lib\job\MemberChain', ['searUser' => $userInfo['uid'], 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');

                    }
                    return $uRes;
                });
                break;
            case 2:
                $orderInfo = Order::where(['order_sn' => $data['order_sn']])->findOrEmpty()->toArray();
                $log['orderInfo'] = $orderInfo;
                if (empty($orderInfo)) {
                    $this->recordLog($log, 'error');
                    throw new GrowthValueException(['errorCode' => 2300102]);
                }

                if (intval($orderInfo['real_pay_price'] - ($orderInfo['fare_price'] ?? 0)) >= 1) {
                    $allDetail = GrowthValueDetail::where(['order_sn' => $data['order_sn']])->field('id,order_sn,uid,user_phone,type,growth_value,surplus_growth_value,growth_scale,share_growth_scale,arrival_status')->select()->each(function ($item) {
                        //默认要取消该订单全部成长值
                        $item['will_dec_growth_value'] = $item['growth_value'];
                        return $item;
                    })->toArray();

                    $log['firstDetail'] = $allDetail;
                    if (empty($allDetail)) {
                        $log['msg'] = '该订单暂无发放成长值记录';
                        $this->recordLog($log, 'error');
                        return false;
                    }

                    foreach ($allDetail as $key => $value) {
                        if ($value['surplus_growth_value'] <= 0) {
                            unset($allDetail[$key]);
                            continue;
                        }
                    }

                    $log['secondDetail'] = $allDetail;
                    if (empty($allDetail)) {
                        $log['msg'] = '该订单所有商品全部可扣成长值已经扣完, 无需重复取消';
                        $this->recordLog($log, 'error');
                        return false;
                    }

                    //cancel_type表示取消的等级 1为全部取消 2为部分取消
                    if ($data['cancel_type'] == 2) {
                        //cancel_type=2时只转化对应的成长值然后减去
                        foreach ($allDetail as $key => $value) {
                            if ($value['type'] == 1) {
                                $allDetail[$key]['will_dec_growth_value'] = priceFormat($data['price_part'] * $value['growth_scale']);
                            } elseif ($value['type'] == 2) {
                                $allDetail[$key]['will_dec_growth_value'] = priceFormat($data['price_part'] * ($value['growth_scale'] * $value['share_growth_scale']));
                            }
                        }
                    }

                    $notSurplus = false;
                    //如果即将要扣的成长值大于剩余可扣成长值,则强制修改为扣除可扣成长值
                    foreach ($allDetail as $key => $value) {
                        if ((string)$value['will_dec_growth_value'] >= (string)$value['surplus_growth_value']) {
                            $notSurplus = true;
                            if ($value['surplus_growth_value'] > 0) {
                                $allDetail[$key]['will_dec_growth_value'] = $value['surplus_growth_value'];
                            } else {
                                $allDetail[$key]['will_dec_growth_value'] = 0;
                            }
                        }
                    }
                    $memberVdc = MemberVdc::where(['status' => 1])->column('demotion_growth_value', 'level');
                    $log['detail'] = $allDetail;
                    $log['msg'] = '取消成长值成功';
                    $DBRes = Db::transaction(function () use ($allDetail, $data, $notSurplus, $memberVdc) {
                        $uRes = false;
                        $mRes = false;
                        $GrowthValueDetailModel = (new GrowthValueDetail());
                        if ($data['cancel_type'] == 1) {
                            $dRes = $GrowthValueDetailModel->where(['order_sn' => $data['order_sn']])->save(['arrival_status' => 3, 'status' => -1, 'surplus_growth_value' => 0]);
                        }
                        $memberModel = new Member();
                        $userModel = new User();
                        foreach ($allDetail as $key => $value) {
                            if (!empty(doubleval($value['will_dec_growth_value']))) {
                                $mRes = $memberModel->where(['uid' => $value['uid'], 'status' => [1, 2]])->dec('growth_value', $value['will_dec_growth_value'])->update();
                                $uRes = $userModel->where(['uid' => $value['uid'], 'status' => [1, 2]])->dec('growth_value', $value['will_dec_growth_value'])->update();
                            }
                            if ($data['cancel_type'] == 2) {
                                //扣去剩余可扣成长值
                                if (!empty($notSurplus)) {
                                    $gRes = $GrowthValueDetailModel->where(['order_sn' => $data['order_sn'], 'id' => $value['id']])->save(['arrival_status' => 3, 'status' => -1, 'surplus_growth_value' => 0]);
                                } else {
                                    if (!empty(doubleval($value['will_dec_growth_value']))) {
                                        $gRes = $GrowthValueDetailModel->where(['order_sn' => $value['order_sn'], 'id' => $value['id']])->dec('surplus_growth_value', $value['will_dec_growth_value'])->update();
                                    }
                                }
                            }
                        }

                        $allUid = array_column($allDetail, 'uid');
                        //判断用户等级是否需要降级
                        $allUser = Member::where(['uid' => $allUid, 'status' => 1])->field('uid,user_phone,level,growth_value,create_time,demotion_growth_value,ppyl_channel,ppyl_max_level')->select()->toArray();
                        $memberService = (new MemberService());
                        foreach ($allUser as $key => $value) {
                            $userNowLevel = $this->checkMemberGrowthValueLevel($value, intval($value['level']));
                            if (!empty($userNowLevel)) {
                                $demotionGrowthValue = $memberVdc[$userNowLevel] ?? 0;
                            } else {
                                $demotionGrowthValue = 0;
                            }

                            if ($userNowLevel != $value['level']) {
                                $this->recordLog(['msg' => $value['uid'] . '用户成长值不足,需要降级 ' . '现在等级为 ' . $value['level'] . '修改为' . $userNowLevel], 'error');
                                User::where(['uid' => $value['uid']])->save(['vip_level' => $userNowLevel]);
                                Member::where(['uid' => $value['uid']])->save(['level' => $userNowLevel, 'demotion_growth_value' => $demotionGrowthValue]);

                                //修改分润第一人冗余结构
                                //先修改自己的,然后修改自己整个团队下级的
                                $dRes = $memberService->refreshDivideChain(['uid' => $value['uid']]);
                                //团队的用队列形式,避免等待时间太长
                                $dtRes = Queue::later(10, 'app\lib\job\MemberChain', ['searUser' => $value['uid'], 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');
                            }
                        }

                        return $uRes;
                    });
                } else {
                    $log['msg'] = '订单实付金额不足1元,没有计入成长值, 故无需取消';
                    $DBRes = true;
                }
                break;
            default:
                throw new GrowthValueException(['msg' => '未知的发放类型']);
        }
        $this->recordLog($log, 'info');
        return judge($DBRes);
    }

    /**
     * @title  返回用户当前成长值对应的正确的等级
     * @param array $userInfo
     * @param int $checkLevel
     * @return int
     */
    public function checkMemberGrowthValueLevel(array $userInfo, int $checkLevel)
    {
        $memberVdc = MemberVdc::where(['level' => $checkLevel, 'status' => 1])->field('level,growth_value,demotion_type,demotion_growth_value')->findOrEmpty()->toArray();
        if (empty($memberVdc) || ($userInfo['growth_value'] <= 0)) {
            if ((!empty($userInfo['ppyl_channel'] ?? null) && $userInfo['ppyl_channel'] == 1) && (!empty($userInfo['ppyl_max_level'] ?? 0) && $userInfo['ppyl_max_level'] > 0)) {
                return $userInfo['ppyl_max_level'];
            }
            return 0;
        }
        //根据不同的降级策略判断决定不同的判断标准是否要降级,如果找不到则默认用该等级的升级成长值
        switch ($memberVdc['demotion_type']) {
            case 1:
                $judgeGrowthValue = $memberVdc['demotion_growth_value'] ?? $memberVdc['growth_value'];
                break;
            case 2:
                //只有在跟用户等级相同的情况才采用用户的历史降级等级, 如果不是的话用回当前判断的会员降级等级
                if ($checkLevel == $userInfo['level']) {
                    $judgeGrowthValue = $userInfo['demotion_growth_value'] ?? $memberVdc['growth_value'];
                } else {
                    $judgeGrowthValue = $memberVdc['demotion_growth_value'] ?? $memberVdc['growth_value'];
                }

                break;
        }
        if ($userInfo['growth_value'] < ($judgeGrowthValue ?? 0)) {
            $res = $this->checkMemberGrowthValueLevel($userInfo, $checkLevel + 1);
        } else {
            $res = $checkLevel;
            if ((!empty($userInfo['ppyl_channel'] ?? null) && $userInfo['ppyl_channel'] == 1) && (!empty($userInfo['ppyl_max_level'] ?? 0) && $userInfo['ppyl_max_level'] < $res)) {
                $res = $userInfo['ppyl_max_level'];
            }
        }
        return $res;
    }

    /**
     * @title  记录日志
     * @param array $data
     * @param string $level
     * @return void
     */
    public function recordLog(array $data, string $level = 'error')
    {
        $log['msg'] = $data['msg'] ?? '成长值系统有误';
        $log['data'] = $data;
        $res = (new Log())->setChannel('growth')->record($log, $level);
    }
}