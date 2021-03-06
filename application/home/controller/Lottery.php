<?php
namespace app\home\controller;
use think\Db;
use app\home\model\Betting;
use app\home\model\SystemConfig;
use app\home\model\ApiConfig;
use app\home\model\ApiBetting;
use app\home\model\ApiGame;
use think\Controller;

class Lottery extends Common
{
  public $lottery_config;
  public $post_data;

  public function _initialize(){
    $this->post_data = input('post.');
    if(empty($this->post_data) || !isset($this->post_data['type'])){
      return;
    }
    $lottery_config = Db::table('lottery_config')->field('basic_config,time_config,name,max_bet')->where(['type'=>$this->post_data['type'],'switch'=>1])->find();
    if(!empty($lottery_config)){
      $lottery_config['basic_config'] = json_decode($lottery_config['basic_config'],true);
      $lottery_config['time_config'] = (empty($lottery_config['time_config']) ? '' : json_decode($lottery_config['time_config'],true));
      $lottery_config['max_bet'] = $lottery_config['max_bet'];
      $this->lottery_config = $lottery_config;
    }
    if(method_exists($this,'__initialize')){
      $this ->__initialize();
    }
  }

  public function getInfoAction(){
    $return_data = [
      'code' => 0,
      'msg' => '错误...',
      'data' => []
    ];
    if(isset($this->post_data['type'])){
      $type = $this->post_data['type'];
    }
    if(isset($type) && is_numeric($type)){
      if(empty($this->lottery_config)){
        $return_data['msg'] = '此彩种没有配置数据或者已经关闭...';
      }else{
        $data = $this->lottery_config['basic_config'];
        // print_r($data);die;
        if($this->post_data['type'] != 21) {
          foreach ($data as $key => $value) {
            if($value['switch']){
              unset($data[$key]['switch']);
              foreach ($value['items'] as $key1 => $value1) {
                if($value1['switch'] == 0){
                  unset($data[$key]['items'][$key1]);
                }else{
                  unset($data[$key]['items'][$key1]['switch']);
                }
              }
            }else{
              unset($data[$key]);
            }
          }
        }
        $return_data['msg'] = 'SUCCESS';
        $return_data['code'] = 1;
        $return_data['data']['basic'] = $data;
        //$return_data['data']['betting'] = bettingFormat(Db::table('betting')->field('user_id,content,money,type')->where(['type'=>$type])->order('id DESC')->limit(5)->select());
        $return_data['data']['name'] = $this->lottery_config['name'];
        $now_data = $this->getHistory();
        $return_data['data']['history'] = $now_data['history'];
        $return_data['data']['number'] = $now_data['time']; //秒
        $return_data['data']['expect'] = $now_data['expect'];
        $return_data['data']['desc'] = $now_data['desc'];
        $return_data['data']['max_expect'] = $now_data['max_expect'];
        $chat_data = Db::table('system_config')->field('value')->where(['name'=>'hm_zh'])->find();
        $return_data['data']['set'] = json_decode(($chat_data ? $chat_data['value'] : []),true);
      }
    }
    return $return_data;
  }

  public function getHistory($type = ''){
    $return_data = [
      'history' => '',
      'expect' => 0,
      'time' => 0
    ];
    $config = '';
    if(empty($type)){
      if(isset($this->post_data['type'])){
        $type = $this->post_data['type'];
      }else{
        return $return_data;
      }
    }else{
      $config = [
        'type' => $type,
        'data' => json_decode(Db::table('lottery_config')->field('time_config')->where(['type'=>$type])->find()['time_config'],true)
      ];
    }
    if(isset($type) && is_numeric($type)){
      $return_data = $this->calculationData($config) + $return_data;
      $return_data['history'] = Db::table('lottery_code')->field('expect,content,create_time')->where(['type'=>$type])->order('expect DESC')->find();
      if($return_data['expect'] - $return_data['history']['expect'] > 1){
        $return_data['history']['expect'] = $return_data['expect'] - 1;
        $return_data['history']['content'] = 0;
      }
    }

    // 2018 10 19 添加六合彩开奖时间优化----------------------------------
    if($type == 21){
      $bit = SystemConfig::get(52);
      if(!empty($bit->value)){
        //所有预设时间
        $all_time = json_decode($bit->value,true);
        $all_time1 = json_decode($bit->value,true);
        //今天时间戳
        $now_time = strtotime(date('Y-m-d 00:00:00',time()));
        //计算后的倒计时
        $desc_number = 0;
        //循环预设时间配置
        foreach ($all_time as $k => $vo) {
          //如果时间大于等于当前时间戳
          if($vo >= $now_time){
            $res = ((int)$vo+(21*60*60)+(30*60)-$return_data['desc'] ) - time();
            if($res > (4*86400)){
              //如果预测时间大于了四天 则开奖时间失效
              break;
            }elseif( $res > 0 ){
              //如果开奖时间大于当前时间
              $desc_number = $res;
              break;
            }else{
              //如果当天开奖时间小于或等于当前时间 则查询数据库最新号码是否是今天开出来的
              if( Db::table('lottery_code')->where('type',21)->where('create_time','>',$now_time)->find() ){
                continue;
              }else{
                break;
              }
            }
          }else{
            array_splice($all_time1, $k, 1);
          }
        }//循环结束
        
        //如果倒计时大于0
        if($desc_number > 0){
          $return_data['time'] = $desc_number;
        }
        //保存新的数据格式
        if(count($all_time1) != count($all_time)){
          $bit->value = json_encode($all_time1);
          $bit->save();
        }

      }
    }
    // 六合彩开奖时间结束------------------------------------------------------

    return $return_data;
  }

  /**
   * [calculationData description]
   * @param  [data] array 彩种时间配置
   * @return [type]       彩种编号
   */
  public function calculationData($config = []){
    if((empty($this->lottery_config['time_config']) && empty($config)) || (!empty($config) && empty($config['data']))){
      $is_data = [ 'expect'=>null,'time'=>null,'max_expect'=>0 ];
      if(method_exists($this,'nowData')){
        $is_data1 = $this->nowData();
        if($is_data1){
          $is_data = $is_data1;
        }
      }
      return $is_data;
    }
    if(empty($config)){
      $data = $this->lottery_config['time_config'];
      $is_type = $this->post_data['type'];
    }else{
      $data = $config['data'];
      $is_type = $config['type'];
    }
    $is_ymd = date("Y-m-d");
    $is_start_time = strtotime($data['start_time']);
    $is_cha_time = $is_start_time - strtotime(date('His'));
    if($is_cha_time > 0){
      // 这里是存在期号跨天处理
      $day_max_expect = ceil((strtotime(date('23:59:59')) - $is_start_time) / 60 / $data['cha']);
      if($day_max_expect < $data['num']){
        $is_ymd = date("Y-m-d",strtotime("-1 day"));
      }else{
        return [ 'expect' => 0,'time' => $is_cha_time,'max_expect'=>0,'desc'=>$data['desc'] ];
      }
    }
    //重庆时时彩的期数会在3：10~7：30期间跳一段时间
    $cq_start_time = strtotime( date('Y-m-d 03:10:00',time()) );
    $cq_node_time = 0;
    if($is_type == 2 && time() > $cq_start_time){
      //中间跳跃时间段4个小时
      $cq_jump_time = 4*60*60+20*60;
      $cq_node_time = $cq_start_time + $cq_jump_time - time();
      if($cq_node_time <= 0){
        $cha_time = time() - $cq_jump_time - strtotime($is_ymd . ' ' . $data['start_time']) + 20*60;
        $cq_node_time = 0;
      }else{
        $cha_time = $cq_start_time - strtotime($is_ymd . ' ' . $data['start_time']) + 20*60;
      }
    }else{
      $cha_time = time() - strtotime($is_ymd . ' ' . $data['start_time']);
    }
    //重庆特殊处理完----
    $cha_date = $cha_time / 60;
    $get_num = ceil($cha_date / $data['cha']);
    $is_time = ($get_num * ($data['cha'] * 60) - $cha_time) + $cq_node_time;
    // $cha_time = strtotime(date('YmdHis')) - strtotime($is_ymd . ' ' . $data['start_time']);
    // $cha_date = $cha_time / 60;
    // $get_num = ceil($cha_date / $data['cha']);
    // switch ($is_type) {
    //   case 2: // 重庆时时彩总共120期,23期之前是 5 分钟一期，之后是 10 分钟一期，96期之后又是5分钟一期
    //     $get_num = ceil($cha_date / 5);
    //     $cha_jian = ($get_num > 23 ? 23 : $get_num) * 5 * 60; // 下期时间
    //     if($get_num > 23){
    //       $cha_24 = strtotime('09:59') - strtotime('01:55');
    //       // 第24期是 01:55 ~ 09:59
    //       $cha_date = $cha_date - 5 * 23 - ($cha_24 / 60);
    //       if($cha_date <= 0){
    //         $get_num = 24;
    //         $cha_jian += $cha_24;
    //       }else{
    //         $get_num = 24 + ceil($cha_date / 10);
    //         $cha_jian += $cha_24 + (($get_num > 96 ? (96 - 24) : ceil($cha_date / 10)) * 10 * 60);
    //       }
    //     }
    //     if($get_num > 96){
    //       $cha_date = $cha_date - 10 * (96 - 24);
    //       $get_num = 96 + ceil($cha_date / 5);
    //       $cha_jian += ceil($cha_date / 5) * 5 * 60;
    //     }
    //     $is_time = $cha_jian - $cha_time;
    //     break;
    //   default:
    //     $is_time = $get_num * ($data['cha'] * 60) - $cha_time;
    //     break;
    // }
    if($get_num > $data['num']){
        return [ 'expect' => 0,'time' => $is_cha_time,'max_expect'=>0,'desc'=>$data['desc'] ];
    }
    if($is_type == 3 || $is_type == 34){  //北京pk10  //北京快三
      $li = Db::table('lottery_code')->field('expect,create_time')->where('type','=',$is_type)->where('create_time','<',strtotime(date('Y-m-d 00:00:00')))->order('expect','DESC')->find();
      //查询该彩种 开始的时间戳
      $z_start = strtotime(date(('Y-m-d '.$data['start_time']),$li['create_time']));
      //获取查询到的旗号时间  获取结束的时间戳
      $z_end = $z_start + ($data['num'] * $data['cha'] * 60);
      // 如果结束时间戳大于当前查询的期数时间 则进行补期
      if(($z_end - $li['create_time']) > 0 && ($z_end - $li['create_time'])/($data['cha'] * 60) >= 0.8 ){
        $li['expect'] = $li['expect'] + round((($z_end - $li['create_time']) / ($data['cha'] * 60)),0 );
      }

      $get_num = $li['expect'] + $get_num;
    }else{
      $get_num = date('Ymd',strtotime($is_ymd)) . substr(('000' . $get_num),-strlen($data['num']));
    }
    $chat_data = [ 'expect' => $get_num,'time' => ($is_time - $data['desc']),'max_expect'=>$data['num'],'desc'=>$data['desc'] ];
    // 这里是当前期是数据库最后一期 加上一期 的处理
    $data_all = [
      21, // 香港六合彩
      // 3,  // 北京pk10  --
      19, // 福彩3d
      22, // 排列三
      20, // 广西快乐十分
      // 34  // 北京快3  --
    ];
    if(in_array($is_type,$data_all)){
      $chat_data['max_expect'] = 0;
      $last_expect = Db::table('lottery_code')->field('expect,create_time')->where(['type'=>$is_type])->order('create_time DESC')->find();
      //print_r($last_expect);
      if(empty($last_expect)){
        $chat_data['expect'] = 0;
      }else{
        // 这里要判断上次开奖入库时间，等到下期开出来才能购买
        // if((time() - $last_expect['create_time']) > ($data['cha'] * 60 * 1) && in_array($is_type,[34,3])){
        //   $chat_data['expect'] = 0;
        // }else{
        //   $chat_data['expect'] = $last_expect['expect'] + 1;
        // }
        $chat_data['expect'] = $last_expect['expect'] + 1;
      }
    }
    return $chat_data;
  }

  public function bettingAction($m = []){
    $return_data = [
      'code' => 0,
      'msg' => '投注错误...',
      'data' => $this->getInfo()['data']
    ];
    $user = $this->checkLogin();
    if(!$user['code']){
      $return_data['msg'] = $user['msg'];
      return $return_data;
    }else if($user['data']['status'] == 1){
      $return_data['msg'] = '帐号已被冻结';
      return $return_data;
    }
    //  print_r($this->lottery_config);
    //  die();
    $user = $user['data'];
    $data = $this->post_data;
    if(isset($data['betting']) && isset($data['basic']) && isset($data['type']) && is_numeric($data['type'])){
      try {
        $data['betting'] = json_decode($data['betting'],true);
        $basic = json_decode($data['basic'],true);
        $is_expect = $this->calculationData()['expect'];
        if($is_expect == 0){
          throw new \Exception('expect error');
        } else {
          foreach ($basic['zh']['expect_s'] as $value) {
            if($value < $is_expect){
              throw new \Exception('有投注期号过期了,请您重新发起投注');
            }
          }
        }
      }catch (\Exception $e) {
        $return_data['msg'] = $e->getMessage();
        return $return_data;
      }
      $zhushu = 0;
      foreach ($data['betting'] as $key1 => &$value1) {
        foreach ($value1 as $key2 => &$value2 ) {
          if(in_array($key1,$m) || in_array($key2,$m)){
            $is_check = $this->verification($key1,$key2,$value2['code']);
            if(empty($value2['code']) || $is_check < 1 || $is_check != $value2['num']){
              return $return_data;
            }
          }
          $value2['win'] = 0;
          $zhushu += $value2['num'];
        }
      }

      // 2018 10 9 添加查询当前期最大总投注并限制 -----------------------------------------------------
      if($this->lottery_config['max_bet'] > 0){
        $bit = $this->chackMaxBet($user['id'],$data['type'],$basic['zh']['expect_s'][0]);
        if( ($bit+($zhushu * $basic['money'])) > $this->lottery_config['max_bet']){
          $return_data['msg'] = '本期总投已上限,已投注'.$bit.'元';
          return $return_data;
        }
      }
      //最大限制完 --------------------------------------------------------------------------------

      // 2018 10 16 添加查询当前期各个类型最大总投注----------------------------------------------
      $bit = $this->checkEveryBet( $user['id'],$data['type'],$basic['zh']['expect_s'][0],$data['betting'] );
      if($bit['code'] < 0){
        $return_data['msg'] = $bit['msg'];
        return $return_data;
      }

      // 2019 1 12 添加查询当前期各个类型限制投注投注--------------------------------------------------
      // $bit = $this->checkEveryNum( $user['id'],$data['type'],$basic['zh']['expect_s'][0],$data['betting'] );
      //   if($bit['code'] < 0){
      //     $return_data['msg'] = $bit['msg'];
      //     return $return_data;
      //   }

      //2019 1 14 修改的下注数
      $bit = $this->checkEveryplus( $user['id'],$data['type'],$basic['zh']['expect_s'][0],$data['betting'] );
        if($bit['code'] < 0){
          $return_data['msg'] = $bit['msg'];
          return $return_data;
        }
  


      //最大限制完------------------------------------------------------------------------------

      // 2018 10 19 添加如果期号和类型 已在数据库里 则无法投注-------------------------------------
      if(Db::table('lottery_code')->where('type',$data['type'])->where('expect',$basic['zh']['expect_s'][0])->find() ){
        $return_data['msg'] = '数据错误,无法投注';
        return $return_data;
      }
      //期数判断完-------------------------------------------------------------------------------

      $sum_money =  $zhushu * $basic['money'] * count($basic['zh']['expect_s']);
      if($basic['hm']['buy_money'] > 0){ // 合买
          if($user['type'] == 1){
            $return_data['msg'] = '试玩会员不能发起合买';
            return $return_data;
          }
          if($user['money'] < ($basic['hm']['bd'] + $basic['hm']['buy_money'])){
            $return_data['msg'] = '您的余额不足';
            return $return_data;
          }
          $chat_data = Db::table('system_config')->field('value')->where(['name'=>'hm_zh'])->find();
          $chat_data = json_decode(($chat_data ? $chat_data['value'] : []),true);
          if($sum_money < $chat_data['total']){
            $return_data['msg'] = '合买总金额至少' . $chat_data['total'];
            return $return_data;
          }
          if($chat_data['zg'] > 0 && floor($basic['hm']['buy_money'] / ($sum_money / 100)) < $chat_data['zg']){
            $return_data['msg'] = '自购至少认购' . ceil($sum_money / 100 * $chat_data['zg']);
            return $return_data;
          }
          if($chat_data['bd'] > 0 && floor($basic['hm']['bd'] / (($sum_money - $basic['hm']['buy_money']) / 100))  < $chat_data['bd']){
            $return_data['msg'] = '保底至少保底' . ceil(($sum_money - $basic['hm']['buy_money']) / 100 * $chat_data['bd']);
            return $return_data;
          }
          if($chat_data['tc_num'] > 0 && $basic['hm']['tc'] > $chat_data['tc_num']){
            $return_data['msg'] = '提成不能大于奖金的' . $chat_data['tc_num'] . '%';
            return $return_data;
          }
          $is_money = 0;
          $out_money = $basic['hm']['buy_money'];
      }else{
        if($user['money'] < $sum_money){
          $return_data['msg'] = '您的余额不足';
          return $return_data;
        }
        $is_money = $sum_money;
        $out_money = $sum_money;
      }
      sort($basic['zh']['expect_s']);
      $user_data = [];
      $model = new Betting;
      $model->startTrans();
      try {
        // 投注表添加数据
        $model->save([
          'user_id' => $user['id'],
          'content' => $data['betting'],
          'money' => $is_money,
          'expect' => (count($basic['zh']['expect_s']) > 1 ? 0 : $basic['zh']['expect_s'][0]),
          'type' => $data['type'],
          // 这里是香港六合彩 A 盘 B 盘
          'other' => ($data['type'] == 21 ? $basic['disc']: null),
          'state' => (count($basic['zh']['expect_s']) > 1 ? 3 : 0),
          'create_time' => time()
        ]);
        if($basic['hm']['buy_money'] > 0){
          switch ($basic['hm']['open']) {
            case '完全公开':
              $is_open = 0;
              break;
            case '仅跟单人可见':
              $is_open = 1;
              break;
            case '截止后公开':
              $is_open = 2;
              break;
            case '完全保密':
              $is_open = 3;
              break;
            default:
              $is_open = 0;
              break;
          }
          $model->he()->save([
            'all' => $sum_money,
            'buy' => $basic['hm']['buy_money'],
            'open' => $is_open,
            'bd' => $basic['hm']['bd'],
            'tc' => $basic['hm']['tc'],
            'explain' => (empty($basic['hm']['explain']) ? null : $basic['hm']['explain'])
          ]);
          $model->gen()->save([
            'user_id' => $user['id'],
            'money' => $basic['hm']['buy_money'],
            'main' => 1
          ]);
          $user_data [] = [ 'uid'=>$user['id'],'money'=>$basic['hm']['bd'],'type'=>12,'explain'=>$this->lottery_config['name'] . '合买保底冻结' ];
        }
        if(count($basic['zh']['expect_s']) > 1){
          $add_data = [];
          foreach ($basic['zh']['expect_s'] as $value) {
            $add_data[] = [
              'expect' => $value,
              'stop' => ($basic['zh']['stop'] ? 1 : 0)
            ];
          }
          $model->zhui()->saveAll($add_data);
        }
        $user_data[] = [ 'uid'=>$user['id'],'money'=>$out_money,'type'=>0,'explain'=>$this->lottery_config['name'] . '下注' ];
        $is_action = moneyAction($user_data);
        if($is_action['code'] == 0){
          throw new \Exception($is_action['msg']);
        }
        $model->commit();
        $return_data['code'] = 1;
        $return_data['msg'] = '已成功投注';
        $this->infoAll($user['id']);
      } catch (\Exception $e) {
        $return_data['msg'] = $e->getMessage();
        $model->rollback();
      }
    }
    return $return_data;
  }

  /**
   * 获得彩种列表及各个彩种的倒计时
   */
  public function getLotteryList(){
    $is_data = [ 'name'=>'lottery_show' ];
    if($this->post_data['type']){
      $is_data['name'] = 'lottery_trade';
    }
    $lottery_show = Db::table('system_config')->field('value')->where($is_data)->find();
    if(empty($lottery_show)){
      $lottery_show = [];
    }else{
      $lottery_show = json_decode($lottery_show['value'],true);
    }
    $lottery_list = [];
    $lottery_config = Db::table('lottery_config')->field('type,time_config,name,note')->where(['switch'=>1])->select() ?? [];
    foreach ($lottery_config as $value) {
      $lottery_list[$value['type']] = [
        'name' => $value['name'],
        'time' => $value['time_config'],
        'note' => $value['note']
      ];
    }
    $lottery_show1 = [];
    foreach ($lottery_show as $key => $value1) {
      if(empty($value1['data']) || count($value1['data']) == 0){
        continue;
      }
      $is_chat = [];
      foreach ($value1['data'] as $key1 => $value2) {
        if(isset($lottery_list[$value2])){
          $is_chat[] = $value2;
        }
      }
      if(count($is_chat) > 0){
        $lottery_show1[] = [ 'name'=>$value1['name'],'data'=>$is_chat ];
      }
    }
    $lottery_show = $lottery_show1;
    $return_data = [];
    foreach ($lottery_show as $key => &$value1) {
      $chat_data = [];
      if(!isset($this->post_data['index']) || $this->post_data['index'] == $key){
        foreach ($value1['data'] as $value2) {
          $is_chat = [
            'name' => $lottery_list[$value2]['name'],
            'type' => $value2,
            'note' => $lottery_list[$value2]['note']
          ];
          if($this->post_data['type']){
            $is_chat['down'] = (in_array($value2,[24,25,26,27]) ? Lottery28::lottery($value2) : $this->calculationData([ 'data'=>json_decode($lottery_list[$value2]['time'],true),'type'=>$value2 ]));
            switch ($value2) {
              case 26:
                $is_type = 2;
                break;
              case 27:
                $is_type = 12;
                break;
              default:
                $is_type = $value2;
                break;
            }
            // $is_chat['code'] = Db::table('lottery_code')->field('expect,content')->where('type',$is_type)->order('expect DESC')->find();
            // if(empty($is_chat['code'])){
            //   $is_chat['code'] = '没有开奖号码';
            // }else{
            //   if($value2 == 26 || $value2 == 27){
            //     $is_chat['code']['content'] = array_slice(explode(',',$is_chat['code']['content']),0,3);
            //     $is_chat['code']['content'] = join(',',$is_chat['code']['content']);
            //   }
            //   $is_chat['code'] = $is_chat['code']['expect'] . '期 ' . $is_chat['code']['content'];
            // }
          }
          $chat_data[] = $is_chat;
        }
      }
      $value1['data'] = $chat_data;
    }
    return $lottery_show;
  }


  /**
   * 每一个玩法的最大限制
   * @param int   $userid   用户id
   * @param int   $type     投注类型
   * @param int   $expect   投注期号
   * @param array $int      投注内容
   * @return array 
   */
  public function checkEveryBet($userid,$type,$expect,$bet){
    $return_data = [
      'code' => 1,
      'msg'  => '通过'
    ];
    $basic = json_decode($this->post_data['basic'],true);
    //已投注的内容
    
    foreach($bet as $key => $item){
      if($this->lottery_config['basic_config'][$key]['bet'] <= 0 || $this->lottery_config['basic_config'][$key]['bet'] == ''){continue;}

      $list_money_zg = 0;
      //当前自购------
      if($basic['hm']['buy_money'] > 0 ){
        //合买情况下
        $list_money_zg += $this->betNumMoney($this->post_data['betting'],$key,$basic['hm']['buy_money']);
      }else{
        $list_money_zg += $this->betNumMoney($this->post_data['betting'],$key,($basic['money']*$this->betNum($bet)));
      }
      //当前自购完-------
      if($list_money_zg > $this->lottery_config['basic_config'][$key]['bet']){
        $return_data['code'] = -1;
        $return_data['msg'] = $this->lottery_config['basic_config'][$key]['name'].'每期投注上限'.$this->lottery_config['basic_config'][$key]['bet'];
        return $return_data;
      }
      $list_money = 0;
      
      //

      // $where = [
      //   ['user_id','=',$userid],
      //   ['type','=',$type],
      //   ['state','in',[0,3]],
      //   ['content','like',('%"'.$key.'"%')]
      // ];

      //正常情况------------
      $rs = Db::table('betting')
          ->field('content,money,id')
          ->where('user_id','=',$userid)
          ->where('type','=',$type)
          ->where('expect','=',$expect)
          ->where('state','in',[0,3])
          ->where('content','like',('%"'.$key.'"%'))
          ->select();
      
      if(!empty($rs)){
        //$list_money += $this->betNumMoney($rs,$key);
        foreach($rs as $vo){
          
          if($vo['money'] == 0){
            //金额为0 表示合买
            $he_m = Db::table('betting_gen')->where('betting_id',$vo['id'])->where('user_id',$userid)->sum('money');
            $list_money += $this->betNumMoney($vo['content'],$key,$he_m);
          }else{
            //否则为正常购买
            $list_money += $this->betNumMoney($vo['content'],$key,$vo['money']);
          }
        }
      }
      //正常情况完-----------

      //追号的情况下 -----------
      //先查询所有 有投注此内容的追号 id
      $zhui = Db::table('betting')
            ->field('content,expect,money,id')
            ->where('user_id','=',$userid)
            ->where('type','=',$type)
            ->where('expect','=',0)
            ->where('state','in',[0,3])
            ->where('content','like',('%"'.$key.'"%'))
            ->select();
      //判断是否有此种玩法是否有追号
      if(!empty($zhui)){
        //如果有追号,循环查询是否有这一id且为本期的追号
        foreach($zhui as $k => $vo){
          if(Db::table('betting_zhui')->where('betting_id',$vo['id'])->where('expect',$expect)->find()){
            
            if($vo['money'] > 0){
              //如果有本期追号且金额大于0表示正常追号
              $list_money += $this->betNumMoney($vo['content'],$key,$vo['money']);
            }else{
              //否则为又追号又合买 查询合买自购金额
              $ze_m = Db::table('betting_gen')->where('betting_id',$vo['id'])->where('expect',$expect)->sum('money');
              $list_money += $this->betNumMoney($vo['content'],$key,$ze_m);
            }
          }
          // else{
          //   // continue;
          // }
        }
      }
      //追号情况完-----------

      //如果投注内容大于了 设定值则返回
      if(($list_money+$list_money_zg) > $this->lottery_config['basic_config'][$key]['bet'] ){
        $return_data['code'] = -1;
        $return_data['msg'] = '玩法('.$this->lottery_config['basic_config'][$key]['name'].'),每期上限'.$this->lottery_config['basic_config'][$key]['bet'].',已投注'.$list_money.'.';
        return $return_data;
      }
    }
    return $return_data;
    // print_r($rs);
    //print_r($this->lottery_config['basic_config']['zh']['bet']);
    // Db::name('lottery')
    //   ->where('content','like',['%'.$bet[].'%','%php%'],'and')->select();
  }

  /**
   * 根据key值计算此玩法总共投注金额
   * @param array   $data   查询投注内容
   * @param string  $key    需要计算的key
   * @param float   $moeny  投注总金额
   * @return float  返回key的实际投注金额
   */
  public function betNumMoney($data,$key,$money){
    $list_money = 0;
    $data = json_decode($data,true);
    if(count($data) == 1){
      $list_money += $money;
    }else{
      $a_m = $money/$this->betNum($data);
      $list_money += $a_m * count($data[$key]);
    }
    return $list_money;
  }


  /**
   * 注数计算
   * @param array  $data 投注数据
   * @return int  投注输
   */
  public function betNum($data){
    $i = 0;
    foreach($data as $vo){
      $i += count($vo);
    }
    return $i;
  }

  /**
   * 最大投注限制 判断
   * @param int   $userid   用户id
   * @param int   $type     类型
   * @param int   $expect   投注期号
   * @return int 本期之前投注总额 
   */
  public function chackMaxBet($userid,$type,$expect){
    $return_data = [
      'code' => -1,
      'msg' => '判断错误'
    ];
    //betting普通投注表总金额
    $bet1 = Db::table('betting')
           ->where('user_id','=',$userid)
           ->where('expect','=',$expect)
           ->where('type','=',$type)
           ->sum('money');
    //查询合买投注总金额
    $heid = Db::table('betting')
            ->where('user_id','=',$userid)
            ->where('expect','=',$expect)
            ->where('type','=',$type)
            ->where('money','=',0)
            ->column('id');
    $bet2 = 0;
    if( !empty($heid) ){
      $bet2 = Db::table('betting_gen')
            ->where('betting_id','in',$heid)
            ->where('user_id','=',$userid)
            ->sum('money');
    }
    //查询追号投注总金额
    $zhuiid = Db::table('betting')
            ->field('id,money')
            ->where('user_id','=',$userid)
            ->where('type','=',$type)
            ->where('expect','=',0)
            // ->where('money','>',0)
            ->where('state','=',3)
            ->select();
    // print_r($zhuiid);
    $bet3 = 0;
    if(!empty($zhuiid)){
      foreach($zhuiid as $value){
        $num = 0;
        if($value['money'] > 0){ 
          $num = Db::table('betting_zhui')->where('betting_id','=',$value['id'])->where('expect','=',$expect)->count();
          if($num == 0){continue;}
          $bet3 += $value['money']/$num;
        }else{
          //又追号又合买的情况下

          //如果追号表无这一期且期号对应
          if(!Db::table('betting_zhui')->where('betting_id','=',$value['id'])->where('expect','=',$expect)->where('state','=',0)->find()){
            continue;
          }
          //如果有则查询和买表里面的自购
          $num = Db::table('betting_gen')->field('buy')->where('betting_id','=',$value['id'])->where('user_id','=',$userid)->sum('money');
          $bet3 += $num;
        }

      }
    }
    return $bet1 + $bet2 + $bet3;
  }

  public function getInfo(){
    return $this->getInfoAction();
  }

  public function betting(){
    return $this->bettingAction();
  }

  public function combinationBasic($n,$m){
    $n1 = 1;$n2 = 1;
    for ($i = $n,$j = 1; $j <= $m; $n1 *= $i--,$n2 *= $j++);
    return $n1 / $n2;
  }
  
  /**
   * 获取所有已开启的游戏
   */
  public function getLotteryListApi(){
    // print_r(123);die();
    //获取所有已开启的游戏
    $data = input('post.');
    if( $data['type'] == -1 ){
      $rs = ApiConfig::list()->toArray();
    } elseif($data['type'] == 0) {
      $rs = ApiConfig::list()->toArray();
      if(!empty($rs)){
        $rs[0]['game'] = ApiGame::game($rs[0]['list']);
      }
    }else{
        $rs = ApiGame::game($data['type']);
    }
    // print_r($rs);
    return $rs;
  }


/**
   * 验证彩票每期投注都在一个范围之内
   * @param $userid 用户id
   * @param $type  彩票类型
   * @param $expect 期号
   * @param $bet   投注数据
   * @return mixed
   */
  // public function checkEveryNum($userid,$type,$expect,$bet){
  //   $return_data = [
  //       'code' => 1,
  //       'msg' => 'OK'
  //   ];

  //   //查找此期号数据库是否有该用户的下注记录
  //   $list = [];
  //   foreach($bet as $key => $item){
  //     if($this->lottery_config['basic_config'][$key]['number'] == 0){continue;}
  //     if(!isset($list[$key])){
  //       $list[$key] = 0;
  //     }
  //     foreach($item as $key1 => $item1){
  //       $list[$key] += $item1['num'];
  //     }
  //     if($list[$key] > $this->lottery_config['basic_config'][$key]['number']){
  //       $return_data['code'] = -1;
  //       $return_data['msg'] = '玩法('.$this->lottery_config['basic_config'][$key]['name'].')超过上限,每期上限'.$this->lottery_config['basic_config'][$key]['number'].'注';
  //       return $return_data;
  //     }
  //   }

  //   if(count($list) == 0){
  //     return $return_data;
  //   }

  //   $res = Db::table('betting')
  //       ->field('content')
  //       ->where('user_id',$userid)
  //       ->where('type',$type)
  //       ->where('expect',$expect)
  //       ->select();
  //   if(empty($res)){
  //     return;
  //   }
  //   foreach($res as $k => $v){
  //     $cc = json_decode($v['content'],true);
  //     foreach($cc as $k1 => $v1){
  //       foreach($v1 as $k2 => $v2 ){
  //         if(isset($list[$k1])){
  //           $list[$k1] += $v2['num'];
  //           if($list[$k1] > $this->lottery_config['basic_config'][$k1]['number']){
  //             $return_data['code'] = -1;
  //             $return_data['msg'] = '玩法('.$this->lottery_config['basic_config'][$k1]['name'].')超过上限,每期上限'.$this->lottery_config['basic_config'][$k1]['number'].'注';
  //             return $return_data;
  //           }
  //         }
  //       }
  //     }
  //   }
  // return true;
  // }


/**
   * 验证彩票每期投注后只能加倍
   * @param $userid 用户id
   * @param $type  彩票类型
   * @param $expect 期号
   * @param $bet   投注数据
   * @return mixed
   */
  public function checkEveryplus($userid,$type,$expect,$bet){
    $return_data = [
        'code' => 1,
        'msg' => 'OK'
    ];

    $list = [];  //用户当前选择
    $arr  = [];  //数据库中所有的

    //第一次循环查出当前用户玩法选择的号
    foreach($bet as $key => $item){
      if(!isset($list[$key])){
        $list[$key] = [];
      }
       foreach ($item as $k => $v) {
        $list[$key][] = $k;
      }
    }

    //查找此期号数据库是否有该用户的下注记录
    $res = Db::table('betting')
        ->field('content')
        ->where('user_id',$userid)
        ->where('type',$type)
        ->where('expect',$expect)
        ->select();
    //没有就直接返回    
    if(empty($res)){
      return;
    }

    //数据库内取出
    foreach($res as $k1 => $v1){
      $cc = json_decode($v1['content'],true);
    foreach($cc as $k2 => $v2){
      if(!isset($arr[$k2])){
        $arr[$k2] = [];
      }
       foreach ($v2 as $k3 => $v3) {
        if(isset($list[$k2])){
          $list[$k2][] = $k3;
        }
      }
    }
  }
  foreach ($list as $key => $value) {
      if($this->lottery_config['basic_config'][$key]['number']>0 && $this->lottery_config['basic_config'][$key]['number'] < count(array_unique($value))){
        $return_data['code'] = -1;
        $return_data['msg'] = '只能加注您所下注的号码';
        return $return_data;
      }
    }
    return;
 }

}


