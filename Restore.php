<?php
namespace FreePBX\modules\Pm2;
use FreePBX\modules\Backup as Base;
class Restore Extends Base\RestoreBase{
  public function runRestore($jobid){
    $configs = $this->getConfigs();
    $advancedSettings = [
      'PM2USEPROXY' => false,
      'NODEJSBINDADDRESS' => 'http://mirror.freepbx.org:6767/',
      'PM2USECACHE' => true,
      'PM2SHELL' => '/bin/bash',
    ];
    foreach($configs as $setting => $value){
      if(!isset($advancedSettings[$setting])){
        continue;
      }
      $this->FreePBX->Config->set($setting,$value);
    }
  }
  public function processLegacy($pdodbconn, $data, $tablelist, $unknowntables, $tmpdir){
    $settings = [
      'PM2USEPROXY' => false,
      'NODEJSBINDADDRESS' => 'http://mirror.freepbx.org:6767/',
      'PM2USECACHE' => true,
      'PM2SHELL' => '/bin/bash',
    ];
    $configs = $this->getAMPConf($pdodbconn);
    foreach ($configs as $setting => $value) {
      if (!isset($settings[$setting])) {
        continue;
      }
      $this->FreePBX->Config->set($setting, $settings[$setting]);
    }
  }
}