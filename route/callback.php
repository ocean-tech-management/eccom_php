<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 回调模块路由配置]
// +----------------------------------------------------------------------


use think\facade\Route;
Route::group('callback/<version>',function(){
    //微信模块
    Route::group('wx',function(){
        Route::rule('callback','PayCallback/payCallBack');
        Route::rule('memberCallback','PayCallback/memberCallBack');
        Route::rule('refundCallback','PayCallback/refundCallback');
        Route::rule('ptRefundCallback','PayCallback/ptRefundCallback');
        Route::rule('ppylRefundCallback','PayCallback/ppylRefundCallback');
        Route::rule('ppylRefund','PayCallback/ppylRefundCallback');
        Route::rule('ppylWinRefund','PayCallback/ppylWinRefundCallback');
        Route::rule('ppylWaitRefund','PayCallback/ppylWaitRefundCallback');
    });

    //阿里巴巴服务模块
    Route::group('alibaba',function(){
        Route::rule('callback','CosCallback/callback');
    });

    //物流(快递100)服务模块
    Route::group('shipping',function(){
        Route::rule('callback','ShippingCallback/callback');
    });

    //汇聚支付模块
    Route::group('joinPay',function(){
        Route::rule('callback','JoinPayCallback/payCallBack');
        Route::rule('agreementCallBack','JoinPayCallback/agreementPayCallBack');
        Route::rule('refund','JoinPayCallback/refundCallback');
        Route::rule('agreementRefund','JoinPayCallback/agreementRefundCallback');
        Route::rule('deviceRefund','JoinPayCallback/deviceRefundCallback');
        Route::rule('ptRefund','JoinPayCallback/ptRefundCallback');
        Route::rule('payment','JoinPayCallback/payment');
        Route::rule('memberCallback','JoinPayCallback/memberCallBack');
        Route::rule('ppylRefund','JoinPayCallback/ppylRefundCallback');
        Route::rule('ppylWinRefund','JoinPayCallback/ppylWinRefundCallback');
        Route::rule('ppylWaitRefund','JoinPayCallback/ppylWaitRefundCallback');
    });

    //乐小活服务模块
    Route::group('letfree',function(){
        Route::rule('callback','LetfreeCallback/callBack');
        Route::rule('callbackDev','LetfreeCallback/callBack');
    });

    //杉德支付模块
    Route::group('sandPay',function(){
        Route::rule('callback','SandPayCallback/payCallBack');
        Route::rule('agreementCallBack','SandPayCallback/agreementPayCallBack');
        Route::rule('refund','SandPayCallback/refundCallback');
        Route::rule('agreementRefund','SandPayCallback/agreementRefundCallback');
        Route::rule('deviceRefund','SandPayCallback/deviceRefundCallback');
        Route::rule('ptRefund','SandPayCallback/ptRefundCallback');
        Route::rule('payment','SandPayCallback/payment');
        Route::rule('memberCallback','SandPayCallback/memberCallBack');
        Route::rule('ppylRefund','SandPayCallback/ppylRefundCallback');
        Route::rule('ppylWinRefund','SandPayCallback/ppylWinRefundCallback');
        Route::rule('ppylWaitRefund','SandPayCallback/ppylWaitRefundCallback');
    });

    //银盛支付模块
    Route::group('ysePay',function(){
        Route::rule('callback','YsePayCallback/payCallBack');
        Route::rule('agreementCallBack','YsePayCallback/agreementPayCallBack');
        Route::rule('refund','YsePayCallback/refundCallback');
        Route::rule('agreementRefund','YsePayCallback/agreementRefundCallback');
        Route::rule('deviceRefund','YsePayCallback/deviceRefundCallback');
        Route::rule('ptRefund','YsePayCallback/ptRefundCallback');
        Route::rule('payment','YsePayCallback/payment');
        Route::rule('memberCallback','YsePayCallback/memberCallBack');
    });


})->prefix('callback.<version>.')->allowCrossDomain([
    'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, If-Match, If-Modified-Since, If-None-Match, If-Unmodified-Since, X-CSRF-TOKEN, X-Requested-With,token',
])->completeMatch();
Route::miss(function() {
    return json(['error_code'=>-1,'msg'=>'不要猜啦~']);
});

//微信模块
Route::rule('callback/<version>/wx/callback','callback/<version>.PayCallback/payCallBack');
Route::rule('callback/<version>/wx/memberCallback','callback/<version>.PayCallback/memberCallBack');
Route::rule('callback/<version>/wx/refundCallback','callback/<version>.PayCallback/refundCallback');
Route::rule('callback/<version>/wx/ptRefundCallback','callback/<version>.PayCallback/ptRefundCallback');

//阿里巴巴服务模块
Route::rule('callback/<version>/alibaba/callback','callback/<version>.CosCallback/callback');

//物流(快递100)服务模块
Route::rule('callback/<version>/shipping/callback','callback/<version>.ShippingCallback/callback');