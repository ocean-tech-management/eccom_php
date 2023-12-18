<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 登录表模块UserAuthType]
// +----------------------------------------------------------------------

namespace app\lib\models;

use app\BaseModel;
use think\facade\Db;

class UserAuthType extends BaseModel
{
    protected $field = ['uid', 'tid', 'create_time', 'openid', 'status', 'update_time', 'wxunionid', 'app_id', 'access_key'];

    protected $autoWriteTimestamp = true;

    //关联微信信息表
    public function getShareId()
    {
        return $this->belongsTo('User', 'uid', 'uid')->bind(['share_id' => 'share_id']);
    }

    //关联微信信息表
    public function wxUser()
    {
        return $this->hasOne('WxUser', 'tid', 'tid');
    }

    //关联超级app信息表
    public function appUser()
    {
        return $this->hasOne('AppUser', 'tid', 'tid');
    }
    //获取用户登录方式
    public function list(array $data)
    {
        $map = [
            'uid' => $data['uid'],
            'status' => 1,
        ];
        $page = intval($data['page'] ?? 0) ?: null;
        if (!empty($data['pageNumber'] ?? null)) {
            $this->pageNumber = intval($data['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = $this->field('id,uid,tid,access_key,app_id')
            ->with([
                'wxUser' => function ($query) {
                    $query->field('tid,nickname,headimgurl,create_time');
                },
                'appUser' => function ($query) {
                    $query->field('tid,nickname,headimgurl,create_time');
                }
            ])
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->where($map)
            ->select()
            ->each(function ($item) {
                $item = $this->checkAccess($item);
                return $item;
            })
            ->toArray();
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    //获取用户授权方式
    public function allList(array $data)
    {
        $map = [
            'uat.status' => 1,
        ];
        $page = intval($data['page'] ?? 0) ?: null;
        if (!empty($data['uid'] ?? null)) {
            $map['uid'] = $data['uid'];
        }
        if (!empty($data['access_name'])) {
            $config = config('system.clientConfig');
            $app_ids = array_column($config, 'app_id', 'name');
            if (isset($app_ids[$data['access_name']]) && !empty($app_ids[$data['access_name']])) {
                $map[] = ['uat.app_id', '=', $app_ids[$data['access_name']]];
            } else {
                $map[] = ['uat.app_id', '=', 000000];
            }
        }
        if (!empty($data['user_keyword'])) {
            //查询user表
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('u.phone|u.name', $data['user_keyword']))];
        }
        if (!empty($data['auth_keyword'])) {
            //查询user表
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('wx.nickname', $data['auth_keyword']))];
        }
        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            $map[] = ['uat.create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        }
        if (!empty($data['pageNumber'] ?? null)) {
            $this->pageNumber = intval($data['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->alias('uat')
                ->join('sp_user u', 'uat.uid = u.uid', 'left')
                ->join('sp_wx_user wx', 'uat.tid = wx.tid', 'left')
                ->join('sp_app_user au', 'uat.tid = au.tid', 'left')
                ->where($map)
                ->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $field = "uat.id,uat.uid,uat.app_id,uat.access_key,uat.create_time as auth_time,u.share_id,u.name,u.avatarUrl,u.phone,u.create_time as user_time,wx.nickname,wx.headimgurl";
        $list = $this->field($field)
            ->alias('uat')
            ->join('sp_user u', 'uat.uid = u.uid', 'left')
            ->join('sp_wx_user wx', 'uat.tid = wx.tid', 'left')
            ->join('sp_app_user au', 'uat.tid = au.tid', 'left')
            ->where($map)
            ->order('uat.create_time desc')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->select()
            ->each(function ($item, $key) {
                if (!empty($item['auth_time'])) $item['auth_time'] = date('Y-m-d H:i:s', $item['auth_time']);
                if (!empty($item['user_time'])) $item['user_time'] = date('Y-m-d H:i:s', $item['user_time']);
                $item['access_name'] = config('system.clientConfig.' . $item['access_key'])['name'];
                return $item;
            })
            ->toArray();
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    //整理多表中的授权信息数据
    public function checkAccess($item)
    {
        $access_data = [];
        //判断access_key类型
        $type = substr($item['access_key'], 0, 1);
        // dd($type);
        switch ($type) {
            case 'a':
                $access_data = $item['appUser'] ?? null;
                break;
            case 'p':
            case 'm':
                $access_data = $item['wxUser'] ?? null;
                break;
            default:
                # code...
                break;
        }
        if ($access_data) {
            $item['info'] = $access_data;
            $item['info']['access_key'] = $item['access_key'];
            $item['info']['app_id'] = $item['app_id'];
            $item['info']['access_name'] = config('system.clientConfig.' . $item['access_key'])['name'];
            unset($item['appUser']);
            unset($item['wxUser']);
        }
        return $item;
    }
}
