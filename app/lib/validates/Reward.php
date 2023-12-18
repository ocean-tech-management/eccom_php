<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 团队奖励机制规则模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\Reward as RewardModel;

class Reward extends BaseValidate
{
    public $errorCode = 500110;

    protected $rule = [
        'id' => 'require',
        'level' => 'require|number|checkLevelC',
        'recommend_number' => 'require|number',
        'train_level' => 'number',
        'team_number' => 'require',
        'reward_scale' => 'require',
        'freed_scale' => 'require',
        'freed_cycle' => 'require',

    ];

    protected $message = [
        'id.require' => '缺少唯一标识',
        'level.require' => '等级必选',
        'recommend_number.require' => '直推人数必填',
        'team_number.require' => '团队总人数必填',
        'train_level.number' => '会员等级只能为数字',
        'reward_scale.require' => '奖励比例必填',
        'freed_scale.require' => '释放比例必填',
        'freed_cycle.require' => '释放周期必填',
    ];

    protected $scene = [
        'create' => ['level', 'recommend_number', 'team_number', 'reward_scale', 'freed_scale', 'freed_cycle', 'train_level'],
        'edit' => ['id', 'level', 'recommend_number', 'team_number', 'reward_scale', 'freed_scale', 'freed_cycle', 'train_level'],
    ];

    public function sceneEdit()
    {
        return $this->remove('level', 'checkLevelC')
            ->append('level', 'checkLevelE');
    }

    public function checkLevelC($value, $rule, $data, $fieldName)
    {
        $exist = RewardModel::where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        if (empty($exist)) {
            $data['train_level'] = $data['train_level'] ?? 0;
            $data['train_number'] = $data['train_number'] ?? 0;
            $exist = RewardModel::where(['recommend_number' => $data['recommend_number'], 'train_level' => $data['train_level'], 'train_number' => $data['train_number'], 'team_number' => $data['team_number'], $this->statusField => [1, 2]])->count();
        }
        return empty($exist) ? true : '该等级或相同条件的规则已存在';
    }

    public function checkLevelE($value, $rule, $data, $fieldName)
    {
        $exist = RewardModel::where([$fieldName => trim($value), $this->statusField => [1, 2]])->where([['id', '<>', $data['id']]])->count();
        if (empty($exist)) {
            $data['train_level'] = $data['train_level'] ?? 0;
            $data['train_number'] = $data['train_number'] ?? 0;
            $exist = RewardModel::where(['recommend_number' => $data['recommend_number'], 'train_level' => $data['train_level'], 'train_number' => $data['train_number'], 'team_number' => $data['team_number'], $this->statusField => [1, 2]])->where([['id', '<>', $data['id']]])->count();
        }
        return empty($exist) ? true : '该等级或相同条件的规则已存在';
    }
}