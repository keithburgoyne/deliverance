<?php

require_once 'XML/RPC2/Client.php';
require_once 'Swat/SwatDate.php';
require_once 'Deliverance/DeliveranceMailingList.php';
require_once 'Deliverance/DeliveranceMailChimpCampaign.php';

/**
 * @package   Deliverance
 * @copyright 2009-2010 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 * @todo      Handle addresses somehow magically, perhaps add type checking on
 *            merge vars, and allow zip to be passed into an address field by
 *            filling with placeholder data in the other address columns (as
 *            suggested by mailchimp).
 */
class DeliveranceMailChimpList extends DeliveranceMailingList
{
	// {{{ class constants

	/**
	 * How many members to batch update at once.
	 *
	 * Must be kept low enough to not timeout. API docs say cap batch updates
	 * between 5k-10k.
	 *
	 * @var integer
	 */
	const BATCH_UPDATE_SIZE = 5000;

	/**
	 * Error code returned when attempting to subscribe an email address that
	 * has previously unsubscribed. We can't programatically resubscribe them,
	 * MailChimp requires them to resubscribe out of their own volition.
	 */
	const PREVIOUSLY_UNSUBSCRIBED_ERROR_CODE = 212;

	/**
	 * Error code returned when attempting to subscribe an email address that
	 * has bounced in the past, so can't be resubscribed.
	 */
	const BOUNCED_ERROR_CODE = 213;

	/**
	 * Error code returned when attempting to unsubscribe an email address that
	 * is not a current member of the list.
	 */
	const NOT_SUBSCRIBED_ERROR_CODE = 215;

	/**
	 * Error code returned when attempting to unsubscribe an email address that
	 * was never a member of the list.
	 */
	const NOT_FOUND_ERROR_CODE = 232;

	/**
	 * Error code returned when attempting to subscribe an email address that
	 * is not a valid email address.
	 */
	const INVALID_ADDRESS_ERROR_CODE = 502;

	/**
	 * Error code returned when the request timeouts.
	 */
	const CURL_TIMEOUT_ERROR_CODE = 28;

	/**
	 * Error code returned when the connection timeouts.
	 */
	const CURL_CONNECT_ERROR_CODE = 7;

	/**
	 * Error code returned when the name lookup timeouts.
	 */
	const CURL_NAME_LOOKUP_TIMEOUT_ERROR_CODE = 6;

	/**
	 * Email type preference value for html email.
	 */
	const EMAIL_TYPE_HTML = 'html';

	/**
	 * Email type preference value for text only email.
	 */
	const EMAIL_TYPE_TEXT = 'text';

	// }}}
	// {{{ public properties

	public $default_address = array(
		'addr1'   => 'null',
		'city'    => 'null',
		'state'   => 'null',
		'zip'     => 'null',
		);

	// }}}
	// {{{ protected properties

	protected $client;
	protected $list_merge_array_map = array();

	/**
	 * Whether or not to require double opt in on subscribe.
	 *
	 * @var boolean
	 */
	protected $double_opt_in = false;

	/**
	 * Whether or not to replace interests on subscribe.
	 *
	 * If true, existing interests are replaced by the interests passed in. If
	 * false, the new interests are merged with the existing ones.
	 *
	 * @var boolean
	 */
	protected $replace_interests = false;

	/**
	 * Whether or not to update existing members on subscribe.
	 *
	 * If true, the member is updated with the new information, if false, an
	 * error is thrown.
	 *
	 * @var boolean
	 */
	protected $update_existing = true;

	/**
	 * Email type subscribes wish to receive.
	 *
	 * Valid email types are class constants starting with EMAIL_TYPE_*
	 *
	 * @var string
	 */
	protected $email_type = self::EMAIL_TYPE_HTML;

	// }}}
	// {{{ public function __construct()

	public function __construct(SiteApplication $app, $shortname = null,
		$connection_timeout = 1000)
	{
		parent::__construct($app, $shortname);

		// if the connection takes longer than 1s timeout. This will prevent
		// users from waiting too long when MailChimp is down - requests will
		// just get queued. Without setting this, the timeout is ~90s
		$client_options = array(
			'connectionTimeout' => $connection_timeout,
		);

		$this->client = XML_RPC2_Client::create(
			$this->app->config->mail_chimp->api_url, $client_options);

		if ($this->shortname === null)
			$this->shortname = $app->config->mail_chimp->default_list;

		$this->initListMergeArrayMap();

		// default double_opt_in to the config var.
		$this->double_opt_in = $this->app->config->mail_chimp->double_opt_in;
	}

	// }}}
	// {{{ public function setDoubleOptIn()

	public function setDoubleOptIn($double_opt_in)
	{
		$this->double_opt_in = $double_opt_in;
	}

	// }}}
	// {{{ public function setReplaceInterests()

	public function setReplaceInterests($replace_interests)
	{
		$this->replace_interests = $replace_interests;
	}

	// }}}
	// {{{ public function setUpdateExisting()

	public function setUpdateExisting($update_existing)
	{
		$this->update_existing = $update_existing;
	}

	// }}}
	// {{{ public function setEmailType()

	public function setEmailType($email_type)
	{
		$this->email_type = $email_type;
	}

	// }}}
	// {{{ public function isAvailable()

	/**
	 * Tests to make sure the service is available.
	 *
	 * Returns false if MailChimp returns an unexpected value or the
	 * XML_RPC2_Client throws an exception. Unexpected values from MailChimp
	 * get thrown in exceptions as well. Any exceptions thrown are not exited
	 * on, so that we can queue requests based on service availability.
	 *
	 * @return boolean whether or not the service is available.
	 */
	public function isAvailable()
	{
		$available = false;

		try {
			$result = $this->client->ping(
				$this->app->config->mail_chimp->api_key);

			// Endearing? Yes. But also annoying to have to check for a string.
			if ($result === "Everything's Chimpy!") {
				$available = true;
			} else {
				// throw whatever the chimp has given us back
				$e = new SiteException($result);
				$e->log();
			}
		} catch (XML_RPC2_CurlException $e) {
			$error_code = $e->getCode();
			// ignore timeout and connection exceptions.
			if ($error_code !== self::CURL_TIMEOUT_ERROR_CODE &&
				$error_code !== self::CURL_CONNECT_ERROR_CODE &&
				$error_code !== self::CURL_NAME_LOOKUP_TIMEOUT_ERROR_CODE) {
				$e = new SiteException($e);
				$e->log();
			}
		} catch (Exception $e) {
			$e = new SiteException($e);
			$e->log();
		}

		return $available;
	}

	// }}}
	// {{{ protected function initListMergeArrayMap()

	protected function initListMergeArrayMap()
	{
		$this->list_merge_array_map = array(
			'email'      => 'EMAIL', // only used for batch subscribes
			'first_name' => 'FNAME',
			'last_name'  => 'LNAME',
			'user_ip'    => 'OPTINIP',
			'interests'  => 'INTERESTS',
		);
	}

	// }}}

	// subscriber methods
	// {{{ public function subscribe()

	public function subscribe($address, array $info = array(),
		$send_welcome = true, array $array_map = array())
	{
		$result = false;
		$queue_request = false;

		if ($this->isAvailable()) {
			$merges = $this->mergeInfo($info, $array_map);
			try {
				$result = $this->client->listSubscribe(
					$this->app->config->mail_chimp->api_key,
					$this->shortname,
					$address,
					$merges,
					$this->email_type,
					$this->double_opt_in,
					$this->update_existing,
					$this->replace_interests,
					$send_welcome
					);
			} catch (XML_RPC2_FaultException $e) {
				// gracefully handle exceptions that we can provide nice
				// feedback about.
				if ($e->getFaultCode() == self::INVALID_ADDRESS_ERROR_CODE) {
					$result = DeliveranceMailingList::INVALID;
				} else {
					$e = new SiteException($e);
					$e->process();
				}
			} catch (XML_RPC2_CurlException $e) {
				$error_code = $e->getCode();
				// ignore timeout and connection exceptions.
				if ($error_code !== self::CURL_TIMEOUT_ERROR_CODE &&
					$error_code !== self::CURL_CONNECT_ERROR_CODE &&
					$error_code !== self::CURL_NAME_LOOKUP_TIMEOUT_ERROR_CODE) {
					$e = new SiteException($e);
					$e->log();
				} else {
					$queue_request = true;
				}
			} catch (XML_RPC2_Exception $e) {
				$e = new SiteException($e);
				$e->process();
			}
		} else {
			$queue_request = true;
		}

		if ($queue_request === true &&
			$this->app->hasModule('SiteDatabaseModule')) {
			$result = $this->queueSubscribe($address, $info, $send_welcome);
		}

		if ($result === true) {
			$result = self::SUCCESS;
		} elseif ($result === false) {
			$result = self::FAILURE;
		}

		return $result;
	}

	// }}}
	// {{{ public function batchSubscribe()

	public function batchSubscribe(array $addresses, $send_welcome = false,
		array $array_map = array())
	{
		$result = false;

		if ($this->isAvailable()) {
			$result = array(
				'success_count' => 0,
				'error_count'   => 0,
				'errors'        => array(),
				);

			// MailChimp doesn't allow welcomes to be sent on batch subscribes.
			// So if we need to send them, do individual subscribes instead.
			if ($send_welcome === true) {
				foreach ($addresses as $info) {
					$current_result = $this->subscribe($info['email'], $info,
						$send_welcome, $array_map);

					switch ($current_result) {
					case self::SUCCESS:
						$result['success_count']++;
						break;

					default:
						// Match MailChimp's batch subscribe error structure.
						$result['error_count']++;
						$result['errors'][] = array(
							'code'    => $current_result,
							'message' => Site::_(sprintf('Error subscribing %s',
								$info['email'])),
						);
					}
				}
			} else {
				$merged_addresses = array();
				$address_count    = count($addresses);
				$current_count    = 0;

				foreach ($addresses as $info) {
					$current_count++;

					$merges = $this->mergeInfo($info, $array_map);
					if (count($merges)) {
						$merged_addresses[] = $merges;
					}

					if (count($merged_addresses) === self::BATCH_UPDATE_SIZE ||
						$current_count == $address_count) {
						// do update
						try {
							$current_result = $this->client->listBatchSubscribe(
								$this->app->config->mail_chimp->api_key,
								$this->shortname,
								$merged_addresses,
								$this->double_opt_in,
								$this->update_existing,
								$this->replace_interests
								);

						} catch (XML_RPC2_Exception $e) {
							$e = new SiteException($e);
							$e->process();
						}

						$result['success_count']+=
							$current_result['success_count'];

						$result['error_count']+= $current_result['error_count'];
						$result['errors'] = array_merge($result['errors'],
							$current_result['errors']);

						$merged_addresses = array();
					}
				}
			}
		} elseif ($this->app->hasModule('SiteDatabaseModule')) {
			$result = $this->queueBatchSubscribe($addresses, $send_welcome);
		}

		return $result;
	}

	// }}}
	// {{{ public function unsubscribe()

	public function unsubscribe($address)
	{
		$result = false;
		$queue_request = false;

		if ($this->isAvailable()) {
			try {
				$result = $this->client->listUnsubscribe(
					$this->app->config->mail_chimp->api_key,
					$this->shortname,
					$address,
					false, // delete_member
					false  // send_goodbye
					);

			} catch (XML_RPC2_FaultException $e) {
				// gracefully handle exceptions that we can provide nice
				// feedback about.
				if ($e->getFaultCode() == self::NOT_FOUND_ERROR_CODE) {
					$result = DeliveranceMailingList::NOT_FOUND;
				} elseif ($e->getFaultCode() ==
					self::NOT_SUBSCRIBED_ERROR_CODE) {
					$result = DeliveranceMailingList::NOT_SUBSCRIBED;
				} else {
					$e = new SiteException($e);
					$e->process();
				}
			} catch (XML_RPC2_CurlException $e) {
				$error_code = $e->getCode();
				// ignore timeout and connection exceptions.
				if ($error_code !== self::CURL_TIMEOUT_ERROR_CODE &&
					$error_code !== self::CURL_CONNECT_ERROR_CODE &&
					$error_code !== self::CURL_NAME_LOOKUP_TIMEOUT_ERROR_CODE) {
					$e = new SiteException($e);
					$e->log();
				} else {
					$queue_request = true;
				}
			} catch (XML_RPC2_Exception $e) {
				$e = new SiteException($e);
				$e->process();
			}
		} else {
			$queue_request = true;
		}

		if ($queue_request === true &&
			$this->app->hasModule('SiteDatabaseModule')) {
			$result = $this->queueUnsubscribe($address);
		}

		if ($result === true) {
			$result = self::SUCCESS;
		} elseif ($result === false) {
			$result = self::FAILURE;
		}

		return $result;
	}

	// }}}
	// {{{ public function batchUnsubscribe()

	public function batchUnsubscribe(array $addresses)
	{
		$result = false;

		if ($this->isAvailable()) {
			$addresses_chunk = array();
			$address_count   = count($addresses);
			$current_count   = 0;
			$result          = array(
				'success_count' => 0,
				'error_count'   => 0,
				'errors'        => array(),
				);

			foreach ($addresses as $email) {
				$current_count++;
				$addresses_chunk[] = $email;

				if (count($addresses_chunk) === self::BATCH_UPDATE_SIZE ||
					$current_count == $address_count) {

					// unsubscribe the current chunk
					try {
						$current_result = $this->client->listBatchUnsubscribe(
							$this->app->config->mail_chimp->api_key,
							$this->shortname,
							$addresses_chunk,
							false, // delete_member
							false  // send_goodbye
							);
					} catch (XML_RPC2_Exception $e) {
						$e = new SiteException($e);
						$e->process();
					}

					$addresses_chunk = array();
					$result['success_count']+= $current_result['success_count'];
					$result['error_count']+= $current_result['error_count'];
					$result['errors'] = array_merge($result['errors'],
						$current_result['errors']);
				}
			}
		} elseif ($this->app->hasModule('SiteDatabaseModule')) {
			$result = $this->queueBatchUnsubscribe($addresses);
		}

		return $result;
	}

	// }}}
	// {{{ public function isMember()

	public function isMember($address)
	{
		$result = false;

		try {
			$info = $this->client->listMemberInfo(
				$this->app->config->mail_chimp->api_key,
				$this->shortname,
				$address
				);

			// this is the only way to tell if a member is actually subscribed.
			if ($info['status'] == 'subscribed') {
				$result = true;
			}
		} catch (XML_RPC2_Exception $e) {
			// if it fails for any reason, just consider the address as not
			// subscribed.
		}

		return $result;
	}

	// }}}
	// {{{ public function getMembers()

	public function getMembers(array $segment_options = array(), $since = '')
	{
		$members = null;
		$url     = $this->app->config->mail_chimp->export_api_url.'list/';

		$url.= sprintf('?apikey=%s&id=%s&status=%s',
				urlencode($this->app->config->mail_chimp->api_key),
				urlencode($this->shortname),
				urlencode('subscribed'));

		if (count($segment_options) > 0 &&
			array_key_exists('match', $segment_options) &&
			array_key_exists('conditions', $segment_options)) {
			// build the segment array according to the description in the
			// MailChimp documentation here
			// http://www.mailchimp.com/api/how-to/#ex3
			$url.= sprintf('&segment[%s]=%s',
				'match',
				$segment_options['match']);

			$count = 0;
			foreach ($segment_options['conditions'] as $condition) {
				foreach ($condition as $key => $value) {
					$url.= sprintf('&segment[%s][%s][%s]=%s',
						'conditions',
						$count,
						$key,
						urlencode($value));
				}
				$count++;
			}
		}

		if (strlen($since) > 0) {
			$url.= sprintf('&since=%s',
				urlencode($since));
		}

		// TODO: better error handling.
		try {
			$members = file_get_contents($url);
		} catch (Exception $e) {
			throw new SiteException($e);
		}

		$first = true;
		$members_out = array();
		$members = explode("\n", $members);
		foreach ($members as $member) {
			if ($first === true) {
				// first row is the headers of the list, grab it as the keys,
				// and then reindex the rest of the results so they are usable
				// by key. TODO: when possible use $this->list_merge_array_map
				// to define the keys instead of the columns passed by MailChimp
				// so that its consistent.
				$columns = json_decode($member);
				$first   = false;
			} else {
				// check to make sure the exploded line has a length, as the
				// results return a blank line at the end that we can ignore.
				if (strlen($member) > 0) {
					$member_array = json_decode($member, true);
					$member_out_array = array();
					foreach ($member_array as $key => $value) {
						$member_out_array[$columns[$key]] = $value;
					}

					if (count($member_out_array)) {
						$members_out[] = $member_out_array;
					}
				}
			}
		}

		return $members_out;
	}

	// }}}
	// {{{ public function getMemberInfo()

	public function getMemberInfo($address)
	{
		$member_info = null;

		try {
			$member_info = $this->client->listMemberInfo(
				$this->app->config->mail_chimp->api_key,
				$this->shortname,
				$address
				);
		} catch (XML_RPC2_Exception $e) {
			// if it fails for any reason, just consider the address as not
			// subscribed.
		}

		return $member_info;
	}

	// }}}
	// {{{ public function updateMemberInfo()

	public function updateMemberInfo($address, array $info,
		array $array_map = array())
	{
		$result = false;

		// TODO: queueing of some sort
		$merges = $this->mergeInfo($info, $array_map);
		try {
			$result = $this->client->listUpdateMember(
				$this->app->config->mail_chimp->api_key,
				$this->shortname,
				$address,
				$merges,
				'', // email_type, left blank to keep existing preference.
				$this->replace_interests
				);
		} catch (XML_RPC2_Exception $e) {
			// TODO: handle some edge cases more elegantly
			throw new SiteException($e);
		}

		if ($result === true) {
			$result = self::SUCCESS;
		} elseif ($result === false) {
			$result = self::FAILURE;
		}

		return $result;
	}

	// }}}
	// {{{ protected function mergeInfo()

	protected function mergeInfo(array $info, array $array_map = array())
	{
		// passed in array_map is second so that it can override any of the
		// list_merge_array_map values
		$array_map = array_merge($this->list_merge_array_map, $array_map);

		$merges = array();
		foreach ($info as $id => $value) {
			if (array_key_exists($id, $array_map) && $value != null) {
				$merge_var = $array_map[$id];
				// interests can be passed in as an array, but MailChimp
				// expects a comma delimited list.
				if ($merge_var == 'INTERESTS' && is_array($value)) {
					$value = implode(',', $value);
				}

				$merges[$merge_var] = $value;
			}
		}

		return $merges;
	}

	// }}}
	// {{{ public function getDefaultAddress()

	public function getDefaultAddress()
	{
		// TODO: do this better somehow
		return $this->default_address;
	}

	// }}}

	// campaign methods
	// {{{ public function saveCampaign()

	public function saveCampaign(DeliveranceMailingCampaign $campaign)
	{
		$campaign->id = $this->getCampaignId($campaign);

		if ($campaign->id != null) {
			$this->updateCampaign($campaign);
		} else {
			$this->createCampaign($campaign);
		}

		return $campaign->id;
	}

	// }}}
	// {{{ public function scheduleCampaign()

	public function scheduleCampaign(DeliveranceMailingCampaign $campaign)
	{
		$send_date = $campaign->getSendDate();
		if ($send_date instanceof SwatDate) {
			// Campaigns have to be unscheduled to set a new send time. Only
			// unschedule if we're rescheduling so that we don't accidentally
			// unschedule a manually scheduled campaign.
			$this->unscheduleCampaign($campaign);

			$send_date->setTZ($this->app->default_time_zone);
			$send_date->toUTC();

			try {
				// TZ intentionally omitted, API call expects date in UTC with
				// no timezone information.
				$this->client->campaignSchedule(
					$this->app->config->mail_chimp->api_key,
					$campaign->id,
					$send_date->getDate());
			} catch (XML_RPC2_Exception $e) {
				$e = new SiteException($e);
				$e->process();
			}
		}
	}

	// }}}
	// {{{ public function unscheduleCampaign()

	public function unscheduleCampaign(DeliveranceMailingCampaign $campaign)
	{
		try {
			$this->client->campaignUnschedule(
				$this->app->config->mail_chimp->api_key,
				$campaign->id);
		} catch (XML_RPC2_FaultException $e) {
			// ignore errors caused by trying to unschedule a campaign that
			// isn't scheduled yet. These are safe to ignore.
			if ($e->getFaultCode() != 122) {
				$e = new SiteException($e);
				$e->process();
			}
		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}
	}

	// }}}
	// {{{ public function getCampaignId()

	public function getCampaignId(DeliveranceMailingCampaign $campaign)
	{
		$campaign_id = null;
		$filters     = array(
			'list_id' => $this->shortname,
			'title'   => $campaign->getTitle(),
			'exact'   => true,
		);

		try {
			$campaigns = $this->client->campaigns(
				$this->app->config->mail_chimp->api_key,
				$filters);
		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}

		if (count($campaigns) > 1) {
			throw new SiteException(sprintf(
				'Multiple campaigns exist with a title of ‘%s’',
				$campaign->getTitle()));
		} elseif (count($campaigns) == 1) {
			$campaign_id = $campaigns[0]['id'];
		}

		return $campaign_id;
	}

	// }}}
	// {{{ public function getCampaigns()

	public function getCampaigns(array $filters = array())
	{
		$campaigns  = array();
		$offset     = 0;
		$chunk_size = 50;
		$chunk      = $this->getCampaignsChunk($filters, $offset, $chunk_size);

		while (count($chunk) > 0) {
			$campaigns = array_merge($campaigns, $chunk);
			$offset++;
			$chunk = $this->getCampaignsChunk($filters, $offset,
				$chunk_size);
		}

		return $campaigns;
	}

	// }}}
	// {{{ public function getCampaignStats()

	public function getCampaignStats($campaign_id)
	{
		$stats = array();

		try {
			$stats = $this->client->campaignStats(
				$this->app->config->mail_chimp->api_key,
				$campaign_id);
		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}

		// This is the stat we most often want to report back. Standardize here
		// so that if it changes, we don't have to update everywhere.
		$stats['successful_sent'] = $stats['emails_sent'] -
			$stats['hard_bounces'] - $stats['soft_bounces'];

		return $stats;
	}

	// }}}
	// {{{ public function getCampaignClickStats()

	public function getCampaignClickStats($campaign_id)
	{
		$stats = array();

		try {
			$stats = $this->client->campaignClickStats(
				$this->app->config->mail_chimp->api_key,
				$campaign_id);
		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}

		return $stats;
	}

	// }}}
	// {{{ public function sendCampaignTest()

	public function sendCampaignTest(DeliveranceMailChimpCampaign $campaign,
		array $test_emails)
	{
		try {
			$this->client->campaignSendTest(
				$this->app->config->mail_chimp->api_key,
				$campaign->id,
				$test_emails);
		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}
	}

	// }}}
	// {{{ public function testSegmentOptions()

	public function getSegmentSize(DeliveranceMailChimpCampaign $campaign,
		array $segment_options)
	{
		$segment_size = 0;

		try {
			$segment_size = $this->client->campaignSegmentTest(
				$this->app->config->mail_chimp->api_key,
				$this->shortname,
				$segment_options);
		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}

		return $segment_size;
	}

	// }}}
	// {{{ protected function createCampaign()

	protected function createCampaign(DeliveranceMailingCampaign $campaign)
	{
		$options = $this->getCampaignOptions($campaign);
		$content = $this->getCampaignContent($campaign);

		try {
			$campaign_id = $this->client->campaignCreate(
				$this->app->config->mail_chimp->api_key,
				$campaign->type, $options, $content);
		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}

		$campaign->id = $campaign_id;

		// call this separately because XML/RPC can't pass nulls, and it's often
		// null. And other values are type checked by MailChimp
		$this->updateCampaignSegmentOptions($campaign);
	}

	// }}}
	// {{{ protected function updateCampaign()

	protected function updateCampaign(DeliveranceMailingCampaign $campaign)
	{
		$options = $this->getCampaignOptions($campaign);
		$content = $this->getCampaignContent($campaign);

		try {
			$this->client->campaignUpdate(
				$this->app->config->mail_chimp->api_key,
				$campaign->id, 'content', $content);

			// options can only be updated one at a time.
			foreach ($options as $title => $value) {
				$this->client->campaignUpdate(
					$this->app->config->mail_chimp->api_key,
					$campaign->id, $title, $value);
			}

			$this->updateCampaignSegmentOptions($campaign);
		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}
	}

	// }}}
	// {{{ protected function updateCampaignSegmentOptions()

	protected function updateCampaignSegmentOptions(
		DeliveranceMailingCampaign $campaign)
	{
		$segment_options = $this->getCampaignSegmentOptions($campaign);
		if ($segment_options !== null) {
			try {
				$this->client->campaignUpdate(
					$this->app->config->mail_chimp->api_key,
					$campaign->id, 'segment_opts', $segment_options);
			} catch (XML_RPC2_Exception $e) {
				$e = new SiteException($e);
				$e->process();
			}
		}
	}

	// }}}
	// {{{ protected function getCampaignOptions()

	protected function getCampaignOptions(
		DeliveranceMailChimpCampaign $campaign)
	{
		$title = $campaign->getTitle();
		if ($title == null)
			throw new SiteException('Campaign “Title” is null');

		$subject = $campaign->getSubject();
		if ($subject == null)
			throw new SiteException('Campaign “Subject” is null');

		$from_address = $campaign->getFromAddress();
		if ($from_address == null)
			throw new SiteException('Campaign “From Address” is null');

		$from_name = $campaign->getFromName();
		if ($from_name == null)
			throw new SiteException('Campaign “From Name” is null');

		$analytics = '';
		$key = $campaign->getAnalyticsKey();
		if ($key != null)
			$analytics = array('google' => $key);

		$options = array(
			'list_id'      => $this->shortname,
			'title'        => $title,
			'subject'      => $subject,
			'from_email'   => $from_address,
			'from_name'    => $from_name,
			'to_email'     => '*|FNAME|* *|LNAME|*',
			'authenticate' => 'true',
			'analytics'    => $analytics,
			'inline_css'   => true,
			'timewarp'     => $campaign->timewarp,
		);

		if ($this->app->config->mail_chimp->default_folder != null) {
			$options['folder_id'] =
				$this->app->config->mail_chimp->default_folder;
		}

		return $options;
	}

	// }}}
	// {{{ protected function getCampaignSegmentOptions()

	protected function getCampaignSegmentOptions(
		DeliveranceMailChimpCampaign $campaign)
	{
		$segment_options = array();

		$segment_options = $campaign->getSegmentOptions();
		if ($segment_options != null) {
			if ($this->getSegmentSize($campaign, $segment_options) == 0) {
				throw new SiteException('Campaign Segment Options return no '.
					'members');
			}
		}

		return $segment_options;
	}

	// }}}
	// {{{ protected function getCampaignContent()

	protected function getCampaignContent(
		DeliveranceMailChimpCampaign $campaign)
	{
		$content = array(
			'html' => $campaign->getContent(
				DeliveranceMailingCampaign::FORMAT_XHTML),
			'text' => $campaign->getContent(
				DeliveranceMailingCampaign::FORMAT_TEXT),
		);

		return $content;
	}

	// }}}
	// {{{ protected function getCampaignsChunk()

	protected function getCampaignsChunk(array $filters = array(), $offset = 0,
		$chunk_size = 0)
	{
		if ($chunk_size > 1000)
			throw new SiteException('Campaign chunk size exceeds API limit');

		$campaigns = array();
		// add the list id to the set of passed in filters
		$filters['list_id'] = $this->shortname;

		try {
			$campaigns = $this->client->campaigns(
				$this->app->config->mail_chimp->api_key,
				$filters,
				$offset,
				$chunk_size
				);

		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}

		return $campaigns;
	}

	// }}}

	// list methods
	// {{{ public function getMemberCount()

	public function getMemberCount()
	{
		$member_count = null;

		try {
		    $lists = $this->client->lists(
				$this->app->config->mail_chimp->api_key);

			foreach ($lists as $list) {
				if ($list['id'] == $this->shortname) {
					$member_count = $list['member_count'];
					break;
				}
			}
		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}

		return $member_count;
	}

	// }}}
	// {{{ public function getMergeVars()

	public function getMergeVars()
	{
		$merge_vars = null;

		try {
			$merge_vars = $this->client->listMergeVars(
				$this->app->config->mail_chimp->api_key, $this->shortname);

		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}

		return $merge_vars;
	}

	// }}}

	// list setup helper methods.
	// {{{ public function getAllLists()

	public function getAllLists()
	{
		$lists = null;

		try {
		    $lists = $this->client->lists(
				$this->app->config->mail_chimp->api_key);

		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}

		return $lists;
	}

	// }}}
	// {{{ public function getFolders()

	public function getFolders()
	{
		$folders = null;

		try {
		    $folders = $this->client->campaignFolders(
				$this->app->config->mail_chimp->api_key);

		} catch (XML_RPC2_Exception $e) {
			$e = new SiteException($e);
			$e->process();
		}

		return $folders;
	}

	// }}}
}

?>