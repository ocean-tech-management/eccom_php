<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\Chapter;
use app\lib\models\Lecturer;
use app\lib\models\Member;
use app\lib\models\MemberVdc;
use app\lib\models\Subject as SubjectModel;
use app\lib\models\SubjectProgress;

class Subject extends BaseController
{
    protected $middleware = [
        'checkApiToken' => ['except' => ['list', 'info', 'chapterList', 'otherList']]
    ];

    /**
     * @title  课程列表
     * @param SubjectModel $model
     * @return string
     * @throws \Exception
     */
    public function list(SubjectModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  课程详情
     * @param SubjectModel $model
     * @return string
     * @throws \Exception
     */
    public function info(SubjectModel $model)
    {
        $uid = $this->request->param('uid');
        $info = $model->info($this->requestData);
        if (!empty($uid)) {
            $userMember = (new Member())->getUserMemberPrice($uid);
        }
        if (empty($userMember['discount'])) {
            $userMember = (new MemberVdc())->getDefaultMemberDis();
        }
        if (!empty($info)) {
            $info['member_price'] = priceFormat(($info['price'] ?? 0) * $userMember['discount']);
            $info['vdc_price'] = priceFormat(($info['price'] ?? 0) * ($userMember['vdc'] ?? 0));
        }
        return returnData($info);
    }

    /**
     * @title  章节列表
     * @param Chapter $model
     * @return string
     * @throws \Exception
     */
    public function chapterList(Chapter $model)
    {
        $data = $this->requestData;
        $data['subject_id'] = $data['id'];
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  章节详情
     * @param Chapter $model
     * @return string
     * @throws \Exception
     */
    public function chapterInfo(Chapter $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  最近学习
     * @param SubjectProgress $model
     * @return string
     * @throws \Exception
     */
    public function learning(SubjectProgress $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  更新进度/评论
     * @param SubjectProgress $model
     * @return string
     * @throws \Exception
     */
    public function progress(SubjectProgress $model)
    {
        $res = $model->newOrEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  课程其他列表
     * @param SubjectProgress $model
     * @return string
     * @remark 评论列表等,根据type区分
     * @throws \Exception
     */
    public function otherList(SubjectProgress $model)
    {
        $list = $model->otherList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  限免列表
     * @param SubjectModel $model
     * @return string
     * @throws \Exception
     */
    public function freeList(SubjectModel $model)
    {
        $data = $this->requestData;
        $data['subject_type'] = 2;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  讲师详情
     * @param Lecturer $model
     * @return string
     * @throws \Exception
     */
    public function lecturerInfo(Lecturer $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }
}