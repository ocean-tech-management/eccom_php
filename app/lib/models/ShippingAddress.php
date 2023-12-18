<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 收货地址模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use Exception;
use think\facade\Db;

class ShippingAddress extends BaseModel
{
    protected $field = ['uid', 'name', 'phone', 'province', 'city', 'area', 'address', 'post_code', 'is_default', 'status' => 'number'];
    protected $validateFields = ['uid', 'name', 'phone', 'status' => 'in:1,2', 'post_code' => 'length:6'];

    /**
     * @title  收货地址列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', $sear['keyword']))];
        }
        $map[] = ['uid', '=', $sear['uid']];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['provinceCode'])->where($map)->field('id,name,uid,phone,province,city,area,address,post_code,is_default,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('is_default asc,create_time desc')->select()->each(function ($item) {
            $item['city_code'] = null;
            if (!empty($item['province_code'])) {
                $item['city_code'] = City::where(['p_code' => $item['province_code'], 'status' => 1, 'name' => $item['city']])->value('code');
            }
            $item['area_code'] = null;
            if (!empty($item['city_code'] ?? null)) {
                $item['area_code'] = City::where(['p_code' => $item['city_code'], 'status' => 1, 'name' => $item['area']])->value('code');
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  收货地址详情
     * @param array $data
     * @return mixed
     */
    public function info(array $data)
    {
        return $this->where(['id' => $data['id']])->field('id,name,uid,phone,province,city,area,address,post_code,is_default')->findOrEmpty()->toArray();
    }

    /**
     * @title  新增
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $res = $this->validate()->baseCreate($data, true);
        $this->changeDefault($res, $data);
        return $res;
    }

    /**
     * @title  编辑
     * @param array $data
     * @return ShippingAddress
     */
    public function edit(array $data)
    {
        $res = $this->validate()->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
        $this->changeDefault($data[$this->getPk()], $data);
        return $res;
    }

    /**
     * @title  删除
     * @param string $id id
     * @return ShippingAddress
     */
    public function del(string $id)
    {
        return $this->baseDelete([$this->getPk() => $id]);
    }

    public function changeDefault(string $id, array $data)
    {
        $res = false;
        $default = $data['is_default'];
        $map[] = ['id', '<>', $id];
        $map[] = ['uid', '=', $data['uid']];
        $map[] = ['is_default', '=', $default];
        $map[] = ['status', '=', 1];
        $old = $this->where($map)->value('id');
        $now = $this->where(['id' => $id, 'status' => 1])->value('is_default');
        //如果原来有默认地址,则修改原来的,没有一个默认地址都没有则当前地址为默认地址
        if (!empty($old)) {
            if ($default == 1 && ($old != $id)) {
                $res = $this->validate(false)->baseUpdate(['id' => $old, 'status' => 1], ['is_default' => 2]);
            }
        } else {
            if ($now != 1) {
                $this->validate(false)->baseUpdate(['id' => $now, 'status' => 1], ['is_default' => 1]);
            }
        }
        return judge($res);
    }

    public function provinceCode()
    {
        return $this->hasOne('City', 'name', 'province')->where(['type' => 1, 'status' => 1])->bind(['province_code' => 'code']);
    }

    public function cityCode()
    {
        return $this->hasOne('City', 'name', 'city')->where(['type' => 2, 'status' => 1])->bind(['city_code' => 'code']);
    }

    public function areaCode()
    {
        return $this->hasOne('City', 'name', 'area')->where(['type' => 3, 'status' => 1])->bind(['area_code' => 'code']);
    }
}