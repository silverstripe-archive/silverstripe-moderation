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

	function ModerationState() {
		if ($this->owner->SpamScore >= $this->RequiredSpamScore) return 'spam';
		if ($this->owner->ModerationScore >= $this->RequiredModerationScore) return 'approved';
		return 'unapproved';
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

	function __construct($moderationScore = null, $spamScore = null) {
		parent::__construct();

		$this->RequiredModerationScore = $moderationScore ? $moderationScore : $this->stat('default_moderation_score');
		$this->RequiredSpamScore = $spamScore ? $spamScore : $this->stat('default_spam_score');
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

		if (ModeratableState::$state != "any") {
			$query->where['MSplit'] = sprintf(self::$wheres[ModeratableState::$state], $this->RequiredSpamScore, $this->RequiredModerationScore);
		}
	}
	
	function onModerationStateChange() {
		if (method_exists($this->owner, "onModerationStateChange")) $this->owner->onModerationStateChange($this->ModerationState());
	}
	
	function markApproved() {
		$old_state = $this->ModerationState();
		
		$this->owner->ModerationScore = $this->RequiredModerationScore;
		$this->owner->SpamScore = 0;
		$this->owner->write();

		if ($old_state != $this->ModerationState()) $this->onModerationStateChange();
	}

	function markUnapproved() {
		$old_state = $this->ModerationState();
				
		$this->owner->ModerationScore = 0;
		$this->owner->write();

		if ($old_state != $this->ModerationState()) $this->onModerationStateChange();
	}

	function markSpam() {
		$old_state = $this->ModerationState();
				
		$this->owner->SpamScore = $this->RequiredSpamScore;
		$this->owner->ModerationScore = 0; // When marked as spam, item loses it's moderation approval
		$this->owner->write();
		
		if ($old_state != $this->ModerationState()) $this->onModerationStateChange();
	}
	
	function markHam() {
		$old_state = $this->ModerationState();
				
		$this->owner->SpamScore = 0;
		$this->owner->write();
		
		if ($old_state != $this->ModerationState()) $this->onModerationStateChange();
	}
}