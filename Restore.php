<?php
namespace FreePBX\modules\Pm2;
use FreePBX\modules\Backup as Base;
class Restore Extends Base\RestoreBase{
	public function runRestore($jobid){
		$configs = $this->getConfigs();
		$advancedSettings = [
			'PM2USEPROXY' => false,
			'PM2PROXY' => 'http://mirror.freepbx.org:6767/',
			'PM2USECACHE' => true,
			'PM2SHELL' => '/bin/bash',
			'PM2DISABLELOG' => false
		];
		foreach($configs as $setting => $value){
			if(!isset($advancedSettings[$setting])){
				continue;
			}
			$this->FreePBX->Config->set($setting,$value);
		}
	}
	public function processLegacy($pdodbconn, $data, $tablelist, $unknowntables){
		$advanced = [
			'PM2USEPROXY',
			'PM2PROXY',
			'PM2USECACHE',
			'PM2SHELL',
			'PM2DISABLELOG'
		];
		foreach ($advanced as $key) {
			if(isset($data['settings'][$key])){
				$this->FreePBX->Config->update($key, $data['settings'][$key]);
			}
		}
	}
}