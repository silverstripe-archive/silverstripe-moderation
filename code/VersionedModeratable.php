<?php

/**
 * A decorator that provides an object with the ability to be moderated, and providing multiple versions so that
 * a new unapproved item can exist in the moderation queue while an older version can still be live.
 * 
 * Implementation notes:
 * - this extends Versioned, which itself is a decorator.
 * - on a dev/build, you'll get extra tables for the versioning constructed, just like regular Versioned.
 * 
 * There are two ways to split the set of objects into approved / unapproved / spam
 * 
 * When writing, the regular SpamScore / ModerationScore is used
 * 
 * When reading, anything in the Live table is assumed approved. Anything in the staged table that is not in the live table is assumed either unapproved or spam, depending
 * on SpamScore
 * 
 */
class VersionedModeratable extends Versioned {
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
	
	// Filter conditions per state. These are used by augmentSQL, which currently assumes the first $F is the spam score threshold and the second
	// is the moderationScore threshold.%T is a placeholder for table substitutiuon, required on approved fetches to avoid ambiguous column names
	private static $wheres = array(
		'approved'   => '((%T.SpamScore < %F OR %T.SpamScore IS NULL) AND %T.ModerationScore >= %F)',
		'unapproved' => '(%T.SpamScore < %F OR %T.SpamScore IS NULL)',
		'spam'       => '(%T.SpamScore >= %F)',
	);
	
	public function ModerationState() {
		return ModeratableState::moderation_state($this, $this->owner);
	}
	
	// Return true if instance is approved. Logic needs to reflect SQL logic in $wheres above.
	public function isApproved() {
		return $this->ModerationState() == 'approved';
	}

	public function isUnapproved() {
		return $this->ModerationState() == 'unapproved';
	}

	// Return true if instance is spam. Logic needs to reflect SQL logic in $wheres above.
	public function isSpam() {
		return $this->ModerationState() == 'spam';
	}

	function __construct($moderation_score = null, $spam_score = null) {
		parent::__construct(array('Stage', 'Live'));
		
		$this->required_moderation_score = $moderation_score ? $moderation_score : ModeratableState::$default_moderation_score;
		$this->required_spam_score = $spam_score ? $spam_score : ModeratableState::$default_spam_score;
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

		$savedstage = Versioned::$reading_stage;

		$stageTable = $this->baseTable($this->defaultStage);
		$liveTable =  $this->baseTable($this->liveStage);

		/* Handle the 'approved' & 'approved_if_latest' selections */
		if (ModeratableState::$state == 'approved' || ModeratableState::$state == 'approved_if_latest') {
			Versioned::$reading_stage = $this->liveStage;
			parent::augmentSQL($query);
			Versioned::$reading_stage = $savedstage;
			
			if (ModeratableState::$state == 'approved_if_latest') {
				$query->from["{$stageTable}VMVerCheck"] = "LEFT JOIN `$stageTable` ON `$stageTable`.ID = `$liveTable`.ID";
				$query->where['VMVerCheck'] = "`$stageTable`.Version <= `$liveTable`.Version";
			}
		}
		/* Handle the 'all', 'unapproved' and 'spam' selections */
		else {
			Versioned::$reading_stage = $this->defaultStage;
			parent::augmentSQL($query);
			Versioned::$reading_stage = $savedstage;
			
			if (ModeratableState::$state != 'all') {
				$sqlfilter = self::$wheres[ModeratableState::$state];
				$sqlfilter = str_replace("%T", $this->owner->class, $sqlfilter);
				
				$query->where['VMSpamSplit'] = sprintf($sqlfilter, $this->required_spam_score, $this->required_moderation_score);
				
				$query->leftJoin($liveTable, "`$stageTable`.ID = `$liveTable`.ID");
				$query->where['VMVerCheck'] = "`$liveTable`.Version IS NULL OR `$stageTable`.Version > `$liveTable`.Version";
			}
		}
	}

	public function mostRecentIsApproved() {
		return !$this->stagesDiffer($this->defaultStage, $this->liveStage);		
	}
	
	public function onAfterWrite() {
		$new_state = $this->ModerationState();
		
		if ($new_state == 'approved' && !$this->mostRecentIsApproved()) {
			$this->owner->publish($this->defaultStage, 'Live');
		}
		
		elseif ($new_state != 'approved' && $this->mostRecentIsApproved()) {
			$this->deleteFromStage($this->liveStage);
			
			// Get the next older approved item
			$cond = str_replace("%T.", "", self::$wheres["approved"]);
			$cond = sprintf($cond, $this->required_spam_score, $this->required_moderation_score); // approved
			$versions = $this->allVersions($cond);
			
			if ($versions && ($olderApproved = $versions->First())) {
				$olderApproved->publish($olderApproved->Version, $this->liveStage);
			}
		}
	}
	
	public function markApproved() {
		ModeratableState::mark_approved($this, $this->owner);
	}
	
	public function markUnapproved() {
		ModeratableState::mark_unapproved($this, $this->owner);
	}

	public function markSpam() {
		ModeratableState::mark_spam($this, $this->owner);
	}

	public function markHam() {
		ModeratableState::mark_ham($this, $this->owner);
	}
	
	/**
	 * Delete the selected instance. If the item is currently live and approved, delete this live item, and see if there is an
	 * older approved item to replace it with. If this is an unapproved item, delete this version from stage.
	 */
	public function moderatorDelete($className, $id) {
		user_error('This function is broken', E_USER_ERROR); exit();
		
		ModeratableState::push_state("any");

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
		
		ModeratableState::pop_state();
	}
}

?>
