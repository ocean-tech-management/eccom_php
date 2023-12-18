<?php

namespace app\controller\open\v1;

use app\BaseController;
use app\lib\services\Log;

class Test extends BaseController
{
    /**
     * @title 测试
     * @return mixed
     */
    public function ddsTest()
    {
        (new Log())->setChannel('transpond')->record(1);
        $data = $this->requestData;
        $res = 1;
        return returnData($res);
    }

}