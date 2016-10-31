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
use i2\models\BidGoods;
use i2\models\BidSubcode;
use i2\models\BidLocal;
use i2\models\CodeLocal;

class AutoController extends \yii\console\Controller
{
  public function stdout2($string){
    $this->stdout(Console::renderColoredString($string));
  }

  public function actionBid(){
    $w=new \GearmanWorker;
    $w->addServers($this->module->gman_server);
    $w->addFunction($this->module->i2_auto_bid,[$this,'bid_work']);
    //------------------
    // 기초금액
    //------------------
    $w->addFunction('i2_auto_basic',function($job){
      try{
        $workload=Json::decode($job->workload());
        $bidkey=BidKey::findOne($workload['bidid']);
        if($bidkey===null){
          $this->stdout2("%rerror> not found bidid: {$workload['bidid']}%n\n");
          return;
        }
        $this->stdout2("%4i2> [기초금액] {$bidkey->whereis} {$bidkey->notinum} {$bidkey->constnm}%n\n");
        $basic=str_replace(',','',$workload['basic']);
        if($basic>0){
          $bidkey->basic=$basic;
          if(($bidkey->opt&pow(2,9))==0) $bidkey->opt=$bidkey->opt+pow(2,9);
          $bidkey->save();
        }
      }catch(\Exception $e){
        $this->stdout($e->getMessage()."\n",Console::FG_RED);
        \Yii::error($e,'kepco');
      }
    });
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
        case 'L':
          $this->bid_l($workload);
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
   * 연기공고
   */
  private function bid_l($workload){
    $prev=BidKey::findOne($workload['previd']);
    if($prev===null){
      $this->stdout(" 등록되지 않은 공고입니다.\n",Console::FG_RED);
      return;
    }
    if($workload['closedt'] <= $prev->closedt){
      $this->stdout(" 입찰마감일이 동일합니다.\n",Console::FG_YELLOW);
      return;
    }

    list($a,$b,$c,$d)=explode('-',$prev->bidid);
    $b=sprintf('%02s',intval($b)+1);
    $newid="$a-$b-$c-$d";

    $maxno=$this->module->db->createCommand("select max([[no]]) from {{bid_key}}")->queryScalar();

    $bidkey=new BidKey;
    $bidkey->attributes=$prev->attributes;
    $bidkey->bidid=$newid;
    $bidkey->writedt=date('Y-m-d H:i:s');
    $bidkey->editdt=date('Y-m-d H:i:s');
    $bidkey->bidproc='B';
    if(($bidkey->opt&pow(2,1))==0) $bidkey->opt=$bidkey->opt+pow(2,1); //정정
    if(($bidkey->opt&pow(2,18))==0) $bidkey->opt=$bidkey->opt+pow(2,18); //연기
    $bidkey->no=$maxno+1;
    if($workload['registdt']) $bidkey->registdt=$workload['registdt'];
    $bidkey->constdt=$workload['constdt'];
    $bidkey->closedt=$workload['closedt'];
    $bidkey->state='N';
    $bidkey->save();

    $prevVal=$prev->bidValue;
    $bidvalue=new BidValue;
    $bidvalue->attributes=$prevVal->attributes;
    $bidvalue->bidid=$bidkey->bidid;

    $prevCon=$prev->bidContent;
    $bidcontent=new BidContent;
    $bidcontent->attributes=$prevCon->attributes;
    $bidcontent->bidid=$bidkey->bidid;

    $prevGoods=$prev->bidGoods;
    foreach($prevGoods as $val){
      $bidgoods=new BidGoods;
      $bidgoods->attributes=$val->attributes;
      $bidgoods->bidid=$bidkey->bidid;
      $bidgoods->save();
    }

    $prevSubcodes=$prev->bidSubcode;
    foreach($prevSubcodes as $val){
      $bidsubcode=new BidSubcode;
      $bidsubcode->attributes=$val->attributes;
      $bidsubcode->bidid=$bidkey->bidid;
      $bidsubcode->save();
    }

    $prev->bidproc='L';
    $prev->editdt=date('Y-m-d H:i:s');
    $prev->save();

    $bidcontent->save();
    $bidvalue->save();

    $bidkey->state='Y';
    $bidkey->save();
		
    $this->stdout(" 연기공고 입력이 완료되었습니다.\n");
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
      if(($bidkey->opt&pow(2,16))==0) $bidkey->opt=$bidkey->opt+pow(2,16); //취소
      $bidkey->no=$maxno+1;

			if(strpos($workload['constnm'],'//')!==false){
				$bidkey->constnm=$workload['constnm'].'(취소)';
			}else{
				$bidkey->constnm=$workload['constnm'].'//'.'(취소)';
			}

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

      $prevGoods=$prev->bidGoods;
      foreach($prevGoods as $val){
        $bidgoods=new BidGoods;
        $bidgoods->attributes=$val->attributes;
        $bidgoods->bidid=$bidkey->bidid;
        $bidgoods->save();
      }

      $prevSubcodes=$prev->bidSubcode;
      foreach($prevSubcodes as $val){
        $bidsubcode=new BidSubcode;
        $bidsubcode->attributes=$val->attributes;
        $bidsubcode->bidid=$bidkey->bidid;
        $bidsubcode->save();
      }

      $bidcontent->save();
      $bidkey->save();
			$bidvalue->save();
      

      if(($prev->opt&pow(2,16))==0) $prev->opt=$prev->opt+pow(2,16); //취소
			$prev->bidproc='M';			
      $prev->editdt=date('Y-m-d H:i:s');
      $prev->save();

      $this->stdout(" > 취소공고 입력이 완료되었습니다.\n",Console::FG_GREEN);

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
		$bidkey->syscode=$workload['syscode'];
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
		$bidkey->location=$workload['location'];
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
    if(isset($workload['bid_html'])) $bidcontent->bid_html=$workload['bid_html'];

    try {
      if(is_array($workload['goods'])){
        foreach($workload['goods'] as $g){
          $bidgoods=new BidGoods([
            'bidid'=>$bidkey->bidid,
            'seq'=>$g['seq'],
            'gcode'=>$g['gcode'],
            'gname'=>$g['gname'],
            'standard'=>$g['standard'],
            'unit'=>$g['unit'],
            'cnt'=>$g['cnt'],
          ]);
          $bidgoods->save();
        }
      }

			$sublocal = '';
			if(is_array($workload['bid_local'])){
				foreach($workload['bid_local'] as $loc){
					$code=codeLocal::findByName($loc['name']);					
					if($code!==null){
						$tname=str_replace($loc['hname'],'',$code->name);
						if(strpos($sublocal,$tname)===false){ 
							$bidlocal=new BidLocal([
								'bidid'=>$bidkey->bidid,
								'name'=>$loc['name'],
								'code'=>$code->code,
							]);
							
							if($sublocal=='')	$sublocal = trim($tname);
							else	$sublocal=$sublocal.','.trim($tname);
							$sublocal = trim($sublocal);

							$bidlocal->save();

						}										
					}
				}
				if($sublocal!=='' and ($bidkey->opt&pow(2,11))==0)	$bidkey->opt+=pow(2,11);				
			}
			
			$this->stdout(" > {$sublocal}\n",Console::FG_GREEN);
			$this->stdout(" > {$bidkey->constnm}\n",Console::FG_GREEN);			

			if($bidkey->constnm!==null and $sublocal!==''){
				if(strpos($bidkey->constnm,'//')!==false){
					$bidkey->constnm=$bidkey->constnm.'('.$sublocal.')';
				}else{					
					$bidkey->constnm=$bidkey->constnm.'//'.'('.$sublocal.')';
				}
			}
			$this->stdout(" > {$bidkey->constnm}\n",Console::FG_GREEN);			

      $bidkey->save();
			$bidvalue->save();
      $bidcontent->save();
      
			
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

		$bidcontent=BidContent::findOne($workload['bidid']);
		if($bidcontent!==null){
			$bidcontent->nbidcomment=$workload['nbidcomment'];
		}
		$bidcontent->save();

    if(($bidkey->opt&pow(2,5))==0) $bidkey->opt+=pow(2,5);
		if($workload['constnm']!==null){
			if(strpos($workload['constnm'],'//')!==false){
				$bidkey->constnm=$workload['constnm'].'(유찰)';
			}else{
				if($bidkey->constnm!==null){
					$bidkey->constnm=$workload['constnm'].'//'.'(유찰)';
				}
			}
		}
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

