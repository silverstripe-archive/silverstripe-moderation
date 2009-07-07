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

	static function state() {
		if (!self::$_state)
			self::$_state = new ModeratableState();
		return self::$_state;	
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
			$query->where(sprintf(self::$wheres[self::state()->moderationState], $this->required_spam_score, $this->required_moderation_score));
		}
	}

	// Get the list of things to moderate. We just filter items by the current moderation state.
	public function getItemsToModerate($className, $filter, $order, $join, $limit) {
		return DataObject::get(
			$className,
			$filter,
			$order,
			$join,
			$limit);
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
	
	function markApproved($className, $id) {
		if (!($obj = DataObject::get_one($className, "ID = $id")))
			return "Could not locate object $id of type $className";

		$obj->ModerationScore = $this->required_moderation_score;
		$obj->SpamScore = 0;
		$obj->write();

		if (method_exists($obj, "onAfterApprove")) {
			$obj->onAfterApprove();
		}
	}

	function markUnapproved($className, $id) {
		if (!($obj = DataObject::get_one($className, "ID = $id")))
			return "Could not locate object $id of type $className";

		$obj->ModerationScore = 0;
		$obj->write();

		if (method_exists($obj, "onAfterUnapprove")) {
			$obj->onAfterUnapprove();
		}
	}

	function markSpam($className, $id) {
		if (!($obj = DataObject::get_one($className, "ID = $id")))
			return "Could not locate object $id of type $className";

		$obj->SpamScore = $this->required_spam_score;
		$obj->ModerationScore = 0; // When marked as spam, item loses it's moderation approval
		$obj->write();
	}
	
	function markHam($className, $id) {
		if (!($obj = DataObject::get_one($className, "ID = $id")))
			return "Could not locate object $id of type $className";

		$obj->SpamScore = 0;
		$obj->write();
	}
}