<?php
namespace i2\models;

class BidLocal extends \yii\db\ActiveRecord
{
	public static function tableName(){
		return 'bid_local';
	}

	public static function getDb(){
		return \i2\module::getInstance()->db;
	}

	public function rules(){
		return [
			[['bidid','code'],'required'],
			[['name'],'safe'],
		];
	}

	public function afterFind(){
		parent::afterFind();
		if($this->name)			$this->name =		iconv('euckr','utf-8',$this->name);
	}

	public function beforeSave($insert){
		if(parent::beforeSave($insert)){
			if($this->name)			$this->name =		iconv('utf-8','euckr',$this->name);
			return true;
		}
		return false;
	}
}