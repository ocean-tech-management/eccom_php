<?php

namespace app\controller\api\v1;

use app\BaseController;
use app\lib\services\AuthType;
class AuthPlatForm extends BaseController
{

    /**
     * 授权方式
     * @return string
     */
    public function userLoginType()
    {
        $data = $this->requestData;
        $rData = (new AuthType())->checkUserAuth($data);
        return returnData($rData);
    }

}