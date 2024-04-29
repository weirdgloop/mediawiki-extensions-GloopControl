<?php

namespace MediaWiki\Extension\GloopControl;

class GloopControlSubpage {

	protected SpecialGloopControl $special;

	function __construct( SpecialGloopControl $special ) {
		$this->special = $special;
		$this->execute();
	}

	protected function execute() {}
}
