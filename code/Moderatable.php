<?php

class Moderatable extends DataObjectDecorator {
	
	static $default_moderation_score = 1.0;
	static $default_spam_score = 10.0;
	
	static $wheres = array(
		'approved'   => '((SpamScore < %F OR SpamScore IS NULL) AND ModerationScore >= %F)',
		'unapproved' => '((SpamScore < %F OR SpamScore IS NULL) AND (ModerationScore < %F OR ModerationScore IS NULL))',
		'spam'       => '(SpamScore >= %F)',
	);

	// Return true if instance is approved. Logic needs to reflect SQL logic in $wheres above.
	function isApproved() {
		return $this->owner->SpamScore < $this->required_spam_score &&
			   $this->owner->ModerationScore >= $this->required_moderation_score;
	}

	// Return true if instance is unapproved. Logic needs to reflect SQL logic in $wheres above.
	function isUnapproved() {
		return $this->owner->SpamScore < $this->required_spam_score &&
			   $this->owner->ModerationScore < $this->required_moderation_score;
	}

	// Return true if instance is spam. Logic needs to reflect SQL logic in $wheres above.
	function isSpam() {
		return $this->owner->SpamScore >= $this->required_spam_score;
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
		$this->required_moderation_score = $moderation_score ? $moderation_score : $this->stat('default_moderation_score');
		$this->required_spam_score = $spam_score ? $spam_score : $this->stat('default_spam_score');
	}
	
	function MarkApproved() {
		$this->owner->ModerationScore = $this->required_moderation_score;
		$this->owner->SpamScore = 0;
		$this->owner->write();

		if (method_exists($this->owner, "onAfterApprove")) {
			$this->owner->onAfterApprove();
		}
	}

	function AreApproved($filter = null, $sort = null, $join = null, $limit=null) {
		return DataObject::get(
			$this->owner->ClassName, 
			sprintf(self::$wheres['approved'], $this->required_spam_score, $this->required_moderation_score) . ($filter ? " AND ($filter)" : ''),
			$sort,
			$join,
			$limit
		);
	}
	
	function AreApprovedCount() {
		$table = array_pop(ClassInfo::dataClassesFor($this->owner->class));
		$res = DB::query("SELECT COUNT(*) FROM {$table} WHERE ".sprintf(self::$wheres['approved'], $this->required_spam_score, $this->required_moderation_score))->value();
		return $res ? $res : 0;
	}
	
	function MarkUnapproved() {
		$this->owner->ModerationScore = 0;
		$this->owner->write();

		if (method_exists($this->owner, "onAfterUnapprove")) {
			$this->owner->onAfterUnapprove();
		}
	}

	/**
	 * Called after unapproval. Override as required, but don't forget to call parent.
	 * $this is the decorator.
	 */
	protected function onAfterUnapprove() {
	}

	function AreUnapproved($filter = null, $sort = null, $join = null, $limit=null) {
		return DataObject::get(
			$this->owner->ClassName, 
			sprintf(self::$wheres['unapproved'], $this->required_spam_score, $this->required_moderation_score) . ($filter ? " AND ($filter)" : ''),
			$sort,
			$join,
			$limit
		);
	}
	
	function AreUnapprovedCount() {
		$table = array_pop(ClassInfo::dataClassesFor($this->owner->class));
		$res = DB::query("SELECT COUNT(*) FROM {$table} WHERE ".sprintf(self::$wheres['unapproved'], $this->required_spam_score, $this->required_moderation_score))->value();
		return $res ? $res : 0;
	}
	
	function MarkSpam() {
		$this->owner->SpamScore = $this->required_spam_score;
		$this->owner->ModerationScore = 0; // When marked as spam, item loses it's moderation approval
		$this->owner->write();
	}
	
	function MarkHam() {
		$this->owner->SpamScore = 0;
		$this->owner->write();
	}
	
	function AreSpam($filter = null, $sort = null, $join = null, $limit=null) {
		return DataObject::get(
			$this->owner->ClassName, 
			sprintf(self::$wheres['spam'], $this->required_spam_score, $this->required_moderation_score) . ($filter ? " AND ($filter)" : ''),
			$sort,
			$join,
			$limit
		);
	}
	
	function AreSpamCount() {
		$table = array_pop(ClassInfo::dataClassesFor($this->owner->class));
		$res = DB::query("SELECT COUNT(*) FROM {$table} WHERE ".sprintf(self::$wheres['spam'], $this->required_spam_score, $this->required_moderation_score))->value();
		return $res ? $res : 0;
	}
}