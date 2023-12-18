<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class RechargeLinkDetail extends BaseModel
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

        if (!empty($sear['link_user'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone|name', $sear['link_user']))];
            $linkUid = User::where($uMap)->value('uid');
            if (!empty($linkUid)) {
                $map[] = ['link_uid', '=', $linkUid];
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
        })->order('create_time desc,id asc')->select()->each(function ($item) {
            if (!empty($item['start_time_period'] ?? null)) {
                $item['start_time_period'] = date('Y-m-d H:i:s', $item['start_time_period']);
            }
            if (!empty($item['end_time_period'] ?? null)) {
                $item['end_time_period'] = date('Y-m-d H:i:s', $item['end_time_period']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    public function orderUser()
    {
        return $this->hasOne('User', 'uid', 'order_uid')->bind(['order_user_name' => 'name', 'order_user_phone' => 'phone']);
    }

    public function linkUser()
    {
        return $this->hasOne('User', 'uid', 'link_uid')->bind(['link_user_name' => 'name', 'link_user_phone' => 'phone']);
    }
}