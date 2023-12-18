<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\Category as CategoryModel;

class Category extends BaseController
{
    /**
     * @title  分类列表
     * @param CategoryModel $model
     * @return string
     * @throws \Exception
     */
    public function list(CategoryModel $model)
    {
        $data = $this->requestData;
        $data['type'] = 2;
        $list = $model->list($data);
        return returnData($list['list']);
    }
}