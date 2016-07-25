<?php
namespace i2\controllers;

use GearmamWorker;

use yii\helpers\Console;
use yii\helpers\Json;
use yii\helpers\ArrayHelper;

use i2\models\BidKey;
use i2\models\BidRes;
use i2\models\BidSuccom;

class AutoController extends \yii\console\Controller
{
  public function actionBid(){
    $w=new GearmanWorker;
    $w->addServers($this->module->gman_server);
    $w->addFunction('i2_auto_bid',[$this,'bid_work']);
    while($w->work());
  }

  public function bid_work($job){
    $workload=$job->workload();
    try {
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
  }

  /**
   * 취소
   */
  private function bid_c($workload){
  }

  /**
   * 정정
   */
  private function bid_m($workload){
  }

  /**
   * 일반
   */
  private function bid_b($workload){
    $bidkey=BidKey::findOne($workload['bidid']);
    if($bidkey!==null) return;

    $bidkey->bidid=$workload['bidid'];
    $bidkey->notinum=$workload['notinum'];
    $bidkey->whereis=$workload['whereis'];
    $bidkey->bidtype=$workload['bidtype'];
    $bidkey->bidview=$workload['bidview']?$workload['bidview']:$workload['bidtype'];
    $bidkey->constnm=$workload['constnm'];
    $bidkey->org_i=$workload['org_i'];
    $bidkey->bidcls=$workload['bidcls'];
    $bidkey->succls=$workload['succls'];
    $bidkey->noiticedt=$workload['noticedt'];
    $bidkey->basic=$workload['basic'];
    $bidkey->presum=$workload['presum'];
    $bidkey->contract=$workload['contract'];
    $bidkey->opendt=$workload['opendt'];
    $bidkey->closedt=$workload['closedt'];
    $bidkey->constdt=$workload['constdt'];
    $bidkey->pqdt=$workload['pqdt'];
    $bidkey->convention=$workload['convention'];
    $bidkey->bidproc='B';
    $bidkey->state=$workload['state'];
    $bidkey->writedt=$workload['writedt']?$workload['writedt']:date('Y-m-d H:i:s');
    $bidkey->editdt=date('Y-m-d H:i:s');

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
    }
    catch(\Exception $e){
      throw $e;
    }
  }

  public function actionSuc(){
    $w=new GearmanWorker;
    $w->addServers($this->module->gman_server);
    $w->addFunction('i2_auto_suc',[$this,'suc_work']);
    while($w->work());
  }

  public function suc_work($job){
    $workload=$job->workload();
    try {
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
    $bidkey=BidKey::findOne($workload['bidkey']);
    if($bidkey===null) return;
    $out[]="[i2] [$bidkey->bidid] %g$bidkey->notinum%n $bidkey->constnm";

    $bidres=BidRes::findOne($bidkey->bidid);
    if($bidres===null){
      $bidres=new BidRes(['bidid'=>$bidid]);
    }
    $bidres->yega=$workload['yega'];
    $bidres->innum=$workload['innum'];
    $bidres->selms=$workload['selms'];
    $bidres->multispare=$workload['multispare'];
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

    $bidkey->bidproc='S';
    $bidkey->resdt=date('Y-m-d H:i:s');
    $bidkey->editdt=date('Y-m-d H:i:s');
    $bidkey->save();
  }
}

