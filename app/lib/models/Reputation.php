<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品系统评价(口碑)模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\BaiduCensor;
use think\facade\Db;
use app\lib\validates\Reputation as ReputationValidate;

class Reputation extends BaseModel
{
    /**
     * @title  口碑列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        $list = [];
        $cacheKey = 'apiGoodsReputationList';
        $cacheExpire = 60 * 30;

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('user_name|title|sub_title', $sear['keyword']))];
        }

        if (!empty($sear['goods_sn'])) {
            if (is_array($sear['goods_sn'])) {
                $map[] = ['goods_sn', 'in', $sear['goods_sn']];
            } else {
                $map[] = ['goods_sn', '=', $sear['goods_sn']];
            }
        }
        if ($this->module == 'api') {
            if (empty($sear['clearCache'] ?? null)) {
                $data = cache($cacheKey . $sear['goods_sn']);
                if (!empty($data)) {
                    return $data;
                }
            }
        }

        if ($this->module != 'api' && !empty($sear['check_status'])) {
            $map[] = ['check_status', '=', intval($sear['check_status'])];
        }

        if ($this->module == 'api' && empty($sear['goods_sn'])) {
            return ['list' => [], 'pageTotal' => 0];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if ($this->module == 'api') {
            $map[] = ['check_status', '=', 1];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $field = $this->getListFieldByModule();

        $list = $this->with(['images' => function ($query) {
            $query->field(['id,reputation_id,image_url,sort'])->order('sort asc,create_time desc');
        }, 'goods' => function ($query) {
            $query->field('goods_sn,title,goods_code,main_image');
        }])->withMin(['goodsSku' => 'sale_price'], 'sale_price')->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('is_top asc,is_featured asc,sort asc,create_time desc')->select()->each(function ($item) {
            if (!empty($item['images'])) {
                $imageDomain = config('system.imgDomain');
                foreach ($item['images'] as $key => $value) {
                    $item['images'][$key]['image_url'] = substr_replace($value['image_url'], $imageDomain, strpos($value['image_url'], '/'), strlen('/'));
                }
            }
            if ($this->module == 'api') {
                if (!empty($item['images'])) {
                    foreach ($item['images'] as $key => $value) {
                        if (!empty($value['image_url'] ?? null)) {
                            $item['images'][$key]['thumbnail'] = $value['image_url'] . '?x-oss-process=image/resize,w_750/quality,q_95';
                        }
                        if (!empty($value['image_url'] ?? null)) {
                            $item['images'][$key]['image_url'] .= '?x-oss-process=image/quality,q_100';
                        }
                    }
                }
            }
            return $item;
        })->toArray();

        $all = ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];

        if ($this->module == 'api') {
            cache($cacheKey . $sear['goods_sn'], $all, $cacheExpire);
        }
        return $all;
    }


    /**
     * @title  口碑评价详情
     * @param int $id 口碑评价id
     * @return mixed
     */
    public function info(int $id)
    {
        $info = $this->with(['images' => function ($query) {
            $query->field(['id,reputation_id,image_url,sort'])->order('sort asc,create_time desc');
        }, 'goods' => function ($query) {
            $query->field('goods_sn,title,goods_code,main_image');
        }])->where([$this->getPk() => $id, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
        if (!empty($info['images'])) {
            $imageDomain = config('system.imgDomain');
            foreach ($info['images'] as $key => $value) {
                $info['images'][$key]['image_url'] = substr_replace($value['image_url'], $imageDomain, strpos($value['image_url'], '/'), strlen('/'));
            }
        }
        return $info ?? [];
    }

    /**
     * @title  新增口碑评价
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $data['user_name'] = trim($data['user_name']);
        $data['content'] = trim($data['content']);
        (new ReputationValidate())->goCheck($data, 'create');

        $DBRes = false;
        $DBRes = Db::transaction(function () use ($data) {
            $data['check_status'] = 1;
            $newReputationId = $this->baseCreate($data, true);

            if (!empty($data['images'])) {
                foreach ($data['images'] as $key => $value) {
                    $addImages[$key]['reputation_id'] = $newReputationId;
                    $addImages[$key]['id'] = null;
                    $addImages[$key]['image_url'] = $value['image_url'];
                    $addImages[$key]['sort'] = intval($value['sort'] ?? 9999);
                }
                (new ReputationImages())->newOrEdit($addImages);
            }
            return $newReputationId;
        });

        if (!empty($data['goods_sn'] ?? null)) {
            cache('apiGoodsReputationList' . $data['goods_sn'], null);
        }

        return $DBRes;
    }

    /**
     * @title  口碑评价官提交评价
     * @param array $data
     * @return mixed
     */
    public function userSubmit(array $data)
    {
        $userInfo = ReputationUser::where(['uid' => $data['uid'], 'status' => 1])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new ServiceException(['msg' => '您暂无法提交口碑评价哦~']);
        }
        $content = trim($data['content']);
        if (empty($content)) {
            throw new ServiceException(['msg' => '请填写真实的口碑评价哟~优质的口碑评价可以让其他用户有更好的体验哦!']);
        }
        //文本审核
        $checkRes = (new BaiduCensor())->contentCensor($content);
        if (empty($checkRes)) {
            throw new ServiceException(['msg' => '系统检测到评价中存在不合规的文本,请您校验后重新提交! 请勿出现低俗辱骂、违禁词、暴恐色情、政治敏感类文本!']);
        }

        $data['user_name'] = trim($userInfo['user_name']);
        $data['user_code'] = trim($userInfo['user_code']);
        $data['user_avatarUrl'] = $userInfo['user_avatarUrl'];
        $data['user_tag'] = !empty($data['user_tag'] ?? null) ? trim($data['user_tag']) : ($userInfo['user_tag'] ?? null);
        $data['title'] = !empty($data['title'] ?? null) ? trim($data['title']) : null;
        $data['content'] = $content;
        (new ReputationValidate())->goCheck($data, 'create');

        $DBRes = false;
        $DBRes = Db::transaction(function () use ($data) {
            $data['link_type'] = 1;
            $data['type'] = 2;
            $data['check_status'] = 3;
            $newReputationId = $this->baseCreate($data, true);

            if (!empty($data['images'])) {
                foreach ($data['images'] as $key => $value) {
                    $addImages[$key]['reputation_id'] = $newReputationId;
                    $addImages[$key]['id'] = null;
                    $addImages[$key]['image_url'] = $value['image_url'];
                    $addImages[$key]['sort'] = intval($value['sort'] ?? 9999);
                }
                (new ReputationImages())->newOrEdit($addImages);
            }
            return $newReputationId;
        });

        if (!empty($data['goods_sn'] ?? null)) {
            cache('apiGoodsReputationList' . $data['goods_sn'], null);
        }

        return $DBRes;
    }

    /**
     * @title  编辑口碑评价
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        $data['user_name'] = trim($data['user_name']);
        $data['content'] = trim($data['content']);
        (new ReputationValidate())->goCheck($data, 'edit');

        $DBRes = false;
        $DBRes = Db::transaction(function () use ($data) {
            $data['check_status'] = 1;
            $res = $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
            if (!empty($data['images'])) {
                if (!empty($data['images'])) {
                    (new ReputationImages())->newOrEdit($data['images']);
                }
            }
            return $res;
        });

        if (!empty($data['goods_sn'] ?? null)) {
            cache('apiGoodsReputationList' . $data['goods_sn'], null);
        }

        return $DBRes;
    }

    /**
     * @title  删除口碑评价
     * @param int $id 口碑评价id
     * @return mixed
     */
    public function del(int $id)
    {
        $info = $this->where([$this->getPk() => $id])->findOrEmpty()->toArray();
        $res = $this->baseDelete([$this->getPk() => $id]);
        (new ReputationImages())->baseDelete(['reputation_id' => $id]);

        if (!empty($info['goods_sn'] ?? null)) {
            cache('apiGoodsReputationList' . $info['goods_sn'], null);
        }
        return $res;
    }

    /**
     * @title  上下架口碑评价
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
        $info = $this->where([$this->getPk() => $data[$this->getPk()]])->findOrEmpty()->toArray();
        if (!empty($info['goods_sn'] ?? null)) {
            cache('apiGoodsReputationList' . $info['goods_sn'], null);
        }
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $save);
    }

    /**
     * @title  置顶口碑评价
     * @param array $data
     * @return mixed
     */
    public function top(array $data)
    {
        if ($data['is_top'] == 1) {
            $save['is_top'] = 2;
        } elseif ($data['is_top'] == 2) {
            $save['is_top'] = 1;
        } else {
            return false;
        }
        $info = $this->where([$this->getPk() => $data[$this->getPk()]])->findOrEmpty()->toArray();
        if (!empty($info['goods_sn'] ?? null)) {
            cache('apiGoodsReputationList' . $info['goods_sn'], null);
        }
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $save);
    }

    /**
     * @title  精选口碑评价
     * @param array $data
     * @return mixed
     */
    public function featured(array $data)
    {
        if ($data['is_featured'] == 1) {
            $save['is_featured'] = 2;
        } elseif ($data['is_featured'] == 2) {
            $save['is_featured'] = 1;
        } else {
            return false;
        }
        $info = $this->where([$this->getPk() => $data[$this->getPk()]])->findOrEmpty()->toArray();
        if (!empty($info['goods_sn'] ?? null)) {
            cache('apiGoodsReputationList' . $info['goods_sn'], null);
        }
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $save);
    }

    /**
     * @title  更新口碑评价排序
     * @param array $data
     * @return mixed
     */
    public function updateSort(array $data)
    {
        if (empty($data['sort'])) {
            return false;
        }
        $info = $this->where([$this->getPk() => $data[$this->getPk()]])->findOrEmpty()->toArray();
        if (!empty($info['goods_sn'] ?? null)) {
            cache('apiGoodsReputationList' . $info['goods_sn'], null);
        }
        $save['sort'] = intval(trim($data['sort']));
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $save);
    }

    /**
     * @title  审核口碑评价
     * @param array $data
     * @return Reputation
     */
    public function check(array $data)
    {
        $existCheckStatus = $this->where([$this->getPk() => $data[$this->getPk()]])->value('check_status');
        if ($existCheckStatus != 3) {
            throw new ServiceException(['msg' => '不可审核的记录']);
        }
        switch ($data['check_status'] ?? 0) {
            case 1:
                $save['check_status'] = 1;
                break;
            case 2:
                if (empty(trim($data['check_remark']))) {
                    throw new ServiceException(['msg' => '请填写不通过原因']);
                }
                $save['check_status'] = 2;
                $save['check_remark'] = trim($data['check_remark']);
                break;
            default:
                throw new ServiceException(['msg' => '无效的操作']);
        }
        $info = $this->where([$this->getPk() => $data[$this->getPk()]])->findOrEmpty()->toArray();
        if (!empty($info['goods_sn'] ?? null)) {
            cache('apiGoodsReputationList' . $info['goods_sn'], null);
        }
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $save);
    }


    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
            case 'api':
                $field = 'id,user_name,user_avatarUrl,user_tag,title,sub_title,content,link_type,goods_sn,is_top,is_featured,sort,status,create_time';
                break;
            default:
                $field = '*';
        }
        return $field;
    }

    public function images()
    {
        return $this->hasMany('ReputationImages', 'reputation_id', 'id')->where(['status' => 1]);
    }

    public function goods()
    {
        return $this->hasOne('GoodsSpu', 'goods_sn', 'goods_sn');
    }

    public function goodsSku()
    {
        return $this->hasMany('GoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1]);
    }
}