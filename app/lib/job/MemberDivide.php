<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\job;


use app\lib\models\BalanceDetail;
use app\lib\models\Member;
use app\lib\models\MemberOrder;
use app\lib\models\User;
use app\lib\services\Log;
use think\facade\Db;
use think\facade\Queue;
use think\queue\Job;
use app\lib\models\Divide;

class MemberDivide
{
    private $memberScale = 0.2;     //新会员上级抽成比例
    private $belong = 1;

    public function fire(Job $job, $data)
    {
        $res = true;
        $log['msg'] = '会员分润处理成功';
        $log['data'] = $data;
        if (!empty($data)) {
            $orderSn = $data['order_sn'];
            $existDivide = Divide::where(['order_sn' => $orderSn, 'type' => 4])->count();
            if ($existDivide) {
                $log['res'] = false;
                $log['msg'] = '该订单已完成会员分润';
                $this->log($log);
                $job->delete();
            }
            if (!empty($orderSn)) {
                $orderInfo = Db::name('member_order')->alias('a')
                    ->join('sp_user b', 'a.uid = b.uid', 'left')
                    ->field('a.*,b.name as user_name,b.link_superior_user,b.avaliable_balance,b.total_balance,b.divide_balance')
                    ->where(['a.order_sn' => $orderSn, 'a.pay_status' => 2, 'a.order_status' => 2])->findOrEmpty();
                if (!empty($orderInfo)) {
                    $topOne = Member::where(['uid' => $orderInfo['link_superior_user'], 'status' => [1]])->field('uid,user_phone,member_card,level,link_superior_user')->findOrEmpty()->toArray();
                    if (!empty($topOne)) {
                        $topOneUser = $orderInfo['link_superior_user'];
                        $topUser = User::where(['uid' => $topOneUser, 'status' => 1])->field('uid,avaliable_balance,divide_balance,total_balance,integral')->findOrEmpty()->toArray();
                        $aDivide['order_sn'] = $orderSn;
                        $aDivide['order_uid'] = $orderInfo['uid'];
                        $aDivide['belong'] = $this->belong;
                        $aDivide['type'] = 4;
                        $aDivide['level'] = 1;
                        $aDivide['order_uid'] = $orderInfo['uid'];
                        $aDivide['price'] = $orderInfo['total_price'];
                        $aDivide['count'] = 1;
                        $aDivide['total_price'] = $orderInfo['total_price'];
                        $aDivide['vdc'] = $this->memberScale;
                        $aDivide['level'] = 1;
                        $aDivide['link_uid'] = $orderInfo['link_superior_user'];
                        $aDivide['divide_price'] = priceFormat($orderInfo['total_price'] * $this->memberScale);
                        $allDivide = $aDivide['divide_price'];
                        $res = Db::transaction(function () use ($aDivide, $allDivide, $orderInfo, $topOneUser, $topUser) {
                            //添加会员分润记录
                            $res = Divide::create($aDivide);

                            //增加余额明细
                            if (!empty(doubleval($allDivide))) {

                                //修改上级用户余额
//                                $save['avaliable_balance'] = priceFormat($topUser['avaliable_balance'] + $allDivide);
//                                $save['total_balance'] = priceFormat($topUser['total_balance'] + $allDivide);
                                $save['divide_balance'] = priceFormat($topUser['divide_balance'] + $allDivide);
                                $userRes = User::update($save, ['uid' => $topOneUser, 'status' => 1]);

                                $detail['order_sn'] = $orderInfo['order_sn'];
                                $detail['uid'] = $topOneUser;
                                $detail['type'] = 1;
                                $detail['price'] = priceFormat($allDivide);
                                $detail['change_type'] = 2;
                                (new BalanceDetail())->new($detail);

                                //推送模版消息通知
                                $template['uid'] = $topOneUser;
                                $template['type'] = 'divide';
                                $template['access_key'] = getAccessKey();
                                $template['template'] = ['first' => '有新的会员分润啦~', $orderInfo['user_name'], date('Y-m-d H:i:s', $orderInfo['create_time']), $allDivide, '新会员提成分润'];
                                Queue::push('app\lib\job\Template', $template, 'tcmTemplateList');
                            }

                            $all['divide'] = $res->getData();
                            $save['link_superior_uid'] = $topOneUser;
                            $all['userBalance'] = $save;
                            $all['balanceDetail'] = $detail ?? [];
                            return $all;
                        });
                        $log['data']['info'] = $res;
                    } else {
                        $log['msg'] = '会员分润-上级用户不是会员,无法参加分润';
                    }
                } else {
                    $log['msg'] = '会员分润-无法查找到订单详情';
                }
            } else {
                $log['msg'] = '会员分润-暂无订单编号';
            }
        } else {
            $log['msg'] = '会员分润-暂无接受数据';
        }
        //记录日志
        $log['res'] = $res;
        $logLevel = $res ? 'info' : 'error';

        $this->log($log, $logLevel);

        $job->delete();
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