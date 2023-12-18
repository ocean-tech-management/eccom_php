<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户签约协议模块Model]
// +----------------------------------------------------------------------

namespace app\lib\models;

use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class UserAgreement extends BaseModel
{

    /**
     * @title  用户签约协议列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn|uag_sn|title', $sear['keyword']))];
        }
        if (!empty($sear['userKeyword'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone|name', $sear['userKeyword']))];
            $linkUid = User::where($uMap)->value('uid');
            if (!empty($linkUid)) {
                $map[] = ['uid', '=', $linkUid];
            }
        }

        if (!empty($sear['ag_sn'] ?? null)) {
            $map[] = ['ag_sn', '=', $sear['ag_sn']];
        }

        if (!empty($sear['sign_status'] ?? null)) {
            $map[] = ['sign_status', '=', intval($sear['sign_status'])];
        }

        if ($this->module == 'api') {
            $map[] = ['uid', '=', $sear['uid']];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber'] ?? 0);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $field = $this->getListFieldByModule();

        $list = $this->with(['user'])->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户签约协议详情
     * @param array $data
     * @return mixed
     */
    public function info(array $data)
    {
        if (!empty($data['type'] ?? null)) {
            $map[] = ['type', '=', intval($data['type'])];
        }
        if (!empty($data['uag_sn'] ?? null)) {
            $map[] = ['uag_sn', '=', intval($data['uag_sn'])];
        }
        if ($this->module == 'api') {
            $map[] = ['uid', '=', $data['uid']];
        }
        return $this->where($map)->field("uag_sn,uid,order_sn,type,ag_sn,title,sign_status,remark,content,snapshot,create_time")->order('create_time desc,id asc')->findOrEmpty()->toArray();
    }

    /**
     * @title  用户新签约协议
     * @param array $data
     * @throws \Exception
     * @return mixed
     */
    public function DBNew(array $data)
    {
        $data['uag_sn'] = (new CodeBuilder())->buildUserAgreementSn();
        $agInfo = Agreement::where(['ag_sn' => $data['ag_sn'], 'status' => 1])->field('ag_sn,title,content,type,remark,attach_type')->findOrEmpty()->toArray();
        if (empty($agInfo)) {
            throw new ServiceException(['msg' => '未生效的协议']);
        }
        if ($agInfo['attach_type'] != -1 && empty($data['snapshot'] ?? null)) {
            throw new ServiceException(['msg' => '请补充额外条件']);
        }
        $existInfo = UserAgreement::where(['ag_sn' => $data['ag_sn'], 'status' => 1, 'uid' => $data['uid']])->count();
        if (!empty($existInfo)) {
            throw new ServiceException(['msg' => '已经签约过啦']);
        }
        $data['title'] = $agInfo['title'];
        $data['order_sn'] = $data['order_sn'] ?? null;
        $data['type'] = $agInfo['type'];
        $data['content'] = $agInfo['content'];
       // $data['sign_status'] = $data['sign_status'];
        $data['remark'] = $agInfo['remark'];
        $res = $this->baseCreate($data, true);
        return $res;
    }


    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name'=>'name', 'user_phone' => 'phone', 'user_avatarUrl' => 'avatarUrl']);
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'uag_sn,uid,order_sn,type,ag_sn,title,content,snapshot,sign_status,remark,status,create_time';
                break;
            case 'api':
                $field = 'uag_sn,uid,order_sn,type,ag_sn,title,sign_status,remark,create_time';
                break;
            default:
                $field = 'uag_sn,uid,order_sn,type,ag_sn,title,content,sign_status,remark,snapshot,status,create_time';
        }
        return $field;
    }
}