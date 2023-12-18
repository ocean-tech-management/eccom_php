<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 协议模块Model]
// +----------------------------------------------------------------------


namespace app\lib\models;

use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class Agreement extends BaseModel
{

    protected $apiAgreementInfoCacheKey = 'apiAgreementInfo';

    /**
     * @title  协议列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.ag_sn|a.title', $sear['keyword']))];
        }

        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber'] ?? 0);
        }
        if (!empty($page)) {
            $aTotal = $this->alias('a')->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->alias('a')
            ->join('sp_user_agreement b', 'a.ag_sn = b.ag_sn and a.status = 1', 'left')
            ->where($map)
            ->field("a.ag_sn,a.title,a.type,a.content,a.remark,a.attach_type,a.browse_time,a.status,a.create_time,sum(if(b.sign_status = 1,1,0)) as agreement_agree,sum(if(b.sign_status = 2,1,0)) as agreement_refuse")
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->group('a.ag_sn')->order('a.create_time desc')->select()->each(function ($item) {
                $item['agreement_sign_total'] = intval(($item['agreement_agree'] ?? 0) + ($item['agreement_refuse'] ?? 0));
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  协议详情
     * @param array $data
     * @return mixed
     */
    public function info(array $sear)
    {
        if (empty($sear['type'] ?? null) && empty($sear['ag_sn'] ?? null)) {
            throw new ServiceException(['msg' => '参数有误']);
        }
        if (!empty($sear['ag_sn'] ?? null)) {
            $map[] = ['ag_sn', '=', $sear['ag_sn']];
        }
        if (!empty($sear['type'] ?? null)) {
            $map[] = ['type', '=', $sear['type']];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $cacheKey = null;
        $cacheExpire = 0;
        if (!empty($sear['cache'] ?? null) && $this->module == 'api') {
            $cacheKey = $this->apiAgreementInfoCacheKey . '-' . md5(implode('-', $sear));
            $cacheExpire = $sear['cache_expire'] ?? 3600 * 24;
        }
        $field = $this->getListFieldByModule();

        return $this->where($map)->field($field)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->order('create_time desc')->findOrEmpty()->toArray();
    }

    /**
     * @title  新建协议
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        $data['ag_sn'] = (new CodeBuilder())->buildAgreementSn();
        $res = $this->baseCreate($data, true);
        return $res;
    }

    /**
     * @title  编辑协议
     * @param array $data
     * @return mixed
     */
    public function DBEdit(array $data)
    {
        $saveData = $data;
        unset($saveData['ag_sn']);
        $res = $this->baseUpdate(['ag_sn' => $data['ag_sn']], $saveData);
        cache($this->apiAgreementInfoCacheKey . '-' . $data['ag_sn'], null);
        return $res;
    }

    /**
     * @title  删除协议
     * @param array $data
     * @return mixed
     */
    public function DBDel(array $data)
    {
        $userAgreement = UserAgreement::where(['ag_sn' => $data['ag_sn'], 'status' => 1])->count('id');
        if (!empty($userAgreement)) {
            throw new ServiceException(['msg' => '已有用户签约该协议, 不允许删除']);
        }
        $res = $this->baseDelete(['ag_sn' => $data['ag_sn']]);
        cache($this->apiAgreementInfoCacheKey . '-' . $data['ag_sn'], null);
        return $res;
    }

    /**
     * @title  上下架
     * @param array $data
     * @return mixed
     */
    public function upOrDown(array $data)
    {
        if ($data['status'] == 1) {
            $save['status'] = 2;
        } elseif ($data['status'] == 2) {
            $save['status'] = 1;
        } else {
            return false;
        }
        cache($this->apiAgreementInfoCacheKey . '-' . $data['ag_sn'], null);
        return $this->baseUpdate(['ag_sn' => $data['ag_sn']], $save);
    }


    public function userAgreement()
    {
        return $this->hasOne('UserAgreement', 'ag_sn', 'ag_sn')->where(['status' => 1]);
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'ag_sn,title,type,content,remark,attach_type,browse_time,status,create_time';
                break;
            case 'api':
                $field = 'ag_sn,title,type,content,remark,content,attach_type,browse_time';
                break;
            default:
                $field = 'ag_sn,title,type,content,remark,attach_type,browse_time,create_time';
        }
        return $field;
    }
}