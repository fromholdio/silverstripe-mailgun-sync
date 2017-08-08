<?php
namespace DPCNSW\SilverstripeMailgunSync;
/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * This Job runs once per day and polls local 'failed' OR 'rejected' MailgunEvent records matching the date range
 * 	to check for 'delivered' status linked to the message-id stored in the event
 *	The job then stores a delivered event
 */
class DeliveryCheckJob extends \AbstractQueuedJob {
	
	// This is to run once every day.
	private static $repeat_time = 86400;
	
	/**
	 * Default repeat at 1pm
	 */
	private static $time_of_day = ['hour' => 13, 'minute' => 0, 'second' => 0 ];
	
	public function getJobType() {
		return \QueuedJob::QUEUED;
	}
	
	public function getTitle() {
		return "Mailgun Delivery Check Job";
	}
	
	/**
	 * getNextStartDateTime - based on configured time for job and the current datetime, set the datetime for the next job
	 * @returns DateTime
	 */
	public static function getNextStartDateTime() {
		$time = \Config::inst()->get(__CLASS__, 'time_of_day');
		$now = new \DateTime();
		$next = new \DateTime();
		$next->setTime(
						(isset($time['hour']) ? $time['hour'] : 0),
						(isset($time['minute']) ? $time['minute'] : 0),
						(isset($time['second']) ? $time['second'] : 0)
		);
		
		// if we are currently after the next datetime, set it to tomorrow at the configured time
		// else, we are before, use current day at $time
		if($now > $next) {
			$next->modify('+1 day');
		}
		
		return $next;
		
	}
	
	/**
	 * Checks for local 'failed' events marked FailedThenDelivered=0 and queries Mailgun for a 'delivered' status
	 */
	public function process() {
		try {
			
			$connector = new Connector\Message();
			// poll for events
			$start = new \DateTime();
			$start->setTimezone( new \DateTimeZone('UTC') );// comparing against UTC date
			$start->modify('-30 days');// MG events are only stored for 30 days
			$start_formatted = $start->format('Y-m-d');
			
			// Find local failed OR rejected events created in the date range
			$events = \MailgunEvent::get()->filterAny([
				'EventType' => [ \MailgunEvent::FAILED, \MailgunEvent::REJECTED ],
			])->filter([
				'FailedThenDelivered' => 0,
				'UTCEventDate:GreaterThanOrEqual' => $start_formatted,
			]);
			$events = $events->where("(MessageId IS NOT NULL AND MessageId <> '')");// filter out events without a MessageId, can't query these at all
			
			if($this->test_event_ids) {
				// filter only by these ids to test
				$events = $events->filter('ID', $this->test_event_ids);
			}
			
			$events = $events->sort('Created ASC');// oldest first
			
			if($events) {
				$count = 0;
				$total = count($events);
				foreach($events as $event) {
					try {
						\SS_Log::log("DeliveryCheckJob check isDelivered for event #{$event->ID}/{$event->EventType}", \SS_Log::DEBUG);
						// Check for delivered events, cleanup
						$connector->isDelivered($event, true);
						$count++;
					} catch (\Exception $e) {
						// don't allow a single isDelivered check to cause later ones to fail
						\SS_Log::log("DeliveryCheckJob hit an issue on event #{$event->ID} - " . $e->getMessage(), \SS_Log::NOTICE);
					}
				}
				\SS_Log::log("DeliveryCheckJob cleaned up {$count}/{$total} events", \SS_Log::DEBUG);
			}
			
			$this->isComplete = true;
			return true;
			
		} catch (\Exception $e) {
			// failed somewhere along the line
			\SS_Log::log("DeliveryCheckJob exception: " .  $e->getMessage(), \SS_Log::NOTICE);
		}
		
		$this->isComplete = true;
		return false;
	}
	
	/**
	 * Create another job in 24hrs
	 */
	public function afterComplete() {
		$next = self::getNextStartDateTime();
		$job = new DeliveryCheckJob(); 
		$service = singleton('QueuedJobService');
		$descriptor_id = $service->queueJob($job, $next->format('Y-m-d H:i:s'));
		if($descriptor_id) {
			\SS_Log::log("Queued new DeliveryCheckJob #{$descriptor_id}", \SS_Log::DEBUG);
		} else {
			\SS_Log::log("Failed to queue new DeliveryCheckJob!", \SS_Log::WARN);
		}
	}
	
}