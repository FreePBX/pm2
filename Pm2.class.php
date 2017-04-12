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
	private $pm2Home = "/tmp";
	private $nodeloc = "/tmp";

	public function __construct($freepbx = null) {
		$this->astman = $freepbx->astman;
		$this->db = $freepbx->Database;
		$this->freepbx = $freepbx;
		$this->pm2Home = $this->getHomeDir() . "/.pm2";
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

		$this->runPM2Command("ping");
		$this->runPM2Command("update");
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

	/**
	 * Get status of a process
	 * @method getStatus
	 * @param  string    $name The process name
	 * @return mixed          Return array of data if known or false if unknown
	 */
	public function getStatus($name) {
		$name = $this->cleanAppName($name);
		$processes = $this->listProcesses();
		foreach($processes as $process) {
			if($process['name'] == $name) {
				return $process;
			}
		}
		return false;
	}

	/**
	 * Start an application in the background
	 * @method start
	 * @param  string $name    The name of the application
	 * @param  string $process The process to run
	 * @return mixed           Output of getStatus
	 */
	public function start($name, $process) {
		$name = $this->cleanAppName($name);
		$pout = $this->getStatus($name);
		if(!empty($pout) && $pout['pm2_env']['status'] == 'online') {
			throw new \Exception("There is already a process by that name running!");
		}
		$astlogdir = $this->freepbx->Config->get("ASTLOGDIR");
		$cwd = dirname($process);
		$this->runPM2Command("start ".$process." --name ".escapeshellarg($name)." -e ".escapeshellarg($astlogdir."/".$name."_err.log")." -o ".escapeshellarg($astlogdir."/".$name."_out.log")." --merge-logs --log-date-format 'YYYY-MM-DD HH:mm Z'", $cwd);
		return $this->getStatus($name);
	}

	/**
	 * Stop an application from running in the background
	 * @method stop
	 * @param  string $name The application name
	 */
	public function stop($name) {
		$name = $this->cleanAppName($name);
		$out = $this->getStatus($name);
		if(empty($out)) {
			throw new \Exception("There is no process by that name");
		}
		$this->runPM2Command("stop ".$name);
	}

	/**
	 * Restart process
	 * @method restart
	 * @param  string  $name The application name
	 */
	public function restart($name) {
		$name = $this->cleanAppName($name);
		$out = $this->getStatus($name);
		if(empty($out)) {
			throw new \Exception("There is no process by that name");
		}
		$this->runPM2Command("restart ".$name);
	}

	/**
	 * Delete process
	 * @method delete
	 * @param  string  $name The application name
	 */
	public function delete($name) {
		$name = $this->cleanAppName($name);
		$out = $this->getStatus($name);
		if(empty($out)) {
			throw new \Exception("There is no process by that name");
		}
		$this->runPM2Command("delete ".$name);
	}

	/**
	 * Update the underlying PM2 process
	 * @method update
	 */
	public function update() {
		$this->runPM2Command("update",true);
	}

	/**
	 * List Processes that PM2 is maintaining
	 * @method listProcesses
	 * @return array        Array of processes
	 */
	public function listProcesses() {
		$output = $this->runPM2Command("jlist");
		$processes = json_decode($output,true);
		$final = array();
		foreach($processes as $process) {
			$process['pm2_env']['created_at_human_diff'] = ($process['pm2_env']['status'] == 'online') ? $this->get_date_diff(time(),(int)round($process['pm2_env']['created_at']/1000)) : 0;
			$process['monit']['human_memory'] = $this->human_filesize($process['monit']['memory']);
			$final[] = $process;
		}
		return $final;
	}

	/**
	 * Run a command against PM2
	 * @method runPM2Command
	 * @param  string        $cmd    The command to run
	 * @param  boolean       $stream Whether to stream the output or return it
	 */
	private function runPM2Command($cmd,$cwd='',$stream=false) {
		$command = $this->generateRunAsAsteriskCommand($this->nodeloc."/node_modules/pm2/bin/pm2 ".$cmd,$cwd);
		$process = new Process($command);
		if(!$stream) {
			$process->mustRun();
			return $process->getOutput();
		} else {
			$process->setTty(true);
			$process->setTimeout(60);
			$process->run(function ($type, $buffer) {
				if (Process::ERR === $type) {
					echo $buffer;
				} else {
					echo $buffer;
				}
			});
		}
	}

	public function chownFreepbx() {
		$files = array();
		$files[] = array('type' => 'execdir',
			'path' => __DIR__.'/node/node_modules/pm2/bin',
			'perms' => 0755);
		$files[] = array('type' => 'rdir',
			'path' => $this->pm2Home,
			'perms' => 0775);
		return $files;
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
	private function generateRunAsAsteriskCommand($command,$cwd='') {
		$webuser = $this->freepbx->Config->get('AMPASTERISKWEBUSER');
		$webgroup = $this->freepbx->Config->get('AMPASTERISKWEBGROUP');
		$webroot = $this->freepbx->Config->get("AMPWEBROOT");
		$varlibdir = $this->freepbx->Config->get("ASTVARLIBDIR");
		$astlogdir = $this->freepbx->Config->get("ASTLOGDIR");

		$cmds = array(
			'cd '.(!empty($cwd) ? $cwd : $this->nodeloc),
			'mkdir -p '.$this->pm2Home,
			'mkdir -p '.$this->nodeloc.'/logs',
			'export HOME="'.$this->getHomeDir().'"',
			'echo "prefix = ~/.node" > ~/.npmrc',
			'export PM2_HOME="'.$this->pm2Home.'"',
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

	/**
	 * Turn all spaces into underscores and remove all utf8
	 * from filenames
	 * @param  string $name The filename
	 * @return string       The cleaned filename
	 */
	private function cleanAppName($name) {
		$name = pathinfo($name,PATHINFO_FILENAME);
		$name = str_replace(" ","-",$name);
		if(function_exists('iconv')) {
			$name = iconv("UTF-8", "ISO-8859-1//TRANSLIT", $name);
		}
		$name = preg_replace('/\s+|\'+|\\\+|\$+|`+|\"+|<+|>+|\?+|\*+|,+|\.+|&+|;+|\/+/','-',$name);
		$name = preg_replace('/[\x00-\x1F\x80-\xFF]/u', '', $name);
		return $name;
	}

	/**
	 * Get human readable time difference between 2 dates
	 *
	 * Return difference between 2 dates in year, month, hour, minute or second
	 * The $precision caps the number of time units used: for instance if
	 * $time1 - $time2 = 3 days, 4 hours, 12 minutes, 5 seconds
	 * - with precision = 1 : 3 days
	 * - with precision = 2 : 3 days, 4 hours
	 * - with precision = 3 : 3 days, 4 hours, 12 minutes
	 *
	 * From: http://www.if-not-true-then-false.com/2010/php-calculate-real-differences-between-two-dates-or-timestamps/
	 *
	 * @param mixed $time1 a time (string or timestamp)
	 * @param mixed $time2 a time (string or timestamp)
	 * @param integer $precision Optional precision
	 * @return string time difference
	 */
	private function get_date_diff( $time1, $time2, $precision = 2 ) {
		// If not numeric then convert timestamps
		if( !is_int( $time1 ) ) {
			$time1 = strtotime( $time1 );
		}
		if( !is_int( $time2 ) ) {
			$time2 = strtotime( $time2 );
		}
		// If time1 > time2 then swap the 2 values
		if( $time1 > $time2 ) {
			list( $time1, $time2 ) = array( $time2, $time1 );
		}
		// Set up intervals and diffs arrays
		$intervals = array( 'year', 'month', 'day', 'hour', 'minute', 'second' );
		$diffs = array();
		foreach( $intervals as $interval ) {
			// Create temp time from time1 and interval
			$ttime = strtotime( '+1 ' . $interval, $time1 );
			// Set initial values
			$add = 1;
			$looped = 0;
			// Loop until temp time is smaller than time2
			while ( $time2 >= $ttime ) {
				// Create new temp time from time1 and interval
				$add++;
				$ttime = strtotime( "+" . $add . " " . $interval, $time1 );
				$looped++;
			}
			$time1 = strtotime( "+" . $looped . " " . $interval, $time1 );
			$diffs[ $interval ] = $looped;
		}
		$count = 0;
		$times = array();
		foreach( $diffs as $interval => $value ) {
			// Break if we have needed precission
			if( $count >= $precision ) {
				break;
			}
			// Add value and interval if value is bigger than 0
			if( $value > 0 ) {
				if( $value != 1 ){
					$interval .= "s";
				}
				// Add value and interval to times array
				$times[] = $value . " " . $interval;
				$count++;
			}
		}
		// Return string with times
		return implode( ", ", $times );
	}

	function human_filesize($bytes, $dec = 2) {
		$size   = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
		$factor = floor((strlen($bytes) - 1) / 3);

		return sprintf("%.{$dec}f", $bytes / pow(1024, $factor)) . @$size[$factor];
	}
}
