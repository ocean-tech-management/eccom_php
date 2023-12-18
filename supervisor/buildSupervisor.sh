#!/bin/bash
#获取执行本脚本的参数 第一个为项目名称 第二个为php目录

#项目文件夹名称
PROJECTFOLDERNAME=$1

#项目GIT目录
PROJECTGITPATH=$2

#项目队列简称前缀
PROJECTNAME=$3

if [ "${PROJECTNAME}" == "" ] || [ "${PROJECTGITPATH}" == "" ] || [ "${PROJECTFOLDERNAME}" == "" ]; then
	echo '请传参 第一参数为项目文件夹名称,如project  第二个参数为项目php文件目录,如 php-project 第三个参数为队列前缀,如 projectQueue'
	exit;
fi

#后端代码项目根目录
PROJECTROOTPATH="/home/git/"${PROJECTFOLDERNAME}"/"${PROJECTGITPATH}

for file in $(ls ${PROJECTROOTPATH}/supervisor)
do
#        echo ${file}
#        echo ${file##*.}
        #只有conf后缀且 不包含项目名称的模版 且 不存在模版文件+项目名称前缀的文件才处理模版替换
#	echo "${file##*/}"
	result=$(echo "${file##*/}" | grep -v "${PROJECTNAME}")
#	echo "$result"
        if [ "${file##*.}" = "conf" ]  && [ ! -f "${PROJECTROOTPATH}/supervisor/${PROJECTNAME}_${file}" ] && [ "$result" != "" ]; then
        echo "我在复制${file}"
        file_text=$(< ${PROJECTROOTPATH}/supervisor/${file})
        echo $file_text
        eval "cat <<EOF
$file_text
EOF
"  > ${PROJECTROOTPATH}/supervisor/${PROJECTNAME}_${file}
        #软链接配置到supervisor
        ln -s ${PROJECTROOTPATH}/supervisor/${PROJECTNAME}_${file} /etc/supervisor/conf.d/${PROJECTNAME}_${file}
        else
                echo '非conf文件'
        fi
        done
