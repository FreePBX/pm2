<?php
namespace FreePBX\Console\Command;
//Symfony stuff all needed add these
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
//Ask stuff
use Symfony\Component\Console\Question\ChoiceQuestion;
//la mesa
use Symfony\Component\Console\Helper\Table;

use Symfony\Component\Console\Command\HelpCommand;

class Pm2 extends Command {
	protected function configure(){
		$this->setName('pm2')
		->setDescription(_('Manage long running processes'))
		->setDefinition(array(
			new InputOption('list', null, InputOption::VALUE_NONE, _('list processes')),
			new InputOption('log', null, InputOption::VALUE_REQUIRED, _('stream logs')),
			new InputOption('lines', 15, InputOption::VALUE_REQUIRED, _('stream logs'))
		));
	}
	protected function execute(InputInterface $input, OutputInterface $output){
		if($input->getOption('list')){
			$data = \FreePBX::Pm2()->listProcesses();
			$table = new Table($output);
			$table->setHeaders(array(_('App name'),'PID',_('Status'),_('Restart'),_("Uptime"), _("CPU"),_("Mem")));
			$rows = array();
			foreach($data as $process) {
				$time = ($process['pm2_env']['status'] == 'online') ? $this->get_date_diff(time(),(int)round($process['pm2_env']['created_at']/1000)) : 0;
				$rows[] = array(
					$process['name'],
					$process['pid'],
					$process['pm2_env']['status'],
					$process['pm2_env']['restart_time'],
					$time,
					$process['monit']['cpu'].'%',
					$this->human_filesize($process['monit']['memory']),
				);
			}
			$table->setRows($rows);
			$table->render();
			return;
		}
		if($input->getOption('log')){
			$app = $input->getOption('log');
			\FreePBX::Pm2()->streamLog($app,$input->getOption('lines'));
			return;
		}
		$this->outputHelp($input,$output);
	}
	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 * @throws \Symfony\Component\Console\Exception\ExceptionInterface
	 */
	protected function outputHelp(InputInterface $input, OutputInterface $output)	 {
		$help = new HelpCommand();
		$help->setCommand($this);
		return $help->run($input, $output);
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
