<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use think\facade\Request;
use think\facade\Session;

class Auth
{
    /**
     * @var object 对象实例
     */
    protected static $instance;

    /**
     * 默认配置
     * 优先级低于 config/auth.php
     */

    protected $config = [
        'auth_on' => 1, // 权限开关
        'auth_type' => 1, // 认证方式，1为实时认证；2为登录认证。
        'auth_group' => 'auth_group', // 用户组数据表名
        'auth_group_access' => 'auth_group_access', // 用户-用户组关系表
        'auth_rule' => 'auth_rule', // 权限规则表
        'auth_user' => 'admin_user', // 用户信息表
    ];

    /**
     * 构造方法
     * Auth constructor.
     */
    public function __construct()
    {
        //可设置配置项 auth, 此配置项为数组。
        if ($auth = Config::get('auth')) {
            $this->config = array_merge($this->config, $auth);
        }
    }

    /**
     * @title  单例模式
     * @return Auth
     */
    public static function instance(): Auth
    {
        if (is_null(self::$instance)) {
            self::$instance = new Auth();
        }
        return self::$instance;
    }


    /**
     * @title 检查权限
     * @param $name  string|array 需要验证的规则列表,支持逗号分隔的权限规则或索引数组
     * @param $uid   int          认证用户的id
     * @param string $relation 如果为'or'表示满足任一条规则即通过验证;如果为'and'则表示需满足所有规则才能通过验证
     * @param int $type 认证类型
     * @param string $mode 执行check的模式
     * @return bool              通过验证返回true;失败返回false
     * @throws \Exception
     */
    public function check($name, int $uid, string $relation = 'or', int $type = 1, $mode = 'url'): bool
    {
        if (!$this->config['auth_on']) {
            return true;
        }
        // 获取用户需要验证的所有有效规则列表
        $authList = $this->getAuthList($uid, $type);

        if (is_string($name)) {
            if (strpos($name, ',') !== false) {
                $name = explode(',', $name);
            } else {
                $name = [$name];
            }
        }
        $list = []; //保存验证通过的规则名
        if ('url' == $mode) {
            //serialize()函数对在不同编码下对中文的处理结果是不一样的,使用正则表达式将序列化的数组中的表示字符长度的值重新计算一遍
            $serParam = preg_replace_callback('#s:(\d+):"(.*?)";#s', function ($match) {
                return 's:' . strlen($match[2]) . ':"' . $match[2] . '";';
            }, serialize(Request::param()));
            $REQUEST = unserialize($serParam);
        }
        foreach ($authList as $auth) {
            //获取url的query参数,判断跟权限name中的参数是不是一致
            $query = preg_replace('/^.+\?/U', '', $auth);
            if ('url' == $mode && $query != $auth) {
                //解析规则中的param，使其变成PHP变量
                parse_str($query, $param);
                //获取$REQUEST与$param带索引检查计算数组的交集
                $intersect = array_intersect_assoc($REQUEST, $param);
                //获取auth中的的query参数
                $auth = preg_replace('/\?.*$/U', '', $auth);

                if (in_array($auth, $name) && $intersect == $param) {
                    //如果节点相符且url参数满足
                    $list[] = $auth;
                }
            } else {
                //例如，$name='Admin/index',不带query参数，就不是url模式，那么就直接判断是否包含，否则如上代码判断url参数是否一致。
                if (in_array($auth, $name)) {
                    $list[] = $auth;
                }
            }
        }

        if ('or' == $relation && !empty($list)) {
            return true;
        }
        $diff = array_diff($name, $list);
        if ('and' == $relation && empty($diff)) {
            return true;
        }
        $not = array_intersect($name, $list);
        if ('not in' == $relation && !empty($not)) {
            return true;
        }
        return false;
    }

    /**
     * @title  根据用户id获取用户组,返回值为数组
     * @param  $uid int     用户id
     * @return array  用户所属的用户组
     * @throws \Exception
     */
    public function getGroups(int $uid): array
    {
        static $groups = [];
//        if (isset($groups[$uid])) {
//            return $groups[$uid];
//        }
        // 转换表名
        $type = Config::get('database.prefix') ? 1 : 0;
        $auth_group_access = parse_name($this->config['auth_group_access'], $type);
        $auth_group = parse_name($this->config['auth_group'], $type);
        // 执行查询
        $user_groups = Db::view($auth_group_access, 'admin_id,group_id')
            ->view($auth_group, 'title,rules', "{$auth_group_access}.group_id={$auth_group}.id", 'LEFT')
            ->where("{$auth_group_access}.admin_id='{$uid}' and {$auth_group}.status='1'")
            ->select()->toArray();
        $groups[$uid] = $user_groups ?: [];
        return $groups[$uid] ?? [];
    }

    /**
     * @title  获得权限列表
     * @param int $uid 管理员uid
     * @param int $type 类型
     * @return array
     * @throws \Exception
     */
    protected function getAuthList(int $uid, int $type): array
    {
        static $_authList = []; //保存用户验证通过的权限列表
        $t = implode(',', (array)$type);
//        if (isset($_authList[$uid . $t])) {
//            return $_authList[$uid . $t];
//        }

        //获取缓存
//        if (2 == $this->config['auth_type'] && Cache::has('_auth_list_' . $uid . $t)) {
//            return Cache::get('_auth_list_' . $uid . $t);
//        }

        //读取用户所属用户组
        $groups = $this->getGroups($uid);
        $ids = []; //保存用户所属用户组设置的所有权限规则id
        foreach ($groups as $g) {
            $ids = array_merge($ids, explode(',', trim($g['rules'], ',')));
        }
        $ids = array_unique($ids);
        if (empty($ids)) {
            $_authList[$uid . $t] = [];
            return [];
        }
        $map = array(
            'id' => $ids,
            'type' => $type,
            'status' => 1,
        );
        //读取用户组所有权限规则
        $rules = Db::name($this->config['auth_rule'])->where($map)->field('condition,name')->select()->toArray();
        //循环规则，判断结果。
        $authList = []; //
        foreach ($rules as $rule) {
            if (!empty($rule['condition'])) {
                //根据condition进行验证
                $user = $this->getUserInfo($uid); //获取用户信息,一维数组
                //使用用户的信息进行条件判断
                //condition定义的格式{字段} (> | == | <) 数值,正则会将{字段}替换成$user['字段'],然后再用eval比较
                //正则表达式替换，如定义{score}>5  and {score}<100  表示用户的分数在5-100之间时这条规则才会通过。
                $command = preg_replace('/\{(\w*?)\}/', '$user[\'\\1\']', $rule['condition']);
                //dump($command); //debug
                @(eval('$condition=(' . $command . ');'));
                if ($condition) {
                    //$authList[] = strtolower($rule['name']);
                    $authList[] = $rule['name'];
                }
            } else {
                //只要存在就记录
                //$authList[] = strtolower($rule['name']);
                $authList[] = $rule['name'];
            }
        }

        $_authList[$uid . $t] = $authList;
//        if (2 == $this->config['auth_type']) {
//            //规则列表结果保存到缓存中
//            Cache::set('_auth_list_' . $uid . $t, $authList,600);
//        }
        return array_unique($authList);
    }

    /**
     * 获得用户资料,根据自己的情况读取数据库
     */
    protected function getUserInfo(int $uid): array
    {
        static $userinfo = [];
        $user = Db::name($this->config['auth_user']);
        // 获取用户表主键
        $_pk = is_string($user->getPk()) ? $user->getPk() : 'id';
        if (!isset($userinfo[$uid])) {
            $userinfo[$uid] = $user->where($_pk, $uid)->findOrEmpty();
        }
        return $userinfo[$uid] ?? [];
    }

    /**
     * 菜单栏无限极分类(新版无限极, 会将子页面同权限划分在同一层级)
     * @param array $cate 需要分级的数组
     * @param int $pid 父类id
     * @param string $name 子类数组名字
     * @param string $isSubPage 是否为子页面层级
     * @return array       分好级的数组
     * @author  Coder
     * @date 2020年06月01日 11:59
     */
    function getGenreTreeNew(array $cate, $pid = 0, $name = 'son', $isSubPage = false)
    {
        $arr = array();
        foreach ($cate as $key => $v) {
            if ($v['pid'] == $pid) {
                //如果是查询子页面的, 权限规则中menu不是页面类型的跳过
                if (!empty($isSubPage) && $v['menu'] != 3) {
                    continue;
                }
                //如果不是查询子页面的, 权限规则中menu是页面类型的跳过
                if (empty($isSubPage) && $v['menu'] == 3 && $v['level'] == 2) {
                    continue;
                }
                unset($cate[$key]);
                //根据不同层级给不同子类名称
                if ($v['level'] == 1) {
                    $name = 'pages';
                } elseif ($v['level'] == 2) {
                    $name = 'permission';
                    //子页面的名称
                    $subPageName = 'pages';
                }

                if (!empty($subPageName ?? null)) {
                    $v[$subPageName] = $this->getGenreTreeNew($cate, $v['id'], $subPageName, true);
                    if (empty($v[$subPageName])) unset($v[$subPageName]);
                }
                $v[$name] = $this->getGenreTreeNew($cate, $v['id'], $name);

                if (empty($v[$name])) unset($v[$name]);

                $arr[] = $v;
            }
        }
        return $arr;
    }

    /**
     * 菜单栏无限极分类(老版无限极)
     * @param array $cate 需要分级的数组
     * @param int $pid 父类id
     * @param string $name 子类数组名字
     * @return array       分好级的数组
     * @date 2020年06月01日 11:59
     */
    function getGenreTree(array $cate, $pid = 0, $name = 'son')
    {
        $arr = array();
        foreach ($cate as $key => $v) {
            if ($v['pid'] == $pid) {

                unset($cate[$key]);
                //根据不同层级给不同子类名称
                if ($v['level'] == 1) {
                    $name = 'pages';
                } elseif ($v['level'] == 2) {
                    $name = 'permission';
                }

                $v[$name] = $this->getGenreTree($cate, $v['id'], $name);

                if (empty($v[$name])) unset($v[$name]);

                $arr[] = $v;
            }
        }
        return $arr;
    }


}