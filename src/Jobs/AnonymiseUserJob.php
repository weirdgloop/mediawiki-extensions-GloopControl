<?php

namespace MediaWiki\Extension\GloopControl\Jobs;

use Job;
use MediaWiki\Page\DeletePageFactory;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LBFactory;

/**
 * Job to anonymise a user, which runs on every derived wiki database, similar to the renameUserDerived job.
 *
 * This may try to redo work that is already done, if some of the tables listed below are in $wgSharedDB. However, this
 * shouldn't be an issue - the job will just silently move on.
 */
class AnonymiseUserJob extends Job {
	private UserFactory $userFactory;

	private LBFactory $lbFactory;

	private WikiPageFactory $wikiPageFactory;

	private DeletePageFactory $deletePageFactory;

	public function __construct(
		Title $title,
		array $params,
		UserFactory $userFactory,
		LBFactory $lbFactory,
		WikiPageFactory $wikiPageFactory,
		DeletePageFactory $deletePageFactory
	) {
		parent::__construct( 'AnonymiseUserJob', $params );
		$this->userFactory = $userFactory;
		$this->lbFactory = $lbFactory;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->deletePageFactory = $deletePageFactory;
	}

	public function run() {
		$oldUser = $this->userFactory->newFromName( $this->params['oldname'] );
		$user = $this->userFactory->newFromName( $this->params['newname'] );

		// Delete any potential PII in the database
		$actorId = $user->getActorId();
		$rowsToDelete = [
			'logging' => [
				// Delete any logs related to the rename
				[ 'log_action' => 'renameuser', 'log_title' => $oldUser->getTitleKey(), 'log_type' => 'renameuser' ]
			],
			'recentchanges' => [
				// Delete any logs related to the rename
				[ 'rc_log_action' => 'renameuser', 'rc_title' => $oldUser->getTitleKey(), 'rc_log_type' => 'renameuser' ]
			],
			// CheckUser PII
			'cu_changes' => [
				[ 'cuc_actor' => $actorId ]
			],
			'cu_log' => [
				[ 'cul_actor' => $actorId ],
				[ 'cul_target_id' => $actorId, 'cul_type' => [ 'useredits', 'userips' ] ]
			]
		];

		$dbw = $this->lbFactory->getMainLB()->getMaintenanceConnectionRef( DB_PRIMARY );

		// Delete user pages - similar to miraheze/RemovePII
		$userPage = $oldUser->getUserPage();
		$rows = $dbw->newSelectQueryBuilder()
			->table( 'page' )
			->fields( [ 'page_id', 'page_namespace', 'page_title' ] )
			->where( [
				'page_namespace IN (' . implode( ',', [ NS_USER, NS_USER_TALK ] ) . ')',
				'(page_title ' . $dbw->buildLike( $userPage->getDBkey() . '/', $dbw->anyString() ) .
				' OR page_title = ' . $dbw->addQuotes( $userPage->getDBkey() ) . ')',
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $rows as $row ) {
			$deletePage = $this->deletePageFactory->newDeletePage(
				$this->wikiPageFactory->newFromID( $row->page_id ),
				User::newSystemUser( 'Weird Gloop', [ 'steal' => true ] )
			);
			$status = $deletePage->setSuppress( true )->forceImmediate( true )->deleteUnsafe( '' );
			if ( !$status->isOK() ) {
				$this->setLastError( "Could not delete " . $row->page_title . " in namespace " . $row->page_namespace );
			}
		}

		foreach ( $rowsToDelete as $key => $value ) {
			if ( !$dbw->tableExists( $key, __METHOD__ ) ) {
				continue;
			}

			foreach ( $value as $where ) {
				try {
					$method = __METHOD__;
					$dbw->doAtomicSection( $method, static function () use ( $dbw, $key, $where, $method ) {
						$dbw->newDeleteQueryBuilder()
							->deleteFrom( $key )
							->where( $where )
							->caller( $method )
							->execute();
					}, IDatabase::ATOMIC_CANCELABLE );
					$this->lbFactory->waitForReplication();
				} catch ( DBQueryError $e ) {
					$this->setLastError( $e->getMessage() );
				}
			}
		}
	}
}
