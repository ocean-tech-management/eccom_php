<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 物流信息模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\services\Shipping;
use think\facade\Db;

class ShippingDetail extends BaseModel
{
    protected $field = ['shipping_code', 'company', 'content', 'node_time', 'node_status', 'shipping_status', 'is_check', 'status'];
    protected $validateFields = ['shipping_code'];

    /**
     * @title  物流明细列表
     * @param array $sear 搜索条件
     * @return array
     * @throws \Exception
     */
    public function list(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('shipping_code|company', $sear['keyword']))];
        }
        $shippingCode = explode(',', $sear['shipping_code']);
        $map[] = ['shipping_code', 'in', $shippingCode];

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['company'])->where($map)->field('shipping_code,company,content,node_time,node_status,is_check')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('shipping_status desc,node_time desc')->select()->toArray();
        $aList = [];
        if (!empty($list)) {
            foreach ($shippingCode as $k => $v) {
                foreach ($list as $key => $value) {
                    if ($value['shipping_code'] == $v) {
                        $aList[$value['shipping_code']][] = $value;
                    }
                }
                if (!isset($aList[$v])) {
                    $aList[$v] = [];
                }
            }
        }
        return ['list' => $aList, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  物流信息更新
     * @param array $data
     * @return ShippingDetail|bool
     */
    public function subscribeNewOrEdit(array $data)
    {
        $res = false;
        $db['company'] = $data['com'];
        $db['shipping_code'] = $data['nu'];
        $db['is_check'] = intval($data['ischeck']);
        $db['shipping_status'] = intval($data['state'] ?? -1);

//        //如果存在发货的首条数据则加入一并修改状态
//        $shippingInfo = self::where(['shipping_code'=>$db['shipping_code'],'shipping_status'=>-1])->field('content as context,node_time as ftime,node_status as status')->findOrEmpty()->toArray();

        if (!empty($shippingInfo)) {
            $allData = array_merge_recursive($data['data'], [$shippingInfo]);
        } else {
            $allData = $data['data'];
        }

        foreach ($allData as $key => $value) {
            $db['content'] = $value['context'];
            $db['node_time'] = $value['ftime'];
            $db['node_status'] = $value['status'];
            $res = self::updateOrCreate(['shipping_code' => $db['shipping_code'], 'node_time' => $db['node_time']], $db);
        }
        return $res;
    }

    /**
     * @title  物流统计
     * @param array $sear
     * @return mixed
     * @throws \Exception
     */
    public function summary(array $sear)
    {
        $cacheKey = 'adminShipSummary';
        $cacheExpire = 3600 * 10;
        if (!empty($cacheKey)) {
            if (!empty(cache($cacheKey))) {
                return cache($cacheKey);
            }
        }

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('company', $sear['keyword']))];
        }
        if (!empty($sear['shipping_code'])) {
            $shippingCode = explode(',', $sear['shipping_code']);
            $map[] = ['shipping_code', 'in', $shippingCode];
        }
        if (!empty($sear['shipping_status'])) {
            $map[] = ['shipping_status', '=', $sear['shipping_status']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
//        $maxId = $this->where($map)->group('shipping_code')->column('max(id)');
//        $list = $this->where(['id' => $maxId])->order('node_time desc')->select()->toArray();
        $maxId = $this->where($map)->group('shipping_code')->field('max(id) as id')->buildSql();
        $list = Db::table($maxId . ' a')->join('sp_shipping_detail b', 'a.id = b.id')->select()->toArray();
        $allShipping = 0;
        $aStatusTile = [];
        if (!empty($list)) {
            foreach ($list as $key => $value) {
                $aList[$value['shipping_code']] = $value['shipping_code'];
                $statusList[$value['shipping_status']][$value['shipping_code']] = $value['shipping_code'];
            }
            $allShipping = count(array_unique($aList));
            $shipStatus = [0 => '在途', 1 => '揽收', 2 => '疑难', 3 => '签收', 4 => '退签', 5 => '派件', 6 => '退回', 7 => '转投', -1 => '平台发货'];
            foreach ($shipStatus as $key => $value) {
                $aStatusTile[$key]['status'] = $key;
                $aStatusTile[$key]['title'] = $value;
                $aStatusTile[$key]['scale'] = sprintf("%.2f", (count(array_unique($statusList[$key] ?? []))) / $allShipping);
            }

        }

        $all['all'] = $allShipping;
        $all['status'] = array_values($aStatusTile) ?? [];
        cache($cacheKey, $all, $cacheExpire);

        return $all;
    }

    public function company()
    {
        return $this->hasOne('ShippingCompany', 'company_code', 'company')->bind(['company_name' => 'company']);
    }

}