<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 积分模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


class Integral
{
    private $proportion = 100;  //换算比例 钱:积分=1:100
    private $percentage = 1.00;

    /**
     * @title  积分换算规则(积分换算成钱)
     * @param int $integral 积分
     * @return bool|string
     * @remark 抵扣比例 1积分1分钱,100积分一元
     */
    public function integralToMoney(int $integral)
    {
        return priceFormat(($integral / $this->proportion));
    }

    /**
     * @title  积分换算规则(钱换算成积分)
     * @param string $money
     * @return int
     * @remark 抵扣比例 1积分1分钱,100积分一元
     */
    public function moneyToIntegral(string $money): int
    {
        return intval((double)$money * $this->proportion);
    }
}