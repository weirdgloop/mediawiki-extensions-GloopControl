<?php

namespace MediaWiki\Extension\GloopControl\Tasks;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\User;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\ILoadBalancer;

class ReassignEditsTask {
	/** @var ILoadBalancer */
	private ILoadBalancer $lb;

	/** @var ActorNormalization */
	private ActorNormalization $actorNormalization;

	private array $reassignTables = [
		'revision' => 'rev_actor',
		'archive' => 'ar_actor',
		'recentchanges' => 'rc_actor'
	];

	public function __construct() {
		$services = MediaWikiServices::getInstance();
		$this->lb = $services->getDBLoadBalancer();
		$this->actorNormalization = $services->getActorNormalization();
	}

	/**
	 * Run the task.
	 * @param User $source user to take edits from
	 * @param User $target user to assign edits to
	 * @return Status
	 */
	public function run( User $source, User $target ) {
		$status = new Status();

		// Same as the reassignEdits.php maintenance script, with potentially less guard rails.
		if ( IPUtils::isIPAddress( $target->getName() ) ) {
			// see https://phabricator.wikimedia.org/T373914
			return $status->fatal( 'gloopcontrol-tasks-error-reassign-ip', $target );
		}

		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$fromActorId = $this->actorNormalization->findActorId( $source, $dbw );
		$toActorId = $this->actorNormalization->acquireActorId( $target, $dbw );

		foreach ( $this->reassignTables as $table => $col ) {
			$dbw->update( $table, [ $col => $toActorId ], [ $col => $fromActorId ], __METHOD__ );
		}

		if ( !$source->isRegistered() ) {
			$dbw->delete( 'ip_changes', [ 'ipc_hex' => IPUtils::toHex( $source->getName() ) ], __METHOD__ );
		}

		return $status::newGood( wfMessage( 'gloopcontrol-tasks-success-reassign', $source, $target ) );
	}
}
