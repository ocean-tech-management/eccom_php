<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 分销分润队列处理]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\job;


use app\lib\models\BalanceDetail;
use app\lib\models\GoodsSkuVdc;
use app\lib\models\GoodsSpu;
use app\lib\models\IntegralDetail;
use app\lib\models\Member;
use app\lib\models\Order;
use app\lib\models\OrderGoods;
use app\lib\models\Reward;
use app\lib\models\RewardFreed;
use app\lib\models\Subject;
use app\lib\models\User;
use app\lib\services\CodeBuilder;
use app\lib\services\Log;
use app\lib\services\Wx;
use think\facade\Db;
use think\facade\Queue;
use think\queue\Job;
use app\lib\models\Divide as DivideModel;

class Divide
{
    private $belong = 1;
    private $topTwoPullNewNumber = 5;   //成为二级分销拉人数量最低限制
    private $integralScale = 0.05;      //普通用户分销比例（返回积分）
    private $firstScale = 0.05;         //普通用户首单分销比例（返回金额）
    private $integralToMoneyScale = 100;
    //如果查询出来的一,二级分润比例大于等于了100%,视为非法参数,分润比例强行变成对应等级的默认比例
    private $defaultVdcOne = 0.08;
    private $defaultVdcTwo = 0.12;

    /**
     * @title  fire
     * @param Job $job
     * @param  $data
     * @return void
     * @throws \Exception
     */
    public function fire(Job $job, $data)
    {
        $res = false;
        $log['res'] = $res;
        $log['msg'] = '订单分润处理成功';
        $log['data'] = $data;
        if (!empty($data)) {
            $orderSn = $data['order_sn'];
            if (!empty($orderSn)) {
                $orderInfo = Db::name('order')->alias('a')
                    ->join('sp_user b', 'a.uid = b.uid', 'left')
                    ->field('a.*,b.name as user_name,b.vip_level,b.member_card,b.avaliable_balance,b.total_balance,b.divide_balance,b.link_superior_user')
                    ->where(['a.order_sn' => $orderSn])
                    ->findOrEmpty();
                if (!empty($orderInfo)) {
                    $mapBase = ['uid' => $orderInfo['uid']];
                    $cannotDivide = false;
                    //判断不可以进行分润操作的订单，因为订单状态尚未符合规则
                    switch ($this->belong) {
                        case 1:
                            if ($orderInfo['pay_status'] != 2 || !in_array($orderInfo['order_status'], [4]) || $orderInfo['vdc_allow'] == 2) {
                                $cannotDivide = true;
                            }
                            $mapMore = ['pay_status' => 2, 'order_status' => [4]];
                            break;
                        case 2:
                            if ($orderInfo['pay_status'] != 2 || $orderInfo['order_status'] != 2) {
                                $cannotDivide = true;
                            }
                            $mapMore = ['pay_status' => 2, 'order_status' => 2];
                            break;
                        default:
                            $cannotDivide = false;
                            $mapMore = ['pay_status' => 2, 'order_status' => 2];
                    }
                    $existDivide = DivideModel::where(['order_sn' => $orderSn, 'status' => [1, 2]])->count();
                    if ($cannotDivide || ($existDivide > 0)) {
                        $log['res'] = false;
                        $log['msg'] = '不合法的分销订单';
                        $this->log($log);
                        $job->delete();
                    }
                    //订单商品详情
                    $orderGoods = OrderGoods::where(['order_sn' => $orderSn])->field('goods_sn,sku_sn,user_level,price,count,total_price,title,vdc_allow')->select()->toArray();
                    $goodsTitle = array_column($orderGoods, 'title');
                    $goodsSku = array_column($orderGoods, 'sku_sn');
                    $goodsSpu = array_column($orderGoods, 'goods_sn');

                    $map = array_merge_recursive($mapBase, $mapMore);
                    //用户成功支付的订单(用来判断是否为首单)
                    $userOrderNum = Order::where($map)->count();
                    //用户会员等级
                    $userLevel = (new Member())->getUserLevel($orderInfo['uid']);

                    $topLevel = 0;
                    $topOneUser = null;
                    $topTwoUser = null;
                    //查看当前分销体系层级
                    $topOne = Member::where(['uid' => $orderInfo['link_superior_user'], 'status' => [1]])->field('uid,user_phone,member_card,level,link_superior_user')->findOrEmpty()->toArray();
                    //查看二级分销商
                    if (!empty($topOne)) {
                        $topLevel = 1;
                        $topOneUser = $topOne['uid'];
                        //查看是否有通过正常邀请成为的二级会员或指定的二级会员
                        $topTwo = Member::where(['link_superior_user' => $topOne['link_superior_user'], 'status' => [1]])->count();
                        $specialVip = Member::where(['uid' => $topOne['link_superior_user'], 'status' => [1], 'type' => 2, 'level' => 2])->value('member_card');
                        if (!empty($specialVip) || $topTwo >= $this->topTwoPullNewNumber) {
                            $topLevel = 2;
                            $topTwoUser = $topOne['link_superior_user'];
                        }
                    }
                    $allVdcOne = 0;
                    $allVdcTwo = 0;
                    $allIntegral = 0;
                    $aDivide = [];
                    $integral = [];
                    //存在上级关系式才计算分润
                    if (!empty($topLevel)) {
                        $goodsVdc = GoodsSkuVdc::where(['sku_sn' => $goodsSku, 'level' => $topLevel, 'status' => 1, 'belong' => $this->belong])->field('goods_sn,sku_sn,level,belong,vdc_type,vdc_one,vdc_two')->select()->toArray();
                        $count = 0;

                        foreach ($orderGoods as $goodsKey => $goodsValue) {
                            //仅允许分润的时候才计算分润
                            if ($goodsValue['vdc_allow'] == 1) {
                                foreach ($goodsVdc as $key => $value) {
                                    if ($goodsValue['sku_sn'] == $value['sku_sn']) {
                                        //获取两级分润详情数组,以便后续入库处理
                                        //先获取一级分润
                                        $aDivide[$count] = $value;
                                        $aDivide[$count]['belong'] = $this->belong;
                                        $aDivide[$count]['type'] = 1;
                                        $aDivide[$count]['level'] = 1;
                                        $aDivide[$count]['order_sn'] = $orderSn;
                                        $aDivide[$count]['order_uid'] = $orderInfo['uid'];
                                        $aDivide[$count]['price'] = $goodsValue['price'];
                                        $aDivide[$count]['count'] = $goodsValue['count'];
                                        $aDivide[$count]['total_price'] = $goodsValue['total_price'];
                                        $aDivide[$count]['vdc'] = $value['vdc_one'];
                                        $aDivide[$count]['link_uid'] = $topOneUser;
                                        if ($value['vdc_type'] == 2) {
                                            $aDivide[$count]['divide_price'] = $value['vdc_one'];
                                        } elseif ($value['vdc_type'] == 1) {
                                            if ($value['vdc_one'] >= 1) $value['vdc_one'] = $this->defaultVdcOne;
                                            $aDivide[$count]['divide_price'] = ($goodsValue['total_price'] * $value['vdc_one']);
                                        }

                                        //此订单用户为普通会员则享受首单抽成
                                        if (empty($userLevel)) {
                                            if ($userOrderNum == 1) {
                                                $aDivide[$count]['type'] = 3;
                                                $aDivide[$count]['vdc'] = $this->firstScale;
                                                $aDivide[$count]['link_uid'] = $topOneUser;
                                                $aDivide[$count]['divide_price'] = ($goodsValue['total_price'] * $this->firstScale);
                                                $allVdcOne += ($goodsValue['total_price'] * $this->firstScale);
                                            } else {
                                                $aDivide[$count]['type'] = 3;
                                                $aDivide[$count]['vdc'] = 0;
                                                $aDivide[$count]['link_uid'] = $topOneUser;
                                                $aDivide[$count]['divide_price'] = 0;
                                            }

                                        } else {
                                            //获取一级分销分润总价格
//                                        if($value['vdc_type'] == 2){
//                                            $allVdcOne += $value['vdc_one'];
//                                        }elseif($value['vdc_type'] == 1){
//                                            if($value['vdc_one'] >= 1) $value['vdc_one'] = $this->defaultVdcOne;
//                                            $allVdcOne += ($goodsValue['total_price'] * $value['vdc_one']);
//                                        }
                                            $allVdcOne += $aDivide[$count]['divide_price'];
                                        }

                                        //在获取二级分润
                                        if ($topLevel == 2) {
                                            $count++;
                                            $aDivide[$count] = $aDivide[$count - 1];
                                            $aDivide[$count]['type'] = 1;
                                            $aDivide[$count]['level'] = 2;
                                            $aDivide[$count]['vdc'] = $value['vdc_two'];
                                            $aDivide[$count]['link_uid'] = $topTwoUser;
                                            if ($value['vdc_type'] == 2) {
                                                $aDivide[$count]['divide_price'] = $value['vdc_two'];
                                            } elseif ($value['vdc_type'] == 1) {
                                                if ($value['vdc_two'] >= 1) $value['vdc_two'] = $this->defaultVdcTwo;
                                                $aDivide[$count]['divide_price'] = ($goodsValue['total_price'] * $value['vdc_two']);
                                            }
                                            //$aDivide[$count]['divide_price'] = ($goodsValue['total_price'] * $value['vdc_two']);
                                        }
                                        //获取二级分销分润总价格
                                        //$allVdcTwo += ($goodsValue['total_price'] * $value['vdc_two']);
                                        $allVdcTwo += $aDivide[$count]['divide_price'];

                                        $count++;
                                    }
                                }
                            }

                        }
                    } else {
                        //如果上级只是普通会员则享受单次积分抽成
                        if (!empty($orderInfo['link_superior_user']) && ($userOrderNum == 1)) {
                            $topOneUser = $orderInfo['link_superior_user'];
                            foreach ($orderGoods as $goodsKey => $goodsValue) {
                                $aDivide[$goodsKey]['order_sn'] = $orderSn;
                                $aDivide[$goodsKey]['goods_sn'] = $goodsValue['goods_sn'];
                                $aDivide[$goodsKey]['sku_sn'] = $goodsValue['sku_sn'];
                                $aDivide[$goodsKey]['belong'] = $this->belong;
                                $aDivide[$goodsKey]['type'] = 2;
                                $aDivide[$goodsKey]['level'] = 1;
                                $aDivide[$goodsKey]['order_uid'] = $orderInfo['uid'];
                                $aDivide[$goodsKey]['price'] = $goodsValue['price'];
                                $aDivide[$goodsKey]['count'] = $goodsValue['count'];
                                $aDivide[$goodsKey]['total_price'] = $goodsValue['total_price'];
                                $aDivide[$goodsKey]['vdc'] = $this->integralScale;
                                $aDivide[$goodsKey]['link_uid'] = $orderInfo['link_superior_user'];
                                $aDivide[$goodsKey]['divide_price'] = 0;
                                $aDivide[$goodsKey]['integral'] = intval($goodsValue['total_price'] * $this->integralScale * $this->integralToMoneyScale);
                                $allIntegral += $aDivide[$goodsKey]['integral'];
                            }
                        }

                    }
                    //格式化总价格,去除没有抽成的值
                    $allVdcOne = priceFormat($allVdcOne);
                    $allVdcTwo = priceFormat($allVdcTwo);
                    if ($this->belong == 2) {
                        //获取讲师分润明细
                        $aDivide = $this->lecturerDivide($orderGoods, $orderSn, $orderInfo, $aDivide);
                    }
                    foreach ($aDivide as $key => $value) {
                        if ($value['type'] == 3 && empty($value['divide_price'])) {
                            unset($aDivide[$key]);
                        }
                        if ($value['type'] == 5 && empty($value['link_uid'])) {
                            unset($aDivide[$key]);
                        }
                    }
                    //处理用户的分润金额,分润明细，积分明细
                    if (!empty($aDivide)) {
                        $aDivide = array_values($aDivide);
                        $res = Db::transaction(function () use ($aDivide, $topTwoUser, $topOneUser, $allVdcOne, $allVdcTwo, $allIntegral, $orderInfo, $goodsTitle) {
                            $allTopDivide = 0;
                            //记录分润明细
                            $res = (new DivideModel())->saveAll($aDivide);
                            $divideTypes = array_filter(array_column($aDivide, 'type'));
                            //二级分销明细
                            $topUserDivide = [$topOneUser => $allVdcOne, $topTwoUser => $allVdcTwo];
                            $topUsers = array_filter(array_keys($topUserDivide));

                            $balanceDetail = (new BalanceDetail());
                            $wx = (new Wx());
                            if (!empty($topUsers)) {
                                $topUse = User::where(['uid' => $topUsers, 'status' => 1])->field('uid,avaliable_balance,total_balance,divide_balance,integral')->select()->toArray();
                                foreach ($topUse as $key => $value) {
                                    foreach ($topUserDivide as $k => $v) {
                                        if ($value['uid'] == $k) {
                                            //分润金额不为0时修改金额和明细
                                            if (!empty(doubleval($v))) {
                                                //计算分销总奖励金额
                                                $allTopDivide += $v;
                                                //修改余额
//                                                $save['avaliable_balance'] = priceFormat($value['avaliable_balance'] + $v);
//                                                $save['total_balance'] = priceFormat($value['total_balance'] + $v);
                                                $save['divide_balance'] = priceFormat($value['divide_balance'] + $v);
                                                User::update($save, ['uid' => $value['uid'], 'status' => 1]);
                                                //增加余额明细
                                                $detail['order_sn'] = $orderInfo['order_sn'];
                                                $detail['uid'] = $value['uid'];
                                                $detail['type'] = 1;
                                                $detail['price'] = priceFormat($v);
                                                $detail['change_type'] = 1;
                                                $balanceDetail->new($detail);

                                                //推送模版消息通知
                                                $template['uid'] = $value['uid'];
                                                $template['type'] = 'divide';
                                                $tile = $goodsTitle[0];
                                                if (count($goodsTitle) > 1) {
                                                    $tile .= '等';
                                                }
                                                $template['access_key'] = getAccessKey();
                                                $template['template'] = ['first' => '有新的订单分润啦~', $orderInfo['user_name'], date('Y-m-d H:i:s', $orderInfo['create_time']), priceFormat($v), $tile];
                                                Queue::push('app\lib\job\Template', $template, 'tcmTemplateList');
                                            }

                                        }
                                        if ($value['uid'] == $topOneUser) {
                                            if (!empty($allIntegral)) {
                                                $allIntegral = $allIntegral + $value['integral'];
                                            }
                                        }
                                    }
                                }
                            }
                            //积分明细
                            if (!empty($topOneUser) && !empty($allIntegral)) {
                                $integral['order_sn'] = $orderInfo['order_sn'];
                                $integral['integral'] = $allIntegral;
                                $integral['type'] = 1;
                                $integral['uid'] = $topOneUser;
                                $integral['change_type'] = 2;

                                (new IntegralDetail())->new($integral);

                                //推送模版消息通知
                                $template['uid'] = $topOneUser;
                                $template['type'] = 'divide';
                                $tile = $goodsTitle[0];
                                if (count($goodsTitle) > 1) {
                                    $tile .= '等';
                                }
                                $template['access_key'] = getAccessKey();
                                $template['template'] = ['first' => '有新的订单积分增加啦~', $orderInfo['user_name'], date('Y-m-d H:i:s', $orderInfo['create_time']), $allIntegral . '积分', $tile];
                                Queue::push('app\lib\job\Template', $template, 'tcmTemplateList');

                            }
                            //讲师分润明细
                            if (in_array(5, $divideTypes)) {
                                $lecturerUid = [];
                                $lecturerCount = 0;
                                foreach ($aDivide as $key => $value) {
                                    if ($value['type'] == 5) {
                                        $lecturerUid[$lecturerCount] = $value['link_uid'];
                                        $lecturerCount++;
                                    }
                                }
                                if (!empty($lecturerUid)) {
                                    $topUse = User::where(['uid' => $lecturerUid, 'status' => 1])->field('uid,avaliable_balance,total_balance,divide_balance,integral')->select()->toArray();
                                    foreach ($topUse as $key => $value) {
                                        foreach ($aDivide as $k => $v) {
                                            if ($value['uid'] == $v['link_uid']) {
                                                //分润金额不为0时修改金额和明细
                                                if (!empty(doubleval($v['divide_price'])) && $v['type'] == 5) {
                                                    //修改余额
//                                                    $save['avaliable_balance'] = priceFormat($value['avaliable_balance'] + $v['divide_price']);
//                                                    $save['total_balance'] = priceFormat($value['total_balance'] + $v['divide_price']);
                                                    $save['divide_balance'] = priceFormat($value['divide_balance'] + $v['divide_price']);
                                                    $save['total_balance'] = priceFormat($value['total_balance'] + $v['divide_price']);
                                                    User::update($save, ['uid' => $value['uid'], 'status' => 1]);
                                                    //增加余额明细
                                                    $detail['order_sn'] = $orderInfo['order_sn'];
                                                    $detail['uid'] = $value['uid'];
                                                    $detail['type'] = 1;
                                                    $detail['price'] = priceFormat($v['divide_price']);
                                                    $detail['change_type'] = 5;
                                                    $balanceDetail->new($detail);

                                                    //推送模版消息通知
                                                    $template['uid'] = $value['uid'];
                                                    $template['type'] = 'divide';
                                                    $tile = '您的课程';
                                                    $template['access_key'] = getAccessKey();
                                                    $template['template'] = ['first' => '有新的讲师专属订单分润啦~', $orderInfo['user_name'], date('Y-m-d H:i:s', $orderInfo['create_time']), priceFormat($v['divide_price']), $tile];
                                                    Queue::push('app\lib\job\Template', $template, 'tcmTemplateList');
                                                }

                                            }
                                        }
                                    }
                                }
                            }

                            //团队奖励机制
                            if (!empty($topTwoUser) && !empty($allTopDivide)) {
                                $userTeamLevel = Member::where(['uid' => $topTwoUser, 'status' => 1])->field('level,leader_level,uid,user_phone')->findOrEmpty()->toArray();
                                if (!empty($userTeamLevel) && $userTeamLevel['level'] == 2 && !empty($userTeamLevel['leader_level'])) {
                                    $teamReward = Reward::where(['level' => intval($userTeamLevel['leader_level']), 'status' => 1])->field('reward_scale,reward_other,freed_scale,freed_cycle')->findOrEmpty()->toArray();
                                    if (!empty($teamReward)) {
                                        $team['uid'] = $topTwoUser;
                                        $team['freed_sn'] = (new CodeBuilder())->buildRewardFreedSn();
                                        $team['order_sn'] = $orderInfo['order_sn'];
                                        $team['divide_price'] = priceFormat($allTopDivide) ?? 0;
                                        $team['reward_scale'] = $teamReward['reward_scale'];
                                        $team['reward_other'] = $teamReward['reward_other'];
                                        $team['freed_scale'] = $teamReward['freed_scale'];
                                        $team['freed_cycle'] = $teamReward['freed_cycle'];
                                        $team['reward_integral'] = priceFormat($team['divide_price'] * $team['reward_scale'] * $this->integralToMoneyScale);
                                        $team['freed_each'] = priceFormat($team['reward_integral'] * $team['freed_scale']);
                                        $team['freed_start'] = (time() + 7200);
                                        $team['freed_end'] = ($team['freed_start'] + ($team['freed_cycle'] * 7200));
                                        $team['fronzen_integral'] = $team['reward_integral'];
                                        $team['is_first'] = 1;
                                        $teamRes = RewardFreed::create($team);
                                    }
                                }
                            }

                            return judge($res);
                        });
                        if ($res) {
                            $log['aDivide'] = $aDivide;
                            $log['aIntegral'] = $integral;
                        }
                    } else {
                        $log['msg'] = '暂无法计算合适的分润';
                    }
                } else {
                    $log['msg'] = '无法查找到订单详情';
                }
            } else {
                $log['msg'] = '暂无订单编号';
            }
        } else {
            $log['msg'] = '暂无接受数据';
        }
        //记录日志
        $log['res'] = $res;
        $logLevel = $res ? 'info' : 'error';

        $this->log($log, $logLevel);
        $job->delete();
//        if ($job->attempts() > 3) {
//            //通过这个方法可以检查这个任务已经重试了几次了
//            print("队列执行重复超过三次\n");
//            print("队列销毁\n");
//            $job->delete();
//        }
//
//        //如果任务执行成功后 记得删除任务，不然这个任务会重复执行，直到达到最大重试次数后失败后，执行failed方法
//        if($res){
//            $job->delete();
//        }else{
//            $job->release(3);
//        }


    }

    /**+
     * @title  讲师分润
     * @param array $orderGoods 订单商品数组
     * @param string $orderSn 订单编号
     * @param array $orderInfo 订单详情
     * @param array $aDivide 分润明细数组
     * @return array
     * @throws \Exception
     */
    private function lecturerDivide(array $orderGoods, string $orderSn, array $orderInfo, array $aDivide = [])
    {
        $goodsSpu = array_column($orderGoods, 'goods_sn');
        //根据商品SPU查询相关的课程和讲师信息
        $subjectGoods = GoodsSpu::where(['goods_sn' => $goodsSpu, 'status' => 1])->column('link_product_id', 'goods_sn');
        $subjectIds = array_values($subjectGoods);
        $subjectInfos = Subject::with(['lecturerForOrder'])->where(['id' => $subjectIds, 'status' => 1])->field('id,lecturer_id')->select()->each(function ($item, $key) use ($subjectGoods) {
            if (in_array($item['id'], $subjectGoods)) {
                $item['goods_sn'] = array_search($item['id'], $subjectGoods);
            }
        })->toArray();
        //将相关的讲师信息重新填回订单商品数组
        if (!empty($subjectInfos)) {
            foreach ($orderGoods as $goodsKey => $goodsValue) {
                foreach ($subjectInfos as $key => $value) {
                    if ($value['goods_sn'] == $goodsValue['goods_sn'] && $orderInfo['uid'] != $value['link_uid'] && !empty($value['lecturer_id'])) {
                        $orderGoods[$goodsKey]['subject_id'] = $value['id'];
                        $orderGoods[$goodsKey]['lecturer_id'] = $value['lecturer_id'];
                        $orderGoods[$goodsKey]['lecturer_name'] = $value['lecturer_name'];
                        $orderGoods[$goodsKey]['lecturer_link_uid'] = $value['link_uid'];
                        $orderGoods[$goodsKey]['lecturer_divide'] = $value['divide'];

                    }
                }
            }
        }
        //判断订单商品是否存在绑定的课程,讲师,并且讲师分销比例不为空的,有则添加新的分润明细
        $count = count($aDivide);
        foreach ($orderGoods as $goodsKey => $goodsValue) {
            if (!empty($goodsValue['subject_id']) && !empty($goodsValue['lecturer_id']) && !empty(doubleval($goodsValue['lecturer_divide']))) {
                $aDivide[$count]['order_sn'] = $orderSn;
                $aDivide[$count]['goods_sn'] = $goodsValue['goods_sn'];
                $aDivide[$count]['sku_sn'] = $goodsValue['sku_sn'];
                $aDivide[$count]['belong'] = $this->belong;
                $aDivide[$count]['type'] = 5;
                $aDivide[$count]['level'] = 1;
                $aDivide[$count]['order_uid'] = $orderInfo['uid'];
                $aDivide[$count]['price'] = $goodsValue['price'];
                $aDivide[$count]['count'] = $goodsValue['count'];
                $aDivide[$count]['total_price'] = $goodsValue['total_price'];
                $aDivide[$count]['vdc'] = $goodsValue['lecturer_divide'];
                $aDivide[$count]['link_uid'] = $goodsValue['lecturer_link_uid'];
                $aDivide[$count]['divide_price'] = ($goodsValue['total_price'] * $goodsValue['lecturer_divide']);
                $aDivide[$count]['integral'] = 0;
                $count++;
            }
        }
        return $aDivide;
    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'error')
    {
        return (new Log())->setChannel('divide')->record($data, $level);
    }
}