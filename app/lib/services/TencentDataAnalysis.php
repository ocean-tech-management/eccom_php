<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 腾讯数据分析Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


class TencentDataAnalysis
{
    private $appId;
    private $secretKey;

    public function __construct()
    {
        $config = config('system.tencent');
        $this->appId = $config['appId'];
        $this->secretKey = $config['secretKey'];
    }

    /**
     * @title  应用历史趋势
     * @param array $sear 搜索条件
     * @return array|mixed
     */
    public function coreData(array $sear)
    {
        $url = 'https://mta.qq.com/h5/api/ctr_core_data';
        $params['start_date'] = $sear['start_time'] ?? date('Y-m-d', strtotime('-7 day'));
        $params['end_date'] = $sear['end_time'] ?? date('Y-m-d', strtotime('-1 day'));
        $params['idx'] = 'pv,uv,vv,iv';
        $params['app_id'] = $this->appId;
        $res = $this->request($url, $params);
        $data = [];
        if (!empty($res)) {
            if (!empty($res['data'])) {
                $data = $res['data'];
                $allDate = getDateFromRange($params['start_date'], $params['end_date'], 'Ymd');
                $allDateNum = count($allDate);
                //补齐没有数据的时间
                if (count($data) != $allDateNum) {
                    $existDate = array_keys($data);
                    foreach ($allDate as $key => $value) {
                        foreach ($data as $k => $v) {
                            if (!in_array($value, $existDate)) {
                                $data[$value]['pv'] = 0;
                                $data[$value]['uv'] = 0;
                                $data[$value]['vv'] = 0;
                                $data[$value]['iv'] = 0;
                            }
                        }
                    }
                }
                ksort($data);
            }
        }

        return $data;
    }

    /**
     * @title  请求
     * @param string $url 请求地址
     * @param array $params 请求参数
     * @return mixed
     */
    public function request(string $url, array $params)
    {
        $sign = $this->buildSign($params);
        $params['sign'] = $sign;
        ksort($params);
        $urlParam = $this->buildUrlParam($params);
        $requestUrl = $url . '?' . $urlParam;
        $res = curl_get($requestUrl);
        return json_decode($res, 1);
    }

    /**
     * @title  生成Url参数
     * @param array $params 请求参数
     * @return bool|string|null
     */
    public function buildUrlParam(array $params)
    {
        $urls = null;
        foreach ($params as $key => $value) {
            $urls .= $key . '=' . $value . '&';
        }
        $urls = substr($urls, 0, strlen($urls) - 1);
        return $urls;
    }

    /**
     * @title  生成签名
     * @param array $params
     * @return string
     */
    public function buildSign(array $params)
    {
        $secret_key = $this->secretKey;
        ksort($params);
        foreach ($params as $key => $value) {
            $secret_key .= $key . '=' . $value;
        }
        $sign = md5($secret_key);
        return $sign;
    }
}