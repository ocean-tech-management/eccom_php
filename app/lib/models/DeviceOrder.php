<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 机器订单模块]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class DeviceOrder extends BaseModel
{
    /**
     * @title  设备订单列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        $map = [];
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn|device_power_imei|user_phone|device_sn', $sear['keyword']))];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }

//        $map[] = ['device_sn','=',$sear['device_sn']];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['user', 'linkUser', 'device', 'combo' => function ($query) {
            $query->field('id,combo_sn,combo_title,continue_time,device_sn,healthy_price,power_number,price,healthy_price,user_divide_scale');
        }])->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    public function user()
    {
        return $this->hasOne('User','uid','uid')->bind(['user_name'=>'name']);
    }

    public function linkUser()
    {
        return $this->hasOne('User','uid','device_link_uid')->bind(['link_user_name'=>'name','link_user_phone'=>'phone']);
    }

    public function device()
    {
        return $this->hasOne('Device','device_sn','device_sn');
    }

    public function combo()
    {
        return $this->hasOne('DeviceCombo','combo_sn','device_combo_sn');
    }
}