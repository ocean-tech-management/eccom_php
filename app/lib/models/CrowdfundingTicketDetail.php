<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 美丽券明细模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\FinanceException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\services\Summary;
use mysql_xdevapi\Exception;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Queue;

class CrowdfundingTicketDetail extends BaseModel
{
    /**
     * @title  美丽券明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('d.phone|d.name|a.order_sn|a.pay_no|a.price', $sear['keyword']))];
        }
        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['change_type'])) {
            if (is_array($sear['change_type'])) {
                $map[] = ['a.change_type', 'in', $sear['change_type']];
            } else {
                $map[] = ['a.change_type', '=', $sear['change_type']];
            }
        }

        if (!empty($sear['type'])) {
            $map[] = ['a.type', '=', $sear['type']];
        }

        if (!empty($sear['uid'])) {
            $map[] = ['a.uid', '=', $sear['uid']];
            $map[] = ['a.price', '<>', 0];
        }


        if ($this->module == 'api') {
            $map[] = ['a.uid', '=', $sear['uid']];
            $map[] = ['a.price', '<>', 0];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber'] ?? 0);
        }
        if (!empty($page)) {
            $aTotal = $this->alias('a')
                ->join('sp_user d','a.uid = d.uid','left')->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        if ($this->module == 'api') {
            $list = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
                ->field('a.*,d.name as user_name,d.phone as user_phone,d.avatarUrl')->where($map)->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->order('a.create_time desc,a.id asc')->select()->toArray();
        } else {
            $list = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
                ->field('a.id,a.uid,a.order_sn,a.type,a.price,a.change_type,a.status,a.create_time,a.pay_type,a.remark,d.name as user_name,d.phone as user_phone,d.avatarUrl')->where($map)->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->order('a.create_time desc,a.id asc')->select()->toArray();
        }



        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  新建美丽券明细
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        return $this->baseCreate($data, true);
    }

    /**
     * @title  获取用户的总美丽券(一般用于校验用户可用美丽券)
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
        $price = $this->where($map)->sum('price');
        return priceFormat($price ?? 0);
    }

    public function withdraw()
    {
        return $this->hasOne('Withdraw', 'order_sn', 'order_sn')->where(['status' => [1]])->bind(['withdraw_total_price' => 'total_price']);
    }
}