<?php

namespace MediaWiki\Extension\GloopControl;

class GloopControlSubpage {
	protected SpecialGloopControl $special;

	public function __construct( SpecialGloopControl $special ) {
		$this->special = $special;
		$this->execute();
	}

	protected function execute() {
	}
}
