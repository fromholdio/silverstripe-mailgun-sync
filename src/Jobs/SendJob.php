<?php
namespace NSWDPC\Messaging\Mailgun;

use Mailgun\Model\Message\SendResponse;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use SilverStripe\Core\Config\Config;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use DateTime;
use DateTimeZone;
use Exception;

/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * Queued Job for sending messages to the Mailgun API
 */
class SendJob extends AbstractQueuedJob
{
    protected $totalSteps = 1;

    public function getJobType()
    {
        $this->totalSteps = 1;
        return QueuedJob::QUEUED;
    }

    public function getTitle()
    {
        $to = isset($this->parameters['to']) ? $this->parameters['to'] : '';
        $subject = isset($this->parameters['subject']) ? $this->parameters['subject'] : '';
        $from = isset($this->parameters['from']) ? $this->parameters['from'] : '';
        return "Email via Mailgun To: {$to} From: {$from} Subject: {$subject}";
    }

    public function getSignature()
    {
        return md5($this->domain . ":" . serialize($this->parameters));
    }

    public function __construct($domain = "", $parameters = [])
    {
        if (!$domain) {
            return;
        }
        if (empty($parameters)) {
            return;
        }
        $this->domain = $domain;
        $this->parameters = $parameters;
    }

    /**
     * polls for 'failed' events in the last day and tries to resubmit them
     */
    public function process()
    {

        //Log::log("SendJob::process", 'DEBUG');

        if ($this->isComplete) {
            //Log::log("SendJob::process already complete", 'DEBUG');
            return;
        }

        $this->currentStep += 1;

        // throw new \Exception("Testing queue errors");

        $connector = new MessageConnector;
        $client = $connector->getClient();

        $domain = $this->domain;
        $parameters = $this->parameters;

        if (!$domain || empty($parameters)) {
            $msg = "MailgunSync\SendJob is missing either the domain or parameters properties";
            $this->messages[] = $msg;
            //Log::log("SendJob::process failed:{$msg}", 'DEBUG');
            throw new Exception($msg);
        }

        $msg = "Unknown error";
        try {

            //Log::log("SendJob::process using domain {$domain}", 'DEBUG');
            //Log::log("SendJob::process to '{$parameters['to']}', from '{$parameters['from']}', subject '{$parameters['subject']}'", 'DEBUG');

            // if required, apply the default recipient
            $connector->applyDefaultRecipient($parameters);
            // decode all attachments
            $connector->decodeAttachments($parameters);
            $response = $client->messages()->send($domain, $parameters);

            $message_id = "";
            if ($response && ($response instanceof SendResponse) && ($message_id = $response->getId())) {
                $message_id = $connector::cleanMessageId($message_id);
                $this->parameters = [];//remove all params
                $msg = "OK {$message_id}";
                $this->messages[] = $msg;
                //Log::log($msg, 'DEBUG');
                // job finished and not marked broken
                $this->isComplete = true;
                return;
            }

            throw new Exception("MailgunSync\SendJob invalid response or no message.id returned");
        } catch (Exception $e) {
            // API level errors caught here
            $msg = $e->getMessage();
        }

        $this->messages[] = $msg;
        //Log::log("SendJob::process failed:{$msg}", 'DEBUG');
        throw new Exception($msg);
    }
}
