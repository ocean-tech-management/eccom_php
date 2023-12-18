<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 提前购凭证(美丽卡)模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use app\lib\services\Log;
use think\facade\Db;

class AdvanceCardDetail extends BaseModel
{
    public $lockAdvanceBuyKey = 'cmLockAdvanceBuy';

    /**
     * @title  提前购卡明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', trim($sear['keyword'])))];
        }
        if (!empty($sear['user_keyword'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', trim($sear['user_keyword'])))];
            $uids = User::where($uMap)->column('uid');
            if (!empty($uids)) {
                $map[] = ['uid', 'in', $uids];
            }
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['change_type'])) {
            if (is_array($sear['change_type'])) {
                $map[] = ['change_type', 'in', $sear['change_type']];
            } else {
                $map[] = ['change_type', '=', $sear['change_type']];
            }
        }
        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
        }

        if (!empty($sear['uid'])) {
            $map[] = ['uid', '=', $sear['uid']];
        }

        if ($this->module == 'api') {
            $map[] = ['uid', '=', $sear['uid']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = $this->with(['userInfo'])->withoutField('id,update_time')->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        if (!empty($list)) {
            $allCrowdActivity = array_unique(array_column($list, 'crowd_code'));
            if (!empty($allCrowdActivity)) {
                $allCrowdActivityName = CrowdfundingActivity::where(['activity_code' => $allCrowdActivity])->column('title', 'activity_code');
                if (!empty($allCrowdActivityName)) {
                    foreach ($list as $key => $value) {
                        if (!empty($value['crowd_code'] ?? null)) {
                            $list[$key]['activity_name'] = $allCrowdActivityName[$value['crowd_code']] ?? '未知福利区';
                        }
                    }
                }
            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  判断用户是否有提前购资格
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function checkUserAdvanceBuy(array $data)
    {
        $goods = $data['goods'] ?? [];
        //用户uid
        $uid = $data['uid'];
        //检查类型 1为订单中有商品检测 2为没有商品检查某个期
        $type = $data['checkType'] ?? 1;
        if ($type == 1) {
            $period = $goods;
        } else {
            $period = $data['period'];
        }

        //查看期详情
        $periodList = CrowdfundingPeriod::where(function ($query) use ($period) {
            foreach ($period as $key => $value) {
                ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_id']];
                ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
            }
            for ($i = 0; $i < count($period); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where(['result_status' => 4, 'status' => 1])->select()->toArray();
        $periodInfo = [];
        $normalPeriod = [];
        $normalPeriodDuration = [];
        if (empty($periodList)) {
            return ['res' => false, 'msg' => '查无期详情', 'periodList' => $periodList ?? []];
        }

        foreach ($periodList as $key => $value) {
            $pCrowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $periodInfo[$pCrowdKey] = $value;
            if ($value['start_time'] <= time() && $value['end_time'] > time()) {
                $normalPeriod[$pCrowdKey] = $value;
            }
        }

        if (!empty($normalPeriod)) {
            $durationList = CrowdfundingPeriodSaleDuration::where(function ($query) use ($period) {
                foreach ($period as $key => $value) {
                    ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_id']];
                    ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                    ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                }
                for ($i = 0; $i < count($period); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where(['status' => 1])->select()->toArray();
            if (!empty($durationList)) {
                foreach ($durationList as $key => $value) {
                    if ($value['start_time'] <= time() && $value['end_time'] > time()) {
                        $normalPeriodDuration[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']] = $value;
                    }
                }
                if (!empty($normalPeriodDuration ?? [])) {
                    return ['res' => false, 'msg' => '存在部分正常购买的期且正常期的存在正常时间段, 不需要使用提前购', 'periodList' => $periodList ?? [], 'normalPeriod' => $normalPeriod, 'periodInfo' => $periodInfo, 'normalPeriodDuration' => $normalPeriodDuration ?? []];
                }
            } else {
                return ['res' => false, 'msg' => '存在部分正常购买的期, 不需要或不允许使用提前购', 'periodList' => $periodList ?? [], 'normalPeriod' => $normalPeriod, 'periodInfo' => $periodInfo];
            }
        }

        //查看用户是否有使用过提前购卡
        $existADCard = [];
        $userUsedAdvanceBuyCard = AdvanceCardDetail::where(function ($query) use ($period) {
            foreach ($period as $key => $value) {
                ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_id']];
                ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
            }
            for ($i = 0; $i < count($period); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where(['change_type' => 5, 'status' => 1, 'type' => 2, 'uid' => $uid])->select()->toArray();
        $advanceGoodsNumber = 0;
        $notExistAdvanceGoods = [];
        $notExistPeriod = [];
        if (!empty($userUsedAdvanceBuyCard)) {
            foreach ($userUsedAdvanceBuyCard as $key => $value) {
                $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                $existADCard[$crowdKey] = $value;
                if (!empty($goods)) {
                    foreach ($goods as $gKey => $gValue) {
                        if ($gValue['activity_id'] = $value['crowd_code'] && $gValue['round_number'] == $value['crowd_round_number'] && $gValue['period_number'] == $value['crowd_period_number']) {
                            $goods[$gKey]['advance_buy'] = true;
                            $advanceGoodsNumber += 1;
                        } else {
                            $goods[$gKey]['advance_buy'] = false;
                            $notExistAdvanceGoods[] = $value;
                            $notExistPeriod[$gValue['activity_id'] . '-' . $gValue['round_number'] . '-' . $gValue['period_number']] = $gValue;
                        }
                    }
                }
            }
            if ($type == 2) {
                return ['res' => true, 'msg' => '用户已使用过提前购卡, 可继续使用', 'userUsedAdvanceBuyCard' => $userUsedAdvanceBuyCard ?? []];
            }
        }
        if ($type == 1 && ($advanceGoodsNumber ?? 0) == count($goods)) {
            return ['res' => true, 'msg' => '用户已使用过提前购卡, 可继续使用', 'goods' => $goods ?? []];
        }

        //查看用户是否有提前购数量, 有则允许跳过时间段判断直接进入购买
        $userAdvanceBuyCard = User::where(['uid' => $uid, 'status' => 1])->value('advance_buy_card');
        if (intval($userAdvanceBuyCard) <= 0) {
            return ['res' => false, 'msg' => '用户无提前购卡, 无法享受提前购'];
        }

        $lockNumber = 0;
        //判断是否有占用中的提前购卡
        $lockList = cache($this->lockAdvanceBuyKey . $uid);
        if (!empty($lockList)) {
            $lockNumber = count($lockList);
            foreach ($period as $key => $value) {
                $crowdKey = $value['activity_id'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                if (!empty($lockList[$crowdKey] ?? null)) {
                    if ($type == 1) {
                        if (empty($notExistPeriod[$crowdKey] ?? null)) {
                            $lockNumber -= 1;
                        }
                    } else {
                        $lockNumber -= 1;
                    }
                }
            }
        }

        if ((intval($userAdvanceBuyCard) - intval($lockNumber)) <= 0) {
            return ['res' => false, 'msg' => '用户有提前购卡, 但剩余的卡都已被占用无法使用, 故无法享受提前购', 'lockNumber' => $lockNumber ?? 0, 'userAdvanceBuyCard' => $userAdvanceBuyCard ?? 0];
        }

        if ($type == 1) {
            if (count(array_unique(array_keys($notExistPeriod))) > ($userAdvanceBuyCard ?? 0)) {
                return ['res' => false, 'msg' => '用户提前购卡数量不足, 无法享受提前购', 'notExistPeriod' => count(array_unique(array_keys($notExistPeriod))), 'userAdvanceBuyCard' => $userAdvanceBuyCard ?? 0];
            }
            foreach ($goods as $key => $value) {
                if (empty($value['advance_buy'] ?? null)) {
                    $goods[$key]['advance_buy'] = true;
                }
            }
            return ['res' => true, 'msg' => '用户拥有提前购卡, 可直接购买', 'goods' => $goods ?? [], 'AdvanceBuyNumber' => count(array_unique(array_keys($notExistPeriod))), 'userAdvanceBuyCard' => $userAdvanceBuyCard ?? 0];
        } else {
            return ['res' => true, 'msg' => '用户拥有提前购卡, 可直接购买', 'AdvanceBuyNumber' => 1, 'userAdvanceBuyCard' => $userAdvanceBuyCard ?? 0];
        }
    }

    /**
     * @title  赠送美丽卡
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function sendAdvanceBuyCard(array $data)
    {
        //赠送类型 1为商城订单购物赠送 2为众筹失败赠送 3为众筹熔断全部人赠送 4为新人赠送 6为系统指定赠送 7为众筹熔断指定订单赠送
        $type = $data['send_type'] ?? 1;
        $condition = false;
        switch ($type) {
            case 1:
                $orderInfo = Order::where(['order_sn' => $data['order_sn'], 'pay_status' => 2, 'order_status' => [2, 3, 8]])->findOrEmpty()->toArray();
                //判断是否奖励过
                $existSend = self::where(['order_sn' => $data['order_sn'], 'status' => 1, 'type' => 1, 'change_type' => $type])->count();
                if (!empty($orderInfo) && empty($existSend)) {
                    $condition = true;
                }
                break;
            case 2:
                $periodInfo = CrowdfundingPeriod::where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'result_status' => 3])->select()->toArray();
                //判断是否奖励过
                $existSend = self::where(['crowd_code' => $data['activity_code'], 'crowd_round_number' => $data['round_number'], 'crowd_period_number' => $data['period_number'], 'status' => 1, 'type' => 1, 'change_type' => $type])->count();
                if (!empty($periodInfo) && empty($existSend)) {
                    $condition = true;
                }
                break;
            case 3:
                $periodInfo = CrowdfundingPeriod::where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'result_status' => 5])->findOrEmpty()->toArray();
                //判断是否奖励过
                $existSend = self::where(['crowd_code' => $data['activity_code'], 'crowd_round_number' => $data['round_number'], 'crowd_period_number' => $data['period_number'], 'status' => 1, 'type' => 1, 'change_type' => $type])->count();
                if (!empty($periodInfo) && empty($existSend)) {
                    $condition = true;
                }
                break;
            case 4:
                $orderList = Order::where(['uid' => $data['uid'], 'pay_status' => 2, 'order_status' => [2, 3]])->findOrEmpty()->toArray();
                //判断是否奖励过
                $existSend = self::where(['uid' => $data['uid'], 'status' => 1, 'type' => 1, 'change_type' => $type])->count();
                if (empty($orderList) && empty($existSend)) {
                    $condition = true;
                }
                break;
            case 6:
                $condition = true;
                break;
            case 7:
                $orderInfo = Order::where(['order_sn' => $data['order_sn'], 'pay_status' => 2, 'order_status' => [2, 3, 8], 'order_type' => 6])->findOrEmpty()->toArray();
                //判断是否奖励过
                $existSend = self::where(['order_sn' => $data['order_sn'], 'status' => 1, 'type' => 1, 'change_type' => $type])->count();
                if (!empty($orderInfo) && empty($existSend)) {
                    $condition = true;
                }
                break;
            default:
                $condition = false;
                break;
        }
        if (empty($condition)) {
            return false;
        }
        //获取系统配置
        $systemConfig = SystemConfig::where(['status' => 1])->field('crowd_advance_buy_condition,order_advance_buy_condition')->findOrEmpty()->toArray();

        //获取用户列表
        switch ($type) {
            case 1:
                $userList = [$orderInfo['uid'] => priceFormat(($orderInfo['real_pay_price'] - $orderInfo['fare_price']))];
                if ((string)priceFormat(($orderInfo['real_pay_price'] - $orderInfo['fare_price'])) >= (string)$systemConfig['order_advance_buy_condition']) {
                    $userListNumber = [$orderInfo['uid'] => floor(priceFormat(($orderInfo['real_pay_price'] - $orderInfo['fare_price'])) / $systemConfig['order_advance_buy_condition'])];
                }
                break;
            case 2:
            case 3:
                if (!empty($periodInfo)) {
                    //不是二维数组强行变更为二维数组
                    if (count($periodInfo) == count($periodInfo, 1)) {
                        $periodInfo = [$periodInfo];
                    }
                    $gWhere[] = ['status', '=', 1];
                    $gWhere[] = ['pay_status', '=', 2];
                    $periodOrder = OrderGoods::with(['orderInfo'])->where(function ($query) use ($periodInfo) {
                        $number = 0;
                        foreach ($periodInfo as $key => $value) {
                            ${'where' . ($number + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                            ${'where' . ($number + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                            ${'where' . ($number + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                            $number++;
                        }

                        for ($i = 0; $i < count($periodInfo); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->where($gWhere)->field('crowd_code,crowd_round_number,crowd_period_number,order_sn,real_pay_price,total_fare_price')->select()->each(function ($item) {
                        if (!empty($item['orderInfo'] ?? [])) {
                            $item['uid'] = $item['orderInfo']['uid'];
                        }
                    })->toArray();
                    $userList = [];
                    if (!empty($periodOrder)) {
                        foreach ($periodOrder as $key => $value) {
                            if (!isset($userList[$value['uid']])) {
                                $userList[$value['uid']] = 0;
                            }
                            $userList[$value['uid']] += $value['real_pay_price'] - $value['total_fare_price'];
                        }
                        foreach ($userList as $key => $value) {
                            if($type == 2){
                                $userListNumber[$key] = 1;
                            }else{
                                if ($value >= $systemConfig['crowd_advance_buy_condition']) {
                                    $userListNumber[$key] = floor(priceFormat($value) / $systemConfig['crowd_advance_buy_condition']);
                                }
                            }
                        }
                    }
                }
                break;
            case 4:
                $userList = [$data['uid'] => 9999];
                $userListNumber = [$data['uid'] => 1];
                break;
            case 6:
                foreach ($data['userList'] as $key => $value) {
                    $userList[$value['uid']] = 0;
                    $userListNumber[$value['uid']] = $value['number'];
                }

                break;
            case 7:
                $userList = [$orderInfo['uid'] => priceFormat(($orderInfo['real_pay_price'] - $orderInfo['fare_price']))];
                if ((string)priceFormat(($orderInfo['real_pay_price'] - $orderInfo['fare_price'])) >= (string)$systemConfig['crowd_advance_buy_condition']) {
                    $userListNumber = [$orderInfo['uid'] => floor(priceFormat(($orderInfo['real_pay_price'] - $orderInfo['fare_price'])) / $systemConfig['crowd_advance_buy_condition'])];
                }
                break;
            default :
                $userList = [];
                break;
        }
        $cardDetail = [];
        if (!empty($userListNumber)) {
            $number = 0;
            foreach ($userListNumber as $key => $value) {
                $cardDetail[$number]['uid'] = $key;
                $cardDetail[$number]['type'] = 1;
                if ($type == 7) {
                    $cardDetail[$number]['change_type'] = 3;
                } else {
                    $cardDetail[$number]['change_type'] = $type;
                }
                $cardDetail[$number]['order_sn'] = $data['order_sn'] ?? null;
                $cardDetail[$number]['condition_price'] = $userList[$key] ?? 0;
                $cardDetail[$number]['number'] = $value;
                $cardDetail[$number]['crowd_code'] = $data['activity_code'] ?? null;
                $cardDetail[$number]['crowd_round_number'] = $data['round_number'] ?? null;
                $cardDetail[$number]['crowd_period_number'] = $data['period_number'] ?? null;
                if ($type == 6) {
                    $cardDetail[$number]['remark'] = $data['remark'] ?? '系统指定赠送';
                }
                $number += 1;
            }
        }

        //数据库操作
        if (!empty($cardDetail)) {
            $DBRes = Db::transaction(function () use ($cardDetail) {
                $res = $this->saveAll($cardDetail);
                foreach ($cardDetail as $key => $value) {
                    if (intval($value['number']) > 0) {
                        User::where(['uid' => $value['uid'], 'status' => 1])->inc('advance_buy_card', intval($value['number']))->update();
                    }
                }
                return true;
            });
        }

        //记录日志
        $log['requestData'] = $data;
        $log['userListNumber'] = $userListNumber ?? [];
        $log['userList'] = $userList ?? [];
        $log['cardDetail'] = $cardDetail ?? [];
        $log['DBRes'] = $DBRes ?? null;
        $this->log($log, 'info');
        return true;
    }

    /**
     * @title  用户使用提前购卡
     * @param array $data
     * @throws \Exception
     * @return mixed
     */
    public function useAdvanceBuyCard(array $data)
    {
        $goods = $data['goods'] ?? [];
        $uid = $data['uid'];
        if (empty($period ?? [])) {
            $goods = OrderGoods::where(['order_sn' => $data['order_sn'], 'order_type' => 6, 'status' => 1])->field('order_sn,goods_sn,sku_sn,crowd_code,crowd_round_number,crowd_period_number,status')->select()->toArray();
        }
        $period = $goods;
        if(empty($period)){
            return false;
        }
        $userUsedAdvanceBuyCard = AdvanceCardDetail::where(function ($query) use ($period) {
            foreach ($period as $key => $value) {
                ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['crowd_code']];
                ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['crowd_round_number']];
                ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['crowd_period_number']];
            }
            for ($i = 0; $i < count($period); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where(['change_type' => 5, 'status' => 1, 'type' => 2, 'uid' => $uid])->select()->toArray();
        $advanceGoodsNumber = 0;
        if (!empty($userUsedAdvanceBuyCard)) {
            foreach ($userUsedAdvanceBuyCard as $key => $value) {
                $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                $existADCard[$crowdKey] = $value;
                if (!empty($goods)) {
                    foreach ($goods as $gKey => $gValue) {
                        if ($gValue['crowd_code'] = $value['crowd_code'] && $gValue['crowd_round_number'] == $value['crowd_round_number'] && $gValue['crowd_period_number'] == $value['crowd_period_number']) {
                            $goods[$gKey]['advance_buy'] = true;
                            $advanceGoodsNumber += 1;
                        } else {
                            $notExistAdvanceGoods[] = $value;
                            $notExistPeriod[$gValue['crowd_code'] . '-' . $gValue['crowd_round_number'] . '-' . $gValue['crowd_period_number']] = $gValue;
                        }
                    }
                }
            }

            if($advanceGoodsNumber != count($goods) && !empty($notExistPeriod ?? [])){
                $finallyCardNumber = count(array_unique(array_keys($notExistPeriod)));
            }else{
                $finallyCardNumber = 0;
            }
        }else{
            foreach ($goods as $key => $value) {
                $notExistPeriod[$value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number']] = $value;
            }
            $finallyCardNumber = count(array_unique(array_keys($notExistPeriod)));
        }
        $cardDetail = [];
        if (!empty($finallyCardNumber)) {
            $DBRes = false;
            $number = 0;
            foreach ($notExistPeriod as $key => $value) {
                $crowdInfo = explode('-',$key);
                $cardDetail[$number]['uid'] = $uid;
                $cardDetail[$number]['type'] = 2;
                $cardDetail[$number]['change_type'] = 5;
                $cardDetail[$number]['order_sn'] = $data['order_sn'] ?? null;
                $cardDetail[$number]['condition_price'] = 0;
                $cardDetail[$number]['number'] = "-1";
                $cardDetail[$number]['crowd_code'] = $crowdInfo[0];
                $cardDetail[$number]['crowd_round_number'] = $crowdInfo[1];
                $cardDetail[$number]['crowd_period_number'] = $crowdInfo[2];
                $number += 1;
            }
            //数据库操作
            if (!empty($cardDetail)) {
                $DBRes = Db::transaction(function () use ($cardDetail, $finallyCardNumber, $uid) {
                    $this->saveAll($cardDetail);
                    $res = User::where(['uid' => $uid, 'status' => 1])->dec('advance_buy_card', intval($finallyCardNumber))->update();
                    return true;
                });
            }

        }else{
            $DBRes = true;
        }

        return judge($DBRes);
    }

    /**
     * @title 批量新增美丽卡
     * @param array $data
     * @return mixed
     */
    public function newBatch(array $data)
    {
        $allUser = $data['all_user'];
        $type = $data['type'] ?? 1;
        $userPhone = array_unique(array_column($allUser, 'user_phone'));
        $userList = User::where(['phone' => $userPhone, 'status' => 1])->column('uid', 'phone');
        if (empty($userList)) {
            throw new ServiceException(['msg' => '查无有效用户']);
        }

        foreach ($userPhone as $key => $value) {
            if (!in_array(trim($value), array_keys($userList))) {
                throw new ServiceException(['msg' => '手机号码' . $value . '不存在平台, 请仔细检查!']);
            }
        }

        $DBRes = DB::transaction(function () use ($allUser, $userList, $type, $data) {
            $res = false;
            $number = 0;
            $balanceNew = [];
            $CodeBuilder = (new CodeBuilder());

            foreach ($allUser as $key => $value) {
                if (priceFormat($value['price']) > 1000000) {
                    throw new ServiceException(['msg' => '单次充值不能超过1000000']);
                }
                if (!empty($userList[$value['user_phone']] ?? null) && doubleval($value['price'] ?? 0) != 0) {
                    $remarkMsg = null;
                    $balanceNew[$number]['order_sn'] = $CodeBuilder->buildSystemRechargeAdvanceCardSn();
                    $balanceNew[$number]['uid'] = $userList[$value['user_phone']];
                    $balanceNew[$number]['type'] = 1;
                    if (priceFormat($value['price']) < 0) {
                        $balanceNew[$number]['type'] = 2;
                    }
                    $balanceNew[$number]['number'] = priceFormat($value['price']);
                    $balanceNew[$number]['change_type'] = 6;
                    $balanceNew[$number]['condition_price'] = 0;
                    $remarkMsg = '后台系统增送';
                    if ($balanceNew[$number]['type'] == 2) {
                        $remarkMsg = '后台系统扣除';
                    }
                    if (!empty($value['remark'] ?? null)) {
                        $remarkMsg .= trim($value['remark']);
                    }
                    $balanceNew[$number]['remark'] = $remarkMsg;
                    $number += 1;
                }
            }

            if (!empty($balanceNew)) {
                $batchSqlAdvanceData['list'] = $balanceNew;
                $batchSqlAdvanceData['db_name'] = 'sp_user';
                $batchSqlAdvanceData['id_field'] = 'uid';
                $batchSqlAdvanceData['operate_field'] = 'advance_buy_card';
                $batchSqlAdvanceData['value_field'] = 'number';
                $batchSqlAdvanceData['operate_type'] = 'inc';
                $batchSqlAdvanceData['sear_type'] = 1;
                $batchSqlAdvanceData['other_map'] = 'status = 1';
                (new CommonModel())->DBBatchIncOrDec($batchSqlAdvanceData);

                //批量新增明细
                $batchSqlAdvanceDetailData['list'] = $balanceNew;
                $batchSqlAdvanceDetailData['db_name'] = 'sp_advance_card_detail';
                $batchSqlAdvanceDetailData['notValidateValueField'] = ['uid'];
                $res = (new CommonModel())->setThrowError()->DBSaveAll($batchSqlAdvanceDetailData);
            }

            unset($allUser);
            unset($balanceNew);
            return $res ?? false;
        });
        return judge($DBRes);
    }

    public function userInfo()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name' => 'name','user_phone' => 'phone']);
    }


    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'error')
    {
        return (new Log())->setChannel('advanceBuy')->record($data, $level);
    }
}