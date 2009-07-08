<?php

class Moderatable extends DataObjectDecorator {
	static $_state = null;

	// Filter conditions per state. These are used by augmentSQL, which currently assumes the first $F is the spam score threshold and the second
	// is the moderationScore threshold.
	static $wheres = array(
		'approved'   => '((SpamScore < %F OR SpamScore IS NULL) AND ModerationScore >= %F)',
		'unapproved' => '((SpamScore < %F OR SpamScore IS NULL) AND (ModerationScore < %F OR ModerationScore IS NULL))',
		'spam'       => '(SpamScore >= %F)',
	);

	public function ModerationState() {
		return ModeratableState::moderation_state($this, $this->owner);
	}
	
	public function isApproved() {
		return $this->ModerationState() == 'approved';
	}

	public function isUnapproved() {
		return $this->ModerationState() == 'unapproved';
	}

	public function isSpam() {
		return $this->ModerationState() == 'spam';
	}
	
	function extraStatics() {
		return array(
			'db' => array(
				'SpamScore' => 'Float',
				'ModerationScore' => 'Float'
			),
			'defaults' => array(
				'SpamScore' => 0,
				'ModerationScore' => 0
			)
		);
	}

	function __construct($moderation_score = null, $spam_score = null) {
		parent::__construct();

		$this->required_moderation_score = $moderation_score ? $moderation_score : $this->stat('default_moderation_score');
		$this->required_spam_score = $spam_score ? $spam_score : $this->stat('default_spam_score');
	}
	
	// Augment the SQL to only return items in the current moderation state.
	function augmentSQL(SQLQuery &$query) {
		// If its disjunctive, throw an error. That would mean all approved objects would be
		// included on all queries. The way SQLQuery works doesn't let us easily mix operators
		// especially if there are other augmentSQL methods. The idea solution is to restructure
		// the query so that the existing disjunction is bracketed and the query is converted to
		// a disjunctive query. Best practice dictates we should also be idempotent, but that's
		// beyond a 3 banana problem.
		if ($query->connective == "OR") throw new Exception("Moderatable can't filter on a disjunctive query");

		if (self::state()->moderationState != "any") {
			$query->where['MSplit'] = sprintf(self::$wheres[self::state()->moderationState], $this->required_spam_score, $this->required_moderation_score);
		}
	}
	
	function markApproved() {
		ModeratableState::mark_approved($this, $this->owner);
	}

	function markUnapproved($className, $id) {
		ModeratableState::mark_unapproved($this, $this->owner);
	}

	function markSpam($className, $id) {
		ModeratableState::mark_spam($this, $this->owner);
	}
	
	function markHam($className, $id) {
		ModeratableState::mark_ham($this, $this->owner);
	}
}