<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品属性Value模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\services\CodeBuilder;
use think\facade\Db;
use app\lib\validates\AttributeValue as AttributeValueValidate;

class AttributeValue extends BaseModel
{
    protected $field = ['attribute_code', 'attribute_value', 'sort', 'remark', 'status'];

    protected $validateFields = ['attribute_code', 'attribute_value'];

    /**
     * @title  属性key列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('attribute_value', $sear['keyword']))];
        }
        if (!empty($sear['attribute_code'])) {
            $map[] = ['attribute_code', '=', $sear['attribute_code']];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['parent'])->where($map)->field('id,attribute_code,attribute_value,status,remark,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  属性Key对应的value详情
     * @param string $attrKeyCode 属性key编码
     * @return mixed
     */
    public function info(string $attrKeyCode)
    {
        return $this->with(['parent'])->where(['attribute_code' => $attrKeyCode, 'status' => [1, 2]])->findOrEmpty()->toArray();
    }

    /**
     * @title  新增或编辑
     * @param array $data
     * @return mixed
     */
    public function newOrEdit(array $data)
    {
        $res = Db::transaction(function () use ($data) {
            $scene = 'create';
            $dbRes = false;
            $dbResData = [];
            if (!empty($data)) {
                $attrCode = current(array_column($data, 'attribute_code'));
                $keyInfo = (new AttributeKey())->info($attrCode);
                $categoryCode = $keyInfo['category_code'];
                $allGoods = GoodsSpu::where(['category_code' => $categoryCode])->field('goods_sn,category_code,attribute_list')->select()->toArray();
                $allId = array_unique(array_filter(array_column($data, $this->getPk())));
                $oldValue = $this->where([$this->getPk() => $allId])->column('attribute_value', 'id');
                $validate = (new AttributeValueValidate());
                foreach ($data as $key => $value) {
                    if (!empty($value[$this->getPk()])) {
                        $scene = 'edit';
                    }
                    $value['category_code'] = $categoryCode;
                    $validate->goCheck($value, $scene);
                    //暂时注释,先不需要强制同步属性值
                    //如果value发生了改变需要对应一起修改所有商品的Value值
//                    if (!empty($value[$this->getPk()]) && $value['attribute_value'] != $oldValue[$value[$this->getPk()]]) {
//                        //修改SPU中的相关属性value值
//                        if (!empty($allGoods)) {
//                            foreach ($allGoods as $gKey => $gValue) {
//                                $goodsAttr = json_decode($gValue['attribute_list'], true);
//                                foreach ($goodsAttr as $gkKey => $gkValue) {
//                                    if ($gkKey == $keyInfo['attribute_name']) {
//                                        foreach ($gkValue as $ggkKey => $ggValue) {
//                                            if ($ggkKey == $oldValue[$value[$this->getPk()]]) {
//                                                unset($goodsAttr[$gkKey][$ggkKey]);
//                                                $goodsAttr[$gkKey][$value['attribute_value']] = $ggValue;
//                                                $needSave[$gValue['goods_sn']] = true;
//                                            }
//                                        }
//                                    }
//                                }
//                                $allGoods[$gKey]['attribute_list'] = json_encode($goodsAttr, JSON_UNESCAPED_UNICODE);
//                                if (!empty($needSave[$gValue['goods_sn']])) {
//                                    $needSaveGoods[$gValue['goods_sn']] = $allGoods[$gKey]['attribute_list'];
//                                }
//                            }
//                            //修改SKU
//                            if (!empty($needSaveGoods)) {
//                                $goodsSn = array_keys($needSaveGoods);
//                                //修改SKU对应的Key名
//                                $allSku = GoodsSku::where(['goods_sn' => $goodsSn])->field('goods_sn,sku_sn,specs')->select()->toArray();
//                                if (!empty($allSku)) {
//                                    foreach ($allSku as $sKey => $sValue) {
//                                        $skuAttr = json_decode($sValue['specs'], true);
//                                        foreach ($skuAttr as $ssKey => $ssValue) {
//                                            if ($ssValue == $oldValue[$value[$this->getPk()]]) {
//                                                unset($skuAttr[$ssKey]);
//                                                $skuAttr[$ssKey] = $value['attribute_value'];
//                                                $needSaveSku[$sValue['sku_sn']] = true;
//                                            }
//                                        }
//                                        $allSku[$sKey]['specs'] = json_encode($skuAttr, JSON_UNESCAPED_UNICODE);
//                                        if (!empty($needSaveSku[$sValue['sku_sn']])) {
//                                            $needSaveSkuGoods[$sValue['sku_sn']] = $allSku[$sKey]['specs'];
//                                        }
//                                    }
//                                }
//                            }
//                        }
//                        if(!empty($needSaveGoods)){
//                            foreach ($needSaveGoods as $sgKey => $sgValue) {
//                                GoodsSpu::update(['attribute_list'=>$sgValue],['goods_sn'=>$sgKey]);
//                            }
//                        }
//                        if(!empty($needSaveSkuGoods)){
//                            foreach ($needSaveSkuGoods as $skgKey => $skgValue) {
//                                GoodsSku::update(['specs'=>$skgValue],['sku_sn'=>$skgKey]);
//                            }
//                        }
//                    }

                    $dbRes = $this->validate()->updateOrCreate([$this->getPk() => $value[$this->getPk()]], $value);
                    $dbData = $dbRes->getData();
                    if (!empty($dbData) && $scene == 'create') {
                        $dbResData[] = $dbData[$this->getPk()];
                    }
                }
            }

            return $dbResData;
        });
        return $res;
    }

    /**
     * @title  新增
     * @param array $data
     * @return AttributeValue
     */
    public function new(array $data)
    {
        (new AttributeValueValidate())->goCheck($data, 'create');
        return $this->validate()->baseCreate($data);
    }

    /**
     * @title  编辑
     * @param array $data
     * @return AttributeValue
     */
    public function edit(array $data)
    {
        (new AttributeValueValidate())->goCheck($data, 'edit');
        return $this->validate()->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
    }

    /**
     * @title  删除
     * @param int $id
     * @return AttributeValue
     */
    public function del(int $id)
    {
        return $this->baseDelete([$this->getPk() => $id]);
    }

    /**
     * @title  检查属性规格是否有重复
     * @param array $data
     * @return mixed
     */
    public function checkUnique(array $data)
    {
        $map[] = ['a.status', 'in', [1, 2]];
        if (!empty($data['category_code'])) {
            $map[] = ['b.category_code', '=', $data['category_code']];
        }
        if (!empty($data['id'])) {
            $map[] = ['a.id', '<>', $data['id']];
        }
        if (!empty($data['attr_sn'])) {
            $map[] = ['b.attr_sn', '=', $data['attr_sn']];
        }
        $map[] = ['a.attribute_value', 'in', $data['attribute_value']];

        $list = $this->alias('a')
            ->join('sp_attribute_key b', 'a.attribute_code = b.attribute_code', 'left')
            ->where($map)
            ->column('a.attribute_value');
        return $list;

    }

    public function parent()
    {
        return $this->hasOne('AttributeKey', 'attribute_code', 'attribute_code')->bind(['AttributeKeyName' => 'attribute_name']);
    }
}