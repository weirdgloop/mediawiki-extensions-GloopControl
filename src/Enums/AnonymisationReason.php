<?php

namespace MediaWiki\Extension\GloopControl\Enums;

enum AnonymisationReason: string {
	case REQUESTED = 'requested';
	case TOS_GENERIC = 'tos_generic';
	case TOS_UNDERAGE = 'tos_underage';
}
