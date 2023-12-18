<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹期销售时间段模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\ShipException;
use app\lib\services\CodeBuilder;
use think\facade\Cache;
use think\facade\Db;

class CrowdfundingPeriodSaleDuration extends BaseModel
{
    /**
     * @title  活动商品开放时间段详情
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear)
    {
        $map[] = ['activity_code', '=', $sear['activity_code']];
        $map[] = ['round_number', '=', $sear['round_number']];
        $map[] = ['period_number', '=', $sear['period_number']];

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('start_time asc,create_time desc')->select()->each(function ($item) {
            if (!empty($item['start_time'] ?? null)) {
                $item['start_time'] = timeToDateFormat($item['start_time']);
            }
            if (!empty($item['end_time'] ?? null)) {
                $item['end_time'] = timeToDateFormat($item['end_time']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新增或编辑时间段
     * @param array $data
     * @throws \Exception
     * @return bool
     */
    public function DBNewOrEdit(array $data)
    {
        $timeDuration = $data['time_duration'] ?? [];
        if (empty($timeDuration)) {
            return false;
        }
        $nowDurationList = [];
        $newDuration = [];
        $notExistDuration = false;
        $firstData = [];
        //如果是单独新增或编辑则需要判断总的销售占比, 如果是数组过来则标识是自动新增时间段, 可以全盘接受
        if (count($timeDuration) == 1) {
            foreach ($timeDuration as $key => $value) {
                unset($timeDuration[$key]['target_sum_scale']);
            }
            $firstData = current($timeDuration);
            $nowDurationList = self::where(['status' => 1, 'activity_code' => $firstData['activity_code'], 'round_number' => $firstData['round_number'], 'period_number' => $firstData['period_number']])->withoutField('id,target_sum_scale')->select()->order('start_time asc')->toArray();
            if (!empty($nowDurationList)) {
                foreach ($nowDurationList as $key => $value) {
                    if (!empty($firstData['duration_code']) && $value['duration_code'] == $firstData['duration_code']) {
                        continue;
                    }
                    if (strtotime($firstData['start_time']) >= $value['start_time'] && strtotime($firstData['start_time']) <= $value['end_time']) {
                        throw new ServiceException(['msg' => '当前编辑的时间段开始时间不允许出现历史开放时间段范围内']);
                    }
                    if ((strtotime($firstData['end_time']) >= $value['start_time'] && strtotime($firstData['end_time']) <= $value['end_time']) || (strtotime($firstData['end_time']) >= $value['end_time'] && strtotime($firstData['start_time']) <= $value['start_time'])) {
                        throw new ServiceException(['msg' => '当前编辑的时间段结束时间不允许出现历史开放时间段范围内']);
                    }
                }
                if(!empty($firstData['duration_code'] ?? null)){
                    $newDuration = $nowDurationList;
                    foreach ($newDuration as $key => $value) {
                        if($value['duration_code'] == $firstData['duration_code']){
                            $newDuration[$key]['start_time'] = strtotime($firstData['start_time']);
                            $newDuration[$key]['end_time'] = strtotime($firstData['end_time']);
                            $newDuration[$key]['target_scale'] = $firstData['target_scale'] ?? 0;
                        }

                    }
                }else{
                    $firstData['start_time'] = strtotime($firstData['start_time']);
                    $firstData['end_time'] = strtotime($firstData['end_time']);
                    $firstData['end_time'] = strtotime($firstData['end_time']);
                    $newDuration = array_merge_recursive($nowDurationList,[$firstData]);
                }

                array_multisort(array_column($newDuration,'start_time'), SORT_ASC, $newDuration);

                $nowAllScale = 0;
                $allSumScale = array_sum(array_column($newDuration,'target_scale'));
                foreach ($newDuration as $key => $value) {
                    if ((string)$nowAllScale < (string)$allSumScale) {
                        if ($key == 0) {
                            $nowAllScale = $value['target_scale'] ?? 0;
                            $newDuration[$key]['target_sum_scale'] = $nowAllScale;
                        } else {
                            $nowAllScale += ($value['target_scale'] ?? 0);
                            $newDuration[$key]['target_sum_scale'] = $nowAllScale;
                        }
                    } else {
                        if (doubleval($value['target_scale'] ?? 0) <= 0) {
                            $newDuration[$key]['target_sum_scale'] = 0;
                        }
                    }

                    if ($value['start_time'] == $firstData['start_time'] && $value['end_time'] == $firstData['end_time']) {
                        $firstData['target_sum_scale'] = $newDuration[$key]['target_sum_scale'];
                    }
                }

                if ($nowAllScale > 1) {
                    throw new ServiceException(['msg' => '时间段的销售额限购总比例不允许超过100%']);
                }
                foreach ($newDuration as $key => $value) {
                    if ($key == (count($newDuration) - 1)) {
                        if (doubleval($value['target_scale']) != 0) {
                            throw new ServiceException(['msg' => '为保证期能顺利完成, 请保证最后一个时间段销售额限购比例必须为0']);
                        }
                    }
                }
            } else {
                $notExistDuration = true;
                if (!empty($firstData['target_scale'] ?? null) && $firstData['target_scale'] > 1) {
                    throw new ServiceException(['msg' => '时间段的销售额限购总比例不允许超过100%']);
                }
                if (doubleval($firstData['target_scale']) != 0) {
                    throw new ServiceException(['msg' => '为保证期能顺利完成, 请保证最后一个时间段销售额限购比例必须为0']);
                }
            }
        }


        $DBRes = Db::transaction(function () use ($data,$nowDurationList,$newDuration,$notExistDuration,$firstData) {
            $timeDuration = $data['time_duration'];
            $coderBuilder = (new CodeBuilder());

            foreach ($timeDuration as $key => $value) {
                $DBNew = [];
                if (empty($value['duration_code'] ?? null)) {
                    $DBNew['duration_code'] = $coderBuilder->buildCrowdFundingSaleDurationCode();
                }
                $DBNew['activity_code'] = $value['activity_code'];
                $DBNew['round_number'] = $value['round_number'];
                $DBNew['period_number'] = $value['period_number'];
                $DBNew['target_scale'] = $value['target_scale'] ?? 0;
                if(count($timeDuration) == 1){
                    if (!empty($notExistDuration)) {
                        $DBNew['target_sum_scale'] = $value['target_scale'];
                    } else {
                        if (isset($firstData['target_sum_scale'])) {
                            $DBNew['target_sum_scale'] = $firstData['target_sum_scale'] ?? 0;
                        }
                    }
                }else{
                    $DBNew['target_sum_scale'] = $value['target_sum_scale'] ?? 0;
                }
                $DBNew['start_time'] = strtotime($value['start_time']);
                $DBNew['end_time'] = strtotime($value['end_time']);
                if (empty($value['duration_code'] ?? null)) {
                    $res[] = self::create($DBNew);
                } else {
                    $res[] = self::update($DBNew, ['duration_code' => $value['duration_code'], 'status' => [1,2]]);
                }
            }

            //更新对应所有时间对应的总销售额比例
            if (!empty($newDuration ?? [])) {
                foreach ($newDuration as $key => $value) {
                    if (isset($value['duration_code'])) {
                        self::update(['target_sum_scale' => $value['target_sum_scale'] ?? 0], ['duration_code' => $value['duration_code'], 'status' => [1, 2]]);
                    }
                }
            }
            return $res;
        });
        cache('HomeApiCrowdFundingActivityList', null);
        //清除首页活动列表标签的缓存
        Cache::tag(['HomeApiCrowdFundingActivityList'])->clear();

        return judge($DBRes);
    }

    /**
     * @title  删除时间段
     * @param string $durationCode
     * @throws \Exception
     * @return bool
     */
    public function del(string $durationCode)
    {
        $info = $this->where(['duration_code' => $durationCode, 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($info)) {
            throw new ServiceException(['msg' => '无效的时间段']);
        }
        if (doubleval($info['target_sum_scale']) <= 0) {
            $allDurationList = $this->where(['activity_code' => $info['activity_code'], 'round_number' => $info['round_number'], 'period_number' => $info['period_number'], 'status' => [1, 2], 'target_sum_scale' => 0, 'target_scale' => 0])->select()->toArray();
            if (count($allDurationList) <= 1) {
                throw new ServiceException(['msg' => '为保证期能顺利完成, 请保证至少有一个末端时间段销售额限购比例为0']);
            }
        }

        $res = $this->where(['duration_code' => $durationCode, 'status' => $this->getStatusByRequestModule()])->save(['status' => -1]);

        cache('HomeApiCrowdFundingActivityList', null);
        //清除首页活动列表标签的缓存
        Cache::tag(['HomeApiCrowdFundingActivityList'])->clear();

        return $res;
    }
}