<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 售后模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\exceptions\AfterSaleException;
use app\lib\models\AfterSaleDetail;
use app\lib\models\MemberVdc;
use app\lib\models\OrderGoods;
use app\lib\models\User;
use app\lib\models\Member;
use app\lib\models\Order;
use app\lib\models\AfterSale as AfterSaleModel;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Queue;

class AfterSale
{
    /**
     * @title  自动确认售后
     * @param array $data
     * @return bool|mixed
     */
    public function autoVerify(array $data = [])
    {
        $log['data'] = $data;
        $orderSn = $data['order_sn'] ?? null;

        if (empty($orderSn)) {
            return $this->recordError(['msg' => '订单有误', 'data' => $log]);
        }
        $log['data']['msg'] = '接受到订单' . $data['order_sn'] . '的自动售后请求';
        $afSn = $data['after_sale_sn'];

        //防止并发和重复申请,设置简易缓存锁
        $cacheKey = $orderSn . '-autoAfterSale';
        $cacheNumKey = $cacheKey . 'Number';
        $cacheExpire = 5;
        $cacheValue = $orderSn . '-' . $afSn;
        $nowCache = cache($cacheKey);
        $nowCacheNum = cache($cacheNumKey) ?? 0;
        if (!empty($nowCache)) {
            if ($orderSn . '-' . $afSn == $nowCache) {
                $log['cacheData'] = $nowCache;
                return $this->recordError(['msg' => '该退售后订单正在自动处理中,请勿重复提交', 'data' => $log]);
            }
            if (!empty($nowCacheNum)) {
                $queueNumber = 1;
            } else {
                $queueNumber = $nowCacheNum;
            }
            $waitTimeSecond = 5;
            $waitTime = intval($queueNumber * $waitTimeSecond);
            $log['data']['errorMsg'] = '该订单 ' . $orderSn . ' 有正在处理的自动退售后, 编号为' . $nowCache . ' ,当前的自动退售后订单先推入等待队列,' . $waitTime . '秒后稍后重新执行';
            $log['data']['cacheData'] = $nowCache;

            //推入等待队列
            $queueData = $data;
            $queueData['autoType'] = 1;
            $queue = Queue::later($waitTime, 'app\lib\job\Auto', $queueData, config('system.queueAbbr') . 'Auto');
            if (!empty($nowCacheNum)) {
                Cache::inc($nowCacheNum);
            }
            return $this->recordError(['msg' => $log['data']['errorMsg'] ?? '自动售后正在处理中...', 'data' => $log]);
        } else {
            Cache::set($cacheKey, $cacheValue, $cacheExpire);
            Cache::set($cacheNumKey, ($nowCacheNum + 1), $cacheExpire);
        }


        $userInfo = User::where(['uid' => $data['uid']])->field('uid,vip_level,phone')->findOrEmpty()->toArray();

        $log['data']['user'] = $userInfo ?? [];

        if (empty($userInfo) || empty($userInfo['vip_level'])) {
            return $this->recordError(['msg' => '用户暂无急速退款权益', 'data' => $log]);
        }

        $userLevel = $userInfo['vip_level'];
        $memberVdc = MemberVdc::where(['level' => $userLevel, 'status' => 1])->field('level,fast_refund_before_ship,fast_refund_after_ship')->findOrEmpty()->toArray();
        $log['data']['memberVdc'] = $memberVdc ?? [];

        //售后类型 1为仅退款 2为退货退款 3为换货
        $afterType = $data['after_type'] ?? 1;

        switch ($afterType) {
            case 1:
                $canFastRefund = $memberVdc['fast_refund_before_ship'];
                $orderStatus = [2, 3, 5];
                $shippingStatus = 1;
                $fastRefundName = '发货前极速退款的权益。';
                break;
            case 2:
            case 3:
                $canFastRefund = $memberVdc['fast_refund_after_ship'];
                $orderStatus = [2, 5, 6];
                $shippingStatus = 3;
                $fastRefundName = '会员信用极佳，发货后极速退款的权益。';

                //暂时先不做发货后的极速退款
                return $this->recordError(['msg' => '暂不支持的极速退款类型', 'data' => $log]);
                break;
            default:
                return $this->recordError(['msg' => '未知的售后类型', 'data' => $log]);
        }

        if ($canFastRefund == 2) {
            return $this->recordError(['msg' => '该会员等级 ' . $memberVdc['level'] . ' ,暂无急速退款权益', 'data' => $log]);
        }
        //查看是否订单商品全部都处理售后申请中,是则添加一个售后中的的状态,因为是全部售后了
        //查看售后历史中的有多少个商品
        $aMap[] = ['order_sn', '=', $orderSn];
        $aMap[] = ['after_status', '<>', -1];
        $afterSaleHisGoodsNumber = AfterSaleModel::where($aMap)->count();
        $log['data']['afterSaleHisGoodsNumber'] = $afterSaleHisGoodsNumber ?? 0;

        //获取全部订单商品总数
        $orderGoods = OrderGoods::where(['order_sn' => $orderSn, 'pay_status' => 2])->count();
        if ($afterSaleHisGoodsNumber >= $orderGoods) {
            array_push($orderStatus, 6);
            array_unique(array_filter($orderStatus));
        }
        $log['data']['orderGoods'] = $orderGoods ?? 0;

        //判断该订单是否存在待备货中的状态,如果有则继续往下找该售后产品是否存在待备货中的状态
        //允许已发货的订单也进入自动售后,因为可能是部分发货 1-20 18:46

        $map[] = ['order_sn', '=', $orderSn];
        $map[] = ['pay_status', '=', 2];
        $map[] = ['order_status', 'in', $orderStatus];
//        $map[] = ['shipping_status', '=', $shippingStatus];
        $log['data']['orderMap'] = $map ?? [];

        $orderInfo = Order::with(['goods'])->where($map)->findOrEmpty()->toArray();

        if (empty($orderInfo)) {
            return $this->recordError(['msg' => '暂符合备货状态的订单', 'data' => $log]);
        }
        $log['data']['order'] = $orderInfo ?? [];

        $goodsMap[] = ['order_sn', '=', $orderSn];
        $goodsMap[] = ['pay_status', '=', 2];
        $goodsMap[] = ['status', 'in', [1]];
        $goodsMap[] = ['after_status', 'in', [1, 2, -1]];
        $goodsMap[] = ['shipping_status', '=', $shippingStatus];
        $goodsMap[] = ['goods_sn', '=', $data['goods_sn']];
        $goodsMap[] = ['sku_sn', '=', $data['sku_sn']];
        $log['data']['goodsMap'] = $goodsMap;

        $orderGoods = OrderGoods::where($goodsMap)->findOrEmpty()->toArray();
        if (empty($orderGoods)) {
            return $this->recordError(['msg' => '暂无待发货且符合备货状态的订单商品', 'data' => $log]);
        }

        $log['data']['orderGoods'] = $orderGoods ?? [];

        //新增一个售后说明,表明为系统自动处理退款
        $detail['after_sale_sn'] = $data['after_sale_sn'];
        $detail['order_sn'] = $orderSn;
        $detail['uid'] = $data['uid'];
        $detail['type'] = $afterType;
        $detail['after_status'] = 2;
        $detail['operate_user'] = '系统';
        $detail['content'] = '该用户享有' . $fastRefundName . ' 系统将自动为其处理退款。(请勿人工操作，稍后系统将自动完成)';
        $detail['close_time'] = time();
        $detailRes = (new AfterSaleDetail())->create($detail);

        //进入售后环节
        $after['after_sale_sn'] = $data['after_sale_sn'];
        $after['verify_status'] = 2;
        $after['notThrowError'] = true;
        $res = (new AfterSaleModel())->verify($after);

        $detailFailRes = [];
        //进入退款环节
        if (!empty($res)) {
            $refundAfter['after_sale_sn'] = $data['after_sale_sn'];
            $refundAfter['notThrowError'] = true;
            $refundRes = (new AfterSaleModel())->sellerConfirmWithdrawPrice($refundAfter);
        }

        $delRes = true;
        //审核失败了或者退款失败要提示处理失败,并删除之前添加的售后时间轴明细
        if (empty($res) || empty($refundRes)) {
            if (empty($res)) {
                if (!empty($detailRes->getData('id'))) {
                    AfterSaleDetail::update(['status' => -1], ['id' => $detailRes->getData('id')]);
                }
            }
            if (empty($res)) {
                $reason = '审核';
            } elseif (empty($refundRes)) {
                $reason = '退款';
            } else {
                $reason = '处理';
            }
            //新增一个自动售后失败说明,表明需要人工处理
            $detailFail['after_sale_sn'] = $data['after_sale_sn'];
            $detailFail['order_sn'] = $orderSn;
            $detailFail['uid'] = $data['uid'];
            $detailFail['type'] = $afterType;
            $detailFail['after_status'] = 2;
            $detailFail['operate_user'] = '系统';
            $detailFail['content'] = '自动退售后' . $reason . '失败, 需要人工审核后, 由人工操作';
            $detailFail['close_time'] = time();
            $detailFailRes = (new AfterSaleDetail())->create($detailFail)->getData();

            $delRes = false;
        }

        //记录成功日志
        $delMsgString = !empty($delRes) ? '成功' : ' [失败!] ';
        $logLevel = !empty($delRes) ? 'info' : 'error';
        $log['delMsg'] = ['已' . ($delMsgString ?? '成功') . '处理订单' . $data['order_sn'] . '的自动售后处理'];
        $log['afterDetailRes'] = $detailRes->getData() ?? [];
        $log['afterRes'] = $res ?? [];
        $log['refundRes'] = $refundRes ?? [];
        $log['afterDetailFailRes'] = $detailFailRes ?? [];
        $this->log($log, ($logLevel ?? 'info'));

        //清除缓存锁
        cache($cacheKey, null);
        Cache::dec($cacheNumKey);

        return $res ?? false;
    }

    /**
     * @title  记录错误日志
     * @param array $data
     * @return bool
     */
    public function recordError(array $data)
    {
        $log['msg'] = $data['msg'] ?? '售后处理失败';
        $log['data'] = $data['data'] ?? [];
        $this->log($data, $data['level'] ?? 'error');
        return false;

    }

    /**
     * @title  记录日志
     * @param array $data
     * @param string $level
     * @return mixed
     */
    public function log(array $data, string $level = 'error')
    {
        return (new Log())->setChannel('autoAfterSale')->record($data, $level);
    }
}