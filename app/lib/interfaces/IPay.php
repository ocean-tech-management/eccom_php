<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 支付Service规范接口类]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\interfaces;


interface IPay
{
    /**
     * @title  下单
     * @param array $data
     * @return mixed
     */
    public function order(array $data);

    /**
     * @title  支付信息对账单
     * @param string $out_trade_no
     * @return mixed
     */
    public function orderQuery(string $out_trade_no);

    /**
     * @title  退款
     * @param array $data
     * @return mixed
     */
    public function refund(array $data);

    /**
     * @title  退款信息对账单
     * @param string $out_refund_no
     * @return mixed
     */
    public function refundQuery(string $out_refund_no);


}