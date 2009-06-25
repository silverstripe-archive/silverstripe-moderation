<?php

class Moderatable extends DataObjectDecorator {
	
	static $default_moderation_score = 1.0;
	static $default_spam_score = 10.0;
	
	static $wheres = array(
		'approved'   => '((SpamScore < %F OR SpamScore IS NULL) AND ModerationScore >= %F)',
		'unapproved' => '((SpamScore < %F OR SpamScore IS NULL) AND (ModerationScore < %F OR ModerationScore IS NULL))',
		'spam'       => '(SpamScore >= %F)',
	);
	
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
	
	function Approve() {
		$this->owner->ModerationScore = $this->required_moderation_score;
		$this->owner->SpamScore = 0;
		$this->owner->write();
	}

	function AreApproved($filter = null, $sort = null, $join = null) {
		return DataObject::get(
			$this->owner->ClassName, 
			sprintf(self::$wheres['approved'], $this->required_spam_score, $this->required_moderation_score) . ($filter ? " AND $filter" : ''),
			$sort,
			$join
		);
	}
	
	function AreApprovedCount() {
		$table = array_pop(ClassInfo::dataClassesFor($this->owner->class));
		$res = DB::query("SELECT COUNT(*) FROM {$table} WHERE ".sprintf(self::$wheres['approved'], $this->required_spam_score, $this->required_moderation_score))->value();
		return $res ? $res : 0;
	}
	
	function Unapprove() {
		$this->owner->ModerationScore = 0;
		$this->owner->write();
	}
	
	function AreUnapproved($filter = null, $sort = null, $join = null) {
		return DataObject::get(
			$this->owner->ClassName, 
			sprintf(self::$wheres['unapproved'], $this->required_spam_score, $this->required_moderation_score) . ($filter ? " AND $filter" : ''),
			$sort,
			$join
		);
	}
	
	function AreUnapprovedCount() {
		$table = array_pop(ClassInfo::dataClassesFor($this->owner->class));
		$res = DB::query("SELECT COUNT(*) FROM {$table} WHERE ".sprintf(self::$wheres['unapproved'], $this->required_spam_score, $this->required_moderation_score))->value();
		return $res ? $res : 0;
	}
	
	function IsSpam() {
		$this->owner->SpamScore = $this->required_spam_score;
		$this->owner->ModerationScore = 0; // When marked as spam, item loses it's moderation approval
		$this->owner->write();
	}
	
	function IsHam() {
		$this->owner->SpamScore = 0;
		$this->owner->write();
	}
	
	function AreSpam($filter = null, $sort = null, $join = null) {
		return DataObject::get(
			$this->owner->ClassName, 
			sprintf(self::$wheres['spam'], $this->required_spam_score, $this->required_moderation_score) . ($filter ? " AND $filter" : ''),
			$sort,
			$join
		);
	}
	
	function AreSpamCount() {
		$table = array_pop(ClassInfo::dataClassesFor($this->owner->class));
		$res = DB::query("SELECT COUNT(*) FROM {$table} WHERE ".sprintf(self::$wheres['spam'], $this->required_spam_score, $this->required_moderation_score))->value();
		return $res ? $res : 0;
	}
}