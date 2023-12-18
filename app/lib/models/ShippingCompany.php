<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 快递公司标准编码Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class ShippingCompany extends BaseModel
{
    protected $field = ['company', 'company_code', 'company_type', 'status' => 'number'];

    /**
     * @title  快递100公司编码列表
     * @param array $sear 搜索条件
     * @return array
     * @throws \Exception
     */
    public function list(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('company|company_code', $sear['keyword']))];
        }
        if (!empty($sear['company_type'])) {
            $map[] = ['company_type', '=', $sear['company_type']];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('id,company,company_code,company_type,status,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc,id asc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }
}