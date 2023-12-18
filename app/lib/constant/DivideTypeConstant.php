<?php

namespace app\lib\constant;

class DivideTypeConstant
{
    //分润类型 1为分销 2为广宣奖  3为感恩奖 4为转售订单奖励 5为团队业绩奖 6为股东奖 7为区域代理奖 8为众筹奖励 9为股票奖励 12为消费金分红
    /** 分销  */
    const NORMAL_DIVIDE_TYPE = 1;

    /** 广宣奖  */
    const AD_DIVIDE_TYPE = 2;

    /** 感恩奖  */
    const GRATEFUL_DIVIDE_TYPE = 3;

    /** 转售订单奖励  */
    const TRANSFORM_DIVIDE_TYPE = 4;

    /** 团队业绩奖  */
    const TEAM_DIVIDE_TYPE = 5;

    /** 股东奖  */
    const SHAREHOLDER_DIVIDE_TYPE = 6;

    /** 区域代理奖  */
    const AREA_DIVIDE_TYPE = 7;

    /** 众筹奖励  */
    const CROWD_DIVIDE_TYPE = 8;

    /** 股票奖励  */
    const STOCKS_DIVIDE_TYPE = 9;

    /** 消费金分红 */
    const CONSUME_DIVIDE_TYPE = 12;
}