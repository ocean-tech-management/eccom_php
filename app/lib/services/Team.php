<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\models\CrowdfundingSystemConfig;
use app\lib\models\ExpMemberVdc;
use app\lib\models\OrderGoods;
use app\lib\models\TeamPerformance;
use app\lib\models\TeamShareholderMemberVdc;
use app\lib\models\User;
use app\lib\models\Divide;
use app\lib\services\TeamMember as TeamMemberService;
use think\facade\Db;
use think\facade\Queue;

class Team
{
    /**
     * @title  记录订单用户对应全部上级的团队订单
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function recordOrderForTeam(array $data)
    {
        $log['requestData'] = $data;
        if (empty($data)) {
            return $this->recordError($log, ['msg' => '传入参数为空']);
        }

        $orderSn = $data['order_sn'];
        $orderInfo = $data['orderInfo'];
        if (empty($orderInfo)) {
            return $this->recordError($log, ['msg' => '暂无有效的订单详情']);
        }

        //判断是否记录过团队订单
        $exist = TeamPerformance::where(['order_sn' => $orderSn])->column('id');
        $log['existRecord'] = $exist;
        if (!empty($exist)) {
            return $this->recordError($log, ['msg' => '该订单已经记录过团队订单啦']);
        }

        $orderGoods = OrderGoods::with(['vdc'])->where(['order_sn' => $orderSn, 'status' => 1, 'pay_status' => 2])->withoutField('id,images,specs,desc,create_time,update_time')->select()->toArray();
        $log['orderGoods'] = $orderGoods;

        $orderUid = $orderInfo['uid'];
        $userInfo = User::where(['uid' => $orderUid])->field('uid,phone,name,vip_level,link_superior_user')->findOrEmpty()->toArray();
        $log['userInfo'] = $userInfo ?? [];
        if (empty($userInfo)) {
            return $this->recordError($log, ['msg' => '无有效用户信息']);
        }
        if (empty($userInfo['vip_level'])) {
            $searUid = $userInfo['link_superior_user'] ?? null;
        } else {
            $searUid = $orderUid;
        }
        if (empty($searUid)) {
            return $this->recordError($log, ['msg' => '暂无可查找的有效本人或上级']);
        }

        //查找这个用户的上级
        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
        if (!empty($databaseVersion) && is_numeric($databaseVersion) && $databaseVersion > 8016) {
            //mysql 8.0.16以上用此方法
            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.team_code,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_member ,(SELECT @id := " . "'" . $searUid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_member WHERE @id IS NOT NULL ) u1 JOIN sp_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
        } else {
            //mysql 8.0.16及以下用此方法
            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.team_code,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_member WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_member,(SELECT @id := " . "'" . $searUid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
        }

        $log['linkUserParent'] = $linkUserParent;
        if (empty($linkUserParent)) {
            return $this->recordError($log, ['msg' => '暂无可记录的有效上级']);
        }
        if (empty($userInfo['vip_level'])) {
            $normalUser[0]['uid'] = $userInfo['uid'];
            $normalUser[0]['member_card'] = null;
            $normalUser[0]['user_phone'] = $userInfo['phone'];
            $normalUser[0]['team_code'] = null;
            $normalUser[0]['child_team_code'] = null;
            $normalUser[0]['parent_team'] = null;
            $normalUser[0]['level'] = 0;
            $normalUser[0]['type'] = 1;
            $normalUser[0]['status'] = 1;
            $normalUser[0]['link_superior_user'] = $userInfo['link_superior_user'];
            $normalUser[0]['divide_level'] = 1;
            $normalUser[0]['recordPerformance'] = true;
            foreach ($linkUserParent as $key => $value) {
                $linkUserParent[$key]['divide_level'] += 1;
                //剔除不是会员的上级
                if ($value['level'] <= 0) {
                    unset($linkUserParent[$key]);
                }
            }
            $linkUserParent = array_merge_recursive($normalUser, $linkUserParent);
        } else {
            foreach ($linkUserParent as $key => $value) {
                //剔除不是会员的上级
                if ($value['level'] <= 0) {
                    unset($linkUserParent[$key]);
                }
            }
        }

        //如果产生了跨级,则修改当前最高等级(最低level值),然后判断后续的是否低于level值,低于则还是为跨级订单
        $nowLevel = $userInfo['vip_level'] ?? 0;
        foreach ($linkUserParent as $key => $value) {
            if ($value['level'] > 0) {
                $linkUserParent[$key]['recordPerformance'] = false;
                if ((!empty($nowLevel) && $value['level'] <= $nowLevel) || (empty($nowLevel) && $value['level'] > $nowLevel)) {
                    $linkUserParent[$key]['recordPerformance'] = true;
                    if ((!empty($nowLevel) && $value['level'] < $nowLevel) || (empty($nowLevel) && $value['level'] > $nowLevel)) {
                        $nowLevel = $value['level'];
                    }
                }
            }
        }

        //获取每个等级的成本价,为了拓展后续 <如果同个筛选时间段内同个用户产生了不同等级的业绩,要强行恢复到某一个等级成本价> 这个功能
        foreach ($orderGoods as $key => $value) {
            $orderGoods[$key]['newVdc'] = [];
            if (!empty($value['vdc'])) {
                foreach ($value['vdc'] as $vKey => $vValue) {
                    $orderGoods[$key]['newVdc'][$vValue['level']] = $vValue;
                    $orderGoods[$key]['all_level_purchase_price'][$vValue['level']] = $vValue['purchase_price'];
                }
            }
        }

        $record = [];
        $count = 0;
        $res = false;
        foreach ($orderGoods as $key => $value) {
            foreach ($linkUserParent as $lKey => $lValue) {
//                if (!empty($value['newVdc'][$lValue['level']])) {
                $record[$count]['order_sn'] = $orderSn;
                $record[$count]['order_uid'] = $userInfo['uid'];
                $record[$count]['order_user_level'] = $userInfo['vip_level'] ?? 0;
                $record[$count]['order_user_phone'] = $userInfo['phone'] ?? null;
                $record[$count]['belong'] = $orderInfo['order_belong'] ?? 1;
                $record[$count]['order_type'] = $orderInfo['order_type'] ?? $value['order_type'] ?? 1;
                if ($lValue['uid'] == $userInfo['uid']) {
                    $record[$count]['type'] = 2;
                } else {
                    $record[$count]['type'] = 1;
                }
                $record[$count]['step_over_level'] = empty($lValue['recordPerformance']) ? 1 : 2;
                $record[$count]['goods_sn'] = $value['goods_sn'];
                $record[$count]['sku_sn'] = $value['sku_sn'];
                $record[$count]['price'] = $value['price'];
                $record[$count]['count'] = $value['count'];
                $record[$count]['total_price'] = $value['total_price'];
                $record[$count]['sale_price'] = $value['sale_price'];
                $record[$count]['all_dis'] = $value['all_dis'];
                $record[$count]['total_fare_price'] = $value['total_fare_price'];
                $record[$count]['real_pay_price'] = $value['real_pay_price'];
                if (!empty($value['newVdc'][$lValue['level']])) {
                    $nowVdc = $value['newVdc'][$lValue['level']];
                    $record[$count]['purchase_price'] = $nowVdc['purchase_price'];
                } else {
                    $record[$count]['purchase_price'] = $value['price'];
                }
                $record[$count]['link_uid'] = $lValue['uid'];
                $record[$count]['link_user_level'] = $lValue['level'];
                $record[$count]['link_user_phone'] = $lValue['user_phone'] ?? null;
                $record[$count]['link_user_team_level'] = $lValue['divide_level'];
                if (!empty($value['all_level_purchase_price'])) {
                    $record[$count]['goods_level_vdc'] = json_encode($value['all_level_purchase_price'], JSON_UNESCAPED_UNICODE);
                }
                $record[$count]['record_status'] = 1;
                if (!empty($orderInfo['create_time'])) {
                    if (is_numeric($orderInfo['create_time'])) {
                        $record[$count]['order_create_time'] = $orderInfo['create_time'];
                    } else {
                        $record[$count]['order_create_time'] = strtotime($orderInfo['create_time']);
                    }
                } else {
                    $record[$count]['order_create_time'] = time();
                }

                $count++;
//                }
            }
        }
        if (!empty($record)) {
            $res = (new TeamPerformance())->saveAll($record);
            $log['DBRes'] = $res->toArray();
        }

        //记录日志
        if (!empty($res)) {
            $log['msg'] = '订单 ' . ($orderSn ?? "<暂无订单编号>") . " [ 团队订单记录服务记录成功 ]";
            $this->log($log, 'info');
        }

        return judge($res);
    }

    /**
     * @title  记录订单用户对应全部上级的团队订单(用户表找爹)
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function recordOrderForTeamFromUserTable(array $data)
    {
        $log['requestData'] = $data;
        if (empty($data)) {
            return $this->recordError($log, ['msg' => '传入参数为空']);
        }

        $orderSn = $data['order_sn'];
        $orderInfo = $data['orderInfo'];
        if (empty($orderInfo)) {
            return $this->recordError($log, ['msg' => '暂无有效的订单详情']);
        }

        //判断是否记录过团队订单
        $exist = TeamPerformance::where(['order_sn' => $orderSn])->column('id');
        $log['existRecord'] = $exist;
        if (!empty($exist)) {
            return $this->recordError($log, ['msg' => '该订单已经记录过团队订单啦']);
        }

        $orderGoods = OrderGoods::with(['vdc'])->where(['order_sn' => $orderSn, 'status' => 1, 'pay_status' => 2])->withoutField('id,images,specs,desc,create_time,update_time')->select()->toArray();
        $log['orderGoods'] = $orderGoods;

        $orderUid = $orderInfo['uid'];
        $userInfo = User::where(['uid' => $orderUid])->field('uid,phone,name,vip_level,link_superior_user')->findOrEmpty()->toArray();
        $log['userInfo'] = $userInfo ?? [];
        if (empty($userInfo)) {
            return $this->recordError($log, ['msg' => '无有效用户信息']);
        }
        if (empty($userInfo['vip_level'])) {
            $searUid = $userInfo['link_superior_user'] ?? null;
        } else {
            $searUid = $orderUid;
        }
        if (empty($searUid)) {
            return $this->recordError($log, ['msg' => '暂无可查找的有效本人或上级']);
        }

        //查找这个用户的上级
        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
        if (!empty($databaseVersion) && is_numeric($databaseVersion) && $databaseVersion > 8016) {
            //mysql 8.0.16以上用此方法
            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.team_vip_level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user ,(SELECT @id := " . "'" . $searUid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
        } else {
            //mysql 8.0.16及以下用此方法
            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.team_vip_level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user,(SELECT @id := " . "'" . $searUid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
        }

        $log['linkUserParent'] = $linkUserParent;
        if (empty($linkUserParent)) {
            return $this->recordError($log, ['msg' => '暂无可记录的有效上级']);
        }
        if (empty($userInfo['vip_level'])) {
            $normalUser[0]['uid'] = $userInfo['uid'];
            $normalUser[0]['member_card'] = null;
            $normalUser[0]['user_phone'] = $userInfo['phone'];
            $normalUser[0]['team_code'] = null;
            $normalUser[0]['child_team_code'] = null;
            $normalUser[0]['parent_team'] = null;
            $normalUser[0]['level'] = 0;
            $normalUser[0]['type'] = 1;
            $normalUser[0]['status'] = 1;
            $normalUser[0]['link_superior_user'] = $userInfo['link_superior_user'];
            $normalUser[0]['divide_level'] = 1;
            $normalUser[0]['recordPerformance'] = true;
            foreach ($linkUserParent as $key => $value) {
                $linkUserParent[$key]['divide_level'] += 1;
                //剔除不是会员的上级
                if ($value['level'] <= 0) {
                    unset($linkUserParent[$key]);
                }
            }
            $linkUserParent = array_merge_recursive($normalUser, $linkUserParent);
        } else {
            foreach ($linkUserParent as $key => $value) {
                //剔除不是会员的上级
                if ($value['level'] <= 0) {
                    unset($linkUserParent[$key]);
                }
            }
        }

        //如果产生了跨级,则修改当前最高等级(最低level值),然后判断后续的是否低于level值,低于则还是为跨级订单
        $nowLevel = $userInfo['vip_level'] ?? 0;
        foreach ($linkUserParent as $key => $value) {
            if ($value['level'] > 0) {
                $linkUserParent[$key]['recordPerformance'] = false;
                if ((!empty($nowLevel) && $value['level'] <= $nowLevel) || (empty($nowLevel) && $value['level'] > $nowLevel)) {
                    $linkUserParent[$key]['recordPerformance'] = true;
                    if ((!empty($nowLevel) && $value['level'] < $nowLevel) || (empty($nowLevel) && $value['level'] > $nowLevel)) {
                        $nowLevel = $value['level'];
                    }
                }
            }
        }

        //获取每个等级的成本价,为了拓展后续 <如果同个筛选时间段内同个用户产生了不同等级的业绩,要强行恢复到某一个等级成本价> 这个功能
        foreach ($orderGoods as $key => $value) {
            $orderGoods[$key]['newVdc'] = [];
            if (!empty($value['vdc'])) {
                foreach ($value['vdc'] as $vKey => $vValue) {
                    $orderGoods[$key]['newVdc'][$vValue['level']] = $vValue;
                    $orderGoods[$key]['all_level_purchase_price'][$vValue['level']] = $vValue['purchase_price'];
                }
            }
        }

        $record = [];
        $count = 0;
        $res = false;
        foreach ($orderGoods as $key => $value) {
            foreach ($linkUserParent as $lKey => $lValue) {
//                if (!empty($value['newVdc'][$lValue['level']])) {
                $record[$count]['order_sn'] = $orderSn;
                $record[$count]['order_uid'] = $userInfo['uid'];
                $record[$count]['order_user_level'] = $userInfo['vip_level'] ?? 0;
                $record[$count]['order_user_phone'] = $userInfo['phone'] ?? null;
                $record[$count]['belong'] = $orderInfo['order_belong'];
                $record[$count]['order_type'] = $orderInfo['order_type'] ?? $value['order_type'] ?? 1;
                if ($lValue['uid'] == $userInfo['uid']) {
                    $record[$count]['type'] = 2;
                } else {
                    $record[$count]['type'] = 1;
                }
                $record[$count]['step_over_level'] = empty($lValue['recordPerformance']) ? 1 : 2;
                $record[$count]['goods_sn'] = $value['goods_sn'];
                $record[$count]['sku_sn'] = $value['sku_sn'];
                $record[$count]['price'] = $value['price'];
                $record[$count]['count'] = $value['count'];
                $record[$count]['total_price'] = $value['total_price'];
                $record[$count]['sale_price'] = $value['sale_price'];
                $record[$count]['all_dis'] = $value['all_dis'];
                $record[$count]['total_fare_price'] = $value['total_fare_price'];
                $record[$count]['real_pay_price'] = $value['real_pay_price'];
                if (!empty($value['newVdc'][$lValue['level']])) {
                    $nowVdc = $value['newVdc'][$lValue['level']];
                    $record[$count]['purchase_price'] = $nowVdc['purchase_price'];
                } else {
                    $record[$count]['purchase_price'] = $value['price'];
                }
                $record[$count]['link_uid'] = $lValue['uid'];
                $record[$count]['link_user_level'] = $lValue['level'];
                $record[$count]['link_user_phone'] = $lValue['user_phone'] ?? null;
                $record[$count]['link_user_team_level'] = $lValue['divide_level'];
                if (!empty($value['all_level_purchase_price'])) {
                    $record[$count]['goods_level_vdc'] = json_encode($value['all_level_purchase_price'], JSON_UNESCAPED_UNICODE);
                }
                $record[$count]['record_status'] = 1;
                if (!empty($orderInfo['create_time'])) {
                    if (is_numeric($orderInfo['create_time'])) {
                        $record[$count]['order_create_time'] = $orderInfo['create_time'];
                    } else {
                        $record[$count]['order_create_time'] = strtotime($orderInfo['create_time']);
                    }
                } else {
                    $record[$count]['order_create_time'] = time();
                }

                $count++;
//                }
            }
        }
        if (!empty($record)) {
            $res = (new TeamPerformance())->saveAll($record);
            $log['DBRes'] = $res->toArray();
        }

        //记录日志
        if (!empty($res)) {
            $log['msg'] = '订单 ' . ($orderSn ?? "<暂无订单编号>") . " [ 团队订单记录服务记录成功-全团包含用户查询 ]";
            $this->log($log, 'info');
        }

        return judge($res);
    }

    /**
     * @title  体验中心等级分润
     * @param array $data
     * @throws \Exception
     * @return mixed
     */
    public function divideTeamOrderForExp(array $data)
    {
        $log['requestData'] = $data;
        if (empty($data)) {
            return $this->recordError($log, ['msg' => '传入参数为空']);
        }


        $orderSn = $data['order_sn'];
        $orderInfo = $data['orderInfo'];
        if (empty($orderInfo)) {
            return $this->recordError($log, ['msg' => '暂无有效的订单详情']);
        }

        //判断是否有过分润记录
        $exist = Divide::where(['order_sn' => $orderSn, 'type' => 5, 'is_exp' => 1, 'status' => 1])->column('id');
        $log['existRecord'] = $exist;
        if (!empty($exist)) {
            return $this->recordError($log, ['msg' => '该订单已经存在体验中心分润记录啦']);
        }

        $orderGoodsMap[] = ['order_sn', '=', $orderSn];
        $orderGoodsMap[] = ['status', '=', 1];
        $orderGoodsMap[] = ['pay_status', '=', 2];
        $orderGoods = OrderGoods::with(['vdc'])->where(function($query){
            $whereOr1[] = ['activity_sign', 'in', (new TeamMemberService())->activitySign];
            $whereOr2[] = ['', 'exp', Db::raw('crowd_code is not null and crowd_round_number is not null and crowd_period_number is not null')];
            $query->whereOr([$whereOr1,$whereOr2]);
        })->where($orderGoodsMap)->withoutField('id,images,specs,desc,create_time,update_time')->select()->toArray();
        $log['orderGoods'] = $orderGoods;

        if (empty($orderGoods)) {
            return $this->recordError($log, ['msg' => '[体验中心分润] 不存在可以计算分润的正常状态的商品']);
        }
        $allTotalPrice = 0;
        foreach ($orderGoods as $key => $value) {
            $allTotalPrice += ($value['real_pay_price'] - $value['total_fare_price']);
        }

        $orderUid = $orderInfo['uid'];
        $userInfo = User::where(['uid' => $orderUid])->field('uid,phone,name,vip_level,link_superior_user,exp_level')->findOrEmpty()->toArray();
        $log['userInfo'] = $userInfo ?? [];
        if (empty($userInfo)) {
            return $this->recordError($log, ['msg' => '无有效用户信息']);
        }
        $searUid = $userInfo['link_superior_user'] ?? null;
        if (empty($searUid)) {
            return $this->recordError($log, ['msg' => '暂无可查找的有效本人或上级']);
        }

        $notMyself = $data['notMySelf'] ?? false;
        $otherMapSql = '';
        if (!empty($notMyself)) {
            $otherMapSql = "AND u2.uid != '" . $searUid . "'";
        }

        //查找这个用户的上级
        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
        if (!empty($databaseVersion) && is_numeric($databaseVersion) && $databaseVersion > 8016) {
            //mysql 8.0.16以上用此方法
            $linkUserParent = Db::query("SELECT u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level,u2.exp_level,u2.team_shareholder_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user,(SELECT @id := " . "'" . $searUid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 and (u2.exp_level > 0 or u2.team_shareholder_level > 0) " . $otherMapSql . " ORDER BY u1.LEVEL ASC;");
        } else {
            //mysql 8.0.16及以下用此方法
            $linkUserParent = Db::query("SELECT u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level,u2.exp_level,u2.team_shareholder_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user,(SELECT @id := " . "'" . $searUid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 and (u2.exp_level > 0 or u2.team_shareholder_level > 0) " . $otherMapSql . " ORDER BY u1.LEVEL ASC;");
        }

        $log['linkUserParent'] = $linkUserParent;
        if (empty($linkUserParent)) {
            return $this->recordError($log, ['msg' => '暂无可记录的有效上级']);
        }
        //体验中心每个等级只能取一个人
        if (count($linkUserParent) >= 1) {
            $newLinkUserParent = [];
            $teamExistLevel = [];
            $teamShareholderUser = [];

            //体验中心一级单独找第一个人出来
            foreach ($linkUserParent as $key => $value) {
                if ($value['exp_level'] > 0) {
                    if($value['exp_level'] != 4){
                        $value['exp_level'] = 4;
                    }
                    if (empty($existLevel[$value['exp_level']] ?? false)) {
                        $value['is_team_shareholder'] = false;
                        $newLinkUserParent[] = $value;
                        $UserExpLevel = $value['exp_level'] ?? 4;
                        $existLevel[$value['exp_level']] = $value;
                        break;
                    }
                }
            }

            foreach ($linkUserParent as $key => $value) {
                if ($value['team_shareholder_level'] > 0) {
                    if (empty($teamExistLevel[$value['team_shareholder_level']] ?? false)) {
                        $value['is_team_shareholder'] = true;
                        $newLinkUserParent[] = $value;
                        $teamUserExpLevel = $value['team_shareholder_level'] ?? 4;
                        $teamExistLevel[$value['team_shareholder_level']] = $value;
                        break;
                    }
                }
            }

            if (empty($newLinkUserParent)) {
                return $this->recordError($log, ['msg' => '筛选后查无可记录的有效上级']);
            }
            $linkUserParent = $newLinkUserParent;
        }

        $number = 0;
//        $toggleScale = CrowdfundingSystemConfig::where(['id' => 1])->value('toggle_scale');
//        $toggleScale = ExpMemberVdc::where(['status' => 1, 'level' => $UserExpLevel ?? 0])->value('vdc_one') ?? 0;
        $expMemberVdc = ExpMemberVdc::where(['status'=>1])->column('vdc_one','level');
        $expMemberVdc[0] = 0;
        $teamShareholderMemberVdc = TeamShareholderMemberVdc::where(['status'=>1])->column('vdc_one','level');
        $teamShareholderMemberVdc[0] = 0;
        foreach ($orderGoods as $key => $value) {
            foreach ($linkUserParent as $uKey => $uValue) {
                $divide[$number]['order_belong'] = $orderInfo['order_belong'] ?? 1;
                $divide[$number]['order_uid'] = $orderInfo['uid'];
                $divide[$number]['type'] = 5;
                $divide[$number]['order_sn'] = $orderSn;
                $divide[$number]['goods_sn'] = $value['goods_sn'];
                $divide[$number]['sku_sn'] = $value['sku_sn'];
                $divide[$number]['price'] = $value['price'];
                $divide[$number]['count'] = $value['count'];
                $divide[$number]['total_price'] = $value['total_price'];
                $divide[$number]['link_uid'] = $uValue['uid'];
                if (!empty($uValue['is_team_shareholder'] ?? false)) {
                    $divide[$number]['level'] = $uValue['team_shareholder_level'] ?? 4;
                } else {
                    $divide[$number]['level'] = $uValue['exp_level'] ?? 4;
                }

                if ($orderInfo['order_type'] == 6) {
                    $divide[$number]['crowd_code'] = $value['crowd_code'] ?? null;
                    $divide[$number]['crowd_round_number'] = $value['crowd_round_number'] ?? null;
                    $divide[$number]['crowd_period_number'] = $value['crowd_period_number'] ?? null;
                }
//                if($divide[$number]['level'] == 3){
                if (!empty($uValue['is_team_shareholder'] ?? false)) {
                    $divide[$number]['remark'] = '团队股东奖励';
                } else {
                    $divide[$number]['remark'] = '体验中心奖励';
                }
                $divide[$number]['purchase_price'] = $value['total_price'];
                $divide[$number]['vdc_genre'] = 2;
                $divide[$number]['dis_reduce_price'] = '0.00';
                $divide[$number]['is_vip_divide'] = 2;
                if (empty($uValue['is_team_shareholder'] ?? false)) {
                    $divide[$number]['vdc'] = $expMemberVdc[$uValue['exp_level']] ?? 0;
                } else {
                    $divide[$number]['vdc'] = $teamShareholderMemberVdc[$uValue['team_shareholder_level']] ?? 0;
                }

                $divide[$number]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']) * $divide[$number]['vdc']);
                $divide[$number]['real_divide_price'] = $divide[$number]['divide_price'];
                $divide[$number]['is_exp'] = 1;
//                $divide[$number]['level'] = 1;
                $divide[$number]['divide_type'] = 2;
                $divide[$number]['arrival_status'] = 2;
                $divide[$number]['level_sort'] = 0;
                $divide[$number]['divide_sort'] = 0;
                if (!empty($uValue['is_team_shareholder'] ?? false)) {
                    $divide[$number]['team_shareholder_level'] = $uValue['team_shareholder_level'] ?? 0;
                }
                $number ++;
            }

        }

        $res = false;
        if(!empty($divide ?? [])){
            $res = Db::transaction(function() use ($divide){
                $res = (new Divide())->saveAll(array_values($divide));
                return $res;
            });
        }

        return judge($res);
    }

    /**
     * @title  记录错误并保存日志,删除该任务后终止
     * @param array $data 所有数据
     * @param array $error 错误内容
     * @return bool
     */
    public function recordError(array $data, array $error)
    {
        $allData['msg'] = '订单 ' . ($data['requestData']['order_sn'] ?? "<暂无订单编号>") . " [ 团队订单记录服务出错: " . ($error['msg'] ?? '原因未知') . " ] ";
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
        return (new Log())->setChannel('team')->record($data, $level);
    }
}