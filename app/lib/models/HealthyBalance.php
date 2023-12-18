<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 健康豆模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\services\CodeBuilder;
use app\lib\constant\PayConstant;
use app\lib\services\Log;
use app\lib\exceptions\OpenException;
use think\facade\Cache;
use think\facade\Db;

class HealthyBalance extends BaseModel
{
    /**
     * @description: 健康转出
     * @param {*} $data
     * @return {*}
     */
    public function conver($data)
    {
        //判断扣除渠道（1：商城；2：福利；3：消费型股东；999.混合（默认））
        if (!in_array($data['type'], PayConstant::HEALTHY_CHANNEL_TYPE) && $data['type'] != 999) {
            throw new OpenException(['msg' => "健康豆转换渠道异常"]);
        }
        $map = [
            'uid' => $data['uid'],
            'status' => 1
        ];
        //记录日记
        // (new Log())->setChannel('healthyBalanceConver')->record($data);
        //依次扣除健康豆
        $deductData = [];
        $deductNum = $data['user_conver_number'];
        $order_sn = $data['so_sn'];
        //防止数据库抖动
        if (!empty(cache('healthy_conver_to_' . $order_sn))) {
            throw new OpenException(['msg' => '提交失败, 已存在处理中的数据']);
        }
        cache('healthy_conver_to_' . $order_sn, $data, 60);
        if ($data['type'] == 999) {
            $balance = $this->where($map)->column("balance", "channel_type");
            if ((string)array_sum($balance) < (string)$data['user_conver_number']) {
                throw new OpenException(['msg' => '健康豆不足！']);
            }
            foreach (PayConstant::HEALTHY_CHANNEL_TYPE as $k => $v) {
                if (!isset($balance[$v]) || $balance[$v] <= 0) {
                    continue;
                }
                $deductData[$k] = $data['user_info'];
                $deductData[$k]['change_type'] = PayConstant::HEALTHY_ORDER_TYPE_CONVER_OUT;
                $deductData[$k]['conver_type'] = 3;
                $deductData[$k]['order_sn'] = $order_sn;
                $deductData[$k]['app_id'] = $data['appId'];
                $deductData[$k]['belong'] = 1;
                $deductData[$k]['uid'] = $data['uid'];
                $deductData[$k]['type'] = 2;
                $deductData[$k]['healthy_channel_type'] = $v;
                $deductData[$k]['remark'] = '设备转出消费';
                if ($balance[$v] >= $deductNum) {
                    $deductData[$k]['price'] = priceFormat(-1 * $deductNum);
                    break;
                } else {
                    $deductData[$k]['price'] = priceFormat(-1 * $balance[$v]);
                    $deductNum -= $balance[$v];
                }
            }
        } else {
            $map['channel_type'] = $data['type'];
            $balance = $this->field('id,channel_type,balance')->where($map)->findOrEmpty()->toArray();
            if (empty($balance)) {
                throw new OpenException(['msg' => '健康豆不足！']);
            }
            if ((string)$balance['balance'] < (string)$data['user_conver_number']) {
                throw new OpenException(['msg' => '健康豆不足！']);
            }
            $deductData[0] = [
                'phone' => $data['user_info']['phone'],
                'transfer_for_uid' => $data['user_info']['transfer_for_uid'],
                'transfer_for_name' => $data['user_info']['transfer_for_name'],
                'transfer_for_user_phone' => $data['user_info']['transfer_for_user_phone'],
                'transfer_from_uid' => $data['user_info']['transfer_from_uid'],
                'transfer_from_name' => $data['user_info']['transfer_from_name'],
                'transfer_from_user_phone' => $data['user_info']['transfer_from_user_phone'],
                'change_type' => PayConstant::HEALTHY_ORDER_TYPE_CONVER_OUT,
                'conver_type' => 3,
                'order_sn' => $order_sn,
                'app_id' => $data['appId'],
                'belong' => 1,
                'uid' => $data['uid'],
                'price' => priceFormat(-1 * $deductNum),
                'healthy_channel_type' => $data['type'],
                'type' => 2,
                'remark' => '设备转出消费'
            ];
        }
        if (empty($deductData)) {
            return false;
        }
        $res = Db::transaction(function () use ($deductData, $data, $order_sn) {
            //执行扣除
            //加锁
            $userId = User::where(['uid' => $data['uid'], 'status' => 1])->value('id');
            $userInfo = User::where(['id' => $userId])
                // ->lock(true)
                ->findOrEmpty()
                ->toArray();
            if (!empty($userInfo)) {
                $userRes = User::where(['uid' => $data['uid'], 'status' => 1])
                    ->dec('healthy_balance', priceFormat($data['user_conver_number']))
                    ->update();
            }
            $hbRes = true;
            //执行并回传渠道扣除信息
            $deductDetailData = [];
            foreach ($deductData as $k => $v) {
                //加锁
                $hbId = HealthyBalance::field('id,uid')->where(['uid' => $data['uid'], 'channel_type' => $v['healthy_channel_type'], 'status' => 1])
                    ->value('id');
                $hbInfo = HealthyBalance::field('id,uid')->where(['id' => $hbId])
                    // ->lock(true)
                    ->findOrEmpty()
                    ->toArray();
                if (!empty($hbInfo)) {
                    $hbUpdateRes = HealthyBalance::where(['uid' => $data['uid'], 'channel_type' => $v['healthy_channel_type'], 'status' => 1])
                        ->inc('balance', priceFormat($v['price']))
                        ->update();
                    if (!$hbUpdateRes) {
                        $hbRes = false;
                    } else {
                        $deductDetailData[] = [
                            'balance_change_type' => $v['healthy_channel_type'],
                            'balance_price' => priceFormat(-1 * $v['price']),
                        ];
                    }
                }
            }
            //记录明细
            $hbdRes = (new HealthyBalanceDetail)->saveAll($deductData);
            //记录操作日记
            $addConverData = $data['user_info'];
            $addConverData['subco_name'] = (new OpenAccount)->where(['appId' => $data['appId']])->value('name');
            $addConverData['app_id'] = $data['appId'];
            $addConverData['uid'] = $data['uid'];
            $addConverData['order_sn'] = $order_sn;
            $addConverData['conver_status'] = 1;
            $addConverData['phone'] = $data['user_mobile_phone'];
            $addConverData['balance'] = $data['user_conver_number'];
            $addConverData['request_user'] = $data['user_info_name'] ?? null;
            $addConverData['user_share_id'] = $data['user_share_key'] ?? null;
            $addConverData['remark'] = '设备系统转出';
            $hbcRes = (new HealthyBalanceConver)->new($addConverData);
            //操作转出额度
            $addConAmountData = [
                'app_id' => $data['appId'],
                'order_sn' => $order_sn,
                'amount' => $data['user_conver_number'],
                'change_type' => 3,
                'type' => 2,
                'all_amount' => $data['amount_all_num'] - $data['user_conver_number'],
            ];
            $caRes = (new ConverAmount)->new($addConAmountData);
            if ($userRes && $hbRes && $hbdRes && $hbcRes && $caRes) {
                //记录日记
                (new Log())->setChannel('healthyBalanceConver')->record($data);
                return ['order_sn' => $order_sn, 'healthy_balance_data' => $deductDetailData];
            }
            return false;
        });
        cache('healthy_conver_to_' . $order_sn, null);
        return $res;
    }

    /**
     * @title  变更健康豆明细
     * @param $uid
     * @param $orderType
     * @param $changePrice
     * @param $channelType
     * @param $aOrderInfo
     * @param string $remark
     * @return mixed
     */
    public function saveHealthBalance($uid, $orderType, $changePrice, $channelType, $aOrderInfo, $remark = "")
    {
        $DBRes = Db::transaction(function () use ($uid, $orderType, $changePrice, $channelType, $aOrderInfo, $remark) {

            $orderTypes = PayConstant::HEALTHY_ORDER_TYPE;
            if (!isset($orderTypes[$orderType])) {
                throw new OpenException(['msg' => "意外的场景"]);
            }
            $orderTypeArray = $orderTypes[$orderType];
            if (!in_array($channelType, PayConstant::HEALTHY_CHANNEL_TYPE)) {
                throw new OpenException(['msg' => "余额变更渠道异常"]);
            }

            $userBalance['uid'] = $uid;
            $userBalance['order_sn'] = $aOrderInfo[$orderTypeArray['order_sn_field']];
            $userBalance['belong'] = $orderTypeArray['belong'];
            $userBalance['type'] = $orderTypeArray['change'] == 1 ? 1 : 2;
            $userBalance['price'] = priceFormat($changePrice * $orderTypeArray['change']);
            $userBalance['change_type'] = $orderType;
            $userBalance['remark'] = $orderTypeArray['remark'];
            $userBalance['healthy_channel_type'] = $channelType;
            if ($channelType == PayConstant::HEALTHY_ORDER_TYPE_GIVING
                || $channelType == PayConstant::HEALTHY_ORDER_TYPE_RECEIVE) {
                $userBalance['transfer_type'] = 1;
                $userBalance['transfer_for_uid'] = $aOrderInfo['to_uid'];
                $userBalance['transfer_for_user_phone'] = $aOrderInfo['to_phone'];
                $userBalance['transfer_for_name'] = $aOrderInfo['to_phone'];
                $userBalance['transfer_from_user_phone'] = $aOrderInfo['from_phone'];
                $userBalance['transfer_from_name'] = $aOrderInfo['from_phone'];
                $userBalance['is_transfer'] = 1;
                $userBalance['transfer_from_uid'] = $aOrderInfo['uid'];
                $userBalance['hand_fee'] = $aOrderInfo['hand_fee'];
                $userBalance['hand_fee_proportion'] = $aOrderInfo['hand_fee_proportion'];
            }
            HealthyBalanceDetail::create($userBalance);
// 操作时锁行
            $healthyBalance = $this->where(['uid' => $uid, 'channel_type' => $channelType, 'status' => 1])
                ->find();
            if ($healthyBalance) {
                if ($changePrice < 0 && $healthyBalance['balance'] < abs($changePrice)) {
                    throw new OpenException(['msg' => "余额不足,无法扣除"]);
                }
                if ($userBalance['price'] > 0) {
                    $channelHealthyBalanceRes = $this->where(['uid' => $uid, 'channel_type' => $channelType, 'status' => 1])
                        ->inc('balance', priceFormat($changePrice))
                        ->update();
                } else {

                    $channelHealthyBalanceRes = $this->where(['uid' => $uid, 'channel_type' => $channelType, 'status' => 1])
                        ->dec('balance', priceFormat($changePrice))
                        ->update();
                }
            } else {
                if ($changePrice < 0) {
                    throw new OpenException(['msg' => "余额不足,无法扣除"]);
                }
                $channelHealthyBalanceRes = $this->baseCreate([
                    'status' => 1,
                    'create_time' => time(),
                    'update_time' => time(),
                    'uid' => $uid,
                    'channel_type' => $channelType,
                    'balance' => priceFormat($changePrice)
                ]);
            }

            if ($orderTypeArray['change'] == 1) {
                $userHealthyBalanceRes = (new User())->where(['uid' => $uid, 'status' => 1])
                    ->inc('healthy_balance', priceFormat($changePrice))
                    ->update();
            } else {

                $userHealthyBalanceRes = (new User())->where(['uid' => $uid, 'status' => 1])
                    ->dec('healthy_balance', priceFormat($changePrice))
                    ->update();
            }
            //            return $this->where(['uid' => $uid, 'status' => 1])->sum("balance");
            return $userHealthyBalanceRes;
        });
        return $DBRes;
    }

    /**
     * @title 批量自增/自减-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBBatchIncOrDecBySql(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                $res = $this->batchIncOrDecBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            $DBRes = false;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            $DBRes = false;
        }
        return judge($DBRes);
    }

    /**
     * @title 批量新增-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBSaveAll(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                $data['notValidateValueField'] = ['uid'];
                $res = $this->batchCreateBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            $DBRes = false;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            $DBRes = false;
        }
        return judge($DBRes);
    }
}
