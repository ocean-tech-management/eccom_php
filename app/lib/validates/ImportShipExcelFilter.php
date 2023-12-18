<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 导入发货订单Excel的单元格读取过滤器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\validates;


use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ImportShipExcelFilter implements IReadFilter
{
    public function readCell($column, $row, $worksheetName = '')
    {
        // TODO: Implement readCell() method.
        //选定区域
        if ($row >= 3) {
            if (in_array($column, range('A', 'P'))) {
                return true;
            }
        }
        return false;
    }
}