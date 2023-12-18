<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹区模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\CrowdFundingActivityException;
use app\lib\services\CodeBuilder;
use think\facade\Cache;
use think\facade\Db;
use think\model\relation\HasMany;

class CrowdfundingActivity extends BaseModel
{
    /**
     * @title  活动列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  后台-活动详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
        $info = $this->where(['activity_code' => $data['activity_code'], 'status' => $this->getStatusByRequestModule(1)])->findOrEmpty()->toArray();
        return $info;
    }

    /**
     * @title  编辑活动
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        $activityGiftType = self::where(['activity_code' => $data['activity_code']])->value('gift_type');
        $info = $this->baseUpdate(['activity_code' => $data['activity_code']], $data);
        //如果修改赠送类型则需要修改对应活动所有商品的赠送类型和数量
        if (!empty($data['gift_type'] ?? null) && $data['gift_type'] != $activityGiftType) {
            CrowdfundingActivityGoodsSku::where(['activity_code' => $data['activity_code']])->save(['gift_type' => $data['gift_type'], 'gift_number' => 0]);
        }
        //清除首页活动列表标签的缓存
        Cache::tag(['HomeApiCrowdFundingActivityList'])->clear();
        return $info;
    }

    /**
     * @title  新增活动
     * @param array $data
     * @return bool
     */
    public function DBNew(array $data)
    {
        $data['activity_code'] = (new CodeBuilder())->buildCrowdFundingActivityCode();
        $res = $this->baseCreate($data);
        return judge($res);
    }

    /**
     * @title  删除活动
     * @param string $aCode 活动编码
     * @param bool $noClearCache 是否清除缓存
     * @return mixed
     */
    public function del(string $aCode, bool $noClearCache = false)
    {
        $res = $this->cache('HomeApiCrowdFundingActivityList')->where(['activity_code' => $aCode])->save(['status' => -1]);
        //一并删除活动商品
        if (!empty($res)) {
            CrowdfundingActivityGoods::update(['status' => -1], ['activity_code' => $aCode, 'status' => [1, 2]]);
            CrowdfundingActivityGoodsSku::update(['status' => -1], ['activity_code' => $aCode, 'status' => [1, 2]]);
        }
        if (empty($noClearCache)) {
            if ($res) {
                cache('ApiCrowdFundingActivityList', null);
                //清除首页活动列表标签的缓存
                Cache::tag(['HomeApiCrowdFundingActivityList', 'ApiCrowdFundingActivityList'])->clear();
            }
        }

        return $res;
    }

    /**
     * @title  上下架活动
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
        $res = $this->cache('HomeApiCrowdFundingActivityList')->where(['activity_code' => $data['activity_code']])->save($save);

        if (empty($data['noClearCache'] ?? null)) {
            if ($res) {
                cache('ApiCrowdFundingActivityList', null);
                //清除首页活动列表标签的缓存
                Cache::tag(['HomeApiCrowdFundingActivityList', 'ApiCrowdFundingActivityList'])->clear();
            }
        }

        return $res;
    }

    public function goods()
    {
        return $this->hasMany('CrowdfundingActivity', 'activity_code', 'activity_code')->where(['status' => 1]);
    }

    /**
     * @title  返回数据格式化
     * @param array $data
     * @param int|null $pageTotal
     * @return array
     */
    public function dataFormat(array $data, int $pageTotal = null)
    {
        $dataEmpty = false;
        $aReturn = [];
        if (empty($data)) {
            $message = '暂无数据';
            $dataEmpty = true;
            if (isset($pageTotal) && !empty($pageTotal)) {
                $aReturn['pageTotal'] = $pageTotal;
                $aReturn['list'] = [];
            }
        }

        if (empty($dataEmpty)) {
            if (!empty($pageTotal)) {
                $aReturn['pageTotal'] = $pageTotal;
                $aReturn['list'] = $data ? $data : [];
            } else {
                $aReturn = $data;
            }

        }
        return $aReturn;
    }

    /**
     * @title  C端首页活动列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cHomeList(array $sear = []): array
    {
        $cacheKey = 'HomeApiCrowdFundingActivityList';
        $cacheExpire = 600;
        $cacheTag = 'HomeApiCrowdFundingActivityList';

        if (empty($sear['clearCache'])) {
            $cacheList = cache($cacheKey);
            if (!empty($cacheList)) {
                return $cacheList;
            }
        }

        $map[] = ['status', '=', 1];
        $activityList = $this->field('title,icon,desc,type,sort,limit_type,background_image,poster,start_time,end_time,activity_code')->where($map)->order('sort asc,create_time desc')->select()->toArray();
        if (!empty($activityList)) {
            foreach ($activityList as $key => $value) {
                //图片展示缩放,OSS自带功能
                if (!empty($value['background_image'])) {
                    $activityList[$key]['background_image'] .= '?x-oss-process=image/format,webp';
                }
                if (!empty($value['poster'])) {
                    $activityList[$key]['poster'] .= '?x-oss-process=image/format,webp';
                }
            }
            //加入缓存
            cache($cacheKey, $activityList, $cacheExpire, $cacheTag);
        }

        return $activityList;
    }

    /**
     * @title 众筹区轮期三级联动列表接口
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function crowdingSear(array $data)
    {
        $commonModel = (new User());
        $list = [];
        $map = [];
        switch ($data['searType'] ?? 1) {
            case 1:
                if (!empty($data['keyword'] ?? null)) {
                    $map[] = ['', 'exp', Db::raw($commonModel->getFuzzySearSql('activity_code|title', $data['keyword']))];
                }
                $list = CrowdfundingActivity::where($map)->field('activity_code,title')->select()->toArray();
                break;
            case 2:
                $map[] = ['activity_code', '=', $data['activity_code']];
                if (!empty($data['keyword'] ?? null)) {
                    $map[] = ['round_number', '=', intval($data['round_number'] ?? 1)];
                }
                $list = CrowdfundingPeriod::where($map)->field('activity_code,title,round_number as number')->group('activity_code,number')->select()->toArray();
                break;
            case 3:
                $map[] = ['activity_code', '=', $data['activity_code']];
                $map[] = ['round_number', '=', $data['round_number']];
                if (!empty($data['keyword'] ?? null)) {
                    $map[] = ['period_number', '=', intval($data['period_number'] ?? 1)];
                }
                $list = CrowdfundingPeriod::where($map)->field('activity_code,title,period_number as number')->group('activity_code,number')->select()->toArray();
                break;
            default:
                throw new CrowdFundingActivityException(['msg' => '未知的类型']);
        }
        return ['list' => $list ?? [], 'pageTotal' => 0];
    }

    public function period()
    {
        return $this->hasMany('CrowdfundingPeriod', 'activity_code', 'activity_code');
    }

}