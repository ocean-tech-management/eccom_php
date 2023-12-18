<?php
// +----------------------------------------------------------------------
// |[ 文档说明: PHPOffice模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\BaseException;
use app\lib\constant\WithdrawConstant;
use app\lib\exceptions\FileException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\ShipException;
use app\lib\models\Export;
use app\lib\models\ShippingCompany;
use app\lib\validates\ImportShipExcelFilter;
use Nick\SecureSpreadsheet\Encrypt;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use think\Exception;
use think\exception\ErrorException;
use think\facade\Db;
use think\facade\Request;

class Office
{
    private $fileRootPath;

    public function __construct()
    {
        $this->fileRootPath = app()->getRootPath() . 'public/storage/';
    }

    /**
     * @title  导入数据表并读取数据
     * @param array $data
     * @return array
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function importExcel(array $data)
    {
        $type = $data['type'] ?? 1;
        $validateType = $data['validateType'] ?? true;
        $fileUpload = (new FileUpload())->validateType($validateType)
            ->type('excel')
            ->upload(Request::file('file'), false, 'uploads/office'
                ,Request::file()['file']->getOriginalName());
        /** 同步上传oss并存在指定目录下 */
        if(isset($data['is_sync']) && $data['is_sync'] == 1) {
            $res = (new AlibabaOSS())->uploadFile($fileUpload, "importFile");
        }
        $list = $this->ReadExcel($fileUpload, $type);
        return $list ?? [];
    }

    /**
     * @title  读取Excel表内容
     * @param string $fileName 文件名称(uploads/为开头)
     * @param int $type 类型 1为发货订单表
     * @return array
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function ReadExcel(string $fileName, int $type = 1)
    {
        $fileName = $this->fileRootPath . $fileName;

        // 检测文件类型
        $inputFileType = IOFactory::identify($fileName);

        // 根据类型创建合适的读取器对象
        $reader = IOFactory::createReader($inputFileType);

        // 设置读取器选项,可选sheet工作表名称
        $reader->setReadDataOnly(TRUE);

        // 使用过滤器
        switch ($type) {
            case 1:
                $filterSubset = new ImportShipExcelFilter();
                $firstRow = 2;
                //每列对应的字段内容
//                $columnCorrespondField = ['A'=>'order_sn','B'=>'shipping_name','C'=>'shipping_phone','D'=>'province','E'=>'city','F'=>'area','G'=>'address','H'=>'post_code','I'=>'seller_remark','J'=>'create_time','K'=>'total_price','L'=>'real_pay_price','M'=>'order_remark','N'=>'shipping_code','O'=>'shipping_company'];
                $columnCorrespondField = ['A' => 'order_sn', 'B' => 'shipping_code', 'C' => 'shipping_company', 'D' => 'shipping_name', 'E' => 'shipping_phone', 'F' => 'province', 'G' => 'city', 'H' => 'area', 'I' => 'address', 'J' => 'all_address', 'K' => 'goods_title', 'L' => 'goods_specs', 'M' => 'goods_number', 'N' => 'isCommoditys', 'O' => 'goods', 'P' => 'create_time', 'Q' => 'post_code', 'R' => 'seller_remark', 'S' => 'order_remark'];
                break;
            case 2:
                $firstRow = 2;
                //每列对应的字段内容
                $columnCorrespondField = ['A' => 'userName', 'B' => 'userPhone', 'C' => 'shenfen', 'D' => 'topUserName', 'E' => 'topUserPhone', 'F' => 'topShenfen'];
                break;
            case 3:
                $firstRow = 2;
                $columnCorrespondField = ['A' => 'userName', 'B' => 'userPhone', 'C' => 'shenfen', 'D' => 'topUserName', 'E' => 'topUserPhone'];
                break;
            case 4:
                $firstRow = 2;
                $columnCorrespondField = ['A' => 'userName', 'B' => 'userPhone', 'C' => 'number'];
                break;
            case 5:
                //纯读取
                $firstRow = 2;
                $columnCorrespondField = ['A' => 'userName', 'B' => 'userPhone', 'C' => 'number'];
                break;
            case 6:
                $firstRow = 2;
                $columnCorrespondField = ['A' => 'userPhone', 'B' => 'price', 'C' => 'remark'];
                break;
            case 7:
                $firstRow = 2;
                $columnCorrespondField = ['A' => 'userPhone', 'B' => 'userName', 'C' => 'realName', 'D' => 'remark'];
                break;
            case 8:
                //杉德支付结果导入
                $firstRow = 2;
                $columnCorrespondField = WithdrawConstant::WITHDRAW_IMPORT_SHANDE_FIELD;
                break;
            case 9:
                //快商结果导入
                $firstRow = 3;
                $columnCorrespondField = WithdrawConstant::WITHDRAW_IMPORT_KUAISHANG_FIELD;
                break;
            case 10:
                //中数科结果导入
                $firstRow = 2;
                $columnCorrespondField = WithdrawConstant::WITHDRAW_IMPORT_ZSK_FIELD;
                break;
            case 11:
                //普通充值导入
                $firstRow = 2;
                $columnCorrespondField = ['A' => 'userPhone', 'B' => 'price', 'C' => 'remark'];
                break;
            case 12:
                //普通充值导入
                $firstRow = 2;
                $columnCorrespondField = ['A' => 'uid', 'B' => 'recharge_price', 'C' => 'crowd_transfer_price', 'D' => 'all_withdraw_price', 'E' => 'now_crowd_balance', 'F' => 'is_huiben', 'G' => 'kou_price','H'=>'user_name','I'=>'user_phone','J'=>'agreement'];
                break;
        }

        //$reader->setReadFilter($filterSubset);

        // 读取表格表对象
        $spreadsheet = $reader->load($fileName);

        //获取工作表
        $workSheet = $spreadsheet->getActiveSheet();

        // 获取总行数
        $highestRow = $workSheet->getHighestRow();

        // 获取总列数
        $highestColumn = $workSheet->getHighestColumn();

        if ($highestRow < $firstRow) {
            throw new FileException(['msg' => '文件无可读内容']);
        }

        //读取内容
        $count = 0;
        $dbData = [];
        for ($row = $firstRow; $row <= $highestRow; $row++) {
            for ($column = 'A'; $column <= $highestColumn; $column++) {
                $value = $workSheet->getCell($column . $row)->getValue();
//                if (empty($columnCorrespondField[$column])) {
//                    throw new FileException(['msg' => '请上传指定模板文件内容!']);
//                }
                if (!empty($columnCorrespondField[$column])) {
                    $dbData[$count][$columnCorrespondField[$column]] = trim($value);
                }
//                $dbData[$count][$columnCorrespondField[$column]] = trim($value);
            }
            $count++;
        }

        $log['fileName'] = $fileName;
        $log['highestRow'] = $highestRow;
        $log['highestColumn'] = $highestColumn;
        $log['dbData'] = $dbData;

        //剔除空数据
        if (!empty($dbData)) {
            switch ($type) {
                case 1:
                    foreach ($dbData as $key => $value) {
                        if (empty($value['order_sn'])) {
                            unset($dbData[$key]);
                        }
                    }
                    break;
                case 2:
                case 11:
                    //去除空格
                    foreach ($dbData as $key => $value) {
                        $dbData[$key]['userPhone'] = str_replace(" ", '', $value['userPhone']);
                    }
                    $existPhone = [];
                    foreach ($dbData as $key => $value) {
                        if (empty($value['userPhone'])) {
                            unset($dbData[$key]);
                        } else {
                            if (!isset($existPhone[$value['userPhone']])) {
                                $existPhone[$value['userPhone']] = $value;
                            } else {
                                throw new ServiceException(['msg' => '表中手机号码为' . $value['userPhone'] . '存在重复记录,请检查数据源剔除重复数据后重新上传']);
                            }
                        }
                    }
                    break;
                case 4:
                    //去除空格
                    foreach ($dbData as $key => $value) {
                        $dbData[$key]['userPhone'] = str_replace(" ", '', $value['userPhone']);
                    }
                    foreach ($dbData as $key => $value) {
                        if (empty($value['userPhone'])) {
                            unset($dbData[$key]);
                        }
                    }
                    break;
                case 5:
                case 7:
                    //去除空格
                    foreach ($dbData as $key => $value) {
                        $dbData[$key]['userPhone'] = str_replace(" ", '', $value['userPhone']);
                    }
                    break;
                case 6:
                    //去除空格
                    foreach ($dbData as $key => $value) {
                        $dbData[$key]['userPhone'] = str_replace(" ", '', $value['userPhone']);
                        if (!is_numeric($value['price']) || doubleval($value['price']) <= 0) {
                            throw new ServiceException(['msg' => '表中手机号码为' . $value['userPhone'] . '的金额小于或等于0, 请检查']);
                        }
                    }
                    break;

                case 8:
                case 9:
                case 10:
                    foreach ($dbData as $key => $value) {
                        $dbData[$key]['bank_account'] = str_replace(" ", '', $value['bank_account']);
                        if (empty($value['withdraw_id'])) {
                            unset($dbData[$key]);
                        }
                        if(empty($value['pay_no'])){
                            $dbData[$key]['pay_no'] = null;
                        }
                    }
                    break;
            }
        }

        if (empty($dbData)) {
            throw new FileException(['msg' => '文件无可读内容']);
        }

        //数据重组处理
        if (!empty($dbData) && $type == 1) {
            $allShippingCompany = array_unique(array_column($dbData, 'shipping_company'));
            $shipCompanyCode = ShippingCompany::where(['company' => $allShippingCompany])->column('company_code', 'company');
            if (empty($shipCompanyCode) || (count($allShippingCompany) != count($shipCompanyCode))) {
                (new Log())->record($log);
                throw new ShipException(['msg' => '存在不合法的物流公司,无法录入']);
            }
            foreach ($dbData as $key => $value) {
                if (empty($value['order_sn'])) {
                    unset($dbData[$key]);
                    continue;
                }
                $dbData[$key]['shipping_code'] = trim($value['shipping_code']);

                //物流编号只能是数字和字母的组合,不允许单号组合在一起
                if (empty(ctype_alnum($dbData[$key]['shipping_code']))) {
                    throw new ServiceException(['msg' => '<' . $value['shipping_code'] . '> 物流单号仅允许英文和数字,请重新填写']);
                }
                $dbData[$key]['shipping_address'] = $value['province'] . $value['city'] . $value['area'] . $value['address'];
                $dbData[$key]['shipping_company_code'] = $shipCompanyCode[$value['shipping_company']];
            }
        }

        (new Log())->record($log);

        return $dbData;
    }

    /**
     * @title  导出Excel
     * @param $fileName
     * @param $fileType
     * @return void
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    function exportExcel($fileName, $fileType, $data)
    {

        //文件名称校验
        if (!$fileName) {
            trigger_error('文件名不能为空', E_USER_ERROR);
        }

        //Excel文件类型校验
        $type = ['Excel2007', 'Xlsx', 'Excel5', 'xls'];
        if (!in_array($fileType, $type)) {
            trigger_error('未知文件类型', E_USER_ERROR);
        }
//
//        $data = [[1, 'jack', 10],
//            [2, 'mike', 12],
//            [3, 'jane', 21],
//            [4, 'paul', 26],
//            [5, 'kitty', 25],
//            [6, 'yami', 60],];

        $title = ['用户三方id', '充值金额','福利转赠金额','总提现金额','当前账户余额','是否回本','应扣除金额','用户姓名', '手机号码','是否已签约协议'];

        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();

        //设置工作表标题名称
        $worksheet->setTitle('Sheet');
        //设置默认行高
        $worksheet->getDefaultRowDimension()->setRowHeight(18);

        //表头
        //设置单元格内容
        foreach ($title as $key => $value) {
            $worksheet->setCellValueByColumnAndRow($key + 1, 1, $value);
        }

        $row = 2; //从第二行开始
        foreach ($data as $item) {
            $column = 1;

            foreach ($item as $value) {
                $worksheet->setCellValueByColumnAndRow($column, $row, $value);
                $column++;
            }
            $row++;
        }


        $fileName = '需要操作余额用户名单';
        $fileType = 'Xlsx';

        //1.下载到服务器
//        $writer = IOFactory::createWriter($spreadsheet, $fileType);
//        $res = $writer->save(app()->getRootPath()."public\storage\uploads\\".$fileName.'.xlsx');

        //2.输出到浏览器
        if ($fileType == 'Excel2007' || $fileType == 'Xlsx') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $fileName . '.xlsx"');
            header('Cache-Control: max-age=0');
        } else { //Excel5
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment;filename="' . $fileName . '.xls"');
            header('Cache-Control: max-age=0');
        }
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx'); //按照指定格式生成Excel文件
        $writer->save('php://output');

        //删除清空
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }


    /**
     * 统一导出方法
     * @param string $fileName 文件名
     * @param array $title 标题列
     * @param array $data  数据
     * @param array $width  字段宽度
     * @param array $params 操作人,查询条件 可重复发密码  最大发送密码次数
     * @param int $is_encrypt  是否加密
     * @param string $password_type 加密方式
     * @param string $fileType 导出文件类型
     * @return array
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    function exportExcelNew(string $fileName, array $title, array $data, array $width, array $params = [], int $is_encrypt = 0, string $password_type = "1", string $fileType = "Xlsx")
    {
        Db::startTrans();
        try {
            //插入数据
            $insert_data['admin_uid'] = $params['admin_uid'];
            $insert_data['file_path'] = "";
            $insert_data['file_name'] = $fileName;
            $insert_data['file_md5'] = "";
            $insert_data['search_data'] = $params['search_data'] ?? "";
            $insert_data['create_time'] = time();
            $insert_data['is_encrypt'] = $is_encrypt ?? 0;
            $insert_data['file_pwd'] = null;
            $insert_data['pwd_type'] = $password_type ?? 1;
            $insert_data['can_resend'] = $params['can_resend'] ?? 0;
            $insert_data['max_send_num'] = $params['max_send_num'] ?? 1;
            $insert_data['status'] = 1;
            $insert_data['send_num'] = 0;
            $insert_data['update_time'] = time();
            $insert_data['model_name'] = $params['model_name'] ?? "withdraw";
            $insert = Db::name("export")->insertGetId($insert_data);
            if (!$insert) {
                throw new Exception("11008");
            }
            /** 生成excel本地文件 */
            $file_name = $this->writeExcel($fileName, $title, $data, $width, $fileType);

            $password = "";
            if ($is_encrypt == 1) {
                //不同的生成密码的方法
                switch ($password_type) {
                    case 2:
                        //暂时没用到
                        $password = getCodeOutZero() ;
                        break;
                    case 1:
                    default:
                        $password = getCodeOutZero() ;
                        break;
                }
                //文件加密
                $test = new Encrypt();
                $test->input($file_name['path'])->password($password)->output($file_name['path']);
                //发送短信
//                if (!env("app_debug")) {
                /**  发送操作验证码 和文件密码一致 START */
                $phone = systemConfig('safe_phone');
                $sendSmsError = null;
                if (!empty(trim($phone)) ?? null) {
                    try {
                        $sms_data['type'] = "exportNotice";
                        $sms_data['notify'] = ['code' => $password];
                        $sendSms = Code::getInstance()->alarm($phone, $sms_data);
                    } catch (BaseException $baseE) {
                        $sendSmsError = $baseE->msg;
                    } catch (\Exception $e) {
                        $sendSmsError = $e->getMessage();
                    }
                }
                /**  发送操作验证码 和文件密码一致 END */
//                }
            }
            $res = (new AlibabaOSS())->uploadFile($file_name['filename']);

            $update_data = [
                'file_path' => urldecode($res),
                'file_md5' => md5_file($file_name['path']),
                'file_pwd' => publicEncrypt($password),
                'update_time' => time()
            ];
            //如果短信验证码发送失败则有两次系统获取验证的机会
            if (!empty($sendSmsError) && !empty($is_encrypt)) {
                $update_data['max_send_num'] = $insert_data['max_send_num'] + 1;
                $update_data['send_num'] = $insert_data['send_num'] + 1;
            }

            $update = Db::name("export")->where(['id' => $insert])->update($update_data);
            if (!$update) {
                throw new Exception("更新文件信息失败");
            }

            //删除清空
            unlink($file_name['path']);

            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            \think\facade\Log::info($e->getMessage());
            throw new FileException(['msg' => $e->getMessage()]);
        }
        $res_data = [
            'url' => urldecode($res),
            'file_id' => $insert,
            'sendSmsRes' => empty($sendSmsError) ? true : false,
            'sendSmsErrorMsg' => $sendSmsError ?? '发送验证短信成功'
        ];
        return $res_data;
    }

    /**
     * @title 生成文件统一方法
     * @param $fileName
     * @param $title
     * @param $data
     * @param $width
     * @param string $fileType
     * @return string[]
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    private function writeExcel($fileName, $title, $data,$width, $fileType = "Xlsx")
    {
        //文件名称校验
        if (!$fileName) {
            trigger_error('文件名不能为空', E_USER_ERROR);
        }

        $fileName = $fileName . getRandomString(10);
        //Excel文件类型校验
        $type = ['Excel2007', 'Xlsx', 'Excel5', 'xls'];
        if (!in_array($fileType, $type)) {
            trigger_error('未知文件类型', E_USER_ERROR);
        }
        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();

        //设置工作表标题名称
        $worksheet->setTitle('Sheet');
        //设置默认行高
        $worksheet->getDefaultRowDimension()->setRowHeight(18);
        if ($width) {
            foreach ($width as $k => $val) {
                if($val) {
                    $worksheet->getColumnDimensionByColumn($k + 1)->setWidth($val);
                }
            }
        }
        //表头
        //设置单元格内容
        foreach ($title as $key => $value) {
            if($value == WithdrawConstant::WITHDRAW_EXPORT_LOCK_STR){
                $worksheet->getStyleByColumnAndRow($key + 1, 1)
                    ->getFont()->getColor()->setARGB(Color::COLOR_RED);
                $worksheet->setCellValueExplicitByColumnAndRow($key + 1, 1, $value,'str');
            }ELSE {
                $worksheet->setCellValueExplicitByColumnAndRow($key + 1, 1, $value,'str');
            }
        }

        $row = 2; //从第二行开始
        foreach ($data as $item) {
            $column = 1;

            foreach ($item as $value) {
                /** 强制设置单元格为文本 */
                $worksheet->getStyleByColumnAndRow($column,$row)
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_TEXT);
                $worksheet->setCellValueExplicitByColumnAndRow($column,$row,$value,'str');
//                $worksheet->setCellValueByColumnAndRow($column, $row, $value);
                $column++;
            }
            $row++;
        }
        //2.输出到浏览器
//        if ($fileType == 'Excel2007' || $fileType == 'Xlsx') {
//            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
//            header('Content-Disposition: attachment;filename="' . $fileName . '.xlsx"');
//            header('Cache-Control: max-age=0');
//        } else { //Excel5
//            header('Content-Type: application/vnd.ms-excel');
//            header('Content-Disposition: attachment;filename="' . $fileName . '.xls"');
//            header('Cache-Control: max-age=0');
//        }
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx'); //按照指定格式生成Excel文件
        $file_path = app()->getRootPath() . 'public/storage/';
        $file_name = $fileName . '.xlsx';

        $writer->save($file_path . $file_name);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        return ["path"=>$file_path.$file_name ,"filename"=> $file_name];
    }

}