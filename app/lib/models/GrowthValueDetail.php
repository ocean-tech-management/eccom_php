<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 成长值详情Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\models\Divide as DivideModel;
use think\facade\Db;

class GrowthValueDetail extends BaseModel
{
    /**
     * @title  成长值明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        $map = [];
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn|b.name|a.user_phone', $sear['keyword']))];
        }
        if (!empty($sear['order_sn'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn', $sear['keyword']))];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
        }

        if ($this->module == 'api') {
            $map[] = ['a.uid', '=', $sear['uid']];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = $sear['pageNumber'];
        }
        if (!empty($page)) {
            $aTotal = Db::name('growth_value_detail')->alias('a')
                ->join('sp_user b', 'a.uid = b.uid', 'left')
                ->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = Db::name('growth_value_detail')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_user c', 'a.order_uid = c.uid', 'left')
            ->field('a.order_sn,a.order_uid,a.order_user_phone,a.order_real_pay_price,a.type,a.uid,a.user_phone,a.growth_value,a.surplus_growth_value,a.arrival_status,a.remark,a.status,a.create_time,b.name as user_name,b.avatarUrl,c.name as order_user_name,c.avatarUrl as order_user_avatarUrl')
            ->where($map)
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.create_time desc')
            ->select()->each(function ($item, $key) {
                if (!empty($item['create_time'])) $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                if (!empty($item['update_time'])) $item['update_time'] = date('Y-m-d H:i:s', $item['update_time']);
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }
}