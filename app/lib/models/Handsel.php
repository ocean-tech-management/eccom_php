<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 直推获得赠送的套餐模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class Handsel extends BaseModel
{

    /**
     * @title  用户奖励套餐列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['uid','=',$sear['uid']];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['goods'=>function($query){
            $query->field('goods_sn,title,main_image');
        }])->withMin(['sku'=>'sale_price'],'sale_price')->where($map)->withoutField('id,update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc,id desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    public function goods()
    {
        return $this->hasOne('GoodsSpu','goods_sn','goods_sn');
    }
    public function sku()
    {
        return $this->hasOne('GoodsSku','goods_sn','goods_sn');
    }
}