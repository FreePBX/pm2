<?php
namespace FreePBX\modules\Pm2;
use FreePBX\modules\Backup as Base;
class Backup Extends Base\BackupBase{
  public function runBackup($id,$transaction){
    $configs = [];
    $advancedSettings = [
      'PM2USEPROXY' => false,
      'NODEJSBINDADDRESS' => 'http://mirror.freepbx.org:6767/',
      'PM2USECACHE' => true,
      'PM2SHELL' => '/bin/bash',
      'PM2DISABLELOG' => false
    ];
    foreach($advancedSettings as $setting => $default){
      $ret = $this->FreePBX->Config->get($setting);
      $configs[$setting] = ($ret)?$ret:$default;
    }
    $this->addConfigs($configs);
  }
}