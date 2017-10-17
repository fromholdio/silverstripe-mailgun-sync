<?php
namespace NSWDPC\SilverstripeMailgunSync\Connector;
use Mailgun\Mailgun;

/**
 * EventsApiClient bottles up common requests to Mailgun via the mailgun-php API client
 */
class Event extends Base {
	protected $results = array();
	
	/**
	 * @param string $begin an RFC 2822 formatted UTC datetime OR empty string for no begin datetime
	 * @param string $event_filter see https://documentation.mailgun.com/en/latest/api-events.html#event-types can also be a filter expression e.g "failed OR rejected"
	 * @param boolean $resubmit whether to resubmit events if possible
	 * @todo when two installs share the same api_domain, events will be synchronised to both install.
	 *					Possible workaround is to tag each message with a specific install 'tag' and add this as a filter on event polling here
	 */
	public function pollEvents($begin = NULL, $event_filter = "", $resubmit = false, $extra_params = array()) {
		
		$api_key = $this->getApiKey();
		$client = Mailgun::create( $api_key );

		$domain = $this->getApiDomain();

		$params = array(
			'ascending'    => 'yes',
		);
		
		if($begin) {
			$params['begin'] = $begin;
		}
		
		if($event_filter) {
			$params['event'] = $event_filter;
		}
		
		// Push anything extra into the API request
		if(!empty($extra_params) && is_array($extra_params)) {
			$params = array_merge($params, $extra_params);
		}
		
		if(!isset($params['limit'])) {
			$params['limit'] = 300;//documented max
		}

		# Make the call via the client.
		$response = $client->events()->get($domain, $params);
		
		$items = $response->getItems();
		
		$events = [];
		if(empty($items)) {
			return [];
		} else {
			$this->results = array_merge( $this->results, $items );
			// recursively retrieve the events based on pagination
			//\SS_Log::log("pollEvents getting next page", \SS_Log::DEBUG);
			$this->getNextPage($client, $response);
		}
		
		//\SS_Log::log("Events: " . count($this->results), \SS_Log::DEBUG);
		
		foreach($this->results as $event) {
			
			// Ignore certain event types
			// List of possible flags
			/*
				[is-routed] =>
				[is-authenticated] => 1
				[is-callback] => 1
				[is-system-test] =>
				[is-test-mode] =>
				
				// other possibles:
				is-batch
				is-big
				is-delayed-bounce
			*/
			$flags = $event->getFlags();
			// ignore any callback notifications from webhooks
			if(isset($flags['is-callback']) && $flags['is-callback'] == 1) {
				continue;
			}
			
			// attempt to store the events
			$mailgun_event = \MailgunEvent::storeEvent($event);
			if(!empty($mailgun_event->ID)) {
				$events[] = $mailgun_event;
				//\SS_Log::log("Got MailgunEvent: {$mailgun_event->ID}", \SS_Log::DEBUG);
				if(!$resubmit) {
					//\SS_Log::log("Not resubmitting", \SS_Log::DEBUG);
				} else {
					//\SS_Log::log("--------------- Start AutomatedResubmit Event #{$mailgun_event->ID}-------------------", \SS_Log::DEBUG);
					try {
						$mailgun_event->AutomatedResubmit();
					} catch (\Exception $e) {
						\SS_Log::log("AutomatedResubmit for event {$mailgun_event->ID} requested but failed with error: " . $e->getMessage(), \SS_Log::WARN);
					}
					//\SS_Log::log("--------------- End   AutomatedResubmit Event #{$mailgun_event->ID}-------------------", \SS_Log::DEBUG);
				}
			} else {
				\SS_Log::log("Failed to create/update MailgunEvent", \SS_Log::NOTICE);
			}
		}
		
		return $events;
		
	}
	
	/*
	 * TODO: Implement the event polling method discussed here http://mailgun-documentation.readthedocs.io/en/latest/api-events.html#event-polling
			In our system, events are generated by physical hosts and follow different routes to the event storage. Therefore, the order in which they appear in the
			storage and become retrievable - via the events API - does not always correspond to the order in which they occur. Consequently, this system behavior
			makes straight forward implementation of event polling miss some events. The page of most recent events returned by the events API may not contain
			all the events that occurred at that time because some of them could still be on their way to the storage engine. When the events arrive and are
			eventually indexed, they are inserted into the already retrieved pages which could result in the event being missed if the pages are accessed too
			early (i.e. before all events for the page are available).

			To ensure that all your events are retrieved and accounted for please implement polling the following way:

			1. Make a request to the events API specifying an ascending time range that begins some time in the past (e.g. half an hour ago);
			2. Retrieve a result page;
			3. Check the timestamp of the last event on the result page. If it is older than some threshold age (e.g. half an hour) then go to step (4), otherwise proceed with step (6);
			4. The result page is trustworthy, use events from the page as you please;
			5. Make a request using the next page URL retrieved with the result page, proceed with step (2);
			6. Discard the result page for it is not trustworthy;
			7. Pause for some time (at least 15 seconds);
			8. Repeat the previous request, and proceed with step (2).
	 */
	private function getNextPage($client, $response) {
		// get the next page of the response
		$response = $client->events()->nextPage($response);
		$items = $response->getItems();
		if(empty($items)) {
			// no more items - nothing to do
			return;
		}
		// add to results
		$this->results = array_merge( $this->results, $items );
		//\SS_Log::log("pollEvents getNextPage again", \SS_Log::DEBUG);
		return $this->getNextPage($client, $response);
		
	}
	
}
