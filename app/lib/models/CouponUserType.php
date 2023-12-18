<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use Exception;
use think\facade\Db;

class CouponUserType extends BaseModel
{
    protected $field = ['u_name', 'u_type', 'u_group_type', 'status', 'vip_level'];

    /**
     * @title  优惠券面向对象列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('u_name', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('u_name,u_type,u_group_type,status,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('u_group_type asc,create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  会员用户类型列表
     * @return array
     */
    public function memberTypeList()
    {
        return $this->where(['status' => [1], 'u_group_type' => 2])->cache('memberTypeList', 300)->column('u_type');
    }

    /**
     * @title  普通用户类型列表
     * @return array
     */
    public function userTypeList()
    {
        return $this->where(['status' => [1], 'u_group_type' => 1])->column('u_type');
    }
}