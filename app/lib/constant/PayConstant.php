<?php


namespace app\lib\constant;


class PayConstant
{


    //支付类型 1为余额 2为微信 3为支付宝 4为流水号支付 5为众筹余额支付 6为银行卡协议支付 7为积分支付 8为美丽券支付 9为健康豆支付
    /** 商城余额支付  */
    const PAY_TYPE_BALANCE = 1;
    /** 微信支付  */
    CONST PAY_TYPE_WX = 2;
    /** 支付宝  */
    CONST PAY_TYPE_ALI = 3;
    /** 流水号  */
    CONST PAY_TYPE_SIGN_NO = 4;
    /** 众筹余额支付  */
    CONST PAY_TYPE_CROWD = 5;
    /** 银行卡协议支付  */
    CONST PAY_TYPE_AGREEMENT = 6;
    /** 美丽豆(积分)支付  */
    CONST PAY_TYPE_INTEGRAL = 7;
    /** 美丽券支付  */
    CONST PAY_TYPE_TICKET = 8;
    /** 健康豆支付  */
    CONST PAY_TYPE_HEALTHY = 9;
    /** 健康金支付  */
    CONST PAY_TYPE_HEALTHY_COIN = 10;

    //商城渠道
    const HEALTHY_CHANNEL_TYPE_SHOP = 1;
    //福利渠道
    const HEALTHY_CHANNEL_TYPE_CROWD = 2;
    //消费型股东渠道
    const HEALTHY_CHANNEL_TYPE_CONSUMER_SHAREHOLDER = 3;

    //渠道列表 扣除顺序跟着数组排序走 1->n
    const HEALTHY_CHANNEL_TYPE = [
        1 => self::HEALTHY_CHANNEL_TYPE_SHOP,
        2 => self::HEALTHY_CHANNEL_TYPE_CROWD,
        3 => self::HEALTHY_CHANNEL_TYPE_CONSUMER_SHAREHOLDER
    ];
    // 余额变更明细配置
    CONST HEALTHY_ORDER_TYPE = [
        self::HEALTHY_ORDER_TYPE_TOP_UP => [
            'type' => self::HEALTHY_ORDER_TYPE_TOP_UP,
            'remark' => '充值收入',
            'change' => 1,//此数值用来乘以金额,支出设置为-1
            'belong' => 1,
            'order_sn_field' => 'order_sn', //关联订单表的订单sn字段
        ],
        self::HEALTHY_ORDER_TYPE_CONSUMPTION => [
            'type' => self::HEALTHY_ORDER_TYPE_CONSUMPTION,
            'remark' => '消费支出',
            'change' => -1,//此数值用来乘以金额,支出设置为-1
            'belong' => 1,
            'order_sn_field' => 'order_sn', //关联订单表的订单sn字段
        ],
        self::HEALTHY_ORDER_TYPE_REFUND => [
            'type' => self::HEALTHY_ORDER_TYPE_REFUND,
            'remark' => '退款收入',
            'change' => 1,//此数值用来乘以金额,支出设置为-1
            'belong' => 1,
            'order_sn_field' => 'order_sn', //关联订单表的订单sn字段
        ],
        self::HEALTHY_ORDER_TYPE_GIVING => [
            'type' => self::HEALTHY_ORDER_TYPE_GIVING,
            'remark' => '转赠支出',
            'change' => -1,//此数值用来乘以金额,支出设置为-1
            'belong' => 1,
            'order_sn_field' => 'order_sn', //关联订单表的订单sn字段
        ],
        self::HEALTHY_ORDER_TYPE_RECEIVE => [
            'type' => self::HEALTHY_ORDER_TYPE_RECEIVE,
            'remark' => '收到转赠收入',
            'change' => 1,//此数值用来乘以金额,支出设置为-1
            'belong' => 1,
            'order_sn_field' => 'order_sn', //关联订单表的订单sn字段
        ],
        self::HEALTHY_ORDER_TYPE_REWARD => [
            'type' => self::HEALTHY_ORDER_TYPE_REWARD,
            'remark' => '奖励收入',
            'change' => 1,//此数值用来乘以金额,支出设置为-1
            'belong' => 1,
            'order_sn_field' => 'order_sn', //关联订单表的订单sn字段
        ],
        self::HEALTHY_ORDER_TYPE_SHOPPING => [
            'type' => self::HEALTHY_ORDER_TYPE_SHOPPING,
            'remark' => '购物赠送收入',
            'change' => 1,//此数值用来乘以金额,支出设置为-1
            'belong' => 1,
            'order_sn_field' => 'order_sn', //关联订单表的订单sn字段
        ],
        self::HEALTHY_ORDER_TYPE_DEVICE_USE => [
            'type' => self::HEALTHY_ORDER_TYPE_DEVICE_USE,
            'remark' => '设备消费支出',
            'change' => -1,//此数值用来乘以金额,支出设置为-1
            'belong' => 1,
            'order_sn_field' => 'order_sn', //关联订单表的订单sn字段
        ],
        self::HEALTHY_ORDER_TYPE_CONVER_IN => [
            'type' => self::HEALTHY_ORDER_TYPE_CONVER_IN,
            'remark' => '健康豆转入',
            'change' => -1,//此数值用来乘以金额,支出设置为-1
            'belong' => 1,
            'order_sn_field' => 'order_sn', //关联订单表的订单sn字段
        ],
        self::HEALTHY_ORDER_TYPE_CONVER_OUT => [
            'type' => self::HEALTHY_ORDER_TYPE_CONVER_OUT,
            'remark' => '健康豆支出',
            'change' => -1,//此数值用来乘以金额,支出设置为-1
            'belong' => 1,
            'order_sn_field' => 'order_sn', //关联订单表的订单sn字段
        ],
    ];
    /** 充值 */
    const HEALTHY_ORDER_TYPE_TOP_UP = 1;
    /** 消费 */
    const HEALTHY_ORDER_TYPE_CONSUMPTION = 2;
    /** 退款 */
    const HEALTHY_ORDER_TYPE_REFUND = 3;
    /** 赠送他人 */
    const HEALTHY_ORDER_TYPE_GIVING = 4;
    /** 收到赠送 */
    const HEALTHY_ORDER_TYPE_RECEIVE = 5;
    /** 奖励 */
    const HEALTHY_ORDER_TYPE_REWARD = 6;
    /** 消费获取 */
    const HEALTHY_ORDER_TYPE_SHOPPING = 7;
    /** 设备消费 */
    const HEALTHY_ORDER_TYPE_DEVICE_USE = 8;
    /** 健康豆转入 */
    const HEALTHY_ORDER_TYPE_CONVER_IN = 9;
    /** 健康豆转出 */
    const HEALTHY_ORDER_TYPE_CONVER_OUT = 10;


}