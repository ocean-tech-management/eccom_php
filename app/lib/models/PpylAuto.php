<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼自动拼团模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class PpylAuto extends BaseModel
{
    /**
     * @title  用户自动拼团列表
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function userAutoList(array $data)
    {
        if (!empty($data['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', $data['keyword']))];
        }
        $map[] = ['uid', '=', $data['uid']];

        $page = intval($data['page'] ?? 0) ?: null;
        if (!empty($data['pageNumber'])) {
            $this->pageNumber = intval($data['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['orderGoods'=> function ($query) use ($map, $data) {
            $query->field('order_sn,goods_sn,sku_sn,price,sale_price,total_price,title,images,specs,total_fare_price,shipping_status,real_pay_price');
        }])->where($map)->withoutField('id,update_time')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->select()->each(function($item){
                if(!empty($item['start_time'])){
                    $item['start_time'] = timeToDateFormat($item['start_time']);
                }
                if(!empty($item['end_time'])){
                    $item['end_time'] = timeToDateFormat($item['end_time']);
                }
                if(!empty($item['stop_time'])){
                    $item['stop_time'] = timeToDateFormat($item['stop_time']);
                }
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    public function orderGoods()
    {
        return $this->hasOne('PpylOrderGoods', 'sku_sn', 'sku_sn');
    }
}