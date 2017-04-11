<?php
// vim: set ai ts=4 sw=4 ft=php:
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2014 Schmooze Com Inc.
//
namespace FreePBX\modules;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Console\Helper\ProgressBar;
class Pm2 extends \FreePBX_Helpers implements \BMO {
	private $nodever = "0.12.18";
	private $npmver = "2.15.11";
	private $pm2 = "/tmp";
	private $nodeloc = "/tmp";

	public function __construct($freepbx = null) {
		$this->astman = $freepbx->astman;
		$this->db = $freepbx->Database;
		$this->freepbx = $freepbx;
		$this->pm2 = $this->getHomeDir() . "/.pm2";
		$this->nodeloc = __DIR__."/node";
	}

	public function install() {
		$output = exec("node --version"); //v0.10.29
		$output = str_replace("v","",trim($output));
		if(empty($output)) {
			out(_("Node is not installed"));
			return false;
		}
		if(version_compare($output,$this->nodever,"<")) {
			out(sprintf(_("Node version is: %s requirement is %s. Run 'yum upgrade nodejs' from the CLI as root"),$output,$this->nodever));
			return false;
		}


		$output = exec("npm --version"); //v0.10.29
		$output = trim($output);
		if(empty($output)) {
			out(_("Node Package Manager is not installed"));
			return false;
		}
		if(version_compare($output,$this->npmver,"<")) {
			out(sprintf(_("NPM version is: %s requirement is %s. Run 'yum upgrade nodejs' from the CLI as root"),$output,$this->npmver));
			return false;
		}

		$webgroup = $this->freepbx->Config->get('AMPASTERISKWEBGROUP');

		$data = posix_getgrgid(filegroup($this->getHomeDir()));
		if($data['name'] != $webgroup) {
			out(sprintf(_("Home directory [%s] is not writable"),$this->getHomeDir()));
			return false;
		}

		if(file_exists($this->getHomeDir()."/.npm")) {
			$data = posix_getgrgid(filegroup($this->getHomeDir()."/.npm"));
			if($data['name'] != $webgroup) {
				out(sprintf(_("Home directory [%s] is not writable"),$this->getHomeDir()."/.npm"));
				return false;
			}
		}

		outn(_("Installing/Updating Required Libraries. This may take a while..."));
		if (php_sapi_name() == "cli") {
			out("The following messages are ONLY FOR DEBUGGING. Ignore anything that says 'WARN' or is just a warning");
		}

		$command = $this->generateRunAsAsteriskCommand('npm-cache -v');
		$process = new Process($command);
		try {
			$process->mustRun();
		} catch (ProcessFailedException $e) {
			$command = $this->generateRunAsAsteriskCommand('npm install -g npm-cache 2>&1');
			exec($command);
		}

		$command = $this->generateRunAsAsteriskCommand('npm-cache -v');
		$process = new Process($command);
		try {
			$process->mustRun();
		} catch (ProcessFailedException $e) {
			out($e->getMessage());
			return false;
		}

		file_put_contents($this->nodeloc."/logs/install.log","");

		$command = $this->generateRunAsAsteriskCommand('npm-cache install 2>&1');
		$handle = popen($command, "r");
		$log = fopen($this->nodeloc."/logs/install.log", "a");
		while (($buffer = fgets($handle, 4096)) !== false) {
			fwrite($log,$buffer);
			if (php_sapi_name() == "cli") {
				outn($buffer);
			} else {
				outn(".");
			}
		}
		fclose($log);
		out("");
		out(_("Finished updating libraries!"));
		if(!file_exists($this->nodeloc."/node_modules/pm2/bin/pm2")) {
			out("");
			out(sprintf(_("There was an error installing. Please review the install log. (%s)"),$this->nodeloc."/logs/install.log"));
			return false;
		}

		//PM2_HOME
	}

	public function uninstall() {
	}

	public function backup(){
	}

	public function restore($backup){
	}

	public static function myConfigPageInits() {
	}

	public function doConfigPageInit($page) {
	}

	public function getStatus($name) {
		$processes = $this->listProcesses();
		foreach($processes as $process) {
			if($process['name'] == $name) {
				return $process;
			}
		}
		return false;
	}

	public function start($name, $process) {
		$astlogdir = $this->freepbx->Config->get("ASTLOGDIR");
		$name = preg_replace("/\W\D/", "", $name);
		$this->runPM2Command("start ".$process." --name ".$name." -e ".$astlogdir."/".$name."_err.log -o ".$astlogdir."/".$name."_out.log --merge-logs");
	}

	public function stop($name) {
		$this->runPM2Command("stop ".$name);
	}

	public function chownFreepbx() {
		$files = array();
		return $files;
	}

	public function streamLog($app,$lines=15) {
		$command = $this->generateRunAsAsteriskCommand($this->nodeloc."/node_modules/pm2/bin/pm2 log ".$app." --lines ".$lines);
		$process = new Process($command);
		$process->setTty(true);
		$process->setTimeout(1325390892);
		$process->run(function ($type, $buffer) {
			if (Process::ERR === $type) {
				echo 'ERR > '.$buffer;
			} else {
				echo 'OUT > '.$buffer;
			}
		});
	}

	public function listProcesses() {
		$output = $this->runPM2Command("jlist");
		$data = json_decode($output,true);
		return $data;
	}

	private function runPM2Command($cmd) {
		$command = $this->generateRunAsAsteriskCommand($this->nodeloc."/node_modules/pm2/bin/pm2 ".$cmd);
		$process = new Process($command);
		$process->mustRun();
		return $process->getOutput();
	}

	/**
	 * Get the Asterisk Users Home Directory
	 * @method getHomeDir
	 * @return string     The home directory location
	 */
	private function getHomeDir() {
		$webuser = \FreePBX::Freepbx_conf()->get('AMPASTERISKWEBUSER');
		$web = posix_getpwnam($webuser);
		$home = trim($web['dir']);
		if (!is_dir($home)) {
			// Well, that's handy. It doesn't exist. Let's use ASTSPOOLDIR instead, because
			// that should exist and be writable.
			$home = \FreePBX::Freepbx_conf()->get('ASTSPOOLDIR');
			if (!is_dir($home)) {
				// OK, I give up.
				throw new \Exception(sprintf(_("Asterisk home dir (%s) doesn't exist, and, ASTSPOOLDIR doesn't exist. Aborting"),$home));
			}
		}
		return $home;
	}

	/**
	 * Generate run command string
	 * @method generateRunAsAsteriskCommand
	 * @param  string                       $command The command to run
	 * @return string                                The finalized command
	 */
	private function generateRunAsAsteriskCommand($command) {
		$webuser = $this->freepbx->Config->get('AMPASTERISKWEBUSER');
		$webgroup = $this->freepbx->Config->get('AMPASTERISKWEBGROUP');
		$webroot = $this->freepbx->Config->get("AMPWEBROOT");
		$varlibdir = $this->freepbx->Config->get("ASTVARLIBDIR");
		$astlogdir = $this->freepbx->Config->get("ASTLOGDIR");

		$cmds = array(
			'cd '.$this->nodeloc,
			'mkdir -p '.$this->pm2,
			'mkdir -p '.$this->nodeloc.'/logs',
			'export HOME="'.$this->getHomeDir().'"',
			'echo "prefix = ~/.node" > ~/.npmrc',
			'export PM2_HOME="'.$this->pm2.'"',
			'export ASTLOGDIR="'.$astlogdir.'"',
			'export PATH="$HOME/.node/bin:$PATH"',
			'export NODE_PATH="$HOME/.node/lib/node_modules:$NODE_PATH"',
			'export MANPATH="$HOME/.node/share/man:$MANPATH"'
		);
		$cmds[] = $command;
		$final = implode(" && ", $cmds);

		if (posix_getuid() == 0) {
			$final = "runuser -l ".escapeshellarg($webuser)." -c ".escapeshellarg($final);
		}
		return $final;
	}
}
