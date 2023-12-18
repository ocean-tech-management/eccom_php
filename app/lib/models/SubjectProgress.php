<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ParamException;
use app\lib\exceptions\ServiceException;
use app\lib\services\BaiduCensor;
use think\facade\Db;

class SubjectProgress extends BaseModel
{
    protected $field = ['subject_id', 'chapter_id', 'progress', 'uid', 'comment', 'score', 'comment_time', 'type', 'status'];
    protected $validateFields = ['subject_id', 'chapter_id', 'uid', 'status' => 'number'];

    /**
     * @title  用户课程进度列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name', $sear['keyword']))];
        }
        $map[] = ['a.status', 'in', $this->getStatusByRequestModule(2)];
        $map[] = ['a.uid', '=', $sear['uid']];
        $map[] = ['a.type', '=', 1];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = Db::name('subject_progress')->alias('a')
                ->join('sp_subject b', 'a.subject_id = b.id', 'left')
                ->join('sp_chapter c', 'a.chapter_id = c.id', 'left')
                ->where($map)
                ->group('a.id')
                ->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = Db::name('subject_progress')->alias('a')
            ->join('sp_subject b', 'a.subject_id = b.id', 'left')
            ->join('sp_chapter c', 'a.chapter_id = c.id', 'left')
            ->where($map)
            ->field('a.subject_id,c.id as chapter_id,a.uid,a.progress,a.comment,a.score,b.name as subject_name,b.cover_path,c.name as chapter_name,c.sort as chapter_sort,a.create_time,a.update_time')
            ->group('a.id')
            ->order('a.update_time desc')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->select()->each(function ($item, $key) {
                $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                $item['update_time'] = date('Y-m-d H:i:s', $item['update_time']);
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  课程其他列表
     * @param array $sear
     * @return array
     * @remark 评论列表等,根据type区分
     * @throws \Exception
     */
    public function otherList(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name', $sear['keyword']))];
        }
        $map[] = ['a.status', 'in', $this->getStatusByRequestModule(1)];

        if (!empty($sear['subject_id'])) {
            $map[] = ['a.subject_id', '=', $sear['subject_id']];
        }
        if (!empty($sear['chapter_id'])) {
            $map[] = ['a.chapter_id', '=', $sear['chapter_id']];
        }
        $map[] = ['a.type', '=', $sear['type'] ?? 2];

        if ($this->module == 'api') {
            $map[] = ['a.subject_id', '=', $sear['subject_id']];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = Db::name('subject_progress')->alias('a')
                ->join('sp_subject b', 'a.subject_id = b.id', 'left')
                ->join('sp_chapter c', 'a.chapter_id = c.id', 'left')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
                ->where($map)
                ->group('a.id')
                ->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = Db::name('subject_progress')->alias('a')
            ->join('sp_subject b', 'a.subject_id = b.id', 'left')
            ->join('sp_chapter c', 'a.chapter_id = c.id', 'left')
            ->join('sp_user d', 'a.uid = d.uid', 'left')
            ->where($map)
            ->field('a.id,a.subject_id,c.id as chapter_id,a.uid,a.progress,a.comment,a.score,a.status,b.name as subject_name,b.cover_path,c.name as chapter_name,c.sort as chapter_sort,a.create_time,a.comment_time,d.name as user_name,d.avatarUrl')
            ->group('a.id')
            ->order('a.update_time desc')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->select()->each(function ($item, $key) {
                $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                $item['comment_time'] = date('Y-m-d H:i:s', $item['comment_time']);
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**+
     * @title  更新课程进度
     * @param array $data
     * @return SubjectProgress|mixed
     * @remark 更新类型 1为更新进度 2为更新评论
     * @throws \Exception
     */
    public function newOrEdit(array $data)
    {
        $type = $data['type'] ?? 1;
        $map = ['subject_id' => $data['subject_id'], 'chapter_id' => $data['chapter_id'] ?? null, 'uid' => $data['uid'], 'status' => [1, 2], 'type' => $type];
        $nowProgress = $this->where($map)->findOrEmpty()->toArray();
        switch ($type) {
            case 1:
                if (empty($data['chapter_id'])) {
                    throw new ParamException(['msg' => '必须传章节关键参哦~']);
                }
                if (($nowProgress['progress'] ?? 0) >= $data['progress']) {
                    $save['progress'] = $nowProgress['progress'];
                } else {
                    $save['progress'] = intval($data['progress']);
                }
                break;
            case 2:
                $subjectInfo = (new Subject())->info(['id' => $data['subject_id'], 'uid' => $data['uid']]);
                if ($subjectInfo['is_buy'] == 2) {
                    throw new ParamException(['msg' => '还没购买不可以评价哦~']);
                }
                if (!empty($nowProgress['comment'])) {
                    throw new ParamException(['msg' => '已经评论过啦~']);
                }
                //审查内容
                $censor = (new BaiduCensor())->contentCensor(trim($data['comment']));
                if (!$censor) {
                    throw new ServiceException(['msg' => '您的评论存在不合规字眼,请文明守法发表评论']);
                }
                $save['comment'] = trim($data['comment']);
                $save['score'] = intval($data['score']);
                $save['comment_time'] = time();
                $save['status'] = 2;
                break;
            default:
                $save['progress'] = intval($data['progress']);
        }
        unset($map['status']);
        $saveData = array_merge_recursive($save, $map);

        if ($type == 1) {
            $res = $this->updateOrCreate($map, $saveData);
        } else {
            $res = $this->baseCreate($saveData);
        }

        return $res;

    }

    /**
     * @title  评论操作
     * @param array $data
     * @return SubjectProgress
     */
    public function edit(array $data)
    {
        $commentId = $data['comment_id'];
        $type = $data['type'] ?? 1;
        //type 1为禁用 2为启用 3为删除
        $commentInfo = $this->where([$this->getPk() => $commentId, 'type' => 2, 'status' => [1, 2]]);
        if (empty($commentInfo)) {
            throw new ParamException(['msg' => '此评论不存在']);
        }
        switch ($type) {
            case 1:
                $save['status'] = 2;
                break;
            case 2:
                $save['status'] = 1;
                break;
            case 3:
                $save['status'] = -1;
                break;
            default:
                $save['status'] = 2;
        }
        $res = $this->baseUpdate([$this->getPk() => $commentId], $save);
        return $res;
    }
}