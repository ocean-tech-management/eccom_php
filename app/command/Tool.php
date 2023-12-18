<?php
declare (strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

class Tool extends Command
{
    protected $actionList = [
        'bakLog', 'bakData'
    ];

    protected function configure()
    {
        // 指令配置
        $this->setName('tool')
            ->setDescription('运维工具，第一个选项为事件，第二个为执行时间，无指定时间则默认当前时间上一个月')
            ->addArgument('action', Argument::OPTIONAL, "运维事件")
            ->addOption('date', null, Option::VALUE_OPTIONAL, '备份时间');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');
        if (!in_array($action, $this->actionList)) return $output->writeln('请求了不支持的事件');
        $date = $input->getOption('date');

        $server = new \app\controller\maintenance\Tool();
        switch ($action) {
            case 'bakLog':
                $server->log($date);
                break;
            case 'bakData':
                $server->data($date);
                break;
        }
        // 指令输出
        $output->writeln('计划执行成功');
    }
}
