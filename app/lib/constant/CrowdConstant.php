<?php

namespace app\lib\constant;

class CrowdConstant
{
    //众筹余额明细变更类型 1为充值 2为众筹消费 3为退回本金 4为奖励 5为转入商城余额 6为提现 7为转换给其他人 8为转换到其他钱包 9收到转让 10抽奖中奖 11熔断分期返回  12后台充值但不计入业绩 13为售后退款

    /** 签到  */
    const CROWD_CHANGE_RECHARGE = 1;

    /** 众筹消费  */
    const CROWD_CHANGE_USE = 2;

    /** 退回本金  */
    const CROWD_CHANGE_REFUND = 3;

    /** 奖励  */
    const CROWD_CHANGE_REWARD = 4;

    /** 转入商城余额  */
    const CROWD_CHANGE_TRANSFORM_TO_SHOP = 5;

    /** 提现  */
    const CROWD_CHANGE_WITHDRAW = 6;

    /** 转换给其他人  */
    const CROWD_CHANGE_TRANSFORM_OUT = 7;

    /** 转换到其他钱包  */
    const CROWD_CHANGE_TRANSFORM_TO_OTHER = 8;

    /** 收到他人转换  */
    const CROWD_CHANGE_TRANSFORM_IN = 9;

    /** 抽奖中奖  */
    const CROWD_CHANGE_LOTTERY = 10;

    /** 熔断分期返回 */
    const CROWD_CHANGE_FUSE = 11;

    /** 后台充值但不计入业绩 */
    const CROWD_CHANGE_SPECIAL_RECHARGE = 12;

    /** 售后退款 */
    const CROWD_CHANGE_AFTERSALE = 13;

}