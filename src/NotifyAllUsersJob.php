<?php

namespace MediaWiki\Extension\GloopControl;

use GenericParameterJob;
use Job;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

class NotifyAllUsersJob extends Job implements GenericParameterJob {

	/** @var IDatabase|null */
	private $dbr;

	/** @var IDatabase|null */
	private $dbw;

	public function __construct( array $params ) {
		parent::__construct( 'NotifyAllUsersJob', $params );
		$this->executionFlags |= self::JOB_NO_EXPLICIT_TRX_ROUND;

		$this->dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA, 'vslow' );
	}

	public function run() {
		// To avoid memory issues when sending to all users on a (large) wiki, we're going to create a separate
		// event for a batch of users.
		$max = $this->dbr->newSelectQueryBuilder()
			->select( 'max(user_id)' )
			->from( 'user' )
			->caller( __METHOD__ )
			->fetchField();

		$batchSize = 500;
		for ( $start = 1; $start <= $max; $start += $batchSize ) {
			Event::create( [
				'type' => $this->params['type'],
				'agent' => MediaWikiServices::getInstance()->getUserFactory()->newFromId( $this->params['agent'] ),
				'title' => null,
				'extra' => [
					'recipients' => [],
					'header' => $this->params['header'],
					'content' => $this->params['content'],
					'target_type' => 'all_users',
					'url' => $this->params['url'],
					'icon' => $this->params['icon'],
					'start' => $start,
					'end' => $start + $batchSize - 1
				]
			] );
		}
	}
}
