<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\services\CodeBuilder;
use app\lib\validates\Brand as BrandValidate;
use think\facade\Db;

class Brand extends BaseModel
{
    protected $field = ['brand_code', 'brand_name', 'category_code', 'sort', 'status'];
    protected $validateFields = ['brand_code', 'brand_name', 'category_code', 'sort' => 'number', 'status' => 'number'];

    /**
     * @title  品牌列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('brand_name', $sear['keyword']))];
        }
        if (!empty($sear['category_code'])) {
            $map[] = ['category_code', '=', $sear['category_code']];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['category'])->where($map)->field('category_code,brand_code,brand_name,status,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        if (!empty($list)) {
            $aCategory = array_column($list, 'category_code');
            $pCategory = Category::with(['parent'])->where(['code' => $aCategory, 'status' => [1, 2]])->select()->toArray();
            if (!empty($pCategory)) {
                foreach ($list as $key => $value) {
                    foreach ($pCategory as $pcKey => $pcValue) {
                        if ($value['category_code'] == $pcValue['code']) {
                            if (!empty($pcValue['parent'])) {
                                $list[$key]['p_category_code'] = $pcValue['parent']['code'];
                                $list[$key]['p_category_name'] = $pcValue['parent']['name'];
                            }
                        }
                    }
                }
            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新增品牌
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $data['brand_name'] = trim($data['brand_name']);
        $data['brand_code'] = (new CodeBuilder())->buildBrandCode($this, $data['brand_name'], $data['category_code']);
        (new BrandValidate())->goCheck($data, 'create');
        return $this->validate()->baseCreate($data, true);
    }

    /**
     * @title  品牌详情
     * @param string $brandCode
     * @return mixed
     */
    public function info(string $brandCode)
    {
        return $this->with(['category'])->where(['brand_code' => $brandCode, 'status' => [1, 2]])->field('brand_code,brand_name,category_code,status,create_time')->findOrEmpty()->toArray();
    }

    /**
     * @title  更新品牌
     * @param array $data
     * @return Brand
     */
    public function edit(array $data)
    {
        $data['brand_name'] = trim($data['brand_name']);
        (new BrandValidate())->goCheck($data, 'edit');
        return $this->validate()->baseUpdate(['brand_code' => $data['brand_code']], $data);
    }

    /**
     * @title  删除品牌
     * @param string $brandCode 品牌编码
     * @return Brand
     */
    public function del(string $brandCode)
    {
        return $this->baseDelete(['brand_code' => $brandCode]);
    }

    /**
     * @title  上下架品牌
     * @param array $data
     * @return Brand|bool
     */
    public function upOrDown(array $data)
    {
        if ($data['status'] == 1) {
            $save['status'] = 2;
        } elseif ($data['status'] == 2) {
            $save['status'] = 1;
        } else {
            return false;
        }
        return $this->baseUpdate(['brand_code' => $data['brand_code']], $save);
    }


    public function category()
    {
        return $this->hasOne('Category', 'code', 'category_code')->bind(['category_name' => 'name']);
    }
}