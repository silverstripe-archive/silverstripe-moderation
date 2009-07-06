<?php

class Moderatable extends DataObjectDecorator {
	
	static $default_moderation_score = 1.0;
	static $default_spam_score = 10.0;

	// The current moderation state, which is used for filtering by augmentSQL, which will limit to this type.
	// The values are "approved", "unapproved", "spam", and "any". "any" means no filtering is done, and should only be used
	// transiently by admin interfaces that need to fetch items of any state.
	static $moderationState = "approved";

	static $stateStack = array();

	// Filter conditions per state. These are used by augmentSQL, which currently assumes the first $F is the spam score threshold and the second
	// is the moderationScore threshold.
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
	
	// Augment the SQL to only return items in the current moderation state.
	function augmentSQL(SQLQuery &$query) {
		// If its disjunctive, throw an error. That would mean all approved objects would be
		// included on all queries. The way SQLQuery works doesn't let us easily mix operators
		// especially if there are other augmentSQL methods. The idea solution is to restructure
		// the query so that the existing disjunction is bracketed and the query is converted to
		// a disjunctive query. Best practice dictates we should also be idempotent, but that's
		// beyond a 3 banana problem.
		if ($query->connective == "OR") throw new Exception("Moderatable can't filter on a disjunctive query");

		if (self::$moderationState != "any") {
			$query->where(sprintf(self::$wheres[self::$moderationState], $this->required_spam_score, $this->required_moderation_score));
		}
	}

	// Get an instance of the decorated class by ID, without applying filtering. Should only be used by admin interface.
	public static function get_by_id_unfiltered($className, $id) {
		$old = self::$moderationState;
		self::$moderationState = "any";
		$result = DataObject::get_by_id($className, $id);
		self::$moderationState = $old;
		return $result;
	}
	

	/**
	 * Get all items of the decorated class that match criteria. Moderation filtering is applied by augmentSQL.
	 */
//	function getModeratedItems(/*$moderationState, */ $filter = null, $sort = null, $join = null, $limit=null) {
//		$result = DataObject::get(
//			$this->owner->ClassName, 
//			$filter,
//			$sort,
//			$join,
//			$limit
//		);
//		return $result;
//	}*/

	public static function push_state($state) {
		self::$stateStack[] = self::$moderationState;
		self::$moderationState = $state;
	}

	public static function pop_state() {
		self::$moderationState = array_pop(self::$stateStack);
	}

	/**
	 * Count the number of items of the decorated class that have the specified moderation count.
	 */
/*	function ModerationCount($state = "approved") {
		$table = array_pop(ClassInfo::dataClassesFor($this->owner->class));
		$res = DB::query("SELECT COUNT(*) FROM {$table} " .
							(($state == "any") ? "" :
							 (" WHERE ". sprintf(self::$wheres['approved'], $this->required_spam_score, $this->required_moderation_score))) .
							)->value();
		return $res ? $res : 0;
	}*/
	
	function MarkApproved() {
		$this->owner->ModerationScore = $this->required_moderation_score;
		$this->owner->SpamScore = 0;
		$this->owner->write();

		if (method_exists($this->owner, "onAfterApprove")) {
			$this->owner->onAfterApprove();
		}
	}

	function MarkUnapproved() {
		$this->owner->ModerationScore = 0;
		$this->owner->write();

		if (method_exists($this->owner, "onAfterUnapprove")) {
			$this->owner->onAfterUnapprove();
		}
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
}