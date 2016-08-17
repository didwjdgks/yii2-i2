<?php
namespace i2\controllers;

use yii\helpers\Console;
use yii\helpers\Json;
use yii\helpers\ArrayHelper;

use i2\models\BidKey;
use i2\models\BidValue;
use i2\models\BidContent;
use i2\models\BidRes;
use i2\models\BidSuccom;
use i2\models\CodeOrgI;

class AutoController extends \yii\console\Controller
{
  public function actionBid(){
    $w=new \GearmanWorker;
    $w->addServers($this->module->gman_server);
    $w->addFunction($this->module->i2_auto_bid,[$this,'bid_work']);
    while($w->work());
  }

  public function bid_work($job){
    try {
      $workload=Json::decode($job->workload());
      $this->stdout("i2> [{$workload['whereis']}] {$workload['bidid']} {$workload['notinum']} {$workload['constnm']} ({$workload['bidproc']})\n");
      switch($workload['bidproc']){
        case 'C':
          $this->bid_c($workload);
          break;
        case 'M':
          $this->bid_m($workload);
          break;
        case 'B':
          $this->bid_b($workload);
          break;
      }
    }
    catch(\Exception $e){
      $this->stdout("$e\n",Console::FG_RED);
      \Yii::error($e,'i2');
    }
    $this->module->db->close();
    $this->stdout(sprintf("[%s] Peak memory usage: %sMb\n",
      date('Y-m-d H:i:s'),
      (memory_get_peak_usage(true)/1024/1024)
    ),Console::FG_GREY);
  }

  /**
   * 취소
   */
  private function bid_c($workload){
    try{
      $bidkey=BidKey::findOne($workload['bidid']);
      if($bidkey!==null){
        $this->stdout(" > 이미 등록된 취소공고입니다.\n",Console::FG_YELLOW);
        return;
      }

      list($bidno)=explode('-',$workload['bidid']);
      $prev=BidKey::find()->where("bidid like '$bidno%'")
        ->orderBy('bidid desc')->limit(1)->one();
      if($prev===null){
        $this->stdout(" > 취소 전 공고가 없습니다.\n",Console::FG_YELLOW);
        return;
      }

      if($prev->bidproc==='C'){
        $this->stdout(" > 이미 등록된 취소공고입니다.\n",Console::FG_YELLOW);
        return;
      }

      $maxno=$this->module->db->createCommand("select max([[no]]) from {{bid_key}}")->queryScalar();

      if($prev->state!='Y'){
        $bidkey=new BidKey;
        $bidkey->attributes=$prev->attributes;
        $bidkey->bidid=$workload['bidid'];
        $bidkey->state='D';
        $bidkey->bidproc='C';
        $bidkey->writedt=date('Y-m-d H:i:s');
        $bidkey->editdt=date('Y-m-d H:i:s');
        $bidkey->no=$maxno+1;
        $bidkey->save();
        $prev->state='D';
        $prev->bidproc='M';
        $prev->save();
        $this->stdout(" > 입력 전 취소공고입니다. 삭제합니다.\n",Console::FG_YELLOW);
        return;
      }

      $bidkey=new BidKey;
      $bidkey->attributes=$prev->attributes;
      $bidkey->bidid=$workload['bidid'];
      $bidkey->writedt=date('Y-m-d H:i:s',strtotime($prev->writedt)+1);
      $bidkey->editdt=date('Y-m-d H:i:s');
      $bidkey->state='Y';
      $bidkey->bidproc='C';
      if(($bidkey->opt&pow(2,1))==0) $bidkey->opt=$bidkey->opt+pow(2,1); //정정
      $bidkey->no=$maxno+1;

      $prevVal=$prev->bidValue;
      $bidvalue=new BidValue;
      $bidvalue->attributes=$prevVal->attributes;
      $bidvalue->bidid=$bidkey->bidid;

      $prevCon=$prev->bidContent;
      $bidcontent=new BidContent;
      $bidcontent->attributes=$prevCon->attributes;
      $bidcontent->bidid=$bidkey->bidid;
      if($workload['bid_html'])
        $bidcontent->bid_html=$workload['bid_html'];
      if($workload['bidcomment'])
        $bidcontent->bidcomment=$workload['bidcomment'];

      $bidcontent->save();
      $bidvalue->save();
      $bidkey->save();

      $prev->bidproc='M';
      $prev->editdt=date('Y-m-d H:i:s');
      $prev->save();

      $this->stdout(" > 취고공고 입력이 완료되었습니다.\n",Console::FG_GREEN);

    }catch(\Exception $e){
      throw $e;
    }
  }

  /**
   * 정정
   */
  private function bid_m($workload){
    $bidkey=BidKey::findOne($workload['bidid']);
    if($bidkey!==null) return;

    list($bidno)=explode('-',$workload['bidid']);
    $prev=BidKey::find()->where("bidid like '$bidno%'")
      ->orderBy('bidid desc')->limit(1)->one();
    if($prev!==null){
      $prev->bidproc='M';
      $prev->editdt=date('Y-m-d H:i:s');
      try{
        $prev->save();
        $this->stdout(" > 정정전공고 처리완료\n",Console::FG_GREEN);
      }catch(\Exception $e){
        throw $e;
      }
    }

    if(!isset($workload['opt'])) $workload['opt']=pow(2,1);
    else if(($workload['opt']&pow(2,1))==0) $workload['opt']+=pow(2,1);

    return $this->bid_b($workload);
  }

  /**
   * 일반
   */
  private function bid_b($workload){
    $bidkey=BidKey::findOne($workload['bidid']);
    if($bidkey!==null) return;

    $bidkey=new BidKey;
    $bidkey->bidid=$workload['bidid'];
    $bidkey->notinum=$workload['notinum'];
    $bidkey->notinum_ex=$workload['notinum_ex'];
    $bidkey->whereis=$workload['whereis'];
    $bidkey->bidtype=$workload['bidtype'];
    $bidkey->bidview=$workload['bidview']?$workload['bidview']:$workload['bidtype'];
    $bidkey->constnm=$workload['constnm'];
    $bidkey->org_i=$workload['org_i'];
    $bidkey->orgcode_y=$workload['orgcode_y']; //도로공사 bidseq 저장 (차수정보)
    $bidkey->bidcls=$workload['bidcls'];
    $bidkey->succls=$workload['succls'];
    $bidkey->conlevel=$workload['conlevel'];
    $bidkey->noticedt=$workload['noticedt'];
		$bidkey->registdt=$workload['registdt'];
    $bidkey->basic=$workload['basic'];
    $bidkey->presum=$workload['presum'];
    $bidkey->contract=$workload['contract'];
    $bidkey->opendt=$workload['opendt'];
    $bidkey->closedt=$workload['closedt'];
    $bidkey->constdt=$workload['constdt'];
    $bidkey->explaindt=$workload['explaindt'];
    $bidkey->agreedt=$workload['agreedt'];
    $bidkey->pqdt=$workload['pqdt'];
    $bidkey->convention=$workload['convention'];
    $bidkey->bidproc='B';
    $bidkey->state=$workload['state'];
    $bidkey->writedt=$workload['writedt']?$workload['writedt']:date('Y-m-d H:i:s');
    $bidkey->editdt=date('Y-m-d H:i:s');
    $bidkey->opt=$workload['opt'];
    $bidkey->state='N';

    $maxno=$this->module->db->createCommand("select max([[no]]) from bid_key")->queryScalar();
    $bidkey->no=$maxno+1;

    $codeorg=CodeOrgI::findByOrgname($bidkey->org_i);
    if($codeorg!==null) $bidkey->orgcode_i=$codeorg->org_Scode;

    $bidvalue=BidValue::findOne($bidkey->bidid);
    if($bidvalue===null) $bidvalue=new BidValue(['bidid'=>$bidkey->bidid]);
    $bidvalue->yegatype=$workload['yegatype'];
    $bidvalue->yegarng=$workload['yegarng'];
    $bidvalue->charger=$workload['charger'];
    $bidvalue->multispare=$workload['multispare'];

    $bidcontent=BidContent::findOne($bidkey->bidid);
    if($bidcontent===null) $bidcontent=new BidContent(['bidid'=>$bidkey->bidid]);
    $bidcontent->orign_lnk=$workload['orign_lnk'];
    $bidcontent->attchd_lnk=$workload['attchd_lnk'];
    $bidcontent->bidcomment=$workload['bidcomment'];

    try {
      $bidvalue->save();
      $bidcontent->save();
      $bidkey->save();
      if(($bidkey->opt&pow(2,1))>0) $this->stdout(" > 정정공고 입력이 완료되었습니다.\n",Console::FG_GREEN);
      else $this->stdout(" > 일반공고 입력이 완료되었습니다.\n",Console::FG_GREEN);
    }
    catch(\Exception $e){
      throw $e;
    }
  }

  public function actionSuc(){
    $w=new \GearmanWorker;
    $w->addServers($this->module->gman_server);
    $w->addFunction($this->module->i2_auto_suc,[$this,'suc_work']);
    while($w->work());
  }

  public function suc_work($job){
    $workload=Json::decode($job->workload());
    try {  
      $this->stdout("i2> [{$workload['bidid']}] {$workload['notinum']} {$workload['constnm']} ({$workload['bidproc']})\n");
      switch($workload['bidproc']){
        case 'F':
          $this->suc_f($workload);
          break;
        case 'S':
          $this->suc_s($workload);
          break;
      }
    }
    catch(\Exception $e){
      $this->stdout("$e\n",Console::FG_RED);
      \Yii::error($e,'i2');
    }
    $this->module->db->close();
    $this->stdout(sprintf(" [%s] Peak memory usage: %sMb\n",
      date('Y-m-d H:i:s'),
      (memory_get_peak_usage(true)/1024/1024)
    ),Console::FG_GREY);
  }

  /**
   * 유찰 처리
   */
  private function suc_f($workload){
    $bidkey=BidKey::findOne($workload['bidid']);
    if($bidkey===null) return;
    $out[]="[i2] [$bidkey->bidid] %g$bidkey->notinum%n $bidkey->constnm";

    $bidres=BidRes::findOne($bidkey->bidid);
    if($bidres===null){
      $bidres=new BidRes(['bidid'=>$bidkey->bidid]);
    }
    $bidres->yega=0;
    $bidres->selms='';
    $bidres->multispare='';
    $bidres->officenm1='유찰';
    $bidres->reswdt=date('Y-m-d H:i:s');
    $bidres->save();

    if(($bidkey->opt&pow(2,5))==0) $bidkey->opt+=pow(2,5);
    $bidkey->bidproc='F';
    $bidkey->resdt=date('Y-m-d H:i:s');
    $bidkey->editdt=date('Y-m-d H:i:s');
    $bidkey->save();

    $out[]="%y유찰%n";

    $this->stdout(Console::renderColoredString(join(' ',$out))."\n");
  }

  /**
   * 개찰 처리
   */
  private function suc_s($workload){
    $bidkey=BidKey::findOne($workload['bidid']);
    if($bidkey===null) return;
    $out[]="[i2] [$bidkey->bidid] %g$bidkey->notinum%n $bidkey->constnm";

    $bidres=BidRes::findOne($bidkey->bidid);
    if($bidres===null){
      $bidres=new BidRes(['bidid'=>$bidkey->bidid]);
    }
    $bidres->yega=$workload['yega'];
    $bidres->innum=$workload['innum'];
    $bidres->selms=$workload['selms'];
    $bidres->multispare=$workload['multispare'];
    $bidres->officenm1=$workload['officenm1'];
    $bidres->prenm1=$workload['prenm1'];
    $bidres->officeno1=$workload['officeno1'];
    $bidres->success1=$workload['success1'];
    $bidres->reswdt=date('Y-m-d H:i:s');
    $bidres->save();

    $out[]="%y개찰%n";
    $this->stdout(Console::renderColoredString(join(' ',$out))."\n");

    BidSuccom::deleteAll(['bidid'=>$bidkey->bidid]);
    if(is_array($workload['succoms'])){
      $total=$bidres->innum;
      $cur=1;
      Console::startProgress(0,$total);
      foreach($workload['succoms'] as $r){
        $bidsuccom=new BidSuccom([
          'bidid'=>$bidkey->bidid,
          'seq'=>$r['seq'],
          'officeno'=>$r['officeno'],
          'officenm'=>$r['officenm'],
          'prenm'=>$r['prenm'],
          'success'=>$r['success'],
          'pct'=>$r['pct'],
          'rank'=>$r['rank'],
          'selms'=>$r['selms'],
          'etc'=>$r['etc'],
        ]);
        $bidsuccom->save();
        Console::updateProgress($cur++,$total);
      }
      Console::endProgress();
    }

    if(($bidkey->opt&pow(2,5))==0) $bidkey->opt+=pow(2,5);
    $bidkey->bidproc='S';
    $bidkey->resdt=date('Y-m-d H:i:s');
    $bidkey->editdt=date('Y-m-d H:i:s');
    $bidkey->save();
  }
}

