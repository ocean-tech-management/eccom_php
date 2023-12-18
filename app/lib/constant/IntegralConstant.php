<?php

namespace app\lib\constant;

class IntegralConstant
{
    //积分明细变更类型 1为签到 2为订单分润 3消费 4为团队奖励 5为众筹奖励 6为商城购物赠送 7为后台充值美丽金赠送 8为后台直接操作

    /** 签到  */
    const INTEGRAL_CHANGE_SIGN = 1;

    /** 订单分润  */
    const INTEGRAL_CHANGE_DIVIDE = 2;

    /** 消费  */
    const INTEGRAL_CHANGE_USE = 3;

    /** 团队奖励  */
    const INTEGRAL_CHANGE_TEAM_DIVIDE = 4;

    /** 众筹奖励  */
    const INTEGRAL_CHANGE_CROWD = 5;

    /** 商城购物赠送  */
    const INTEGRAL_CHANGE_SHOP = 6;

    /** 后台充值美丽金赠送  */
    const INTEGRAL_CHANGE_CROWD_RECHARGE = 7;

    /** 后台操作  */
    const INTEGRAL_CHANGE_SYSTEM = 8;
}