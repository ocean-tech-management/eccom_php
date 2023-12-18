<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 健康豆操作模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;
use app\lib\exceptions\OpenException;
use app\lib\services\Log;

class HealthyBalanceConver extends BaseModel
{
    /**
     * @description: 健康执行转出
     * @param {*} $data
     * @return {*}
     */
    public function new($data)
    {
        return $this->baseCreate($data, true);
    }

    /**
     * @description: 健康转出记录
     * @param {*} $data
     * @return {*}
     */
    public function list($sear): array
    {
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn|transfer_from_name', $sear['keyword']))];
        }
        if (!empty($sear['app_id'])) {
            $map[] = ['app_id', '=', $sear['app_id']];
        }
        if (!empty($sear['phone'])) {
            $map[] = ['phone', '=', $sear['phone']];
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
        if ($this->module == 'api') {
            $map[] = ['uid', '=', $sear['uid']];
            $field = 'id,uid,balance,transfer_from_user_phone,transfer_from_name,transfer_for_user_phone,transfer_for_name,remark,conver_status,create_time';
        } elseif ($this->module == 'admin') {
            if (!empty($sear['conver_status'])) {
                $map[] = ['conver_status', '=', $sear['conver_status']];
            }
            $field = 'id,uid,app_id,subco_name,balance,transfer_from_user_phone,transfer_from_name,transfer_for_user_phone,transfer_for_name,remark,conver_status,create_time';
        }
        $list = $this->field($field)
            ->where($map)
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->order('create_time desc,id asc')
            ->select()
            ->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @description: 搜索订单
     * @param {*} $data
     * @return {*}
     */
    public function checkOrder($sear)
    {
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['conver_status', '=', 1];
        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            if (abs(strtotime($sear['end_time']) - strtotime($sear['start_time'])) > 60 * 60 * 24 * 7) {
                throw new OpenException(['msg' => '查询的时间跨度不能大于7天！']);
            }
            $map[] = ['create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }
        if (!empty($sear['so_no'])) {
            $sear['so_no'] = explode(',', $sear['so_no']);
            $map[] = ['order_sn', 'in', $sear['so_no']];
        }
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? 0)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        (new Log())->setChannel('healthyBalanceConver')->record($map);
        $field = 'order_sn as order_conver_no,balance as balance_price,transfer_from_user_phone as conver_mobile_phone,transfer_from_name as conver_user_name,transfer_from_uid as conver_user_id,conver_status as status,create_time';
        $list = $this->field($field)
            ->where($map)
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->order('create_time desc,id asc')
            ->select()
            ->each(function ($item) {
                $item['healthy_balance_data'] = HealthyBalanceDetail::field('healthy_channel_type as balance_change_type,price as balance_price')
                    ->where([
                        'order_sn' => $item['order_conver_no'],
                        'status' => 1,
                        'conver_type' => 3
                    ])
                    ->select();
            })
            ->toArray();
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }
}
