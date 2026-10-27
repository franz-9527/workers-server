<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;



final class PaidSmsPolicy
{

    public static function parameters(array $invite):array
    {
        $distanceText=((int)$invite['distance_m_snapshot']<1000)
            ? ((int)$invite['distance_m_snapshot'].'m')
            : (number_format((int)$invite['distance_m_snapshot']/1000,1,'.','').'km');
        $category=(string)(($invite['category_names_snapshot']??'')?:$invite['category_name_snapshot']?:($invite['workflow_profile']==='measurement'?'上门量尺':'施工'));
        // 阿里云审核模板仅接收类目、区县和近似距离，不能混入订单描述、手机号或链接。
        $categoryList=json_decode($category,true);
        if(is_array($categoryList))$category=implode('、',array_slice(array_filter(array_map('strval',$categoryList)),0,2));
        // 先移除网址和手机号，再仅留短类目与区县；不使用详细地址字段。
        $category=(string)preg_replace('~(?:https?://|www\\.)[^\\s,，]+|1[0-9]{10}~iu','',$category);
        $category=trim((string)preg_replace('/[^\\p{Han}\\p{L}\\p{N}、\\- ]/u','',$category));
        $district=(string)preg_replace('~(?:https?://|www\\.)[^\\s,，]+|1[0-9]{10}~iu','',(string)$invite['district']);
        $district=trim((string)preg_replace('/[^\\p{Han}\\p{L}\\p{N}\\- ]/u','',$district));
        $county=(string)preg_replace('/^.*(?:省|市)/u','',$district);
        if(preg_match('/^(.{1,14}?(?:区|县|旗))/u',$county,$roughArea))$district=$roughArea[1];
        elseif(preg_match('/^(.{1,14}?市)/u',$district,$roughArea))$district=$roughArea[1];
        else $district='';
        $params=[
            'category'=>mb_substr($category?:'施工服务',0,20),
            'district'=>mb_substr($district?:'您附近',0,20),
            'distance'=>mb_substr(str_replace(['km','m'],['公里','米'],$distanceText),0,12),
        ];

        return $params;
    }
    public static function receipt(array $invite,string $mobile,array $details):?array
    {
        $expected='nearby-invite:'.(string)$invite['public_no'];$hasBiz=trim((string)($invite['aliyun_biz_id']??''))!=='';
        foreach($details as $detail){
            if(!is_array($detail))continue;$out=(string)($detail['out_id']??'');
            if(!$hasBiz && $out!==$expected)continue;
            if($hasBiz && $out!=='' && $out!==$expected)continue;
            if((string)($detail['phone_num']??'')!=='' && (string)$detail['phone_num']!==$mobile)continue;
            return $detail;
        }
        return null;
    }
}
