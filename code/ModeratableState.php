<?php

/**
 * ModeratableCommon is a static class that contains definitions common to both Moderatable and VersionedModeratable.
 * Normally this would just be coded in the base class, but since they derive from different classes we don't have
 * that option.
 * This is not very nice, and makes it hard to override in a site, so this should be made better at some point.
 */

class ModeratableState extends Object {
	static public $default_moderation_score = 1.0;
	static public $default_spam_score = 10.0;

	// The current moderation state, which is used for filtering by augmentSQL, which will limit to this type.
	// The values are "approved", "approved_if_latest", "unapproved", "spam", and "any". "any" means no filtering is done, and should only be used
	// transiently by admin interfaces that need to fetch items of any state.
	static public  $state = "approved";
	static private $stack = array();

	static public function push_state($state) {
		self::$stack[] = self::$state;
		self::$state = $state;
	}
	
	static public function pop_state() {
		self::$state = array_pop(self::$stack);
	}

	/******* FOR INTERNAL USE ONLY *********/
	
	static public function moderation_state($dec, $obj) {
		if ($obj->SpamScore >= $dec->required_spam_score) return 'spam';
		if ($obj->ModerationScore >= $dec->required_moderation_score) return 'approved';
		return 'unapproved';
	}
	
	static public function on_moderation_state_change($dec, $obj) {
		if (method_exists($obj, "onModerationStateChange")) $obj->onModerationStateChange(self::moderation_state($dec, $obj));
	}
	
	static public function mark_approved($dec, $obj) {
		$old_state = self::moderation_state($dec, $obj);
		
		$obj->ModerationScore = $dec->required_moderation_score;
		$obj->SpamScore = 0;
		$obj->write();

		if ($old_state != self::moderation_state($dec, $obj)) self::on_moderation_state_change($dec, $obj);
	}

	static public function mark_unapproved($dec, $obj) {
		$old_state = self::moderation_state($dec, $obj);
		
		$obj->ModerationScore = 0;
		$obj->write();

		if ($old_state != self::moderation_state($dec, $obj)) self::on_moderation_state_change($dec, $obj);
	}

	static public function mark_spam($dec, $obj) {
		$old_state = self::moderation_state($dec, $obj);
		
		$obj->SpamScore = $dec->required_spam_score;
		$obj->ModerationScore = 0; // When marked as spam, item loses it's moderation approval
		$obj->write();
		
		if ($old_state != self::moderation_state($dec, $obj)) self::on_moderation_state_change($dec, $obj);
	}
	
	static public function mark_ham($dec, $obj) {
		$old_state = self::moderation_state($dec, $obj);
		
		$obj->SpamScore = 0;
		$obj->write();
		
		if ($old_state != self::moderation_state($dec, $obj)) self::on_moderation_state_change($dec, $obj);
	}
}

?>