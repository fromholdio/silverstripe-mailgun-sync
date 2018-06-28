<?php
namespace NSWDPC\SilverstripeMailgunSync;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Member;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;

/**
 * @author James Ellis
 * @note provides a record to track submissions via Mailgun. When a submission is made, a record is saved to MailgunSubmission.
 *			This allows for tracking of messages via Mailgun data without exposing source classnames in email headers
 * @see https://help.mailgun.com/hc/en-us/articles/203879300-What-can-I-search-for-in-logs-and-events-
 * Things to keep in mind:
 * 	We only store your events data for a certain number of days
 * 	Free accounts - 2 days
 * 	Paid accounts (with a credit card on file) - 30 days
 * 	We store the raw MIME of the message for up to 3 days
 */
class MailgunSubmission extends DataObject implements PermissionProvider {

	/**
	 * Defines the database table name
	 * @var string
	 */
	private static $table_name = 'MailgunSubmission';

	private static $singular_name = "Submission";
	private static $plural_name = "Submissions";

	private static $default_sort = "Created DESC";

	private static $db = [
		'SubmissionClassName' => 'Varchar(255)',// ClassName of Submission source e.g UserDefinedForm
		'SubmissionID' => 'Int', // ID of classname
		'Recipient' => 'Varchar(255)', // The specific recipient for this submission, optional and makes no sense when multiple recipients
		'MessageId' => 'Varchar(255)',// remote message id (optional) - for submissions with multiple recipients, the most recent message-id returned
		'Domain' => 'Varchar(255)',// mailgun configured domain (optional) this message is linked to, set on send
	];

	private static $has_many = [
		'Events' => MailgunEvent::class // link this submission to multiple mailgun events
	];

	private static $indexes = [
		'Submission' => ['type' => 'index', 'columns' => ["SubmissionID","SubmissionClassName"] ],
		'MessageDomain' => ['type' => 'index', 'columns' => ["MessageId","Domain"] ],
		'MessageId' => ['type' => 'index', 'columns' => ['MessageId'] ],
	];

	private static $summary_fields = [
		'ID' => '#',
		'Created.Nice' => 'Created',
		'Events.Count' => 'Events',
		'DeliveredCount' => 'Delivered',
		'FailedOrRejectedCount' => 'Failed/Rejected',
		'SubmissionDetails' => 'Source',
		'Recipient' => 'Recipient',// optional
		'Domain' => 'Domain',
	];

	/**
	 * @return array
	 */
	public function providePermissions() {
		return array(
			'MAILGUNSUBMISSION_VIEW' => array(
				'name' => 'View Mailgun submissions',
				'category' => 'Mailgun',
			)
		);
	}

	public function requireDefaultRecords() {
		parent::requireDefaultRecords();
	}

	public function getTitle() {
		return $this->SubmissionDetails();
	}

	public function SubmissionDetails() {
		return $this->SubmissionClassName . " #" . $this->SubmissionID;
	}

	/**
	 * Retrieve the record that originated this submission e.g UserDefinedForm ID=89
	 * @returns DataObject|false
	 */
	public function getSubmissionRecord($as_list = FALSE) {
		$record = DataObject::get($this->SubmissionClassName)->filter('ID', $this->SubmissionID);
		if(!$as_list) {
			return $record->first();
		} else {
			return $record;
		}
	}

	/**
	 * Returns the MailgunSubmission record linked to the DataObject passed
	 * @returns \MailgunSubmission|false
	 * @param $submission_source
	 * @param $as_list
	 */
	public static function getMailgunSubmission(DataObject $submission_source, $as_list = false) {
		$list = MailgunSubmission::get()->filter( ['SubmissionClassName' => $submission_source->ClassName,  'SubmissionID' => $submission_source->ID ] );
		if(!$as_list) {
			return $list->first();
		} else {
			return $list;
		}
	}

	/**
	 * The count of events with a Delivered status for this submission
	 */
	public function DeliveredCount() {
		$events = $this->Events()->filter('EventType', MailgunEvent::DELIVERED);
		return $events ? $events->count() : 0;
	}

	/**
	 * The count of events with an Accepted status for this submission
	 */
	public function AcceptedCount() {
		$events = $this->Events()->filter('EventType', MailgunEvent::ACCEPTED);
		return $events ? $events->count() : 0;
	}

	/**
	 * The count of events with a Stored status for this submission
	 */
	public function StoredCount() {
		$events = $this->Events()->filter('EventType', MailgunEvent::STORED);
		return $events ? $events->count() : 0;
	}

	/**
	 * The count of events with some type of failure status for this submission
	 */
	public function FailedCount() {
		$events = $this->Events()->filter('EventType', MailgunEvent::FAILED);
		return $events ? $events->count() : 0;
	}

	/**
	 * The count of events with a Rejected status
	 */
	public function RejectedCount() {
		$events = $this->Events()->filter('EventType', MailgunEvent::REJECTED);
		return $events ? $events->count() : 0;
	}

	/**
	 * The count of events that are Failed or Rejected
	 */
	public function FailedOrRejectedCount() {
		$events = $this->Events()->filterAny('EventType', [ MailgunEvent::FAILED, MailgunEvent::REJECTED ]);
		return $events ? $events->count() : 0;
	}

	/**
	 * These submissions can't be deleted
	 */
	public function canDelete($member = NULL) {
		return false;
	}

	public function canEdit($member = NULL) {
		if(!$member) $member = Member::currentUser();
		return Permission::check('MAILGUNSUBMISSION_VIEW', 'any', $member);
	}

	/**
	 * Allow viewing by members with this permission
	 */
	public function canView($member = NULL) {
		if(!$member) $member = Member::currentUser();
		return Permission::check('MAILGUNSUBMISSION_VIEW', 'any', $member);
	}

	public function getCmsFields() {
		$fields = parent::getCmsFields();

		// no need to make these editable...
		$fields->replaceField('SubmissionClassName', $fields->dataFieldByName('SubmissionClassName')->setTitle('Type')->performReadOnlyTransformation());
		$fields->replaceField('SubmissionID', $fields->dataFieldByName('SubmissionID')->setTitle('ID')->performReadOnlyTransformation());
		$fields->replaceField('Recipient', $fields->dataFieldByName('Recipient')->performReadOnlyTransformation());
		$fields->replaceField('MessageId', $fields->dataFieldByName('MessageId')->setTitle('Mailgun Message Id')->performReadOnlyTransformation());
		$fields->replaceField('Domain', $fields->dataFieldByName('Domain')->setTitle('Mailgun Message Domain')->performReadOnlyTransformation());

		// events GridField
		$events = $fields->dataFieldByName('Events');
		if($events && $events instanceof GridField) {
			$events_config = $events->getConfig();
			$events_config->removeComponentsByType('GridFieldAddNewButton');
			$events_config->removeComponentsByType('GridFieldDeleteAction');
			$events_config->removeComponentsByType('GridFieldEditButton');
			$events_config->removeComponentsByType('GridFieldAddExistingAutoCompleter');
		}

		// stats
		$fields->addFieldsToTab(
			'Root.Main', [
				HeaderField::create('SubmissionStatsHeader', 'Submission Stats', 4),
				LiteralField::create('SubmissionStats', "<p>Accepted: " . $this->AcceptedCount()
																											. " / Delivered: " . $this->DeliveredCount()
																											. " / Failed: " . $this->FailedCount()
																											. " / Rejected: " . $this->RejectedCount()
																									. "</p>")
			]
		);

		// create a gridfield record representing the source of this submission
		$list = $this->getSubmissionRecord(TRUE);
		if($list && $list->count() > 0) {
			$record = $list->first();
			$config = GridFieldConfig_RecordEditor::create()
									->removeComponentsByType('GridFieldAddNewButton')
									->removeComponentsByType('GridFieldEditButton')
									->removeComponentsByType('GridFieldDeleteAction');

			$gridfield = GridField::create(
											'SourceRecord',
											$record->Title,
											$list,
											$config
										);

			$fields->addFieldToTab('Root.Main', $gridfield);
		}
		return $fields;
	}

}
