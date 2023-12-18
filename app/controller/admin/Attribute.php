<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品属性模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\exceptions\ServiceException;
use app\lib\models\AttributeKey;
use app\lib\models\AttributeValue;
use League\Flysystem\Plugin\EmptyDir;
use think\facade\Db;

class Attribute extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  属性Key列表
     * @param AttributeKey $model
     * @return string
     * @throws \Exception
     */
    public function keyList(AttributeKey $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  新增属性Key
     * @param AttributeKey $model
     * @return string
     */
    public function keyCreate(AttributeKey $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑属性key
     * @param AttributeKey $model
     * @return string
     * @throws \Exception
     */
    public function keyUpdate(AttributeKey $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除属性key
     * @param AttributeKey $model
     * @return string
     */
    public function keyDelete(AttributeKey $model)
    {
        $res = $model->del($this->request->param('attribute_code'));
        return returnMsg($res);
    }

    /**
     * @title  属性Value列表
     * @param AttributeValue $model
     * @return string
     * @throws \Exception
     */
    public function valueList(AttributeValue $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  新增属性value
     * @param AttributeValue $model
     * @return string
     */
    public function valueCreate(AttributeValue $model)
    {
        $res = $model->newOrEdit($this->requestData);
        return returnData($res);
    }

    /**
     * @title  编辑属性value
     * @param AttributeValue $model
     * @return string
     */
    public function valueUpdate(AttributeValue $model)
    {
        $res = $model->newOrEdit($this->requestData);
        return returnData($res);
    }

    /**
     * @title  删除属性value
     * @param AttributeValue $model
     * @return string
     */
    public function valueDelete(AttributeValue $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  二合一新增
     * @return string
     */
    public function create()
    {
        $res = Db::transaction(function () {
            $requestData = $this->requestData;
            $keyRes = (new AttributeKey())->new($requestData);
            $createKey = $keyRes->getData();
            if (!empty($createKey)) {
                $valueData['attribute_code'] = $createKey['attribute_code'];
                $AttributeValue = (new AttributeValue());
                if (!empty($requestData['attributeValues'])) {
                    //检查属性值唯一性
                    $check['attribute_value'] = array_column($requestData['attributeValues'], 'attribute_value');
                    $check['category_code'] = $requestData['category_code'];
                    //强行加上规格编码的限制,临时措施
                    if (!empty($requestData['attr_sn'])) {
                        $check['attr_sn'] = $requestData['attr_sn'];
                    }
                    $repeatList = $AttributeValue->checkUnique($check);
                    if (!empty($repeatList)) {
                        throw new ServiceException(['msg' => '属性值 ' . implode(',', $repeatList) . ' 在该分类已经存在']);
                    }
                    foreach ($requestData['attributeValues'] as $key => $value) {
                        $valueData['attribute_value'] = $value['attribute_value'];
                        $valueData['sort'] = $value['sort'] ?? 0;
                        $valueData['category_code'] = $requestData['category_code'];

                        //强行加上规格编码,临时措施
                        $valueData['attr_sn'] = $requestData['attr_sn'] ?? null;
                        $AttributeValue->new($valueData);
                    }
                }
            }
            return $keyRes;
        });
        return returnMsg($res);
    }
}