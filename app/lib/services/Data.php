<?php


namespace app\lib\services;

//本服务不允许使用外部异常处理方式
use think\facade\Db;

/**
 * 备份数据需要包含create_time字段
 * Class Data
 * @package app\lib\services
 */
class Data
{
    protected $mode = '';
    protected $logLevel = ['info', 'waring', 'error'];
    protected $tables = [];
    protected $retentionTables = ['sp_maintence_job', 'sp_user_import'];
    protected $bkStartTime = '';
    protected $bkEndTime = '';
    protected $bakupPath = '';


    public function fire()
    {
        $map = [
            ['status', '=', 1]
        ];
        $row = Db::table('sp_maintence_job')->where($map)->find();

        if (!$row) return $this->log('无需执行的job', $this->logLevel[0]);

        if ($row['mode'] == 2) {

        }
    }

    /**
     * @param int $mode 1为增量备份 2为全量备份 3为指定备份
     * @param array $tables
     * @return array|int|Db|\think\Model|void
     */
    public function setMode(int $mode, array $tables = [])
    {
        if (!in_array($mode, [1, 2, 3])) return $this->log('非法调用模式', $this->logLevel[2], [$mode]);

        switch ($mode) {
            case 2:
                $res = $this->fullBackUp('12312312');
                break;
        }

        return $res;
    }


    protected function fullBackUp()
    {
        $map = [
            ['status', '=', 1]
        ];
        $job = Db::table('sp_maintence_job')->where($map)->find();
        if ($job) {
            $this->log('还有未完成的job', $this->logLevel[2]);
            return $job;
        }

        return $this->setTables()->setTime(time())->createJob(2);
    }

    protected function setTime(int $bkEndTime, int $bkStartTime = 0)
    {
        $this->bkStartTime = $bkStartTime;
        $this->bkEndTime = time();
        return $this;
    }

    protected function getBakupPath()
    {
        $bakupPath = config('system.maintenance.bakupPath');

        $this->bakupPath = $bakupPath;
        return $this;
    }

    protected function createJob(int $mode)
    {
//      获取需要执行的表格，生成待执行工作流
        $data = [];
        foreach ($this->tables as $value) {
            $map = [
                ['create_time', '>', $this->bkStartTime],
                ['create_time', '<=', $this->bkEndTime],
            ];
            $row = [
                'id' => md5(microtime() . $value),
                'bk_start_time' => $this->bkStartTime,
                'bk_end_time' => $this->bkEndTime,
                'create_time' => time(),
                'table_name' => $value,
                'mode' => $mode,
                'status' => 1,
                'match_rows' => Db::table($value)->where($map)->count(),
            ];
            $data[] = $row;
        }

        try {
            $res = Db::table('sp_maintence_job')->insertAll($data);
        } catch (\Exception $exception) {
            return $this->log($exception->getMessage(), $this->logLevel[2], [$mode]);
        }
        $this->log('创建备份任务成功', $this->logLevel[0], ['doing_time' => date("Y-m-d H:i:s")]);

        return $res;
    }

    /**
     * @param array $tables
     * @return $this|void
     */
    protected function setTables(array $tables = [])
    {
//      如果传入表格为空，则扫描全库表名
        if (empty($tables)) {
            $res = Db::query('show tables');
            if (empty($res)) return $this->log('数据库查询不到数据表', $this->logLevel[2]);
            foreach ($res as $value) {
                $tables[] = $value['Tables_in_interstellar'];
            }
        }
//      过滤掉无需备份的数据表
        $this->tables = array_diff($tables, $this->retentionTables);
        return $this;
    }

    protected function log(string $msg, string $level, array $data = [])
    {
        $logData['msg'] = $msg;
        $logData['data'] = $data;
        (new Log())->setChannel('maintenance')->record($data, $level);
        return true;
    }


    /**
     * 每月一次备份
     * 当前时间执行上个月日志备份
     * 备份完成后自动删除日志
     *
     */
    public function runLog(string $date = '')
    {
//      备份runtime日志
        $dir = date("Ym", strtotime('-1 month'));
        $now = date("Ym", time());
        if (trim($date)) $dir = $date;
        if ($dir == $now) {
            $type = 2;
        } else {
            $type = 1;
        }

        $this->zipLog(app()->getRuntimePath() . 'log/' . $dir, 'runtime' . $dir, $type);

//      备份业务日志
        $config = config('log.type_channel');
        $channels = array_values($config);
        if (empty($channels)) return $this->log('系统配置的备份目录丢失，请检查配置是否正确', $this->logLevel[2]);
        foreach ($channels as $item) {
            $this->zipLog(app()->getRootPath() . 'log/system/' . $item . '/' . $dir, $item . $dir, $type);
        }

        return true;
    }

    /**
     * 日志目录，文件名，模式
     * @param string $logPath
     * @param string $fileName
     * @param int $type
     * @return bool
     */
    protected function zipLog(string $logPath, string $fileName, int $type = 1)
    {
        if (!is_dir($logPath)) {
            $this->log('请求的文件夹不存在', $this->logLevel[2], [$logPath]);
        } else {
            $basePath = $this->getBakupPath()->bakupPath;

            if (!$basePath) return $this->log('系统配置的备份目录丢失，请检查配置是否正确', $this->logLevel[2]);

            $download = $basePath . '/download';
            if (!is_dir($download)) mkdir($download, 0755);
//        日志文件存在的时候，允许执行


//      备份压缩模式
            if ($type == 1) {
                //       将文件放到备份目录当中去
                $shell = "cd {$download} && zip -r {$fileName}  {$logPath}";
                $res = system($shell, $code);
                if ($code) {
                    return $this->log('执行shell脚本有异常，运行日志压缩失败', $this->logLevel[2], [$logPath, $fileName]);
                }
                //      当zip文件存在的时候，删除掉源文件
                if (is_file($download . '/' . $fileName . '.zip')) {
                    $shell = "rm -rf {$logPath}";
                    $res = system($shell, $code);
                    if ($code) {
                        return $this->log('删除运行日志失败', $this->logLevel[2], [$logPath, $fileName]);
                    }
                }
            }

            if ($type == 2) {
//          只允许压缩到当前日期前一天
                $subDay = date("d", time() - 3600 * 24);
                $day = 01;
                while ($day <= $subDay) {
                    $day = str_pad($day, '2', '0', STR_PAD_LEFT);
                    $temp = $fileName . $day . '.zip';
                    //       将文件放到备份目录当中去
                    $shell = "cd {$logPath} && zip -r {$temp} *{$day}_info.log";

                    $res = system($shell, $code);
                    if (is_file($logPath . '/' . $temp)) {
                        $shell = "cd {$logPath} && rm -rf  *{$day}_info.log";
                        $res = system($shell, $code);
                        if ($code) {
                            return $this->log('删除运行日志失败', $this->logLevel[2], [$logPath, $fileName]);
                        }
                    }

                    $shell = "cd {$logPath} && zip -r {$temp} *{$day}_error.log";
                    $res = system($shell, $code);
                    if (is_file($logPath . '/' . $temp)) {
                        $shell = "cd {$logPath} && rm -rf  *{$day}_error.log";
                        $res = system($shell, $code);
                        if ($code) {
                            return $this->log('删除运行日志失败', $this->logLevel[2], [$logPath, $fileName]);
                        }
                    }

                    $shell = "cd {$logPath} && zip -r {$temp} *{$day}_debug.log";
                    $res = system($shell, $code);
                    if (is_file($logPath . '/' . $temp)) {
                        $shell = "cd {$logPath} && rm -rf  *{$day}_debug.log";
                        $res = system($shell, $code);
                        if ($code) {
                            return $this->log('删除运行日志失败', $this->logLevel[2], [$logPath, $fileName]);
                        }
                    }

                    $shell = "cd {$logPath} && zip -r {$temp} *{$day}_sql.log";
                    $res = system($shell, $code);
                    if (is_file($logPath . '/' . $temp)) {
                        $shell = "cd {$logPath} && rm -rf  *{$day}_sql.log";
                        $res = system($shell, $code);
                        if ($code) {
                            return $this->log('删除运行日志失败', $this->logLevel[2], [$logPath, $fileName]);
                        }
                    }
                    $day++;
                }
            }
        }


        return true;
    }


}