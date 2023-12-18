<?php

namespace app\lib\services;

use app\lib\exceptions\DbException;
use app\lib\exceptions\ServiceException;
use PHPMailer\PHPMailer\PHPMailer;
use think\exception\ValidateException;
use think\facade\View;
use think\Validate;

class Mail
{
    protected $host;
    protected $port;
    protected $userName;
    protected $password;
    protected $fromName;
    protected $debug = 0;                  //是否调试模式输出
    protected $charSet = 'UTF-8';          //邮件编码
    protected $config = [];                //自定义邮箱配置
    protected $templateName;               //邮件视图渲染配置键名
    protected $templateInfo;               //邮件视图渲染配置详情
    protected $isValidate = true;          //试图渲染的情况下是否开启字段验证; 默认开启

    public function __construct()
    {
        $config = $this->getConfig();
        if (empty($config)) {
            throw new ServiceException(['msg' => '邮件系统配置有误']);
        }
        $this->host = $config['host'];
        $this->port = $config['port'];
        $this->userName = $config['userName'];
        $this->password = $config['password'];
        $this->fromName = $config['fromName'] ?? ($config('system.projectName') ?? 'system');  //发件人姓名
        $this->setTemplate();
    }

    /**
     * @title 支持自定义邮箱配置
     * @param array $data
     * @return $this
     */
    public function setConfig(array $data = [])
    {
        $this->config = $data;
        return $this;
    }

    /**
     * @title 获取邮箱配置
     * @return array|mixed
     */
    public function getConfig()
    {
        $config = !empty($this->config) ? $this->config : config('system.mail');
        return $config;
    }

    /**
     * @title 视图渲染配置
     * @return array
     */
    protected function templateConfig()
    {
        return [
            'normal' => [
                //视图地址
                'path' => 'mail/msg',
                //视图模版渲染字段
                'field' => ['title', 'subject', 'content', 'logo', 'forUserName', 'fromUserName', 'needRemind', 'platformContactInfo'],
                //字段默认值
                'defaultValue' => [
                    'logo' => 'https://oss-cm.mlhcmk.com/cmk.png',
                    'title' => '邮件',
                    'forUserName' => '客户',
                    'fromUserName' => $this->fromName,
                    'needRemind' => true,
                    'platformContactInfo' => [],
                ],
                //字段验证规则
                'rule' => [
                    'title' => 'require',
                    'subject' => 'require',
                    'content' => 'require',
                ],
                //字段验证规则提示文案
                'ruleMessage' => [
                    'title.require' => '标题必填',
                    'subject.require' => '主题必填',
                    'content.require' => '内容必填',
                ]
            ],

            'test' => [
                //视图地址
                'path' => 'test/test',
                //视图模版渲染字段
                'field' => [],
                //字段默认值
                'defaultValue' => [],
                //字段验证规则
                'rule' => [],
                //字段验证规则提示文案
                'ruleMessage' => []
            ]
        ];
    }

    /**
     * @title 发送邮件
     * @param array $data 发送的内容及接受方信息
     * @return mixed
     */
    public function send_email(array $data)
    {
        try {
            //接收方email地址数组
            $addressList = $data['email_list'];

            //发件人名称
            $fromName = $data['from_name'] ?? null;

            //抄送email地址数组
            $ccAddressList = $data['cc_email_list'] ?? [];

            //密抄送email地址数组
            $bccAddressList = $data['bcc_email_list'] ?? [];

            //view_type 发送内容的渲染方式  1为纯文案 2为html模版渲染 默认2
            $viewType = $data['view_type'] ?? 2;

            $mail = new PHPMailer(true);

            //服务器配置
            $mail->CharSet = $this->charSet;   //设定邮件编码
            $mail->SMTPDebug = $this->debug; // 调试模式输出
            $mail->isSMTP();                 // 使用SMTP
            // SMTP服务器
            $mail->Host = $this->host;
            // 服务器端口 25 或者465 具体要看邮箱服务器支持
            $mail->Port = $this->port;
            $mail->SMTPAuth = true;  // 允许 SMTP 认证
            $mail->Username = $this->userName;  // SMTP 用户名  即邮箱的用户名
            $mail->Password = $this->password;  // SMTP 密码  部分邮箱是授权码(例如163邮箱)
            $mail->SMTPSecure = 'ssl';  // 允许 TLS 或者ssl协议

            //发件人
            $mail->setFrom($this->userName, ($fromName ?? $this->fromName));

            // 收件人
            //设置收件人邮箱地址 该方法有两个参数 第一个参数为收件人邮箱地址 第二参数为给该地址设置的昵称 不同的邮箱系统会自动进行处理变动 这里第二个参数的意义不大
            if (is_array($addressList)) {
                foreach ($addressList as $key => $address) {
                    if (!is_numeric($key)) {
                        $mail->addAddress($address, $key);
                    } else {
                        $mail->addAddress($address);
                    }
                }
            } else {
                $mail->addAddress($addressList);
            }

            //回复的时候回复给哪个邮箱 建议和发件人一致
            $mail->addReplyTo($this->userName, ($fromName ?? $this->fromName));

            //抄送
            if (!empty($ccAddressList ?? [])) {
                if (is_array($ccAddressList)) {
                    foreach ($ccAddressList as $ccAddress) {
                        $mail->addCC($ccAddress);
                    }
                } else {
                    $mail->addCC($ccAddressList);
                }
            }

            //密送
            if (!empty($bccAddressList ?? [])) {
                if (is_array($bccAddressList)) {
                    foreach ($bccAddressList as $bccAddress) {
                        $mail->addBCC($bccAddress);
                    }
                } else {
                    $mail->addBCC($bccAddressList);
                }
            }

            //$mail->addCC('cc@example.com');                    //抄送
            //$mail->addBCC('bcc@example.com');                    //密送

            //为该邮件添加附件 该方法有两个参数 第一个参数为附件存放的目录（相对目录、或绝对目录均可） 第二参数为在邮件附件中该附件的名称
            //attachment 必须为二维数组
            if (!empty($data['attachment'] ?? [])) {
                foreach ($data['attachment'] as $key => $value) {
                    $mail->addAttachment($value['path'], $value['title']);
                }
            }
            // $mail->addAttachment('./test.jpg','testFile.jpg');
            //同样该方法可以多次调用 上传多个附件
            // $mail->addAttachment('./Jlib-1.1.0.js','Jlib.js');


            // 是否以HTML文档格式发送  发送后客户端可直接显示对应HTML内容
            $mail->isHTML(true);
            //为支持多语言, 请保证发送的主题和内容相关的文案已加入全部语言包, 如果不加则默认显示中文
            $mail->Subject = lang($data['subject'] ?? '邮件');

            switch ($viewType){
                case 1:
                    $body = $data['content'] ?? '内容';
                    break;
                case 2:
                    /**采用模版视图渲染的方式获取发送的邮件html,支持的参数如下
                    title: 邮件主标题;
                    logo: 网站logo网址;
                    forUserName: 收件用户名称;
                    fromUserName: 邮件发送方简称;
                    content: 主体内容文案;
                    needRemind: 邮件末尾是否需要添加反诈提醒;
                    platformContactInfo: 平台联系方式;
                    其中contentList 为二维数组, 格式为[['boldTitle'=>lang('加粗子标题'),'content'=>lang('文案内容')]]; platformContactInfo为一维数组, 格式为[lang('平台官方电话')=>'123456']*/
//                    $sendContent['content'] = $data['content'];
//                    $sendContent['title'] = $data['title'] ?? ($data['subject'] ?? '邮件');
//                    $sendContent['logo'] = $data['logo'] ?? 'https://oss-cm.mlhcmk.com/cmk.png';
//                    $sendContent['forUserName'] = $data['forUserName'] ?? '客户';
//                    $sendContent['fromUserName'] = $data['fromUserName'] ?? $this->fromName;
//                    $sendContent['needRemind'] = $data['needRemind'] ?? true;
//                    $sendContent['platformContactInfo'] = $data['platformContactInfo'] ?? [];

                    //视图模版地址
//                    $emailViewTemplate = $data['view_path'] ?? 'mail/msg';

                    if (empty($this->templateInfo)) {
                        throw new ServiceException(['msg' => '暂无有效的视图内容']);
                    }
                    $templateInfo = $this->templateInfo;

                    if($this->isValidate && !empty($this->templateInfo['rule'] ?? [])){
                        $this->validateData($data,$templateInfo['rule'],$templateInfo['ruleMessage'] ?? []);
                    }
                    if (!empty($templateInfo['field'] ?? [])) {
                        foreach ($templateInfo['field'] as $key => $value) {
                            if (isset($templateInfo['defaultValue'][$value])) {
                                $sendContent[$value] = $data[$value] ?? (isset($templateInfo['defaultValue'][$value]) ? $templateInfo['defaultValue'][$value] : null);
                            } else {
                                $sendContent[$value] = $data[$value];
                            }
                        }
                    }

                    $emailViewTemplate = $templateInfo['path'];
                    $body = View::fetch($emailViewTemplate, ($sendContent ?? []));
                    break;
                default:
                    throw new ServiceException(['msg'=>'暂不支持的内容渲染方式']);
            }

            $mail->Body = $body;

            $msg = $mail->send();
            $result = ['res' => true, 'msg' => $msg];
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            $result = ['res' => false, 'msg' => $msg];
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            $result = ['res' => false, 'msg' => $msg];
        }

        return $result;
    }


    /**
     * @title  视图渲染的情况下是否开启字段验证
     * @return $this
     */
    protected function validate($status = true)
    {
        $this->isValidate = $status;
        return $this;
    }

    /**
     * @title 设置视图渲染配置
     * @param string $name
     * @return $this
     */
    public function setTemplate(string $name= 'normal')
    {
        $this->getTemplate($name);
        return $this;
    }

    /**
     * @title 获取视图渲染模版配置
     * @param string|null $name
     * @return array
     */
    public function getTemplate(string $name= null)
    {
        if (!empty($name)) {
            $this->templateName = $name;
        }else{
            $this->templateName = 'normal';
        }
        $templateInfo = $this->templateInfo = $this->templateConfig()[$this->templateName] ?? [];
        return $templateInfo;
    }


    /**
     * 验证数据
     * @access protected
     * @param array $data 数据
     * @param string|array $validate 验证器名或者验证规则数组
     * @param array $message 提示信息
     * @param bool $batch 是否批量验证
     * @return array|string|true
     * @throws ValidateException|DbException
     */
    protected function validateData(array $data, $validate = [], array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                list($validate, $scene) = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }
}