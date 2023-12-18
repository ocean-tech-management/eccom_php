<?php

namespace app\lib\models;

use app\BaseModel;
use app\lib\exceptions\ServiceException;
use think\facade\Db;
use think\model\concern\Virtual;

class CommonModel extends BaseModel
{
    //设置当前模型为虚拟模型
    use Virtual;

    //注意: 虚拟模型不再支持自动时间戳功能，如果需要时间字段需要在实例化的时候传入。

    //是否需要直接抛出异常, 默认否
    private $throwError = false;

    //是否直接返回sql, 不执行sql, 默认否; 本参数在不传sear_type的时候才生效
    private $returnSql = false;

    //是否设置数据库名, 默认无; 本参数在不传db_name的时候才生效
    private $dbName = null;

    //默认不需要过滤的字段名称, 主要集中在用户uid等字段或加密后的字段, 由于字段值特殊性(为随机生成的英文数字字符串), 不需要过滤, 否则可能会导致业务出错
    private $defaultNotValidateValueField = ['uid', 'order_uid', 'link_uid', 'link_superior_user', 'oper_uid', 'entrance_link_user', 'top_link_uid', 'second_link_uid', 'share_id', 'tid', 'wxunionid', 'openid', 'unionId', 'session3rd', 'pwd', 'pay_pwd', 'user_no', 'bank_no'];

    /**
     * @title 设置是否需要直接抛出异常
     * @param bool $throwError
     * @return $this
     */
    public function setThrowError(bool $throwError = true)
    {
        $this->throwError = $throwError;
        return $this;
    }

    /**
     * @title 设置是否直接返回sql
     * @param bool $getSql
     * @return $this
     */
    public function getSql(bool $getSql = true)
    {
        $this->returnSql = $getSql;
        return $this;
    }

    /**
     * @title 设置表名
     * @param string $dbName
     * @return $this
     */
    public function setTableName(string $dbName)
    {
        if (!empty($dbName)) {
            $this->dbName = trim($dbName);
        }
        return $this;
    }

    /**
     * @title 批量自增/自减-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBBatchIncOrDec(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                if (empty($data['sear_type'] ?? null) && !empty($this->returnSql)) {
                    $data['sear_type'] = 2;
                }
                if (empty($data['db_name'] ?? null) && !empty($this->dbName)) {
                    $data['db_name'] = $this->dbName;
                }
                $res = $this->batchIncOrDecBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            if (!empty($this->throwError)) {
                throw new ServiceException(['msg' => $msg]);
            } else {
                $DBRes = false;
            }

        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            if (!empty($this->throwError)) {
                throw new ServiceException(['msg' => $msg]);
            } else {
                $DBRes = false;
            }
        }
        return $DBRes;
    }

    /**
     * @title 批量新增-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBSaveAll(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                if (empty($data['sear_type'] ?? null) && !empty($this->returnSql)) {
                    $data['sear_type'] = 2;
                }
                if (empty($data['db_name'] ?? null) && !empty($this->dbName)) {
                    $data['db_name'] = $this->dbName;
                }
                if (empty($data['notValidateValueField'] ?? []) && !empty($this->defaultNotValidateValueField)) {
                    $data['notValidateValueField'] = $this->defaultNotValidateValueField;
                }
                $res = $this->batchCreateBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            if (!empty($this->throwError)) {
                throw new ServiceException(['msg' => $msg]);
            } else {
                $DBRes = false;
            }
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            if (!empty($this->throwError)) {
                throw new ServiceException(['msg' => $msg]);
            } else {
                $DBRes = false;
            }
        }
        return $DBRes;
    }

    /**
     * @title 批量更新-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBUpdateAllAboutUniqueId(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                if (empty($data['sear_type'] ?? null) && !empty($this->returnSql)) {
                    $data['sear_type'] = 2;
                }
                if (empty($data['db_name'] ?? null) && !empty($this->dbName)) {
                    $data['db_name'] = $this->dbName;
                }
                if (empty($data['notValidateValueField'] ?? []) && !empty($this->defaultNotValidateValueField)) {
                    $data['notValidateValueField'] = $this->defaultNotValidateValueField;
                }
                $res = $this->batchUpdateAboutUniqueIdBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            if (!empty($this->throwError)) {
                throw new ServiceException(['msg' => $msg]);
            } else {
                $DBRes = false;
            }
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            if (!empty($this->throwError)) {
                throw new ServiceException(['msg' => $msg]);
            } else {
                $DBRes = false;
            }
        }
        return $DBRes;
    }

}