<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼余额明细模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\PpylException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use think\facade\Db;

class PpylBalanceDetail extends BaseModel
{
    /**
     * @title  余额明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['change_type'])) {
            $map[] = ['change_type', '=', $sear['change_type']];
        }
        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
        }

        if ($this->module == 'api') {
            $map[] = ['uid', '=', $sear['uid']];
            $map[] = ['price', '<>', 0];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['reward'])->where($map)->withoutField('id,update_time,status,belong')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        if (!empty($list)) {
            $allOrderUid = array_column($list, 'order_uid');
            $allOrderUser = User::where(['uid' => $allOrderUid])->column('name', 'uid');
            foreach ($list as $key => $value) {
                foreach ($allOrderUser as $k => $v) {
                    if (!empty($value['order_uid'])) {
                        if ($k == $value['order_uid']) {
                            $list[$key]['order_user_name'] = $v;
                        }
                    }

                }
                unset($list[$key]['order_uid']);
            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  拼拼有礼余额转入商城余额
     * @param array $data
     * @return bool
     */
    public function transformMallBalance(array $data)
    {
        throw new PpylException(['msg' => '抱歉, 该功能已被停用, 如有疑问请联系客服']);
        $uid = $data['uid'];
        $price = $data['price'];
        $userInfo = User::where(['uid' => $uid, 'status' => 1])->field('uid,phone,name,vip_level,ppyl_balance')->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new UserException();
        }
        if ($userInfo['ppyl_balance'] <= 0 || ((string)$userInfo['ppyl_balance'] < (string)$price)) {
            throw new ServiceException(['msg' => '可用余额不足']);
        }

        $DBRes = Db::transaction(function () use ($userInfo, $data, $price) {
            $balanceRes = false;
            $ppylBalanceRes = false;
            //减少拼拼有礼可用余额,增加商城可用余额
            User::where(['uid' => $userInfo['uid'], 'status' => 1])->dec('ppyl_balance', $price)->update();
            User::where(['uid' => $userInfo['uid'], 'status' => 1])->inc('total_balance', $price)->update();
            User::where(['uid' => $userInfo['uid'], 'status' => 1])->inc('avaliable_balance', $price)->update();

            //拼拼有礼钱包余额
            $ppylBalanceDetail['uid'] = $userInfo['uid'];
            $ppylBalanceDetail['type'] = 2;
            $ppylBalanceDetail['price'] = '-' . $price;
            $ppylBalanceDetail['change_type'] = 2;
            if (!empty($ppylBalanceDetail)) {
                $ppylBalanceRes = PpylBalanceDetail::create($ppylBalanceDetail)->getData();
            }

            //拼拼有礼钱包余额
            $BalanceDetail['uid'] = $userInfo['uid'];
            $BalanceDetail['type'] = 1;
            $BalanceDetail['price'] = $price;
            $BalanceDetail['change_type'] = 10;
            if (!empty($BalanceDetail)) {
                $balanceRes = BalanceDetail::create($BalanceDetail)->getData();
            }

            if (empty($balanceRes) && empty($ppylBalanceRes)) {
                return false;
            } else {
                return ['ppylBalanceRes' => $ppylBalanceRes ?? [], 'balanceRes' => $balanceRes ?? []];
            }

        });

        return judge($DBRes);

    }

    /**
     * @title  新建余额明细
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        return $this->baseCreate($data, true);
    }

    /**
     * @title  获取用户的总余额(一般用于校验用户可用余额)
     * @param string $uid
     * @param array $type 类型
     * @return float
     */
    public function getAllBalanceByUid(string $uid, array $type = [])
    {
        $map[] = ['uid', '=', $uid];
        $map[] = ['status', '=', 1];
        if (!empty($type)) {
            $map[] = ['change_type', 'in', $type];
        }
        return $this->where($map)->sum('price');
    }

    public function reward()
    {
        return $this->hasOne('PpylReward', 'order_sn', 'order_sn')->where(['status' => [1]])->bind(['order_uid', 'total_price', 'reward_base_price']);
    }
}