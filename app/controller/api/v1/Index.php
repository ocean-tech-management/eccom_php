<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;

class Index extends BaseController
{
    public function index()
    {
        echo '这是前端的接口V1版本a a a a  ';
    }

    public function test()
    {
        dump(current(explode('/', trim(\think\facade\Request::pathinfo()))));
    }
}