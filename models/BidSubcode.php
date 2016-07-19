<?php
namespace i2\models;

class BidSubcode extends \yii\db\ActiveRecord
{
  public static function tableName(){
    return 'bid_subcode';
  }

  public function rules(){
    return [
    ];
  }

  public function beforeSave($insert){
    if(parent::beforeSave($insert)){
      if($this->g2b_code_nm) $this->g2b_code_nm=iconv('utf-8','euckr',$this->g2b_code_nm);
      return true;
    }
    return false;
  }

  public function afterFind(){
    parent::afterFind();
    if($this->g2b_code_nm) $this->g2b_code_nm=iconv('euckr','utf-8',$this->g2b_code_nm);
  }
}

