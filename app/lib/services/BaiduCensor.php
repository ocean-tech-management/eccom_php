<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 百度内容审核Service 可支持图像和文本审查]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;

use app\lib\vendor\baidu\AipImageCensor;

class BaiduCensor
{

    private $appId = '18530707';
    private $apiKey = 'I5DuMI9iXGtgS9UoYea5vGR0';
    private $secretKey = 'qtgnGUViKtlG93nXEdO0kIYmYMT2Y8Mm';

    /**
     * @title  内容文本审核
     * @param string $content 内容
     * @return mixed
     */
    public function contentCensor(string $content)
    {
        $client = new AipImageCensor($this->appId, $this->apiKey, $this->secretKey);
        $result = $client->textCensorUserDefined($content);
        if (isset($result['error_code'])) {
            $result['conclusionType'] = 88;
            $result['conclusion'] = '调用服务有误';
        }
        switch ($result['conclusionType']) {
            case 1:
                $res = true;
                $level = 'info';
                break;
            case 8:
                $res = true;
                $level = 'error';
                break;
            default:
                $res = false;
                $level = 'error';
        }
        $result['content'] = $content;
        (new Log())->setChannel('baidu')->record($result, $level);
        return $res;
    }


}