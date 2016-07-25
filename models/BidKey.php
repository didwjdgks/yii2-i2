<?php
namespace i2\models;

use i2\Module;

class BidKey extends \yii\db\ActiveRecord
{
  public static function tableName(){
    return 'bid_key';
  }

  public static function getDb(){
    return Module::getInstance()->db;
  }
  
  public function rules(){
    return [
      [['notinum_ex'],'default','value'=>''],
      [['bidproc'],'default','value'=>'B'],
      [['opt'],'default','value'=>0],
      [['bidcls'],'default','value'=>'01'],
      [['contract'],'default','value'=>'10'],
      [['succls'],'default','value'=>'00'],
    ];
  }
  
  public function beforeSave($insert){
    if(parent::beforeSave($insert)){
      if($this->notinum) $this->notinum=iconv('utf-8','euckr',$this->notinum);
      if($this->constnm) $this->constnm=iconv('utf-8','euckr',$this->constnm);
      if($this->org_i) $this->org_i=iconv('utf-8','euckr',$this->org_i);
      return true;
    }
    return false;
  }
  
  public function afterFind(){
    parent::afterFind();
    if($this->notinum) $this->notinum=iconv('euckr','utf-8',$this->notinum);
    if($this->constnm) $this->constnm=iconv('euckr','utf-8',$this->constnm);
    if($this->org_i) $this->org_i=iconv('euckr','utf-8',$this->org_i);
  }
}

