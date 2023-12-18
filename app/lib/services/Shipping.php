<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 快递模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


class Shipping
{
    private $key;                                                //客户授权key
    protected $requestUrl = 'http://poll.kuaidi100.com/poll';    //订阅请求地址

    public function __construct()
    {
        $config = config('system.shipping');
        $this->key = $config['key'];
    }

    /**
     * @title  提交物流订阅
     * @param array $data
     * @return mixed
     */
    public function subscribe(array $data)
    {
        $param = array(
            'company' => $data['company'] ?? '',                    //快递公司编码
            'number' => $data['shipping_code'],                        //快递单号
            'from' => $data['start_city'] ?? '',                    //出发地城市
            'to' => $data['end_city'] ?? '',                        //目的地城市
            'key' => $this->key,                                    //客户授权key
            'parameters' => array(
                'callbackurl' => config('system.callback.shippingCallBackUrl'),        //回调地址
                'salt' => '',                                        //加密串
                'resultv2' => '1',                                    //行政区域解析
                'autoCom' => !empty($data['company']) ? '0' : '1',  //单号智能识别
                'interCom' => '0',                                    //开启国际版
                'departureCountry' => '',                            //出发国
                'departureCom' => '',                                //出发国快递公司编码
                'destinationCountry' => '',                            //目的国
                'destinationCom' => '',                                //目的国快递公司编码
                'phone' => $data['user_phone'] ?? ''                //手机号
            )
        );
        //请求参数
        $post_data = array();
        $post_data["schema"] = 'json';
        $post_data["param"] = json_encode($param);
        $url = $this->requestUrl;
        $params = "";
        foreach ($post_data as $k => $v) {
            $params .= "$k=" . urlencode($v) . "&";        //默认UTF-8编码格式
        }
        $post_data = substr($params, 0, -1);
        $res = curl_post($url, $post_data);
        $result = str_replace("\"", '"', $res);
        $result = json_decode($result, 1);

        //记录日志
        $log['data'] = $data;
        $log['subscribeRes'] = $result;
        (new Log())->setChannel('shipping')->record($log, 'info');
        return $result;
    }
}