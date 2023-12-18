<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 财务模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\constant\WithdrawConstant;
use app\lib\exceptions\FileException;
use think\facade\Cache;
use think\facade\Db;
class Export
{
    /**
     * 根据导出文件类型获取导出文件的字段和标题
     * @param $type
     * @return array
     */
    public function getExportField($type)
    {
        switch ($type) {
            case 3://杉德支付
                $data = WithdrawConstant::WITHDRAW_EXPORT_SHANDE_DATA;
                break;
            case 4://快商
                $data = WithdrawConstant::WITHDRAW_EXPORT_KUAISHANG_DATA;
                break;
            case 5://中数科
                $data = WithdrawConstant::WITHDRAW_EXPORT_ZSK_DATA;
                break;
            default:
                throw new FileException(['errorCode' => 11007]);
        }
        foreach ($data as $k => $datum) {
            $data[$k]['line'] = stringFromColumnIndex($k + 1);
        }
        $startRow = WithdrawConstant::EXCEL_START_COLUMN[$type];

        $finally['field'] = $data;
        $finally['start'] = $startRow;
        return $finally;
    }
}