<?php

/**
 * Created by PhpStorm.
 * User: aupl
 * Date: 2017/11/22 0021
 * Time: 10:58
 */
namespace app\wap\Service;

use think\Db;
use think\Model;
use think\Request;

class Activity extends Model{
    protected $activityModel;
    protected $length    = 20;//排行榜前20
    protected $startTime = '2017-11-01';//排行榜前20

    public function initialize(){
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->activityModel = new \app\wap\model\Activity();
    }

    /**
     * 获取榜单用户id
     * @params $flash    type:bool 是否更新缓存 默认不更新
     * @params $activity type:array 单个活动数据
     * @return Array
     * */
    public function getActivityUserId($flash = false, $activity = array()){
        $activity      = $activity ?: $this->activityModel->getActivityByEndTime('ID,BeginTime,EndTime,Type,WalletCounts,PlayCounts');//当天之前的活动
        $play          = $activity['Type'] == 1 && $activity['PlayCounts']   ? $this->activityModel->getPlayCountAll($activity['PlayCounts'], $flash, $activity['ID'] , isset($activity['BeginTime']) ?$activity['BeginTime'] : $this->startTime, $activity['EndTime']) : array();
        $wallet        = $activity['Type'] == 1 && $activity['WalletCounts'] ? $this->activityModel->getWalletCountAll($activity['WalletCounts'], $flash, $activity['ID']) : array();
        $data['play']     = $play   ? array_column($play,   'UserID') : array();
        $data['wallet']   = $wallet ? array_column($wallet, 'UserID') : array();
        logs_write($data, 'Service/Activity', 'getActivityUserId', ['flash' => $flash, 'activity' => $activity]);
        return $data;
    }

    /**
     * 获取榜单用户数据
     * @params $flash type:bool 是否更新缓存 默认不更新
     * @params $activity type:array 单个活动数据
     * @return Array
     * */
    public function getActivityDataAll($activity = array()){
        $activity        = $activity ?: $this->activityModel->getActivityByEndTime('ID,BeginTime,EndTime,Type,WalletCounts,PlayCounts');//当天之前的活动
        $data['play']    = $activity['Type'] == 1 && $activity['PlayCounts']   ? $this->activityModel->getPlayCountAll($activity['PlayCounts'], false, $activity['ID'] ?: '', isset($activity['BeginTime']) ?$activity['BeginTime']: $this->startTime, $activity['EndTime'] ?: '') : array();
        $data['wallet']  = $activity['Type'] == 1 && $activity['WalletCounts'] ? $this->activityModel->getWalletCountAll($activity['WalletCounts'], false, $activity['ID'] ?: '') : array();
        $data['play']    = $data['play']   ?: array();
        $data['wallet']  = $data['wallet'] ?: array();
        logs_write($data, 'Service/Activity', 'getActivityDataAll', ['activity' => $activity]);
        return $data;
    }

    /**
     * 计算抢红包机会
     * 一个人最多有两次机会（同一个人，同时在两个榜单上的情况）
     * @param $userId type:int 用户ID
     * @return int
     */
    public function getUserLotteryCount($userId){
        $lottery_count = 0;
        if(!$userId){
            return $lottery_count;
        }

        $data = $this->getActivityUserId();

        if(in_array($userId, $data['play'])){
            $lottery_count += 1;
        }

        if(in_array($userId, $data['wallet'])){
            $lottery_count += 1;
        }

        return $lottery_count;
    }

    /**
     * 获取用户充值记录
     * @params $field 查询字段
     * @params $username 指定用户名 如果为空,则为指定时间区间内的全部用户
     * @params $beginTime 指定时间的开始时间
     * @params $endTime 指定时间的结束时间
     * @return Array
     * */
    public function getUserRechargeRecord($field = '*', $username = '', $beginTime = '2017-12-01', $endTime = '2017-12-31 23:59:59'){
        $where = [
            'InSuccess' => 1,
            'AddTime'   => ['between', [$beginTime, $endTime]]
        ];
        if($username){
            $where['UserName'] = $username;
        }

        return $this->activityModel->getUserRechargeRecord($field, $where, 'AddTime ASC');
    }

    /**
     * 隐藏中间字符串
     * @params $string 要处理的字符串
     * @params $replaceString 要替换的字符串
     * @return string
     * */
    public function hidString($string, $replaceString = '****'){
        $start = 2;
        $len  = mb_strlen($string, 'utf-8');
        if(preg_match('/^1[34578]\d{9}$/', $string)){
            $string = substr_replace($string, $replaceString, $start + 1, 4);//隐藏电话号码中间四位
        }else{
            if($len == strlen($string)){//无汉字字符处理（如果字符串长度大于4，则保留前两位和最后两位，其它都隐藏，反之，保留前两位字符）
                $string = substr_replace($string, $replaceString, $start, $len > 4 ? $len - $start - 2 : 2);
            }else{//有汉字字符处理（如果字符串长度大于2，保留前一位和最后一位，其它都隐藏，反之，保留前一位）
                $replace  = mb_substr($string, $start - 1, $len > 2 ? $len - 2 : 1, 'utf-8');
                $string = str_replace($replace, $replaceString, $string);
            }
        }

        return $string;
    }

    /**
     * 隐藏数组中指定键名的中间字符
     * @params $data  要处理的数组
     * @params $field 要替换的字符串的键名
     * @return Array
     * */
    public function hidArrayString($data, $field){
        if(!$data || !$field){
            return $data;
        }elseif (is_string($data)){
            return self::hidString($data);
        }elseif (is_object($data)){
            $data = self::object_to_array($data);
        }

        foreach ($data as $key => &$val){
            (isset($val[$field]) && $val[$field]) && $val[$field] = self::hidString($val[$field]);
        }

        return $data;
    }

    /**
     * 对象转为数组
     * @params 对象
     * @return Array
     * */
    public function object_to_array($object){
        $data = is_object($object) ? get_object_vars($object) : $object;
        foreach($data as $key => &$value){
            (is_array($value) || is_object($value)) && $value = self::object_to_array($value);
        }
        return $data;
    }

    public function getRandMoney($min, $max){
        if(!$min || !$max){
            return 0;
        }
        return mt_rand($min, $max) / 100;
    }

    /**
     * 通过参数作为条件获取适合的活动,并返回一条用户抽奖数据信息
     * @params $userId 用户ID
     * @params $minRechargeMoney 满足某个活动的最低充值
     * @params $maxRechargeMoney 满足某个活动的最大充值
     * @params $beginTime        活动开始时间
     * @params $endTime          活动结束时间
     * @return Array
     * */
    public function getUserActivityLotteryData($userId, $minRechargeMoney = 1, $maxRechargeMoney = '', $beginTime = '2017-12-01', $endTime = '2017-12-31 23:59:59'){
        $where = ['Status' => 1, 'Type' => 2, 'BeginTime' => ['egt', $beginTime], 'EndTime' => ['elt', $endTime]];
        $where['RechargeMoney'] = $maxRechargeMoney ? [['egt', $minRechargeMoney], ['lt', $maxRechargeMoney]] : ['egt', $minRechargeMoney];

        $time     = date('Y-m-d H:i:s');
        $data     = array();
        $activity = Db::name('Activity')->where($where)->order('ID DESC')->find();
        if($activity){
            $data = ['UserID' => $userId, 'Money' => $this->getRandMoney($activity['BonusMin'], $activity['BonusMax']), 'CreateTime' => $time, 'ActivityID' => $activity['ID']];
        }
        logs_write(['data' => $data, 'activity' => $activity], 'Service/Activity', 'getUserActivityLotteryData', ['userId' => $userId, 'min' => $minRechargeMoney, 'max' => $maxRechargeMoney]);

        return $data;
    }

    /**
     * 12月期间用户充值获取抽奖机会
     * @params $min 第二级单月总额最低满19元
     * @params $max 第三级单月总额最低满199元
     * @return bool
     * */
    public function rechargeChange($min = 19, $max = 199, $beginTime = '2017-12-01', $endTime = '2017-12-31 23:59:59'){
        $data = $this->getUserRechargeRecord('PayID,Users_ids as UserId,UserName,AddTime,PayMoney','', $beginTime, $endTime);
        if($data){
            $records = array();
            foreach($data as $key => &$val){
                if(!array_key_exists($val['UserId'], $records)){
                    $records[$val['UserId']] = $val['PayMoney'];
                }else{
                    $records[$val['UserId']] += $val['PayMoney'];
                }
            }

            $lData = array();
            foreach($records as $userId => $totalMoney){
                if($totalMoney >= 1){
                    $lData[] = $this->getUserActivityLotteryData($userId, 1, $min, $beginTime, $endTime);
                }
                if ($totalMoney >= $min){
                    $lData[] = $this->getUserActivityLotteryData($userId, $min, $max, $beginTime, $endTime);
                }
                if($totalMoney >= $max){
                    $lData[] = $this->getUserActivityLotteryData($userId, $max, '', $beginTime, $endTime);
                }
            }
            $lData = array_filter($lData);//过滤空数组
            $res = Db::name('lottery_records')->insertAll($lData);
            if(!$res){
                return false;
            }
        }

        return true;
    }
}