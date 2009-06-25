<?php
/**
 * Comment administration system within the CMS
 * @package cms
 * @subpackage comments
 */
class ModeratableAdmin extends ModelAdmin {
	
	static $url_segment = 'moderation';
	static $menu_title = 'Moderation';
	
	static $url_handlers = array(
		'$ClassName!/$ID!/$Command!' => 'moderate',
	);
	
	static $managed_models = 'decorator:Moderatable';
	static $collection_controller_class = "ModeratableAdmin_CollectionController";
	
	public function init() {
		parent::init();

		Requirements::javascript('moderation/javascript/ModeratableAdmin_right.js');
		Requirements::css('moderation/css/ModeratableAdmin.css');
	}

	function getManagedModels() {
		$classes = $this->stat('managed_models');
		
		if (is_string($classes)) {
			if (preg_match('/^decorator:(.*)/', $classes, $match)) {
				$classes = array();
				foreach (ClassInfo::subclassesFor('DataObject') as $class) {
					if (Object::has_extension($class, $match[1])) $classes[] = $class;
				}
				if (count($class) > 1) array_unshift($classes, 'All');
			}
			else $classes = array($classes);
		}
		
		return $classes;
	}
	
	protected function getModelForms() {
		$modelClasses = $this->getManagedModels();
		
		$forms = new DataObjectSet();
		foreach($modelClasses as $modelClass) {
			$this->$modelClass()->SearchForm();

			$forms->push(new ArrayData(array(
				'SearchForm' => $this->$modelClass()->SearchForm(),
				'CreateForm' => $this->$modelClass()->CreateForm(),
				'ImportForm' => $this->$modelClass()->ImportForm(),
				'Title' => $modelClass == 'All' ? 'All' : singleton($modelClass)->singular_name(),
				'ClassName' => $modelClass,
			)));
		}
		
		return $forms;
	}
	
	function moderate() {
		$id = (int)$this->urlParams['ID'];
		$className = Convert::raw2sql($this->urlParams['ClassName']);
		
		if ($do = DataObject::get_by_id($className, $id)) {
			FormResponse::add('$("Form_EditForm").elementMoved('.$do->ID.');');
			
			switch ($this->urlParams['Command']) {
				case 'delete':
					$do->delete();
					FormResponse::status_message("$className deleted", 'good');
					break;

				case 'isspam':
					$do->IsSpam();
					FormResponse::status_message("$className set as spam", 'good');
					break;
					
				case 'isham':
					$do->IsHam();
					FormResponse::status_message("$className set as ham", 'good');
					break;
				
				case 'approve':
					$do->Approve();
					FormResponse::status_message("$className accepted", 'good');
					break;
					
				case 'unapprove':
					$do->Unapprove();
					FormResponse::status_message("$className accepted", 'good');
					break;
					
				default:
					FormResponse::clear();
					FormResponse::status_message("Command invalid", 'bad');
			}
		}
		else FormResponse::status_message("$className $ID not found", 'bad');
		
		return FormResponse::respond();
	}
	
}

class ModeratableAdmin_CollectionController extends ModelAdmin_CollectionController {

	/* We can't create or import reviews in this model admin */
	public function CreateForm() { return null; }
	public function ImportForm() { return null; }
	
	public function SearchForm() {
		if ($this->modelClass != 'All') {
			$context = singleton($this->modelClass)->getDefaultSearchContext();
			$fields = $context->getSearchFields();
		}
		else {
			$fields = new FieldSet();
		}
		
		$fields->push(new DropdownField(
			'State', 'State', 
			array(
				'unapproved' => 'Awaiting Moderation',
				'approved' => 'Approved',
				'spam' => 'Marked as Spam'
			),
			'unapproved'
		));
		
		$form = new Form($this, "SearchForm",
			$fields,
			new FieldSet(
				new FormAction('search', _t('MemberTableField.SEARCH', 'Search')),
				$clearAction = new ResetFormAction('clearsearch', _t('ModelAdmin.CLEAR_SEARCH','Clear Search'))
			)
		);
		
		$form->setFormMethod('get');
		$form->setHTMLID("Form_SearchForm_" . $this->modelClass);
		$clearAction->useButtonTag = true;
		$clearAction->addExtraClass('minorAction');

		return $form;
	}
	
	function search($request, $form) {
		// Get the results form to be rendered
		$resultsForm = $this->ResultsForm(array_merge($form->getData(), $request));
		
		return new HTTPResponse(
			$resultsForm->forTemplate(), 
			200, 
			'Search completed'
		);
	}
		
	public function ResultsForm($searchCriteria) {
		if($searchCriteria instanceof HTTPRequest) $searchCriteria = $searchCriteria->getVars();
		$section = $searchCriteria['State'];
		
		switch ($section) {
			case 'approved':
				$method = 'AreApproved';
				$title = "Approved";
				$commands = array('unapprove' => 'Unapprove', 'isspam' => 'Is Spam');
				break;
				
			case 'unapproved':
				$method = 'AreUnapproved';
				$title = "Waiting Moderation";
				$commands = array('approve' => 'Approve', 'isspam' => 'Is Spam');
				break;
				
			default:
				$method = 'AreSpam';
				$title = "Spam";
				$commands = array('approve' => 'Approve', 'isham' => 'Not Spam');
		}
		$commands['delete'] = 'Delete';
		
		if(($class = $this->getModelClass()) == 'All') {
			$ds = new DataObjectSet();
			foreach ($this->parentController->getManagedModels() as $class) {
				if ($class != 'All') $ds->merge(singleton($class)->$method('', 'Created'));
			}
		}
		else {
			$ds = singleton($class)->$method("({$this->getSearchQuery($searchCriteria)->getFilter()})", 'Created');
		}

		$fields = new FieldSet();
		if ($ds) foreach ($ds as $do) {
			$links = array();
			foreach ($commands as $command => $text) {
				$links[] = "<input class='action ajaxaction' type='button' value='{$text}' action='{$this->parentController->Link("{$do->ClassName}/{$do->ID}/{$command}")}' />";
			}
			
			$templates = array();
			foreach (array_reverse(ClassInfo::ancestry($do->ClassName)) as $class) {
				if ($class == 'DataObject') break;
				$templates[] = $class.'Moderation';
			}
			
			$data = new ArrayData(array('ModerationLinks' => implode('',$links), 'Preview' => $do->renderWith($templates)));
			$fields->push(new LiteralField("Item{$do->ID}", $data->renderWith('ModerationPreview')));
		}

		return new Form(
			$this, 'EditForm', 
			$fields,
			new FieldSet()
		);
	}
}

?>
