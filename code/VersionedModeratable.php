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
		'approved'   => '((`$Table`.SpamScore < $RequiredSpamScore OR `$Table`.SpamScore IS NULL) AND `$Table`.ModerationScore >= $RequiredModerationScore)',
		'unapproved' => '(`$Table`.SpamScore < $RequiredSpamScore OR `$Table`.SpamScore IS NULL)',
		'spam'       => '(`$Table`.SpamScore >= $RequiredSpamScore)',
	);

	private function where($what) {
		return str_replace(
			array('$Table', '$RequiredModerationScore', '$RequiredSpamScore'), 
			array($this->owner->class, $this->RequiredModerationScore, $this->RequiredSpamScore), 
			self::$wheres[$what]
		);
	}
	
	function __construct($moderationScore = null, $spamScore = null) {
		parent::__construct(array('Stage', 'Live'));
		
		$this->RequiredModerationScore = $moderationScore ? $moderationScore : ModeratableState::$default_moderation_score;
		$this->RequiredSpamScore = $spamScore ? $spamScore : ModeratableState::$default_spam_score;
	}

	/* The next few functions mix in functions from Moderatable. Calling methods statically means that $this will still point to this instance in those methods */
	
	public function ModerationState()         { return Moderatable::ModerationState(); }
	public function onModerationStateChange() { return Moderatable::onModerationStateChange(); }
	
	public function markApproved()            { Moderatable::markApproved(); }
	public function markUnapproved()          { Moderatable::markUnapproved(); }
	public function markSpam()                { Moderatable::markSpam(); }
	public function markHam()                 { Moderatable::markHam(); }
	
	public function isApproved() {
		return $this->ModerationState() == 'approved';
	}

	public function isUnapproved() {
		return $this->ModerationState() == 'unapproved';
	}

	public function isSpam() {
		return $this->ModerationState() == 'spam';
	}
	
	public function hasUnapprovedVersion() {
		$stage_version = Versioned::get_versionnumber_by_stage($this->owner->class, 'Stage', $this->owner->ID);
		$live_version = Versioned::get_versionnumber_by_stage($this->owner->class, 'Live', $this->owner->ID);
		
		return $stage_version > $live_version;
	}

	public function hasApprovedVersion() {
		$live_version = Versioned::get_versionnumber_by_stage($this->owner->class, 'Live', $this->owner->ID);
		return (bool)$live_version;
	}
	
	/**
	 * augmentSQL alters read requests to return the 'correct' DataObject, given the current Moderatable state setting
	 */  
	function augmentSQL(SQLQuery &$query) {
		/* If its disjunctive, throw an error. That would mean all approved objects would be included on all queries. */
		if ($query->connective == "OR") throw new Exception("Moderatable can't filter on a disjunctive query");

		$savedstage = Versioned::$reading_stage;

		$stageTable = $this->baseTable($this->defaultStage);
		$liveTable =  $this->baseTable($this->liveStage);

		/* Handle the 'approved' & 'approved_if_latest' selections */
		if (ModeratableState::$state == 'approved' || ModeratableState::$state == 'approved_if_latest') {
			Versioned::$reading_stage = $this->liveStage;
			parent::augmentSQL($query);
			
			if (ModeratableState::$state == 'approved_if_latest') {
				$query->from["{$stageTable}VMVerCheck"] = "LEFT JOIN `$stageTable` ON `$stageTable`.ID = `$liveTable`.ID";
				$query->where['VMVerCheck'] = "`$stageTable`.Version <= `$liveTable`.Version";
			}
		}
		/* Handle the 'any', 'unapproved' and 'spam' selections */
		else {
			Versioned::$reading_stage = $this->defaultStage;
			parent::augmentSQL($query);
			
			if (ModeratableState::$state != 'any') {
				$query->where['VMSpamSplit'] = $this->where(ModeratableState::$state);
				
				$query->leftJoin($liveTable, "`$stageTable`.ID = `$liveTable`.ID");
				$query->where['VMVerCheck'] = "`$liveTable`.Version IS NULL OR `$stageTable`.Version > `$liveTable`.Version";
			}
		}
		
		Versioned::$reading_stage = $savedstage;
	}
	
	/** If this is set to a particular version causes the next write to update an old version. It will auto-clear itself after the write */
	protected static $generate_new_version = false;
	
	/**
	 * augmentWrite handles the case where we're trying to update the moderation score or spam score and don't want to create a new version
	 */
	public function augmentWrite(&$manipulation) {
		/* If we want to create a new version, do that now */
		if (self::$generate_new_version) {
			self::$generate_new_version = false;
			parent::augmentWrite($manipulation);
			return;
		}

		/* Otherwise, we just change the manipulation to save to the versioned table, and rely on onAfterWrite to fix up the staged & live tables */
		$class = $this->owner->class; 
		$versions = $class . '_versions';
		
		$manipulation[$versions] = $manipulation[$class]; 

		unset($manipulation[$class]);
		unset($manipulation[$versions]['id']);
		
		$manipulation[$this->owner->class.'_versions']['where'] = "RecordID = {$this->owner->ID} AND Version = {$this->owner->Version}";
	}
	
	public function writeAsNewVersion() {
		self::$generate_new_version = true;
		$this->owner->write();
	}
	
	/**
	 * Utility function for recalculateStages, which gives the most recent version in the versions table matching a particular filter.
	 * Partially copied from Versioned#allVersions, but that function searches by LastEdited first, before Version, which breaks our 'can update versions later' model
	 */
	private function latestVersionMatching($id, $filter = "") {
		$query = $this->owner->extendedSQL($filter,"");

		foreach($query->from as $table => $join) {
			if($join[0] == '`') $baseTable = str_replace('`','',$join);
			else if (substr($join,0,5) != 'INNER') $query->from[$table] = "LEFT JOIN `$table` ON `$table`.RecordID = `{$baseTable}_versions`.RecordID AND `$table`.Version = `{$baseTable}_versions`.Version";
			$query->renameTable($table, $table . '_versions');
		}
		$query->select[] = "`{$baseTable}_versions`.AuthorID, `{$baseTable}_versions`.Version, `{$baseTable}_versions`.RecordID";
		$query->where[]  = "`{$baseTable}_versions`.RecordID = '{$id}'";
		$query->orderby  = "`{$baseTable}_versions`.Version DESC";

		foreach($query->execute() as $record) return $record['Version'];
	}
	
	// Is public for testing. Not for normal usage
	static public $supress_triggers = false;

	private function recalculateStages() {
		if (self::$supress_triggers) return;

		$id = $this->owner->ID; // ID gets set to 0 on publish, so we need to save it
		ModeratableState::push_state("any");
		self::$supress_triggers = true;
		
		if ($approved = $this->latestVersionMatching($id, $this->where('approved'))) {
			$this->owner->ID = $id;
			self::$generate_new_version = true;
			$this->owner->publish($approved, $this->liveStage);
		}
		else {
			ModeratableState::push_state('approved'); $this->owner->delete(); ModeratableState::pop_state();
		}

		/* Update the Default stage */
		if ($latest = $this->latestVersionMatching($id)) {
			$this->owner->ID = $id;
			self::$generate_new_version = true;
			$this->owner->publish($latest, $this->defaultStage);
		}
		else {
			$this->owner->delete();
		}
		
		self::$supress_triggers = false;
		ModeratableState::pop_state();
	}
	
	public function onAfterDelete() {
		$this->recalculateStages();
	}
	
	public function onAfterWrite() {
		$this->recalculateStages();
	}
}

?>
