<?php
// +----------------------------------------------------------------------
// |[ 文档说明: APP版本管理模块Model]
// +----------------------------------------------------------------------

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use Exception;
use think\facade\Db;

class AppVersion extends BaseModel
{

    protected $apiVersionListCacheKey = 'apiAppVersionList';

    /**
     * @title  APP版本列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        $cacheKey = false;
        $cacheExpire = 0;

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title|v_sn', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $field = $this->getListFieldByModule();

        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('type asc, create_time desc')->select()->each(function ($item){
            if(!empty($item['release_time'] ?? null)){
                $item['release_time'] = timeToDateFormat($item['release_time']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  C端APP版本列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function cList(array $sear = []): array
    {
        $cacheKey = $this->apiVersionListCacheKey;
        $cacheExpire = 3600 * 24;
        $map[] = ['type', 'in', [1, 2]];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $list = $this->where($map)->field('title,version,version_code,desc,package_url,type,force_update,release_time')->limit(2)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->order('type asc, create_time desc')->select()->each(function ($item){
            if(!empty($item['release_time'] ?? null)){
                $item['release_time'] = timeToDateFormat($item['release_time']);
            }
        })->toArray();
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  APP版本详情
     * @param string $vSn APP版本编码
     * @return mixed
     */
    public function info(string $vSn)
    {
        return $this->where(['v_sn' => $vSn, 'status' => $this->getStatusByRequestModule()])->withoutField('id,update_time')->findOrEmpty()->toArray();
    }

    /**
     * @title  新增APP版本
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        if ($data['type'] == 2) {
            $cogWhere[] = ['type', '=', 2];
            $cogWhere[] = ['status', '=', 1];
            $checkOtherGray = self::where($cogWhere)->count('id');
            if (!empty($checkOtherGray)) {
                throw new ServiceException(['msg' => '不允许同时存在两个灰度发布的版本']);
            }
            if (empty($data['gray_user'] ?? null)) {
                throw new ServiceException(['msg' => '请上传灰度发布的面向用户名单']);
            }
        }
        //校验版本名称和版本号
        $version = $data['version'] ?? null;
        $versionCode = $data['version_code'] ?? null;
        if (empty($version) || empty($versionCode)) {
            throw new ServiceException(['msg' => '请填写版本名称和版本号']);
        }
        if (!is_numeric($versionCode)) {
            throw new ServiceException(['msg' => '版本号必须为数字']);
        }
        $version = explode('.', $version);
        if (count($version) != 3) {
            throw new ServiceException(['msg' => '请保证填写的版本名称为X.X.X的格式,其中X必须为数字']);
        }
        $versionNumber = '';
        foreach ($version as $key => $value) {
            if (!is_numeric($value)) {
                throw new ServiceException(['msg' => '请保证填写的版本名称为X.X.X的格式,其中X必须为数字']);
            }
            $versionNumber .= $value;
        }
        $cvWhere[] = ['version', '>=', $versionNumber];
        $cvWhere[] = ['status', 'in', [1, 2]];
        $checkVersion = self::where($cvWhere)->count('id');
        if (!empty($checkVersion)) {
            throw new ServiceException(['msg' => '请填写最大的版本名称, 不允许小于过往版本名称']);
        }
        $cvcWhere[] = ['version_code', '>=', $versionCode];
        $cvcWhere[] = ['status', 'in', [1, 2]];
        $checkVersionCode = self::where($cvcWhere)->count('id');
        if (!empty($checkVersionCode)) {
            throw new ServiceException(['msg' => '请填写最大的版本号, 不允许小于过往版本号']);
        }
        $cvgWhere[] = ['status', '=', 1];
        $cvgWhere[] = ['type', '=', 2];
        $checkGrayPack = self::where($cvgWhere)->count('id');
        if (!empty($checkGrayPack)) {
            throw new ServiceException(['msg' => '仅允许存在一个灰度版本']);
        }
        $data['v_sn'] = (new CodeBuilder())->buildAppVersionSn();
        cache($this->apiVersionListCacheKey, null);
        return $this->baseCreate($data, true);
    }

    /**
     * @title  编辑APP版本
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        $info = $this->info($data['v_sn']);
        if (empty($info)) {
            throw new ServiceException(['msg' => '非法操作']);
        }
        //灰度发布和全量发布的线上版本仅允许修改描述, 标题, 和灰度发布的面向用户
        if ($info['type'] != 3) {
            $saveData['v_sn'] = $data['v_sn'];
            $saveData['desc'] = $data['desc'] ?? null;
            $saveData['title'] = $data['title'] ?? null;
            if ($info['type'] == 2) {
                if (empty($data['gray_user'] ?? null)) {
                    throw new ServiceException(['msg' => '请上传灰度发布的面向用户名单']);
                }
                $saveData['gray_user'] = $data['gray_user'];
                //如果是灰度版本的, 允许修改为普通版本
                if ($data['type'] == 3) {
                    $saveData['type'] = $data['type'];
                }
            }
            $data = $saveData;
        } else {
            //校验版本名称和版本号
            $version = $data['version'] ?? null;
            $versionCode = $data['version_code'] ?? null;
            if (empty($version) || empty($versionCode)) {
                throw new ServiceException(['msg' => '请填写版本名称和版本号']);
            }
            if (!is_numeric($versionCode)) {
                throw new ServiceException(['msg' => '版本号必须为数字']);
            }
            $version = explode('.', $version);
            if (count($version) != 3) {
                throw new ServiceException(['msg' => '请保证填写的版本号为X.X.X的格式,其中X必须为数字']);
            }
            $versionNumber = '';
            foreach ($version as $key => $value) {
                if (!is_numeric($value)) {
                    throw new ServiceException(['msg' => '请保证填写的版本号为X.X.X的格式,其中X必须为数字']);
                }
                $versionNumber .= $value;
            }
            $cvWhere[] = ['version', '>=', $versionNumber];
            $cvWhere[] = ['status', 'in', [1, 2]];
            $checkVersion = self::where($cvWhere)->count('id');
            if (!empty($checkVersion)) {
                throw new ServiceException(['msg' => '请填写最大的版本号, 不允许小于过往版本号']);
            }
            $cvcWhere[] = ['version_code', '>=', $versionCode];
            $cvcWhere[] = ['status', 'in', [1, 2]];
            $checkVersionCode = self::where($cvcWhere)->count('id');
            if (!empty($checkVersionCode)) {
                throw new ServiceException(['msg' => '请填写最大的版本号, 不允许小于过往版本号']);
            }
        }

        cache($this->apiVersionListCacheKey, null);
        return $this->baseUpdate(['v_sn' => $data['v_sn'], 'status' => [1, 2]], $data);
    }

    /**
     * @title  发布
     * @param string $vSn APP版本编码
     * @return mixed
     */
    public function release(string $vSn)
    {
        $info = $this->info($vSn);
        if (empty($info)) {
            throw new ServiceException(['msg' => '非法操作']);
        }
        if ($info['type'] == 1) {
            throw new ServiceException(['msg' => '已发布请勿重复操作']);
        }
        $res = self::update(['type' => 1, 'release_time' => time()], ['v_sn' => $vSn, 'type' => [2, 3], 'status' => [1, 2]]);
        $cMap[] = ['v_sn', '<>', $vSn];
        $cMap[] = ['type', '=', 1];
        $cMap[] = ['status', 'in', [1, 2]];
        self::update(['type' => 3], $cMap);
        cache($this->apiVersionListCacheKey, null);
        return true;
    }

    /**
     * @title  删除APP版本
     * @param string $vSn APP版本编码
     * @return mixed
     */
    public function del(string $vSn)
    {
        $info = $this->info($vSn);
        if (empty($info)) {
            throw new ServiceException(['msg' => '非法操作']);
        }
        if ($info['type'] != 3) {
            throw new ServiceException(['msg' => '仅允许删除普通版本']);
        }
        //查看当前发布版本的版本号, 不允许删除比当前发布版本号还要小的历史普通版本
        $nowReleaseVersion = self::where(['status' => 1, 'type' => 1])->order('create_time desc')->value('version');
        if (!empty($nowReleaseVersion)) {
            $releaseVersion = str_replace('.', '', $nowReleaseVersion);
            $thisVersion = str_replace('.', '', $info['version']);
            if ($thisVersion <= $releaseVersion) {
                throw new ServiceException(['msg' => '不允许删除比当前发布版本名称还要小的历史版本']);
            }
        }

        cache($this->apiVersionListCacheKey, null);
        return $this->baseDelete(['v_sn' => $vSn]);
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
        cache($this->apiVersionListCacheKey, null);
        return $this->baseUpdate(['v_sn' => $data['v_sn']], $save);
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'v_sn,title,version,version_code,desc,package_url,type,release_time,gray_user,force_update,status,create_time';
                break;
            case 'api':
            default:
                $field = 'title,version,desc,package_url,type,release_time';
                break;
        }
        return $field;
    }

}