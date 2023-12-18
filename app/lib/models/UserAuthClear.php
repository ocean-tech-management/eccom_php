<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 清除登录授权记录表模块UserAuthClear]
// +----------------------------------------------------------------------

namespace app\lib\models;

use app\lib\exceptions\ServiceException;
use app\BaseModel;
use think\facade\Cache;
use think\facade\Db;
use app\lib\validates\UserAuthClear as UserAuthClearValidate;

class UserAuthClear extends BaseModel
{

    protected $autoWriteTimestamp = true;

    //关联管理员表
    public function admin()
    {
        return $this->belongsTo('AdminUser', 'admin_id');
    }

    //关联微信信息表
    public function User()
    {
        return $this->belongsTo('User', 'uid', 'uid');
    }

    //关联登录表
    public function userAuthType()
    {
        return $this->belongsTo('UserAuthType', 'uat_id');
    }

    //获取清除记录列表
    public function list(array $data)
    {
        $map = [];
        $map[] = ['uac.status', '=', 1];
        if (!empty($data['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('u.phone|uac.nickname', $data['keyword']))];
        }
        if (!empty($data['share_id'])) {
            $map[] = ['uac.share_id', '=', $data['share_id']];
        }
        if (!empty($data['app_id'])) {
            $map[] = ['uat.app_id', '=', $data['app_id']];
        }
        $page = intval($data['page'] ?? 0) ?: null;
        if (!empty($data['pageNumber'] ?? null)) {
            $this->pageNumber = intval($data['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = Db::name('user_auth_clear')
                ->alias('uac')
                ->join('sp_user_auth_type uat', 'uat.id = uac.uat_id', 'left')
                ->where($map)
                ->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = Db::name('user_auth_clear')
            ->alias('uac')
            ->field('uac.admin_name,uac.admin_account,uac.id,uac.phone,uac.share_id,uac.uid,uac.uat_id,uac.access_key,uac.nickname,uac.headimgurl,uac.image_url,uac.desc,uac.create_time,uat.app_id')
            ->alias('uac')
            ->join('sp_user_auth_type uat', 'uat.id = uac.uat_id', 'left')
            ->where($map)
            ->order('uac.create_time desc')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->select()
            ->each(function ($item) {
                $item['access_name'] = config('system.clientConfig.' . $item['access_key'])['name'];
                $item['create_time'] = timeToDateFormat($item['create_time']);
                return $item;
            })
            ->toArray();
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @description: 清除授权信息
     * @param {array} $data
     * @return {*}
     */
    public function new(array $data)
    {
        (new UserAuthClearValidate())->goCheck($data);
        $id = $data['id'];
        if (empty($id)) {
            throw new ServiceException(['msg' => '请输入授权表id']);
        }
        $res = Db::transaction(function () use ($id, $data) {
            //清除旧数据(多个连带unionid一样的记录)
            //禁用userAuthType
            $ust_data = UserAuthType::field('id,uid,tid,access_key,wxunionid')->where(['id' => $id, 'status' => 1])->find();
            if (empty($ust_data)) {
                throw new ServiceException(['msg' => '该记录不存在，请刷新后重试']);
            }
            $admin_data = AdminUser::field('id,name,account')->where(['id' => $data['adminId']])->find();
            $user = User::field('share_id,phone')->where(['uid' => $ust_data['uid']])->find();
            $data['admin_id'] = $data['adminId'];
            $data['admin_name'] = $admin_data['name'];
            $data['admin_account'] = $admin_data['account'];
            $data['share_id'] = $user['share_id'];
            $data['phone'] = $user['phone'];
            unset($data['id']);
            if ($ust_data['wxunionid']) {
                $wx_ust_datas = UserAuthType::field('id,uid,tid,access_key,wxunionid')->where(['wxunionid' => $ust_data['wxunionid'], 'status' => 1])->select();
                foreach ($wx_ust_datas as $k => $v) {
                    $user_data = WxUser::field('id,nickname,headimgurl')->where(['tid' => $v['tid']])->find();
                    //添加userAuthClear
                    $uac_data[$k] = $data;
                    $uac_data[$k]['uat_id'] = $v['id'];
                    $uac_data[$k]['uid'] = $v['uid'];
                    $uac_data[$k]['tid'] = $v['tid'];
                    $uac_data[$k]['access_key'] = $v['access_key'];
                    $uac_data[$k]['nickname'] = $user_data->nickname;
                    $uac_data[$k]['headimgurl'] = $user_data->headimgurl;
                }
                $usc_res = (new UserAuthClear)->saveAll($uac_data);
                $ust_res = UserAuthType::where(['wxunionid' => $ust_data['wxunionid']])->update(['status' => -1]);
                // $wxu_res = WxUser::where(['unionid' => $ust_data['wxunionid']])->delete();
                // $wxu_res = WxUser::where(['unionid' => $ust_data['wxunionid']])->update(['status' => -1]);
            } else {
                //判断accessModel类型
                $model = getAccessModel($ust_data['access_key']);
                $user_data = $model->field('id,nickname,headimgurl')->where(['tid' => $ust_data['tid']])->find();
                $ust_res = UserAuthType::where(['id' => $id])->update(['status' => -1]);
                //添加userAuthClear
                $uac_data = $data;
                $uac_data['uat_id'] = $ust_data->id;
                $uac_data['uid'] = $ust_data->uid;
                $uac_data['tid'] = $ust_data->tid;
                $uac_data['access_key'] = $ust_data->access_key;
                $uac_data['nickname'] = $user_data->nickname;
                $uac_data['headimgurl'] = $user_data->headimgurl;
                $usc_res = UserAuthClear::create($uac_data);
                // $wxu_res = $model->where(['tid' => $ust_data['tid']])->delete();
                // $wxu_res = $model->where(['tid' => $ust_data['tid']])->update(['status' => -1]);
            }
            if ($usc_res && $ust_res) {
                // if ($usc_res && $ust_res && $wxu_res) {
                //清除用户的缓存令牌, 迫使用户强制重新登录
                Cache::tag([$ust_data['uid']])->clear();
                return true;
            }

            return false;
        });

        return $res;
    }
}
