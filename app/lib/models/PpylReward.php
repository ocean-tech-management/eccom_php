<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\PpylException;
use think\facade\Db;

class PpylReward extends BaseModel
{
    /**
     * @title  拼拼有礼奖励列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if (!empty($sear['arrival_status'])) {
            $map[] = ['arrival_status', 'in', $sear['arrival_status']];
        }
        if (!empty($sear['order_sn'])) {
            $map[] = ['order_sn', '=', $sear['order_sn']];
        }

        if (!empty($sear['username'])) {
            $map[] = ['link_uid', 'in', $this->getIdsByTeamLeaderName($sear['username'])];
        }

        if (!empty($sear['goods_sn'])) {
            $map[] = ['goods_sn', '=', $sear['goods_sn']];
        }

        if ($this->module == 'api') {
            $map[] = ['order_uid', '=', $sear['uid']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }


        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        $field = $this->getListFieldByModule();

        if (!empty($page)) {
            $total = self::where($map)->group('order_sn')->count();
            $pageTotal = ceil($total / $this->pageNumber);
        }

        $vipName = MemberVdc::where(['status' => 1])->column('name', 'level');
        $vipName[0] = '普通用户';
        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->group('order_sn')
            ->with(['user', 'orderUser'])
            ->order('create_time desc')
            ->select()->each(function ($item) use ($vipName) {
                $item['level'] = $vipName[$item['level']] ?? '未知等级';
                $item['type'] = $this->getTypeText($item['type']);
                $item['arrival_status'] = $this->getArrivalStatusText($item['arrival_status']);
                if (!empty($item['user'])) {
                    if (!trim($item['user']['name'])) $item['user']['name'] = '默认用户';
                }
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  拼拼有礼奖励列表(导出列表)
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function exportList(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if (!empty($sear['arrival_status'])) {
            $map[] = ['arrival_status', 'in', $sear['arrival_status']];
        }
        if (!empty($sear['order_sn'])) {
            $map[] = ['order_sn', '=', $sear['order_sn']];
        }

        if (!empty($sear['username'])) {
            $map[] = ['link_uid', 'in', $this->getIdsByTeamLeaderName($sear['username'])];
        }

        if (!empty($sear['goods_sn'])) {
            $map[] = ['goods_sn', 'in', $sear['goods_sn']];
        }

        if ($this->module == 'api') {
            $map[] = ['order_uid', '=', $sear['uid']];
        }
        //方法1 只查订单原始的本人推荐,后续联表补齐直推间推,是一条订单合并的数据
        $map[] = ['type','=',1];

        //方法2 查用户的所有推荐和他自己本人的,不是一条订单合并的数据(逻辑在下面)


        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        //方法1
        $field = 'type,order_sn,total_price,link_uid,arrival_status,create_time,order_uid,level,real_reward_price as myself_reward_price,goods_sn,sku_sn,freed_status,arrival_status';

        //方法2
//        $field = 'type,order_sn,total_price,link_uid,reward_price,arrival_status,create_time,order_uid,level,reward_price as myself_reward_price,goods_sn,sku_sn,freed_status,arrival_status';

        if (!empty($page)) {
            $total = self::where($map)->group('order_sn')->count();
            $pageTotal = ceil($total / $this->pageNumber);
        }

        $vipName = MemberVdc::where(['status' => 1])->column('name', 'level');
        $vipName[0] = '普通用户';
        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->group('order_sn')
            ->with(['user', 'orderUser', 'topUserOrder' => function ($query) {
                $query->field('order_sn,goods_sn,type,sku_sn,type,link_uid,reward_base_price,reward_price,real_reward_price,arrival_status,freed_status,vdc_level')->order('id asc');
            }, 'orderGoods' => function ($query) {
                $query->field('goods_sn,sku_sn,title,images,specs,total_price');
            }])
            ->withSum(['allOrder' => 'reward_price'], 'real_reward_price')
            ->order('create_time desc')
            ->select()->each(function ($item) use ($vipName) {
                $item['level'] = $vipName[$item['level']] ?? '未知等级';
                $item['type'] = $this->getTypeText($item['type']);
                $item['arrival_status'] = $this->getArrivalStatusText($item['arrival_status']);
                if (!empty($item['user'])) {
                    if (!trim($item['user']['name'])) $item['user']['name'] = '默认用户';
                }
                if ($item['type'] == 2) {
                    $item['topUserOrder'] = [];
                }
                if (!empty($item['topUserOrder'] ?? null)) {
                    foreach ($item['topUserOrder'] as $key => $value) {
                        $item['topUserOrder'][$key]['arrival_status'] = $this->getArrivalStatusText($value['arrival_status']);
                        $item['topUserOrder'][$key]['type'] = $this->getArrivalStatusText($value['type']);
                    }
                }
                return $item;
            })->toArray();

        //补充上级用户信息
        if (!empty($list)) {
            $topUserUid = [];
            foreach ($list as $key => $value) {
                if (!empty($value['topUserOrder'] ?? null)) {
                    if (!empty($topUserUid)) {
                        $topUserUid = array_merge_recursive($topUserUid, array_column($value['topUserOrder'], 'link_uid'));
                    } else {
                        $topUserUid = array_column($value['topUserOrder'], 'link_uid');
                    }
                }
            }
            if (!empty($topUserUid)) {
                $topUserUid = array_unique($topUserUid);
                $topUserList = User::where(['uid' => $topUserUid])->field('uid,phone,name,vip_level')->select()->each(function ($item) use ($vipName) {
                    $item['level'] = $vipName[$item['vip_level']] ?? '未知等级';
                })->toArray();
                if (!empty($topUserList)) {
                    foreach ($topUserList as $key => $value) {
                        $topUserInfo[$value['uid']] = $value;
                    }
                    foreach ($list as $key => $value) {
                        if (!empty($value['topUserOrder'] ?? null)) {
                            foreach ($value['topUserOrder'] as $tKey => $tValue) {
                                if (!empty($topUserInfo[$tValue['link_uid']] ?? null)) {
                                    $list[$key]['topUserOrder'][$tKey]['name'] = $topUserInfo[$tValue['link_uid']]['name'];
                                    $list[$key]['topUserOrder'][$tKey]['phone'] = $topUserInfo[$tValue['link_uid']]['phone'];
                                    $list[$key]['topUserOrder'][$tKey]['level'] = $topUserInfo[$tValue['link_uid']]['level'];
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
     * @title  获取分润记录列表
     * @param array $sear 搜索条件
     * @return array
     * @throws \Exception
     */
    public function recordList(array $sear)
    {
        $map = [];
        if (!empty($sear['order_sn'])) {
            $map[] = ['order_sn', 'like', ['%' . $sear['order_sn'] . '%']];
        }

        if (!empty($sear['status'])) {
            $map[] = ['arrival_status', '=', $sear['status']];
        }


        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;

        $field = $this->getListFieldByModule();

        if (!empty($page)) {
            $total = Db::name('divide')->where($map)->group('order_sn')->count();
            $pageTotal = ceil($total / $this->pageNumber);
        }
        $vipName = MemberVdc::where(['status' => 1])->column('name', 'level');
        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->group('order_sn')
            ->with(['leader', 'leader.user'])
            ->order('create_time desc')
            ->select()->each(function ($item) use ($vipName) {
                $item['level'] = $vipName[$item['level']] ?? '未知等级';
                $item['type'] = $this->getTypeText($item['is_vip_divide']);
                $item['arrival_status'] = $this->getArrivalStatusText($item['arrival_status']);
                if (!empty($item['user'])) {
                    if (!trim($item['user']['name'])) $item['user']['name'] = '默认用户';
                }
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  获取分润详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function recordDetail(array $data)
    {
        $map = [];
        $field = $this->getListFieldByModule();
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['order_sn', '=', $data['order_sn']];
        $row = $this->with(['user'])->where($map)
            ->field($field)
            ->findOrEmpty()->toArray();

//        sum 会导致无数据也返回模型
        if (!trim($row['reward_price'])) return [];
        $vipName = MemberVdc::where(['status' => 1])->column('name', 'level');
        $vipName[0] = '普通用户';

        $row['records'] = $this->with(['user', 'orderGoods' => function ($query) {
            $query->field('goods_sn,sku_sn,title,images,specs');
        }])->where($map)
            ->field('type,level,link_uid,reward_base_price,vdc,reward_price,dis_reduce_price,refund_reduce_price,real_reward_price,remark,goods_sn,sku_sn')
            ->select()->each(function (&$item) use ($vipName) {
                $item['level'] = $vipName[$item['level']];
                if (!empty($item['user'])) {
                    if (!trim($item['user']['name'])) $item['user']['name'] = '默认用户';
                }

                return $item;
            })->toArray();

        $row['arrival_status'] = $this->getArrivalStatusText($row['arrival_status']);
        $row['type'] = $this->getTypeText($row['type']);
        $row['username'] = $row['user']['name'];

        return $row;
    }

    /**
     * @title  拼拼有礼奖励列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cList(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if (!empty($sear['arrival_status'])) {
            $map[] = ['arrival_status', 'in', $sear['arrival_status']];
        } else {
            $map[] = ['arrival_status', 'in', [1,2,3,-2]];
        }

        if (!empty($sear['username'])) {
            $map[] = ['link_uid', 'in', $this->getIdsByTeamLeaderName($sear['username'])];
        }

        if (!empty($sear['goods_sn'])) {
            $map[] = ['goods_sn', '=', $sear['goods_sn']];
        }

        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
        }

        $map[] = ['link_uid', '=', $sear['uid']];

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }


        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        $field = $this->getListFieldByModule();

        if (!empty($page)) {
            $total = self::where($map)->count();
            $pageTotal = ceil($total / $this->pageNumber);
        }

        $vipName = MemberVdc::where(['status' => 1])->column('name', 'level');
        $vipName[0] = '普通用户';

        $list = $this->where($map)->field('order_sn,order_uid,type,goods_sn,sku_sn,price,total_price,link_uid,reward_base_price,reward_price,dis_reduce_price,refund_reduce_price,real_reward_price,arrival_status,status,create_time,grant_time,receive_time,arrival_time,freed_type,freed_limit_start_time,freed_limit_end_time,freed_time,remark,level,freed_status')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })
            ->with(['orderUser', 'orderGoods' => function ($query) {
                $query->field('goods_sn,sku_sn,title,images,specs,total_price,real_pay_price,sale_price');
            }])
            ->order('create_time desc')
            ->select()->each(function ($item) use ($vipName) {
                $item['level'] = $vipName[$item['level']] ?? '未知等级';
//                $item['type'] = $this->getTypeText($item['type']);
//                $item['arrival_status'] = $this->getArrivalStatusText($item['arrival_status']);
                if (!empty($item['grant_time'])) {
                    $item['grant_time'] = timeToDateFormat($item['grant_time']);
                }
                if (!empty($item['receive_time'])) {
                    $item['receive_time'] = timeToDateFormat($item['receive_time']);
                }
                if (!empty($item['freed_time'])) {
                    $item['freed_time'] = timeToDateFormat($item['freed_time']);
                }
                if (!empty($item['freed_limit_end_time'])) {
                    $item['freed_limit_end_time'] = timeToDateFormat($item['freed_limit_end_time']);
                }
                if (!empty($item['freed_limit_start_time'])) {
                    $item['freed_limit_start_time'] = timeToDateFormat($item['freed_limit_start_time']);
                }
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  拼拼有礼奖励汇总数据
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cListSummary(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $map[] = ['arrival_status', 'in', [3]];
        if (!empty($sear['order_sn'])) {
            $map[] = ['order_sn', '=', $sear['order_sn']];
        }

        if (!empty($sear['username'])) {
            $map[] = ['link_uid', 'in', $this->getIdsByTeamLeaderName($sear['username'])];
        }

        if (!empty($sear['goods_sn'])) {
            $map[] = ['goods_sn', '=', $sear['goods_sn']];
        }

        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
        }

        $map[] = ['link_uid', '=', $sear['uid']];

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }


        $vipName = MemberVdc::where(['status' => 1])->column('name', 'level');
        $vipName[0] = '普通用户';

        $list = $this->where($map)->field('link_uid as uid,create_time,sum(if(freed_status = 2 and status = 1,1,0)) as wait_activation_number,sum(if(arrival_status = 3 and status = 1,1,0)) as wait_arrival_number')
            ->order('create_time desc')
            ->group('link_uid')
            ->select()->toArray();

        if (empty($list)) {
            $finally['uid'] = $sear['uid'];
            $finally['wait_activation_number'] = 0;
            $finally['wait_arrival_number'] = 0;
            $finally['all'] = 0;
        } else {
            $finally = current($list);
            if (!empty($sear['type']) && $sear['type'] == 1) {
                $finally['wait_activation_number'] = 0;
            }
            $finally['all'] = ($finally['wait_activation_number'] ?? 0) + ($finally['wait_arrival_number'] ?? 0);
        }
        return $finally ?? [];
    }

    /**
     * @title  拼拼有礼奖励分组汇总数据
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cListSummaryGroup(array $sear = []): array
    {

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $map[] = ['arrival_status', 'in', [1, 2]];

        $map[] = ['link_uid', '=', $sear['uid']];

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }


        $vipName = MemberVdc::where(['status' => 1])->column('name', 'level');
        $vipName[0] = '普通用户';

        $info = $this->where($map)->field('link_uid as uid,sum(if(type = 1 and status = 1,real_reward_price,0)) as myself_price,sum(if(type = 1 and status = 1,1,0)) as myself_number,sum(if(type = 2 and status = 1,1,0)) as top_number,sum(if(type = 2 and status = 1,real_reward_price,0)) as top_price')->findOrEmpty()->toArray();
        $finally = [];
        if (!empty($info)) {
            $finally[0]['type'] = 1;
            $finally[0]['price'] = priceFormat($info['myself_price'] ?? 0);
            $finally[0]['number'] = $info['myself_number'];
            $finally[1]['type'] = 2;
            $finally[1]['price'] = priceFormat($info['top_price'] ?? 0);
            $finally[1]['number'] = $info['top_number'];
        }
        return $finally ?? [];
    }

    /**
     * @title  领取红包
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function receiveReward(array $data)
    {
        $uid = $data['uid'];
        $orders = $data['orders'];
        $orderSn = array_unique(array_column($orders, 'order_sn'));
        $rMap[] = ['link_uid', '=', $uid];
        $rMap[] = ['order_sn', 'in', $orderSn];
        $rMap[] = ['arrival_status', '=', 3];
        $rMap[] = ['status', '=', 1];
        $rewardInfo = self::where($rMap)->select()->toArray();
        if (empty($rewardInfo)) {
            return false;
        }
        //剔除没有奖励的订单或者没有的类型订单
        foreach ($rewardInfo as $key => $value) {
            $rewardInfo[$value['order_sn']][$value['type']] = $value;
        }
        foreach ($orders as $key => $value) {
            if (empty($rewardInfo[$value['order_sn']] ?? []) || (!empty($rewardInfo[$value['order_sn']] ?? []) && empty($rewardInfo[$value['order_sn']][$value['type']] ?? []))) {
                unset($orders[$key]);
                continue;
            }
            $orders[$key]['freed_limit_start_time'] = $rewardInfo[$value['order_sn']][$value['type']]['freed_limit_start_time'];
            $orders[$key]['freed_limit_end_time'] = $rewardInfo[$value['order_sn']][$value['type']]['freed_limit_end_time'];
        }
        if (empty($orders)) {
            return false;
        }
        //判断是否可以领取
        foreach ($orders as $key => $value) {
            $ppMap = [];
            $orders[$key]['canReceive'] = true;
            if ($value['type'] == 2 && $rewardInfo[$value['order_sn']][$value['type']]['freed_type'] == 1) {
                $orders[$key]['canReceive'] = false;
                $timeStart = $value['freed_limit_start_time'];
                $timeEnd = $value['freed_limit_end_time'];
                $ppMap[] = ['create_time', '>=', $timeStart];
                $ppMap[] = ['create_time', '<=', $timeEnd];
                $ppMap[] = ['activity_status', 'in', [2, -3]];
                $ppMap[] = ['uid', '=', $uid];
                $orderNumber = PpylOrder::where($ppMap)->count();
                if (!empty($orderNumber)) {
                    $orders[$key]['canReceive'] = true;
                }else{
                    throw new PpylException(['msg' => '订单' . $value['order_sn'] . '不符合领取条件哟~']);
                }
            }
        }

        //查看红包奖励冻结规则
        $rewardRule = PpylConfig::where(['status' => 1])->field('frozen_reward_time,top_reward_receive_order_number,top_reward_receive_type,freed_expire_time')->findOrEmpty()->toArray();
        $DBRes = Db::transaction(function () use ($rewardRule, $orders, $uid) {
            if (!empty($orders)) {
                foreach ($orders as $key => $value) {
                    if (!empty($value['canReceive'] ?? false))
                        $pplyTopUser[$key]['arrival_status'] = 2;
                    $pplyTopUser[$key]['grant_time'] = time() + $rewardRule['frozen_reward_time'];
                    $pplyTopUser[$key]['receive_time'] = time();
                    $res[] = self::update($pplyTopUser[$key], ['order_sn' => $value['order_sn'], 'type' => $value['type'], 'link_uid' => $uid, 'arrival_status' => 3]);
                }
            }
            return $res ?? [];
        });

        return judge($DBRes);

    }

    /**
     * @title  自动激活上级推荐红包
     * @param array $data
     * @return bool
     * @throws \Exception
     * @remark 建议队列执行, 执行时间可能比较久
     */
    public function autoReceiveTopReward(array $data)
    {
        $uid = $data['uid'];
        $userInfo = User::where(['uid' => $uid, 'status' => 1])->field('uid,phone,vip_level,c_vip_level,c_vip_time_out_time,link_superior_user,auto_receive_reward')->findOrEmpty()->toArray();
        $rMap[] = ['link_uid', '=', $uid];
        $rMap[] = ['freed_status', '=', 2];
        $rMap[] = ['arrival_status', '=', 3];
        $rMap[] = ['status', '=', 1];
        $rewardInfo = self::where($rMap)->select()->toArray();

        if (empty($rewardInfo)) {
            return false;
        }

        $orders = $rewardInfo;
        if (empty($orders)) {
            return false;
        }
        //查看红包奖励冻结规则
        $rewardRule = PpylConfig::where(['status' => 1])->field('frozen_reward_time,top_reward_receive_order_number,top_reward_receive_type,freed_expire_time')->findOrEmpty()->toArray();
        $limitNumber = $rewardRule['top_reward_receive_order_number'] ?? 0;

        //判断是否可以领取
        foreach ($orders as $key => $value) {
            $ppMap = [];
            $orders[$key]['canReceive'] = true;
            if ($value['type'] == 2 && $value['freed_type'] == 1) {
                $orders[$key]['canReceive'] = false;
                $timeStart = $value['freed_limit_start_time'];
                $timeEnd = $value['freed_limit_end_time'];
                $ppMap[] = ['create_time', '>=', $timeStart];
                $ppMap[] = ['create_time', '<=', $timeEnd];
                $ppMap[] = ['activity_status', 'in', [2, -3]];
                $ppMap[] = ['uid', '=', $value['link_uid']];
                $orderNumber = PpylOrder::where($ppMap)->count();

                if (!empty($orderNumber) && $orderNumber >= ($limitNumber ?? 0)) {
                    $orders[$key]['canReceive'] = true;
                }
            }
        }

        $DBRes = Db::transaction(function () use ($rewardRule, $orders, $uid, $userInfo) {
            if (!empty($orders)) {
                foreach ($orders as $key => $value) {
                    if (!empty($value['canReceive'] ?? false)) {
                        $pplyTopUser[$key]['freed_status'] = 1;
                        //会员开启自动领取的则自动领取,否则只是激活
                        if (!empty($userInfo['c_vip_level']) && $userInfo['c_vip_time_out_time'] > time() && $userInfo['auto_receive_reward'] == 1) {
                            $pplyTopUser[$key]['receive_time'] = time();
                            $pplyTopUser[$key]['grant_time'] = time() + $rewardRule['frozen_reward_time'];
                            $pplyTopUser[$key]['arrival_status'] = 2;
                        }
                        $res[] = self::update($pplyTopUser[$key], ['reward_sn' => $value['reward_sn'], 'order_sn' => $value['order_sn'], 'type' => $value['type'], 'link_uid' => $uid, 'arrival_status' => 3]);
                    }
                }
            }
            return $res ?? [];
        });

        return judge($DBRes);

    }

    /**
     * @title  一键领取上级推荐奖励
     * @param array $data
     * @return bool
     * @throws \Exception
     * @remark 建议队列执行, 执行时间可能比较久
     */
    public function quicklyReceiveReward(array $data)
    {
        $uid = $data['uid'];
        $type = $data['type'] ?? 1;
        $userInfo = User::where(['uid' => $uid, 'status' => 1])->field('uid,phone,vip_level,c_vip_level,c_vip_time_out_time,link_superior_user,auto_receive_reward')->findOrEmpty()->toArray();
        $rMap[] = ['link_uid', '=', $uid];
        $rMap[] = ['type', '=', $type];
        $rMap[] = ['status', '=', 1];
        if ($type == 1) {
            $rMap[] = ['arrival_status', '=', 3];
        } else {
            $rMap[] = ['freed_status', '=', 1];
            $rMap[] = ['arrival_status', '=', 3];
        }

        $rewardInfo = self::where($rMap)->select()->toArray();

        if (empty($rewardInfo)) {
            return false;
        }

        $orders = $rewardInfo;
        if (empty($orders)) {
            return false;
        }
        //查看红包奖励冻结规则
        $rewardRule = PpylConfig::where(['status' => 1])->field('frozen_reward_time,top_reward_receive_order_number,top_reward_receive_type,freed_expire_time')->findOrEmpty()->toArray();

        //推荐奖励判断是否可以领取,个人奖励都可以领取
        if ($type == 2) {
            $limitNumber = $rewardRule['top_reward_receive_order_number'] ?? 0;

            //判断是否可以领取
            foreach ($orders as $key => $value) {
                $ppMap = [];
                $orders[$key]['canReceive'] = true;
                if ($value['type'] == 2 && $value['freed_type'] == 1) {
                    $orders[$key]['canReceive'] = false;
                    $timeStart = $value['freed_limit_start_time'];
                    $timeEnd = $value['freed_limit_end_time'];
                    $ppMap[] = ['create_time', '>=', $timeStart];
                    $ppMap[] = ['create_time', '<=', $timeEnd];
                    $ppMap[] = ['activity_status', 'in', [2, -3]];
                    $ppMap[] = ['uid', '=', $value['link_uid']];
                    $orderNumber = PpylOrder::where($ppMap)->count();

                    if (!empty($orderNumber) && $orderNumber >= ($limitNumber ?? 0)) {
                        $orders[$key]['canReceive'] = true;
                    }
                }
            }
        } else {
            foreach ($orders as $key => $value) {
                $orders[$key]['canReceive'] = true;
            }
        }


        $DBRes = Db::transaction(function () use ($rewardRule, $orders, $uid, $userInfo, $type) {
            if (!empty($orders)) {
                foreach ($orders as $key => $value) {
                    if (!empty($value['canReceive'] ?? false)) {
                        if ($type == 2) {
                            $pplyTopUser[$key]['freed_status'] = 1;
                        }
                        $pplyTopUser[$key]['receive_time'] = time();
                        $pplyTopUser[$key]['grant_time'] = time() + $rewardRule['frozen_reward_time'];
                        $pplyTopUser[$key]['arrival_status'] = 2;
                        $res[] = self::update($pplyTopUser[$key], ['reward_sn' => $value['reward_sn'], 'order_sn' => $value['order_sn'], 'type' => $value['type'], 'link_uid' => $uid, 'arrival_status' => 3]);
                    }
                }
            }
            return $res ?? [];
        });

        return judge($DBRes);

    }

    public function getIdsByTeamLeaderName(string $username)
    {
        $userList = Db::name('user')
            ->alias('u')
            ->where('u.name', 'like', '%' . $username . '%')
            ->join('member m', 'u.uid=m.uid')
            ->field(['u.uid as uid'])
            ->cache('getIdsByTeamLeaderName' . $username, 600)
            ->select()
            ->toArray();
        $ids = [];
        foreach ($userList as $item) {
            $ids[] = $item['uid'];
        }

        return $ids;
    }

    /**
     * @title  用户收益汇总,个人中心数据面板
     * @param array $sear
     * @return mixed
     * @throws \Exception
     */
    public function userRewardSummary(array $sear)
    {
        $map[] = ['link_uid', '=', $sear['uid']];
        if (!empty($sear['start_time'] ?? null) && !empty($sear['end_time'] ?? null)) {
            $startTime = strtotime($sear['start_time']);
            $endTime = strtotime($sear['end_time']);

        }
//        else {
//            $startTime = strtotime(date('Y-m-d',time()) . " 00:00:00");
//            $endTime = strtotime(date('Y-m-d',time()) . " 23:59:59");
//        }
        if (!empty($startTime) && !empty($endTime)) {
            $map[] = ['receive_time', '>=', $startTime];
            $map[] = ['receive_time', '<=', $endTime];
        }

        $map[] = ['arrival_status', 'in', [1, 2]];
        $map[] = ['status', '=', 1];
        $info = self::where($map)->field('order_sn,type,arrival_status,real_reward_price,receive_time,create_time,sum(if(type = 1,real_reward_price,0)) as myself_reward,sum(if(type = 2,real_reward_price,0)) as top_reward')->findOrEmpty()->toArray();

        if (!empty($startTime) && !empty($endTime)) {
            $sear['start_time'] = date('Y-m-d H:i:s', $startTime);
            $sear['end_time'] = date('Y-m-d H:i:s', $endTime);
        }

        $rewardNumber = $this->cListSummary($sear);
        $finally['myself_reward'] = $info['myself_reward'] ?? 0;
        $finally['top_reward'] = $info['top_reward'] ?? 0;
        $finally['reward_number'] = $rewardNumber['all'] ?? 0;
        return $finally;
    }

    private function getListFieldByModule(string $default = '')
    {
        switch ($default ? $default : $this->module) {
            case 'admin':
            case 'manager':
                $field = 'type,order_sn,total_price,link_uid,SUM(reward_price) as reward_price,arrival_status,create_time,order_uid,level';
                break;
            case 'api':
                $field = 'a.after_sale_sn,a.order_sn,a.order_real_price,a.uid,b.name as user_name,a.type,a.apply_reason,a.apply_status,a.apply_price,a.buyer_received_goods,a.verify_status,a.verify_reason,a.apply_time,a.verify_time,a.create_time,a.after_status,a.status,c.title as goods_title,c.images as goods_images,c.specs as goods_specs,c.count as goods_count,a.is_vip_divide';
                break;
            case 'list':
                $field = 'id,type,order_sn,total_price,link_uid,SUM(divide_price) as divide_price,arrival_status,create_time,is_vip_divide';
                break;
            default:
                $field = 'type,order_sn,total_price,link_uid,SUM(divide_price) as divide_price,arrival_status,is_vip_divide,create_time';
        }
        return $field;
    }

    private function getLevelText(int $level)
    {
        switch ($level) {
            case 1:
                $text = 'DT';
                break;
            case 2:
                $text = 'SV';
                break;
            case 3:
                $text = 'VIP';
                break;
            default:
                $text = '数据错误';
        }

        return $text;
    }

    private function getArrivalStatusText(int $status)
    {
        switch ($status) {
            case -1:
                $text = '整单被删除';
                break;
            case 1:
                $text = '到账';
                break;
            case 2:
                $text = '冻结中';
                break;
            case 3:
                $text = '待领取';
                break;
            case -2:
                $text = '超时释放';
                break;
            default:
                $text = '暂无分润';
        }

        return $text;
    }

    private function getTypeText(int $type)
    {
        switch ($type) {
            case 1:
                $text = '本人奖励';
                break;
            case 2:
                $text = '推荐奖励';
                break;
            default:
                $text = '暂无奖金';
        }

        return $text;
    }

    public function leader()
    {
        return $this->hasOne('divide', 'order_sn', 'order_sn')
            ->where('status', 'in', [1, 2])
            ->order('level', 'desc')->bind(['level', 'divide_type', 'purchase_price' => 'purchase_price', 'cost_price' => 'cost_price'])->hidden([
                'id', 'order_sn', 'order_uid', 'belong', 'type', 'goods_sn', 'sku_sn', 'price', 'count', 'vdc', 'vdc_genre', 'integral'
            ]);
    }

    public function user()
    {
        return $this->hasOne('user', 'uid', 'link_uid')->field('uid,name,phone,avatarUrl,c_vip_level,c_vip_time_out_time');
    }

    public function orderUser()
    {
        return $this->hasOne('user', 'uid', 'order_uid')->field('uid,name,phone,avatarUrl,c_vip_level,c_vip_time_out_time');
    }

    public function orderGoods()
    {
        return $this->hasOne('PpylOrderGoods', 'sku_sn', 'sku_sn');
    }

    public function topUserOrder()
    {
        return $this->hasMany(get_class($this), 'order_sn', 'order_sn')->where(['type' => 2])->order('id asc');
    }

    public function allOrder()
    {
        return $this->hasMany(get_class($this), 'order_sn', 'order_sn');
    }
}