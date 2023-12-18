<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\callback\v1;


use app\BaseController;
use app\lib\models\Chapter;
use app\lib\models\File;
use app\lib\models\GoodsSku;
use app\lib\services\AlibabaCos;
use app\lib\services\Log;
use think\facade\Request;

class CosCallback extends BaseController
{
    private $belong = 1;

    /**
     * @title  COS转码回调
     * @return string
     * @throws \AlibabaCloud\Client\Exception\ServerException
     * @throws \AlibabaCloud\Client\Exception\ClientException
     */
    public function callback()
    {
        $body = Request::param();
        if (!empty($body)) {
            $save['source_id'] = $body['VideoId'] ?? null;
            if ($body['EventType'] == 'StreamTranscodeComplete') {
                $save['source_url'] = $body['FileUrl'];
            }
            if (!empty($save['source_id'])) {
                $videoInfo = (new AlibabaCos())->getVideoInfo($save['source_id']);
                $body['videoInfo'] = $videoInfo;
                $save['cover_url'] = $videoInfo['Video']['CoverURL'] ?? null;
                $res = File::update($save, ['source_id' => $save['source_id'], 'status' => 1]);
                if (!empty($save['source_url'])) {
                    $saveChapter['content'] = $save['source_url'];
                    $saveChapter['status'] = 1;
                    $chapterInfo = Chapter::where(['source_id' => $save['source_id']])->column('id');
                    $resChapter = Chapter::update($saveChapter, ['source_id' => $save['source_id']]);
                    $resSkuChapter = GoodsSku::update(['status' => 1], ['content' => $chapterInfo]);
                }
            }

        }
        (new Log())->record($body, 'info');
        return returnMsg();
    }
}