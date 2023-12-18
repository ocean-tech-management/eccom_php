<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品属性Key模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\services\Code;
use app\lib\services\CodeBuilder;
use think\facade\Db;
use app\lib\validates\AttributeKey as AttributeKeyValidate;

class AttributeKey extends BaseModel
{
    protected $field = ['category_code', 'attribute_code', 'attribute_name', 'desc', 'status', 'attr_sn'];

    protected $validateFields = ['category_code', 'attribute_code', 'attribute_name'];

    /**
     * @title  属性key列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('attribute_name', $sear['keyword']))];
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

        $list = $this->with(['category', 'values'])->where($map)->field('category_code,attribute_code,attribute_name,status,desc,attr_sn,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        if (!empty($list)) {
            //补充父类分类
            $categoryCode = array_column($list, 'category_code');
            if (!empty($categoryCode)) {
                $categoryInfo = Category::with(['parent'])->where(['code' => $categoryCode])->select()->toArray();
                if (!empty($categoryInfo)) {
                    foreach ($list as $key => $value) {
                        if (!isset($list[$key]['p_category_code'])) {
                            $list[$key]['p_category_code'] = null;
                            $list[$key]['p_category_name'] = null;
                        }
                        foreach ($categoryInfo as $cKey => $cValue) {
                            if ($value['category_code'] == $cValue['code']) {
                                $list[$key]['p_category_code'] = $cValue['parent']['code'];
                                $list[$key]['p_category_name'] = $cValue['parent']['name'];
                            }
                        }
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    public function info(string $attrKeyCode)
    {
        return $this->with(['category', 'values'])->where(['attribute_code' => $attrKeyCode, 'status' => [1, 2]])->findOrEmpty()->toArray();
    }

    /**
     * @title  新增
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $data['attribute_code'] = (new CodeBuilder())->buildAttributeCode($data['category_code']);
        (new AttributeKeyValidate())->goCheck($data, 'create');
        return $this->validate()->baseCreate($data);
    }

    /**
     * @title  编辑
     * @param array $data
     * @return mixed
     *
     * @throws \Exception
     */
    public function edit(array $data)
    {
        (new AttributeKeyValidate())->goCheck($data, 'edit');
        $info = $this->where(['attribute_code' => $data['attribute_code']])->field('category_code,attribute_name')->findOrEmpty()->toArray();

        //暂时注释,不需要强制修改原有的规格名
//        if ($info['attribute_name'] != $data['attribute_name']) {
//            //修改SPU属性列表对应要修改的key名
//            $category = $info['category_code'];
//            $allGoods = GoodsSpu::where(['category_code' => $category])->field('goods_sn,category_code,attribute_list')->select()->toArray();
//            if(!empty($allGoods)){
//                foreach ($allGoods as $key => $value) {
//                    $pos = strpos($value['attribute_list'], $info['attribute_name']);
//                    if (!empty($pos)) {
//                        $allGoods[$key]['attribute_list'] = substr_replace($value['attribute_list'], $data['attribute_name'], $pos, strlen($info['attribute_name']));
//                        GoodsSpu::update(['attribute_list'=>$allGoods[$key]['attribute_list']],['goods_sn'=>$value['goods_sn']]);
//                    }
//                }
//                $goodsSn = array_column($allGoods,'goods_sn');
//                //修改SKU对应的Key名
//                $allSku = GoodsSku::where(['goods_sn' => $goodsSn])->field('goods_sn,sku_sn,specs')->select()->toArray();
//                if(!empty($allSku)){
//                    foreach ($allSku as $key => $value) {
//                        $pos = strpos($value['specs'], $info['attribute_name']);
//                        if (!empty($pos)) {
//                            $allSku[$key]['specs'] = substr_replace($value['specs'], $data['attribute_name'], $pos, strlen($info['attribute_name']));
//                            GoodsSku::update(['specs'=>$allSku[$key]['specs']],['sku_sn'=>$value['sku_sn'],'goods_sn'=>$value['goods_sn']]);
//                        }
//                    }
//                }
//            }
//        }

        return $this->validate()->baseUpdate(['attribute_code' => $data['attribute_code']], $data);
    }

    /**
     * @title  删除
     * @param string $attrKeyCode
     * @return AttributeKey
     */
    public function del(string $attrKeyCode)
    {
        return $this->baseDelete(['attribute_code' => $attrKeyCode]);
    }

    public function category()
    {
        return $this->hasOne('Category', 'code', 'category_code')->bind(['category_name' => 'name']);
    }

    public function values()
    {
        return $this->hasMany('AttributeValue', 'attribute_code', 'attribute_code')->where(['status' => 1])->order('sort asc')->field('id,attribute_code,attribute_value,sort,remark');
    }

}