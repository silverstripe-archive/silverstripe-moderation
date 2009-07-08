<?php

/**
 * A decorator that provides an object with the ability to be moderated, and providing multiple versions so that
 * a new unapproved item can exist in the moderation queue while an older version can still be live.
 * 
 * Implementation notes:
 * - this extends Versioned, which itself is a decorator. When you extend your class with this decorator, you pass it
 *   an array with the stages, typically  array('Stage', 'Live').
 * - on a dev/build, you'll get extra tables for the versioning constructed, just like regular Versioned.
 * - methods are defined on this class that are called by the ModeratableAdmin class.
 * 
 * Representation:
 * - 'Live' contains the published and approved version of extended class. This by default is what the public facing web site sees. Note that if there are only unapproved
 *   versions, Live will be absent.
 * - 'Stage' can be in one of two states:
 *      - if a new version has been added but is waiting for approval, it exists as a newer version in Stage, flagged unapproved.
 *      - when an unapproved version is stage is approved, it is flagged as approved, and this updated version is published to live, so Stage and Live
 *        have the same version.
 */
class VersionedModeratable extends Versioned {
	private static $_state = null;

	// Used by push_reading_state and pop_reading_state.
	private static $reading_state_stack = array();

	// Filter conditions per state. These are used by augmentSQL, which currently assumes the first $F is the spam score threshold and the second
	// is the moderationScore threshold.%T is a placeholder for table substitutiuon, required on approved fetches to avoid ambiguous column names
	private static $wheres = array(
		'approved'   => '((%T.SpamScore < %F OR %T.SpamScore IS NULL) AND %T.ModerationScore >= %F)',
		'unapproved' => '((%T.SpamScore < %F OR %T.SpamScore IS NULL) AND (%T.ModerationScore < %F OR %T.ModerationScore IS NULL))',
		'spam'       => '(%T.SpamScore >= %F)',
	);
	
	// Return true if instance is approved. Logic needs to reflect SQL logic in $wheres above.
	public function isApproved() {
		return $this->owner->SpamScore < $this->required_spam_score &&
			   $this->owner->ModerationScore >= $this->required_moderation_score;
	}

	public function isUnapproved() {
		return $this->owner->SpamScore < $this->required_spam_score &&
			   $this->owner->ModerationScore < $this->required_moderation_score;
	}

	// Return true if instance is spam. Logic needs to reflect SQL logic in $wheres above.
	public function isSpam() {
		return $this->owner->SpamScore >= $this->required_spam_score;
	}

	// pseudo-static that returns the static state. Can be called singleton(class)->stqte()
	public function moderationState() {
		if (!self::$_state)
			self::$_state = new ModeratableState();
		return self::$_state;	
	}
	
	public function extraStatics() {
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

	function __construct($stages, $moderation_score = null, $spam_score = null) {
		parent::__construct($stages);
		
		$this->required_moderation_score = $moderation_score ? $moderation_score : $this->moderationState()->default_moderation_score;
		$this->required_spam_score = $spam_score ? $spam_score : $this->moderationState()->default_spam_score;
	}

	// Augment the SQL to only return items in the current moderation state.
	function augmentSQL(SQLQuery &$query) {
		parent::augmentSQL($query);

		// If its disjunctive, throw an error. That would mean all approved objects would be
		// included on all queries. The way SQLQuery works doesn't let us easily mix operators
		// especially if there are other augmentSQL methods. The idea solution is to restructure
		// the query so that the existing disjunction is bracketed and the query is converted to
		// a disjunctive query. Best practice dictates we should also be idempotent, but that's
		// beyond a 3 banana problem.
		if ($query->connective == "OR") throw new Exception("Moderatable can't filter on a disjunctive query");

		if ($this->moderationState()->moderationState != "any") {
			$sqlfilter = self::$wheres[$this->moderationState()->moderationState];
			reset($query->from);
			$v = each($query->from); // extract first table, v[0] is class name, v[1] is base table name.
			$sqlfilter = str_replace("%T", $v[1], $sqlfilter);
			$query->where(sprintf($sqlfilter, $this->required_spam_score, $this->required_moderation_score));
		}
	}

	// Get the list of things to moderate. We use the following rules, depending on the moderation state
	// which will have been pushed on the state stack:
	// "approved":		Return a list of items where the most current version is published and is appoved.
	// "unapproved":	Return a list of items where the most current version is not published, and not approved.
	// "spam":			Variation of unapproved, but with the spam condition instead of unapproved.
	public function getItemsToModerate($className, $filter, $order, $join, $limit) {
		// Note, we are a singleton instance.

		switch ($this->moderationState()->moderationState) {
			case "approved":
				// We want live records where there are not higher versioned stage. This is a little tricky. We want to fetch
				// from the live table, but we include a left join to the staging for the same object, only selecting live records where there
				// isn't a staging record with a higher version. Note: can't put ` around stageTable, otherwise it gets substituted for the live
				// table by Versioned.
				$stageTable = $className;
				$liveTable = $className . "_" . $this->liveStage;
				$ds = Versioned::get_by_stage(
					$className,
					$this->liveStage,
					"(" . $filter . ") and (`$stageTable`.Version is null or `$stageTable`.Version <= `$liveTable`.Version)",
					$order,
					$join . " left join $stageTable on $stageTable.ID=`$liveTable`.ID",
					$limit);
				break;

			case "unapproved":
			case "spam":
				$this->moderationState()->push_state($this->moderationState()->moderationState);

				// We want the highest version stage where there is no higher versioned live, and which is flagged unapproved. This query will
				// give us the staging objects, and the augment sql filtering will give us the right ones.
				// Any of the following circumstances could be true:
				// - Unapproved in stage, none in live (new supplier)
				// - Unapproved in stage, an earlier version in Live. (stage will have the newer one)
				// - Approved in live - these are excluded because they are also flagged approved in stage when they are published.
				// The filtering condition is set based on whether it is unapproved or spam.
				$ds = Versioned::get_by_stage(
					$className,
					$this->defaultStage,
					$filter,
					$order,
					$join,
					$limit);

				$this->moderationState()->pop_state();

				break;

			default:
				throw new Exception ("VersionedModeratable: passed an unknown state: " . $this->moderationState()->moderationState);
		}

		return $ds;
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

	// Get $id from staging table, mark it as approved, save and then publish to live.
	public function markApproved($className, $id) {
		$this->moderationState()->push_state("any");

		$obj = self::get_one_by_stage(
			$className,
			$this->defaultStage,
			"ID = $id");

		// Update staging
		$obj->ModerationScore = $this->required_moderation_score;
		$obj->SpamScore = 0;
		$obj->write();
		$obj->flushCache();

		$obj->publish($this->defaultStage, "Live");

		$this->moderationState()->pop_state();
		
		// If the owner wants to know, do it
		if (method_exists($this->owner, "onAfterApprove")) {
			$this->owner->onAfterApprove();
		}
	}

	/**
	 * Delete the selected instance. If the item is currently live and approved, delete this live item, and see if there is an
	 * older approved item to replace it with. If this is an unapproved item, delete this version from stage.
	 */
	public function moderatorDelete($className, $id) {
		$this->moderationState()->push_state("any");

		// If it's live, delete it from live
		$liveObj = self::get_one_by_stage(
			$className,
			$this->liveStage,
			"ID = $id");
		$stageObj = self::get_one_by_stage(
			$className,
			$this->defaultStage,
			"ID = $id");
	
		// Delete it from stage whether approved or not.
		$stageObj->deleteFromStage($this->defaultStage);

		// If the live version is the same one, delete that too.
		if ($stageObj->Version == $liveObj->Version)
			$liveObj->deleteFromStage($this->liveStage);

		// Get the next older item
		$versions = $this->allVersions();

		if ($versions && ($previous = $versions->First()))
		{
			// Publish $olderApproved by version no
			$previous->publish($previous->Version, "Stage");
			if ($previous->isApproved()) $previous->publish($previous->Version, "Live");
		}
		$this->moderationState()->pop_state();
	}

	/**
	 * Mark an item as unapproved. It will already be in the live table and will be marked as approved. What we do is:
	 *  - Unpublished the live record.
	 *  - Look for an older version that was approved. If we find it, we make that one live instead.
	 *  - If we don't find an older approved version, we just remove the live record. This may result in no live record.
	 */
	public function markUnapproved($className, $id) {
		$this->internalUnapprove($className, $id, 0);

		if (method_exists($this->owner, "onAfterUnapprove")) {
			$this->owner->onAfterUnapprove();
		}
	}

	public function markSpam($className, $id) {
		$this->internalUnapprove($className, $id, -1, $this->required_spam_score);
	}

	// This handles both unapproval and spam, which are basically the same logic, except the way we mark the object
	// begin modified. -1 for a score means it doesn't get updated.
	private function internalUnapprove($className, $id, $newModerationScore = -1, $newSpamScore = -1) {
		$this->moderationState()->push_state("any");

		// Unpublish live
		$obj = self::get_one_by_stage(
			$className,
			$this->liveStage,
			"ID = $id");
		if ($obj) $obj->deleteFromStage($this->liveStage);

		// Mark this version in staging as being unapproved
		$obj = self::get_one_by_stage(
			$className,
			$this->defaultStage,
			"ID = $id");

		if ($newModerationScore >= 0) $obj->ModerationScore = $newModerationScore;
		if ($newSpamScore >= 0) $obj->SpamScore = $newSpamScore;
		$obj->writeToStage($this->defaultStage);

		// Get the next older approved item
		$cond = str_replace("%T.", "", self::$wheres["approved"]);
		$cond = sprintf($cond, $this->required_spam_score, $this->required_moderation_score); // approved
		$versions = $this->allVersions();
		if ($versions && ($olderApproved = $versions->First()))
		{
			// Publish $olderApproved by version no
			$olderApproved->publish($olderApproved->Version, "Live");
		}

		$this->moderationState()->pop_state();
		
	}

	public function markHam($className, $id) {
		throw new Exception ("VersionedModeratable: markHam not implemented");
//		$this->owner->SpamScore = 0;
//		$this->owner->write();
	}
}

?>
