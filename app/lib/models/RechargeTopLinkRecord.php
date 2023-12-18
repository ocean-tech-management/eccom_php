<?php

namespace app\lib\models;

use app\BaseModel;
use app\lib\exceptions\ServiceException;
use think\facade\Db;

class RechargeTopLinkRecord extends BaseModel
{
    /**
     * @title  记录明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn|price', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if (!empty($sear['recharge_type'])) {
            $map[] = ['recharge_type', '=', $sear['recharge_type']];
        }

        if (!empty($sear['order_user'])) {
            $uMap = [];
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone|name', $sear['order_user']))];
            $linkUid = User::where($uMap)->value('uid');
            if (!empty($linkUid)) {
                $map[] = ['uid', '=', $linkUid];
            }else{
                return ['list' => [], 'pageTotal' => 0];
            }
        }

        if (!empty($sear['link_user'])) {
            $uMap = [];
            $linkUid = null;
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone|name', $sear['link_user']))];
            $linkUid = User::where($uMap)->value('uid');
            if (!empty($linkUid)) {
                $map[] = ['link_uid', '=', $linkUid];
            }else{
                return ['list' => [], 'pageTotal' => 0];
            }
        }

        if ($this->module == 'api') {
            $map[] = ['uid', '=', $sear['uid']];
            $map[] = ['price', '<>', 0];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? 0)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['orderUser','linkUser'])->where($map)->withoutField('id,update_time,status')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc,id asc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title 记录
     * @param array $data
     * @return bool
     */
    public function record(array $data)
    {
        $orderSn = $data['order_sn'] ?? null;
        $orderInfo = [];
        if(!empty($orderSn)){
            //判断是否存在记录, 存在不允许重复记录
            $checkExist = self::where(['order_sn' => $orderSn, 'status' => 1])->count();
            if (!empty($checkExist)) {
                return false;
            }
            $orderInfo = CrowdfundingBalanceDetail::where(['order_sn' => $orderSn, 'status' => 1, 'type' => 1, 'is_finance' => 2])->findOrEmpty()->toArray();
            if (empty($orderInfo)) {
                return false;
            }
        }

        $userInfo = User::where(['uid' => ($orderInfo['uid'] ?? $data['uid']), 'status' => [1, 2]])->field('uid,name,phone,link_superior_user')->findOrEmpty()->toArray();
        if (empty($userInfo) || empty($userInfo['link_superior_user'] ?? null)) {
            return false;
        }

        $newRecord['uid'] = $orderInfo['uid'] ?? $data['uid'];
        $newRecord['order_sn'] = $orderInfo['order_sn'] ?? ($data['order_sn'] ?? null);
        //财务号
        $financeUid = SystemConfig::where(['id' => 1])->value('finance_uid');
        $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];

        if(!empty($orderInfo)){
            //不同的充值渠道有不同的记录类型,不符合的直接剔除不计入
            switch ($orderInfo['remark']) {
                case "充值":
                    $newRecord['recharge_type'] = 1;
                    break;
                case strstr($orderInfo['remark'], '后台系统充值'):
                    $newRecord['recharge_type'] = 2;
                    break;
                case strstr($orderInfo['remark'], '用户自主提交线下付款申请后审核通过充值'):
                    $newRecord['recharge_type'] = 3;
                    break;
                case (strstr($orderInfo['remark'], '13840616567美丽金转入') || strstr($orderInfo['remark'], '14745543825美丽金转入') || strstr($orderInfo['remark'], '18529431349美丽金转入') || strstr($orderInfo['remark'], '15245037478美丽金转入') || in_array($orderInfo['transfer_from_uid'], $withdrawFinanceAccount)):
                    $newRecord['recharge_type'] = 4;
                    break;
                default:
                    $newRecord['recharge_type'] = null;
                    if (in_array($orderInfo['transfer_from_uid'], $withdrawFinanceAccount)) {
                        $newRecord['recharge_type'] = 4;
                    }
                    break;
            }
        }else{
            $newRecord['recharge_type'] = $data['recharge_type'] ?? 1;
        }

        $newRecord['link_uid'] = $userInfo['link_superior_user'];
        $newRecord['total_price'] = $orderInfo['price'] ?? $data['total_price'];
        $newRecord['record_scale'] = 0.3;
        $newRecord['price'] = priceFormat($newRecord['total_price'] * $newRecord['record_scale']);
        $newRecord['create_time'] = time();
        $newRecord['update_time'] = time();
        $res = Db::name('recharge_top_link_record')->insert($newRecord);
        return true;
    }


    public function orderUser()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name' => 'name', 'user_phone' => 'phone']);
    }

    public function linkUser()
    {
        return $this->hasOne('User', 'uid', 'link_uid')->bind(['link_user_name' => 'name', 'link_user_phone' => 'phone']);
    }
}