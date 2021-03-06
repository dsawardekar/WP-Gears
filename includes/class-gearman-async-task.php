<?php

class Gearman_Async_Task extends WP_Async_Task {

	/**
	 * @var GearmanClient
	 */
	protected $_client;

	/**
	 * @var GearmanWorker
	 */
	protected $_worker;

	public function init() {
		// Only use gearman implementation when WP_GEARS is defined and true
		if ( ! defined( 'WP_GEARS' ) || ! WP_GEARS ) {
			return false;
		}
		global $gearman_servers;

		if ( ! class_exists( 'GearmanClient' ) || ! class_exists( 'GearmanWorker' ) ) {
			return false;
		}

		if ( defined( 'DOING_ASYNC' ) && DOING_ASYNC ) {
			$this->_worker = new GearmanWorker();
			$this->_client = new GearmanClient();

			if ( empty( $gearman_servers ) ) {
				$this->_client->addServer();
				return $this->_worker->addServer();
			} else {
				$this->_client->addServers( implode( ',', $gearman_servers ) );
				return $this->_worker->addServers( implode( ',', $gearman_servers ) );
			}
		} else {
			$this->_client = new GearmanClient();
			if ( empty( $gearman_servers ) ) {
				$this->_client->addServer();
			} else {
				$this->_client->addServers( implode( ',', $gearman_servers ) );
			}

			// Supressing errors, because this will return true or false, depending on if we could connect & communicate
			return @$this->_client->ping( 'test' );
		}
	}

	public function add( $hook, $args = array(), $priority = 'normal' ) {
		$jobdata = array();
		$jobdata['hook'] = $hook;
		$jobdata['args'] = $args;
		$jobdata['blog_id'] = ( function_exists( 'is_multisite' ) && is_multisite() ) ? get_current_blog_id() : null;

		switch( $priority ) {
			case 'high':
				return $this->_client->doHighBackground( $this->gearman_function(), json_encode( $jobdata ) );
				break;
			case 'low':
				return $this->_client->doLowBackground( $this->gearman_function(), json_encode( $jobdata ) );
				break;
			case 'normal':
			default:
				return $this->_client->doBackground( $this->gearman_function(), json_encode( $jobdata ) );
				break;
		}
	}

	/**
	 * Returns the gearman function group for this install
	 *
	 * @return string
	 */
	public function gearman_function() {
		$key = '';

		if ( WP_ASYNC_TASK_SALT ) {
			$key .= WP_ASYNC_TASK_SALT . ':';
		}

		$key .= 'WP_Async_Task';

		return $key;
	}


	/* Task Runner */
	public function work() {
		$this->_worker->addFunction( $this->gearman_function(), array( $this, 'do_job' ) );

		$this->_worker->work();

		// Killing after one job, so we don't run into unexpected behaviors or memory issues. Supervisord will respawn the php processes
		die();
	}

	public function do_job( $job ) {
		$job_data = json_decode( $job->workload(), true );

		if ( function_exists( 'is_multisite' ) && is_multisite() && $job_data['blog_id'] ) {
			switch_to_blog( $job_data['blog_id'] );
		}

		do_action( 'wp_async_task_before_job', $job_data['hook'] );
		do_action( 'wp_async_task_before_job_' . $job_data['hook'] );

		do_action( $job_data['hook'], $job_data['args'] );

		do_action( 'wp_async_task_after_job', $job_data['hook'] );
		do_action( 'wp_async_task_after_job_' . $job_data['hook'] );

		return true;
	}
}

