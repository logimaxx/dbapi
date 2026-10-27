<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CodeIgniter's file logger never reaches `docker logs`.
 * Send the same lines to PHP's error_log (php-fpm stderr).
 */
class MY_Log extends CI_Log {

	public function __construct()
	{
		parent::__construct();
		$this->_enabled = TRUE;
	}

	public function write_log($level, $msg)
	{
		$level = strtoupper($level);

		if (( ! isset($this->_levels[$level]) OR ($this->_levels[$level] > $this->_threshold))
			&& ! isset($this->_threshold_array[$this->_levels[$level]]))
		{
			return FALSE;
		}

		error_log(rtrim($this->_format_line($level, date($this->_date_fmt), $msg)));
		return TRUE;
	}
}
