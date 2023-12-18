<?php
// +----------------------------------------------------------------------
// |[ 文档说明: Model基类]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app;

use app\lib\exceptions\DbException;
use app\lib\exceptions\ServiceException;
use think\exception\ValidateException;
use think\facade\Db;
use think\Model;
use think\Validate;

abstract class BaseModel extends Model
{
    /**
     * 更新时间字段
     * @var string
     */
    protected $updateTime = 'update_time';

    /**
     * 新建时间字段
     * @var string
     */
    protected $createTime = 'create_time';

    /**
     * 只读字段
     * @var array
     */
    protected $readonly = ['id'];

    /**
     * 必填验证字段
     * @var array
     */
    protected $validateFields = [];

    /**
     * 是否开启验证必填字段
     * @var bool
     */
    protected $isValidate = false;

    /**
     * 列表分页条数
     * @var int
     */
    protected $pageNumber = 10;

    /**
     * 唯一规则
     * @var mixed
     */
    protected $uniqueRule;

    /**
     * 请求模块
     * @var string
     */
    protected $module;

    /**
     * 是否开启缓存
     * @var string
     */
    protected $cache;

    public function __construct(array $data = [])
    {
        parent::__construct($data);
        $this->module = current(explode('/', trim(\think\facade\Request::pathinfo())));
    }

    /**
     * @title  新增基础方法
     * @param array $data 新增数据
     * @param bool $getPk 是否需要获取自增id
     * @return mixed
     * @throws DbException
     */
    protected function baseCreate(array $data, bool $getPk = false)
    {
        if ($this->isValidate) $this->validateData($data);
        $res = self::create($data, $this->field);
        if ($getPk) return intval($res->getData($this->getPk()));
        return $res;
    }

    /**
     * @title  更新基础方法
     * @param array $map 更新条件
     * @param array $data 更新数据
     * @return static
     */
    protected function baseUpdate(array $map, array $data)
    {
        $this->checkMap($map);
        if ($this->isValidate) $this->validateData($data);
        return self::update($data, $map, $this->field);
    }

    /**
     * @title  删除基础方法
     * @param array $map 条件
     * @param string $field 删除字段
     * @return static
     */
    protected function baseDelete(array $map, string $field = 'status')
    {
        $this->checkMap($map);
        return self::update([$field => -1], $map, [$field]);
    }

    /**
     * @title  更新或新增方法
     * @param array $map 更新条件
     * @param array $data 更新数据
     * @param array $validateCreateFields 新增数据验证必填字段
     * @param array $validateUpdateFields 更新数据验证必填字段
     * @return static
     * @remark 根据条件判断,有则修改无则新增
     */
    protected function updateOrCreate(array $map, array $data, array $validateCreateFields = [], array $validateUpdateFields = [])
    {
        $exist = $this->where($map)->count();
        if (!empty($exist)) {
            $res = $this->validate($validateUpdateFields)->baseUpdate($map, $data);
        } else {
            $res = $this->validate($validateCreateFields)->baseCreate($data);
        }
        return $res;
    }


    /**
     * @title  是否开启字段验证
     * @param mixed $fields 自定义验证字段数组
     * @return $this
     */
    protected function validate($fields = true)
    {
        if (is_array($fields)) {
            if (!empty($fields)) {
                $this->validateFields = $fields;
            }
            $this->isValidate = true;
        } elseif (is_bool($fields)) {
            $this->isValidate = $fields;
        }
        return $this;
    }

    /**
     * @title  开启缓存
     * @param string $cacheKey 缓存键名
     * @param int $cacheExpire 缓存时效
     * @param string|null $tag 缓存标签
     * @return $this
     */
    public function openCache(string $cacheKey, int $cacheExpire = 60, string $tag = null)
    {
        if (!empty($cacheKey)) {
            $this->cache = ['cacheKey' => $cacheKey, 'cacheExpire' => $cacheExpire, 'cacheTag' => $tag];
        }
        return $this;

    }

    /**
     * @title  获取默认字段验证
     * @return array
     * @throws DbException
     */
    protected function baseRules(): array
    {
        $fields = $this->validateFields;
        $rules = [];
        if (empty($fields)) throw new DbException(['errorCode' => 900102]);
        foreach ($fields as $key => $value) {
            if (!is_numeric($key)) {
                $rules[$key] = $value;
            } else {
                $rules[$value] = 'require';
            }
        }
        if (!empty($this->uniqueRule)) {
            $unique = $this->uniqueRule;
            if (array_key_exists($unique['field'], $rules)) {
                $rules[$unique['field']] .= '|' . $unique['rule'];
            } else {
                $rules[$unique['field']] = $unique['rule'];
            }
        }
        return $rules;
    }

    protected function unique(string $field, string $statusField = 'status=1', array $other = [])
    {
        $prefix = config('database.connections.mysql.prefix');
        $base = 'unique:' . str_replace($prefix, '', $this->getTable());
        $sBase = empty($statusField) ? $base : $base . ',' . $statusField;
        $rule = empty($other) ? $sBase : $sBase . '&' . http_build_query($other);
        $this->uniqueRule = ['field' => $field, 'rule' => $rule];
        return $this;
    }

    /**
     * @title  获取模糊查询Sql语句
     * @param string $field 匹配字段
     * @param string $keyword 查询关键词
     * @return string
     * @remark 正则匹配字段是否存在分隔符,若存在则根据规则拼接sql,否则用指定sql
     */
    protected function getFuzzySearSql(string $field, string $keyword): string
    {
        //过滤掉一些不必要的字符
        if ($this->module == 'api') {
            $regex = "/\/|\～|\，|\。|\！|\？|\“|\”|\【|\】|\『|\』|\：|\；|\《|\》|\’|\‘|\ |\!|\@|\#|\\$|\%|\^|\&|\*|\(|\)|\+|\{|\}|\:|\<|\>|\?|\[|\]|\,|\/|\;|\'|\`|\=|\\\|\|\"|update|insert|delete|drop|union|into|load_file|outfile|select|SELECT|UNION|DROP|DELETE|SLEEP|FROM|from|dump|DUMP|UPDATE|INSERT|script|style|html|body|title|link|meta|object|OR|or|LIKE|like|md5|rm|rf|cd|chmod|RM|RF|CD|CHMOD|\n|\m|\e|\i|\r|\t|/";
            $keyword = preg_replace($regex, "", $keyword);
            if (empty($keyword)) {
                throw new ServiceException(['msg' => '关键词包含非法参数,请重新输入~']);
            }

            $banKeyword = str_replace(" ", '', trim($keyword));
            if (empty($banKeyword)) {
                throw new ServiceException(['msg' => '请填写不为空的合规关键词']);
            }
            $banCondition = ['delete', 'select', 'limit', 'drop', 'insert', 'like', 'union', 'sleep', 'dump', 'update', 'md5', 'rm -rf', 'chmod','exit','die','print','printf'];
            foreach ($banCondition as $key => $value) {
                if (!empty(stristr(strtolower($banKeyword), $value))) {
                    throw new ServiceException(['msg' => '非法参数注入, 您的IP已被记录, 请立即停止您的行为!']);
                }
            }
        }

        $allowSymbol = ['\|', '\&'];
        $symbolToSql = ['|' => 'OR', '&' => 'AND'];
        preg_match_all('/(' . implode('|', $allowSymbol) . ')/', $field, $wordsFound);
        $wordsFound = array_unique($wordsFound[0]);
        if (!empty($wordsFound)) {
            $firstSymbol = current($wordsFound);
            $sqlSymbol = $symbolToSql[$firstSymbol];
            $fields = explode($firstSymbol, $field);
        }
        $query = '';
        if (!empty($fields) && is_array($fields)) {
            foreach ($fields as $key => $value) {
                //支持原生的(*.*)字段模式(如a.name)
                if (strpos($value, '.')) {
                    $value = substr($value, 0, strrpos($value, ".")) . '`.' . '`' . substr($value, strripos($value, ".") + 1);
                }
                $query .= "LOCATE(\"" . $keyword . "\", `$value`) > 0 $sqlSymbol ";
            }
            $query = rtrim($query, " $sqlSymbol ");
        } else {
            if (strpos($field, '.')) {
                $field = substr($field, 0, strrpos($field, ".")) . '`.' . '`' . substr($field, strripos($field, ".") + 1);
            }
            $query = "LOCATE(\"" . $keyword . "\", `$field`) > 0";
        }
        return $query;
    }

    /**
     * @title  根据请求模块获取不同的状态值
     * @param int $type 状态类型 1为包含正常和禁用 2为仅包含正常状态 3为仅包含禁用状态
     * @return array
     */
    protected function getStatusByRequestModule(int $type = 1)
    {
        //多应用模式下
        //$module = ltrim(\think\facade\Request::root(),'/');
        //单应用模式下
        $pathInfo = explode('/', trim(\think\facade\Request::pathinfo()));
        $module = $pathInfo[0];
        if ($module == 'admin' || $module == 'manager') {
            if ($type == 1) {
                $status = [1, 2, 3];
            } elseif ($type == 2) {
                $status = [1];
            } elseif ($type == 3) {
                $status = [2];
            }

        } else {
            $status = [1];
        }
        return $status;
    }

    /**
     * @title  检查Sql条件
     * @param mixed $map 数组条件
     * @return void
     * @author  Coder
     * @date   2019年12月12日 10:51
     */
    private function checkMap($map): void
    {
        if (empty($map)) {
            throw new DbException(['errorCode' => 900103]);
        }
    }

    /**
     * 验证数据
     * @access protected
     * @param array $data 数据
     * @param string|array $validate 验证器名或者验证规则数组
     * @param array $message 提示信息
     * @param bool $batch 是否批量验证
     * @return array|string|true
     * @throws ValidateException|DbException
     */
    protected function validateData(array $data, $validate = [], array $message = [], bool $batch = false)
    {
        if (empty($validate)) {
            $validate = $this->baseRules();
        }
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                list($validate, $scene) = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

    /**
     * @title 检验sql语句关于插入值或查询值的合法性, 防止sql注入
     * @param $keyword
     * @return array
     */
    public function validateSqlValue($keyword): array
    {
        $result['res'] = true;
        $result['value'] = $keyword;
        //数字无需校验, 直接放行
        if (is_numeric($keyword)) {
            return $result;
        }
        //主动剔除部分不允许的关键词,防止形成sql注入或条件判断失真
        $regex = "/update|insert|delete|drop|union|into|load_file|outfile|select|SELECT|UNION|DROP|DELETE|SLEEP|FROM|from|dump|DUMP|UPDATE|INSERT|script|style|link|meta|object|OR|or|oR|Or|LIKE|like|md5|rm|rM|Rm|rf|cd|chmod|RM|RF|CD|CHMOD|DIE|EXIT|die|exit|\'|\"|\`|\@|\#|\=|/";
        $keyword = preg_replace($regex, "", str_replace(" ", '', trim($keyword)));
        $banKeyword = str_replace(" ", '', trim($keyword));
        if (empty($banKeyword)) {
            $result['res'] = false;
            $result['msg'] = '请填写不为空的合规值';
            return $result;
        }
        $banCondition = ['delete', 'select', 'limit', 'drop', 'insert', 'like', 'union', 'sleep', 'dump', 'update', 'md5', 'rm -rf', 'chmod', 'exit', 'die', 'print', 'printf', 'rm-rf', 'remove'];
        foreach ($banCondition as $key => $value) {
            if (!empty(stristr(strtolower($banKeyword), $value))) {
                $result['res'] = false;
                $result['msg'] = '存在非法参数注入';
            }
        }
        $result['value'] = $keyword;
        return $result;
    }

    /**
     * @title 批量自增或自减的sql拼接执行方法
     * @param array $data
     * @return mixed
     * @remark 利用when case拼接生成批量自增或自减的sql并执行, 请注意: 为避免执行错误和增加执行效率, 本方法单次仅允许执行单字段的自增或自减
     * @ramark 为了避免多层嵌套事务, 本方法不做事务块处理, 请在调用本方法前生成事务
     * @remake 请在调用本方法做好参数键值的安全过滤, 谨防sql注入
     */
    public function batchIncOrDecBySql(array $data)
    {
        //数据数组,支持一, 二维数组;
        //一维数组默认key为sql条件判断中唯一标识的值, value为需要操作的值
        $dataList = $data['list'];
        if (empty($dataList)) {
            throw new ServiceException(['msg' => '数据为空']);
        }
        if (!is_array($dataList)) {
            throw new ServiceException(['msg' => '数据必须为一维或二维数组']);
        }
        //操作数据库表名, 必须为表全称, 包含表前缀
        $dbName = $data['db_name'];
        //一条sql最大参与拼接的数据量, 默认为500, 超过会自动切割成一条新的sql
        $onceSqlDataNumber = $data['once_sql_number'] ?? 500;
        //sql条件判断中唯一标识, 举例: 可以为uid或者id, 主要是为了必须的where条件和最后的执行的where范围约束
        $sqlUniqueId = $data['id_field'] ?? 'uid';
        //sql执行最后where额外判断, 必须为字符串的sql语句, 需要注意字符串的单引号问题和sql注入问题
        $sqlOtherMap = $data['other_map'] ?? '';
        //需要自增或自减的数据库字段, 单次仅支持操作一个字段
        $sqlField = $data['operate_field'];
        //二维数组中需要自增的字段对应的值的字段名称
        $valueField = $data['value_field'] ?? null;
        //操作类型 inc为自增, dec为自减
        if (!in_array($data['operate_type'], ['inc', 'dec'])) {
            throw new ServiceException(['msg' => '不支持的自增操作类型']);
        }
        $sqlFieldOper = $data['operate_type'] == 'inc' ? '+' : '-';
        //执行类型 1为生成sql并执行 2为仅生成后直接返回,不执行 默认1;
        $searType = $data['sear_type'] ?? 1;
        $returnData = [];

        //将二维数组的唯一标识转化为一维数组一一对应,并判断自增/减的值必须为数字类型
        if (count($dataList) != count($dataList, 1)) {
            foreach ($dataList as $key => $value) {
                if (!is_numeric($value[$valueField])) {
                    throw new ServiceException(['msg' => '数据中存在非数字类型的值,请检查 错误key' . $key]);
                }
                if (!isset($dataInfo[$value[$sqlUniqueId]])) {
                    $dataInfo[$value[$sqlUniqueId]] = 0;
                }
                $dataInfo[$value[$sqlUniqueId]] += $value[$valueField];
            }
        } else {
            foreach ($dataList as $key => $value) {
                if (!is_numeric($value)) {
                    throw new ServiceException(['msg' => '数据中存在非数字类型的值,请检查 错误key' . $key]);
                }
            }
            $dataInfo = $dataList;
        }

        //检验参数值的合法性, 防止sql注入--暂时注释, 本方法强制约束值必须为数字, 无需校验值合法问题
//        foreach ($dataInfo as $key => $value) {
//            if (!empty($value) && !is_numeric($value)) {
//                $validateRes = $this->validateSqlValue($value);
//                if (empty($validateRes['res'] ?? false)) {
//                    throw new ServiceException(['msg' => '数据中存在非法类型的值,原因: ' . ($validateRes['msg'] ?? '未知错误') . ' 请检查 错误key: ' . $key . ' 错误值: ' . $value]);
//                } else {
//                    $dataInfo[$key] = $validateRes['value'];
//                }
//            }
//        }

        $updateUserSql = 'update ' . $dbName . ' set ' . $sqlField . " = CASE $sqlUniqueId ";
        $updateUserSqlMore = [];
        $allUidSql = "('" . implode("','", array_unique(array_keys($dataInfo))) . "')";
        //更新的用户拼接sql
        if (!empty($dataInfo ?? [])) {
            foreach ($dataInfo as $key => $value) {
                if (doubleval($value) == 0) {
                    unset($dataInfo[$key]);
                }
            }
            if (empty($dataInfo)) {
                return false;
            }
            $number = 0;
            foreach ($dataInfo as $key => $value) {
                if ($number >= $onceSqlDataNumber) {
                    if ($number % $onceSqlDataNumber == 0) {
                        $updateUserHeaderSql = 'update ' . $dbName . ' set  ' . $sqlField . "  = CASE $sqlUniqueId ";
                    }
                    $updateUserSqlMore[intval($number / $onceSqlDataNumber)] = $updateUserHeaderSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN ($sqlField $sqlFieldOper " . ($value ?? 0) . ")";
                    $updateUserSqlMoreUid[intval($number / $onceSqlDataNumber)][] = ($key ?? 'notfound');
                    unset($dataInfo[$key]);
                } else {
                    $updateUserSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN ($sqlField $sqlFieldOper " . ($value ?? 0) . ")";
                    unset($dataInfo[$key]);
                }
                $number += 1;
            }

            $updateUserSql .= " ELSE ($sqlField $sqlFieldOper 0) END WHERE $sqlUniqueId in " . $allUidSql;
            if (!empty($sqlOtherMap ?? null)) {
                $updateUserSql .= " AND $sqlOtherMap";
            }

            if (!empty($updateUserSqlMore ?? [])) {
                foreach ($updateUserSqlMore as $key => $value) {
                    $updateUserSqlMore[$key] .= " ELSE ($sqlField $sqlFieldOper 0) END WHERE $sqlUniqueId in " . "('" . implode("','", $updateUserSqlMoreUid[$key]) . "')";
                    if (!empty($sqlOtherMap ?? null)) {
                        $updateUserSqlMore[$key] .= " AND $sqlOtherMap";
                    }
                }
            }
            //判断存在sql则执行sql
            if (!empty($updateUserSql ?? null) && $searType == 1) {
                Db::query($updateUserSql);
                //判断是否有超过其他多的sql, 有一并执行
                if (!empty($updateUserSqlMore ?? [])) {
                    foreach ($updateUserSqlMore as $key => $value) {
                        Db::query($value);
                    }
                }
            }
            if ($searType == 2) {
                $returnData = ['sql' => $updateUserSql ?? null, 'moreSql' => $updateUserSqlMore ?? []];
            }
        }

        //释放变量释放内存
        unset($number);
        unset($updateUserSql);
        unset($updateUserSqlMore);
        unset($dataInfo);
        return !empty($returnData ?? []) ? $returnData : true;
    }


    /**
     * @title 批量新增数据sql拼接执行方法
     * @param array $data
     * @return mixed
     * @remark 如果不是数字类型其他的默认强制会以字符串的形式插入, 请注意核对插入的数据类型准确性
     * @remark 为了避免多层嵌套事务, 本方法不做事务块处理, 请在调用本方法前生成事务
     * @remark 请在调用本方法做好参数键值的安全过滤, 谨防sql注入
     */
    public function batchCreateBySql(array $data)
    {
        //数据数组, 仅支持二维数组
        $dataList = $data['list'];
        if (empty($dataList)) {
            throw new ServiceException(['msg' => '数据为空']);
        }
        if (!is_array($dataList) || (is_array($dataList) && count($dataList) == count($dataList, 1))) {
            throw new ServiceException(['msg' => '数据必须为二维数组']);
        }
        //如果有特殊字段可以无需校验对应的值, 比如uid等, 必须为字段名的一维数组
        $notValidateValueField = $data['notValidateValueField'] ?? [];

        //检验参数值的合法性, 防止sql注入
        foreach ($dataList as $key => $value) {
            $validateRes = [];
            foreach ($value as $cKey => $cValue) {
                $validateRes = [];
                if (!empty($cValue) && !is_numeric($cValue)) {
                    if (!empty($notValidateValueField) && in_array($cKey, $notValidateValueField)) {
                        $validateRes = ['res' => true, 'value' => $cValue];
                    } else {
                        $validateRes = $this->validateSqlValue($cValue);
                    }
                    if (empty($validateRes['res'] ?? false)) {
                        throw new ServiceException(['msg' => '数据中存在非法类型的值,原因: ' . ($validateRes['msg'] ?? '未知错误') . ' 请检查 错误key: ' . $cKey . ' 错误值: ' . $cValue]);
                    } else {
                        $dataList[$key][$cKey] = $validateRes['value'];
                    }
                }
            }
        }

        //操作数据库表名, 必须为表全称, 包含表前缀
        $dbName = $data['db_name'];
        //执行类型 1为生成sql并执行 2为仅生成后直接返回,不执行 默认1;
        $searType = $data['sear_type'] ?? 1;
        $returnData = [];

        //新增字段, 如果有则按照指定的,顺序必须跟数据数组中的值一一对应, 传值必须为数组; 没有则默认取数组中全部的键名
        if (empty($data['operate_field'] ?? [])) {
            $operateField = array_keys($dataList[0]);
        } else {
            $operateField = $data['operate_field'];
        }
        //是否自动填充创建和更新时间, 默认是
        $autoFillCreateAndUpdateTime = $data['auto_fill_time'] ?? true;
        if (!empty($autoFillCreateAndUpdateTime)) {
            if (!in_array('create_time', $operateField)) {
                array_push($operateField, 'create_time');
            }
            if (!in_array('update_time', $operateField)) {
                array_push($operateField, 'update_time');
            }
        }

        //是否自动填充状态值字段status, 默认否
        $autoFillStatus = $data['auto_fill_status'] ?? false;
        if (!empty($autoFillStatus)) {
            if (!in_array('status', $operateField)) {
                array_push($operateField, 'status');
            }
        }

        $sqlField = implode(' , ', $operateField);

        $sqls = '';
        if (!empty($dataList)) {
            $sqls = sprintf("INSERT INTO $dbName ($sqlField) VALUES ");
            foreach ($dataList as $key => $items) {
                $itemStrs = '';
                $createTime = time();
                $valueSql = "";
                foreach ($operateField as $oKey => $oValue) {
                    if (!empty($autoFillCreateAndUpdateTime)) {
                        if (empty($items['create_time'] ?? null)) {
                            $items['create_time'] = $createTime;
                        }
                        if (empty($items['update_time'] ?? null)) {
                            $items['update_time'] = $createTime;
                        }
                    }
                    if (!empty($autoFillStatus)) {
                        if (empty($items['status'] ?? null)) {
                            $items['status'] = 1;
                        }
                    }
                    if ($oKey == (count($operateField) - 1)) {
                        $valueSql .= (is_numeric($items[$oValue]) ? $items[$oValue] : "'" . $items[$oValue] . "'");
                    } else {
                        $valueSql .= (is_numeric($items[$oValue]) ? $items[$oValue] . " , " : "'" . $items[$oValue] . "'" . " , ");
                    }
                }
                $itemStrs = '( ' . ($valueSql);
                $itemStrs .= '),';
                $sqls .= $itemStrs;
            }
            // 去除最后一个逗号，并且加上结束分号
            $sqls = rtrim($sqls, ',');
            $sqls .= ';';
        }
        if (!empty($sqls)) {
            if ($searType == 1) {
                Db::query($sqls);
            } else {
                $returnData = ['sql' => $sqls ?? null, 'moreSql' => []];
            }
        }
        //释放变量释放内存
        unset($itemStrs);
        unset($sqls);
        unset($valueSql);
        unset($dataList);
        return !empty($returnData ?? []) ? $returnData : true;
    }

    /**
     * @title 根据唯一标识批量更新数据sql拼接执行方法
     * @param array $data
     * @return mixed
     * @remark 默认强制会以字符串的形式插入, 请注意核对插入的数据类型准确性;
     * @remark 如果需要更新值原样展示(如需要字段拼接或自增等), 请将需要原样展示的字段数组组成一维数组后通过 raw_fields 单独传入
     * @remark 为了避免多层嵌套事务, 本方法不做事务块处理, 请在调用本方法前生成事务
     * @remark 请在调用本方法做好参数键值的安全过滤, 谨防sql注入
     */
    public function batchUpdateAboutUniqueIdBySql(array $data)
    {
        //数据数组,支持一, 二维数组;
        //一维数组默认key为sql条件判断中唯一标识的值, value为需要操作的值
        $dataList = $data['list'];
        if (empty($dataList)) {
            throw new ServiceException(['msg' => '数据为空']);
        }
        if (!is_array($dataList) || (is_array($dataList) && count($dataList) == count($dataList, 1))) {
            throw new ServiceException(['msg' => '数据必须为二维数组']);
        }
        //更新的值需要原样展示的字段, 若此字段为空默认更新的值都为字符串
        $rawValueFields = $data['raw_fields'] ?? [];
        //如果有特殊字段可以无需校验对应的值, 比如uid等, 必须为字段名的一维数组
        $notValidateValueField = $data['notValidateValueField'] ?? [];

        if (!empty($rawValueFields ?? []) && !is_array($rawValueFields)) {
            throw new ServiceException(['msg' => '原样展示数据字段必须为一维数组']);
        }

        //如果有需要原样传值的参数则默认不判断值的安全性
        if (!empty($rawValueFields ?? [])) {
            $notValidateValueField = array_merge_recursive($notValidateValueField, $rawValueFields);
        }

        //校验sql注入
        $dataList = $this->batchSqlVerify($dataList, ($notValidateValueField ?? []));

        //操作数据库表名, 必须为表全称, 包含表前缀
        $dbName = $data['db_name'];
        //一条sql最大参与拼接的数据量, 默认为500, 超过会自动切割成一条新的sql
        $onceSqlDataNumber = $data['once_sql_number'] ?? 1;
        //sql条件判断中唯一标识, 举例: 可以为uid或者id, 主要是为了必须的case条件和最后的执行的where范围约束
        $sqlUniqueId = $data['id_field'] ?? 'uid';
        //sql执行最后where额外判断, 必须为字符串的sql语句, 需要注意字符串的单引号问题和sql注入问题
        $sqlOtherMap = $data['other_map'] ?? '';
        //执行类型 1为生成sql并执行 2为仅生成后直接返回,不执行 默认1;
        $searType = $data['sear_type'] ?? 1;

        //是否自动填充更新时间, 默认否
        $autoFillCreateAndUpdateTime = $data['auto_fill_time'] ?? false;
        //是否剔除唯一标识更新, 默认是
        $removeUniqueIdUpdate = $data['remove_unique_id_update'] ?? true;

        $returnData = [];

        //数据分组, 防止拼接的单条sql过大
        for ($page = 1; $page <= intval(ceil(count($dataList) / $onceSqlDataNumber)); $page++) {
            $start = ($page - 1) * $onceSqlDataNumber;
            $dataArray[$page - 1] = array_slice($dataList, $start, $onceSqlDataNumber);
        };

        //获取每个分组的唯一标识列表, 自动生成更新时间字段(若有需要)
        foreach ($dataArray as $key => $value) {
            foreach ($value as $cKey => $cValue) {
                $allUidInfo[$key][] = $cValue[$sqlUniqueId];
                if (!empty($autoFillCreateAndUpdateTime)) {
                    if (!in_array('update_time', array_keys($cValue))) {
                        $dataArray[$key][$cKey]['update_time'] = time();
                    }
                }
            }
        }

        foreach ($dataArray as $key => $value) {
            $keys = array_keys(current($value));
            $updateUserSql[$key] = "update {$dbName} set ";
            $allUidSql = "('" . implode("','", array_unique($allUidInfo[$key])) . "')";
            foreach ($keys as $column) {
                //唯一标志,不作为更新值
                if (!empty($removeUniqueIdUpdate ?? false) && $column == $sqlUniqueId) {
                    continue;
                }
                $updateUserSql[$key] .= sprintf("`%s` = CASE `%s` ", $column, $sqlUniqueId);
                foreach ($value as $line) {
                    if (isset($line[$column])) {
                        $varsValue = "'%s'";
                        if (!empty($rawValueFields ?? []) && in_array($column, $rawValueFields)) {
                            $varsValue = "%s";
                        }
                        $updateUserSql[$key] .= sprintf("WHEN '%s' THEN {$varsValue} ", $line[$sqlUniqueId], $line[$column]);
                    }
                }
                //加上else约束, 如果没有正确遇到case则为字段原来的值
                $updateUserSql[$key] .= sprintf(" ELSE %s END ,", $column);
            }
            $updateUserSql[$key] = rtrim($updateUserSql[$key], ',');
            //根据当前数组的唯一标识加上where条件约束,防止全表更新
            $updateUserSql[$key] .= sprintf("where `%s` IN %s ", $sqlUniqueId, $allUidSql);
            //如果有额外判断条件则在最后拼接上
            if (!empty($sqlOtherMap)) {
                $updateUserSql[$key] .= " and " . $sqlOtherMap;
            }
        }

        //判断存在sql则执行sql
        if (!empty($updateUserSql ?? null) && $searType == 1) {
            foreach ($updateUserSql as $key => $value) {
                Db::query($value);
            }
        }
        if ($searType == 2) {
            $returnData = ['sql' => $updateUserSql ?? null];
        }
        //释放变量释放内存
        unset($number);
        unset($updateUserSql);
        unset($dataList);
        unset($data);
        return !empty($returnData ?? []) ? $returnData : true;
    }

    /**
     * @title 生成sql时的参数校验过滤
     * @param array $dataList
     * @param array $notValidateValueField
     * @return array
     */
    public function batchSqlVerify(array $dataList, array $notValidateValueField)
    {
        //$notValidateValueField 如果有特殊字段可以无需校验对应的值, 比如uid等, 必须为字段名的一维数组
        //检验参数值的合法性, 防止sql注入
        //仅校验非数字的值
        foreach ($dataList as $key => $value) {
            $validateRes = [];
            foreach ($value as $cKey => $cValue) {
                $validateRes = [];
                if (!empty($cValue) && !is_numeric($cValue)) {
                    if (!empty($notValidateValueField) && in_array($cKey, $notValidateValueField)) {
                        $validateRes = ['res' => true, 'value' => $cValue];
                    } else {
                        $validateRes = $this->validateSqlValue($cValue);
                    }
                    if (empty($validateRes['res'] ?? false)) {
                        throw new ServiceException(['msg' => '数据中存在非法类型的值,原因: ' . ($validateRes['msg'] ?? '未知错误') . ' 请检查 错误key: ' . $cKey . ' 错误值: ' . $cValue]);
                    } else {
                        $dataList[$key][$cKey] = $validateRes['value'];
                    }
                }
            }
        }

        return $dataList;
    }


}