<?php

namespace app\controller\open\v1;

use app\BaseController;
use app\lib\services\Log;

class Debug extends BaseController
{
    /**
     * @title debug日志记录
     * @return mixed
     */
    public function debugRecordLog()
    {
        $data = $this->requestData;
        unset($data['version']);
        if (!empty($data)) {
            (new Log())->setChannel('debugLog')->record($data);
        }
        return returnData(true);
    }

}