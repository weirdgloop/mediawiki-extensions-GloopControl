<?php

namespace MediaWiki\Extension\GloopControl\Jobs;

use Job;
use MediaWiki\Extension\GloopControl\Tasks\AnonymiseUserTask;
use MediaWiki\Page\DeletePageFactory;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\RenameUser\RenameUserFactory;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;
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
		DeletePageFactory $deletePageFactory,
		private readonly RenameUserFactory $renameUserFactory
	) {
		parent::__construct( 'AnonymiseUserJob', $params );
		$this->userFactory = $userFactory;
		$this->lbFactory = $lbFactory;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->deletePageFactory = $deletePageFactory;
	}

	public function run() {
		$oldName = $this->params['oldname'];
		$newName = $this->params['newname'];
		$needsRenameUser = $this->params['needsRenameUser'] ?? false;

		$oldUser = $this->userFactory->newFromName( $this->params['oldname'] );
		$oldUser->load( IDBAccessObject::READ_LATEST );

		$user = $this->userFactory->newFromName( $this->params['newname'] );
		$user->load( IDBAccessObject::READ_LATEST );

		$systemUser = User::newSystemUser( 'Weird Gloop', [ 'steal' => true ] );

		// Finish the user rename operation. Normally, this would be done via RenameUserDerivedJob, but we'd be racing
		// with it. (WG-430)
		if ( $needsRenameUser && $this->userFactory->isUserTableShared() ) {
			// Clear local cache for the user
			$user->invalidateCache();
			// Run the local rename work
			$rename = $this->renameUserFactory->newDerivedRenameUser(
				$systemUser,
				$user->getId(),
				$this->params['oldname'],
				$this->params['newname'],
				AnonymiseUserTask::RENAME_USER_REASON,
				[ 'movePages' => false ]
			);
			$status = $rename->renameLocal();
			if ( !$status->isGood() ) {
				$this->setLastError(
					"Cannot finish derived local user rename from $oldName to $newName: $status"
				);
				return false;
			}
		}

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
				$systemUser
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
