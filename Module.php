<?php
namespace i2;

use yii\db\Connection;
use yii\di\Instance;

class Module extends \yii\base\Module
{
  public $db='i2db';

  public $gman_server;
  public $i2_auto_bid='i2_auto_bid';
  public $i2_auto_suc='i2_auto_suc';

  public function init(){
    parent::init();

    $this->db=Instance::ensure($this->db,Connection::className());
  }
}

