<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * Kohana_Minion_Daemon class
 * Creates a CLI Daemon using minion
 *
 * @link http://pastebin.com/GTgw4uVR
 * @abstract
 * @extends Minion_Task
 */
abstract class Kohana_Minion_Daemon extends Minion_Task {

	// Process constants
	const PARENT_PROC	= 0;
	const CHILD_PROC 	= 1;

	/**
	 * @var boolean Received stop signal?
	 * @access protected
	 */
	protected $_terminate = FALSE;

	/**
	 * @var int Sleep time between loops, in ms.  Default to 1s
	 * @access protected
	 */
	protected $_sleep = 1000000;

	/**
	 * @var boolean Break the loop on an exception?
	 * @access protected
	 */
	protected $_break_on_exception = TRUE;

	/**
	 * @var int How many iterations before we run the cleanup method?
	 * @access protected
	 */
	protected $_cleanup_iterations = 1000;

	/**
	 * @var boolean PHP >5.3 garbage collection enabled?
	 * @access protected
	 */
	protected $_gc_enabled = FALSE;

	/**
	 * @var array CLI arguments
	 * @access protected
	 */
	protected $_daemon_options = array(
		"fork" => TRUE,
		"pid" => "/tmp/minion-daemon-{worker}.pid",
		"exit" => TRUE,
        "worker" => 1
	);

	/**
	 * @var string PID filename
	 * @access protected
	 */
	protected $_pid = NULL;
	
	/**
	 * @var logger object
	 * @access protected
	 */
	protected $_logger = NULL;


	/**
	 * Sets up the daemon task
	 *
	 * @access public
	 * @return void
	 */
	public function __construct()
	{
		// No time limit on minion daemon tasks
		set_time_limit(0);

		// Merge configs
		$this->_options = Arr::merge($this->_daemon_options, $this->_options);

        // Check PID
        $this->_setup_pid($this->_options);

		parent::__construct();

		if ( ! function_exists('pcntl_signal_dispatch'))
		{
			// PHP < 5.3 uses ticks to handle signals instead of pcntl_signal_dispatch
			// call sighandler only every 10 ticks
			declare(ticks = 10);
		}

		// Make sure PHP has support for pcntl
		if ( ! function_exists('pcntl_signal'))
		{
			$message = 'PHP does not appear to be compiled with the PCNTL extension.  This is neccesary for daemonization';

			Kohana::$log->add(Log::ERROR,$message);
			throw new Kohana_Exception($message);
		}

        pcntl_signal(SIGTERM, array($this, 'handle_signals'));
		pcntl_signal(SIGINT, array($this, 'handle_signals'));
		pcntl_signal(SIGQUIT, array($this, 'handle_signals'));

		// Enable PHP 5.3 garbage collection
		if (function_exists('gc_enable'))
		{
			gc_enable();
			$this->_gc_enabled = gc_enabled();
		}

		while (ob_get_level())
		{
			// Flush all output buffers (so echo statements work)
			ob_end_flush();
		}
	}

	/**
	 * Handle PCNTRL signals
	 *
	 * @access public
	 * @param mixed $signal
	 * @return void
	 */
	public function handle_signals($signal)
	{
		// We don't want to exit the script prematurely
		switch ($signal)
		{
			case SIGINT:
			case SIGTERM:
			case SIGQUIT:
				$this->_terminate = TRUE;
				break;
			default:
				Kohana::$log->add(Log::ERROR, 'signal :signal is unhandled. Terminating', array(
					':signal' => $signal,
				));
				$this->_terminate = TRUE;
		}
	}

	/**
	 * Create PID file
	 * 
	 * @access protected
	 * @param array $config
	 * @return void
	 */
	protected function _setup_pid(array $config)
	{
        if ( Arr::get($config, 'pid'))
		{
			// add worker ID to pid file
            $this->_pid = strtr($config['pid'], array(
                '{worker}' => Arr::get($config, 'worker', 1)
            ));

            if ( file_exists($this->_pid))
			{
				$pid = file_get_contents($this->_pid);
				echo "Daemon already running with a PID of $pid";
				exit(0);
			}
		}
	}
	
	/**
	 * Execute minion task.
	 * @return void
	 */
	protected function _execute(array $config)
	{
		// Should we fork this daemon?
		if (array_key_exists('fork', $config) AND $config['fork'] == TRUE)
		{
			if ($this->_fork() == Minion_Daemon::PARENT_PROC)
			{
				// We're in the parent process
				return;
			}
		}
		
        if ( $this->_pid) {
            // Set the pid if required
            file_put_contents( $this->_pid, getmypid());
        }

		// Setup loop
		$this->before($config);

		// Count the number of iterations.  Used for cleanup
		$iterations = 0;

		// Launch loop
		while (TRUE)
		{
			// Dispatching signals every loop.  PHP < 5.3 handles via ticks
			if (function_exists('pcntl_signal_dispatch'))
			{
				pcntl_signal_dispatch();
			}
		
			// End the process if we received a signal since the last loop
			if ($this->_terminate)
				break;

			// Increment iteration counter
			$iterations++;

			// Trigger heartbeat
            $this->heartbeat($config);

			// Execute loop statement in try catch block
			try
			{
				$result = $this->loop($config);
			}
			catch(Exception $e)
			{
				$result = $this->_handle_exception($e);

				// Should we exit?
				if ($this->_break_on_exception)
					break;
			}

            // End the process if we received a signal or the loop returned false
            if ($this->_terminate OR $result === FALSE)
            	break;

            // Do we need to do any cleanup?
            if ($iterations == $this->_cleanup_iterations)
            {
            	$this->_cleanup($config);

            	// Reset iterations counter
            	$iterations = 0;
            }

             // Memory management
            unset($result);

            // Pause before next execution
            usleep($this->_sleep);
		}

		// Cleanup
		$this->after($config);

		// Remove PID file
		if ($this->_pid)
		{
			unlink($this->_pid);
		}

        // Write log
        Kohana::$log->add(Log::NOTICE, 'Task worker :id exiting', array(
            ':id' => $this->_options['worker']
        ));
        Kohana::$log->write();

		// If possible, exit rather than return to keep a clean output
		if ( Arr::get($config, 'exit') === TRUE)
		{
			exit(0);
		}
	}

	/**
	 * Main process.
	 *
	 * Return FALSE or set $this->_terminate = TRUE to break out of the loop
	 *
	 * Since this loop runs over and over, be careful of memory usage
	 *
	 * @access public
	 * @abstract
	 * @param array $config
	 * @return boolean
	 */
	abstract public function loop(array $config);

	/**
	 * Runs once to perform any set up tasks before the loop begins
	 *
	 * @access public
	 * @param array $config
	 * @return void
	 */
	public function before(array $config)
	{}

	/**
	 * Runs once to perform any tear down tasks after the loop exits
	 *
	 * @access public
	 * @param array $config
	 * @return void
	 */
	public function after(array $config)
	{}

	/**
	 * Runs once on every loop
	 * Can be used to set a value that ensures the process is functioning
	 *
	 * @access public
	 * @param array $config
	 * @return void
	 */
	public function heartbeat(array $config)
	{}


	/**
	 * Wrapper for $this->_terminate
	 *
	 * @access public
	 * @return void
	 */
	public function terminate()
	{
		$this->_terminate = TRUE;
	}

	/**
	 * Lightweight exception handler
	 *
	 * @access protected
	 * @param Exception $e
	 * @return void
	 */
	protected function _handle_exception(Exception $e)
	{
		Kohana::$log->add(Log::ERROR,Kohana_Exception::text($e));
	}

	/**
	 * Fork the process
	 * Exits the parent process
	 *
	 * @access protected
	 * @return constant
	 */
	protected function _fork()
	{
		// Fork the current process
		$pid = pcntl_fork();

		if ($pid == -1)
		{
			$message = "Failed to fork process.  Exiting.";

			Kohana::$log->add(Log::ERROR,$message);
			throw new Kohana_Exception($message);
		}
		elseif ($pid)
		{
			// This is the parent process.
			Kohana::$log->add(Log::NOTICE,"Worker :id forked with a PID of $pid", array(
                ':id' => $this->_options['worker']
            ));
			echo "Process forked with a PID of $pid\n";

			return Minion_Daemon::PARENT_PROC;
		}

		return Minion_Daemon::CHILD_PROC;
	}

	/**
	 * Runs some cleanup tasks once every N iterations
	 * Should be used to control memory, logging, etc
	 *
	 * @access protected
	 * @param array $config
	 * @return void
	 */
	protected function _cleanup(array $config)
	{
		// Refresh stat cache
		clearstatcache();

		// Force Kohana to write logs.  Otherwise, memory will continue to grow
		Kohana::$log->write();

		// Garbage collection
		if ($this->_gc_enabled)
		{
			gc_collect_cycles();
		}

		// Log memory usage for monitoring purposes
		Kohana::$log->add(Log::INFO,"Running _cleanup().  Current memory usage: :memory bytes.",array(":memory" => memory_get_usage()));
	}
}
