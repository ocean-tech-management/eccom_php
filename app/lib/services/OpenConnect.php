<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 连接第三方开放平台模块Service]
// +----------------------------------------------------------------------



namespace app\lib\services;

use app\lib\exceptions\OpenException;
use app\lib\models\OpenConnect as OpenConnectModel;

class OpenConnect
{
    protected $appId;
    protected $secret;
    protected $requestUrl;


    public function setConfig(int $appId)
    {
        $info = OpenConnectModel::where(['appId' => $appId, 'status' => 1])->findOrEmpty()->toArray();
        if (!empty($info)) {
            $this->appId = $info['appId'];
            $this->secret = $info['secretKey'];
            $this->requestUrl = $info['requestUrl'];
        } else {
            throw new OpenException(['msg' => '不存在的开发者']);
        }
        return $this;
    }

    /**
     * @title  获取模块对应的请求地址
     * @param string $module
     * @return mixed|null
     */
    public function getModuleUrl(string $module)
    {
        $url = [
            'goodsList' => '/open/v1/goods/list',
            'goodsInfo' => '/open/v1/goods/info'
        ];
        return $url[$module] ?? null;
    }

    /**
     * @title  公共通用请求
     * @param string $module 请求模块,获取请求地址
     * @param array $data 请求参数
     * @return array
     */
    public function normalRequest(string $module, array $data)
    {
        $param = $data;
        $param['appId'] = $this->appId;
        $param['secretKey'] = $this->secret;

        $requestData['schema'] = 'json';
        $requestData['param'] = json_encode($param, 256);

        $moduleUrl = $this->getModuleUrl($module);
        if (empty($moduleUrl)) {
            throw new OpenException(['msg' => '未定义的模块地址']);
        }

        $requestUrl = $this->requestUrl . $moduleUrl;

        $res = $this->httpRequest($requestUrl, $requestData);

        if (empty($res)) {
            throw new OpenException(['msg' => '三方服务有误,请稍后重试']);
        }

        $returnData = [];
        $returnData = json_decode($res, true);

        return $returnData;
    }


    /**
     * @title  curl请求
     * @param string $url 请求地址
     * @param string $data
     * @return bool|string
     */
    private function httpRequest(string $url, $data = "", $header = false)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        $headers = array(
            "Content-type: application/json;charset='utf-8'",
        );
        if (!empty($header)) {
            $headers = array_merge_recursive($headers, $header);
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        if (!empty($data)) { //判断是否为POST请求
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }

}