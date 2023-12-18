<?php


namespace app\lib\constant;


class WithdrawConstant
{
    /** 提现单查询锁 */
    const WITHDRAW_EXCEL_LOCK_KEY = "withdraw_excel_lock:";
    /** 提现单查询锁时间 */
    CONST WITHDRAW_EXCEL_LOCK_TIME = 10;
    /** 批量审核提现单锁 */
    const WITHDRAW_BATCH_CHECK_LOCK = "withdraw_batch_check_lock:";
    /** 批量审核提现单锁时间 */
    const WITHDRAW_BATCH_CHECK_TIME = 60;

    const WITHDRAW_EXCEL_DATA_KEY = "withdraw_excel_data:";
    const WITHDRAW_EXCEL_DATA_TIME = 3600;
    const WITHDRAW_EXCEL_SEARCH_DATA_KEY = "withdraw_excel_search_data:";
    const WITHDRAW_EXCEL_SEARCH_DATA_TIME = 3600;


    //1为微信支付 2为汇聚支付 3为杉德支付 4为快商 5为中数科 88为线下打款
    /** 提现打款方式 微信支付  */
    const WITHDRAW_PAY_TYPE_WX = 1;
    /** 提现打款方式 汇聚支付  */
    CONST WITHDRAW_PAY_TYPE_JOIN = 2;
    /** 提现打款方式 杉德支付  */
    CONST WITHDRAW_PAY_TYPE_SHANDE = 3;
    /** 提现打款方式 快商  */
    CONST WITHDRAW_PAY_TYPE_KUAISHANG = 4;
    /** 提现打款方式 中数科  */
    CONST WITHDRAW_PAY_TYPE_ZSK = 5;
    /** 提现打款方式 线下打款  */
    CONST WITHDRAW_PAY_TYPE_OFFLINE = 88;

    /** 微信支付 */
    const WITHDRAW_PAY_WX = "微信支付";
    /** 汇聚支付 */
    const WITHDRAW_PAY_JOIN = "汇聚支付";
    /** 杉德支付 */
    const WITHDRAW_PAY_SHANDE = "杉德支付";
    /** 快商 */
    const WITHDRAW_PAY_KUAISHANG = "快商";
    /** 中数科 */
    const WITHDRAW_PAY_ZSK = "中数科";
    /** 线下打款 */
    const WITHDRAW_PAY_OFFLINE = "线下打款";

    /**
     * 衫德导出数据结构
     */
    const WITHDRAW_EXPORT_SHANDE_DATA = [
            array (
                'title' => '序号（选填）',
                'value' => 'key',
                'validates' => '',
                'width' => '5',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '收款方姓名（必填）',
                'value' => 'user_real_name',
                'validates' => 'require',
                'width' => '15',
                'import' => 1,
                'front_width' => 200,
            ),
            array (
                'title' => '收款方银行卡号（必填）',
                'value' => 'bank_account',
                'validates' => 'require',
                'width' => '25',
                'import' => 1,
                'front_width' => 200,
            ),
            array (
                'title' => '金额（必填，单位：元）',
                'value' => 'price',
                'validates' => 'require|money',
                'width' => '15',
                'import' => 1,
                'front_width' => 200,
            ),
            array (
                'title' => '附言（选填）',
                'value' => 'remark',
                'validates' => '',
                'width' => '25',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '收款人手机号（选填）',
                'value' => 'user_phone',
                'validates' => 'mobile',
                'width' => '15',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '系统提现标识',
                'value' => 'id',
                'validates' => 'require|unique',
                'width' => '15',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '打款方式',
                'value' => 'pay_type',
                'validates' => 'require|pay_channel',//打款方式内容必须是打款方式中的其中一个
                'width' => '25',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '提现编号',
                'value' => 'order_sn',
                'validates' => 'require|unique',
                'width' => '25',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '用户注册时间',
                'value' => 'user_create_time',
                'validates' => '',
                'width' => '25',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '身份证号',
                'value' => 'user_no',
                'validates' => '',
                'width' => '25',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '支付编号',
                'value' => 'pay_no',
                'validates' => '',
                'width' => '25',
                'import' => 1,
                'front_width' => 200,
            ),
            array (
                'title' => self::WITHDRAW_EXPORT_LOCK_STR,
                'value' => '',
                'validates' => '',
                'width' => '70',
                'import' => 0,
                'front_width' => 200,
            ),
    ];

    /**
     * 中数科导出数据结构
     */
    const WITHDRAW_EXPORT_ZSK_DATA = [
            array (
                'title' => '序号',
                'value' => 'key',
                'validates' => '',
                'width' => '5',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '收款方账户名',
                'value' => 'user_real_name',
                'validates' => 'require',
                'width' => '15',
                'import' => 1,
                'front_width' => 200,
            ),
            array (
                'title' => '实名手机',
                'value' => 'user_phone',
                'validates' => 'mobile',
                'width' => '25',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '身份证号',
                'value' => 'user_no',
                'validates' => '',
                'width' => '15',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '收款方账号',
                'value' => 'bank_account',
                'validates' => 'require',
                'width' => '25',
                'import' => 1,
                'front_width' => 200,
            ),
            array (
                'title' => '发放金额',
                'value' => 'price',
                'validates' => 'require|money',
                'width' => '15',
                'import' => 1,
                'front_width' => 200,
            ),
            array (
                'title' => '系统提现标识',
                'value' => 'id',
                'validates' => 'require|unique',
                'width' => '15',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '提现编号',
                'value' => 'order_sn',
                'validates' => 'require|unique',
                'width' => '25',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '用户注册时间',
                'value' => 'user_create_time',
                'validates' => '',
                'width' => '25',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '备注',
                'value' => 'remark',
                'validates' => '',
                'width' => '25',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => '支付编号',
                'value' => 'pay_no',
                'validates' => '',
                'width' => '25',
                'import' => 1,
                'front_width' => 200,
            ),
            array (
                'title' => '打款方式',
                'value' => 'pay_type',
                'validates' => 'require|pay_channel',//打款方式内容必须是打款方式中的其中一个
                'width' => '25',
                'import' => 0,
                'front_width' => 200,
            ),
            array (
                'title' => self::WITHDRAW_EXPORT_LOCK_STR,
                'value' => '',
                'validates' => '',
                'width' => '70',
                'import' => 0,
                'front_width' => 200,
            ),
    ];

    /**
     * 快商导出数据结构
     */
    const WITHDRAW_EXPORT_KUAISHANG_DATA = [
        array (
            'title' => '收款人姓名（必填）',
            'value' => 'user_real_name',
            'validates' => 'require',
            'width' => '15',
            'import' => 1,
            'front_width' => 200,
        ),
        array (
            'title' => '收款人身份证（必填）',
            'value' => 'user_no',
            'validates' => '',
            'width' => '20',
            'import' => 1,
            'front_width' => 200,
        ),
        array (
            'title' => '收款人银行账号（必填）',
            'value' => 'bank_account',
            'validates' => 'require',
            'width' => '25',
            'import' => 1,
            'front_width' => 200,
        ),
        array (
            'title' => '收款人手机号（必填）',
            'value' => 'user_phone',
            'validates' => 'mobile',
            'width' => '25',
            'import' => 1,
            'front_width' => 200,
        ),
        array (
            'title' => '发放金额（必填）',
            'value' => 'price',
            'validates' => 'require|money',
            'width' => '15',
            'import' => 1,
            'front_width' => 200,
        ),
        array (
            'title' => '提现类型',
            'value' => 'withdraw_type_cn',
            'validates' => '',
            'width' => '25',
            'import' => 0,
            'front_width' => 200,
        ),
        array (
            'title' => '提现时间',
            'value' => 'create_time',
            'validates' => '',
            'width' => '25',
            'import' => 0,
            'front_width' => 200,
        ),
        array (
            'title' => '状态',
            'value' => 'check_status',
            'validates' => '',
            'width' => '15',
            'import' => 0,
            'front_width' => 200,
        ),
        array (
            'title' => '备注',
            'value' => 'remark',
            'validates' => '',
            'width' => '15',
            'import' => 0,
            'front_width' => 250,
        ),
        array (
            'title' => '提现编号',
            'value' => 'order_sn',
            'validates' => 'require|unique',
            'width' => '25',
            'import' => 0,
            'front_width' => 250,
        ),
        array (
            'title' => '标识',
            'value' => 'id',
            'validates' => 'require|unique',
            'width' => '25',
            'import' => 0,
            'front_width' => 200,
        ),
        array (
            'title' => '打款方式',
            'value' => 'pay_type',
            'validates' => 'require|pay_channel',
            'width' => '15',
            'import' => 0,
            'front_width' => 200,
        ),
        array (
            'title' => '用户注册时间',
            'value' => 'user_create_time',
            'validates' => '',
            'width' => '25',
            'import' => 0,
            'front_width' => 200,
        ),
        array (
            'title' => '支付编号',
            'value' => 'pay_no',
            'validates' => '',
            'width' => '25',
            'import' => 1,
            'front_width' => 200,
        ),
        array (
            'title' => '用户三方id',
            'value' => 'uid',
            'validates' => '',
            'width' => '25',
            'import' => 1,
            'front_width' => 200,
        ),
        array (
            'title' => self::WITHDRAW_EXPORT_LOCK_STR,
            'value' => '',
            'validates' => '',
            'width' => '70',
            'import' => 0,
            'front_width' => 500,
        ),
    ];

    /** 提现申请结果导入数据对应字段 */
    const WITHDRAW_IMPORT_SHANDE_FIELD = [
        'C' => 'bank_account',
        'B' => 'user_real_name',
        'D' => 'price',
        'G' => 'withdraw_id',
        'L' => 'pay_no',
    ];

    /** 提现申请结果导入数据对应字段 */
    const WITHDRAW_IMPORT_ZSK_FIELD = [
        'E' => 'bank_account',
        'B' => 'user_real_name',
        'F' => 'price',
        'G' => 'withdraw_id',
        'K' => 'pay_no',
    ];

    /** 提现申请结果导入数据对应字段-快商 */
    const WITHDRAW_IMPORT_KUAISHANG_FIELD = [
        'C' => 'bank_account',
        'A' => 'user_real_name',
        'E' => 'price',
        'K' => 'withdraw_id',
        'N' => 'pay_no',
    ];

    /**不同支付商导出导入excel的默认起始行和列*/
    const EXCEL_START_COLUMN = [
        /** 提现打款方式 微信支付  */
        self::WITHDRAW_PAY_TYPE_WX => [
            //列
            'column' => 1,
            //行
            'row' => 1
        ],
        self::WITHDRAW_PAY_TYPE_JOIN => [
            'column' => 1,
            'row' => 1
        ],
        self::WITHDRAW_PAY_TYPE_SHANDE => [
            'column' => 1,
            'row' => 1
        ],
        self::WITHDRAW_PAY_TYPE_KUAISHANG => [
            'column' => 1,
            'row' => 2
        ],
        self::WITHDRAW_PAY_TYPE_ZSK => [
            'column' => 1,
            'row' => 1
        ],
        self::WITHDRAW_PAY_TYPE_OFFLINE => [
            'column' => 1,
            'row' => 1
        ],
    ];

    /** 文件锁文案 */
    const WITHDRAW_EXPORT_LOCK_STR = "本文件为导出文件, 重新导入时请删除或修改本单元格内容";

}